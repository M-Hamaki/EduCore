<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Approval;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\ApprovalActorEligibilityQuery;
use EduCore\Modules\Staff\Contracts\ApprovalDelegationRevalidationQuery;
use EduCore\Modules\Staff\Contracts\ApprovalManagerRelationshipRevalidationQuery;
use EduCore\Modules\Staff\Contracts\ApprovalTransitionAuthorization;
use JsonException;

/**
 * Revalidates live access just before a transition without changing a frozen
 * workflow's original assignee or its historical evidence.
 */
final class ApprovalWorkflowAuthorization implements ApprovalTransitionAuthorization
{
    public function __construct(
        private ApprovalActorEligibilityQuery $actors,
        private ApprovalDelegationRevalidationQuery $delegations,
        private ?ApprovalManagerRelationshipRevalidationQuery $managerRelationships = null
    ) {
    }

    public function assertCanAct(
        int $actorId,
        string $operation,
        array $instance,
        array $step,
        ?array $assignee,
        DateTimeImmutable $atInstant
    ): void {
        if ($actorId <= 0) {
            throw new DomainException('APPROVAL_ACTOR_INVALID');
        }

        if ($operation === 'decide') {
            if ($assignee === null || (int) ($assignee['assignee_user_id'] ?? 0) !== $actorId) {
                throw new DomainException('NOT_ASSIGNED_APPROVER');
            }
            $this->assertAssigneeCanAct($actorId, $assignee, $atInstant);

            return;
        }

        if ($operation === 'reassign') {
            if ($assignee !== null && (int) ($assignee['assignee_user_id'] ?? 0) === $actorId) {
                $this->assertAssigneeCanAct($actorId, $assignee, $atInstant);

                return;
            }
            $eligibility = $this->actors->currentEligibility($actorId, 'administration', $atInstant);
            $this->assertLiveSession($eligibility);
            if (($eligibility['can_manage_approvals'] ?? false) !== true) {
                throw new DomainException('APPROVAL_REASSIGN_FORBIDDEN');
            }

            return;
        }

        if ($operation === 'escalate') {
            $eligibility = $this->actors->currentEligibility($actorId, 'administration', $atInstant);
            $this->assertLiveSession($eligibility);
            if (($eligibility['can_manage_approvals'] ?? false) !== true) {
                throw new DomainException('APPROVAL_ESCALATION_FORBIDDEN');
            }

            return;
        }

        throw new DomainException('APPROVAL_TRANSITION_OPERATION_INVALID');
    }

    public function assertCanReceiveAssignment(
        int $userId,
        array $instance,
        array $step,
        DateTimeImmutable $atInstant
    ): void {
        $eligibility = $this->actors->currentEligibility($userId, 'named_user', $atInstant);
        if (($eligibility['allowed'] ?? false) !== true) {
            throw new DomainException('APPROVAL_ASSIGNMENT_TARGET_INACTIVE');
        }
    }

    /** @param array<string,mixed> $assignee */
    private function assertAssigneeCanAct(int $actorId, array $assignee, DateTimeImmutable $atInstant): void
    {
        $relationshipKind = (string) ($assignee['relationship_kind'] ?? '');
        if ($relationshipKind === '') {
            throw new DomainException('APPROVAL_ASSIGNEE_EVIDENCE_INVALID');
        }
        $eligibility = $this->actors->currentEligibility($actorId, $relationshipKind, $atInstant);
        $this->assertLiveSession($eligibility);
        if (($eligibility['allowed'] ?? false) !== true) {
            if (($eligibility['reason'] ?? '') === 'service_ended') {
                throw new DomainException('APPROVAL_ACTOR_SERVICE_ENDED');
            }
            throw new DomainException('APPROVAL_SESSION_REVALIDATION_FAILED');
        }

        if (in_array($relationshipKind, ['direct_manager', 'administrative_manager', 'delegated_direct_manager', 'delegated_administrative_manager'], true)
            && $this->managerRelationships !== null
            && !$this->managerRelationships->isStillResponsible(
                $actorId,
                $relationshipKind,
                $this->assigneeSnapshot($assignee),
                $atInstant
            )) {
            throw new DomainException('APPROVAL_MANAGER_RELATIONSHIP_CHANGED');
        }

        if (str_starts_with($relationshipKind, 'delegated_')) {
            $this->assertDelegationStillActive($actorId, $assignee, $atInstant);
        }
    }

    /** @param array{allowed?:mixed,reason?:mixed} $eligibility */
    private function assertLiveSession(array $eligibility): void
    {
        if (($eligibility['allowed'] ?? false) !== true
            && ($eligibility['reason'] ?? '') !== 'service_ended') {
            throw new DomainException('APPROVAL_SESSION_REVALIDATION_FAILED');
        }
    }

    /** @param array<string,mixed> $assignee */
    private function assertDelegationStillActive(int $actorId, array $assignee, DateTimeImmutable $atInstant): void
    {
        $snapshot = $this->assigneeSnapshot($assignee);
        $delegationId = $this->positiveId($snapshot['delegation_id'] ?? null);
        $actingForUserId = $this->positiveId($snapshot['acting_for_user_id'] ?? null);
        if ($delegationId === null || $actingForUserId === null) {
            throw new DomainException('APPROVAL_DELEGATION_EVIDENCE_INVALID');
        }
        if (!$this->delegations->isStillActive($delegationId, $actingForUserId, $actorId, $atInstant)) {
            throw new DomainException('APPROVAL_DELEGATION_EXPIRED');
        }
    }

    /** @param array<string,mixed> $assignee @return array<string,mixed> */
    private function assigneeSnapshot(array $assignee): array
    {
        $snapshot = $assignee['assignment_snapshot'] ?? null;
        if (is_array($snapshot)) {
            return $snapshot;
        }
        if (!is_string($snapshot) || trim($snapshot) === '') {
            throw new DomainException('APPROVAL_DELEGATION_EVIDENCE_INVALID');
        }
        try {
            $decoded = json_decode($snapshot, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('APPROVAL_DELEGATION_EVIDENCE_INVALID');
        }
        if (!is_array($decoded)) {
            throw new DomainException('APPROVAL_DELEGATION_EVIDENCE_INVALID');
        }

        return $decoded;
    }

    private function positiveId(mixed $value): ?int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
