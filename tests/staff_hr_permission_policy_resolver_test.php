<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Staff\Application\Permission\PermissionPolicyResolver;
use EduCore\Modules\Staff\Contracts\PermissionPolicyReadRepository;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;

final class PermissionResolverFixtureRepository implements PermissionPolicyReadRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $types = [];

    /** @var list<array<string,mixed>> */
    public array $candidates = [];

    public int $candidateCalls = 0;

    public function findType(int $permissionTypeId): ?array
    {
        return $this->types[$permissionTypeId] ?? null;
    }

    public function candidateVersionsFor(
        int $permissionTypeId,
        int $staffId,
        array $assignment,
        DateTimeImmutable $effectiveAt
    ): array {
        ++$this->candidateCalls;

        return $this->candidates;
    }
}

final class PermissionResolverFixtureAssignments implements StaffAssignmentAtDateQuery
{
    /** @var array<string,mixed>|null */
    public ?array $assignment;

    public function __construct(?array $assignment)
    {
        $this->assignment = $assignment;
    }

    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
    {
        return $this->assignment;
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $callback, string $code, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), $code), $message . ' (' . $exception->getMessage() . ')');
    }
};

$candidate = static function (
    int $versionId,
    string $scopeType,
    int $scopeId,
    int $priority,
    string $validFrom,
    ?string $validTo = null,
    ?string $scopeValidFrom = null,
    ?string $scopeValidTo = null,
    int $permissionTypeId = 1
): array {
    return [
        'version_id' => $versionId,
        'permission_type_id' => $permissionTypeId,
        'version_no' => $versionId,
        'state' => 'published',
        'valid_from' => $validFrom,
        'valid_to' => $validTo,
        'timezone' => 'Africa/Cairo',
        'max_requests_per_month' => 3,
        'max_minutes_per_request' => 120,
        'max_minutes_per_month' => 240,
        'min_notice_minutes' => 30,
        'retroactive_limit_days' => 2,
        'reserve_on_submit' => 1,
        'allow_overlap' => 0,
        'allow_quota_override' => 0,
        'quota_override_max_minutes' => null,
        'scope_id_record' => $versionId,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'scope_priority' => $priority,
        'scope_valid_from' => $scopeValidFrom ?? $validFrom,
        'scope_valid_to' => $scopeValidTo ?? $validTo,
    ];
};

$activeAssignment = [
    'assignment_id' => 900,
    'org_unit_id' => 20,
    'job_title_id' => 30,
    'group_ids' => [40, 41],
    'employment_status' => 'active',
];
$repository = new PermissionResolverFixtureRepository();
$repository->types[1] = [
    'id' => 1,
    'code' => 'LATE_ARRIVAL',
    'name' => 'Late arrival',
    'coverage_behavior' => 'late_arrival',
    'requires_reason' => 1,
    'requires_custom_label' => 0,
    'requires_attachment' => 0,
    'allow_retroactive' => 1,
    'status' => 'active',
];
$assignmentQuery = new PermissionResolverFixtureAssignments($activeAssignment);
$resolver = new PermissionPolicyResolver($repository, $assignmentQuery);
$at = new DateTimeImmutable('2026-10-15 08:00:00.000000');

$repository->candidates = [
    $candidate(101, 'global', 0, 0, '2026-01-01 00:00:00.000000'),
    $candidate(102, 'job_title', 30, 0, '2026-01-01 00:00:00.000000'),
    $candidate(103, 'org_unit', 20, 0, '2026-01-01 00:00:00.000000'),
    $candidate(104, 'group', 40, 10, '2026-01-01 00:00:00.000000'),
    $candidate(105, 'staff', 10, 0, '2026-01-01 00:00:00.000000'),
];
$resolved = $resolver->resolve(10, 1, $at);
$assert($resolved['status'] === 'resolved', 'active worker with a matching policy resolves');
$assert($resolved['policy']['version_id'] === 105, 'staff scope outranks group, unit, title, and global');
$assert($resolved['explanation']['scope_type'] === 'staff', 'resolution explains the winning scope');
$assert($resolved['policy']['max_minutes_per_month'] === 240, 'resolver normalizes policy limits');
$assert($resolved['type']['coverage_behavior'] === 'late_arrival', 'resolver returns safe permission-type semantics');

$repository->candidates = [
    $candidate(201, 'global', 0, 0, '2026-01-01 00:00:00.000000'),
    $candidate(202, 'group', 40, 3, '2026-01-01 00:00:00.000000'),
    $candidate(203, 'group', 41, 9, '2026-01-01 00:00:00.000000'),
];
$groupPriority = $resolver->resolve(10, 1, $at);
$assert($groupPriority['policy']['version_id'] === 203, 'higher configured priority wins within the same scope rank');

$repository->candidates = [
    $candidate(301, 'global', 0, 0, '2026-01-01 00:00:00.000000'),
    $candidate(302, 'group', 99, 100, '2026-01-01 00:00:00.000000'),
];
$unmatchedGroup = $resolver->resolve(10, 1, $at);
$assert($unmatchedGroup['policy']['version_id'] === 301, 'unmatched group scope never leaks into the worker policy');

$repository->candidates = [
    $candidate(401, 'group', 40, 5, '2026-01-01 00:00:00.000000'),
    $candidate(402, 'group', 41, 5, '2026-01-01 00:00:00.000000'),
];
$tie = $resolver->resolve(10, 1, $at);
$assert($tie['status'] === 'unresolved', 'equal effective policy rank fails closed');
$assert($tie['reason_code'] === 'PERMISSION_POLICY_CONFLICT', 'equal rank has a stable policy-conflict code');
$assert($tie['conflicts'] === [401, 402], 'policy conflict returns stable version identifiers');

$repository->candidates = [
    $candidate(501, 'global', 0, 0, '2026-01-01 00:00:00.000000', '2026-11-01 00:00:00.000000'),
    $candidate(502, 'global', 0, 0, '2026-11-01 00:00:00.000000'),
];
$beforeBoundary = $resolver->resolve(10, 1, new DateTimeImmutable('2026-10-31 23:59:59.999999'));
$atBoundary = $resolver->resolve(10, 1, new DateTimeImmutable('2026-11-01 00:00:00.000000'));
$assert($beforeBoundary['policy']['version_id'] === 501, 'policy valid_to remains exclusive until its final microsecond');
$assert($atBoundary['policy']['version_id'] === 502, 'successor policy takes effect at its exact half-open boundary');

$repository->candidates = [
    $candidate(601, 'staff', 10, 0, 'not-a-date'),
    $candidate(602, 'global', 0, 0, '2026-01-01 00:00:00.000000'),
];
$invalidCandidate = $resolver->resolve(10, 1, $at);
$assert($invalidCandidate['status'] === 'unresolved', 'invalid matching policy data fails closed instead of falling back');
$assert($invalidCandidate['reason_code'] === 'PERMISSION_POLICY_PAYLOAD_INVALID', 'invalid matching policy has a stable code');
$assert($invalidCandidate['conflicts'] === [601], 'invalid matching policy identifies its version');

$repository->candidates = [];
$missingPolicy = $resolver->resolve(10, 1, $at);
$assert($missingPolicy['status'] === 'unavailable', 'no effective policy is unavailable');
$assert($missingPolicy['reason_code'] === 'PERMISSION_POLICY_NOT_FOUND', 'missing policy has a stable code');

$inactiveRepository = new PermissionResolverFixtureRepository();
$inactiveRepository->types[1] = $repository->types[1] + ['status' => 'inactive'];
$inactiveRepository->types[1]['status'] = 'inactive';
$inactiveResolver = new PermissionPolicyResolver($inactiveRepository, $assignmentQuery);
$inactiveType = $inactiveResolver->resolve(10, 1, $at);
$assert($inactiveType['status'] === 'unavailable', 'inactive permission type cannot resolve a policy');
$assert($inactiveType['reason_code'] === 'PERMISSION_TYPE_INACTIVE', 'inactive type has a stable code');
$assert($inactiveRepository->candidateCalls === 0, 'inactive permission type avoids unnecessary policy reads');

$inactiveAssignment = $activeAssignment;
$inactiveAssignment['employment_status'] = 'ended';
$inactiveAssignmentQuery = new PermissionResolverFixtureAssignments($inactiveAssignment);
$inactiveWorkerResolver = new PermissionPolicyResolver($repository, $inactiveAssignmentQuery);
$inactiveWorker = $inactiveWorkerResolver->resolve(10, 1, $at);
$assert($inactiveWorker['status'] === 'unavailable', 'inactive worker cannot resolve a request policy');
$assert($inactiveWorker['reason_code'] === 'STAFF_NOT_ACTIVE', 'inactive worker uses a safe availability code');

$assertThrows(
    static fn (): array => $resolver->resolve(0, 1, $at),
    'STAFF_ID_INVALID',
    'invalid staff identifier is rejected before a repository query'
);
$assertThrows(
    static fn (): array => $resolver->resolve(10, 0, $at),
    'PERMISSION_TYPE_ID_INVALID',
    'invalid permission type identifier is rejected before a repository query'
);
$assertThrows(
    static fn (): array => (new PermissionPolicyResolver())->resolve(10, 1, $at),
    'dependencies are not configured',
    'unconfigured resolver fails explicitly rather than returning an unsafe policy'
);

$adapterSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/PdoPermissionPolicyReadRepository.php'
);
$assert(
    str_contains($adapterSource, 'staff_permission_policy_versions')
    && str_contains($adapterSource, 'staff_permission_policy_scopes'),
    'PDO adapter reads the Staff-owned permission policy tables'
);
$assert(
    !str_contains($adapterSource, 'staff_assignments')
    && !str_contains($adapterSource, 'staff_policy_group_memberships')
    && !str_contains($adapterSource, 'staff_profiles'),
    'PDO adapter consumes the dated assignment snapshot instead of querying current Staff internals'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} permission policy resolver failure(s).\n");
    exit(1);
}

echo "Staff-HR permission policy resolver tests passed.\n";
