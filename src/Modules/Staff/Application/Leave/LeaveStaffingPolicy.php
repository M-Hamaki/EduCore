<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Staff\Contracts\LeaveStaffingReadRepository;
use InvalidArgumentException;

/**
 * Evaluates operational capacity and blackout rules before a leave is sent.
 *
 * It never grants an override itself. A breached rule produces a deterministic
 * requirement fingerprint; only separately stored immutable manager evidence
 * for that exact fingerprint can pass the normal worker submission check.
 */
final class LeaveStaffingPolicy
{
    /** @var list<string> */
    private const SCOPE_TYPES = ['global', 'org_unit', 'job_title', 'group', 'staff'];

    public function __construct(private LeaveStaffingReadRepository $repository)
    {
    }

    /**
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $quote
     * @return array<string,mixed>
     */
    public function assess(
        int $requestId,
        array $draft,
        array $quote,
        DateTimeImmutable $checkedAt,
        ?array $approvedOverride = null
    ): array {
        $evaluation = $this->evaluate($requestId, $draft, $quote, $checkedAt);
        if (($evaluation['status'] ?? null) !== 'override_required') {
            return $evaluation;
        }

        if ($approvedOverride === null) {
            throw new DomainException((string) ($evaluation['failure_code'] ?? 'LEAVE_STAFFING_OVERRIDE_REQUIRED'));
        }

        $requirement = $evaluation['override_requirement'] ?? null;
        if (!is_array($requirement)) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_REQUIREMENT_INVALID');
        }
        $evidence = $this->assertApprovedOverride($approvedOverride, $requirement);

        return [
            'status' => 'overridden',
            'checked_at' => $this->instant($checkedAt),
            'scope' => $evaluation['scope'],
            'rules' => $evaluation['rules'],
            'blackouts' => $evaluation['blackouts'],
            'override' => [
                'decision_id' => $evidence['decision_id'],
                'decision_hash' => $evidence['decision_hash'],
                'requirement_fingerprint' => $requirement['fingerprint'],
                'required_role_keys' => $requirement['required_role_keys'],
                'decided_at' => $evidence['decided_at'],
            ],
        ];
    }

    /**
     * Returns the precise live requirement that an authorized manager may
     * decide. Hard rules deliberately remain errors rather than becoming a
     * path to manufacture an override.
     *
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $quote
     * @return array<string,mixed>
     */
    public function requiredOverride(
        int $requestId,
        array $draft,
        array $quote,
        DateTimeImmutable $checkedAt
    ): array {
        $evaluation = $this->evaluate($requestId, $draft, $quote, $checkedAt);
        $requirement = $evaluation['override_requirement'] ?? null;
        if (($evaluation['status'] ?? null) !== 'override_required' || !is_array($requirement)) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_NOT_REQUIRED');
        }
        if (($requirement['required_role_keys'] ?? []) === []) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_ROLE_REQUIRED');
        }

        return $requirement;
    }

    /**
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $quote
     * @return array<string,mixed>
     */
    private function evaluate(
        int $requestId,
        array $draft,
        array $quote,
        DateTimeImmutable $checkedAt
    ): array {
        if ($requestId <= 0) {
            throw new InvalidArgumentException('LEAVE_REQUEST_ID_INVALID');
        }
        $kind = strtolower(trim((string) ($draft['request_kind'] ?? '')));
        if (!in_array($kind, ['leave', 'extension', 'early_return', 'cancellation'], true)) {
            throw new InvalidArgumentException('LEAVE_REQUEST_KIND_INVALID');
        }
        if (in_array($kind, ['early_return', 'cancellation'], true)) {
            return [
                'status' => 'not_applicable',
                'reason' => 'successor_reduces_or_reverses_absence',
                'checked_at' => $this->instant($checkedAt),
                'rules' => [],
                'blackouts' => [],
            ];
        }

        $policy = $quote['policy_snapshot'] ?? null;
        $assignment = $quote['assignment'] ?? null;
        if (!is_array($policy) || !is_array($assignment)) {
            throw new DomainException('LEAVE_STAFFING_POLICY_PAYLOAD_INVALID');
        }
        $staffUserId = $this->positiveId(
            $draft['staff_user_id'] ?? null,
            'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID'
        );
        // The dated assignment quote intentionally contains only the
        // organization dimensions. The private adapter also needs the
        // request owner to evaluate staff-scoped blackout rules.
        $assignment['staff_user_id'] = $staffUserId;
        $policyVersionId = $this->positiveId($policy['version_id'] ?? null, 'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID');
        $scope = $this->scope($policy['scope'] ?? null);
        $timezone = $this->timezone($policy['timezone'] ?? null);
        $from = $this->dateTime($draft['from_at'] ?? null, $timezone, 'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID');
        $to = $this->dateTime($draft['to_at'] ?? null, $timezone, 'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID');
        if ($to <= $from) {
            throw new DomainException('LEAVE_STAFFING_POLICY_PAYLOAD_INVALID');
        }

        $requestHash = $this->hash($draft['request_hash'] ?? null, 'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID');
        $blackouts = $this->normalizeBlackouts(
            $this->repository->blackoutsFor($policyVersionId, $assignment, $from, $to)
        );
        $requirements = [];
        foreach ($blackouts as $blackout) {
            if (!$blackout['requires_override']) {
                throw new DomainException('LEAVE_REQUEST_BLACKOUT');
            }
            $requirements[] = [
                'kind' => 'blackout',
                'blackout_id' => $blackout['id'],
                'role_key' => $blackout['override_role_key'],
            ];
        }

        $minimumAvailable = $this->nullableNonNegativeInt(
            $policy['minimum_available_staff'] ?? null,
            'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID'
        );
        $maximumAbsenceBasisPoints = $this->nullablePercentageBasisPoints(
            $policy['max_absence_percentage'] ?? null,
            'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID'
        );
        $rules = [];
        $violations = [];
        if ($minimumAvailable !== null || $maximumAbsenceBasisPoints !== null) {
            foreach ($this->consumingWorkDates($draft, $timezone) as $workDate) {
                $availability = $this->normalizeAvailability(
                    $this->repository->availabilityForScopeForUpdate(
                        $scope['scope_type'],
                        $scope['scope_id'],
                        $workDate,
                        $requestId
                    )
                );
                $staffIds = $availability['staff_ids'];
                if ($staffIds === []) {
                    throw new DomainException('LEAVE_STAFFING_POPULATION_EMPTY');
                }
                if ($availability['conflicting_staff_ids'] !== []) {
                    throw new DomainException('LEAVE_STAFFING_POPULATION_AMBIGUOUS');
                }
                if (!in_array($staffUserId, $staffIds, true)) {
                    throw new DomainException('LEAVE_STAFFING_SCOPE_MISMATCH');
                }
                $absentBefore = $availability['absent_staff_ids'];
                $absentAfter = array_values(array_unique([...$absentBefore, $staffUserId]));
                sort($absentAfter, SORT_NUMERIC);
                $total = count($staffIds);
                $availableAfter = $total - count($absentAfter);
                $minimumBreached = $minimumAvailable !== null && $availableAfter < $minimumAvailable;
                // Round up so that a fractional percentage can never slip
                // under a published maximum because of integer truncation.
                $absenceBasisPoints = intdiv((count($absentAfter) * 10000) + $total - 1, $total);
                $maximumBreached = $maximumAbsenceBasisPoints !== null
                    && $absenceBasisPoints > $maximumAbsenceBasisPoints;
                $rule = [
                    'work_date' => $workDate->format('Y-m-d'),
                    'total_staff' => $total,
                    'absent_before' => count($absentBefore),
                    'absent_after' => count($absentAfter),
                    'available_after' => $availableAfter,
                    'minimum_available_staff' => $minimumAvailable,
                    'absence_basis_points' => $absenceBasisPoints,
                    'maximum_absence_basis_points' => $maximumAbsenceBasisPoints,
                    'minimum_breached' => $minimumBreached,
                    'maximum_breached' => $maximumBreached,
                ];
                $rules[] = $rule;
                if ($minimumBreached || $maximumBreached) {
                    $violations[] = $rule;
                }
            }
        }

        if ($violations !== []) {
            if ($this->boolean($policy['requires_staffing_override'] ?? false, 'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID')) {
                $requirements[] = [
                    'kind' => 'capacity',
                    'role_key' => $this->nullableRoleKey(
                        $policy['override_role_key'] ?? null,
                        'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID'
                    ),
                    'violations' => $violations,
                ];
            } elseif ((bool) ($violations[0]['minimum_breached'] ?? false)) {
                throw new DomainException('LEAVE_STAFFING_MINIMUM_BREACHED');
            } else {
                throw new DomainException('LEAVE_STAFFING_ABSENCE_LIMIT_BREACHED');
            }
        }

        if ($requirements !== []) {
            $roles = [];
            foreach ($requirements as $requirement) {
                $roleKey = $requirement['role_key'] ?? null;
                if (is_string($roleKey) && $roleKey !== '') {
                    $roles[$roleKey] = true;
                }
            }
            $roleKeys = array_keys($roles);
            sort($roleKeys, SORT_STRING);
            $overrideRequirement = [
                'request_id' => $requestId,
                'request_hash' => $requestHash,
                'policy_version_id' => $policyVersionId,
                'scope' => $scope,
                'requirements' => $requirements,
                'required_role_keys' => $roleKeys,
            ];
            $overrideRequirement['fingerprint'] = hash(
                'sha256',
                json_encode($overrideRequirement, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            $hasCapacityRequirement = array_filter(
                $requirements,
                static fn (array $requirement): bool => ($requirement['kind'] ?? null) === 'capacity'
            ) !== [];

            return [
                'status' => 'override_required',
                'failure_code' => $hasCapacityRequirement
                    ? 'LEAVE_STAFFING_OVERRIDE_REQUIRED'
                    : 'LEAVE_BLACKOUT_OVERRIDE_REQUIRED',
                'checked_at' => $this->instant($checkedAt),
                'scope' => $scope,
                'rules' => $rules,
                'blackouts' => $blackouts,
                'override_requirement' => $overrideRequirement,
            ];
        }

        return [
            'status' => 'clear',
            'checked_at' => $this->instant($checkedAt),
            'scope' => $scope,
            'rules' => $rules,
            'blackouts' => $blackouts,
        ];
    }

    /** @param array<string,mixed> $draft @return list<DateTimeImmutable> */
    private function consumingWorkDates(array $draft, DateTimeZone $timezone): array
    {
        $dates = [];
        foreach ((array) ($draft['request_days'] ?? []) as $day) {
            if (!is_array($day) || (string) ($day['requested_units'] ?? '0.000') === '0.000') {
                continue;
            }
            $date = $this->dateTime(
                $this->dateKey($day['work_date'] ?? null, 'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID') . ' 00:00:00',
                $timezone,
                'LEAVE_STAFFING_POLICY_PAYLOAD_INVALID'
            )->setTime(0, 0, 0, 0);
            $dates[$date->format('Y-m-d')] = $date;
        }
        if ($dates === []) {
            throw new DomainException('LEAVE_STAFFING_POLICY_PAYLOAD_INVALID');
        }
        ksort($dates, SORT_STRING);

        return array_values($dates);
    }

    /** @return array{scope_type:string,scope_id:int,priority:int} */
    private function scope(mixed $value): array
    {
        if (!is_array($value)) {
            throw new DomainException('LEAVE_STAFFING_POLICY_PAYLOAD_INVALID');
        }
        $type = strtolower(trim((string) ($value['scope_type'] ?? '')));
        if (!in_array($type, self::SCOPE_TYPES, true)) {
            throw new DomainException('LEAVE_STAFFING_SCOPE_INVALID');
        }
        // PolicyScope represents the global scope ID as null in its
        // immutable explanation, while the staffing repository uses 0 for
        // the same scope in SQL. Normalize only that documented spelling.
        $rawScopeId = $value['scope_id'] ?? null;
        $scopeId = $type === 'global' && ($rawScopeId === null || $rawScopeId === '')
            ? 0
            : $this->nonNegativeInt($rawScopeId, 'LEAVE_STAFFING_SCOPE_INVALID');
        if (($type === 'global' && $scopeId !== 0) || ($type !== 'global' && $scopeId <= 0)) {
            throw new DomainException('LEAVE_STAFFING_SCOPE_INVALID');
        }

        return [
            'scope_type' => $type,
            'scope_id' => $scopeId,
            'priority' => $this->nonNegativeInt($value['priority'] ?? 0, 'LEAVE_STAFFING_SCOPE_INVALID'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function normalizeBlackouts(array $blackouts): array
    {
        $normalized = [];
        foreach ($blackouts as $blackout) {
            if (!is_array($blackout)) {
                throw new DomainException('LEAVE_STAFFING_BLACKOUT_PAYLOAD_INVALID');
            }
            $normalized[] = [
                'id' => $this->positiveId($blackout['id'] ?? null, 'LEAVE_STAFFING_BLACKOUT_PAYLOAD_INVALID'),
                'requires_override' => $this->boolean(
                    $blackout['requires_override'] ?? null,
                    'LEAVE_STAFFING_BLACKOUT_PAYLOAD_INVALID'
                ),
                'override_role_key' => $this->nullableRoleKey(
                    $blackout['override_role_key'] ?? null,
                    'LEAVE_STAFFING_BLACKOUT_PAYLOAD_INVALID'
                ),
            ];
        }
        usort($normalized, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $requirement
     * @return array{decision_id:int,decision_hash:string,decided_at:?string}
     */
    private function assertApprovedOverride(array $evidence, array $requirement): array
    {
        if (strtolower(trim((string) ($evidence['decision_outcome'] ?? ''))) !== 'approved') {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID');
        }
        $decisionId = $this->positiveId(
            $evidence['id'] ?? $evidence['decision_id'] ?? null,
            'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
        );
        $requestId = $this->positiveId(
            $evidence['leave_request_id'] ?? null,
            'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
        );
        if ($requestId !== (int) ($requirement['request_id'] ?? 0)) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID');
        }
        $requestHash = $this->hash(
            $evidence['request_hash'] ?? null,
            'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
        );
        $requirementHash = $this->hash(
            $evidence['requirement_fingerprint'] ?? null,
            'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
        );
        $expectedRequestHash = $this->hash(
            $requirement['request_hash'] ?? null,
            'LEAVE_STAFFING_OVERRIDE_REQUIREMENT_INVALID'
        );
        $expectedRequirementHash = $this->hash(
            $requirement['fingerprint'] ?? null,
            'LEAVE_STAFFING_OVERRIDE_REQUIREMENT_INVALID'
        );
        if (!hash_equals($expectedRequestHash, $requestHash)
            || !hash_equals($expectedRequirementHash, $requirementHash)) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_EVIDENCE_STALE');
        }

        $requiredRoles = $this->roleKeys(
            (array) ($requirement['required_role_keys'] ?? []),
            'LEAVE_STAFFING_OVERRIDE_REQUIREMENT_INVALID'
        );
        $evidenceRoles = $this->roleKeys(
            (array) ($evidence['required_role_keys'] ?? []),
            'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
        );
        if ($requiredRoles !== $evidenceRoles) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_EVIDENCE_STALE');
        }

        return [
            'decision_id' => $decisionId,
            'decision_hash' => $this->hash(
                $evidence['decision_hash'] ?? null,
                'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
            ),
            'decided_at' => isset($evidence['decided_at']) && $evidence['decided_at'] !== null
                ? (string) $evidence['decided_at']
                : null,
        ];
    }

    private function nullableRoleKey(mixed $value, string $error): ?string
    {
        if ($value === null) {
            return null;
        }
        $roleKey = trim((string) $value);
        if ($roleKey === '') {
            return null;
        }
        if (mb_strlen($roleKey, 'UTF-8') > 80) {
            throw new DomainException($error);
        }

        return $roleKey;
    }

    /** @param array<int,mixed> $values @return list<string> */
    private function roleKeys(array $values, string $error): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $roleKey = $this->nullableRoleKey($value, $error);
            if ($roleKey === null) {
                throw new DomainException($error);
            }
            $normalized[$roleKey] = true;
        }
        $keys = array_keys($normalized);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return array{staff_ids:list<int>,absent_staff_ids:list<int>,conflicting_staff_ids:list<int>} */
    private function normalizeAvailability(array $availability): array
    {
        $normalizeIds = function (mixed $value): array {
            $ids = [];
            foreach ((array) $value as $id) {
                $normalized = $this->positiveId($id, 'LEAVE_STAFFING_AVAILABILITY_PAYLOAD_INVALID');
                $ids[$normalized] = $normalized;
            }
            ksort($ids, SORT_NUMERIC);

            return array_values($ids);
        };

        $staffIds = $normalizeIds($availability['staff_ids'] ?? null);
        $absentIds = $normalizeIds($availability['absent_staff_ids'] ?? null);
        foreach ($absentIds as $staffId) {
            if (!in_array($staffId, $staffIds, true)) {
                throw new DomainException('LEAVE_STAFFING_AVAILABILITY_PAYLOAD_INVALID');
            }
        }

        return [
            'staff_ids' => $staffIds,
            'absent_staff_ids' => $absentIds,
            'conflicting_staff_ids' => $normalizeIds($availability['conflicting_staff_ids'] ?? null),
        ];
    }

    private function timezone(mixed $value): DateTimeZone
    {
        if (!is_string($value) || trim($value) === '') {
            throw new DomainException('LEAVE_STAFFING_POLICY_PAYLOAD_INVALID');
        }
        try {
            return new DateTimeZone($value);
        } catch (\Throwable $exception) {
            throw new DomainException('LEAVE_STAFFING_POLICY_PAYLOAD_INVALID', 0, $exception);
        }
    }

    private function dateTime(mixed $value, DateTimeZone $timezone, string $error): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($timezone);
        }
        if (!is_string($value) || trim($value) === '') {
            throw new DomainException($error);
        }
        try {
            return (new DateTimeImmutable($value, $timezone))->setTimezone($timezone);
        } catch (\Throwable $exception) {
            throw new DomainException($error, 0, $exception);
        }
    }

    private function dateKey(mixed $value, string $error): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new DomainException($error);
        }
        try {
            $date = new DateTimeImmutable($value . ' 00:00:00', new DateTimeZone('UTC'));
        } catch (\Throwable $exception) {
            throw new DomainException($error, 0, $exception);
        }
        if ($date->format('Y-m-d') !== $value) {
            throw new DomainException($error);
        }

        return $value;
    }

    private function nullableNonNegativeInt(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->nonNegativeInt($value, $error);
    }

    private function nullablePercentageBasisPoints(mixed $value, string $error): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,2}))?$/', $raw, $matches) !== 1) {
            throw new DomainException($error);
        }
        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');
        $basisPoints = $whole * 100 + $fraction;
        if ($basisPoints <= 0 || $basisPoints > 10000) {
            throw new DomainException($error);
        }

        return $basisPoints;
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
        throw new DomainException($error);
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new DomainException($error);
        }

        return (int) $value;
    }

    private function nonNegativeInt(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new DomainException($error);
        }

        return (int) $value;
    }

    private function hash(mixed $value, string $error): string
    {
        $hash = strtolower(trim((string) $value));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new DomainException($error);
        }

        return $hash;
    }

    private function instant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
