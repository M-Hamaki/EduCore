<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Attendance\Application\ScheduleChangeRequestService;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;

final class ScheduleChangeApprovalOutcomeHandler implements ApprovalWorkflowOutcomeHandler
{
    public function __construct(
        private SchedulePolicyRepository $repository,
        private ScheduleChangeRequestService $service,
        private AuditEventWriter $audit
    ) {
    }

    public function apply(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void
    {
        $requestId = (int) ($instance['resource_id'] ?? 0);
        $workflowInstanceId = (int) ($instance['id'] ?? 0);
        $current = $this->repository->changeRequestForUpdate($requestId);
        if ($requestId <= 0 || $workflowInstanceId <= 0 || $current === null) {
            throw new DomainException('SCHEDULE_CHANGE_NOT_FOUND');
        }
        if ((int) ($current['workflow_instance_id'] ?? 0) !== $workflowInstanceId) {
            throw new DomainException('SCHEDULE_CHANGE_WORKFLOW_MISMATCH');
        }
        if ($outcome === 'approved') {
            $snapshot = (array) (($instance['snapshot']['context']['approved_schedule_snapshot'] ?? null) ?: []);
            $this->service->approve(
                $requestId,
                $actorId,
                (int) ($current['lock_version'] ?? 0),
                $snapshot,
                $occurredAt,
                'schedule-change-workflow-approved:' . $workflowInstanceId
            );

            return;
        }
        if ($outcome !== 'rejected') {
            throw new DomainException('SCHEDULE_CHANGE_WORKFLOW_OUTCOME_INVALID');
        }
        if ((string) ($current['status'] ?? '') !== 'submitted'
            || !$this->repository->updateChangeRequest($requestId, (int) $current['lock_version'], ['status' => 'rejected'])) {
            throw new DomainException('SCHEDULE_CHANGE_STALE');
        }
        $this->audit->recordEvent(
            'staff_schedule_change_rejected',
            'staff_schedule_change_request',
            $requestId,
            null,
            ['workflow_instance_id' => $workflowInstanceId, 'from_status' => 'submitted', 'to_status' => 'rejected'],
            ['user_id' => $actorId, 'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u')]
        );
    }
}
