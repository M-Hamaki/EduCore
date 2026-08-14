<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Approval;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;

/**
 * Routes a final shared-workflow decision to the Staff resource that owns its
 * state. It keeps ApprovalWorkflowService independent of permission and leave
 * persistence details while failing closed for a newly introduced resource.
 */
final class StaffApprovalOutcomeRouter implements ApprovalWorkflowOutcomeHandler
{
    public function __construct(
        private ApprovalWorkflowOutcomeHandler $permissionOutcomes,
        private ApprovalWorkflowOutcomeHandler $leaveOutcomes,
        private ?ApprovalWorkflowOutcomeHandler $disciplineOutcomes = null,
        private ?ApprovalWorkflowOutcomeHandler $scheduleChangeOutcomes = null
    ) {
    }

    /** @param array<string,mixed> $instance */
    public function apply(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void
    {
        $resourceType = trim((string) ($instance['resource_type'] ?? ''));

        match ($resourceType) {
            'permission_request' => $this->permissionOutcomes->apply($instance, $outcome, $actorId, $occurredAt),
            'leave_request' => $this->leaveOutcomes->apply($instance, $outcome, $actorId, $occurredAt),
            'discipline_case' => $this->applyDiscipline($instance, $outcome, $actorId, $occurredAt),
            'schedule_change' => $this->applyScheduleChange($instance, $outcome, $actorId, $occurredAt),
            default => throw new DomainException('APPROVAL_OUTCOME_RESOURCE_UNSUPPORTED'),
        };
    }

    private function applyScheduleChange(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void
    {
        if ($this->scheduleChangeOutcomes === null) {
            throw new DomainException('APPROVAL_OUTCOME_RESOURCE_UNSUPPORTED');
        }
        $this->scheduleChangeOutcomes->apply($instance, $outcome, $actorId, $occurredAt);
    }

    /** @param array<string,mixed> $instance */
    private function applyDiscipline(
        array $instance,
        string $outcome,
        int $actorId,
        DateTimeImmutable $occurredAt
    ): void {
        if ($this->disciplineOutcomes === null) {
            throw new DomainException('APPROVAL_OUTCOME_RESOURCE_UNSUPPORTED');
        }
        $this->disciplineOutcomes->apply($instance, $outcome, $actorId, $occurredAt);
    }
}
