<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Modules/Staff/Contracts/ManagerHierarchyAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/PermissionSubmissionWorkflowResolver.php';
require_once $root . '/src/Modules/Staff/Contracts/ApprovalWorkflowResolutionGateway.php';
require_once $root . '/src/Modules/Staff/Contracts/ApprovalWorkflowDefinitionQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/ApprovalRoleAssigneeQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/ApprovalDelegationQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Approval/PdoApprovalWorkflowDefinitionQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Approval/PdoApprovalRoleAssigneeQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Approval/PdoApprovalDelegationQuery.php';
require_once $root . '/src/Modules/Staff/Application/Approval/ApprovalWorkflowResolver.php';

use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowResolver;
use EduCore\Modules\Staff\Contracts\ApprovalRoleAssigneeQuery;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowDefinitionQuery;
use EduCore\Modules\Staff\Contracts\ApprovalDelegationQuery;
use EduCore\Modules\Staff\Contracts\ManagerHierarchyAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalDelegationQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalRoleAssigneeQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowDefinitionQuery;

final class ApprovalResolverFixtureDefinitions implements ApprovalWorkflowDefinitionQuery
{
    /** @var list<array<string,mixed>> */
    public array $definitions = [];

    /** @var list<array{resource_type:string,effective_at:string}> */
    public array $calls = [];

    public function findPublishedForResource(string $resourceType, DateTimeImmutable $effectiveAt): array
    {
        $this->calls[] = [
            'resource_type' => $resourceType,
            'effective_at' => $effectiveAt->format('Y-m-d H:i:s'),
        ];

        return $this->definitions;
    }
}

final class ApprovalResolverFixtureManagers implements ManagerHierarchyAtDateQuery
{
    /** @var array<string,array<string,mixed>> */
    public array $responses = [];

    /** @var list<array{staff_id:int,kind:string,at:string}> */
    public array $calls = [];

    public function resolve(int $staffId, string $managerKind, DateTimeImmutable $atDate): array
    {
        $this->calls[] = [
            'staff_id' => $staffId,
            'kind' => $managerKind,
            'at' => $atDate->format('Y-m-d H:i:s'),
        ];

        return $this->responses[$managerKind] ?? [
            'manager_id' => null,
            'assignment_id' => 41,
            'delegation' => null,
            'conflicts' => [],
        ];
    }
}

final class ApprovalResolverFixtureRoles implements ApprovalRoleAssigneeQuery
{
    /** @var list<array{user_id:int,role_keys:list<string>}> */
    public array $users = [];

    /** @var list<string> */
    public array $lastRoleKeys = [];

    public function activeUsersForRoles(array $roleKeys, DateTimeImmutable $resolvedAt): array
    {
        $this->lastRoleKeys = $roleKeys;

        return $this->users;
    }
}

final class ApprovalResolverFixtureDelegations implements ApprovalDelegationQuery
{
    /** @var array<string,mixed> */
    public array $response = ['delegation' => null, 'conflicts' => []];

    /** @var list<array{delegator:int,staff:int,resource:string,request_type:?int}> */
    public array $calls = [];

    public function resolve(
        int $delegatorUserId,
        int $staffUserId,
        ?int $orgUnitId,
        array $groupIds,
        string $resourceType,
        ?int $requestTypeId,
        DateTimeImmutable $atDate
    ): array {
        $this->calls[] = [
            'delegator' => $delegatorUserId,
            'staff' => $staffUserId,
            'resource' => $resourceType,
            'request_type' => $requestTypeId,
        ];

        return $this->response;
    }
}

/** @param array<string,mixed> $overrides @return array<string,mixed> */
function approvalResolverStage(int $id, int $sequence, string $resolverType, array $config = [], array $overrides = []): array
{
    return array_replace([
        'stage_id' => $id,
        'sequence_no' => $sequence,
        'name' => "Stage {$sequence}",
        'resolver_type' => $resolverType,
        'resolver_config' => $config,
        'decision_mode' => 'sequential',
        'quorum_count' => null,
        'sla_minutes' => 60,
        'on_timeout' => 'fail_closed',
        'self_approval_rule' => 'forbid',
        'same_actor_rule' => 'forbid',
        'tie_rule' => 'reject',
        'rejection_rule' => 'stop_workflow',
    ], $overrides);
}

/** @param list<array<string,mixed>> $stages @return array<string,mixed> */
function approvalResolverDefinition(array $stages, int $versionId = 71): array
{
    return [
        'workflow_id' => 70,
        'workflow_code' => 'PERMISSION_DEFAULT',
        'workflow_name' => 'Default permission workflow',
        'resource_type' => 'permission_request',
        'workflow_version_id' => $versionId,
        'version_no' => 3,
        'valid_from' => '2026-01-01 00:00:00.000000',
        'valid_to' => null,
        'cancellation_rule' => 'request_cancellation',
        'escalation_rule' => ['after_minutes' => 60],
        'stages' => $stages,
    ];
}

/** @param callable():mixed $callback */
function approvalResolverThrows(callable $callback, string $expectedCode): bool
{
    try {
        $callback();
    } catch (DomainException $exception) {
        return $exception->getMessage() === $expectedCode;
    }

    return false;
}

$definitions = new ApprovalResolverFixtureDefinitions();
$managers = new ApprovalResolverFixtureManagers();
$roles = new ApprovalResolverFixtureRoles();
$delegations = new ApprovalResolverFixtureDelegations();
$resolver = new ApprovalWorkflowResolver($definitions, $managers, $roles, $delegations);
$managers->responses = [
    'direct' => [
        'manager_id' => 501,
        'assignment_id' => 41,
        'delegation' => null,
        'conflicts' => [],
    ],
    'administrative' => [
        'manager_id' => 601,
        'assignment_id' => 41,
        'delegation' => null,
        'conflicts' => [],
    ],
];
$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(1, 1, 'direct_manager'),
    approvalResolverStage(2, 2, 'admin_manager'),
])];
$request = ['id' => 9001, 'from_at' => '2026-12-01 07:30:00'];
$policy = ['timezone' => 'Africa/Cairo'];
$assignment = ['assignment_id' => 41, 'org_unit_id' => 10, 'job_title_id' => 20, 'group_ids' => [30]];
$submittedAt = new DateTimeImmutable('2026-08-07 10:00:00', new DateTimeZone('Africa/Cairo'));
$directAndAdmin = $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt);

$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(3, 1, 'direct_manager', ['fallback_user_ids' => [701]]),
], 72)];
$managers->responses['direct'] = [
    'manager_id' => null,
    'assignment_id' => 41,
    'delegation' => null,
    'conflicts' => [],
];
$fallback = $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt);

$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(4, 1, 'direct_manager'),
], 73)];
$delegations->response = [
    'delegation' => [
        'delegation_id' => 81,
        'acting_for_user_id' => 501,
        'delegate_user_id' => 502,
        'valid_from' => '2026-01-01 00:00:00.000000',
        'valid_to' => '2026-12-31 23:59:59.999999',
    ],
    'conflicts' => [],
];
$managers->responses['direct'] = [
    'manager_id' => 501,
    'assignment_id' => 41,
    'delegation' => null,
    'conflicts' => [],
];
$delegated = $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt);

$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(5, 1, 'role_scope', ['role_keys' => ['hr_manager']], [
        'decision_mode' => 'quorum',
        'quorum_count' => 2,
    ]),
], 74)];
$delegations->response = ['delegation' => null, 'conflicts' => []];
$roles->users = [
    ['user_id' => 801, 'role_keys' => ['hr_manager']],
    ['user_id' => 802, 'role_keys' => ['hr_manager']],
];
$roleScope = $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt);

$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(6, 1, 'named_users', ['user_ids' => [100, 901]]),
], 75)];
$selfExcluded = $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt);

$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(7, 1, 'named_users', ['user_ids' => [901]]),
    approvalResolverStage(8, 2, 'named_users', ['user_ids' => [901]]),
], 76)];
$duplicateRejected = approvalResolverThrows(
    static fn (): array => $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt),
    'DUPLICATE_STAGE_ACTOR'
);

$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(9, 1, 'named_users', ['user_ids' => [901]]),
    approvalResolverStage(10, 2, 'named_users', ['user_ids' => [901, 902]], [
        'same_actor_rule' => 'require_alternate',
    ]),
], 77)];
$alternate = $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt);

$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(11, 1, 'named_users', ['user_ids' => [901]]),
    approvalResolverStage(12, 2, 'named_users', ['user_ids' => [901]], [
        'same_actor_rule' => 'merge',
    ]),
], 78)];
$merged = $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt);

$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(13, 1, 'direct_manager'),
], 79)];
$managers->responses['direct'] = [
    'manager_id' => null,
    'assignment_id' => 41,
    'delegation' => null,
    'conflicts' => [['reason' => 'ambiguous_manager_assignment']],
];
$managerConflictRejected = approvalResolverThrows(
    static fn (): array => $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt),
    'APPROVAL_MANAGER_HIERARCHY_CONFLICT'
);

$definitions->definitions = [approvalResolverDefinition([
    approvalResolverStage(14, 1, 'direct_manager'),
], 80)];
$managers->responses['direct'] = [
    'manager_id' => 501,
    'assignment_id' => 99,
    'delegation' => null,
    'conflicts' => [],
];
$assignmentMismatchRejected = approvalResolverThrows(
    static fn (): array => $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt),
    'APPROVAL_ASSIGNMENT_SNAPSHOT_MISMATCH'
);

$definitions->definitions = [];
$missingWorkflowRejected = approvalResolverThrows(
    static fn (): array => $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt),
    'APPROVER_NOT_CONFIGURED'
);

$definitions->definitions = [
    approvalResolverDefinition([approvalResolverStage(15, 1, 'named_users', ['user_ids' => [901]])], 81),
    approvalResolverDefinition([approvalResolverStage(16, 1, 'named_users', ['user_ids' => [902]])], 82),
];
$ambiguousWorkflowRejected = approvalResolverThrows(
    static fn (): array => $resolver->resolveForSubmission(100, 100, 8, $request, $policy, $assignment, $submittedAt),
    'APPROVAL_WORKFLOW_AMBIGUOUS'
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE staff_approval_workflows (id INTEGER PRIMARY KEY, code TEXT, name TEXT, resource_type TEXT, status TEXT)');
$pdo->exec('CREATE TABLE staff_approval_workflow_versions (id INTEGER PRIMARY KEY, workflow_id INTEGER, version_no INTEGER, state TEXT, valid_from TEXT, valid_to TEXT, cancellation_rule TEXT, escalation_rule TEXT)');
$pdo->exec('CREATE TABLE staff_approval_stages (id INTEGER PRIMARY KEY, workflow_version_id INTEGER, sequence_no INTEGER, name TEXT, resolver_type TEXT, resolver_config TEXT, decision_mode TEXT, sla_minutes INTEGER, on_timeout TEXT, self_approval_rule TEXT, same_actor_rule TEXT, quorum_count INTEGER, tie_rule TEXT, rejection_rule TEXT)');
$pdo->exec('CREATE TABLE user_role_assignments (user_id INTEGER, role_key TEXT, status TEXT)');
$pdo->exec('CREATE TABLE staff_delegations (id INTEGER PRIMARY KEY, delegator_user_id INTEGER, delegate_user_id INTEGER, scope_type TEXT, scope_id INTEGER, request_types TEXT, valid_from TEXT, valid_to TEXT, status TEXT)');
$pdo->exec("INSERT INTO staff_approval_workflows VALUES (1, 'P', 'Permission', 'permission_request', 'active')");
$pdo->exec("INSERT INTO staff_approval_workflow_versions VALUES (2, 1, 1, 'published', '2026-01-01 00:00:00.000000', '2026-08-01 00:00:00.000000', 'request_cancellation', '{\"after_minutes\":60}')");
$pdo->exec("INSERT INTO staff_approval_stages VALUES (3, 2, 1, 'Manager', 'named_users', '{\"user_ids\":[901]}', 'sequential', 60, 'fail_closed', 'forbid', 'forbid', NULL, 'reject', 'stop_workflow')");
$pdo->exec("INSERT INTO user_role_assignments VALUES (801, 'hr_manager', 'active'), (802, 'hr_manager', 'inactive'), (803, 'admin', 'active')");
$pdo->exec("INSERT INTO staff_delegations VALUES
    (91, 501, 510, 'global', 0, '[\"permission_request\"]', '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active'),
    (92, 501, 511, 'request_type', 8, '[\"permission_request\"]', '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active'),
    (93, 501, 512, 'staff', 100, '[\"leave_request\"]', '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active')");
$pdoDefinitions = new PdoApprovalWorkflowDefinitionQuery($pdo);
$pdoRoles = new PdoApprovalRoleAssigneeQuery($pdo);
$pdoDelegations = new PdoApprovalDelegationQuery($pdo);
$pdoPublished = $pdoDefinitions->findPublishedForResource('permission_request', new DateTimeImmutable('2026-07-31 23:59:59'));
$pdoExpired = $pdoDefinitions->findPublishedForResource('permission_request', new DateTimeImmutable('2026-08-01 00:00:00'));
$pdoRoleUsers = $pdoRoles->activeUsersForRoles(['hr_manager'], $submittedAt);
$pdoDelegation = $pdoDelegations->resolve(501, 100, 10, [30], 'permission_request', 8, new DateTimeImmutable('2026-07-31 23:59:59'));

$checks = [
    'direct_then_administrative_stages_are_snapshotted_in_order' => $directAndAdmin['workflow_version_id'] === 71
        && ($directAndAdmin['snapshot']['stages'][0]['assignees'][0]['user_id'] ?? null) === 501
        && ($directAndAdmin['snapshot']['stages'][1]['assignees'][0]['user_id'] ?? null) === 601,
    'future_permission_uses_request_start_for_manager_resolution' => ($managers->calls[0]['at'] ?? null) === '2026-12-01 07:30:00'
        && ($definitions->calls[0]['effective_at'] ?? null) === '2026-12-01 07:30:00',
    'published_named_fallback_is_used_only_when_manager_is_missing' => ($fallback['snapshot']['stages'][0]['assignees'][0]['user_id'] ?? null) === 701
        && ($fallback['snapshot']['stages'][0]['assignees'][0]['relationship_kind'] ?? null) === 'manager_fallback',
    'delegation_replaces_assignee_but_preserves_acting_for_evidence' => ($delegated['snapshot']['stages'][0]['assignees'][0]['user_id'] ?? null) === 502
        && ($delegated['snapshot']['stages'][0]['assignees'][0]['acting_for_user_id'] ?? null) === 501
        && ($delegated['snapshot']['stages'][0]['assignees'][0]['delegation_id'] ?? null) === 81
        && ($delegations->calls[0]['request_type'] ?? null) === 8,
    'role_scope_snapshots_all_resolved_role_assignees_and_quorum' => array_column($roleScope['snapshot']['stages'][0]['assignees'] ?? [], 'user_id') === [801, 802]
        && ($roleScope['snapshot']['stages'][0]['quorum_count'] ?? null) === 2
        && $roles->lastRoleKeys === ['hr_manager'],
    'self_candidate_is_removed_when_a_safe_alternate_exists' => array_column($selfExcluded['snapshot']['stages'][0]['assignees'] ?? [], 'user_id') === [901],
    'duplicate_actor_is_never_silently_counted_twice' => $duplicateRejected,
    'require_alternate_replaces_repeated_actor_with_new_assignee' => array_column($alternate['snapshot']['stages'][1]['assignees'] ?? [], 'user_id') === [902],
    'merge_rule_keeps_explicit_duplicate_evidence_for_state_machine' => ($merged['snapshot']['stages'][1]['merged_actor_ids'] ?? null) === [901],
    'manager_hierarchy_conflict_fails_closed' => $managerConflictRejected,
    'assignment_snapshot_mismatch_fails_closed' => $assignmentMismatchRejected,
    'missing_published_workflow_fails_closed' => $missingWorkflowRejected,
    'ambiguous_published_workflow_fails_closed' => $ambiguousWorkflowRejected,
    'pdo_definition_query_reads_only_effective_published_version_with_stages' => count($pdoPublished) === 1
        && ($pdoPublished[0]['workflow_version_id'] ?? null) === 2
        && ($pdoPublished[0]['stages'][0]['stage_id'] ?? null) === 3
        && $pdoExpired === [],
    'pdo_role_query_keeps_only_active_requested_roles' => $pdoRoleUsers === [['user_id' => 801, 'role_keys' => ['hr_manager']]],
    'pdo_delegation_query_honors_resource_and_request_type_scope' => ($pdoDelegation['delegation']['delegate_user_id'] ?? null) === 511
        && ($pdoDelegation['delegation']['acting_for_user_id'] ?? null) === 501
        && $pdoDelegation['conflicts'] === [],
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
