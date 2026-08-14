<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Discipline\DisciplineFinanceEffectService;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectRepository;
use EduCore\Modules\Staff\Contracts\PayrollImpactGateway;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class MemoryDisciplineFinanceEffectRepository implements DisciplineFinanceEffectRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $decisions = [];
    /** @var array<int,array<string,mixed>> */
    public array $cases = [];
    /** @var array<int,array<string,mixed>> */
    public array $appeals = [];
    /** @var array<int,array<string,mixed>> */
    public array $effects = [];
    public bool $inTransaction = false;
    public int $rollbacks = 0;
    private int $nextEffectId = 1;

    public function transactional(callable $work): mixed
    {
        $snapshot = [
            $this->effects,
            $this->nextEffectId,
        ];
        $wasInTransaction = $this->inTransaction;
        $this->inTransaction = true;
        try {
            return $work();
        } catch (Throwable $exception) {
            [$this->effects, $this->nextEffectId] = $snapshot;
            ++$this->rollbacks;
            throw $exception;
        } finally {
            $this->inTransaction = $wasInTransaction;
        }
    }

    public function decisionForUpdate(int $decisionId): ?array
    {
        return $this->decisions[$decisionId] ?? null;
    }

    public function caseForUpdate(int $caseId): ?array
    {
        return $this->cases[$caseId] ?? null;
    }

    public function appealForUpdate(int $appealId): ?array
    {
        return $this->appeals[$appealId] ?? null;
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

    public function applyEffectsForDecisionForUpdate(int $decisionId): array
    {
        return array_values(array_filter(
            $this->effects,
            static fn (array $effect): bool => (int) $effect['decision_id'] === $decisionId
                && (string) $effect['direction'] === 'apply'
        ));
    }

    public function reversalForEffectForUpdate(int $effectId): ?array
    {
        foreach ($this->effects as $effect) {
            if ((int) ($effect['reverses_effect_id'] ?? 0) === $effectId) {
                return $effect;
            }
        }

        return null;
    }

    public function dueEffectIdsForDispatch(int $limit, string $dueAt): array
    {
        $ids = [];
        foreach ($this->effects as $id => $effect) {
            $status = (string) ($effect['status'] ?? '');
            $nextAttemptAt = $effect['next_attempt_at'] ?? null;
            $leaseExpiresAt = $effect['lease_expires_at'] ?? null;
            if ((in_array($status, ['pending', 'retry'], true)
                    && ($nextAttemptAt === null || (string) $nextAttemptAt <= $dueAt))
                || ($status === 'processing'
                    && $leaseExpiresAt !== null
                    && (string) $leaseExpiresAt <= $dueAt)) {
                $ids[] = (int) $id;
            }
        }

        return array_slice($ids, 0, $limit);
    }

    public function insertEffect(array $effect): int
    {
        $id = $this->nextEffectId++;
        $this->effects[$id] = ['id' => $id] + $effect + [
            'attempt_count' => 0,
            'next_attempt_at' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'accepted_reference' => null,
            'accepted_at' => null,
            'last_error_code' => null,
        ];

        return $id;
    }

    public function claimEffect(
        int $effectId,
        string $leaseToken,
        string $claimedAt,
        string $leaseExpiresAt
    ): bool {
        $effect = $this->effects[$effectId] ?? null;
        if ($effect === null) {
            return false;
        }
        $status = (string) $effect['status'];
        $isDue = in_array($status, ['pending', 'retry'], true)
            || ($status === 'processing'
                && $effect['lease_expires_at'] !== null
                && (string) $effect['lease_expires_at'] <= $claimedAt);
        if (!$isDue) {
            return false;
        }

        $this->effects[$effectId]['status'] = 'processing';
        $this->effects[$effectId]['attempt_count'] = (int) $effect['attempt_count'] + 1;
        $this->effects[$effectId]['next_attempt_at'] = null;
        $this->effects[$effectId]['lease_token'] = $leaseToken;
        $this->effects[$effectId]['lease_expires_at'] = $leaseExpiresAt;
        $this->effects[$effectId]['last_error_code'] = null;

        return true;
    }

    public function markEffectAccepted(
        int $effectId,
        string $leaseToken,
        ?string $financeReference,
        string $acceptedAt
    ): bool {
        if (($this->effects[$effectId]['status'] ?? null) !== 'processing'
            || ($this->effects[$effectId]['lease_token'] ?? null) !== $leaseToken) {
            return false;
        }
        $this->effects[$effectId]['status'] = 'accepted';
        $this->effects[$effectId]['lease_token'] = null;
        $this->effects[$effectId]['lease_expires_at'] = null;
        $this->effects[$effectId]['accepted_reference'] = $financeReference;
        $this->effects[$effectId]['accepted_at'] = $acceptedAt;
        $this->effects[$effectId]['last_error_code'] = null;

        return true;
    }

    public function markEffectForRetry(
        int $effectId,
        string $leaseToken,
        string $reasonCode,
        string $nextAttemptAt
    ): bool {
        if (($this->effects[$effectId]['status'] ?? null) !== 'processing'
            || ($this->effects[$effectId]['lease_token'] ?? null) !== $leaseToken) {
            return false;
        }
        $this->effects[$effectId]['status'] = 'retry';
        $this->effects[$effectId]['lease_token'] = null;
        $this->effects[$effectId]['lease_expires_at'] = null;
        $this->effects[$effectId]['last_error_code'] = $reasonCode;
        $this->effects[$effectId]['next_attempt_at'] = $nextAttemptAt;

        return true;
    }

    public function markEffectRejected(
        int $effectId,
        string $leaseToken,
        string $reasonCode
    ): bool {
        if (($this->effects[$effectId]['status'] ?? null) !== 'processing'
            || ($this->effects[$effectId]['lease_token'] ?? null) !== $leaseToken) {
            return false;
        }
        $this->effects[$effectId]['status'] = 'rejected';
        $this->effects[$effectId]['lease_token'] = null;
        $this->effects[$effectId]['lease_expires_at'] = null;
        $this->effects[$effectId]['last_error_code'] = $reasonCode;
        $this->effects[$effectId]['next_attempt_at'] = null;

        return true;
    }

    public function cancelQueuedApplyEffect(int $effectId, string $reasonCode): bool
    {
        if (($this->effects[$effectId]['direction'] ?? null) !== 'apply'
            || !in_array((string) ($this->effects[$effectId]['status'] ?? ''), ['pending', 'retry'], true)) {
            return false;
        }
        $this->effects[$effectId]['status'] = 'cancelled';
        $this->effects[$effectId]['next_attempt_at'] = null;
        $this->effects[$effectId]['lease_token'] = null;
        $this->effects[$effectId]['lease_expires_at'] = null;
        $this->effects[$effectId]['last_error_code'] = $reasonCode;

        return true;
    }
}

final class MemoryDisciplineFinanceAudit implements AuditEventWriter
{
    /** @var list<array{action:string,record_id:int|string}> */
    public array $events = [];

    public function __construct(private MemoryDisciplineFinanceEffectRepository $repository)
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
            throw new RuntimeException('DISCIPLINE_FINANCE_AUDIT_OUTSIDE_TRANSACTION');
        }
        $this->events[] = ['action' => $action, 'record_id' => $recordId];
    }
}

final class MemoryDisciplinePayrollGateway implements PayrollImpactGateway
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    /** @var list<array{accepted:bool,status:string,finance_reference:?string}> */
    public array $responses = [];

    public function submitFacts(
        string $effectKey,
        int $staffId,
        string $factType,
        string $units,
        string $effectivePeriod,
        string $sourceRef,
        array $metadata
    ): array {
        $this->calls[] = [
            'effect_key' => $effectKey,
            'staff_id' => $staffId,
            'fact_type' => $factType,
            'units' => $units,
            'effective_period' => $effectivePeriod,
            'source_ref' => $sourceRef,
            'metadata' => $metadata,
        ];

        return array_shift($this->responses) ?? [
            'accepted' => true,
            'status' => 'accepted',
            'finance_reference' => 'FIN-' . count($this->calls),
        ];
    }
}

$repository = new MemoryDisciplineFinanceEffectRepository();
$audit = new MemoryDisciplineFinanceAudit($repository);
$gateway = new MemoryDisciplinePayrollGateway();
$service = new DisciplineFinanceEffectService($repository, $gateway, $audit);
$failures = [];
$assert = static function (string $name, bool $passed) use (&$failures): void {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
};

$decision = static function (int $id, int $caseId, string $units = '1.000'): array {
    return [
        'id' => $id,
        'case_id' => $caseId,
        'status' => 'issued',
        'sanction_code' => 'discipline_unpaid_day',
        'policy_snapshot' => json_encode([
            'finance_effect' => [
                'fact_type' => 'discipline_unpaid_day',
                'effect_code' => 'discipline_unpaid_day',
                'units' => $units,
                'effective_from' => '2026-08-12',
                'effective_to' => '2026-08-12',
                'effective_period' => '2026-08',
            ],
        ], JSON_THROW_ON_ERROR),
        'financial_effect_requested' => 1,
        'decision_hash' => hash('sha256', 'decision-' . $id),
    ];
};
$case = static function (int $id, string $status = 'decided'): array {
    return [
        'id' => $id,
        'case_no' => 'DISC-' . $id,
        'subject_staff_user_id' => 41,
        'status' => $status,
        'lock_version' => 1,
    ];
};

$repository->cases[700] = $case(700);
$repository->decisions[801] = $decision(801, 700);
$queued = $service->queueForIssuedDecision(801, 900, new DateTimeImmutable('2026-08-01 08:00:00 UTC'));
$replay = $service->queueForIssuedDecision(801, 900, new DateTimeImmutable('2026-08-01 08:01:00 UTC'));
$rejectedEffectId = (int) ($queued['effect_id'] ?? 0);
$rejectedPayload = json_decode((string) ($repository->effects[$rejectedEffectId]['payload_json'] ?? ''), true);
$assert(
    'issued_decision_creates_one_policy_frozen_fact_without_money_or_reason',
    $queued['status'] === 'queued'
        && $replay['status'] === 'idempotent_replay'
        && $replay['effect_id'] === $rejectedEffectId
        && count($repository->effects) === 1
        && ($repository->effects[$rejectedEffectId]['units'] ?? '') === '1.000'
        && !array_key_exists('amount', (array) ($rejectedPayload['metadata'] ?? []))
        && !array_key_exists('decision_reason', (array) ($rejectedPayload['metadata'] ?? []))
);

$gateway->responses[] = [
    'accepted' => false,
    'status' => 'period_closed',
    'finance_reference' => null,
];
$periodClosed = $service->dispatchEffect(
    $rejectedEffectId,
    901,
    new DateTimeImmutable('2026-08-01 08:02:00 UTC')
);
$repeatClosed = $service->dispatchEffect(
    $rejectedEffectId,
    901,
    new DateTimeImmutable('2026-08-01 08:03:00 UTC')
);
$assert(
    'posted_period_rejection_is_final_and_never_resubmits_duplicate_fact',
    $periodClosed['status'] === 'rejected'
        && $repeatClosed['status'] === 'rejected'
        && ($repository->effects[$rejectedEffectId]['status'] ?? '') === 'rejected'
        && ($repository->effects[$rejectedEffectId]['last_error_code'] ?? '') === 'FINANCE_PERIOD_CLOSED'
        && count($gateway->calls) === 1
);

$repository->cases[701] = $case(701);
$repository->decisions[802] = $decision(802, 701, '2.000');
$acceptedQueue = $service->queueForIssuedDecision(802, 900, new DateTimeImmutable('2026-08-01 09:00:00 UTC'));
$acceptedApplyId = (int) ($acceptedQueue['effect_id'] ?? 0);
$accepted = $service->dispatchEffect(
    $acceptedApplyId,
    901,
    new DateTimeImmutable('2026-08-01 09:01:00 UTC')
);
$repository->cases[701]['status'] = 'revoked';
$repository->appeals[901] = [
    'id' => 901,
    'case_id' => 701,
    'decision_id' => 802,
    'appellant_user_id' => 41,
    'status' => 'revoked',
    'appeal_hash' => hash('sha256', 'appeal-901'),
    'lock_version' => 2,
];
$reversal = $service->queueReversalForResolvedAppeal(
    901,
    902,
    new DateTimeImmutable('2026-08-01 10:00:00 UTC')
);
$reversalReplay = $service->queueReversalForResolvedAppeal(
    901,
    902,
    new DateTimeImmutable('2026-08-01 10:01:00 UTC')
);
$reversalId = (int) ($reversal['effect_id'] ?? 0);
$reversalPayload = json_decode((string) ($repository->effects[$reversalId]['payload_json'] ?? ''), true);
$assert(
    'revoked_appeal_queues_one_immutable_signed_reversal_and_replays_safely',
    $accepted['accepted']
        && $reversal['status'] === 'queued'
        && $reversalReplay['status'] === 'idempotent_replay'
        && $reversalReplay['effect_id'] === $reversalId
        && ($repository->effects[$reversalId]['direction'] ?? '') === 'reverse'
        && ($repository->effects[$reversalId]['reverses_effect_id'] ?? 0) === $acceptedApplyId
        && ($repository->effects[$reversalId]['units'] ?? '') === '-2.000'
        && (($reversalPayload['metadata']['appeal_outcome'] ?? '') === 'revoked')
);

$reversalDispatch = $service->dispatchEffect(
    $reversalId,
    901,
    new DateTimeImmutable('2026-08-01 10:02:00 UTC')
);
$lastGatewayCall = end($gateway->calls);
$assert(
    'reversal_reaches_finance_only_through_fact_gateway_with_no_confidential_reason',
    $reversalDispatch['accepted']
        && ($lastGatewayCall['source_ref'] ?? null) === 'staff_discipline_appeal:901'
        && ($lastGatewayCall['units'] ?? null) === '-2.000'
        && !array_key_exists('reason', (array) ($lastGatewayCall['metadata'] ?? []))
        && !array_key_exists('amount', (array) ($lastGatewayCall['metadata'] ?? []))
);

$repository->cases[702] = $case(702);
$repository->decisions[803] = $decision(803, 702, '0.500');
$pendingQueue = $service->queueForIssuedDecision(803, 900, new DateTimeImmutable('2026-08-01 11:00:00 UTC'));
$pendingApplyId = (int) ($pendingQueue['effect_id'] ?? 0);
$repository->cases[702]['status'] = 'amended';
$repository->appeals[902] = [
    'id' => 902,
    'case_id' => 702,
    'decision_id' => 803,
    'appellant_user_id' => 41,
    'status' => 'amended',
    'appeal_hash' => hash('sha256', 'appeal-902'),
    'lock_version' => 2,
];
$cancelled = $service->queueReversalForResolvedAppeal(
    902,
    902,
    new DateTimeImmutable('2026-08-01 11:01:00 UTC')
);
$assert(
    'unaccepted_apply_fact_is_cancelled_instead_of_sending_an_unnecessary_reversal',
    $cancelled['status'] === 'cancelled_unaccepted'
        && ($repository->effects[$pendingApplyId]['status'] ?? '') === 'cancelled'
        && count($repository->effects) === 4
);

$serviceSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Application/Discipline/DisciplineFinanceEffectService.php'
);
$repositorySource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/PdoDisciplineFinanceEffectRepository.php'
);
$handlerSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Application/Discipline/DisciplineDecisionApprovalOutcomeHandler.php'
);
$assert(
    'discipline_adapter_has_no_direct_finance_table_write_and_final_approval_only_queues_local_intent',
    preg_match('/\b(?:FROM|JOIN|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+finance_/i', $serviceSource) !== 1
        && preg_match('/\b(?:FROM|JOIN|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:finance|payroll)_/i', $repositorySource) !== 1
        && str_contains($serviceSource, 'PayrollImpactGateway')
        && str_contains($handlerSource, 'DisciplineFinanceEffectQueue')
        && !str_contains($handlerSource, 'PayrollImpactGateway')
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Staff-HR discipline Finance integration failure(s).\n");
    exit(1);
}

echo "Staff-HR discipline Finance integration tests passed.\n";
