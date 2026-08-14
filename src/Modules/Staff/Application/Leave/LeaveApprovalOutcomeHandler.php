<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerGateway;
use EduCore\Modules\Staff\Contracts\LeaveBalanceMovementLookup;
use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\LeaveRequestRepository;
use EduCore\Modules\Staff\Domain\Leave\LeaveUnits;
use InvalidArgumentException;
use JsonException;

/**
 * Applies an immutable final workflow decision to a Staff leave request.
 *
 * A normal leave/extension consumes (or releases) its submit-time reservation.
 * An approved early-return/cancellation restores only its own approved portion
 * of the direct parent's historical consumption. Attendance is notified by a
 * narrow contract and Finance receives an outbox fact, never a direct write.
 */
final class LeaveApprovalOutcomeHandler implements ApprovalWorkflowOutcomeHandler
{
    private const RESOURCE_TYPE = 'leave_request';

    /** @var list<string> */
    private const OUTCOMES = ['approved', 'rejected'];

    /** @var list<string> */
    private const RESERVING_KINDS = ['leave', 'extension'];

    /** @var list<string> */
    private const REVERSING_KINDS = ['early_return', 'cancellation'];

    public function __construct(
        private LeaveRequestRepository $requests,
        private LeaveBalanceLedgerGateway $balances,
        private LeaveBalanceMovementLookup $movementLookup,
        private AttendanceCoverageChangeGateway $attendance,
        private LeaveFinanceEffectQueue $financeEffects,
        private AuditEventWriter $audit
    ) {
    }

    /** @param array<string,mixed> $instance */
    public function apply(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void
    {
        if ((string) ($instance['resource_type'] ?? '') !== self::RESOURCE_TYPE) {
            throw new DomainException('APPROVAL_OUTCOME_RESOURCE_UNSUPPORTED');
        }
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('LEAVE_APPROVAL_OUTCOME_INVALID');
        }
        if ($actorId <= 0) {
            throw new InvalidArgumentException('APPROVAL_ACTOR_INVALID');
        }

        $instanceId = $this->positiveId($instance['id'] ?? $instance['instance_id'] ?? null, 'APPROVAL_INSTANCE_INVALID');
        $requestId = $this->positiveId($instance['resource_id'] ?? null, 'LEAVE_REQUEST_ID_INVALID');
        $request = $this->requests->requestForUpdate($requestId);
        if ($request === null) {
            throw new DomainException('LEAVE_REQUEST_NOT_FOUND');
        }
        if ((string) ($request['status'] ?? '') !== 'pending_approval'
            || (int) ($request['workflow_instance_id'] ?? 0) !== $instanceId) {
            throw new DomainException('LEAVE_APPROVAL_OUTCOME_STALE');
        }

        $staffUserId = $this->positiveId($request['staff_user_id'] ?? null, 'LEAVE_REQUEST_STAFF_INVALID');
        $leaveTypeId = $this->positiveId($request['leave_type_id'] ?? null, 'LEAVE_REQUEST_TYPE_INVALID');
        $expectedLockVersion = $this->positiveId($request['lock_version'] ?? null, 'LEAVE_REQUEST_LOCK_INVALID');
        if (!$this->requests->lockStaffForRequest($staffUserId)) {
            throw new DomainException('LEAVE_REQUEST_STAFF_NOT_FOUND');
        }

        $snapshot = $this->policySnapshot($request['policy_snapshot'] ?? null);
        $requestHash = $this->requestHash($request['request_hash'] ?? null);
        $kind = $this->requestKind($request['request_kind'] ?? null);
        $days = $this->effectiveDays($this->requests->daysForRequestForUpdate($requestId));
        if ($days === []) {
            throw new DomainException('LEAVE_REQUEST_DAYS_MISSING');
        }

        $movementCount = 0;
        if (in_array($kind, self::RESERVING_KINDS, true)) {
            foreach ($days as $day) {
                $this->settleReservedDay(
                    $request,
                    $day,
                    $outcome,
                    $instanceId,
                    $actorId
                );
                ++$movementCount;
            }
        } elseif ($outcome === 'approved') {
            $parent = $this->approvedParent($request, $staffUserId, $leaveTypeId);
            $parentDays = $this->effectiveDays(
                $this->requests->daysForRequestForUpdate(
                    $this->positiveId($parent['id'] ?? null, 'LEAVE_REQUEST_PARENT_INVALID')
                )
            );
            foreach ($days as $day) {
                $this->restoreParentConsumption(
                    $request,
                    $day,
                    $parent,
                    $parentDays,
                    $instanceId,
                    $actorId
                );
                ++$movementCount;
            }
        }

        if (!$this->requests->finalizeWorkflowOutcome(
            $requestId,
            $expectedLockVersion,
            $outcome,
            $occurredAt
        )) {
            throw new DomainException('LEAVE_APPROVAL_OUTCOME_STALE');
        }

        $after = array_replace($request, [
            'status' => $outcome,
            'decided_at' => $occurredAt->format('Y-m-d H:i:s.u'),
            'approved_at' => $outcome === 'approved' ? $occurredAt->format('Y-m-d H:i:s.u') : ($request['approved_at'] ?? null),
            'lock_version' => $expectedLockVersion + 1,
        ]);
        $coveragePublication = null;
        $financeQueue = null;
        if ($outcome === 'approved') {
            $coveragePublication = $this->publishCoverage(
                $after,
                $days,
                $kind,
                $requestHash,
                $instanceId,
                $actorId
            );
            $financeQueue = $this->financeEffects->queueForApprovedRequest($requestId, $actorId, $occurredAt);
        }

        $this->audit->recordEvent(
            'staff_leave_request_approval_finalized',
            'staff_leave_requests',
            $requestId,
            $outcome,
            [
                'approval_instance_id' => $instanceId,
                'staff_user_id' => $staffUserId,
                'leave_type_id' => $leaveTypeId,
                'request_kind' => $kind,
                'outcome' => $outcome,
                'movement_count' => $movementCount,
                'coverage_published_count' => (int) ($coveragePublication['published_count'] ?? 0),
                'finance_effect_count' => count((array) ($financeQueue['effect_ids'] ?? [])),
                'finance_replayed_effect_count' => count((array) ($financeQueue['replayed_effect_ids'] ?? [])),
                'policy_schema_version' => (int) ($snapshot['schema_version'] ?? 0),
            ],
            ['user_id' => $actorId, 'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u')]
        );
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $day */
    private function settleReservedDay(
        array $request,
        array $day,
        string $outcome,
        int $instanceId,
        int $actorId
    ): void {
        $requestId = $this->positiveId($request['id'] ?? null, 'LEAVE_REQUEST_ID_INVALID');
        $dayId = $this->positiveId($day['id'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED');
        $units = $this->positiveUnits($day['requested_units'] ?? null, 'LEAVE_REQUEST_DAY_UNITS_INVALID');
        $submissionKey = trim((string) ($request['submission_idempotency_key'] ?? ''));
        if ($submissionKey === '') {
            throw new DomainException('LEAVE_REQUEST_SUBMISSION_EVIDENCE_INVALID');
        }
        $reservationKey = 'leave-reserve:' . hash('sha256', $submissionKey . ':' . $dayId);
        $reservation = $this->movementLookup->movementByIdempotencyForUpdate($reservationKey);
        if ($reservation === null
            || (string) ($reservation['movement_type'] ?? '') !== 'reserve'
            || (int) ($reservation['leave_request_id'] ?? 0) !== $requestId
            || (int) ($reservation['request_day_id'] ?? 0) !== $dayId
            || $this->positiveUnits($reservation['reserved_delta'] ?? null, 'LEAVE_APPROVAL_RESERVATION_INVALID') !== $units) {
            throw new DomainException('LEAVE_APPROVAL_RESERVATION_MISSING');
        }
        $account = $this->accountForMovement(
            $reservation,
            (int) ($request['staff_user_id'] ?? 0),
            (int) ($request['leave_type_id'] ?? 0),
            (string) ($day['entitlement_period_key'] ?? '')
        );
        $action = $outcome === 'approved' ? 'consume' : 'release';
        $this->balances->record([
            'actor_id' => $actorId,
            'account' => $account,
            'leave_request_id' => $requestId,
            'request_day_id' => $dayId,
            'movement_type' => $action,
            'units' => LeaveUnits::format($units),
            'source_type' => 'leave_request',
            'source_id' => $requestId,
            'logical_key' => hash('sha256', 'leave-approval:' . $action . ':' . $instanceId . ':' . $dayId),
            'idempotency_key' => $this->ledgerIdempotency($action, $instanceId, $dayId),
            'reason_code' => $outcome === 'approved' ? 'leave_approved' : 'leave_rejected',
        ]);
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $successorDay
     * @param array<string,mixed> $parent
     * @param list<array<string,mixed>> $parentDays
     */
    private function restoreParentConsumption(
        array $request,
        array $successorDay,
        array $parent,
        array $parentDays,
        int $instanceId,
        int $actorId
    ): void {
        $requestId = $this->positiveId($request['id'] ?? null, 'LEAVE_REQUEST_ID_INVALID');
        $successorDayId = $this->positiveId($successorDay['id'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED');
        $successorUnits = $this->positiveUnits(
            $successorDay['requested_units'] ?? null,
            'LEAVE_REQUEST_DAY_UNITS_INVALID'
        );
        $parentDay = $this->parentDayForSuccessor($successorDay, $parentDays);
        $parentDayId = $this->positiveId($parentDay['id'] ?? null, 'LEAVE_REQUEST_PARENT_ALLOCATION_MISSING');
        $parentWorkflowId = $this->positiveId(
            $parent['workflow_instance_id'] ?? null,
            'LEAVE_REQUEST_PARENT_WORKFLOW_INVALID'
        );
        $consumptionKey = $this->ledgerIdempotency('consume', $parentWorkflowId, $parentDayId);
        $consumption = $this->movementLookup->movementByIdempotencyForUpdate($consumptionKey);
        if ($consumption === null
            || (string) ($consumption['movement_type'] ?? '') !== 'consume'
            || (int) ($consumption['leave_request_id'] ?? 0) !== (int) $parent['id']
            || (int) ($consumption['request_day_id'] ?? 0) !== $parentDayId) {
            throw new DomainException('LEAVE_APPROVAL_PARENT_CONSUMPTION_MISSING');
        }
        $consumedUnits = $this->positiveUnits(
            $consumption['consumed_delta'] ?? null,
            'LEAVE_APPROVAL_PARENT_CONSUMPTION_INVALID'
        );
        if ($successorUnits > $consumedUnits) {
            throw new DomainException('LEAVE_APPROVAL_RESTORE_EXCEEDS_PARENT_CONSUMPTION');
        }
        $restoreIdempotencyKey = $this->ledgerIdempotency('restore', $instanceId, $successorDayId);
        $existingRestore = $this->movementLookup->movementByIdempotencyForUpdate($restoreIdempotencyKey);
        if ($existingRestore === null) {
            $restoredUnits = 0;
            foreach ($this->movementLookup->restorationMovementsForSourceForUpdate(
                $this->positiveId($consumption['id'] ?? null, 'LEAVE_APPROVAL_PARENT_CONSUMPTION_INVALID')
            ) as $restoration) {
                if ((string) ($restoration['movement_type'] ?? '') !== 'restore'
                    || (string) ($restoration['source_type'] ?? '') !== 'leave_balance_movement'
                    || (int) ($restoration['source_id'] ?? 0) !== (int) $consumption['id']) {
                    throw new DomainException('LEAVE_APPROVAL_RESTORE_EVIDENCE_INVALID');
                }
                $restoredUnits += $this->positiveUnits(
                    $restoration['available_delta'] ?? null,
                    'LEAVE_APPROVAL_RESTORE_EVIDENCE_INVALID'
                );
            }
            if ($restoredUnits + $successorUnits > $consumedUnits) {
                throw new DomainException('LEAVE_APPROVAL_RESTORE_EXCEEDS_PARENT_CONSUMPTION');
            }
        }

        $account = $this->accountForMovement(
            $consumption,
            (int) ($request['staff_user_id'] ?? 0),
            (int) ($request['leave_type_id'] ?? 0),
            (string) ($successorDay['entitlement_period_key'] ?? '')
        );
        $this->balances->record([
            'actor_id' => $actorId,
            'account' => $account,
            'leave_request_id' => $requestId,
            'request_day_id' => $successorDayId,
            'movement_type' => 'restore',
            'units' => LeaveUnits::format($successorUnits),
            'source_type' => 'leave_balance_movement',
            'source_id' => $this->positiveId($consumption['id'] ?? null, 'LEAVE_APPROVAL_PARENT_CONSUMPTION_INVALID'),
            'logical_key' => hash(
                'sha256',
                'leave-approval:restore:' . $instanceId . ':' . $successorDayId . ':' . $consumption['id']
            ),
            'idempotency_key' => $restoreIdempotencyKey,
            'reason_code' => (string) ($request['request_kind'] ?? '') === 'early_return'
                ? 'leave_early_return_approved'
                : 'leave_cancellation_approved',
        ]);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function approvedParent(array $request, int $staffUserId, int $leaveTypeId): array
    {
        $parentId = $this->positiveId($request['parent_request_id'] ?? null, 'LEAVE_REQUEST_PARENT_INVALID');
        $parent = $this->requests->requestForUpdate($parentId);
        if ($parent === null
            || (string) ($parent['status'] ?? '') !== 'approved'
            || (int) ($parent['staff_user_id'] ?? 0) !== $staffUserId
            || (int) ($parent['leave_type_id'] ?? 0) !== $leaveTypeId) {
            throw new DomainException('LEAVE_REQUEST_PARENT_NOT_APPROVED');
        }

        return $parent;
    }

    /**
     * @param array<string,mixed> $successorDay
     * @param list<array<string,mixed>> $parentDays
     * @return array<string,mixed>
     */
    private function parentDayForSuccessor(array $successorDay, array $parentDays): array
    {
        $workDate = $this->dateKey($successorDay['work_date'] ?? null, 'LEAVE_REQUEST_DAY_DATE_INVALID');
        $periodKey = trim((string) ($successorDay['entitlement_period_key'] ?? ''));
        if ($periodKey === '') {
            throw new DomainException('LEAVE_REQUEST_DAY_PERIOD_INVALID');
        }
        $matches = array_values(array_filter(
            $parentDays,
            fn (array $parentDay): bool => $this->dateKey(
                $parentDay['work_date'] ?? null,
                'LEAVE_REQUEST_PARENT_ALLOCATION_MISSING'
            ) === $workDate
                && (string) ($parentDay['entitlement_period_key'] ?? '') === $periodKey
                && $this->positiveUnits(
                    $parentDay['requested_units'] ?? null,
                    'LEAVE_REQUEST_PARENT_ALLOCATION_MISSING'
                ) > 0
        ));
        if (count($matches) !== 1) {
            throw new DomainException('LEAVE_REQUEST_PARENT_ALLOCATION_MISSING');
        }

        return $matches[0];
    }

    /**
     * @param array<string,mixed> $request
     * @param list<array<string,mixed>> $days
     * @return array{published_count:int}
     */
    private function publishCoverage(
        array $request,
        array $days,
        string $kind,
        string $requestHash,
        int $instanceId,
        int $actorId
    ): array {
        $requestId = $this->positiveId($request['id'] ?? null, 'LEAVE_REQUEST_ID_INVALID');
        $staffUserId = $this->positiveId($request['staff_user_id'] ?? null, 'LEAVE_REQUEST_STAFF_INVALID');
        $eventType = in_array($kind, self::REVERSING_KINDS, true)
            ? 'coverage_reversed'
            : 'coverage_approved';
        $published = 0;
        foreach ($days as $day) {
            $dayId = $this->positiveId($day['id'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED');
            $workDate = $this->dateKey($day['work_date'] ?? null, 'LEAVE_REQUEST_DAY_DATE_INVALID');
            $fingerprint = hash('sha256', implode('|', [
                'leave-coverage-v1',
                $eventType,
                $requestId,
                $instanceId,
                $dayId,
                $requestHash,
                $workDate,
                (string) ($day['requested_units'] ?? ''),
                (string) ($day['requested_minutes'] ?? ''),
            ]));
            $this->attendance->publish([
                'actor_id' => $actorId,
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate,
                'event_type' => $eventType,
                'source_type' => 'leave_request',
                'source_id' => $requestId,
                'source_fingerprint' => $fingerprint,
                'reason_code' => $eventType === 'coverage_approved'
                    ? 'leave_coverage_approved'
                    : 'leave_coverage_reversed',
                'idempotency_key' => 'leave-coverage:' . $eventType . ':' . hash(
                    'sha256',
                    $requestId . ':' . $instanceId . ':' . $dayId . ':' . $fingerprint
                ),
            ]);
            ++$published;
        }

        return ['published_count' => $published];
    }

    /** @param array<string,mixed> $movement @return array<string,mixed> */
    private function accountForMovement(
        array $movement,
        int $staffUserId,
        int $leaveTypeId,
        string $entitlementPeriodKey
    ): array {
        $accountId = $this->positiveId($movement['account_id'] ?? null, 'LEAVE_APPROVAL_ACCOUNT_INVALID');
        $account = $this->movementLookup->accountByIdForUpdate($accountId);
        if ($account === null
            || (int) ($account['staff_user_id'] ?? 0) !== $staffUserId
            || (int) ($account['leave_type_id'] ?? 0) !== $leaveTypeId
            || !hash_equals((string) ($account['entitlement_period_key'] ?? ''), $entitlementPeriodKey)) {
            throw new DomainException('LEAVE_APPROVAL_ACCOUNT_INVALID');
        }

        return [
            'staff_user_id' => $staffUserId,
            'leave_type_id' => $leaveTypeId,
            'entitlement_period_key' => $entitlementPeriodKey,
            'period_from' => $this->dateKey($account['period_from'] ?? null, 'LEAVE_APPROVAL_ACCOUNT_INVALID'),
            'period_to' => $this->dateKey($account['period_to'] ?? null, 'LEAVE_APPROVAL_ACCOUNT_INVALID'),
            'negative_balance_limit_units' => $this->nonNegativeUnits(
                $account['negative_balance_limit_units'] ?? null,
                'LEAVE_APPROVAL_ACCOUNT_INVALID'
            ),
        ];
    }

    /** @param list<array<string,mixed>> $days @return list<array<string,mixed>> */
    private function effectiveDays(array $days): array
    {
        $effective = [];
        foreach ($days as $day) {
            if (!is_array($day)) {
                throw new DomainException('LEAVE_REQUEST_DAY_PERSIST_FAILED');
            }
            $units = LeaveUnits::fromDecimal(
                $day['requested_units'] ?? null,
                false,
                'LEAVE_REQUEST_DAY_UNITS_INVALID'
            );
            if ($units < 0) {
                throw new DomainException('LEAVE_REQUEST_DAY_UNITS_INVALID');
            }
            if ($units === 0) {
                continue;
            }
            $this->positiveId($day['id'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED');
            $this->dateKey($day['work_date'] ?? null, 'LEAVE_REQUEST_DAY_DATE_INVALID');
            $effective[] = $day;
        }

        return $effective;
    }

    /** @return array<string,mixed> */
    private function policySnapshot(mixed $value): array
    {
        if (is_array($value)) {
            $snapshot = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            try {
                $snapshot = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new DomainException('LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID', 0, $exception);
            }
        } else {
            throw new DomainException('LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID');
        }
        if (!is_array($snapshot)
            || !is_array($snapshot['policy'] ?? null)
            || !is_array($snapshot['leave_type'] ?? null)
            || !is_array($snapshot['allocation'] ?? null)) {
            throw new DomainException('LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID');
        }

        return $snapshot;
    }

    private function requestKind(mixed $value): string
    {
        $kind = strtolower(trim((string) $value));
        if (!in_array($kind, array_merge(self::RESERVING_KINDS, self::REVERSING_KINDS), true)) {
            throw new DomainException('LEAVE_REQUEST_KIND_INVALID');
        }

        return $kind;
    }

    private function ledgerIdempotency(string $action, int $instanceId, int $dayId): string
    {
        return 'leave-approval-' . $action . ':' . hash('sha256', $instanceId . ':' . $dayId);
    }

    private function requestHash(mixed $value): string
    {
        $hash = trim((string) $value);
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new DomainException('LEAVE_REQUEST_HASH_INVALID');
        }

        return $hash;
    }

    private function positiveUnits(mixed $value, string $error): int
    {
        $units = LeaveUnits::fromDecimal($value, false, $error);
        if ($units <= 0) {
            throw new DomainException($error);
        }

        return $units;
    }

    private function nonNegativeUnits(mixed $value, string $error): string
    {
        $units = LeaveUnits::fromDecimal($value, false, $error);
        if ($units < 0) {
            throw new DomainException($error);
        }

        return LeaveUnits::format($units);
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function dateKey(mixed $value, string $error): string
    {
        $date = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new DomainException($error);
        }

        return $date;
    }
}
