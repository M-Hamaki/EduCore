<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\LeaveWorkdayCalendarQuery;
use EduCore\Modules\Staff\Application\Policy\EffectivePolicyResolver;
use EduCore\Modules\Staff\Contracts\LeavePolicyReadRepository;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffEmploymentQuery;
use EduCore\Modules\Staff\Domain\Leave\LeaveEntitlementPeriod;
use EduCore\Modules\Staff\Domain\Leave\LeaveUnits;
use EduCore\Modules\Staff\Domain\Policy\EffectiveDatedPolicy;
use EduCore\Modules\Staff\Domain\Policy\PolicyScope;
use InvalidArgumentException;

/**
 * Resolves one effective leave policy and calculates an immutable draft
 * allocation from the Attendance-owned working calendar.
 *
 * It deliberately returns a quote rather than writing a request or balance.
 * Request state, staffing/blackout decisions, workflow, and ledger movements
 * remain separate owners so a quote cannot accidentally reserve entitlement.
 */
final class LeavePolicyService
{
    private const UNIT_SCALE = LeaveUnits::SCALE;
    private const MAX_REQUEST_CALENDAR_DAYS = 3660;

    public function __construct(
        private LeavePolicyReadRepository $policies,
        private StaffAssignmentAtDateQuery $assignments,
        private StaffEmploymentQuery $employment,
        private LeaveWorkdayCalendarQuery $calendar,
        private EffectivePolicyResolver $effectivePolicies
    ) {
    }

    /**
     * Build a validated leave allocation for one half-open request window.
     *
     * The caller must supply the server-side submission instant; it must not
     * pass a browser-controlled timestamp as evidence of notice or
     * retroactivity.
     *
     * @return array{
     *     staff_user_id:int,
     *     leave_type:array<string,mixed>,
     *     policy:array<string,mixed>,
     *     policy_snapshot:array<string,mixed>,
     *     assignment:array<string,mixed>,
     *     from_at:string,
     *     to_at:string,
     *     timezone:string,
     *     requested_units:string,
     *     requested_minutes:int,
     *     request_days:list<array<string,mixed>>
     * }
     */
    public function quote(
        int $staffUserId,
        int $leaveTypeId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        DateTimeImmutable $submittedAt
    ): array {
        if ($staffUserId <= 0) {
            throw new DomainException('LEAVE_STAFF_ID_INVALID');
        }
        if ($leaveTypeId <= 0) {
            throw new DomainException('LEAVE_TYPE_ID_INVALID');
        }
        if ($toAt <= $fromAt) {
            throw new DomainException('LEAVE_WINDOW_INVALID');
        }

        $type = $this->normalizeType($this->policies->findType($leaveTypeId), $leaveTypeId);
        $initial = $this->resolvePolicy($staffUserId, $leaveTypeId, $fromAt, $type);
        $policy = $initial['policy'];
        $timezone = $policy['timezone_object'];
        $fromAt = $fromAt->setTimezone($timezone);
        $toAt = $toAt->setTimezone($timezone);
        $submittedAt = $submittedAt->setTimezone($timezone);

        $this->assertWindowSize($fromAt, $toAt);
        $serviceStart = $this->serviceStartFor(
            $staffUserId,
            $fromAt,
            (int) $policy['min_service_months'] > 0
                || $policy['entitlement_period_type'] === 'service_anniversary'
        );
        $this->assertMinimumService($serviceStart, $fromAt, (int) $policy['min_service_months']);
        $this->assertTiming($fromAt, $submittedAt, $policy);

        $calendarDays = $this->calendar->daysIntersecting(
            $staffUserId,
            $fromAt,
            $toAt,
            $timezone
        );
        if ($calendarDays === []) {
            throw new DomainException('LEAVE_WORKDAY_CALENDAR_EMPTY');
        }

        $firstRequestedDate = $fromAt->setTime(0, 0, 0, 0);
        $lastRequestedDate = $toAt->modify('-1 microsecond')->setTime(0, 0, 0, 0);
        $unitMilli = 0;
        $requestedMinutes = 0;
        $requestDays = [];
        foreach ($calendarDays as $calendarDay) {
            if (!is_array($calendarDay)) {
                throw new DomainException('LEAVE_WORKDAY_CALENDAR_PAYLOAD_INVALID');
            }
            $requestDay = $this->calculateDay(
                $staffUserId,
                $leaveTypeId,
                $type,
                $policy,
                $fromAt,
                $toAt,
                $calendarDay,
                $firstRequestedDate,
                $lastRequestedDate,
                $serviceStart
            );
            if ($requestDay === null) {
                continue;
            }
            $requestDays[] = $requestDay;
            $unitMilli += (int) $requestDay['_units_milli'];
            $requestedMinutes += (int) $requestDay['requested_minutes'];
        }
        if ($unitMilli <= 0 || $requestedMinutes <= 0) {
            throw new DomainException('LEAVE_REQUEST_HAS_NO_WORKING_TIME');
        }

        $maxConsecutiveMilli = $policy['max_consecutive_units_milli'];
        if ($maxConsecutiveMilli !== null && $unitMilli > $maxConsecutiveMilli) {
            throw new DomainException('LEAVE_MAX_CONSECUTIVE_UNITS_EXCEEDED');
        }

        $this->assertPolicyStableForRequest(
            $staffUserId,
            $leaveTypeId,
            $type,
            $policy,
            $fromAt,
            $toAt,
            $requestDays
        );

        foreach ($requestDays as &$requestDay) {
            unset($requestDay['_units_milli'], $requestDay['_policy_probe_from'], $requestDay['_policy_probe_to']);
        }
        unset($requestDay);

        return [
            'staff_user_id' => $staffUserId,
            'leave_type' => $type,
            'policy' => $this->policyPublicView($policy),
            'policy_snapshot' => $this->policySnapshot($policy, $type, $initial['assignment'], $serviceStart),
            'assignment' => $initial['assignment'],
            'from_at' => $this->formatInstant($fromAt),
            'to_at' => $this->formatInstant($toAt),
            'timezone' => $timezone->getName(),
            'requested_units' => $this->formatMilli($unitMilli),
            'requested_minutes' => $requestedMinutes,
            'request_days' => $requestDays,
        ];
    }

    /**
     * @param array<string,mixed> $type
     * @return array{policy:array<string,mixed>,assignment:array<string,mixed>}
     */
    private function resolvePolicy(
        int $staffUserId,
        int $leaveTypeId,
        DateTimeImmutable $effectiveAt,
        array $type
    ): array {
        try {
            $assignment = $this->assignments->forStaff($staffUserId, $effectiveAt);
        } catch (\Throwable $exception) {
            throw new DomainException('LEAVE_STAFF_ASSIGNMENT_UNRESOLVED', 0, $exception);
        }
        if ($assignment === null || (string) ($assignment['employment_status'] ?? '') !== 'active') {
            throw new DomainException('LEAVE_STAFF_NOT_ACTIVE');
        }
        $assignment = $this->normalizeAssignment($assignment);

        try {
            $rawCandidates = $this->policies->candidateVersionsFor(
                $leaveTypeId,
                $staffUserId,
                $assignment,
                $effectiveAt
            );
        } catch (\Throwable $exception) {
            throw new DomainException('LEAVE_POLICY_QUERY_FAILED', 0, $exception);
        }

        $candidates = [];
        foreach ($rawCandidates as $candidate) {
            if (!is_array($candidate)
                || (int) ($candidate['leave_type_id'] ?? 0) !== $leaveTypeId
                || (string) ($candidate['state'] ?? '') !== EffectiveDatedPolicy::STATE_PUBLISHED
                || !$this->candidateMatchesStaff($staffUserId, $assignment, $candidate)) {
                continue;
            }
            try {
                $candidates[] = $this->effectivePolicyCandidate($candidate, $leaveTypeId);
            } catch (\Throwable $exception) {
                throw new DomainException('LEAVE_POLICY_PAYLOAD_INVALID', 0, $exception);
            }
        }
        if ($candidates === []) {
            throw new DomainException('LEAVE_POLICY_NOT_FOUND');
        }

        try {
            $resolved = $this->effectivePolicies->resolveWithExplanation($candidates, $effectiveAt);
        } catch (DomainException $exception) {
            throw new DomainException('LEAVE_POLICY_CONFLICT', 0, $exception);
        }
        if ($resolved === null) {
            throw new DomainException('LEAVE_POLICY_NOT_FOUND');
        }

        $policy = $resolved['policy']->configuration();
        $policy['scope_explanation'] = $resolved['reason'];
        $policy['timezone_object'] = new DateTimeZone((string) $policy['timezone']);

        return ['policy' => $policy, 'assignment' => $assignment];
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function effectivePolicyCandidate(array $candidate, int $leaveTypeId): EffectiveDatedPolicy
    {
        $timezone = $this->timezone((string) ($candidate['timezone'] ?? ''));
        $versionId = $this->positiveId(
            $candidate['policy_version_id'] ?? $candidate['version_id'] ?? $candidate['id'] ?? null,
            'LEAVE_POLICY_VERSION_ID_INVALID'
        );
        $versionNo = $this->positiveId($candidate['version_no'] ?? null, 'LEAVE_POLICY_VERSION_NO_INVALID');
        $validFrom = $this->date($candidate['valid_from'] ?? null, $timezone, 'LEAVE_POLICY_VALID_FROM_INVALID');
        $validTo = $this->exclusiveEnd(
            $candidate['valid_to'] ?? null,
            $validFrom,
            $timezone,
            'LEAVE_POLICY_VALID_TO_INVALID'
        );
        $scopeType = strtolower(trim((string) ($candidate['scope_type'] ?? '')));
        $scopeId = (int) ($candidate['scope_id'] ?? 0);
        $scopeFrom = $this->date(
            $candidate['scope_valid_from'] ?? $candidate['valid_from'] ?? null,
            $timezone,
            'LEAVE_POLICY_SCOPE_VALID_FROM_INVALID'
        );
        $scopeTo = $this->exclusiveEnd(
            $candidate['scope_valid_to'] ?? $candidate['valid_to'] ?? null,
            $scopeFrom,
            $timezone,
            'LEAVE_POLICY_SCOPE_VALID_TO_INVALID'
        );
        $scope = new PolicyScope(
            $scopeType,
            $scopeType === PolicyScope::TYPE_GLOBAL ? null : $scopeId,
            $this->nonNegativeInt($candidate['scope_priority'] ?? $candidate['priority'] ?? 0, 'LEAVE_POLICY_SCOPE_PRIORITY_INVALID'),
            $scopeFrom,
            $scopeTo
        );

        return new EffectiveDatedPolicy(
            $leaveTypeId,
            $versionId,
            $versionNo,
            EffectiveDatedPolicy::STATE_PUBLISHED,
            $validFrom,
            $validTo,
            $scope,
            $this->normalizePolicy($candidate, $leaveTypeId, $versionId, $versionNo, $timezone)
        );
    }

    /**
     * @param array<string,mixed> $type
     * @param array<string,mixed> $policy
     * @param array<string,mixed> $calendarDay
     * @return array<string,mixed>|null
     */
    private function calculateDay(
        int $staffUserId,
        int $leaveTypeId,
        array $type,
        array $policy,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        array $calendarDay,
        DateTimeImmutable $firstRequestedDate,
        DateTimeImmutable $lastRequestedDate,
        ?DateTimeImmutable $serviceStart
    ): ?array {
        $status = (string) ($calendarDay['status'] ?? '');
        if ($status === 'unresolved') {
            throw new DomainException('LEAVE_WORKDAY_CALENDAR_UNRESOLVED');
        }
        $workDate = $this->calendarDate($calendarDay['work_date'] ?? null, $policy['timezone_object']);
        $withinRequestedDates = $workDate >= $firstRequestedDate && $workDate <= $lastRequestedDate;
        if ($status !== 'working') {
            return $withinRequestedDates
                ? $this->nonWorkingDay($workDate, $calendarDay, $policy)
                : null;
        }

        $requiredMinutes = $this->positiveInt(
            $calendarDay['required_minutes'] ?? null,
            'LEAVE_WORKDAY_REQUIRED_MINUTES_INVALID'
        );
        $coveredMinutes = 0;
        $firstOverlap = null;
        $lastOverlap = null;
        foreach ((array) ($calendarDay['working_intervals'] ?? []) as $interval) {
            if (!is_array($interval)) {
                throw new DomainException('LEAVE_WORKDAY_INTERVAL_INVALID');
            }
            $start = $this->date($interval['start_at'] ?? null, $policy['timezone_object'], 'LEAVE_WORKDAY_INTERVAL_INVALID');
            $end = $this->date($interval['end_at'] ?? null, $policy['timezone_object'], 'LEAVE_WORKDAY_INTERVAL_INVALID');
            $minutes = $this->positiveInt($interval['minutes'] ?? null, 'LEAVE_WORKDAY_INTERVAL_MINUTES_INVALID');
            if ($end <= $start || $this->wholeMinutesBetween($start, $end, 'LEAVE_WORKDAY_INTERVAL_MINUTES_INVALID') !== $minutes) {
                throw new DomainException('LEAVE_WORKDAY_INTERVAL_MINUTES_INVALID');
            }
            $overlapStart = $start > $fromAt ? $start : $fromAt;
            $overlapEnd = $end < $toAt ? $end : $toAt;
            if ($overlapEnd <= $overlapStart) {
                continue;
            }
            $coveredMinutes += $this->wholeMinutesBetween(
                $overlapStart,
                $overlapEnd,
                'LEAVE_WINDOW_NOT_MINUTE_ALIGNED'
            );
            $firstOverlap = $firstOverlap === null || $overlapStart < $firstOverlap ? $overlapStart : $firstOverlap;
            $lastOverlap = $lastOverlap === null || $overlapEnd > $lastOverlap ? $overlapEnd : $lastOverlap;
        }
        if ($coveredMinutes === 0) {
            return $withinRequestedDates
                ? $this->nonWorkingDay($workDate, $calendarDay, $policy)
                : null;
        }
        if ($coveredMinutes > $requiredMinutes || $firstOverlap === null || $lastOverlap === null) {
            throw new DomainException('LEAVE_WORKDAY_COVERAGE_INVALID');
        }

        $fullWorkingDay = $coveredMinutes === $requiredMinutes;
        $allowPartial = $type['unit'] === 'hour'
            || ((bool) $type['allow_partial_unit'] && (bool) $policy['allow_partial_unit']);
        if (!$fullWorkingDay && !$allowPartial) {
            throw new DomainException('LEAVE_PARTIAL_UNIT_NOT_ALLOWED');
        }
        if (!$fullWorkingDay && $coveredMinutes % (int) $policy['minimum_increment_minutes'] !== 0) {
            throw new DomainException('LEAVE_INCREMENT_NOT_ALLOWED');
        }

        $unitsMilli = $type['unit'] === 'day'
            ? ($fullWorkingDay ? self::UNIT_SCALE : $this->fractionMilli($coveredMinutes, $requiredMinutes))
            : $this->fractionMilli($coveredMinutes, 60);
        if ($unitsMilli <= 0) {
            throw new DomainException('LEAVE_UNIT_CALCULATION_INVALID');
        }

        return [
            'work_date' => $workDate->format('Y-m-d'),
            'day_kind' => $fullWorkingDay ? 'workday' : 'partial',
            'from_at' => $this->formatInstant($firstOverlap, $policy['timezone_object']),
            'to_at' => $this->formatInstant($lastOverlap, $policy['timezone_object']),
            'requested_units' => $this->formatMilli($unitsMilli),
            'requested_minutes' => $coveredMinutes,
            'consumed_units' => $this->formatMilli($unitsMilli),
            'consumed_minutes' => $coveredMinutes,
            'entitlement_period_key' => $this->entitlementPeriodKey($workDate, $policy, $serviceStart),
            'calendar_exception_id' => $this->nullablePositiveInt($calendarDay['calendar_exception_id'] ?? null),
            '_units_milli' => $unitsMilli,
            '_policy_probe_from' => $this->formatInstant($firstOverlap, $policy['timezone_object']),
            '_policy_probe_to' => $this->formatInstant($lastOverlap, $policy['timezone_object']),
        ];
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $calendarDay @return array<string,mixed> */
    private function nonWorkingDay(DateTimeImmutable $workDate, array $calendarDay, array $policy): array
    {
        return [
            'work_date' => $workDate->format('Y-m-d'),
            'day_kind' => 'non_working',
            'from_at' => null,
            'to_at' => null,
            'requested_units' => '0.000',
            'requested_minutes' => 0,
            'consumed_units' => '0.000',
            'consumed_minutes' => 0,
            'entitlement_period_key' => null,
            'calendar_exception_id' => $this->nullablePositiveInt($calendarDay['calendar_exception_id'] ?? null),
            '_units_milli' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $type
     * @param array<string,mixed> $policy
     * @param list<array<string,mixed>> $requestDays
     */
    private function assertPolicyStableForRequest(
        int $staffUserId,
        int $leaveTypeId,
        array $type,
        array $policy,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        array $requestDays
    ): void {
        $probes = [$fromAt, $toAt->modify('-1 microsecond')];
        foreach ($requestDays as $requestDay) {
            if (!isset($requestDay['_policy_probe_from'], $requestDay['_policy_probe_to'])) {
                continue;
            }
            $probes[] = $this->date(
                $requestDay['_policy_probe_from'],
                $policy['timezone_object'],
                'LEAVE_POLICY_PROBE_INVALID'
            );
            $probes[] = $this->date(
                $requestDay['_policy_probe_to'],
                $policy['timezone_object'],
                'LEAVE_POLICY_PROBE_INVALID'
            )->modify('-1 microsecond');
        }
        foreach ($probes as $probe) {
            $resolved = $this->resolvePolicy($staffUserId, $leaveTypeId, $probe, $type);
            if ((int) $resolved['policy']['version_id'] !== (int) $policy['version_id']) {
                throw new DomainException('LEAVE_POLICY_CHANGES_WITHIN_REQUEST');
            }
        }
    }

    /** @param array<string,mixed> $policy */
    private function assertTiming(
        DateTimeImmutable $fromAt,
        DateTimeImmutable $submittedAt,
        array $policy
    ): void {
        if ($fromAt < $submittedAt) {
            $retroactiveDays = (int) $fromAt->setTime(0, 0, 0, 0)
                ->diff($submittedAt->setTime(0, 0, 0, 0))
                ->format('%a');
            if (!(bool) $policy['allow_retroactive']) {
                throw new DomainException('LEAVE_RETROACTIVE_NOT_ALLOWED');
            }
            if ($retroactiveDays > (int) $policy['retroactive_limit_days']) {
                throw new DomainException('LEAVE_RETROACTIVE_LIMIT_EXCEEDED');
            }

            return;
        }
        if ($this->elapsedWholeMinutes($submittedAt, $fromAt, 'LEAVE_NOTICE_WINDOW_INVALID')
            < (int) $policy['min_notice_minutes']) {
            throw new DomainException('LEAVE_MIN_NOTICE_NOT_MET');
        }
    }

    /** @param array<string,mixed> $policy */
    private function serviceStartFor(
        int $staffUserId,
        DateTimeImmutable $fromAt,
        bool $required
    ): ?DateTimeImmutable {
        $contract = $this->employment->activeContractOf($staffUserId, $fromAt->format('Y-m-d'));
        if (!is_array($contract) || !($contract['is_active'] ?? false) || empty($contract['hire_date'])) {
            if ($required) {
                throw new DomainException('LEAVE_SERVICE_START_UNAVAILABLE');
            }

            return null;
        }
        $serviceStart = $this->date(
            $contract['hire_date'],
            $fromAt->getTimezone(),
            'LEAVE_SERVICE_START_INVALID'
        )->setTime(0, 0, 0, 0);
        if ($serviceStart > $fromAt) {
            throw new DomainException('LEAVE_SERVICE_START_INVALID');
        }

        return $serviceStart;
    }

    private function assertMinimumService(
        ?DateTimeImmutable $serviceStart,
        DateTimeImmutable $fromAt,
        int $minimumMonths
    ): void {
        if ($minimumMonths === 0) {
            return;
        }
        if ($serviceStart === null) {
            throw new DomainException('LEAVE_SERVICE_START_UNAVAILABLE');
        }
        $months = ((int) $fromAt->format('Y') - (int) $serviceStart->format('Y')) * 12
            + (int) $fromAt->format('n') - (int) $serviceStart->format('n');
        if ((int) $fromAt->format('d') < (int) $serviceStart->format('d')) {
            --$months;
        }
        if ($months < $minimumMonths) {
            throw new DomainException('LEAVE_MIN_SERVICE_NOT_MET');
        }
    }

    private function assertWindowSize(DateTimeImmutable $fromAt, DateTimeImmutable $toAt): void
    {
        $calendarDays = (int) $fromAt->setTime(0, 0, 0, 0)
            ->diff($toAt->modify('-1 microsecond')->setTime(0, 0, 0, 0))
            ->format('%a') + 1;
        if ($calendarDays > self::MAX_REQUEST_CALENDAR_DAYS) {
            throw new DomainException('LEAVE_WINDOW_TOO_LONG');
        }
    }

    /**
     * @param array<string,mixed>|null $type
     * @return array<string,mixed>
     */
    private function normalizeType(?array $type, int $leaveTypeId): array
    {
        if ($type === null || (int) ($type['id'] ?? $leaveTypeId) !== $leaveTypeId) {
            throw new DomainException('LEAVE_TYPE_NOT_FOUND');
        }
        if ((string) ($type['status'] ?? '') !== 'active') {
            throw new DomainException('LEAVE_TYPE_INACTIVE');
        }
        $unit = strtolower(trim((string) ($type['unit'] ?? '')));
        if (!in_array($unit, ['day', 'hour'], true)) {
            throw new DomainException('LEAVE_TYPE_UNIT_INVALID');
        }

        return [
            'id' => $leaveTypeId,
            'code' => trim((string) ($type['code'] ?? '')),
            'name' => trim((string) ($type['name'] ?? '')),
            'unit' => $unit,
            'allow_partial_unit' => $this->boolean($type['allow_partial_unit'] ?? false, 'LEAVE_TYPE_PARTIAL_FLAG_INVALID'),
            'requires_reason' => $this->boolean($type['requires_reason'] ?? true, 'LEAVE_TYPE_REASON_FLAG_INVALID'),
            'requires_attachment' => $this->boolean($type['requires_attachment'] ?? false, 'LEAVE_TYPE_ATTACHMENT_FLAG_INVALID'),
            'requires_medical_document' => $this->boolean($type['requires_medical_document'] ?? false, 'LEAVE_TYPE_MEDICAL_FLAG_INVALID'),
            'payroll_effect_code' => $this->nullableText($type['payroll_effect_code'] ?? null, 80, 'LEAVE_TYPE_PAYROLL_EFFECT_INVALID'),
            'status' => 'active',
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function normalizePolicy(
        array $candidate,
        int $leaveTypeId,
        int $versionId,
        int $versionNo,
        DateTimeZone $timezone
    ): array {
        $periodType = strtolower(trim((string) ($candidate['entitlement_period_type'] ?? '')));
        if (!in_array($periodType, ['calendar_year', 'academic_year', 'service_anniversary', 'custom'], true)) {
            throw new InvalidArgumentException('LEAVE_POLICY_PERIOD_TYPE_INVALID');
        }
        $anchor = $this->nullableMonthDay($candidate['entitlement_period_anchor_mmdd'] ?? null);
        if (in_array($periodType, ['academic_year', 'custom'], true) && $anchor === null) {
            throw new InvalidArgumentException('LEAVE_POLICY_PERIOD_ANCHOR_REQUIRED');
        }
        if (in_array($periodType, ['calendar_year', 'service_anniversary'], true) && $anchor !== null) {
            throw new InvalidArgumentException('LEAVE_POLICY_PERIOD_ANCHOR_NOT_ALLOWED');
        }
        $allowRetroactive = $this->boolean(
            $candidate['allow_retroactive'] ?? null,
            'LEAVE_POLICY_RETROACTIVE_FLAG_INVALID'
        );
        $retroactiveLimit = $this->nonNegativeInt(
            $candidate['retroactive_limit_days'] ?? null,
            'LEAVE_POLICY_RETROACTIVE_LIMIT_INVALID'
        );
        if (($allowRetroactive && $retroactiveLimit === 0) || (!$allowRetroactive && $retroactiveLimit !== 0)) {
            throw new InvalidArgumentException('LEAVE_POLICY_RETROACTIVE_CONFIGURATION_INVALID');
        }
        $allowNegativeBalance = $this->boolean(
            $candidate['allow_negative_balance'] ?? null,
            'LEAVE_POLICY_NEGATIVE_FLAG_INVALID'
        );
        $negativeBalanceLimit = $this->decimalMilli(
            $candidate['negative_balance_limit_units'] ?? null,
            'LEAVE_POLICY_NEGATIVE_LIMIT_INVALID'
        );
        if (($allowNegativeBalance && $negativeBalanceLimit === 0)
            || (!$allowNegativeBalance && $negativeBalanceLimit !== 0)) {
            throw new InvalidArgumentException('LEAVE_POLICY_NEGATIVE_CONFIGURATION_INVALID');
        }
        $minimumAvailableStaff = $this->nullableNonNegativeIntField(
            $candidate['minimum_available_staff'] ?? null,
            'LEAVE_POLICY_STAFFING_MINIMUM_INVALID'
        );
        $maximumAbsencePercentage = $this->nullablePercentage(
            $candidate['max_absence_percentage'] ?? null,
            'LEAVE_POLICY_STAFFING_PERCENTAGE_INVALID'
        );
        $requiresStaffingOverride = $this->boolean(
            $candidate['requires_staffing_override'] ?? 0,
            'LEAVE_POLICY_STAFFING_OVERRIDE_INVALID'
        );
        $staffingOverrideRole = $this->nullableText(
            $candidate['override_role_key'] ?? null,
            80,
            'LEAVE_POLICY_STAFFING_OVERRIDE_INVALID'
        );
        if (($requiresStaffingOverride && $staffingOverrideRole === null)
            || (!$requiresStaffingOverride && $staffingOverrideRole !== null)) {
            throw new InvalidArgumentException('LEAVE_POLICY_STAFFING_OVERRIDE_INVALID');
        }

        return [
            'version_id' => $versionId,
            'leave_type_id' => $leaveTypeId,
            'version_no' => $versionNo,
            'timezone' => $timezone->getName(),
            'entitlement_period_type' => $periodType,
            'entitlement_period_anchor_mmdd' => $anchor,
            'entitlement_units' => $this->decimalMilli($candidate['entitlement_units'] ?? null, 'LEAVE_POLICY_ENTITLEMENT_INVALID'),
            'accrual_mode' => $this->enum(
                $candidate['accrual_mode'] ?? null,
                ['grant', 'monthly', 'manual'],
                'LEAVE_POLICY_ACCRUAL_MODE_INVALID'
            ),
            'accrual_units' => $this->decimalMilli($candidate['accrual_units'] ?? null, 'LEAVE_POLICY_ACCRUAL_INVALID'),
            'carry_limit_units_milli' => $this->nullableDecimalMilli($candidate['carry_limit_units'] ?? null, 'LEAVE_POLICY_CARRY_LIMIT_INVALID'),
            'carry_expiry_months' => $this->nullablePositiveIntField(
                $candidate['carry_expiry_months'] ?? null,
                'LEAVE_POLICY_CARRY_EXPIRY_INVALID'
            ),
            'max_consecutive_units_milli' => $this->nullablePositiveDecimalMilli(
                $candidate['max_consecutive_units'] ?? null,
                'LEAVE_POLICY_MAX_CONSECUTIVE_INVALID'
            ),
            'min_notice_minutes' => $this->nonNegativeInt($candidate['min_notice_minutes'] ?? null, 'LEAVE_POLICY_NOTICE_INVALID'),
            'min_service_months' => $this->nonNegativeInt($candidate['min_service_months'] ?? null, 'LEAVE_POLICY_SERVICE_MONTHS_INVALID'),
            'allow_retroactive' => $allowRetroactive,
            'retroactive_limit_days' => $retroactiveLimit,
            'minimum_increment_minutes' => $this->positiveInt($candidate['minimum_increment_minutes'] ?? null, 'LEAVE_POLICY_INCREMENT_INVALID'),
            'allow_partial_unit' => $this->boolean($candidate['allow_partial_unit'] ?? null, 'LEAVE_POLICY_PARTIAL_FLAG_INVALID'),
            'allow_overlap' => $this->boolean($candidate['allow_overlap'] ?? null, 'LEAVE_POLICY_OVERLAP_FLAG_INVALID'),
            'allow_negative_balance' => $allowNegativeBalance,
            'negative_balance_limit_units_milli' => $negativeBalanceLimit,
            'requires_return_to_work' => $this->boolean($candidate['requires_return_to_work'] ?? null, 'LEAVE_POLICY_RETURN_FLAG_INVALID'),
            'requires_attachment' => $this->boolean($candidate['requires_attachment'] ?? null, 'LEAVE_POLICY_ATTACHMENT_FLAG_INVALID'),
            'requires_medical_document' => $this->boolean($candidate['requires_medical_document'] ?? null, 'LEAVE_POLICY_MEDICAL_FLAG_INVALID'),
            'payroll_effect_code' => $this->nullableText(
                $candidate['payroll_effect_code'] ?? null,
                80,
                'LEAVE_POLICY_PAYROLL_EFFECT_INVALID'
            ),
            'minimum_available_staff' => $minimumAvailableStaff,
            'max_absence_percentage' => $maximumAbsencePercentage,
            'requires_staffing_override' => $requiresStaffingOverride,
            'override_role_key' => $staffingOverrideRole,
        ];
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $type @param array<string,mixed> $assignment */
    private function policySnapshot(
        array $policy,
        array $type,
        array $assignment,
        ?DateTimeImmutable $serviceStart
    ): array {
        $snapshot = $this->policyPublicView($policy);
        $snapshot['leave_type'] = [
            'id' => $type['id'],
            'code' => $type['code'],
            'unit' => $type['unit'],
            'allow_partial_unit' => $type['allow_partial_unit'],
        ];
        $snapshot['assignment_id'] = $assignment['assignment_id'];
        $snapshot['scope'] = $policy['scope_explanation'];
        $snapshot['service_start_at'] = $serviceStart === null ? null : $serviceStart->format('Y-m-d');

        return $snapshot;
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function policyPublicView(array $policy): array
    {
        $view = $policy;
        unset($view['timezone_object'], $view['scope_explanation']);
        foreach ([
            'entitlement_units',
            'accrual_units',
            'carry_limit_units_milli',
            'max_consecutive_units_milli',
            'negative_balance_limit_units_milli',
        ] as $field) {
            if (array_key_exists($field, $view) && $view[$field] !== null) {
                $view[$field] = $this->formatMilli((int) $view[$field]);
            }
        }

        return $view;
    }

    /** @param array<string,mixed> $policy */
    private function entitlementPeriodKey(
        DateTimeImmutable $workDate,
        array $policy,
        ?DateTimeImmutable $serviceStart
    ): string
    {
        return LeaveEntitlementPeriod::forWorkDate($workDate, $policy, $serviceStart)['key'];
    }

    /** @param array<string,mixed> $assignment @param array<string,mixed> $candidate */
    private function candidateMatchesStaff(int $staffUserId, array $assignment, array $candidate): bool
    {
        if (isset($candidate['scope_status']) && (string) $candidate['scope_status'] !== 'active') {
            return false;
        }
        $scopeType = (string) ($candidate['scope_type'] ?? '');
        $scopeId = (int) ($candidate['scope_id'] ?? 0);

        return match ($scopeType) {
            PolicyScope::TYPE_GLOBAL => $scopeId === 0,
            PolicyScope::TYPE_STAFF => $scopeId === $staffUserId,
            PolicyScope::TYPE_ORG_UNIT => $scopeId === (int) ($assignment['org_unit_id'] ?? 0),
            PolicyScope::TYPE_JOB_TITLE => $scopeId === (int) ($assignment['job_title_id'] ?? 0),
            PolicyScope::TYPE_GROUP => in_array(
                $scopeId,
                array_map('intval', (array) ($assignment['group_ids'] ?? [])),
                true
            ),
            default => false,
        };
    }

    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    private function normalizeAssignment(array $assignment): array
    {
        $assignmentId = $this->positiveId($assignment['assignment_id'] ?? null, 'LEAVE_STAFF_ASSIGNMENT_INVALID');
        $groups = array_values(array_unique(array_filter(
            array_map('intval', (array) ($assignment['group_ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        sort($groups, SORT_NUMERIC);

        return [
            'assignment_id' => $assignmentId,
            'org_unit_id' => $this->nullablePositiveInt($assignment['org_unit_id'] ?? null),
            'job_title_id' => $this->nullablePositiveInt($assignment['job_title_id'] ?? null),
            'group_ids' => $groups,
            'employment_status' => 'active',
        ];
    }

    private function wholeMinutesBetween(DateTimeImmutable $from, DateTimeImmutable $to, string $error): int
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        if ($seconds <= 0 || $seconds % 60 !== 0) {
            throw new DomainException($error);
        }

        return intdiv($seconds, 60);
    }

    private function elapsedWholeMinutes(DateTimeImmutable $from, DateTimeImmutable $to, string $error): int
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        if ($seconds < 0) {
            throw new DomainException($error);
        }

        return intdiv($seconds, 60);
    }

    private function fractionMilli(int $numerator, int $denominator): int
    {
        return LeaveUnits::fractionMilli($numerator, $denominator, 'LEAVE_UNIT_CALCULATION_INVALID');
    }

    private function formatMilli(int $value): string
    {
        return LeaveUnits::format($value);
    }

    private function decimalMilli(mixed $value, string $error): int
    {
        return LeaveUnits::fromDecimal($value, false, $error);
    }

    private function nullableDecimalMilli(mixed $value, string $error): ?int
    {
        return LeaveUnits::nullableFromDecimal($value, false, $error);
    }

    private function nullablePositiveDecimalMilli(mixed $value, string $error): ?int
    {
        $milli = $this->nullableDecimalMilli($value, $error);
        if ($milli !== null && $milli <= 0) {
            throw new InvalidArgumentException($error);
        }

        return $milli;
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException($error);
        }

        return $id;
    }

    private function positiveInt(mixed $value, string $error): int
    {
        return $this->positiveId($value, $error);
    }

    private function nonNegativeInt(mixed $value, string $error): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < 0) {
            throw new InvalidArgumentException($error);
        }

        return $number;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);

        return $number !== false && $number > 0 ? $number : null;
    }

    private function nullablePositiveIntField(mixed $value, string $error): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->positiveInt($value, $error);
    }

    private function nullableNonNegativeIntField(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->nonNegativeInt($value, $error);
    }

    private function nullablePercentage(mixed $value, string $error): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,2}))?$/', $raw, $matches) !== 1) {
            throw new InvalidArgumentException($error);
        }
        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        if ($whole === 0 && (int) $fraction === 0) {
            throw new InvalidArgumentException($error);
        }
        if ($whole > 100 || ($whole === 100 && (int) $fraction !== 0)) {
            throw new InvalidArgumentException($error);
        }

        return $whole . '.' . $fraction;
    }

    private function boolean(mixed $value, string $error): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, '0'], true)) {
            return false;
        }
        if (in_array($value, [1, '1'], true)) {
            return true;
        }
        throw new InvalidArgumentException($error);
    }

    private function enum(mixed $value, array $allowed, string $error): string
    {
        $normalized = trim((string) $value);
        if (!in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }

        return $normalized;
    }

    private function nullableText(mixed $value, int $maxLength, string $error): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = trim((string) $value);
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function timezone(string $value): DateTimeZone
    {
        try {
            return new DateTimeZone(trim($value));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('LEAVE_POLICY_TIMEZONE_INVALID', 0, $exception);
        }
    }

    private function date(mixed $value, DateTimeZone $timezone, string $error): DateTimeImmutable
    {
        try {
            if ($value instanceof DateTimeImmutable) {
                return $value;
            }
            if ($value instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($value);
            }
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($error);
            }

            return new DateTimeImmutable($value, $timezone);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($error, 0, $exception);
        }
    }

    private function exclusiveEnd(
        mixed $value,
        DateTimeImmutable $from,
        DateTimeZone $timezone,
        string $error
    ): ?DateTimeImmutable {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $exclusive = $this->date($value, $timezone, $error);
        if ($exclusive <= $from) {
            throw new InvalidArgumentException($error);
        }

        return $exclusive->modify('-1 microsecond');
    }

    private function calendarDate(mixed $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new DomainException('LEAVE_WORKDAY_DATE_INVALID');
        }
        $date = $this->date($value . ' 00:00:00', $timezone, 'LEAVE_WORKDAY_DATE_INVALID')->setTime(0, 0, 0, 0);
        if ($date->format('Y-m-d') !== $value) {
            throw new DomainException('LEAVE_WORKDAY_DATE_INVALID');
        }

        return $date;
    }

    private function nullableMonthDay(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $monthDay = trim((string) $value);
        if (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $monthDay) !== 1) {
            throw new InvalidArgumentException('LEAVE_POLICY_PERIOD_ANCHOR_INVALID');
        }
        [$month, $day] = array_map('intval', explode('-', $monthDay));
        if (!checkdate($month, $day, 2024)) {
            throw new InvalidArgumentException('LEAVE_POLICY_PERIOD_ANCHOR_INVALID');
        }

        return $monthDay;
    }

    private function formatInstant(DateTimeImmutable $instant, ?DateTimeZone $timezone = null): string
    {
        if ($timezone !== null) {
            $instant = $instant->setTimezone($timezone);
        }

        return $instant->format('Y-m-d H:i:s.u');
    }
}
