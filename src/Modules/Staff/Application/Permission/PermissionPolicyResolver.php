<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Permission;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\PermissionPolicyReadRepository;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use RuntimeException;

/**
 * Resolves one explainable effective permission policy for a worker.
 *
 * Policy scope follows the documented Staff precedence. A tie is not
 * arbitrarily ordered because an incorrect quota rule would be worse than an
 * explicit unavailable request surface.
 */
final class PermissionPolicyResolver
{
    /** @var array<string,int> */
    private const SCOPE_PRECEDENCE = [
        'global' => 100,
        'job_title' => 200,
        'org_unit' => 300,
        'group' => 400,
        'staff' => 500,
    ];

    private ?PermissionPolicyReadRepository $repository;
    private ?StaffAssignmentAtDateQuery $assignmentQuery;

    public function __construct(
        ?PermissionPolicyReadRepository $repository = null,
        ?StaffAssignmentAtDateQuery $assignmentQuery = null
    ) {
        $this->repository = $repository;
        $this->assignmentQuery = $assignmentQuery;
    }

    /** @return array<string,mixed> */
    public function resolve(
        int $staffId,
        int $permissionTypeId,
        DateTimeImmutable $effectiveAt
    ): array {
        if ($staffId <= 0) {
            throw new DomainException('STAFF_ID_INVALID');
        }
        if ($permissionTypeId <= 0) {
            throw new DomainException('PERMISSION_TYPE_ID_INVALID');
        }
        if ($this->repository === null || $this->assignmentQuery === null) {
            throw new RuntimeException('Permission policy resolver dependencies are not configured.');
        }

        $type = $this->repository->findType($permissionTypeId);
        if ($type === null) {
            return $this->unavailable(
                'PERMISSION_TYPE_NOT_FOUND',
                $staffId,
                $permissionTypeId,
                $effectiveAt,
                null,
                null
            );
        }
        $type = $this->normalizeType($type, $permissionTypeId);
        if ($type['status'] !== 'active') {
            return $this->unavailable(
                'PERMISSION_TYPE_INACTIVE',
                $staffId,
                $permissionTypeId,
                $effectiveAt,
                null,
                $type
            );
        }

        $assignment = $this->assignmentQuery->forStaff($staffId, $effectiveAt);
        if ($assignment === null || ($assignment['employment_status'] ?? '') !== 'active') {
            return $this->unavailable(
                'STAFF_NOT_ACTIVE',
                $staffId,
                $permissionTypeId,
                $effectiveAt,
                $assignment,
                $type
            );
        }

        return $this->resolveFromCandidates(
            $staffId,
            $permissionTypeId,
            $effectiveAt,
            $assignment,
            $type,
            $this->repository->candidateVersionsFor($permissionTypeId, $staffId, $assignment, $effectiveAt)
        );
    }

    /**
     * Pure effective-policy selection boundary for previews and focused tests.
     *
     * @param array<string,mixed> $assignment
     * @param array<string,mixed> $type
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    public function resolveFromCandidates(
        int $staffId,
        int $permissionTypeId,
        DateTimeImmutable $effectiveAt,
        array $assignment,
        array $type,
        array $candidates
    ): array {
        $eligible = [];
        $invalid = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)
                || (int) ($candidate['permission_type_id'] ?? 0) !== $permissionTypeId
                || (string) ($candidate['state'] ?? '') !== 'published'
                || !$this->candidateMatchesStaff($staffId, $assignment, $candidate)) {
                continue;
            }

            try {
                if (!$this->dateInRange($effectiveAt, $candidate['valid_from'] ?? null, $candidate['valid_to'] ?? null)
                    || !$this->dateInRange(
                        $effectiveAt,
                        $candidate['scope_valid_from'] ?? $candidate['valid_from'] ?? null,
                        $candidate['scope_valid_to'] ?? $candidate['valid_to'] ?? null
                    )) {
                    continue;
                }
                $scopeType = (string) $candidate['scope_type'];
                $candidate['_scope_rank'] = self::SCOPE_PRECEDENCE[$scopeType];
                $candidate['_priority'] = (int) ($candidate['scope_priority'] ?? $candidate['priority'] ?? 0);
                $candidate['_effective_start'] = $this->effectiveStart($candidate);
            } catch (\Throwable $exception) {
                $invalid[] = (int) ($candidate['version_id'] ?? 0);
                continue;
            }
            $eligible[] = $candidate;
        }

        $invalid = array_values(array_unique(array_filter($invalid, static fn (int $id): bool => $id > 0)));
        sort($invalid, SORT_NUMERIC);
        if ($invalid !== []) {
            return $this->unresolved(
                'PERMISSION_POLICY_PAYLOAD_INVALID',
                $staffId,
                $permissionTypeId,
                $effectiveAt,
                $assignment,
                $type,
                $invalid
            );
        }
        if ($eligible === []) {
            return $this->unavailable(
                'PERMISSION_POLICY_NOT_FOUND',
                $staffId,
                $permissionTypeId,
                $effectiveAt,
                $assignment,
                $type
            );
        }

        usort($eligible, [$this, 'compareRank']);
        $winner = $eligible[0];
        $ties = array_values(array_filter(
            $eligible,
            fn (array $candidate): bool => $this->sameRank($winner, $candidate)
        ));
        if (count($ties) > 1) {
            $conflicts = array_values(array_unique(array_filter(
                array_map(static fn (array $candidate): int => (int) ($candidate['version_id'] ?? 0), $ties),
                static fn (int $id): bool => $id > 0
            )));
            sort($conflicts, SORT_NUMERIC);

            return $this->unresolved(
                'PERMISSION_POLICY_CONFLICT',
                $staffId,
                $permissionTypeId,
                $effectiveAt,
                $assignment,
                $type,
                $conflicts
            );
        }

        $policy = $this->normalizePolicy($winner);

        return [
            'status' => 'resolved',
            'reason_code' => 'PERMISSION_POLICY_RESOLVED',
            'staff_id' => $staffId,
            'permission_type_id' => $permissionTypeId,
            'effective_at' => $effectiveAt->format('Y-m-d H:i:s.u'),
            'assignment' => $assignment,
            'type' => $type,
            'policy' => $policy,
            'conflicts' => [],
            'explanation' => [
                'policy_version_id' => $policy['version_id'],
                'scope_id_record' => $policy['scope']['id'],
                'scope_type' => $policy['scope']['type'],
                'scope_id' => $policy['scope']['scope_id'],
                'priority' => $policy['scope']['priority'],
                'effective_start' => $policy['effective_start'],
            ],
        ];
    }

    /** @param array<string,mixed>|null $assignment @param array<string,mixed>|null $type */
    private function unavailable(
        string $reasonCode,
        int $staffId,
        int $permissionTypeId,
        DateTimeImmutable $effectiveAt,
        ?array $assignment,
        ?array $type
    ): array {
        return [
            'status' => 'unavailable',
            'reason_code' => $reasonCode,
            'staff_id' => $staffId,
            'permission_type_id' => $permissionTypeId,
            'effective_at' => $effectiveAt->format('Y-m-d H:i:s.u'),
            'assignment' => $assignment,
            'type' => $type,
            'policy' => null,
            'conflicts' => [],
            'explanation' => ['reason_code' => $reasonCode],
        ];
    }

    /**
     * @param array<string,mixed> $assignment
     * @param array<string,mixed> $type
     * @param list<int> $conflicts
     * @return array<string,mixed>
     */
    private function unresolved(
        string $reasonCode,
        int $staffId,
        int $permissionTypeId,
        DateTimeImmutable $effectiveAt,
        array $assignment,
        array $type,
        array $conflicts
    ): array {
        return [
            'status' => 'unresolved',
            'reason_code' => $reasonCode,
            'staff_id' => $staffId,
            'permission_type_id' => $permissionTypeId,
            'effective_at' => $effectiveAt->format('Y-m-d H:i:s.u'),
            'assignment' => $assignment,
            'type' => $type,
            'policy' => null,
            'conflicts' => $conflicts,
            'explanation' => [
                'reason_code' => $reasonCode,
                'conflicting_policy_version_ids' => $conflicts,
            ],
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function candidateMatchesStaff(int $staffId, array $assignment, array $candidate): bool
    {
        $scopeType = (string) ($candidate['scope_type'] ?? '');
        $scopeId = (int) ($candidate['scope_id'] ?? 0);
        if (!isset(self::SCOPE_PRECEDENCE[$scopeType])) {
            return false;
        }

        return match ($scopeType) {
            'global' => $scopeId === 0,
            'staff' => $scopeId === $staffId,
            'org_unit' => $scopeId === (int) ($assignment['org_unit_id'] ?? 0),
            'job_title' => $scopeId === (int) ($assignment['job_title_id'] ?? 0),
            'group' => in_array(
                $scopeId,
                array_map('intval', (array) ($assignment['group_ids'] ?? [])),
                true
            ),
        };
    }

    private function dateInRange(DateTimeImmutable $at, mixed $from, mixed $to): bool
    {
        if ($from === null || trim((string) $from) === '') {
            throw new DomainException('PERMISSION_POLICY_VALID_FROM_MISSING');
        }
        $start = new DateTimeImmutable((string) $from);
        $end = $to === null || trim((string) $to) === '' ? null : new DateTimeImmutable((string) $to);

        return $at >= $start && ($end === null || $at < $end);
    }

    /** @param array<string,mixed> $candidate */
    private function effectiveStart(array $candidate): string
    {
        $policyStart = new DateTimeImmutable((string) $candidate['valid_from']);
        $scopeStart = new DateTimeImmutable((string) ($candidate['scope_valid_from'] ?? $candidate['valid_from']));

        return ($scopeStart > $policyStart ? $scopeStart : $policyStart)
            ->format(DateTimeImmutable::ATOM);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compareRank(array $left, array $right): int
    {
        $scope = $right['_scope_rank'] <=> $left['_scope_rank'];
        if ($scope !== 0) {
            return $scope;
        }
        $priority = $right['_priority'] <=> $left['_priority'];
        if ($priority !== 0) {
            return $priority;
        }

        return strcmp((string) $right['_effective_start'], (string) $left['_effective_start']);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameRank(array $left, array $right): bool
    {
        return $left['_scope_rank'] === $right['_scope_rank']
            && $left['_priority'] === $right['_priority']
            && $left['_effective_start'] === $right['_effective_start'];
    }

    /** @param array<string,mixed> $type @return array<string,mixed> */
    private function normalizeType(array $type, int $permissionTypeId): array
    {
        return [
            'id' => (int) ($type['id'] ?? $permissionTypeId),
            'code' => (string) ($type['code'] ?? ''),
            'name' => (string) ($type['name'] ?? ''),
            'coverage_behavior' => (string) ($type['coverage_behavior'] ?? 'none'),
            'requires_reason' => (bool) ($type['requires_reason'] ?? false),
            'requires_custom_label' => (bool) ($type['requires_custom_label'] ?? false),
            'requires_attachment' => (bool) ($type['requires_attachment'] ?? false),
            'allow_retroactive' => (bool) ($type['allow_retroactive'] ?? false),
            'status' => (string) ($type['status'] ?? 'inactive'),
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function normalizePolicy(array $candidate): array
    {
        return [
            'version_id' => (int) ($candidate['version_id'] ?? 0),
            'permission_type_id' => (int) ($candidate['permission_type_id'] ?? 0),
            'version_no' => (int) ($candidate['version_no'] ?? 0),
            'valid_from' => (string) ($candidate['valid_from'] ?? ''),
            'valid_to' => $this->nullableString($candidate['valid_to'] ?? null),
            'timezone' => (string) ($candidate['timezone'] ?? 'Africa/Cairo'),
            'max_requests_per_month' => $this->nullableInt($candidate['max_requests_per_month'] ?? null),
            'max_minutes_per_request' => $this->nullableInt($candidate['max_minutes_per_request'] ?? null),
            'max_minutes_per_month' => $this->nullableInt($candidate['max_minutes_per_month'] ?? null),
            'min_notice_minutes' => (int) ($candidate['min_notice_minutes'] ?? 0),
            'retroactive_limit_days' => (int) ($candidate['retroactive_limit_days'] ?? 0),
            'reserve_on_submit' => (bool) ($candidate['reserve_on_submit'] ?? false),
            'allow_overlap' => (bool) ($candidate['allow_overlap'] ?? false),
            'allow_quota_override' => (bool) ($candidate['allow_quota_override'] ?? false),
            'quota_override_max_minutes' => $this->nullableInt($candidate['quota_override_max_minutes'] ?? null),
            'scope' => [
                'id' => (int) ($candidate['scope_id_record'] ?? 0),
                'type' => (string) ($candidate['scope_type'] ?? ''),
                'scope_id' => (int) ($candidate['scope_id'] ?? 0),
                'priority' => (int) ($candidate['scope_priority'] ?? $candidate['priority'] ?? 0),
                'valid_from' => (string) ($candidate['scope_valid_from'] ?? ''),
                'valid_to' => $this->nullableString($candidate['scope_valid_to'] ?? null),
            ],
            'effective_start' => (string) ($candidate['_effective_start'] ?? ''),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
