<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendancePeriodRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use JsonException;

/**
 * Makes the payroll/attendance cutoff explicit.
 *
 * A new affected-day fact is always persisted. While a period is open it is
 * ready for recalculation; while it is closed it becomes a reviewable reopen
 * request. This service never silently recalculates a closed month and never
 * invokes Finance.
 */
final class AttendancePeriodService
{
    public const ENGINE_VERSION = 'attendance-period-v1';

    /** @var list<string> */
    private const CHANGE_TYPES = [
        'late_event',
        'coverage_approved',
        'coverage_reversed',
        'leave_approved',
        'leave_reversed',
        'schedule_correction',
        'calendar_correction',
        'manual_recalculation',
    ];

    private DateTimeZone $utc;

    public function __construct(
        private AttendanceTransactionManager $transactions,
        private AttendancePeriodRepository $repository,
        private AuditEventWriter $audit
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    /** @return list<array<string,mixed>> */
    public function periods(int $limit = 24): array
    {
        return $this->repository->listPeriods($limit);
    }

    /** @return list<array<string,mixed>> */
    public function changeRequests(int $limit = 100): array
    {
        return $this->repository->listChangeRequests($limit);
    }

    /**
     * Publishes one durable fact that an attendance day may need recalculation.
     *
     * @return array<string,mixed>
     */
    public function requestAffectedDayChange(
        int $actorId,
        int $staffUserId,
        DateTimeImmutable $workDate,
        string $requestType,
        string $sourceType,
        ?int $sourceId,
        string $sourceFingerprint,
        string $reasonCode,
        string $idempotencyKey
    ): array {
        $this->assertActor($actorId);
        if ($staffUserId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_CHANGE_STAFF_ID_INVALID');
        }
        if ($sourceId !== null && $sourceId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_CHANGE_SOURCE_ID_INVALID');
        }
        $workDate = $workDate->setTime(0, 0, 0, 0);
        $requestType = $this->changeType($requestType);
        $sourceType = $this->identifier($sourceType, 'ATTENDANCE_PERIOD_CHANGE_SOURCE_TYPE_INVALID');
        $reasonCode = $this->identifier($reasonCode, 'ATTENDANCE_PERIOD_CHANGE_REASON_CODE_INVALID');
        $sourceFingerprint = $this->hashValue(
            $sourceFingerprint,
            'ATTENDANCE_PERIOD_CHANGE_SOURCE_FINGERPRINT_INVALID'
        );
        $idempotencyKey = $this->requiredText(
            $idempotencyKey,
            190,
            'ATTENDANCE_PERIOD_CHANGE_IDEMPOTENCY_KEY_INVALID'
        );
        $bounds = $this->periodBoundsForDate($workDate);
        $requestHash = $this->hash([
            'actor_id' => $actorId,
            'staff_user_id' => $staffUserId,
            'work_date' => $workDate->format('Y-m-d'),
            'request_type' => $requestType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_fingerprint' => $sourceFingerprint,
            'reason_code' => $reasonCode,
            'engine_version' => self::ENGINE_VERSION,
        ]);
        $changeFingerprint = $this->hash([
            'staff_user_id' => $staffUserId,
            'work_date' => $workDate->format('Y-m-d'),
            'request_type' => $requestType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_fingerprint' => $sourceFingerprint,
            'reason_code' => $reasonCode,
        ]);
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $staffUserId,
            $workDate,
            $requestType,
            $sourceType,
            $sourceId,
            $sourceFingerprint,
            $reasonCode,
            $idempotencyKey,
            $bounds,
            $requestHash,
            $changeFingerprint,
            $now
        ): array {
            $existing = $this->repository->changeByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (!hash_equals((string) ($existing['request_hash'] ?? ''), $requestHash)) {
                    throw new DomainException('ATTENDANCE_PERIOD_CHANGE_IDEMPOTENCY_CONFLICT');
                }

                return $this->changeReceipt($existing, true, false);
            }

            $period = $this->repository->ensurePeriodForUpdate(
                $bounds['key'],
                $bounds['start'],
                $bounds['end']
            );
            $periodId = $this->positiveId($period['id'] ?? null, 'ATTENDANCE_PERIOD_NOT_FOUND');
            $duplicateFact = $this->repository->changeByFingerprintForUpdate($periodId, $changeFingerprint);
            if ($duplicateFact !== null) {
                return $this->changeReceipt($duplicateFact, true, true);
            }

            $status = (string) ($period['state'] ?? '') === 'closed' ? 'pending' : 'ready';
            $changeId = $this->repository->insertChangeRequest([
                'period_id' => $periodId,
                'request_type' => $requestType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate->format('Y-m-d'),
                'source_fingerprint' => $sourceFingerprint,
                'reason_code' => $reasonCode,
                'status' => $status,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'change_fingerprint' => $changeFingerprint,
                'requested_by' => $actorId,
                'requested_at' => $this->databaseInstant($now),
                'lock_version' => 1,
            ]);
            if ($changeId <= 0) {
                throw new DomainException('ATTENDANCE_PERIOD_CHANGE_PERSISTENCE_FAILED');
            }
            $change = [
                'id' => $changeId,
                'period_id' => $periodId,
                'period_key' => $bounds['key'],
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate->format('Y-m-d'),
                'request_type' => $requestType,
                'status' => $status,
                'lock_version' => 1,
            ];
            $this->audit->recordEvent(
                'staff_attendance_period_change_requested',
                'staff_attendance_period_change_requests',
                $changeId,
                null,
                [
                    'period_id' => $periodId,
                    'staff_user_id' => $staffUserId,
                    'work_date' => $workDate->format('Y-m-d'),
                    'request_type' => $requestType,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'status' => $status,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->changeReceipt($change, false, false);
        });
    }

    /** @return array<string,mixed> */
    public function closePeriod(
        int $actorId,
        string $periodKey,
        int $expectedLockVersion,
        ?int $lastClosedRunId,
        string $reason
    ): array {
        $this->assertActor($actorId);
        if ($expectedLockVersion <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_LOCK_VERSION_INVALID');
        }
        if ($lastClosedRunId !== null && $lastClosedRunId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_CLOSE_RUN_ID_INVALID');
        }
        $bounds = $this->periodBoundsForKey($periodKey);
        $reason = $this->requiredText($reason, 2000, 'ATTENDANCE_PERIOD_CLOSE_REASON_REQUIRED');
        $reasonHash = hash('sha256', $reason);
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $expectedLockVersion,
            $lastClosedRunId,
            $bounds,
            $reasonHash,
            $now
        ): array {
            $period = $this->repository->ensurePeriodForUpdate(
                $bounds['key'],
                $bounds['start'],
                $bounds['end']
            );
            $periodId = $this->positiveId($period['id'] ?? null, 'ATTENDANCE_PERIOD_NOT_FOUND');
            if ((string) ($period['state'] ?? '') === 'closed') {
                if (
                    hash_equals((string) ($period['close_reason_hash'] ?? ''), $reasonHash)
                    && $this->sameNullableInt($period['last_closed_run_id'] ?? null, $lastClosedRunId)
                ) {
                    return $this->periodReceipt($period, true);
                }
                throw new DomainException('ATTENDANCE_PERIOD_ALREADY_CLOSED');
            }
            if ((int) ($period['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('ATTENDANCE_PERIOD_STALE');
            }
            if ($this->repository->hasUnappliedChangeRequestsForPeriodForUpdate($periodId)) {
                throw new DomainException('ATTENDANCE_PERIOD_UNAPPLIED_CHANGE_EXISTS');
            }
            if (!$this->repository->closePeriod(
                $periodId,
                $expectedLockVersion,
                $actorId,
                $now,
                $lastClosedRunId,
                $reasonHash
            )) {
                throw new DomainException('ATTENDANCE_PERIOD_STALE');
            }
            $after = [
                'id' => $periodId,
                'period_key' => $bounds['key'],
                'state' => 'closed',
                'lock_version' => $expectedLockVersion + 1,
                'last_closed_run_id' => $lastClosedRunId,
            ];
            $this->audit->recordEvent(
                'staff_attendance_period_closed',
                'staff_attendance_periods',
                $periodId,
                null,
                [
                    'before' => ['state' => 'open', 'lock_version' => $expectedLockVersion],
                    'after' => $after,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->periodReceipt($after, false);
        });
    }

    /**
     * Approves or rejects a closed-period change. Approval reopens the period
     * only when it is still closed; a request that remains pending after an
     * earlier reopen still needs its own explicit decision.
     *
     * @return array<string,mixed>
     */
    public function decideChangeRequest(
        int $actorId,
        int $changeRequestId,
        int $expectedChangeLockVersion,
        int $expectedPeriodLockVersion,
        string $decision,
        string $reviewComment,
        string $idempotencyKey
    ): array {
        $this->assertActor($actorId);
        if ($changeRequestId <= 0 || $expectedChangeLockVersion <= 0 || $expectedPeriodLockVersion <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_DECISION_VERSION_INVALID');
        }
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_DECISION_INVALID');
        }
        $reviewComment = trim($reviewComment);
        if ($decision === 'reject' && $reviewComment === '') {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_REJECTION_REASON_REQUIRED');
        }
        if (mb_strlen($reviewComment, 'UTF-8') > 2000) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_REVIEW_COMMENT_INVALID');
        }
        $idempotencyKey = $this->requiredText(
            $idempotencyKey,
            190,
            'ATTENDANCE_PERIOD_DECISION_IDEMPOTENCY_KEY_INVALID'
        );
        $reviewHash = $reviewComment === '' ? null : hash('sha256', $reviewComment);
        $decisionHash = $this->hash([
            'actor_id' => $actorId,
            'change_request_id' => $changeRequestId,
            'decision' => $decision,
            'review_comment_hash' => $reviewHash,
            'engine_version' => self::ENGINE_VERSION,
        ]);
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $changeRequestId,
            $expectedChangeLockVersion,
            $expectedPeriodLockVersion,
            $decision,
            $idempotencyKey,
            $reviewHash,
            $decisionHash,
            $now
        ): array {
            $change = $this->repository->changeRequestForUpdate($changeRequestId);
            if ($change === null) {
                throw new DomainException('ATTENDANCE_PERIOD_CHANGE_NOT_FOUND');
            }
            if ((string) ($change['decision_idempotency_key'] ?? '') === $idempotencyKey) {
                if (!hash_equals((string) ($change['decision_hash'] ?? ''), $decisionHash)) {
                    throw new DomainException('ATTENDANCE_PERIOD_DECISION_IDEMPOTENCY_CONFLICT');
                }

                return $this->decisionReceipt($change, true);
            }
            if ((string) ($change['status'] ?? '') !== 'pending') {
                if (hash_equals((string) ($change['decision_hash'] ?? ''), $decisionHash)) {
                    return $this->decisionReceipt($change, true);
                }
                throw new DomainException('ATTENDANCE_PERIOD_CHANGE_NOT_PENDING');
            }
            if ((int) ($change['lock_version'] ?? 0) !== $expectedChangeLockVersion) {
                throw new DomainException('ATTENDANCE_PERIOD_CHANGE_STALE');
            }
            $periodId = $this->positiveId($change['period_id'] ?? null, 'ATTENDANCE_PERIOD_NOT_FOUND');
            $period = $this->repository->periodByIdForUpdate($periodId);
            if ($period === null || (int) ($period['lock_version'] ?? 0) !== $expectedPeriodLockVersion) {
                throw new DomainException('ATTENDANCE_PERIOD_STALE');
            }
            $periodState = (string) ($period['state'] ?? '');
            if (!in_array($periodState, ['open', 'closed'], true)) {
                throw new DomainException('ATTENDANCE_PERIOD_STATE_INVALID');
            }

            $reopened = false;
            if ($decision === 'approve' && $periodState === 'closed') {
                if (!$this->repository->reopenPeriod($periodId, $expectedPeriodLockVersion, $actorId, $now)) {
                    throw new DomainException('ATTENDANCE_PERIOD_STALE');
                }
                $reopened = true;
            }
            $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
            if (!$this->repository->decideChangeRequest($changeRequestId, $expectedChangeLockVersion, [
                'status' => $newStatus,
                'reviewed_by' => $actorId,
                'reviewed_at' => $now,
                'review_comment_hash' => $reviewHash,
                'decision_idempotency_key' => $idempotencyKey,
                'decision_hash' => $decisionHash,
            ])) {
                throw new DomainException('ATTENDANCE_PERIOD_CHANGE_STALE');
            }
            $after = $change;
            $after['status'] = $newStatus;
            $after['lock_version'] = $expectedChangeLockVersion + 1;
            $after['decision_idempotency_key'] = $idempotencyKey;
            $after['decision_hash'] = $decisionHash;
            $this->audit->recordEvent(
                $decision === 'approve'
                    ? 'staff_attendance_period_reopen_approved'
                    : 'staff_attendance_period_reopen_rejected',
                'staff_attendance_period_change_requests',
                $changeRequestId,
                null,
                [
                    'before' => ['status' => 'pending', 'lock_version' => $expectedChangeLockVersion],
                    'after' => [
                        'period_id' => $periodId,
                        'status' => $newStatus,
                        'reopened' => $reopened,
                        'review_comment_hash' => $reviewHash,
                    ],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->decisionReceipt($after, false, $reopened, $expectedPeriodLockVersion + ($reopened ? 1 : 0));
        });
    }

    /**
     * Links a completed/no-change recalculation run to an approved open-period
     * fact. It refuses application if the period was closed again in between.
     *
     * @return array<string,mixed>
     */
    public function markChangeApplied(
        int $actorId,
        int $changeRequestId,
        int $expectedChangeLockVersion,
        int $recalculationRunId
    ): array {
        $this->assertActor($actorId);
        if ($changeRequestId <= 0 || $expectedChangeLockVersion <= 0 || $recalculationRunId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_APPLY_VERSION_INVALID');
        }
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $changeRequestId,
            $expectedChangeLockVersion,
            $recalculationRunId,
            $now
        ): array {
            $change = $this->repository->changeRequestForUpdate($changeRequestId);
            if ($change === null) {
                throw new DomainException('ATTENDANCE_PERIOD_CHANGE_NOT_FOUND');
            }
            if ((string) ($change['status'] ?? '') === 'applied') {
                if ((int) ($change['applied_run_id'] ?? 0) !== $recalculationRunId) {
                    throw new DomainException('ATTENDANCE_PERIOD_CHANGE_APPLY_CONFLICT');
                }

                return $this->changeReceipt($change, true, false);
            }
            if ((int) ($change['lock_version'] ?? 0) !== $expectedChangeLockVersion) {
                throw new DomainException('ATTENDANCE_PERIOD_CHANGE_STALE');
            }
            if (!in_array((string) ($change['status'] ?? ''), ['ready', 'approved'], true)) {
                throw new DomainException('ATTENDANCE_PERIOD_CHANGE_NOT_READY');
            }
            $periodId = $this->positiveId($change['period_id'] ?? null, 'ATTENDANCE_PERIOD_NOT_FOUND');
            $period = $this->repository->periodByIdForUpdate($periodId);
            if ($period === null || (string) ($period['state'] ?? '') !== 'open') {
                throw new DomainException('ATTENDANCE_PERIOD_CLOSED');
            }
            if (!$this->repository->applyChangeRequest(
                $changeRequestId,
                $expectedChangeLockVersion,
                $recalculationRunId,
                $now
            )) {
                throw new DomainException('ATTENDANCE_PERIOD_CHANGE_STALE');
            }
            $after = $change;
            $after['status'] = 'applied';
            $after['applied_run_id'] = $recalculationRunId;
            $after['lock_version'] = $expectedChangeLockVersion + 1;
            $this->audit->recordEvent(
                'staff_attendance_period_change_applied',
                'staff_attendance_period_change_requests',
                $changeRequestId,
                null,
                [
                    'before' => ['status' => $change['status'], 'lock_version' => $expectedChangeLockVersion],
                    'after' => [
                        'period_id' => $periodId,
                        'status' => 'applied',
                        'recalculation_run_id' => $recalculationRunId,
                    ],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->changeReceipt($after, false, false);
        });
    }


    /**
     * Applies several already-completed recalculation runs atomically. The
     * nested calls deliberately share the Attendance transaction manager,
     * whose PDO implementation uses savepoints; an exception rolls the outer
     * batch back so a close cannot observe a partially applied period.
     *
     * @param list<array{change_request_id:int,expected_lock_version:int,recalculation_run_id:int}> $commands
     * @return list<array<string,mixed>>
     */
    public function markChangesAppliedBatch(int $actorId, array $commands): array
    {
        $this->assertActor($actorId);
        if ($commands === [] || count($commands) > 500) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_APPLY_BATCH_INVALID');
        }
        $seen = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                throw new InvalidArgumentException('ATTENDANCE_PERIOD_APPLY_BATCH_INVALID');
            }
            $changeId = (int) ($command['change_request_id'] ?? 0);
            $lockVersion = (int) ($command['expected_lock_version'] ?? 0);
            $runId = (int) ($command['recalculation_run_id'] ?? 0);
            if ($changeId <= 0 || $lockVersion <= 0 || $runId <= 0 || isset($seen[$changeId])) {
                throw new InvalidArgumentException('ATTENDANCE_PERIOD_APPLY_BATCH_INVALID');
            }
            $seen[$changeId] = true;
        }

        return $this->transactions->transactional(function () use ($actorId, $commands): array {
            $receipts = [];
            foreach ($commands as $command) {
                $receipts[] = $this->markChangeApplied(
                    $actorId,
                    (int) $command['change_request_id'],
                    (int) $command['expected_lock_version'],
                    (int) $command['recalculation_run_id']
                );
            }

            return $receipts;
        });
    }


    /** @return array{key:string,start:string,end:string} */
    private function periodBoundsForDate(DateTimeImmutable $workDate): array
    {
        return $this->periodBoundsForKey($workDate->format('Y-m'));
    }

    /** @return array{key:string,start:string,end:string} */
    private function periodBoundsForKey(string $periodKey): array
    {
        $periodKey = trim($periodKey);
        if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $periodKey) !== 1) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_KEY_INVALID');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $periodKey . '-01', $this->utc);
        if (!$start instanceof DateTimeImmutable || $start->format('Y-m') !== $periodKey) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_KEY_INVALID');
        }
        $end = $start->modify('last day of this month');

        return [
            'key' => $periodKey,
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    private function changeType(string $requestType): string
    {
        $requestType = strtolower(trim($requestType));
        if (!in_array($requestType, self::CHANGE_TYPES, true)) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_CHANGE_TYPE_INVALID');
        }

        return $requestType;
    }

    private function identifier(string $value, string $error): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/', $value) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function hashValue(string $value, string $error): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_ACTOR_INVALID');
        }
    }

    private function requiredText(string $value, int $maxLength, string $error): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function positiveId(mixed $value, string $error): int
    {
        $value = (int) $value;
        if ($value <= 0) {
            throw new DomainException($error);
        }

        return $value;
    }

    private function sameNullableInt(mixed $left, ?int $right): bool
    {
        $left = $left === null ? null : (int) $left;

        return $left === $right;
    }

    /** @return array<string,mixed> */
    private function changeReceipt(array $change, bool $replayed, bool $deduplicated): array
    {
        $status = (string) ($change['status'] ?? '');

        return [
            'change_request_id' => $this->positiveId($change['id'] ?? null, 'ATTENDANCE_PERIOD_CHANGE_NOT_FOUND'),
            'period_id' => $this->positiveId($change['period_id'] ?? null, 'ATTENDANCE_PERIOD_NOT_FOUND'),
            'period_key' => isset($change['period_key']) ? (string) $change['period_key'] : null,
            'staff_user_id' => isset($change['staff_user_id']) ? (int) $change['staff_user_id'] : null,
            'work_date' => isset($change['work_date']) ? (string) $change['work_date'] : null,
            'request_type' => isset($change['request_type']) ? (string) $change['request_type'] : null,
            'status' => $status,
            'lock_version' => max(1, (int) ($change['lock_version'] ?? 1)),
            'next_action' => $status === 'pending'
                ? 'reopen_required'
                : (in_array($status, ['ready', 'approved'], true) ? 'recalculate_now' : 'none'),
            'replayed' => $replayed,
            'deduplicated' => $deduplicated,
        ];
    }

    /** @return array<string,mixed> */
    private function periodReceipt(array $period, bool $replayed): array
    {
        return [
            'period_id' => $this->positiveId($period['id'] ?? null, 'ATTENDANCE_PERIOD_NOT_FOUND'),
            'period_key' => (string) ($period['period_key'] ?? ''),
            'state' => (string) ($period['state'] ?? ''),
            'lock_version' => max(1, (int) ($period['lock_version'] ?? 1)),
            'last_closed_run_id' => isset($period['last_closed_run_id']) && $period['last_closed_run_id'] !== null
                ? (int) $period['last_closed_run_id']
                : null,
            'replayed' => $replayed,
        ];
    }

    /** @return array<string,mixed> */
    private function decisionReceipt(
        array $change,
        bool $replayed,
        bool $reopened = false,
        ?int $periodLockVersion = null
    ): array {
        $receipt = $this->changeReceipt($change, $replayed, false);
        $receipt['reopened'] = $reopened;
        $receipt['period_lock_version'] = $periodLockVersion;

        return $receipt;
    }

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    /** @param array<string,mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('ATTENDANCE_PERIOD_HASH_INVALID', 0, $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
