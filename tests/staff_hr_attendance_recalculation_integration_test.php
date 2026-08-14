<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\AttendancePeriodService;
use EduCore\Modules\Attendance\Contracts\AttendancePeriodRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

final class AttendancePeriodIntegrationState
{
    /** @var array<int,array<string,mixed>> */
    public array $periods = [];

    /** @var array<int,array<string,mixed>> */
    public array $changes = [];

    /** @var list<array<string,mixed>> */
    public array $auditEvents = [];

    public int $nextPeriodId = 1;
    public int $nextChangeId = 1;

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'periods' => $this->periods,
            'changes' => $this->changes,
            'auditEvents' => $this->auditEvents,
            'nextPeriodId' => $this->nextPeriodId,
            'nextChangeId' => $this->nextChangeId,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    public function restore(array $snapshot): void
    {
        $this->periods = $snapshot['periods'];
        $this->changes = $snapshot['changes'];
        $this->auditEvents = $snapshot['auditEvents'];
        $this->nextPeriodId = $snapshot['nextPeriodId'];
        $this->nextChangeId = $snapshot['nextChangeId'];
    }
}

final class AttendancePeriodIntegrationTransaction implements AttendanceTransactionManager
{
    public function __construct(private AttendancePeriodIntegrationState $state)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $snapshot = $this->state->snapshot();
        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->state->restore($snapshot);
            throw $exception;
        }
    }
}

final class AttendancePeriodIntegrationRepository implements AttendancePeriodRepository
{
    public function listPeriods(int $limit = 24): array { return array_values($this->state->periods); }
    public function listChangeRequests(int $limit = 100): array { return array_values($this->state->changes); }
    public function __construct(private AttendancePeriodIntegrationState $state)
    {
    }

    public function ensurePeriodForUpdate(string $periodKey, string $periodStart, string $periodEnd): array
    {
        foreach ($this->state->periods as $period) {
            if (($period['period_key'] ?? null) === $periodKey) {
                return $period;
            }
        }
        $id = $this->state->nextPeriodId++;
        return $this->state->periods[$id] = [
            'id' => $id,
            'period_key' => $periodKey,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'state' => 'open',
            'last_closed_run_id' => null,
            'close_reason_hash' => null,
            'lock_version' => 1,
        ];
    }

    public function periodByIdForUpdate(int $periodId): ?array
    {
        return $this->state->periods[$periodId] ?? null;
    }

    public function changeByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->state->changes as $change) {
            if (($change['idempotency_key'] ?? null) === $idempotencyKey) {
                return $change;
            }
        }

        return null;
    }

    public function changeByFingerprintForUpdate(int $periodId, string $changeFingerprint): ?array
    {
        foreach ($this->state->changes as $change) {
            if ((int) ($change['period_id'] ?? 0) === $periodId && ($change['change_fingerprint'] ?? null) === $changeFingerprint) {
                return $change;
            }
        }

        return null;
    }

    public function insertChangeRequest(array $change): int
    {
        $id = $this->state->nextChangeId++;
        $this->state->changes[$id] = ['id' => $id] + $change;

        return $id;
    }

    public function changeRequestForUpdate(int $changeRequestId): ?array
    {
        return $this->state->changes[$changeRequestId] ?? null;
    }

    public function hasUnappliedChangeRequestsForPeriodForUpdate(int $periodId): bool
    {
        foreach ($this->state->changes as $change) {
            if (
                (int) ($change['period_id'] ?? 0) === $periodId
                && in_array((string) ($change['status'] ?? ''), ['pending', 'ready', 'approved'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    public function closePeriod(
        int $periodId,
        int $expectedLockVersion,
        int $actorId,
        DateTimeImmutable $closedAt,
        ?int $lastClosedRunId,
        string $reasonHash
    ): bool {
        $period = $this->state->periods[$periodId] ?? null;
        if (
            $period === null
            || ($period['state'] ?? null) !== 'open'
            || (int) ($period['lock_version'] ?? 0) !== $expectedLockVersion
        ) {
            return false;
        }
        $this->state->periods[$periodId]['state'] = 'closed';
        $this->state->periods[$periodId]['last_closed_run_id'] = $lastClosedRunId;
        $this->state->periods[$periodId]['closed_by'] = $actorId;
        $this->state->periods[$periodId]['closed_at'] = $closedAt->format('Y-m-d H:i:s.u');
        $this->state->periods[$periodId]['close_reason_hash'] = $reasonHash;
        ++$this->state->periods[$periodId]['lock_version'];

        return true;
    }

    public function reopenPeriod(
        int $periodId,
        int $expectedLockVersion,
        int $actorId,
        DateTimeImmutable $reopenedAt
    ): bool {
        $period = $this->state->periods[$periodId] ?? null;
        if (
            $period === null
            || ($period['state'] ?? null) !== 'closed'
            || (int) ($period['lock_version'] ?? 0) !== $expectedLockVersion
        ) {
            return false;
        }
        $this->state->periods[$periodId]['state'] = 'open';
        $this->state->periods[$periodId]['reopened_by'] = $actorId;
        $this->state->periods[$periodId]['reopened_at'] = $reopenedAt->format('Y-m-d H:i:s.u');
        ++$this->state->periods[$periodId]['lock_version'];

        return true;
    }

    public function decideChangeRequest(int $changeRequestId, int $expectedLockVersion, array $decision): bool
    {
        $change = $this->state->changes[$changeRequestId] ?? null;
        if (
            $change === null
            || ($change['status'] ?? null) !== 'pending'
            || (int) ($change['lock_version'] ?? 0) !== $expectedLockVersion
        ) {
            return false;
        }
        foreach ($decision as $key => $value) {
            if ($key === 'reviewed_at' && $value instanceof DateTimeImmutable) {
                $value = $value->format('Y-m-d H:i:s.u');
            }
            $this->state->changes[$changeRequestId][$key] = $value;
        }
        ++$this->state->changes[$changeRequestId]['lock_version'];

        return true;
    }

    public function applyChangeRequest(
        int $changeRequestId,
        int $expectedLockVersion,
        int $runId,
        DateTimeImmutable $appliedAt
    ): bool {
        $change = $this->state->changes[$changeRequestId] ?? null;
        if (
            $change === null
            || !in_array((string) ($change['status'] ?? ''), ['ready', 'approved'], true)
            || (int) ($change['lock_version'] ?? 0) !== $expectedLockVersion
        ) {
            return false;
        }
        $this->state->changes[$changeRequestId]['status'] = 'applied';
        $this->state->changes[$changeRequestId]['applied_run_id'] = $runId;
        $this->state->changes[$changeRequestId]['applied_at'] = $appliedAt->format('Y-m-d H:i:s.u');
        ++$this->state->changes[$changeRequestId]['lock_version'];

        return true;
    }
}

final class AttendancePeriodIntegrationAudit implements AuditEventWriter
{
    public function __construct(private AttendancePeriodIntegrationState $state)
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
        $this->state->auditEvents[] = compact('action', 'entityType', 'recordId', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$throws = static function (callable $operation, string $expected, string $message) use (&$failures): void {
    try {
        $operation();
        fwrite(STDERR, "FAIL: {$message} (no exception)\n");
        ++$failures;
    } catch (Throwable $exception) {
        if ($exception->getMessage() !== $expected) {
            fwrite(STDERR, "FAIL: {$message} (got {$exception->getMessage()})\n");
            ++$failures;
        }
    }
};

$state = new AttendancePeriodIntegrationState();
$service = new AttendancePeriodService(
    new AttendancePeriodIntegrationTransaction($state),
    new AttendancePeriodIntegrationRepository($state),
    new AttendancePeriodIntegrationAudit($state)
);
$zone = new DateTimeZone('Africa/Cairo');
$january = new DateTimeImmutable('2026-01-05 00:00:00', $zone);
$hashA = str_repeat('a', 64);
$hashB = str_repeat('b', 64);
$hashC = str_repeat('c', 64);

$openChange = $service->requestAffectedDayChange(
    900, 701, $january, 'late_event', 'biometric_event', 1001, $hashA, 'LATE_EVENT_RECEIVED', 'period-open-1'
);
$openChangeId = (int) $openChange['change_request_id'];
$assert(($openChange['status'] ?? null) === 'ready' && ($openChange['next_action'] ?? null) === 'recalculate_now', 'open-period late event becomes a durable ready-to-recalculate fact');
$replay = $service->requestAffectedDayChange(
    900, 701, $january, 'late_event', 'biometric_event', 1001, $hashA, 'LATE_EVENT_RECEIVED', 'period-open-1'
);
$assert(($replay['replayed'] ?? null) === true && (int) ($replay['change_request_id'] ?? 0) === $openChangeId, 'same late-event idempotency key does not create another fact');
$throws(
    static fn () => $service->requestAffectedDayChange(
        900, 701, $january, 'late_event', 'biometric_event', 1001, $hashA, 'DIFFERENT_REASON', 'period-open-1'
    ),
    'ATTENDANCE_PERIOD_CHANGE_IDEMPOTENCY_CONFLICT',
    'reusing a late-event key with a different command fails closed'
);
$throws(
    static fn () => $service->closePeriod(900, '2026-01', 1, null, 'إقفال شهر يناير'),
    'ATTENDANCE_PERIOD_UNAPPLIED_CHANGE_EXISTS',
    'period cannot close while a ready recalculation fact is unapplied'
);
$service->markChangeApplied(900, $openChangeId, 1, 501);
$closedJanuary = $service->closePeriod(900, '2026-01', 1, 501, 'إقفال شهر يناير');
$assert(($closedJanuary['state'] ?? null) === 'closed' && (int) ($closedJanuary['lock_version'] ?? 0) === 2, 'period close advances an optimistic lock only after all facts are applied');

$lateApproval = $service->requestAffectedDayChange(
    900, 701, $january, 'coverage_approved', 'permission_request', 301, $hashB, 'PERMISSION_APPROVED', 'closed-late-approval-1'
);
$lateApprovalId = (int) $lateApproval['change_request_id'];
$assert(($lateApproval['status'] ?? null) === 'pending' && ($lateApproval['next_action'] ?? null) === 'reopen_required', 'approved coverage after closure becomes a reopen request rather than a silent recalculation');
$deduplicatedLateApproval = $service->requestAffectedDayChange(
    901, 701, $january, 'coverage_approved', 'permission_request', 301, $hashB, 'PERMISSION_APPROVED', 'closed-late-approval-2'
);
$assert(($deduplicatedLateApproval['deduplicated'] ?? null) === true && (int) ($deduplicatedLateApproval['change_request_id'] ?? 0) === $lateApprovalId, 'same late approval is deduplicated even with a distinct transport key');
$throws(
    static fn () => $service->markChangeApplied(900, $lateApprovalId, 1, 502),
    'ATTENDANCE_PERIOD_CHANGE_NOT_READY',
    'closed-period fact cannot be applied before explicit reopen approval'
);
$approved = $service->decideChangeRequest(900, $lateApprovalId, 1, 2, 'approve', '', 'closed-late-decision-1');
$assert(($approved['status'] ?? null) === 'approved' && ($approved['reopened'] ?? null) === true, 'approved late coverage explicitly reopens the closed period');
$assert(($state->periods[1]['state'] ?? null) === 'open' && (int) ($state->periods[1]['lock_version'] ?? 0) === 3, 'reopen is persisted with a new period version');
$approvedReplay = $service->decideChangeRequest(900, $lateApprovalId, 1, 2, 'approve', '', 'closed-late-decision-1');
$assert(($approvedReplay['replayed'] ?? null) === true, 'same reopen decision idempotency key replays without another reopen');

$reversal = $service->requestAffectedDayChange(
    900, 701, $january, 'coverage_reversed', 'permission_request', 301, $hashC, 'PERMISSION_REVERSED', 'coverage-reversal-1'
);
$reversalId = (int) $reversal['change_request_id'];
$assert(($reversal['status'] ?? null) === 'ready', 'coverage reversal after explicit reopen becomes a separately traceable recalculation fact');
$auditBeforeFailedBatch = count($state->auditEvents);
$throws(
    static fn () => $service->markChangesAppliedBatch(900, [
        ['change_request_id' => $lateApprovalId, 'expected_lock_version' => 2, 'recalculation_run_id' => 502],
        ['change_request_id' => $reversalId, 'expected_lock_version' => 99, 'recalculation_run_id' => 503],
    ]),
    'ATTENDANCE_PERIOD_CHANGE_STALE',
    'failed batch does not partially apply an approved change before a stale reversal'
);
$assert(($state->changes[$lateApprovalId]['status'] ?? null) === 'approved' && ($state->changes[$reversalId]['status'] ?? null) === 'ready', 'failed batch rolls all applied facts and audit writes back together');
$assert(count($state->auditEvents) === $auditBeforeFailedBatch, 'failed batch leaves no partial audit trail');
$batch = $service->markChangesAppliedBatch(900, [
    ['change_request_id' => $lateApprovalId, 'expected_lock_version' => 2, 'recalculation_run_id' => 502],
    ['change_request_id' => $reversalId, 'expected_lock_version' => 1, 'recalculation_run_id' => 503],
]);
$assert(count($batch) === 2 && ($state->changes[$lateApprovalId]['status'] ?? null) === 'applied' && ($state->changes[$reversalId]['status'] ?? null) === 'applied', 'approved coverage and its reversal apply atomically with their distinct recalculation runs');
$reclosedJanuary = $service->closePeriod(900, '2026-01', 3, 503, 'إقفال يناير بعد التصحيح');
$assert(($reclosedJanuary['state'] ?? null) === 'closed' && (int) ($reclosedJanuary['lock_version'] ?? 0) === 4, 'period can close again only after every reopened-period fact is applied');

$february = new DateTimeImmutable('2026-02-03 00:00:00', $zone);
$service->closePeriod(900, '2026-02', 1, null, 'إقفال فبراير');
$februaryLate = $service->requestAffectedDayChange(
    900, 701, $february, 'late_event', 'biometric_event', 2001, $hashA, 'LATE_EVENT_RECEIVED', 'february-late-1'
);
$throws(
    static fn () => $service->decideChangeRequest(900, (int) $februaryLate['change_request_id'], 1, 2, 'reject', '', 'february-reject-1'),
    'ATTENDANCE_PERIOD_REJECTION_REASON_REQUIRED',
    'rejection of a locked-period change requires a recorded reason'
);
$rejected = $service->decideChangeRequest(900, (int) $februaryLate['change_request_id'], 1, 2, 'reject', 'اكتمل الإقفال المالي لهذه الفترة', 'february-reject-1');
$assert(($rejected['status'] ?? null) === 'rejected' && ($state->periods[2]['state'] ?? null) === 'closed', 'rejection keeps the closed period closed and preserves the review fact');
$assert(
    count(array_filter($state->auditEvents, static fn (array $event): bool => array_key_exists('source_fingerprint', (array) ($event['details'] ?? [])))) === 0,
    'period audit details do not expose raw source fingerprints'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance period integration failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance period integration tests passed.\n";
