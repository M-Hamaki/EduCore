<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Approval;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovalTransitionAuthorization;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Owns the durable approval-instance state machine. It accepts only frozen
 * workflow snapshots, serializes transitions through repository locks, and
 * records the resource outcome and audit evidence in one transaction.
 */
final class ApprovalWorkflowService implements ApprovalWorkflowSubmissionGateway
{
    private const INSTANCE_PENDING = 'pending';
    private const INSTANCE_APPROVED = 'approved';
    private const INSTANCE_REJECTED = 'rejected';
    private const STEP_WAITING = 'waiting';
    private const STEP_ACTIVE = 'active';
    private const STEP_APPROVED = 'approved';
    private const STEP_REJECTED = 'rejected';
    private const STEP_SKIPPED = 'skipped';
    private const DECISIONS = ['approve', 'reject', 'abstain'];

    public function __construct(
        private ApprovalWorkflowRepository $repository,
        private ApprovalWorkflowOutcomeHandler $outcomes,
        private AuditEventWriter $audit,
        ?DateTimeZone $clockZone = null,
        private ?ApprovalNotificationService $notifications = null,
        private ?ApprovalTransitionAuthorization $authorization = null
    ) {
        $this->clockZone = $clockZone ?? new DateTimeZone('UTC');
    }

    private DateTimeZone $clockZone;

    /**
     * Persists an approval instance from a frozen resolver snapshot.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function submit(array $command): array
    {
        $command = $this->normalizeSubmission($command);
        $command['snapshot_json'] = $this->encodeJson($command['snapshot'], 'APPROVAL_SNAPSHOT_ENCODE_FAILED');

        return $this->repository->transactional(function () use ($command): array {
            $existing = $this->repository->instanceByIdempotencyForUpdate($command['idempotency_key']);
            if ($existing !== null) {
                if ((string) ($existing['resource_type'] ?? '') !== $command['resource_type']
                    || (int) ($existing['resource_id'] ?? 0) !== $command['resource_id']
                    || (int) ($existing['workflow_version_id'] ?? 0) !== $command['workflow_version_id']
                    || !hash_equals((string) ($existing['snapshot_json'] ?? ''), $command['snapshot_json'])) {
                    throw new DomainException('APPROVAL_SUBMISSION_IDEMPOTENCY_CONFLICT');
                }

                return $this->instanceReceipt($existing, true);
            }

            $stages = $this->snapshotStages($command['snapshot']);
            $firstSequence = (int) $stages[0]['sequence_no'];
            $instanceId = $this->repository->insertInstance([
                'resource_type' => $command['resource_type'],
                'resource_id' => $command['resource_id'],
                'workflow_version_id' => $command['workflow_version_id'],
                'status' => self::INSTANCE_PENDING,
                'current_sequence' => $firstSequence,
                'started_at' => $this->databaseInstant($command['submitted_at']),
                'completed_at' => null,
                'snapshot_json' => $command['snapshot_json'],
                'lock_version' => 1,
                'idempotency_key' => $command['idempotency_key'],
            ]);
            if ($instanceId <= 0) {
                throw new DomainException('APPROVAL_INSTANCE_PERSIST_FAILED');
            }

            $activeStepId = null;
            foreach ($stages as $stage) {
                $isFirst = (int) $stage['sequence_no'] === $firstSequence;
                $dueAt = $isFirst ? $this->dueAt($stage, $command['submitted_at']) : null;
                $stepId = $this->repository->insertStep([
                    'instance_id' => $instanceId,
                    'stage_id' => (int) $stage['stage_id'],
                    'sequence_no' => (int) $stage['sequence_no'],
                    'status' => $isFirst ? self::STEP_ACTIVE : self::STEP_WAITING,
                    'due_at' => $dueAt === null ? null : $this->databaseInstant($dueAt),
                    'activated_at' => $isFirst ? $this->databaseInstant($command['submitted_at']) : null,
                    'completed_at' => null,
                    'snapshot_json' => $this->encodeJson($stage, 'APPROVAL_STAGE_SNAPSHOT_ENCODE_FAILED'),
                    'lock_version' => 1,
                ]);
                if ($stepId <= 0) {
                    throw new DomainException('APPROVAL_STEP_PERSIST_FAILED');
                }
                if ($isFirst) {
                    $activeStepId = $stepId;
                }

                foreach ($this->stageAssignees($stage, true) as $assignee) {
                    $assigneeSnapshot = $assignee['assignment_snapshot'];
                    $assigneeSnapshot['acting_for_user_id'] = $this->nullablePositiveId($assignee['acting_for_user_id'] ?? null);
                    $assigneeSnapshot['delegation_id'] = $this->nullablePositiveId($assignee['delegation_id'] ?? null);
                    $assigneeId = $this->repository->insertAssignee([
                        'step_id' => $stepId,
                        'assignee_user_id' => (int) $assignee['user_id'],
                        'relationship_kind' => (string) $assignee['relationship_kind'],
                        'assignment_snapshot' => $this->encodeJson(
                            $assigneeSnapshot,
                            'APPROVAL_ASSIGNEE_SNAPSHOT_ENCODE_FAILED'
                        ),
                        'status' => 'eligible',
                    ]);
                    if ($assigneeId <= 0) {
                        throw new DomainException('APPROVAL_ASSIGNEE_PERSIST_FAILED');
                    }
                }
            }
            if ($activeStepId === null) {
                throw new DomainException('APPROVAL_WORKFLOW_STAGES_INVALID');
            }

            $this->audit->recordEvent(
                'staff_approval_submitted',
                'staff_approval_instances',
                $instanceId,
                $command['resource_type'],
                [
                    'resource_type' => $command['resource_type'],
                    'resource_id' => $command['resource_id'],
                    'workflow_version_id' => $command['workflow_version_id'],
                    'stage_count' => count($stages),
                    'active_step_id' => $activeStepId,
                    'snapshot_hash' => hash('sha256', $command['snapshot_json']),
                    'idempotency_hash' => hash('sha256', $command['idempotency_key']),
                ],
                ['user_id' => $command['actor_id']]
            );
            $this->notifyAssignees(
                [
                    'id' => $instanceId,
                    'resource_type' => $command['resource_type'],
                    'resource_id' => $command['resource_id'],
                    'workflow_version_id' => $command['workflow_version_id'],
                ],
                $activeStepId,
                $this->stageAssignees($stages[0], true)
            );

            return [
                'instance_id' => $instanceId,
                'status' => self::INSTANCE_PENDING,
                'current_sequence' => $firstSequence,
                'active_step_id' => $activeStepId,
                'replayed' => false,
            ];
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function decide(array $command): array
    {
        $command = $this->normalizeDecision($command);

        return $this->repository->transactional(function () use ($command): array {
            $idempotent = $this->repository->decisionByIdempotencyForUpdate($command['idempotency_key']);
            if ($idempotent !== null) {
                if ((int) ($idempotent['step_id'] ?? 0) !== $command['step_id']
                    || (int) ($idempotent['actor_user_id'] ?? 0) !== $command['actor_id']
                    || (string) ($idempotent['decision'] ?? '') !== $command['decision']
                    || ($idempotent['comment'] ?? null) !== $command['comment']) {
                    throw new DomainException('APPROVAL_DECISION_IDEMPOTENCY_CONFLICT');
                }
                $step = $this->requiredStep($command['step_id']);

                return $this->decisionReceipt($idempotent, $step, true);
            }

            $step = $this->requiredStep($command['step_id']);
            $this->assertDecidableStep($step, $command['expected_lock_version']);
            $stage = $this->decodeStage($step['step_snapshot_json'] ?? null);
            $assignees = $this->repository->assigneesForStepForUpdate($command['step_id']);
            $assignee = $this->eligibleAssignee($assignees, $command['actor_id']);
            if ($assignee === null) {
                $prior = $this->repository->decisionForActorForUpdate($command['step_id'], $command['actor_id']);
                if ($prior !== null) {
                    throw new DomainException('ALREADY_DECIDED');
                }
                throw new DomainException('NOT_ASSIGNED_APPROVER');
            }
            $this->assertTransitionAuthorized(
                $command['actor_id'],
                'decide',
                $step,
                $assignee,
                $command['decided_at']
            );
            $this->assertSelfDecisionAllowed($step, $stage, $command['actor_id']);
            if ($this->repository->decisionForActorForUpdate($command['step_id'], $command['actor_id']) !== null) {
                throw new DomainException('ALREADY_DECIDED');
            }

            $decisionId = $this->repository->insertDecision([
                'step_id' => $command['step_id'],
                'actor_user_id' => $command['actor_id'],
                'acting_for_user_id' => $this->actingForUserId($assignee),
                'decision' => $command['decision'],
                'comment' => $command['comment'],
                'decided_at' => $this->databaseInstant($command['decided_at']),
                'idempotency_key' => $command['idempotency_key'],
                'is_effective' => 1,
            ]);
            if ($decisionId <= 0) {
                throw new DomainException('APPROVAL_DECISION_PERSIST_FAILED');
            }
            if (!$this->repository->updateAssigneeStatus((int) $assignee['assignee_id'], 'decided')) {
                throw new DomainException('APPROVAL_ASSIGNEE_STALE');
            }

            $decisions = $this->repository->decisionsForStepForUpdate($command['step_id']);
            $stageOutcome = $this->stageOutcome($stage, $assignees, $decisions);
            $completedAt = $stageOutcome === 'pending' ? null : $this->databaseInstant($command['decided_at']);
            if (!$this->repository->updateStep(
                $command['step_id'],
                $command['expected_lock_version'],
                [
                    'status' => $this->stepStatusForOutcome($stageOutcome),
                    'due_at' => $step['due_at'] ?? null,
                    'activated_at' => $step['activated_at'] ?? null,
                    'completed_at' => $completedAt,
                ]
            )) {
                throw new DomainException('STALE_APPROVAL_STEP');
            }

            $instanceTransition = $this->applyStageOutcome(
                $step,
                $stageOutcome,
                $command['actor_id'],
                $command['decided_at']
            );

            $this->audit->recordEvent(
                'staff_approval_decided',
                'staff_approval_decisions',
                $decisionId,
                $command['decision'],
                [
                    'instance_id' => (int) $step['instance_id'],
                    'step_id' => $command['step_id'],
                    'actor_user_id' => $command['actor_id'],
                    'acting_for_user_id' => $this->actingForUserId($assignee),
                    'decision' => $command['decision'],
                    'step_outcome' => $stageOutcome,
                    'instance_status' => $instanceTransition['status'],
                    'idempotency_hash' => hash('sha256', $command['idempotency_key']),
                ],
                ['user_id' => $command['actor_id']]
            );
            $this->notifyFollowingStage($step, $instanceTransition);

            return [
                'decision_id' => $decisionId,
                'instance_id' => (int) $step['instance_id'],
                'step_id' => $command['step_id'],
                'step_status' => $this->stepStatusForOutcome($stageOutcome),
                'instance_status' => $instanceTransition['status'],
                'current_sequence' => $instanceTransition['current_sequence'],
                'replayed' => false,
            ];
        });
    }

    /**
     * Replace one current assignee with a named assignee and preserve a
     * dedicated escalation event. The transition authorization contract
     * revalidates a live session and source authority before any mutation.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function reassign(array $command): array
    {
        $command = $this->normalizeReassignment($command, 'reassigned');

        return $this->repository->transactional(function () use ($command): array {
            $step = $this->requiredStep($command['step_id']);
            $this->assertDecidableStep($step, $command['expected_lock_version']);
            $assignees = $this->repository->assigneesForStepForUpdate($command['step_id']);
            $from = $this->eligibleAssignee($assignees, $command['from_user_id']);
            $this->assertTransitionAuthorized(
                $command['actor_id'],
                'reassign',
                $step,
                $from,
                $command['occurred_at']
            );
            if ($from === null) {
                throw new DomainException('APPROVAL_REASSIGN_SOURCE_INVALID');
            }
            if ($this->assigneeExists($assignees, $command['to_user_id'])) {
                throw new DomainException('APPROVAL_REASSIGN_TARGET_ALREADY_ASSIGNED');
            }
            $this->assertAssignmentTargetAllowed($command['to_user_id'], $step, $command['occurred_at']);
            if (!$this->repository->updateAssigneeStatus((int) $from['assignee_id'], 'reassigned')) {
                throw new DomainException('APPROVAL_ASSIGNEE_STALE');
            }
            $newAssigneeId = $this->repository->insertAssignee([
                'step_id' => $command['step_id'],
                'assignee_user_id' => $command['to_user_id'],
                'relationship_kind' => 'reassigned',
                'assignment_snapshot' => $this->encodeJson([
                    'reassigned_from_user_id' => $command['from_user_id'],
                    'reassigned_by_user_id' => $command['actor_id'],
                    'reason' => $command['reason'],
                ], 'APPROVAL_REASSIGN_SNAPSHOT_ENCODE_FAILED'),
                'status' => 'eligible',
            ]);
            if ($newAssigneeId <= 0) {
                throw new DomainException('APPROVAL_ASSIGNEE_PERSIST_FAILED');
            }
            if (!$this->repository->updateStep(
                $command['step_id'],
                $command['expected_lock_version'],
                [
                    'status' => self::STEP_ACTIVE,
                    'due_at' => $step['due_at'] ?? null,
                    'activated_at' => $step['activated_at'] ?? null,
                    'completed_at' => null,
                ]
            )) {
                throw new DomainException('STALE_APPROVAL_STEP');
            }
            $eventId = $this->recordEscalationEvent($command, 'reassigned');
            $this->audit->recordEvent(
                'staff_approval_reassigned',
                'staff_approval_assignees',
                $newAssigneeId,
                null,
                [
                    'instance_id' => (int) $step['instance_id'],
                    'step_id' => $command['step_id'],
                    'from_user_id' => $command['from_user_id'],
                    'to_user_id' => $command['to_user_id'],
                    'escalation_event_id' => $eventId,
                ],
                ['user_id' => $command['actor_id']]
            );
            $this->notifyAssignees(
                $this->instancePayload($step),
                $command['step_id'],
                [['assignee_user_id' => $command['to_user_id']]],
                'reassigned',
                $newAssigneeId
            );

            return [
                'instance_id' => (int) $step['instance_id'],
                'step_id' => $command['step_id'],
                'assignee_id' => $newAssigneeId,
                'lock_version' => $command['expected_lock_version'] + 1,
            ];
        });
    }

    /**
     * Timeout escalation replaces remaining eligible assignees with one
     * explicit target so a sequential stage cannot silently become a quorum.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function escalate(array $command): array
    {
        $command = $this->normalizeReassignment($command, 'escalated');

        return $this->repository->transactional(function () use ($command): array {
            $step = $this->requiredStep($command['step_id']);
            $this->assertDecidableStep($step, $command['expected_lock_version']);
            $this->assertTransitionAuthorized(
                $command['actor_id'],
                'escalate',
                $step,
                null,
                $command['occurred_at']
            );
            $stage = $this->decodeStage($step['step_snapshot_json'] ?? null);
            if ((string) ($stage['on_timeout'] ?? '') !== 'escalate') {
                throw new DomainException('APPROVAL_ESCALATION_NOT_CONFIGURED');
            }
            $dueAt = $this->nullableDate($step['due_at'] ?? null, 'APPROVAL_STEP_DUE_INVALID');
            if ($dueAt === null || $command['occurred_at'] < $dueAt) {
                throw new DomainException('APPROVAL_ESCALATION_NOT_DUE');
            }
            $assignees = $this->repository->assigneesForStepForUpdate($command['step_id']);
            if ($this->assigneeExists($assignees, $command['to_user_id'])) {
                throw new DomainException('APPROVAL_REASSIGN_TARGET_ALREADY_ASSIGNED');
            }
            $this->assertAssignmentTargetAllowed($command['to_user_id'], $step, $command['occurred_at']);
            $eligibleAssignees = array_values(array_filter(
                $assignees,
                static fn(array $assignee): bool => (string) ($assignee['status'] ?? '') === 'eligible'
            ));
            if ($eligibleAssignees === []) {
                throw new DomainException('APPROVAL_ESCALATION_SOURCE_INVALID');
            }
            $fromUserId = null;
            foreach ($eligibleAssignees as $assignee) {
                $fromUserId ??= (int) $assignee['assignee_user_id'];
                if (!$this->repository->updateAssigneeStatus((int) $assignee['assignee_id'], 'reassigned')) {
                    throw new DomainException('APPROVAL_ASSIGNEE_STALE');
                }
            }
            $newDueAt = $this->dueAt($stage, $command['occurred_at']);
            $newAssigneeId = $this->repository->insertAssignee([
                'step_id' => $command['step_id'],
                'assignee_user_id' => $command['to_user_id'],
                'relationship_kind' => 'escalated',
                'assignment_snapshot' => $this->encodeJson([
                    'escalated_from_user_id' => $fromUserId,
                    'escalated_by_user_id' => $command['actor_id'],
                    'reason' => $command['reason'],
                ], 'APPROVAL_ESCALATION_SNAPSHOT_ENCODE_FAILED'),
                'status' => 'eligible',
            ]);
            if ($newAssigneeId <= 0) {
                throw new DomainException('APPROVAL_ASSIGNEE_PERSIST_FAILED');
            }
            if (!$this->repository->updateStep(
                $command['step_id'],
                $command['expected_lock_version'],
                [
                    'status' => self::STEP_ACTIVE,
                    'due_at' => $newDueAt === null ? null : $this->databaseInstant($newDueAt),
                    'activated_at' => $step['activated_at'] ?? null,
                    'completed_at' => null,
                ]
            )) {
                throw new DomainException('STALE_APPROVAL_STEP');
            }
            $eventId = $this->recordEscalationEvent($command, 'escalated', $fromUserId);
            $this->audit->recordEvent(
                'staff_approval_escalated',
                'staff_approval_escalation_events',
                $eventId,
                null,
                [
                    'instance_id' => (int) $step['instance_id'],
                    'step_id' => $command['step_id'],
                    'from_user_id' => $fromUserId,
                    'to_user_id' => $command['to_user_id'],
                ],
                ['user_id' => $command['actor_id']]
            );
            $this->notifyAssignees(
                $this->instancePayload($step),
                $command['step_id'],
                [['assignee_user_id' => $command['to_user_id']]],
                'escalated',
                $newAssigneeId
            );

            return [
                'instance_id' => (int) $step['instance_id'],
                'step_id' => $command['step_id'],
                'assignee_id' => $newAssigneeId,
                'lock_version' => $command['expected_lock_version'] + 1,
            ];
        });
    }

    /** @param array<string,mixed> $step @return array{status:string,current_sequence:int} */
    private function applyStageOutcome(array $step, string $stageOutcome, int $actorId, DateTimeImmutable $occurredAt): array
    {
        $instanceId = (int) $step['instance_id'];
        $instanceLockVersion = (int) $step['instance_lock_version'];
        $currentSequence = (int) $step['current_sequence'];
        if ($stageOutcome === 'pending') {
            return ['status' => (string) $step['instance_status'], 'current_sequence' => $currentSequence];
        }

        $steps = $this->repository->stepsForInstanceForUpdate($instanceId);
        if ($stageOutcome === 'rejected') {
            foreach ($steps as $otherStep) {
                if ((int) $otherStep['step_id'] === (int) $step['step_id']
                    || !in_array((string) $otherStep['status'], [self::STEP_WAITING, self::STEP_ACTIVE], true)) {
                    continue;
                }
                if (!$this->repository->updateStep(
                    (int) $otherStep['step_id'],
                    (int) $otherStep['lock_version'],
                    [
                        'status' => self::STEP_SKIPPED,
                        'due_at' => $otherStep['due_at'] ?? null,
                        'activated_at' => $otherStep['activated_at'] ?? null,
                        'completed_at' => $this->databaseInstant($occurredAt),
                    ]
                )) {
                    throw new DomainException('STALE_APPROVAL_STEP');
                }
            }
            if (!$this->repository->updateInstance($instanceId, $instanceLockVersion, [
                'status' => self::INSTANCE_REJECTED,
                'current_sequence' => (int) $step['sequence_no'],
                'completed_at' => $this->databaseInstant($occurredAt),
            ])) {
                throw new DomainException('STALE_APPROVAL_INSTANCE');
            }
            $this->outcomes->apply($this->instancePayload($step), self::INSTANCE_REJECTED, $actorId, $occurredAt);

            return ['status' => self::INSTANCE_REJECTED, 'current_sequence' => (int) $step['sequence_no']];
        }

        return $this->activateFollowingStep($step, $steps, $actorId, $occurredAt);
    }

    /** @param array<string,mixed> $currentStep @param list<array<string,mixed>> $steps @return array{status:string,current_sequence:int} */
    private function activateFollowingStep(array $currentStep, array $steps, int $actorId, DateTimeImmutable $occurredAt): array
    {
        $instanceId = (int) $currentStep['instance_id'];
        $instanceLockVersion = (int) $currentStep['instance_lock_version'];
        $nextSteps = array_values(array_filter(
            $steps,
            static fn(array $candidate): bool => (int) $candidate['sequence_no'] > (int) $currentStep['sequence_no']
                && (string) $candidate['status'] === self::STEP_WAITING
        ));
        usort($nextSteps, static fn(array $left, array $right): int => (int) $left['sequence_no'] <=> (int) $right['sequence_no']);

        foreach ($nextSteps as $nextStep) {
            $nextStage = $this->decodeStage($nextStep['snapshot_json'] ?? null);
            $nextAssignees = $this->repository->assigneesForStepForUpdate((int) $nextStep['step_id']);
            $mergedActors = array_values(array_map('intval', (array) ($nextStage['merged_actor_ids'] ?? [])));
            if ($nextAssignees === [] && $mergedActors !== []) {
                if (!$this->repository->updateStep(
                    (int) $nextStep['step_id'],
                    (int) $nextStep['lock_version'],
                    [
                        'status' => self::STEP_SKIPPED,
                        'due_at' => $nextStep['due_at'] ?? null,
                        'activated_at' => $this->databaseInstant($occurredAt),
                        'completed_at' => $this->databaseInstant($occurredAt),
                    ]
                )) {
                    throw new DomainException('STALE_APPROVAL_STEP');
                }
                $currentStep['sequence_no'] = $nextStep['sequence_no'];
                continue;
            }
            if ($nextAssignees === []) {
                throw new DomainException('APPROVER_NOT_CONFIGURED');
            }
            $dueAt = $this->dueAt($nextStage, $occurredAt);
            if (!$this->repository->updateStep(
                (int) $nextStep['step_id'],
                (int) $nextStep['lock_version'],
                [
                    'status' => self::STEP_ACTIVE,
                    'due_at' => $dueAt === null ? null : $this->databaseInstant($dueAt),
                    'activated_at' => $this->databaseInstant($occurredAt),
                    'completed_at' => null,
                ]
            )) {
                throw new DomainException('STALE_APPROVAL_STEP');
            }
            if (!$this->repository->updateInstance($instanceId, $instanceLockVersion, [
                'status' => self::INSTANCE_PENDING,
                'current_sequence' => (int) $nextStep['sequence_no'],
                'completed_at' => null,
            ])) {
                throw new DomainException('STALE_APPROVAL_INSTANCE');
            }

            return ['status' => self::INSTANCE_PENDING, 'current_sequence' => (int) $nextStep['sequence_no']];
        }

        if (!$this->repository->updateInstance($instanceId, $instanceLockVersion, [
            'status' => self::INSTANCE_APPROVED,
            'current_sequence' => (int) $currentStep['sequence_no'],
            'completed_at' => $this->databaseInstant($occurredAt),
        ])) {
            throw new DomainException('STALE_APPROVAL_INSTANCE');
        }
        $this->outcomes->apply($this->instancePayload($currentStep), self::INSTANCE_APPROVED, $actorId, $occurredAt);

        return ['status' => self::INSTANCE_APPROVED, 'current_sequence' => (int) $currentStep['sequence_no']];
    }

    /** @param array<string,mixed> $stage @param list<array<string,mixed>> $assignees @param list<array<string,mixed>> $decisions */
    private function stageOutcome(array $stage, array $assignees, array $decisions): string
    {
        $eligibleActorIds = [];
        foreach ($assignees as $assignee) {
            if (in_array((string) ($assignee['status'] ?? ''), ['eligible', 'decided'], true)) {
                $eligibleActorIds[(int) $assignee['assignee_user_id']] = true;
            }
        }
        $decisionByActor = [];
        foreach ($decisions as $decision) {
            if ((int) ($decision['is_effective'] ?? 0) !== 1) {
                continue;
            }
            $actorId = (int) ($decision['actor_user_id'] ?? 0);
            if (isset($eligibleActorIds[$actorId])) {
                $decisionByActor[$actorId] = (string) $decision['decision'];
            }
        }
        $approved = count(array_filter($decisionByActor, static fn(string $decision): bool => $decision === 'approve'));
        $rejected = count(array_filter($decisionByActor, static fn(string $decision): bool => $decision === 'reject'));
        $decided = count($decisionByActor);
        $total = count($eligibleActorIds);
        $remaining = $total - $decided;
        $mode = (string) ($stage['decision_mode'] ?? '');
        $stopOnReject = (string) ($stage['rejection_rule'] ?? 'stop_workflow') === 'stop_workflow';

        if ($mode === 'sequential' || $mode === 'any_one') {
            if ($approved > 0) {
                return 'approved';
            }
            if ($stopOnReject && $rejected > 0) {
                return 'rejected';
            }

            return $remaining === 0 ? 'rejected' : 'pending';
        }
        if ($mode === 'all') {
            if ($stopOnReject && $rejected > 0) {
                return 'rejected';
            }
            if ($remaining === 0) {
                $tieOutcome = $this->tieOutcome($stage, $approved, $rejected);
                if ($tieOutcome !== null) {
                    return $tieOutcome;
                }
                return $approved === $total ? 'approved' : 'rejected';
            }

            return 'pending';
        }
        if ($mode === 'quorum') {
            $quorum = (int) ($stage['quorum_count'] ?? 0);
            if ($quorum <= 0) {
                throw new DomainException('APPROVAL_STAGE_QUORUM_INVALID');
            }
            if ($approved >= $quorum) {
                return 'approved';
            }
            if ($stopOnReject && $rejected > 0) {
                return 'rejected';
            }
            if ($remaining === 0) {
                $tieOutcome = $this->tieOutcome($stage, $approved, $rejected);
                if ($tieOutcome !== null) {
                    return $tieOutcome;
                }
            }

            return $approved + $remaining < $quorum || $remaining === 0 ? 'rejected' : 'pending';
        }

        throw new DomainException('APPROVAL_STAGE_DECISION_MODE_INVALID');
    }

    /** @param array<string,mixed> $stage */
    private function tieOutcome(array $stage, int $approved, int $rejected): ?string
    {
        if ($approved === 0 || $approved !== $rejected) {
            return null;
        }

        return match (strtolower((string) ($stage['tie_rule'] ?? 'reject'))) {
            'approve' => 'approved',
            'reject' => 'rejected',
            default => throw new DomainException('APPROVAL_STAGE_TIE_RULE_INVALID'),
        };
    }

    private function stepStatusForOutcome(string $outcome): string
    {
        return match ($outcome) {
            'pending' => self::STEP_ACTIVE,
            'approved' => self::STEP_APPROVED,
            'rejected' => self::STEP_REJECTED,
            default => throw new DomainException('APPROVAL_STAGE_OUTCOME_INVALID'),
        };
    }

    /** @param array<string,mixed> $instance @param list<array<string,mixed>> $assignees */
    private function notifyAssignees(
        array $instance,
        int $stepId,
        array $assignees,
        string $eventType = 'assigned',
        ?int $eventIdentity = null
    ): void {
        if ($this->notifications === null) {
            return;
        }
        $this->notifications->notifyAssignees($instance, $stepId, $assignees, $eventType, $eventIdentity);
    }

    /** @param array<string,mixed> $step @param array{status:string,current_sequence:int} $transition */
    private function notifyFollowingStage(array $step, array $transition): void
    {
        if ($this->notifications === null
            || $transition['status'] !== self::INSTANCE_PENDING
            || $transition['current_sequence'] === (int) ($step['current_sequence'] ?? 0)) {
            return;
        }
        $activeSteps = array_values(array_filter(
            $this->repository->stepsForInstanceForUpdate((int) $step['instance_id']),
            static fn(array $candidate): bool => (string) ($candidate['status'] ?? '') === self::STEP_ACTIVE
                && (int) ($candidate['sequence_no'] ?? 0) === $transition['current_sequence']
        ));
        if (count($activeSteps) !== 1) {
            throw new DomainException('APPROVAL_ACTIVE_STEP_CONFLICT');
        }
        $activeStep = $activeSteps[0];
        $this->notifyAssignees(
            $this->instancePayload($step),
            (int) $activeStep['step_id'],
            $this->repository->assigneesForStepForUpdate((int) $activeStep['step_id'])
        );
    }

    /** @param array<string,mixed> $step @param array<string,mixed>|null $assignee */
    private function assertTransitionAuthorized(
        int $actorId,
        string $operation,
        array $step,
        ?array $assignee,
        DateTimeImmutable $atInstant
    ): void {
        if ($this->authorization === null) {
            return;
        }
        $this->authorization->assertCanAct(
            $actorId,
            $operation,
            $this->instancePayload($step),
            $step,
            $assignee,
            $atInstant
        );
    }

    /** @param array<string,mixed> $step */
    private function assertAssignmentTargetAllowed(int $userId, array $step, DateTimeImmutable $atInstant): void
    {
        if ($this->authorization === null) {
            return;
        }
        $this->authorization->assertCanReceiveAssignment(
            $userId,
            $this->instancePayload($step),
            $step,
            $atInstant
        );
    }

    /** @param array<string,mixed> $command */
    private function recordEscalationEvent(array $command, string $eventType, ?int $fromUserId = null): int
    {
        $eventId = $this->repository->insertEscalationEvent([
            'step_id' => $command['step_id'],
            'event_type' => $eventType,
            'from_assignee' => $fromUserId ?? $command['from_user_id'],
            'to_assignee' => $command['to_user_id'],
            'reason' => $command['reason'],
            'created_by' => $command['actor_id'],
            'created_at' => $this->databaseInstant($command['occurred_at']),
        ]);
        if ($eventId <= 0) {
            throw new DomainException('APPROVAL_ESCALATION_PERSIST_FAILED');
        }

        return $eventId;
    }

    /** @return array<string,mixed> */
    private function normalizeSubmission(array $command): array
    {
        $snapshot = $command['snapshot'] ?? null;
        if (!is_array($snapshot)) {
            throw new InvalidArgumentException('APPROVAL_SNAPSHOT_INVALID');
        }

        return [
            'actor_id' => $this->positiveId($command['actor_id'] ?? null, 'APPROVAL_ACTOR_INVALID'),
            'resource_type' => $this->requiredText($command['resource_type'] ?? null, 'APPROVAL_RESOURCE_TYPE_INVALID'),
            'resource_id' => $this->positiveId($command['resource_id'] ?? null, 'APPROVAL_RESOURCE_ID_INVALID'),
            'workflow_version_id' => $this->positiveId($command['workflow_version_id'] ?? null, 'APPROVAL_WORKFLOW_VERSION_INVALID'),
            'snapshot' => $snapshot,
            'idempotency_key' => $this->idempotencyKey($command['idempotency_key'] ?? null),
            'submitted_at' => $this->commandDate($command['submitted_at'] ?? null, 'APPROVAL_SUBMISSION_TIME_INVALID'),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeDecision(array $command): array
    {
        $decision = strtolower($this->requiredText($command['decision'] ?? null, 'APPROVAL_DECISION_INVALID'));
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException('APPROVAL_DECISION_INVALID');
        }
        $comment = $this->nullableComment($command['comment'] ?? null);
        if ($decision === 'reject' && $comment === null) {
            throw new InvalidArgumentException('APPROVAL_REJECTION_REASON_REQUIRED');
        }

        return [
            'actor_id' => $this->positiveId($command['actor_id'] ?? null, 'APPROVAL_ACTOR_INVALID'),
            'step_id' => $this->positiveId($command['step_id'] ?? null, 'APPROVAL_STEP_ID_INVALID'),
            'expected_lock_version' => $this->positiveId($command['expected_lock_version'] ?? null, 'APPROVAL_STEP_VERSION_INVALID'),
            'decision' => $decision,
            'comment' => $comment,
            'idempotency_key' => $this->idempotencyKey($command['idempotency_key'] ?? null),
            'decided_at' => $this->commandDate($command['decided_at'] ?? null, 'APPROVAL_DECISION_TIME_INVALID'),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeReassignment(array $command, string $eventType): array
    {
        $occurredAt = $this->commandDate(
            $command['occurred_at'] ?? $command['decided_at'] ?? null,
            'APPROVAL_TRANSITION_TIME_INVALID'
        );
        $fromUserId = $this->nullablePositiveId($command['from_user_id'] ?? null);
        if ($eventType === 'reassigned' && $fromUserId === null) {
            throw new InvalidArgumentException('APPROVAL_REASSIGN_SOURCE_INVALID');
        }
        $toUserId = $this->positiveId($command['to_user_id'] ?? null, 'APPROVAL_REASSIGN_TARGET_INVALID');
        if ($fromUserId !== null && $fromUserId === $toUserId) {
            throw new InvalidArgumentException('APPROVAL_REASSIGN_TARGET_INVALID');
        }

        return [
            'actor_id' => $this->positiveId($command['actor_id'] ?? null, 'APPROVAL_ACTOR_INVALID'),
            'step_id' => $this->positiveId($command['step_id'] ?? null, 'APPROVAL_STEP_ID_INVALID'),
            'expected_lock_version' => $this->positiveId($command['expected_lock_version'] ?? null, 'APPROVAL_STEP_VERSION_INVALID'),
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'reason' => $this->requiredText($command['reason'] ?? null, 'APPROVAL_TRANSITION_REASON_REQUIRED', 1000),
            'occurred_at' => $occurredAt,
        ];
    }

    /** @param array<string,mixed> $snapshot @return list<array<string,mixed>> */
    private function snapshotStages(array $snapshot): array
    {
        $stages = $snapshot['stages'] ?? null;
        if (!is_array($stages) || $stages === []) {
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
            $this->positiveId($stage['stage_id'] ?? null, 'APPROVAL_STAGE_ID_INVALID');
            $this->stageAssignees($stage, true);
            $bySequence[$sequence] = $stage;
        }
        ksort($bySequence, SORT_NUMERIC);

        return array_values($bySequence);
    }

    /** @param array<string,mixed> $stage @return list<array<string,mixed>> */
    private function stageAssignees(array $stage, bool $allowMergedEmpty = false): array
    {
        $assignees = $stage['assignees'] ?? null;
        if (!is_array($assignees)) {
            throw new DomainException('APPROVAL_STAGE_ASSIGNEES_INVALID');
        }
        $normalized = [];
        foreach ($assignees as $assignee) {
            if (!is_array($assignee)) {
                throw new DomainException('APPROVAL_STAGE_ASSIGNEES_INVALID');
            }
            $userId = $this->positiveId($assignee['user_id'] ?? null, 'APPROVAL_ASSIGNEE_INVALID');
            if (isset($normalized[$userId])) {
                throw new DomainException('APPROVAL_STAGE_ASSIGNEE_DUPLICATE');
            }
            $snapshot = $assignee['assignment_snapshot'] ?? null;
            if (!is_array($snapshot)) {
                throw new DomainException('APPROVAL_ASSIGNEE_SNAPSHOT_INVALID');
            }
            $normalized[$userId] = [
                'user_id' => $userId,
                'relationship_kind' => $this->requiredText($assignee['relationship_kind'] ?? null, 'APPROVAL_ASSIGNEE_INVALID'),
                'assignment_snapshot' => $snapshot,
                'acting_for_user_id' => $this->nullablePositiveId($assignee['acting_for_user_id'] ?? null),
                'delegation_id' => $this->nullablePositiveId($assignee['delegation_id'] ?? null),
            ];
        }
        if ($normalized === [] && !($allowMergedEmpty && (array) ($stage['merged_actor_ids'] ?? []) !== [])) {
            throw new DomainException('APPROVER_NOT_CONFIGURED');
        }
        ksort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }

    /** @return array<string,mixed> */
    private function requiredStep(int $stepId): array
    {
        $step = $this->repository->stepWithInstanceForUpdate($stepId);
        if ($step === null) {
            throw new DomainException('APPROVAL_STEP_NOT_FOUND');
        }

        return $step;
    }

    /** @param array<string,mixed> $step */
    private function assertDecidableStep(array $step, int $expectedLockVersion): void
    {
        if ((string) ($step['instance_status'] ?? '') !== self::INSTANCE_PENDING
            || (string) ($step['step_status'] ?? '') !== self::STEP_ACTIVE) {
            throw new DomainException('ALREADY_DECIDED');
        }
        if ((int) ($step['step_lock_version'] ?? 0) !== $expectedLockVersion) {
            throw new DomainException('STALE_APPROVAL_STEP');
        }
    }

    /** @param list<array<string,mixed>> $assignees @return array<string,mixed>|null */
    private function eligibleAssignee(array $assignees, int $actorId): ?array
    {
        foreach ($assignees as $assignee) {
            if ((int) ($assignee['assignee_user_id'] ?? 0) === $actorId
                && (string) ($assignee['status'] ?? '') === 'eligible') {
                return $assignee;
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $assignees */
    private function assigneeExists(array $assignees, int $userId): bool
    {
        foreach ($assignees as $assignee) {
            if ((int) ($assignee['assignee_user_id'] ?? 0) === $userId) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $step @param array<string,mixed> $stage */
    private function assertSelfDecisionAllowed(array $step, array $stage, int $actorId): void
    {
        $instanceSnapshot = $this->decodeObject($step['instance_snapshot_json'] ?? null, 'APPROVAL_INSTANCE_SNAPSHOT_INVALID');
        $staffUserId = $this->nullablePositiveId($instanceSnapshot['context']['staff_user_id'] ?? null);
        if ($staffUserId !== null && $staffUserId === $actorId
            && (string) ($stage['self_approval_rule'] ?? 'forbid') !== 'allow_explicit') {
            throw new DomainException('SELF_APPROVAL_FORBIDDEN');
        }
    }

    /** @param array<string,mixed> $assignee */
    private function actingForUserId(array $assignee): ?int
    {
        $snapshot = $this->decodeObject($assignee['assignment_snapshot'] ?? null, 'APPROVAL_ASSIGNEE_SNAPSHOT_INVALID');

        return $this->nullablePositiveId($snapshot['acting_for_user_id'] ?? null);
    }

    /** @return array<string,mixed> */
    private function decodeStage(mixed $value): array
    {
        return $this->decodeObject($value, 'APPROVAL_STAGE_SNAPSHOT_INVALID');
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value, string $errorCode): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new DomainException($errorCode);
        }
        try {
            $decoded = json_decode($value, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException($errorCode);
        }
        if (!is_array($decoded)) {
            throw new DomainException($errorCode);
        }

        return $decoded;
    }

    private function dueAt(array $stage, DateTimeImmutable $from): ?DateTimeImmutable
    {
        $minutes = $stage['sla_minutes'] ?? null;
        if ($minutes === null || $minutes === '') {
            return null;
        }
        if (filter_var($minutes, FILTER_VALIDATE_INT) === false || (int) $minutes < 0) {
            throw new DomainException('APPROVAL_STAGE_SLA_INVALID');
        }

        return $from->add(new DateInterval('PT' . (int) $minutes . 'M'));
    }

    /** @param array<string,mixed> $step @return array<string,mixed> */
    private function instancePayload(array $step): array
    {
        return [
            'id' => (int) $step['instance_id'],
            'resource_type' => (string) $step['resource_type'],
            'resource_id' => (int) $step['resource_id'],
            'workflow_version_id' => (int) $step['workflow_version_id'],
            'snapshot' => $this->decodeObject($step['instance_snapshot_json'] ?? null, 'APPROVAL_INSTANCE_SNAPSHOT_INVALID'),
        ];
    }

    /** @return array<string,mixed> */
    private function instanceReceipt(array $instance, bool $replayed): array
    {
        return [
            'instance_id' => (int) ($instance['id'] ?? 0),
            'status' => (string) ($instance['status'] ?? ''),
            'current_sequence' => (int) ($instance['current_sequence'] ?? 0),
            'active_step_id' => null,
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $decision @param array<string,mixed> $step @return array<string,mixed> */
    private function decisionReceipt(array $decision, array $step, bool $replayed): array
    {
        return [
            'decision_id' => (int) ($decision['decision_id'] ?? $decision['id'] ?? 0),
            'instance_id' => (int) ($step['instance_id'] ?? 0),
            'step_id' => (int) ($step['step_id'] ?? 0),
            'step_status' => (string) ($step['step_status'] ?? ''),
            'instance_status' => (string) ($step['instance_status'] ?? ''),
            'current_sequence' => (int) ($step['current_sequence'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    private function commandDate(mixed $value, string $errorCode): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value === null || $value === '') {
            return new DateTimeImmutable('now', $this->clockZone);
        }
        try {
            return new DateTimeImmutable((string) $value, $this->clockZone);
        } catch (\Throwable) {
            throw new InvalidArgumentException($errorCode);
        }
    }

    private function nullableDate(mixed $value, string $errorCode): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable((string) $value, $this->clockZone);
        } catch (\Throwable) {
            throw new DomainException($errorCode);
        }
    }

    private function positiveId(mixed $value, string $errorCode): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($errorCode);
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new DomainException('APPROVAL_SNAPSHOT_INVALID');
        }

        return (int) $value;
    }

    private function idempotencyKey(mixed $value): string
    {
        return $this->requiredText($value, 'APPROVAL_IDEMPOTENCY_KEY_INVALID', 190);
    }

    private function requiredText(mixed $value, string $errorCode, int $maximum = 500): string
    {
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text) > $maximum) {
            throw new InvalidArgumentException($errorCode);
        }

        return $text;
    }

    private function nullableComment(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $comment = trim((string) $value);
        if ($comment === '') {
            return null;
        }
        if (mb_strlen($comment) > 5000) {
            throw new InvalidArgumentException('APPROVAL_COMMENT_INVALID');
        }

        return $comment;
    }

    private function encodeJson(array $value, string $errorCode): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new DomainException($errorCode);
        }
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
