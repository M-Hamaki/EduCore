<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/** Staff-owned read contract proving a completed workflow approval decision. */
interface ApprovalDecisionEvidenceQuery
{
    public function workflowOwnsScheduleChange(int $workflowInstanceId, int $requestId): bool;

    public function actorApprovedScheduleChange(int $workflowInstanceId, int $requestId, int $actorId): bool;
}
