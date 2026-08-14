<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Approval;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Staff\Contracts\ApprovalDelegationQuery;
use EduCore\Modules\Staff\Contracts\ApprovalRoleAssigneeQuery;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowDefinitionQuery;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowResolutionGateway;
use EduCore\Modules\Staff\Contracts\ManagerHierarchyAtDateQuery;
use EduCore\Modules\Staff\Contracts\PermissionSubmissionWorkflowResolver;
use InvalidArgumentException;
use JsonException;

/**
 * Converts one published, effective workflow definition into immutable
 * submission evidence. It resolves people before persistence; it does not
 * create instances, steps, decisions, or notifications.
 */
final class ApprovalWorkflowResolver implements PermissionSubmissionWorkflowResolver, ApprovalWorkflowResolutionGateway
{
    private const RESOURCE_PERMISSION_REQUEST = 'permission_request';
    private const RESOLVER_TYPES = ['direct_manager', 'admin_manager', 'named_users', 'role_scope'];
    private const DECISION_MODES = ['sequential', 'any_one', 'all', 'quorum'];
    private const SELF_APPROVAL_RULES = ['forbid', 'require_alternate', 'allow_explicit'];
    private const SAME_ACTOR_RULES = ['forbid', 'merge', 'require_alternate'];

    public function __construct(
        private ApprovalWorkflowDefinitionQuery $definitions,
        private ManagerHierarchyAtDateQuery $managers,
        private ApprovalRoleAssigneeQuery $roles,
        private ApprovalDelegationQuery $delegations
    ) {
    }

    public function resolveForSubmission(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        array $request,
        array $policy,
        array $assignment,
        DateTimeImmutable $submittedAt
    ): array {
        if ($actorId <= 0 || $staffUserId <= 0 || $permissionTypeId <= 0) {
            throw new InvalidArgumentException('Approval submission identifiers must be positive.');
        }

        $effectiveAt = $this->permissionEffectiveAt($request, $policy);

        return $this->resolveForResource(
            self::RESOURCE_PERMISSION_REQUEST,
            $staffUserId,
            [
                'actor_id' => $actorId,
                'permission_type_id' => $permissionTypeId,
                'request_id' => $this->nullablePositiveId($request['id'] ?? null),
                'assignment_id' => $this->positiveId(
                    $assignment['assignment_id'] ?? null,
                    'APPROVAL_ASSIGNMENT_SNAPSHOT_INVALID'
                ),
                'assignment' => $assignment,
            ],
            $effectiveAt,
            $submittedAt
        );
    }

    /**
     * Generic entrypoint for future Staff-owned request types.
     *
     * @param array<string,mixed> $context
     * @return array{workflow_version_id:int,snapshot:array<string,mixed>}
     */
    public function resolveForResource(
        string $resourceType,
        int $staffUserId,
        array $context,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $resolvedAt
    ): array {
        $resourceType = trim($resourceType);
        if ($resourceType === '' || $staffUserId <= 0) {
            throw new InvalidArgumentException('Approval workflow resolution requires a resource type and worker.');
        }

        $definitions = $this->uniqueDefinitions(
            $this->definitions->findPublishedForResource($resourceType, $effectiveAt)
        );
        if ($definitions === []) {
            throw new DomainException('APPROVER_NOT_CONFIGURED');
        }
        if (count($definitions) !== 1) {
            throw new DomainException('APPROVAL_WORKFLOW_AMBIGUOUS');
        }

        $definition = $definitions[0];
        $stages = $this->orderedStages($definition['stages'] ?? []);
        if ($stages === []) {
            throw new DomainException('APPROVER_NOT_CONFIGURED');
        }

        $seenActorIds = [];
        $snapshotStages = [];
        foreach ($stages as $stage) {
            $normalized = $this->normalizeStage($stage);
            $assignees = $this->resolveStageAssignees(
                $normalized,
                $resourceType,
                $staffUserId,
                $context,
                $effectiveAt,
                $resolvedAt
            );
            $assignees = $this->applySelfApprovalRule(
                $assignees,
                $staffUserId,
                $normalized['self_approval_rule']
            );
            [$assignees, $mergedActorIds] = $this->applySameActorRule(
                $assignees,
                $seenActorIds,
                $normalized['same_actor_rule']
            );
            $this->assertDecisionModeHasValidAssignees($normalized, $assignees, $mergedActorIds);

            foreach ($assignees as $assignee) {
                $seenActorIds[(int) $assignee['user_id']] = true;
            }
            $snapshotStages[] = [
                'stage_id' => $normalized['stage_id'],
                'sequence_no' => $normalized['sequence_no'],
                'name' => $normalized['name'],
                'resolver_type' => $normalized['resolver_type'],
                'decision_mode' => $normalized['decision_mode'],
                'quorum_count' => $normalized['quorum_count'],
                'sla_minutes' => $normalized['sla_minutes'],
                'on_timeout' => $normalized['on_timeout'],
                'self_approval_rule' => $normalized['self_approval_rule'],
                'same_actor_rule' => $normalized['same_actor_rule'],
                'tie_rule' => $normalized['tie_rule'],
                'rejection_rule' => $normalized['rejection_rule'],
                'merged_actor_ids' => $mergedActorIds,
                'assignees' => $assignees,
            ];
        }

        return [
            'workflow_version_id' => $this->positiveId(
                $definition['workflow_version_id'] ?? null,
                'APPROVAL_WORKFLOW_VERSION_INVALID'
            ),
            'snapshot' => [
                'schema_version' => 1,
                'resource_type' => $resourceType,
                'workflow_id' => $this->positiveId($definition['workflow_id'] ?? null, 'APPROVAL_WORKFLOW_INVALID'),
                'workflow_code' => $this->requiredText($definition['workflow_code'] ?? null, 'APPROVAL_WORKFLOW_CODE_INVALID'),
                'workflow_name' => $this->requiredText($definition['workflow_name'] ?? null, 'APPROVAL_WORKFLOW_NAME_INVALID'),
                'workflow_version_no' => $this->positiveId($definition['version_no'] ?? null, 'APPROVAL_WORKFLOW_VERSION_INVALID'),
                'valid_from' => $this->requiredText($definition['valid_from'] ?? null, 'APPROVAL_WORKFLOW_VALIDITY_INVALID'),
                'valid_to' => $this->nullableText($definition['valid_to'] ?? null),
                'cancellation_rule' => $this->requiredText($definition['cancellation_rule'] ?? null, 'APPROVAL_WORKFLOW_CANCELLATION_RULE_INVALID'),
                'escalation_rule' => $this->normalizedJsonValue($definition['escalation_rule'] ?? null, 'APPROVAL_WORKFLOW_ESCALATION_RULE_INVALID'),
                'effective_at' => $this->databaseInstant($effectiveAt),
                'resolved_at' => $this->databaseInstant($resolvedAt),
                'context' => $this->snapshotContext($context, $staffUserId),
                'stages' => $snapshotStages,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $definitions @return list<array<string,mixed>> */
    private function uniqueDefinitions(array $definitions): array
    {
        $unique = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new DomainException('APPROVAL_WORKFLOW_PAYLOAD_INVALID');
            }
            $versionId = $this->positiveId(
                $definition['workflow_version_id'] ?? null,
                'APPROVAL_WORKFLOW_VERSION_INVALID'
            );
            $unique[$versionId] = $definition;
        }

        return array_values($unique);
    }

    /** @param mixed $stages @return list<array<string,mixed>> */
    private function orderedStages(mixed $stages): array
    {
        if (!is_array($stages)) {
            throw new DomainException('APPROVAL_WORKFLOW_STAGES_INVALID');
        }

        $bySequence = [];
        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                throw new DomainException('APPROVAL_WORKFLOW_STAGE_INVALID');
            }
            $sequence = $this->positiveId($stage['sequence_no'] ?? null, 'APPROVAL_STAGE_SEQUENCE_INVALID');
            if (isset($bySequence[$sequence])) {
                throw new DomainException('APPROVAL_STAGE_SEQUENCE_CONFLICT');
            }
            $bySequence[$sequence] = $stage;
        }
        ksort($bySequence, SORT_NUMERIC);

        return array_values($bySequence);
    }

    /**
     * @param array<string,mixed> $stage
     * @return array{stage_id:int,sequence_no:int,name:string,resolver_type:string,resolver_config:array<string,mixed>,decision_mode:string,quorum_count:?int,sla_minutes:?int,on_timeout:string,self_approval_rule:string,same_actor_rule:string,tie_rule:string,rejection_rule:string}
     */
    private function normalizeStage(array $stage): array
    {
        $resolverType = strtolower($this->requiredText($stage['resolver_type'] ?? null, 'APPROVAL_STAGE_RESOLVER_INVALID'));
        if (!in_array($resolverType, self::RESOLVER_TYPES, true)) {
            throw new DomainException('APPROVAL_STAGE_RESOLVER_INVALID');
        }
        $decisionMode = strtolower($this->requiredText($stage['decision_mode'] ?? null, 'APPROVAL_STAGE_DECISION_MODE_INVALID'));
        if (!in_array($decisionMode, self::DECISION_MODES, true)) {
            throw new DomainException('APPROVAL_STAGE_DECISION_MODE_INVALID');
        }
        $selfRule = strtolower($this->requiredText($stage['self_approval_rule'] ?? null, 'APPROVAL_STAGE_SELF_RULE_INVALID'));
        if (!in_array($selfRule, self::SELF_APPROVAL_RULES, true)) {
            throw new DomainException('APPROVAL_STAGE_SELF_RULE_INVALID');
        }
        $sameActorRule = strtolower($this->requiredText($stage['same_actor_rule'] ?? null, 'APPROVAL_STAGE_SAME_ACTOR_RULE_INVALID'));
        if (!in_array($sameActorRule, self::SAME_ACTOR_RULES, true)) {
            throw new DomainException('APPROVAL_STAGE_SAME_ACTOR_RULE_INVALID');
        }
        $quorum = $this->nullablePositiveId($stage['quorum_count'] ?? null);
        if ($decisionMode === 'quorum' && $quorum === null) {
            throw new DomainException('APPROVAL_STAGE_QUORUM_INVALID');
        }

        return [
            'stage_id' => $this->positiveId($stage['stage_id'] ?? $stage['id'] ?? null, 'APPROVAL_STAGE_ID_INVALID'),
            'sequence_no' => $this->positiveId($stage['sequence_no'] ?? null, 'APPROVAL_STAGE_SEQUENCE_INVALID'),
            'name' => $this->requiredText($stage['name'] ?? null, 'APPROVAL_STAGE_NAME_INVALID'),
            'resolver_type' => $resolverType,
            'resolver_config' => $this->objectConfig($stage['resolver_config'] ?? null),
            'decision_mode' => $decisionMode,
            'quorum_count' => $quorum,
            'sla_minutes' => $this->nullableNonNegativeId($stage['sla_minutes'] ?? null),
            'on_timeout' => $this->requiredText($stage['on_timeout'] ?? null, 'APPROVAL_STAGE_TIMEOUT_RULE_INVALID'),
            'self_approval_rule' => $selfRule,
            'same_actor_rule' => $sameActorRule,
            'tie_rule' => $this->requiredText($stage['tie_rule'] ?? null, 'APPROVAL_STAGE_TIE_RULE_INVALID'),
            'rejection_rule' => $this->requiredText($stage['rejection_rule'] ?? null, 'APPROVAL_STAGE_REJECTION_RULE_INVALID'),
        ];
    }

    /**
     * @param array{stage_id:int,sequence_no:int,name:string,resolver_type:string,resolver_config:array<string,mixed>,decision_mode:string,quorum_count:?int,sla_minutes:?int,on_timeout:string,self_approval_rule:string,same_actor_rule:string,tie_rule:string,rejection_rule:string} $stage
     * @param array<string,mixed> $context
     * @return list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}>
     */
    private function resolveStageAssignees(
        array $stage,
        string $resourceType,
        int $staffUserId,
        array $context,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $resolvedAt
    ): array {
        return match ($stage['resolver_type']) {
            'direct_manager' => $this->managerAssignees('direct', $stage, $resourceType, $staffUserId, $context, $effectiveAt),
            'admin_manager' => $this->managerAssignees('administrative', $stage, $resourceType, $staffUserId, $context, $effectiveAt),
            'named_users' => $this->namedAssignees($stage['resolver_config']),
            'role_scope' => $this->roleAssignees($stage['resolver_config'], $resolvedAt),
            default => throw new DomainException('APPROVAL_STAGE_RESOLVER_INVALID'),
        };
    }

    /**
     * @param array{stage_id:int,sequence_no:int,name:string,resolver_type:string,resolver_config:array<string,mixed>,decision_mode:string,quorum_count:?int,sla_minutes:?int,on_timeout:string,self_approval_rule:string,same_actor_rule:string,tie_rule:string,rejection_rule:string} $stage
     * @param array<string,mixed> $context
     * @return list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}>
     */
    private function managerAssignees(
        string $managerKind,
        array $stage,
        string $resourceType,
        int $staffUserId,
        array $context,
        DateTimeImmutable $effectiveAt
    ): array {
        $hierarchy = $this->managers->resolve($staffUserId, $managerKind, $effectiveAt);
        $conflicts = $hierarchy['conflicts'] ?? null;
        if (!is_array($conflicts) || $conflicts !== []) {
            throw new DomainException('APPROVAL_MANAGER_HIERARCHY_CONFLICT');
        }

        $expectedAssignmentId = $this->nullablePositiveId($context['assignment_id'] ?? null);
        $resolvedAssignmentId = $this->nullablePositiveId($hierarchy['assignment_id'] ?? null);
        if ($expectedAssignmentId !== null && $resolvedAssignmentId !== $expectedAssignmentId) {
            throw new DomainException('APPROVAL_ASSIGNMENT_SNAPSHOT_MISMATCH');
        }

        $managerId = $this->nullablePositiveId($hierarchy['manager_id'] ?? null);
        if ($managerId === null) {
            $fallbackIds = $this->positiveIdList($stage['resolver_config']['fallback_user_ids'] ?? []);
            if ($fallbackIds === []) {
                throw new DomainException('APPROVER_NOT_CONFIGURED');
            }

            return $this->namedAssigneeRows($fallbackIds, 'manager_fallback', null, null, [
                'assignment_id' => $expectedAssignmentId,
                'manager_kind' => $managerKind,
                'fallback' => true,
            ]);
        }

        $assignmentContext = $context['assignment'] ?? [];
        if (!is_array($assignmentContext)) {
            throw new DomainException('APPROVAL_CONTEXT_INVALID');
        }
        $delegationResolution = $this->delegations->resolve(
            $managerId,
            $staffUserId,
            $this->nullablePositiveId($assignmentContext['org_unit_id'] ?? null),
            $this->positiveIdListOrEmpty($assignmentContext['group_ids'] ?? []),
            $resourceType,
            $this->nullablePositiveId($context['permission_type_id'] ?? null),
            $effectiveAt
        );
        $delegationConflicts = $delegationResolution['conflicts'] ?? null;
        if (!is_array($delegationConflicts) || $delegationConflicts !== []) {
            throw new DomainException('APPROVAL_DELEGATION_CONFLICT');
        }
        $delegation = $delegationResolution['delegation'] ?? null;
        if ($delegation !== null && !is_array($delegation)) {
            throw new DomainException('APPROVAL_DELEGATION_PAYLOAD_INVALID');
        }
        if (is_array($delegation)) {
            $delegateId = $this->positiveId(
                $delegation['delegate_user_id'] ?? null,
                'APPROVAL_DELEGATION_PAYLOAD_INVALID'
            );

            return [[
                'user_id' => $delegateId,
                'relationship_kind' => 'delegated_' . $managerKind . '_manager',
                'acting_for_user_id' => $managerId,
                'delegation_id' => $this->positiveId($delegation['delegation_id'] ?? null, 'APPROVAL_DELEGATION_PAYLOAD_INVALID'),
                'assignment_snapshot' => [
                    'assignment_id' => $resolvedAssignmentId,
                    'manager_kind' => $managerKind,
                    'manager_user_id' => $managerId,
                    'acting_for_user_id' => $managerId,
                    'delegation_id' => $this->positiveId($delegation['delegation_id'] ?? null, 'APPROVAL_DELEGATION_PAYLOAD_INVALID'),
                    'delegation_valid_from' => $this->requiredText($delegation['valid_from'] ?? null, 'APPROVAL_DELEGATION_PAYLOAD_INVALID'),
                    'delegation_valid_to' => $this->nullableText($delegation['valid_to'] ?? null),
                ],
            ]];
        }

        return [[
            'user_id' => $managerId,
            'relationship_kind' => $managerKind . '_manager',
            'acting_for_user_id' => null,
            'delegation_id' => null,
            'assignment_snapshot' => [
                'assignment_id' => $resolvedAssignmentId,
                'manager_kind' => $managerKind,
                'manager_user_id' => $managerId,
            ],
        ]];
    }

    /**
     * @param array<string,mixed> $config
     * @return list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}>
     */
    private function namedAssignees(array $config): array
    {
        $ids = $this->positiveIdList($config['user_ids'] ?? []);
        if ($ids === []) {
            throw new DomainException('APPROVAL_NAMED_USERS_EMPTY');
        }

        return $this->namedAssigneeRows($ids, 'named_user', null, null, ['configured' => true]);
    }

    /**
     * @param array<string,mixed> $config
     * @return list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}>
     */
    private function roleAssignees(array $config, DateTimeImmutable $resolvedAt): array
    {
        $roleKeys = $this->roleKeyList($config['role_keys'] ?? []);
        if ($roleKeys === []) {
            throw new DomainException('APPROVAL_ROLE_SCOPE_EMPTY');
        }
        $users = $this->roles->activeUsersForRoles($roleKeys, $resolvedAt);
        $assignees = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                throw new DomainException('APPROVAL_ROLE_SCOPE_PAYLOAD_INVALID');
            }
            $userId = $this->positiveId($user['user_id'] ?? null, 'APPROVAL_ROLE_SCOPE_PAYLOAD_INVALID');
            $matchedRoles = $this->roleKeyList($user['role_keys'] ?? []);
            if ($matchedRoles === []) {
                throw new DomainException('APPROVAL_ROLE_SCOPE_PAYLOAD_INVALID');
            }
            $assignees[] = [
                'user_id' => $userId,
                'relationship_kind' => 'role_scope',
                'acting_for_user_id' => null,
                'delegation_id' => null,
                'assignment_snapshot' => [
                    'role_keys' => $matchedRoles,
                    'configured_role_keys' => $roleKeys,
                    'resolved_at' => $this->databaseInstant($resolvedAt),
                ],
            ];
        }

        return $this->uniqueAssignees($assignees);
    }

    /**
     * @param list<int> $userIds
     * @param array<string,mixed> $snapshot
     * @return list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}>
     */
    private function namedAssigneeRows(
        array $userIds,
        string $relationshipKind,
        ?int $actingForUserId,
        ?int $delegationId,
        array $snapshot
    ): array {
        $rows = [];
        foreach ($userIds as $userId) {
            $rows[] = [
                'user_id' => $userId,
                'relationship_kind' => $relationshipKind,
                'acting_for_user_id' => $actingForUserId,
                'delegation_id' => $delegationId,
                'assignment_snapshot' => $snapshot,
            ];
        }

        return $this->uniqueAssignees($rows);
    }

    /**
     * @param list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}> $assignees
     * @return list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}>
     */
    private function applySelfApprovalRule(array $assignees, int $staffUserId, string $rule): array
    {
        if ($rule === 'allow_explicit') {
            return $assignees;
        }

        $filtered = array_values(array_filter(
            $assignees,
            static fn(array $assignee): bool => (int) $assignee['user_id'] !== $staffUserId
        ));
        if ($filtered === []) {
            throw new DomainException('SELF_APPROVAL_FORBIDDEN');
        }

        return $filtered;
    }

    /**
     * @param list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}> $assignees
     * @param array<int,true> $seenActorIds
     * @return array{0:list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}>,1:list<int>}
     */
    private function applySameActorRule(array $assignees, array $seenActorIds, string $rule): array
    {
        $duplicates = [];
        foreach ($assignees as $assignee) {
            $userId = (int) $assignee['user_id'];
            if (isset($seenActorIds[$userId])) {
                $duplicates[] = $userId;
            }
        }
        $duplicates = array_values(array_unique($duplicates));
        sort($duplicates, SORT_NUMERIC);
        if ($duplicates === []) {
            return [$assignees, []];
        }
        if ($rule === 'forbid') {
            throw new DomainException('DUPLICATE_STAGE_ACTOR');
        }
        if ($rule === 'merge') {
            $filtered = array_values(array_filter(
                $assignees,
                static fn(array $assignee): bool => !isset($seenActorIds[(int) $assignee['user_id']])
            ));

            return [$filtered, $duplicates];
        }

        $filtered = array_values(array_filter(
            $assignees,
            static fn(array $assignee): bool => !isset($seenActorIds[(int) $assignee['user_id']])
        ));
        if ($filtered === []) {
            throw new DomainException('DUPLICATE_STAGE_ACTOR');
        }

        return [$filtered, []];
    }

    /**
     * @param array{stage_id:int,sequence_no:int,name:string,resolver_type:string,resolver_config:array<string,mixed>,decision_mode:string,quorum_count:?int,sla_minutes:?int,on_timeout:string,self_approval_rule:string,same_actor_rule:string,tie_rule:string,rejection_rule:string} $stage
     * @param list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}> $assignees
     */
    private function assertDecisionModeHasValidAssignees(array $stage, array $assignees, array $mergedActorIds = []): void
    {
        $count = count($assignees);
        if ($count === 0) {
            if ($mergedActorIds !== []) {
                return;
            }
            throw new DomainException('APPROVER_NOT_CONFIGURED');
        }
        if ($stage['decision_mode'] === 'sequential' && $count !== 1) {
            throw new DomainException('APPROVAL_STAGE_ASSIGNEE_COUNT_INVALID');
        }
        if ($stage['decision_mode'] === 'quorum'
            && ($stage['quorum_count'] === null || $stage['quorum_count'] > $count)) {
            throw new DomainException('APPROVAL_STAGE_QUORUM_INVALID');
        }
    }

    /**
     * @param list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}> $assignees
     * @return list<array{user_id:int,relationship_kind:string,acting_for_user_id:?int,delegation_id:?int,assignment_snapshot:array<string,mixed>}>
     */
    private function uniqueAssignees(array $assignees): array
    {
        $unique = [];
        foreach ($assignees as $assignee) {
            $userId = (int) $assignee['user_id'];
            if (isset($unique[$userId])) {
                continue;
            }
            $unique[$userId] = $assignee;
        }
        ksort($unique, SORT_NUMERIC);

        return array_values($unique);
    }

    /** @return array<string,mixed> */
    private function objectConfig(mixed $config): array
    {
        if ($config === null || $config === '') {
            return [];
        }
        if (is_array($config)) {
            return $config;
        }
        if (!is_string($config)) {
            throw new DomainException('APPROVAL_STAGE_CONFIG_INVALID');
        }
        try {
            $decoded = json_decode($config, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('APPROVAL_STAGE_CONFIG_INVALID');
        }
        if (!is_array($decoded)) {
            throw new DomainException('APPROVAL_STAGE_CONFIG_INVALID');
        }

        return $decoded;
    }

    /** @return list<int> */
    private function positiveIdList(mixed $values): array
    {
        if (!is_array($values)) {
            throw new DomainException('APPROVAL_STAGE_CONFIG_INVALID');
        }
        $ids = [];
        foreach ($values as $value) {
            $id = $this->positiveId($value, 'APPROVAL_STAGE_CONFIG_INVALID');
            $ids[$id] = true;
        }
        $result = array_keys($ids);
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /** @return list<string> */
    private function roleKeyList(mixed $values): array
    {
        if (!is_array($values)) {
            throw new DomainException('APPROVAL_STAGE_CONFIG_INVALID');
        }
        $keys = [];
        foreach ($values as $value) {
            $key = trim((string) $value);
            if ($key === '') {
                throw new DomainException('APPROVAL_STAGE_CONFIG_INVALID');
            }
            $keys[$key] = true;
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string,mixed> */
    private function snapshotContext(array $context, int $staffUserId): array
    {
        $assignment = $context['assignment'] ?? [];
        if (!is_array($assignment)) {
            throw new DomainException('APPROVAL_CONTEXT_INVALID');
        }

        $snapshot = [
            'staff_user_id' => $staffUserId,
            'actor_id' => $this->nullablePositiveId($context['actor_id'] ?? null),
            'permission_type_id' => $this->nullablePositiveId($context['permission_type_id'] ?? null),
            'request_id' => $this->nullablePositiveId($context['request_id'] ?? null),
            'assignment_id' => $this->nullablePositiveId($context['assignment_id'] ?? null),
            'org_unit_id' => $this->nullablePositiveId($assignment['org_unit_id'] ?? null),
            'job_title_id' => $this->nullablePositiveId($assignment['job_title_id'] ?? null),
            'group_ids' => $this->positiveIdListOrEmpty($assignment['group_ids'] ?? []),
        ];

        if (array_key_exists('approved_schedule_snapshot', $context)) {
            $approvedSnapshot = $this->normalizedJsonValue(
                $context['approved_schedule_snapshot'],
                'APPROVAL_SCHEDULE_CHANGE_SNAPSHOT_INVALID'
            );
            if (!is_array($approvedSnapshot)) {
                throw new DomainException('APPROVAL_SCHEDULE_CHANGE_SNAPSHOT_INVALID');
            }
            $snapshot['approved_schedule_snapshot'] = $approvedSnapshot;
        }

        return $snapshot;
    }

    /** @return list<int> */
    private function positiveIdListOrEmpty(mixed $values): array
    {
        if ($values === null) {
            return [];
        }

        return $this->positiveIdList($values);
    }

    private function permissionEffectiveAt(array $request, array $policy): DateTimeImmutable
    {
        $fromAt = $request['from_at'] ?? null;
        if (!is_string($fromAt) || trim($fromAt) === '') {
            throw new DomainException('APPROVAL_EFFECTIVE_AT_INVALID');
        }
        try {
            $timezone = new DateTimeZone((string) ($policy['timezone'] ?? 'Africa/Cairo'));

            return new DateTimeImmutable($fromAt, $timezone);
        } catch (\Throwable) {
            throw new DomainException('APPROVAL_EFFECTIVE_AT_INVALID');
        }
    }

    private function positiveId(mixed $value, string $errorCode): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new DomainException($errorCode);
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new DomainException('APPROVAL_CONTEXT_INVALID');
        }

        return (int) $value;
    }

    private function nullableNonNegativeId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new DomainException('APPROVAL_STAGE_SLA_INVALID');
        }

        return (int) $value;
    }

    private function requiredText(mixed $value, string $errorCode): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new DomainException($errorCode);
        }

        return $text;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normalizedJsonValue(mixed $value, string $errorCode): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new DomainException($errorCode);
        }
        try {
            return json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException($errorCode);
        }
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
