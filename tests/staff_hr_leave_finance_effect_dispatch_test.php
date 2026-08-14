<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Leave\LeaveFinanceEffectService;
use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectRepository;
use EduCore\Modules\Staff\Contracts\PayrollImpactGateway;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class DueEffectMemoryRepository implements LeaveFinanceEffectRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $effects = [];
    public bool $inTransaction = false;
    private int $nextEffectId = 100;

    public function transactional(callable $operation): mixed
    {
        $snapshot = $this->effects;
        $this->inTransaction = true;
        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->effects = $snapshot;
            throw $exception;
        } finally {
            $this->inTransaction = false;
        }
    }

    public function requestForUpdate(int $requestId): ?array
    {
        return null;
    }

    public function requestDaysForUpdate(int $requestId): array
    {
        return [];
    }

    public function effectByIdentityForUpdate(string $effectKey, string $idempotencyKey): ?array
    {
        foreach ($this->effects as $effect) {
            if ((string) ($effect['effect_key'] ?? '') === $effectKey
                || (string) ($effect['idempotency_key'] ?? '') === $idempotencyKey) {
                return $effect;
            }
        }

        return null;
    }

    public function effectForUpdate(int $effectId): ?array
    {
        return $this->effects[$effectId] ?? null;
    }

    public function dueEffectIdsForDispatch(int $limit, string $dueAt): array
    {
        $rows = [];
        foreach ($this->effects as $effect) {
            if ((string) ($effect['resource_type'] ?? '') !== 'leave_request'
                || (string) ($effect['target_module'] ?? '') !== 'finance'
                || !$this->isDue($effect, $dueAt)) {
                continue;
            }
            $rows[] = $effect;
        }
        usort($rows, static function (array $left, array $right): int {
            $leftDue = (string) ($left['next_attempt_at'] ?? '');
            $rightDue = (string) ($right['next_attempt_at'] ?? '');
            $leftOrder = $leftDue === '' ? '0' : '1' . $leftDue;
            $rightOrder = $rightDue === '' ? '0' : '1' . $rightDue;

            return $leftOrder <=> $rightOrder ?: ((int) $left['id'] <=> (int) $right['id']);
        });

        return array_map(
            static fn (array $effect): int => (int) $effect['id'],
            array_slice($rows, 0, $limit)
        );
    }

    public function insertEffect(array $effect): int
    {
        $id = $this->nextEffectId++;
        $this->effects[$id] = ['id' => $id] + $effect + [
            'result_ref' => null,
            'last_error' => null,
            'attempts' => 0,
            'next_attempt_at' => null,
            'completed_at' => null,
        ];

        return $id;
    }

    public function claimEffect(int $effectId, string $claimedAt, string $leaseUntil): bool
    {
        $effect = $this->effects[$effectId] ?? null;
        if ($effect === null || !$this->isDue($effect, $claimedAt)) {
            return false;
        }

        $this->effects[$effectId]['status'] = 'processing';
        $this->effects[$effectId]['attempts'] = (int) ($effect['attempts'] ?? 0) + 1;
        $this->effects[$effectId]['next_attempt_at'] = $leaseUntil;
        $this->effects[$effectId]['last_error'] = null;

        return true;
    }

    public function markEffectAccepted(int $effectId, ?string $financeReference, string $completedAt): bool
    {
        if (($this->effects[$effectId]['status'] ?? null) !== 'processing') {
            return false;
        }
        $this->effects[$effectId]['status'] = 'accepted';
        $this->effects[$effectId]['result_ref'] = $financeReference;
        $this->effects[$effectId]['completed_at'] = $completedAt;
        $this->effects[$effectId]['next_attempt_at'] = null;
        $this->effects[$effectId]['last_error'] = null;

        return true;
    }

    public function markEffectForRetry(int $effectId, string $reasonCode, string $nextAttemptAt): bool
    {
        if (($this->effects[$effectId]['status'] ?? null) !== 'processing') {
            return false;
        }
        $this->effects[$effectId]['status'] = 'retry';
        $this->effects[$effectId]['last_error'] = $reasonCode;
        $this->effects[$effectId]['next_attempt_at'] = $nextAttemptAt;

        return true;
    }

    /** @param array<string,mixed> $effect */
    private function isDue(array $effect, string $dueAt): bool
    {
        $status = (string) ($effect['status'] ?? '');
        $nextAttempt = (string) ($effect['next_attempt_at'] ?? '');
        if (in_array($status, ['pending', 'retry'], true)) {
            return $nextAttempt === '' || $nextAttempt <= $dueAt;
        }

        return $status === 'processing' && $nextAttempt !== '' && $nextAttempt <= $dueAt;
    }
}

final class DueEffectAudit implements AuditEventWriter
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(private DueEffectMemoryRepository $repository)
    {
    }

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if (!$this->repository->inTransaction) {
            throw new RuntimeException('LEAVE_FINANCE_DISPATCH_AUDIT_OUTSIDE_TRANSACTION');
        }
        $this->events[] = $action;
    }
}

final class DueEffectGateway implements PayrollImpactGateway
{
    /** @var list<string> */
    public array $effectKeys = [];
    public bool $accept = true;

    public function submitFacts(
        string $effectKey,
        int $staffId,
        string $factType,
        string $units,
        string $effectivePeriod,
        string $sourceRef,
        array $metadata
    ): array {
        $this->effectKeys[] = $effectKey;

        return [
            'accepted' => $this->accept,
            'status' => $this->accept ? 'accepted' : 'rejected',
            'finance_reference' => $this->accept ? 'FIN-' . count($this->effectKeys) : null,
        ];
    }
}

$effect = static function (
    int $id,
    string $status = 'pending',
    ?string $nextAttemptAt = null,
    string $resourceType = 'leave_request',
    string $targetModule = 'finance'
): array {
    return [
        'id' => $id,
        'effect_key' => 'leave-effect-' . $id,
        'idempotency_key' => 'leave-idempotency-' . $id,
        'resource_type' => $resourceType,
        'resource_id' => 1000 + $id,
        'target_module' => $targetModule,
        'fact_type' => 'paid_leave',
        'units' => '1.000',
        'effective_period' => '2026-08',
        'payload' => json_encode([
            'staff_user_id' => 42,
            'source_ref' => 'staff_leave_request:' . (1000 + $id),
            'metadata' => ['direction' => 'forward', 'schema_version' => 1],
        ], JSON_THROW_ON_ERROR),
        'status' => $status,
        'result_ref' => null,
        'last_error' => null,
        'attempts' => 0,
        'next_attempt_at' => $nextAttemptAt,
        'completed_at' => null,
    ];
};

$repository = new DueEffectMemoryRepository();
$audit = new DueEffectAudit($repository);
$gateway = new DueEffectGateway();
$service = new LeaveFinanceEffectService($repository, $gateway, $audit);
$at = new DateTimeImmutable('2026-08-09 08:00:00 UTC');

$repository->effects = [
    1 => $effect(1),
    2 => $effect(2, 'retry', '2026-08-09 07:00:00.000000'),
    3 => $effect(3, 'retry', '2026-08-09 08:10:00.000000'),
    4 => $effect(4, 'processing', '2026-08-09 07:10:00.000000'),
    5 => $effect(5, 'accepted'),
    6 => $effect(6, 'pending', null, 'leave_request', 'notifications'),
    7 => $effect(7, 'pending', null, 'permission_request', 'finance'),
];

$failures = [];
$assert = static function (string $name, bool $passed) use (&$failures): void {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
};

$first = $service->dispatchDueEffects(2, 707, $at);
$assert(
    'dispatcher_claims_only_due_leave_finance_effects_up_to_the_limit',
    $first['selected_effect_ids'] === [1, 2]
        && $first['accepted_count'] === 2
        && $first['retry_count'] === 0
        && $gateway->effectKeys === ['leave-effect-1', 'leave-effect-2']
        && ($repository->effects[1]['status'] ?? '') === 'accepted'
        && ($repository->effects[2]['status'] ?? '') === 'accepted'
);

$second = $service->dispatchDueEffects(10, 707, $at);
$assert(
    'expired_lease_is_reclaimed_but_future_nonfinance_and_other_resource_effects_are_not_dispatched',
    $second['selected_effect_ids'] === [4]
        && $second['accepted_count'] === 1
        && $gateway->effectKeys === ['leave-effect-1', 'leave-effect-2', 'leave-effect-4']
        && ($repository->effects[3]['status'] ?? '') === 'retry'
        && ($repository->effects[6]['status'] ?? '') === 'pending'
        && ($repository->effects[7]['status'] ?? '') === 'pending'
);

$repository->effects[8] = $effect(8);
$gateway->accept = false;
$retry = $service->dispatchDueEffects(10, 707, $at);
$gateway->accept = true;
$beforeDue = $service->dispatchDueEffects(10, 707, $at->modify('+30 seconds'));
$afterDue = $service->dispatchDueEffects(10, 707, $at->modify('+61 seconds'));
$assert(
    'gateway_rejection_retries_with_the_same_effect_identity_only_after_due_time',
    $retry['selected_effect_ids'] === [8]
        && $retry['retry_count'] === 1
        && ($repository->effects[8]['status'] ?? '') === 'accepted'
        && $beforeDue['selected_effect_ids'] === []
        && $afterDue['selected_effect_ids'] === [8]
        && count(array_keys($gateway->effectKeys, 'leave-effect-8', true)) === 2
);

$serviceSource = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Staff/Application/Leave/LeaveFinanceEffectService.php');
$repositorySource = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/PdoLeaveFinanceEffectRepository.php');
$dispatcherSource = (string) file_get_contents(dirname(__DIR__) . '/tools/staff_leave_finance_effect_dispatcher.php');
$assert(
    'dispatcher_preserves_staff_finance_contract_and_explicit_apply_guard',
    str_contains($serviceSource, 'dispatchDueEffects')
        && str_contains($repositorySource, 'dueEffectIdsForDispatch')
        && str_contains($dispatcherSource, '--apply')
        && str_contains($dispatcherSource, '--gateway-bootstrap')
        && !preg_match('/\b(?:FROM|JOIN|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:finance|payroll)_/i', $dispatcherSource)
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Staff-HR due Finance dispatch failure(s).\n");
    exit(1);
}

echo "Staff-HR due Finance dispatch tests passed.\n";
