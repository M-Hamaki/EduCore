<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Approval;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowAdministrationRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Owns administrative workflow versions and delegations. Published versions
 * are never edited in place: a new version is created, reviewed, then
 * published atomically with any safe closure of the previous open version.
 */
final class ApprovalWorkflowAdministrationService
{
    /** @var list<string> */
    private const RESOURCE_TYPES = [
        'permission_request',
        'leave_request',
        'discipline_case',
        'attendance_adjustment',
        'schedule_change',
        'ertaq_ticket',
    ];
    /** @var list<string> */
    private const WORKFLOW_STATUSES = ['active', 'inactive', 'retired'];
    /** @var list<string> */
    private const RESOLVER_TYPES = ['direct_manager', 'admin_manager', 'named_users', 'role_scope'];
    /** @var list<string> */
    private const DECISION_MODES = ['sequential', 'any_one', 'all', 'quorum'];
    /** @var list<string> */
    private const TIMEOUT_ACTIONS = ['fail_closed', 'escalate', 'reassign', 'expire'];
    /** @var list<string> */
    private const SELF_APPROVAL_RULES = ['forbid', 'require_alternate', 'allow_explicit'];
    /** @var list<string> */
    private const SAME_ACTOR_RULES = ['forbid', 'merge', 'require_alternate'];
    /** @var list<string> */
    private const REJECTION_RULES = ['stop_workflow', 'continue'];
    /** @var list<string> */
    private const CANCELLATION_RULES = ['request_cancellation', 'workflow_required', 'not_allowed'];
    /** @var list<string> */
    private const DELEGATION_SCOPES = ['global', 'org_unit', 'group', 'staff', 'request_type'];
    /** @var list<string> */
    private const DELEGATION_CREATE_STATUSES = ['draft', 'active'];
    /** @var list<string> */
    private const DELEGATION_END_STATUSES = ['suspended', 'revoked'];

    private DateTimeZone $clockZone;

    public function __construct(
        private ApprovalWorkflowAdministrationRepository $repository,
        private AuditEventWriter $audit,
        ?DateTimeZone $clockZone = null
    ) {
        $this->clockZone = $clockZone ?? new DateTimeZone('Africa/Cairo');
    }

    /** @return list<array<string,mixed>> */
    public function workflowVersions(): array
    {
        return $this->repository->workflowVersions();
    }

    /** @return list<array<string,mixed>> */
    public function delegations(): array
    {
        return $this->repository->delegations();
    }

    /** @return list<array<string,mixed>> */
    public function activeUsers(): array
    {
        return $this->repository->activeUsers();
    }

    /** @return list<string> */
    public function activeRoleKeys(): array
    {
        return $this->repository->activeRoleKeys();
    }

    /** @return array{workflow_id:int,version_id:int,published:bool} */
    public function createWorkflowVersion(array $input, int $actorId): array
    {
        $command = $this->normalizeWorkflowVersion($input, $actorId);

        return $this->repository->transactional(function () use ($command): array {
            $workflow = null;
            $workflowCreated = false;
            if ($command['workflow_id'] !== null) {
                $workflow = $this->repository->workflowForUpdate($command['workflow_id']);
                if ($workflow === null) {
                    throw new DomainException('APPROVAL_WORKFLOW_NOT_FOUND');
                }
                if ((string) ($workflow['status'] ?? '') === 'retired') {
                    throw new DomainException('APPROVAL_WORKFLOW_RETIRED');
                }
            } else {
                $workflowId = $this->repository->insertWorkflow([
                    'code' => $command['code'],
                    'name' => $command['name'],
                    'resource_type' => $command['resource_type'],
                    'status' => $command['workflow_status'],
                    'created_by' => $command['actor_id'],
                ]);
                if ($workflowId <= 0) {
                    throw new DomainException('APPROVAL_WORKFLOW_PERSIST_FAILED');
                }
                $workflow = [
                    'id' => $workflowId,
                    'code' => $command['code'],
                    'name' => $command['name'],
                    'resource_type' => $command['resource_type'],
                    'status' => $command['workflow_status'],
                ];
                $workflowCreated = true;
                $this->audit->recordEvent(
                    'staff_approval_workflow_created',
                    'staff_approval_workflows',
                    $workflowId,
                    $command['name'],
                    [
                        'workflow_code' => $command['code'],
                        'resource_type' => $command['resource_type'],
                        'status' => $command['workflow_status'],
                    ],
                    ['user_id' => $command['actor_id']]
                );
            }

            $workflowId = (int) ($workflow['id'] ?? 0);
            if ($workflowId <= 0) {
                throw new DomainException('APPROVAL_WORKFLOW_PERSIST_FAILED');
            }
            $versionId = $this->repository->insertVersion([
                'workflow_id' => $workflowId,
                'version_no' => $this->repository->nextVersionNumber($workflowId),
                'state' => 'draft',
                'valid_from' => $this->databaseInstant($command['valid_from']),
                'valid_to' => $command['valid_to'] === null ? null : $this->databaseInstant($command['valid_to']),
                'cancellation_rule' => $command['cancellation_rule'],
                'escalation_rule' => '{}',
                'supersedes_id' => null,
                'published_by' => null,
                'published_at' => null,
                'created_by' => $command['actor_id'],
            ]);
            if ($versionId <= 0) {
                throw new DomainException('APPROVAL_WORKFLOW_VERSION_PERSIST_FAILED');
            }
            foreach ($command['stages'] as $stage) {
                $stageId = $this->repository->insertStage([
                    'workflow_version_id' => $versionId,
                    'sequence_no' => $stage['sequence_no'],
                    'name' => $stage['name'],
                    'resolver_type' => $stage['resolver_type'],
                    'resolver_config' => $this->encodeJson($stage['resolver_config'], 'APPROVAL_STAGE_CONFIG_ENCODE_FAILED'),
                    'decision_mode' => $stage['decision_mode'],
                    'sla_minutes' => $stage['sla_minutes'],
                    'on_timeout' => $stage['on_timeout'],
                    'self_approval_rule' => $stage['self_approval_rule'],
                    'same_actor_rule' => $stage['same_actor_rule'],
                    'quorum_count' => $stage['quorum_count'],
                    'tie_rule' => $stage['tie_rule'],
                    'rejection_rule' => $stage['rejection_rule'],
                ]);
                if ($stageId <= 0) {
                    throw new DomainException('APPROVAL_STAGE_PERSIST_FAILED');
                }
            }
            $this->audit->recordEvent(
                'staff_approval_workflow_version_drafted',
                'staff_approval_workflow_versions',
                $versionId,
                (string) ($workflow['name'] ?? ''),
                [
                    'workflow_id' => $workflowId,
                    'workflow_created' => $workflowCreated,
                    'stage_count' => count($command['stages']),
                    'valid_from' => $this->databaseInstant($command['valid_from']),
                    'valid_to' => $command['valid_to'] === null ? null : $this->databaseInstant($command['valid_to']),
                ],
                ['user_id' => $command['actor_id']]
            );

            if ($command['publish_now'] === false) {
                return ['workflow_id' => $workflowId, 'version_id' => $versionId, 'published' => false];
            }
            $this->publishDraftInTransaction($versionId, $command['actor_id'], $command['published_at']);

            return ['workflow_id' => $workflowId, 'version_id' => $versionId, 'published' => true];
        });
    }

    public function publishVersion(int $versionId, int $actorId): void
    {
        $this->assertActor($actorId);
        if ($versionId <= 0) {
            throw new InvalidArgumentException('APPROVAL_WORKFLOW_VERSION_INVALID');
        }

        $this->repository->transactional(function () use ($versionId, $actorId): void {
            $this->publishDraftInTransaction($versionId, $actorId, new DateTimeImmutable('now', $this->clockZone));
        });
    }

    public function changeWorkflowStatus(int $workflowId, string $status, int $actorId): void
    {
        $this->assertActor($actorId);
        if ($workflowId <= 0 || !in_array($status, self::WORKFLOW_STATUSES, true)) {
            throw new InvalidArgumentException('APPROVAL_WORKFLOW_STATUS_INVALID');
        }

        $this->repository->transactional(function () use ($workflowId, $status, $actorId): void {
            $workflow = $this->repository->workflowForUpdate($workflowId);
            if ($workflow === null) {
                throw new DomainException('APPROVAL_WORKFLOW_NOT_FOUND');
            }
            $before = (string) ($workflow['status'] ?? '');
            if ($before === 'retired' && $status !== 'retired') {
                throw new DomainException('APPROVAL_WORKFLOW_RETIRED');
            }
            if ($before === $status) {
                return;
            }
            if (!$this->repository->setWorkflowStatus($workflowId, $status)) {
                throw new DomainException('APPROVAL_WORKFLOW_STATUS_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_approval_workflow_status_changed',
                'staff_approval_workflows',
                $workflowId,
                (string) ($workflow['name'] ?? ''),
                ['from_status' => $before, 'to_status' => $status],
                ['user_id' => $actorId]
            );
        });
    }

    public function createDelegation(array $input, int $actorId): int
    {
        $command = $this->normalizeDelegation($input, $actorId);

        return $this->repository->transactional(function () use ($command): int {
            if (!$this->repository->isActiveUser($command['delegator_user_id'])
                || !$this->repository->isActiveUser($command['delegate_user_id'])) {
                throw new DomainException('APPROVAL_DELEGATION_ACCOUNT_INACTIVE');
            }
            if ($command['status'] === 'active' && $this->repository->hasActiveDelegationScopeOverlap($command)) {
                throw new DomainException('APPROVAL_DELEGATION_SCOPE_CONFLICT');
            }
            $delegationId = $this->repository->insertDelegation($command);
            if ($delegationId <= 0) {
                throw new DomainException('APPROVAL_DELEGATION_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_approval_delegation_created',
                'staff_delegations',
                $delegationId,
                null,
                [
                    'delegator_user_id' => $command['delegator_user_id'],
                    'delegate_user_id' => $command['delegate_user_id'],
                    'scope_type' => $command['scope_type'],
                    'scope_id' => $command['scope_id'],
                    'request_type_count' => count($command['request_types_list']),
                    'valid_from' => $command['valid_from'],
                    'valid_to' => $command['valid_to'],
                    'status' => $command['status'],
                    'reason_hash' => hash('sha256', $command['reason']),
                ],
                ['user_id' => $command['created_by']]
            );

            return $delegationId;
        });
    }

    public function endDelegation(int $delegationId, string $status, int $actorId): void
    {
        $this->assertActor($actorId);
        if ($delegationId <= 0 || !in_array($status, self::DELEGATION_END_STATUSES, true)) {
            throw new InvalidArgumentException('APPROVAL_DELEGATION_STATUS_INVALID');
        }

        $this->repository->transactional(function () use ($delegationId, $status, $actorId): void {
            $delegation = $this->repository->delegationForUpdate($delegationId);
            if ($delegation === null) {
                throw new DomainException('APPROVAL_DELEGATION_NOT_FOUND');
            }
            $before = (string) ($delegation['status'] ?? '');
            if (in_array($before, ['revoked', 'expired'], true)) {
                throw new DomainException('APPROVAL_DELEGATION_TERMINAL');
            }
            if (!$this->repository->setDelegationStatus($delegationId, $status)) {
                throw new DomainException('APPROVAL_DELEGATION_STATUS_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_approval_delegation_status_changed',
                'staff_delegations',
                $delegationId,
                null,
                [
                    'from_status' => $before,
                    'to_status' => $status,
                    'delegator_user_id' => (int) ($delegation['delegator_user_id'] ?? 0),
                    'delegate_user_id' => (int) ($delegation['delegate_user_id'] ?? 0),
                ],
                ['user_id' => $actorId]
            );
        });
    }

    public function activateDelegation(int $delegationId, int $actorId): void
    {
        $this->assertActor($actorId);
        if ($delegationId <= 0) {
            throw new InvalidArgumentException('APPROVAL_DELEGATION_STATUS_INVALID');
        }

        $this->repository->transactional(function () use ($delegationId, $actorId): void {
            $delegation = $this->repository->delegationForUpdate($delegationId);
            if ($delegation === null) {
                throw new DomainException('APPROVAL_DELEGATION_NOT_FOUND');
            }
            $before = (string) ($delegation['status'] ?? '');
            if (!in_array($before, ['draft', 'suspended'], true)) {
                throw new DomainException('APPROVAL_DELEGATION_STATUS_INVALID');
            }
            if (!$this->repository->isActiveUser((int) ($delegation['delegator_user_id'] ?? 0))
                || !$this->repository->isActiveUser((int) ($delegation['delegate_user_id'] ?? 0))) {
                throw new DomainException('APPROVAL_DELEGATION_ACCOUNT_INACTIVE');
            }
            $validTo = $this->databaseDate($delegation['valid_to'] ?? null, 'APPROVAL_DELEGATION_VALIDITY_INVALID');
            if ($validTo <= new DateTimeImmutable('now', $this->clockZone)) {
                throw new DomainException('APPROVAL_DELEGATION_EXPIRED');
            }
            if ($this->repository->hasActiveDelegationScopeOverlap($delegation)) {
                throw new DomainException('APPROVAL_DELEGATION_SCOPE_CONFLICT');
            }
            if (!$this->repository->setDelegationStatus($delegationId, 'active')) {
                throw new DomainException('APPROVAL_DELEGATION_STATUS_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_approval_delegation_status_changed',
                'staff_delegations',
                $delegationId,
                null,
                [
                    'from_status' => $before,
                    'to_status' => 'active',
                    'delegator_user_id' => (int) ($delegation['delegator_user_id'] ?? 0),
                    'delegate_user_id' => (int) ($delegation['delegate_user_id'] ?? 0),
                ],
                ['user_id' => $actorId]
            );
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeWorkflowVersion(array $input, int $actorId): array
    {
        $this->assertActor($actorId);
        $workflowId = $this->nullablePositiveId($input['workflow_id'] ?? null, 'APPROVAL_WORKFLOW_INVALID');
        $validFrom = $this->dateTime($input['valid_from'] ?? null, 'APPROVAL_WORKFLOW_VALIDITY_INVALID');
        $validTo = $this->nullableDateTime($input['valid_to'] ?? null, 'APPROVAL_WORKFLOW_VALIDITY_INVALID');
        if ($validTo !== null && $validTo <= $validFrom) {
            throw new InvalidArgumentException('APPROVAL_WORKFLOW_VALIDITY_INVALID');
        }

        return [
            'workflow_id' => $workflowId,
            'code' => $workflowId === null ? $this->workflowCode($input['code'] ?? null) : null,
            'name' => $workflowId === null ? $this->requiredText($input['name'] ?? null, 'APPROVAL_WORKFLOW_NAME_INVALID', 200) : null,
            'resource_type' => $workflowId === null ? $this->resourceType($input['resource_type'] ?? null) : null,
            'workflow_status' => $workflowId === null ? $this->workflowStatus($input['workflow_status'] ?? 'active') : null,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'cancellation_rule' => $this->choice(
                $input['cancellation_rule'] ?? 'workflow_required',
                self::CANCELLATION_RULES,
                'APPROVAL_WORKFLOW_CANCELLATION_RULE_INVALID'
            ),
            'stages' => $this->normalizeStages($input),
            'publish_now' => $this->truthy($input['publish_now'] ?? false),
            'published_at' => new DateTimeImmutable('now', $this->clockZone),
            'actor_id' => $actorId,
        ];
    }

    /** @param array<string,mixed> $input @return list<array<string,mixed>> */
    private function normalizeStages(array $input): array
    {
        $names = $this->listInput($input['stage_name'] ?? []);
        $resolvers = $this->listInput($input['stage_resolver_type'] ?? []);
        $modes = $this->listInput($input['stage_decision_mode'] ?? []);
        $slas = $this->listInput($input['stage_sla_minutes'] ?? []);
        $timeouts = $this->listInput($input['stage_on_timeout'] ?? []);
        $selfRules = $this->listInput($input['stage_self_approval_rule'] ?? []);
        $sameActorRules = $this->listInput($input['stage_same_actor_rule'] ?? []);
        $quorums = $this->listInput($input['stage_quorum_count'] ?? []);
        $ties = $this->listInput($input['stage_tie_rule'] ?? []);
        $rejections = $this->listInput($input['stage_rejection_rule'] ?? []);
        $stageUserIds = is_array($input['stage_user_ids'] ?? null) ? $input['stage_user_ids'] : [];
        $stageRoleKeys = is_array($input['stage_role_keys'] ?? null) ? $input['stage_role_keys'] : [];
        if ($names === [] || count($names) !== count($resolvers) || count($names) !== count($modes)) {
            throw new InvalidArgumentException('APPROVAL_WORKFLOW_STAGES_INVALID');
        }

        $availableRoleKeys = in_array('role_scope', $resolvers, true)
            ? array_fill_keys($this->repository->activeRoleKeys(), true)
            : [];
        $stages = [];
        foreach ($names as $index => $name) {
            $resolverType = $this->choice($resolvers[$index] ?? null, self::RESOLVER_TYPES, 'APPROVAL_STAGE_RESOLVER_INVALID');
            $decisionMode = $this->choice($modes[$index] ?? null, self::DECISION_MODES, 'APPROVAL_STAGE_DECISION_MODE_INVALID');
            $userIds = $this->positiveIdList($stageUserIds[$index] ?? [], 'APPROVAL_STAGE_ASSIGNEE_INVALID');
            foreach ($userIds as $userId) {
                if (!$this->repository->isActiveUser($userId)) {
                    throw new DomainException('APPROVAL_STAGE_ASSIGNEE_INACTIVE');
                }
            }
            $roleKeys = $this->roleKeyList($stageRoleKeys[$index] ?? []);
            foreach ($roleKeys as $roleKey) {
                if (!isset($availableRoleKeys[$roleKey])) {
                    throw new DomainException('APPROVAL_ROLE_SCOPE_INVALID');
                }
            }
            if ($resolverType === 'named_users' && $userIds === []) {
                throw new InvalidArgumentException('APPROVAL_NAMED_USERS_EMPTY');
            }
            if ($resolverType === 'role_scope' && $roleKeys === []) {
                throw new InvalidArgumentException('APPROVAL_ROLE_SCOPE_EMPTY');
            }
            $resolverConfig = match ($resolverType) {
                'named_users' => ['user_ids' => $userIds],
                'role_scope' => ['role_keys' => $roleKeys],
                default => $userIds === [] ? [] : ['fallback_user_ids' => $userIds],
            };
            $quorum = $this->nullablePositiveId($quorums[$index] ?? null, 'APPROVAL_STAGE_QUORUM_INVALID');
            if ($decisionMode === 'quorum' && $quorum === null) {
                throw new InvalidArgumentException('APPROVAL_STAGE_QUORUM_INVALID');
            }
            if ($decisionMode !== 'quorum') {
                $quorum = null;
            }
            $stages[] = [
                'sequence_no' => $index + 1,
                'name' => $this->requiredText($name, 'APPROVAL_STAGE_NAME_INVALID', 200),
                'resolver_type' => $resolverType,
                'resolver_config' => $resolverConfig,
                'decision_mode' => $decisionMode,
                'sla_minutes' => $this->nullableNonNegativeInt($slas[$index] ?? null, 'APPROVAL_STAGE_SLA_INVALID'),
                'on_timeout' => $this->choice($timeouts[$index] ?? 'fail_closed', self::TIMEOUT_ACTIONS, 'APPROVAL_STAGE_TIMEOUT_RULE_INVALID'),
                'self_approval_rule' => $this->choice($selfRules[$index] ?? 'forbid', self::SELF_APPROVAL_RULES, 'APPROVAL_STAGE_SELF_RULE_INVALID'),
                'same_actor_rule' => $this->choice($sameActorRules[$index] ?? 'forbid', self::SAME_ACTOR_RULES, 'APPROVAL_STAGE_SAME_ACTOR_RULE_INVALID'),
                'quorum_count' => $quorum,
                'tie_rule' => $this->choice($ties[$index] ?? 'reject', ['approve', 'reject'], 'APPROVAL_STAGE_TIE_RULE_INVALID'),
                'rejection_rule' => $this->choice($rejections[$index] ?? 'stop_workflow', self::REJECTION_RULES, 'APPROVAL_STAGE_REJECTION_RULE_INVALID'),
            ];
        }

        return $stages;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeDelegation(array $input, int $actorId): array
    {
        $this->assertActor($actorId);
        $delegator = $this->positiveId($input['delegator_user_id'] ?? null, 'APPROVAL_DELEGATION_DELEGATOR_INVALID');
        $delegate = $this->positiveId($input['delegate_user_id'] ?? null, 'APPROVAL_DELEGATION_DELEGATE_INVALID');
        if ($delegator === $delegate) {
            throw new InvalidArgumentException('APPROVAL_DELEGATION_SELF_FORBIDDEN');
        }
        $scopeType = $this->choice($input['scope_type'] ?? null, self::DELEGATION_SCOPES, 'APPROVAL_DELEGATION_SCOPE_INVALID');
        $scopeId = $scopeType === 'global'
            ? 0
            : $this->positiveId($input['scope_id'] ?? null, 'APPROVAL_DELEGATION_SCOPE_INVALID');
        $validFrom = $this->dateTime($input['valid_from'] ?? null, 'APPROVAL_DELEGATION_VALIDITY_INVALID');
        $validTo = $this->dateTime($input['valid_to'] ?? null, 'APPROVAL_DELEGATION_VALIDITY_INVALID');
        if ($validTo <= $validFrom) {
            throw new InvalidArgumentException('APPROVAL_DELEGATION_VALIDITY_INVALID');
        }
        $requestTypes = $this->resourceTypeList($input['request_types'] ?? []);

        return [
            'delegator_user_id' => $delegator,
            'delegate_user_id' => $delegate,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'request_types' => $requestTypes === [] ? null : $this->encodeJson($requestTypes, 'APPROVAL_DELEGATION_REQUEST_TYPES_INVALID'),
            'request_types_list' => $requestTypes,
            'valid_from' => $this->databaseInstant($validFrom),
            'valid_to' => $this->databaseInstant($validTo),
            'reason' => $this->requiredText($input['reason'] ?? null, 'APPROVAL_DELEGATION_REASON_REQUIRED', 500),
            'status' => $this->choice($input['status'] ?? 'draft', self::DELEGATION_CREATE_STATUSES, 'APPROVAL_DELEGATION_STATUS_INVALID'),
            'created_by' => $actorId,
        ];
    }

    private function publishDraftInTransaction(int $versionId, int $actorId, DateTimeImmutable $publishedAt): void
    {
        $version = $this->repository->versionForUpdate($versionId);
        if ($version === null) {
            throw new DomainException('APPROVAL_WORKFLOW_VERSION_NOT_FOUND');
        }
        if ((string) ($version['state'] ?? '') !== 'draft') {
            throw new DomainException('APPROVAL_WORKFLOW_VERSION_NOT_DRAFT');
        }
        if ((string) ($version['workflow_status'] ?? '') !== 'active') {
            throw new DomainException('APPROVAL_WORKFLOW_INACTIVE');
        }
        if ($this->repository->stageCountForVersion($versionId) <= 0) {
            throw new DomainException('APPROVER_NOT_CONFIGURED');
        }
        $validFrom = $this->databaseDate($version['valid_from'] ?? null, 'APPROVAL_WORKFLOW_VALIDITY_INVALID');
        $validTo = $this->nullableDatabaseDate($version['valid_to'] ?? null, 'APPROVAL_WORKFLOW_VALIDITY_INVALID');
        $closedVersionIds = [];
        foreach ($this->repository->publishedVersionsForUpdate((int) $version['workflow_id']) as $existing) {
            $existingFrom = $this->databaseDate($existing['valid_from'] ?? null, 'APPROVAL_WORKFLOW_VALIDITY_INVALID');
            $existingTo = $this->nullableDatabaseDate($existing['valid_to'] ?? null, 'APPROVAL_WORKFLOW_VALIDITY_INVALID');
            $overlaps = $existingFrom < ($validTo ?? new DateTimeImmutable('9999-12-31 23:59:59', $this->clockZone))
                && ($existingTo === null || $existingTo > $validFrom);
            if (!$overlaps) {
                continue;
            }
            if ($existingTo === null && $validTo === null && $existingFrom < $validFrom) {
                if (!$this->repository->setVersionValidTo((int) $existing['id'], $this->databaseInstant($validFrom))) {
                    throw new DomainException('APPROVAL_WORKFLOW_VERSION_CLOSE_FAILED');
                }
                $closedVersionIds[] = (int) $existing['id'];
                continue;
            }
            throw new DomainException('APPROVAL_WORKFLOW_PUBLISH_CONFLICT');
        }
        if (!$this->repository->publishVersion($versionId, $actorId, $this->databaseInstant($publishedAt))) {
            throw new DomainException('APPROVAL_WORKFLOW_PUBLISH_FAILED');
        }
        $this->audit->recordEvent(
            'staff_approval_workflow_published',
            'staff_approval_workflow_versions',
            $versionId,
            (string) ($version['workflow_name'] ?? ''),
            [
                'workflow_id' => (int) $version['workflow_id'],
                'version_no' => (int) $version['version_no'],
                'valid_from' => $this->databaseInstant($validFrom),
                'valid_to' => $validTo === null ? null : $this->databaseInstant($validTo),
                'closed_predecessor_version_ids' => $closedVersionIds,
            ],
            ['user_id' => $actorId]
        );
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('APPROVAL_ACTOR_INVALID');
        }
    }

    private function workflowCode(mixed $value): string
    {
        $code = strtoupper(trim((string) $value));
        if (preg_match('/^[A-Z][A-Z0-9_]{2,79}$/', $code) !== 1) {
            throw new InvalidArgumentException('APPROVAL_WORKFLOW_CODE_INVALID');
        }

        return $code;
    }

    private function workflowStatus(mixed $value): string
    {
        $status = $this->choice($value, self::WORKFLOW_STATUSES, 'APPROVAL_WORKFLOW_STATUS_INVALID');
        if ($status === 'retired') {
            throw new InvalidArgumentException('APPROVAL_WORKFLOW_STATUS_INVALID');
        }

        return $status;
    }

    private function resourceType(mixed $value): string
    {
        return $this->choice($value, self::RESOURCE_TYPES, 'APPROVAL_WORKFLOW_RESOURCE_TYPE_INVALID');
    }

    /** @return list<string> */
    private function resourceTypeList(mixed $value): array
    {
        $types = [];
        foreach ($this->listInput($value) as $item) {
            $types[$this->resourceType($item)] = true;
        }

        return array_keys($types);
    }

    private function choice(mixed $value, array $allowed, string $errorCode): string
    {
        $choice = strtolower(trim((string) $value));
        if (!in_array($choice, $allowed, true)) {
            throw new InvalidArgumentException($errorCode);
        }

        return $choice;
    }

    /** @return list<mixed> */
    private function listInput(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return list<int> */
    private function positiveIdList(mixed $value, string $errorCode): array
    {
        $ids = [];
        foreach ($this->listInput($value) as $item) {
            $ids[$this->positiveId($item, $errorCode)] = true;
        }

        return array_keys($ids);
    }

    /** @return list<string> */
    private function roleKeyList(mixed $value): array
    {
        $keys = [];
        foreach ($this->listInput($value) as $item) {
            $key = trim((string) $item);
            if ($key === '' || mb_strlen($key) > 80 || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException('APPROVAL_ROLE_SCOPE_INVALID');
            }
            $keys[$key] = true;
        }

        return array_keys($keys);
    }

    private function positiveId(mixed $value, string $errorCode): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($errorCode);
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value, string $errorCode): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->positiveId($value, $errorCode);
    }

    private function nullableNonNegativeInt(mixed $value, string $errorCode): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new InvalidArgumentException($errorCode);
        }

        return (int) $value;
    }

    private function requiredText(mixed $value, string $errorCode, int $maximum): string
    {
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text) > $maximum) {
            throw new InvalidArgumentException($errorCode);
        }

        return $text;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    private function dateTime(mixed $value, string $errorCode): DateTimeImmutable
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($errorCode);
        }
        foreach (['!Y-m-d\\TH:i', '!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $text, $this->clockZone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException($errorCode);
    }

    private function nullableDateTime(mixed $value, string $errorCode): ?DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->dateTime($value, $errorCode);
    }

    private function databaseDate(mixed $value, string $errorCode): DateTimeImmutable
    {
        return $this->dateTime($value, $errorCode);
    }

    private function nullableDatabaseDate(mixed $value, string $errorCode): ?DateTimeImmutable
    {
        return $this->nullableDateTime($value, $errorCode);
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }

    private function encodeJson(array $value, string $errorCode): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new DomainException($errorCode);
        }
    }
}
