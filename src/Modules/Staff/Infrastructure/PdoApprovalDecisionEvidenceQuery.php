<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\ApprovalDecisionEvidenceQuery;
use PDO;

final class PdoApprovalDecisionEvidenceQuery implements ApprovalDecisionEvidenceQuery
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function actorApprovedScheduleChange(int $workflowInstanceId, int $requestId, int $actorId): bool
    {
        if ($workflowInstanceId <= 0 || $requestId <= 0 || $actorId <= 0) {
            return false;
        }
        $statement = $this->db->prepare(
            'SELECT 1
             FROM staff_approval_instances instance
             JOIN staff_approval_steps step ON step.instance_id = instance.id
             JOIN staff_approval_decisions decision ON decision.step_id = step.id
             WHERE instance.id = ?
               AND instance.resource_type = \'schedule_change\'
               AND instance.resource_id = ?
               AND instance.status = \'approved\'
               AND decision.actor_user_id = ?
               AND decision.decision = \'approve\'
               AND decision.is_effective = 1
             LIMIT 1'
        );
        $statement->execute([$workflowInstanceId, $requestId, $actorId]);

        return (bool) $statement->fetchColumn();
    }

    public function workflowOwnsScheduleChange(int $workflowInstanceId, int $requestId): bool
    {
        if ($workflowInstanceId <= 0 || $requestId <= 0) {
            return false;
        }
        $statement = $this->db->prepare(
            'SELECT 1 FROM staff_approval_instances
             WHERE id = ? AND resource_type = \'schedule_change\' AND resource_id = ?
             LIMIT 1'
        );
        $statement->execute([$workflowInstanceId, $requestId]);

        return (bool) $statement->fetchColumn();
    }
}
