<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Approval;

use EduCore\Modules\Staff\Contracts\AssignedApprovalInboxReadRepository;
use PDO;

/** PDO projection for the manager inbox; it never mutates approval state. */
final class PdoAssignedApprovalInboxReadRepository implements AssignedApprovalInboxReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function countActiveForAssignee(int $assigneeUserId, ?string $resourceType): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM staff_approval_assignees assignee
             INNER JOIN staff_approval_steps step ON step.id = assignee.step_id
             INNER JOIN staff_approval_instances instance ON instance.id = step.instance_id
             WHERE assignee.assignee_user_id = :assignee_user_id
               AND assignee.status = \'eligible\'
               AND step.status = \'active\'
               AND instance.status = \'pending\'
               AND instance.current_sequence = step.sequence_no'
            . ($resourceType === null ? '' : ' AND instance.resource_type = :resource_type')
        );
        $statement->bindValue(':assignee_user_id', $assigneeUserId, PDO::PARAM_INT);
        if ($resourceType !== null) {
            $statement->bindValue(':resource_type', $resourceType, PDO::PARAM_STR);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function activeForAssignee(
        int $assigneeUserId,
        ?string $resourceType,
        int $limit,
        int $offset
    ): array {
        $statement = $this->db->prepare(
            'SELECT instance.id AS instance_id,
                    instance.resource_type,
                    instance.resource_id,
                    instance.workflow_version_id,
                    instance.current_sequence,
                    instance.started_at,
                    instance.snapshot_json AS instance_snapshot_json,
                    step.id AS step_id,
                    step.stage_id,
                    step.sequence_no,
                    step.due_at,
                    step.activated_at,
                    step.lock_version AS step_lock_version,
                    step.snapshot_json AS step_snapshot_json,
                    assignee.id AS assignee_id,
                    assignee.assignee_user_id,
                    assignee.relationship_kind,
                    assignee.assignment_snapshot
             FROM staff_approval_assignees assignee
             INNER JOIN staff_approval_steps step ON step.id = assignee.step_id
             INNER JOIN staff_approval_instances instance ON instance.id = step.instance_id
             WHERE assignee.assignee_user_id = :assignee_user_id
               AND assignee.status = \'eligible\'
               AND step.status = \'active\'
               AND instance.status = \'pending\'
               AND instance.current_sequence = step.sequence_no'
            . ($resourceType === null ? '' : ' AND instance.resource_type = :resource_type')
            . ' ORDER BY CASE WHEN step.due_at IS NULL THEN 1 ELSE 0 END ASC,
                       step.due_at ASC,
                       step.activated_at ASC,
                       instance.id ASC
                LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':assignee_user_id', $assigneeUserId, PDO::PARAM_INT);
        if ($resourceType !== null) {
            $statement->bindValue(':resource_type', $resourceType, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
