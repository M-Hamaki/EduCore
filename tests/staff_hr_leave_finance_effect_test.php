<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Leave\LeaveFinanceEffectService;
use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectRepository;
use EduCore\Modules\Staff\Contracts\PayrollImpactGateway;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class MemoryLeaveFinanceEffectRepository implements LeaveFinanceEffectRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $requests = [];
    /** @var array<int,list<array<string,mixed>>> */
    public array $days = [];
    /** @var array<int,array<string,mixed>> */
    public array $effects = [];
    public bool $inTransaction = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    private int $nextEffectId = 1;

    public function transactional(callable $operation): mixed
    {
        $snapshot = [$this->effects, $this->nextEffectId];
        $this->inTransaction = true;
        try {
            $result = $operation();
            ++$this->commits;

            return $result;
        } catch (Throwable $exception) {
            [$this->effects, $this->nextEffectId] = $snapshot;
            ++$this->rollbacks;
            throw $exception;
        } finally {
            $this->inTransaction = false;
        }
    }

    public function requestForUpdate(int $requestId): ?array
    {
        return $this->requests[$requestId] ?? null;
    }

    public function requestDaysForUpdate(int $requestId): array
    {
        return $this->days[$requestId] ?? [];
    }

    public function effectByIdentityForUpdate(string $effectKey, string $idempotencyKey): ?array
    {
        foreach ($this->effects as $effect) {
            if ((string) $effect['effect_key'] === $effectKey
                || (string) $effect['idempotency_key'] === $idempotencyKey) {
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
        return [];
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
        if ($effect === null || !in_array((string) $effect['status'], ['pending', 'retry', 'processing'], true)) {
            return false;
        }
        $this->effects[$effectId]['status'] = 'processing';
        $this->effects[$effectId]['attempts'] = (int) $effect['attempts'] + 1;
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
}

final class MemoryLeaveFinanceAudit implements AuditEventWriter
{
    /** @var list<array{action:string,record_id:int|string}> */
    public array $events = [];
    public ?string $failOn = null;

    public function __construct(private MemoryLeaveFinanceEffectRepository $repository)
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
            throw new RuntimeException('LEAVE_FINANCE_AUDIT_OUTSIDE_TRANSACTION');
        }
        if ($this->failOn === $action) {
            throw new RuntimeException('LEAVE_FINANCE_AUDIT_FAILURE');
        }
        $this->events[] = ['action' => $action, 'record_id' => $recordId];
    }
}

final class MemoryPayrollImpactGateway implements PayrollImpactGateway
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    public bool $accept = true;
    public bool $throw = false;

    public function submitFacts(
        string $effectKey,
        int $staffId,
        string $factType,
        string $units,
        string $effectivePeriod,
        string $sourceRef,
        array $metadata
    ): array {
        if ($this->throw) {
            throw new RuntimeException('FINANCE_UNAVAILABLE');
        }
        $this->calls[] = [
            'effect_key' => $effectKey,
            'staff_id' => $staffId,
            'fact_type' => $factType,
            'units' => $units,
            'effective_period' => $effectivePeriod,
            'source_ref' => $sourceRef,
            'metadata' => $metadata,
        ];

        return [
            'accepted' => $this->accept,
            'status' => $this->accept ? 'accepted' : 'rejected',
            'finance_reference' => $this->accept ? 'FIN-' . count($this->calls) : null,
        ];
    }
}

$repository = new MemoryLeaveFinanceEffectRepository();
$audit = new MemoryLeaveFinanceAudit($repository);
$finance = new MemoryPayrollImpactGateway();
$service = new LeaveFinanceEffectService($repository, $finance, $audit);
$failures = [];
$assert = static function (string $name, bool $passed) use (&$failures): void {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
};
$throws = static function (callable $operation): bool {
    try {
        $operation();
    } catch (Throwable) {
        return true;
    }

    return false;
};

$snapshot = static function (?string $effectCode): array {
    return [
        'payroll_effect_code' => $effectCode,
        'leave_type' => ['id' => 4, 'code' => 'regular', 'unit' => 'day'],
    ];
};
$request = static function (
    int $id,
    string $kind,
    string $units,
    int $minutes,
    ?string $effectCode,
    string $status = 'approved'
) use ($snapshot): array {
    return [
        'id' => $id,
        'staff_user_id' => 41,
        'leave_type_id' => 4,
        'request_kind' => $kind,
        'status' => $status,
        'requested_units' => $units,
        'requested_minutes' => $minutes,
        'policy_version_id' => 9,
        'policy_snapshot' => $snapshot($effectCode),
        'request_hash' => hash('sha256', 'request-' . $id),
    ];
};
$day = static function (string $date, string $units, int $minutes, string $kind = 'workday'): array {
    return [
        'work_date' => $date,
        'day_kind' => $kind,
        'requested_units' => $units,
        'requested_minutes' => $minutes,
    ];
};

$repository->requests[101] = $request(101, 'leave', '1.500', 720, 'paid_leave');
$repository->days[101] = [
    $day('2026-08-31', '0.500', 240),
    $day('2026-09-01', '1.000', 480),
    $day('2026-09-02', '0.000', 0, 'non_working'),
];
$queued = $service->queueForApprovedRequest(101, 900, new DateTimeImmutable('2026-08-01 08:00:00 UTC'));
$effectIds = $queued['effect_ids'];
sort($effectIds);
$effects = array_values($repository->effects);
usort($effects, static fn (array $left, array $right): int => (string) $left['effective_period'] <=> (string) $right['effective_period']);
$firstPayload = json_decode((string) ($effects[0]['payload'] ?? ''), true);
$assert(
    'paid_leave_is_split_by_effective_month_with_exact_units',
    $queued['status'] === 'queued'
        && count($effectIds) === 2
        && ($effects[0]['effective_period'] ?? '') === '2026-08'
        && ($effects[0]['units'] ?? '') === '0.500'
        && ($effects[1]['effective_period'] ?? '') === '2026-09'
        && ($effects[1]['units'] ?? '') === '1.000'
        && ($effects[0]['fact_type'] ?? '') === 'paid_leave'
        && !array_key_exists('reason', (array) ($firstPayload['metadata'] ?? []))
        && !array_key_exists('amount', (array) ($firstPayload['metadata'] ?? []))
);

foreach ($effectIds as $effectId) {
    $service->dispatchEffect($effectId, null, new DateTimeImmutable('2026-08-01 08:02:00 UTC'));
}
$assert(
    'paid_facts_dispatch_only_through_finance_contract',
    count($finance->calls) === 2
        && $finance->calls[0]['staff_id'] === 41
        && $finance->calls[0]['source_ref'] === 'staff_leave_request:101'
        && $finance->calls[0]['fact_type'] === 'paid_leave'
        && !array_key_exists('reason', $finance->calls[0]['metadata'])
        && !array_key_exists('supporting_document_ref', $finance->calls[0]['metadata'])
        && (($repository->effects[$effectIds[0]]['status'] ?? '') === 'accepted')
);

$replay = $service->queueForApprovedRequest(101, 900, new DateTimeImmutable('2026-08-01 08:03:00 UTC'));
$assert(
    'same_approved_leave_replays_without_duplicate_finance_facts',
    $replay['status'] === 'idempotent_replay'
        && $replay['effect_ids'] === []
        && count($replay['replayed_effect_ids']) === 2
        && count($repository->effects) === 2
);

$repository->requests[102] = $request(102, 'leave', '1.000', 480, 'unpaid_leave');
$repository->days[102] = [$day('2026-09-05', '1.000', 480)];
$unpaid = $service->queueForApprovedRequest(102, 900);
$unpaidEffectId = $unpaid['effect_ids'][0] ?? 0;
$unpaidDispatched = $service->dispatchEffect($unpaidEffectId, null);
$assert(
    'unpaid_leave_uses_policy_fact_code_without_salary_amount',
    $unpaidDispatched['accepted']
        && ($repository->effects[$unpaidEffectId]['fact_type'] ?? '') === 'unpaid_leave'
        && ($repository->effects[$unpaidEffectId]['units'] ?? '') === '1.000'
        && !str_contains((string) ($repository->effects[$unpaidEffectId]['payload'] ?? ''), 'amount')
);

$repository->requests[103] = $request(103, 'cancellation', '1.000', 480, 'paid_leave');
$repository->days[103] = [$day('2026-09-10', '1.000', 480)];
$cancelled = $service->queueForApprovedRequest(103, 900);
$cancelEffectId = $cancelled['effect_ids'][0] ?? 0;
$cancelPayload = json_decode((string) ($repository->effects[$cancelEffectId]['payload'] ?? ''), true);
$assert(
    'approved_cancellation_emits_signed_reversal_fact',
    ($repository->effects[$cancelEffectId]['units'] ?? '') === '-1.000'
        && (($cancelPayload['metadata']['direction'] ?? '') === 'reverse')
        && (($cancelPayload['metadata']['requested_minutes'] ?? 0) === -480)
);

$repository->requests[104] = $request(104, 'leave', '1.000', 480, null);
$repository->days[104] = [$day('2026-09-12', '1.000', 480)];
$noEffect = $service->queueForApprovedRequest(104, 900);
$assert(
    'leave_without_finance_policy_code_creates_no_effect',
    $noEffect['status'] === 'not_applicable' && $noEffect['effect_ids'] === []
);

$repository->requests[105] = $request(105, 'leave', '1.000', 480, 'paid_leave', 'pending_approval');
$repository->days[105] = [$day('2026-09-12', '1.000', 480)];
$effectsBeforeRejected = count($repository->effects);
$assert(
    'non_final_leave_cannot_queue_finance_effect',
    $throws(static fn (): array => $service->queueForApprovedRequest(105, 900))
        && count($repository->effects) === $effectsBeforeRejected
);

$repository->requests[106] = $request(106, 'leave', '1.000', 480, 'paid_leave');
$repository->days[106] = [$day('2026-09-12', '0.500', 480)];
$assert(
    'mismatched_day_allocation_fails_before_outbox_write',
    $throws(static fn (): array => $service->queueForApprovedRequest(106, 900))
        && count($repository->effects) === $effectsBeforeRejected
);

$repository->requests[107] = $request(107, 'leave', '1.000', 480, 'paid_leave');
$repository->days[107] = [$day('2026-09-13', '1.000', 480)];
$retryQueue = $service->queueForApprovedRequest(107, 900);
$retryEffectId = $retryQueue['effect_ids'][0] ?? 0;
$finance->accept = false;
$retry = $service->dispatchEffect($retryEffectId, null);
$finance->accept = true;
$assert(
    'finance_rejection_is_retryable_without_leaking_gateway_error',
    !$retry['accepted']
        && $retry['status'] === 'retry'
        && ($repository->effects[$retryEffectId]['last_error'] ?? '') === 'FINANCE_GATEWAY_REJECTED'
);

$repository->requests[108] = $request(108, 'leave', '1.000', 480, 'paid_leave');
$repository->days[108] = [$day('2026-09-14', '1.000', 480)];
$effectsBeforeAuditFailure = count($repository->effects);
$audit->failOn = 'staff_leave_finance_effect_queued';
$assert(
    'mandatory_audit_failure_rolls_back_finance_outbox_insert',
    $throws(static fn (): array => $service->queueForApprovedRequest(108, 900))
        && count($repository->effects) === $effectsBeforeAuditFailure
        && $repository->rollbacks > 0
);
$audit->failOn = null;

$serviceSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Application/Leave/LeaveFinanceEffectService.php'
);
$repositorySource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/PdoLeaveFinanceEffectRepository.php'
);
$assert(
    'finance_boundary_contains_no_direct_finance_table_write',
    preg_match('/\b(?:FROM|JOIN|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+finance_/i', $serviceSource) !== 1
        && str_contains($serviceSource, 'PayrollImpactGateway')
        && str_contains($repositorySource, 'staff_external_effects')
        && preg_match('/\b(?:FROM|JOIN|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:finance|payroll)_/i', $repositorySource) !== 1
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Staff-HR leave Finance effect failure(s).\n");
    exit(1);
}

echo "Staff-HR leave Finance effect tests passed.\n";
