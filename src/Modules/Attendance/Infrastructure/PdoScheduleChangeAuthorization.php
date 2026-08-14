<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use EduCore\Modules\Attendance\Contracts\ScheduleChangeAuthorization;
use EduCore\Modules\Staff\Contracts\ApprovalDecisionEvidenceQuery;

/** Fail-closed authorization backed by the immutable approval workflow evidence. */
final class PdoScheduleChangeAuthorization implements ScheduleChangeAuthorization
{
    private ApprovalDecisionEvidenceQuery $approvalEvidence;

    public function __construct(ApprovalDecisionEvidenceQuery $approvalEvidence)
    {
        $this->approvalEvidence = $approvalEvidence;
    }

    public function canSubmit(int $actorId, int $staffId, array $payload): bool
    {
        return $actorId > 0 && $actorId === $staffId;
    }

    public function canApprove(int $actorId, array $request): bool
    {
        $workflowId = (int) ($request['workflow_instance_id'] ?? 0);
        $requestId = (int) ($request['id'] ?? 0);
        if ($actorId <= 0 || $workflowId <= 0 || $requestId <= 0) {
            return false;
        }
        return $this->approvalEvidence->actorApprovedScheduleChange($workflowId, $requestId, $actorId);
    }

    public function canLinkWorkflow(int $actorId, array $request, int $workflowInstanceId): bool
    {
        $requestId = (int) ($request['id'] ?? 0);
        return $actorId > 0
            && $actorId === (int) ($request['staff_user_id'] ?? 0)
            && $this->approvalEvidence->workflowOwnsScheduleChange($workflowInstanceId, $requestId);
    }
}
