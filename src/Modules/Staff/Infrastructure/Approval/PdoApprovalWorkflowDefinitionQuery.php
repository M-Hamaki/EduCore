<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Approval;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowDefinitionQuery;
use InvalidArgumentException;
use PDO;

/** Read-only PDO adapter for effective, published workflow definitions. */
final class PdoApprovalWorkflowDefinitionQuery implements ApprovalWorkflowDefinitionQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function findPublishedForResource(string $resourceType, DateTimeImmutable $effectiveAt): array
    {
        $resourceType = trim($resourceType);
        if ($resourceType === '') {
            throw new InvalidArgumentException('An approval workflow resource type is required.');
        }

        $timestamp = $effectiveAt->format('Y-m-d H:i:s.u');
        $statement = $this->db->prepare(
            "SELECT workflow.id AS workflow_id,
                    workflow.code AS workflow_code,
                    workflow.name AS workflow_name,
                    workflow.resource_type AS resource_type,
                    version.id AS workflow_version_id,
                    version.version_no,
                    version.valid_from,
                    version.valid_to,
                    version.cancellation_rule,
                    version.escalation_rule
             FROM staff_approval_workflows workflow
             INNER JOIN staff_approval_workflow_versions version ON version.workflow_id = workflow.id
             WHERE workflow.resource_type = :resource_type
               AND workflow.status = 'active'
               AND version.state = 'published'
               AND version.valid_from <= :effective_at
               AND (version.valid_to IS NULL OR version.valid_to > :effective_at_again)
             ORDER BY workflow.id ASC, version.version_no DESC"
        );
        $statement->execute([
            ':resource_type' => $resourceType,
            ':effective_at' => $timestamp,
            ':effective_at_again' => $timestamp,
        ]);

        $definitions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $versionId = (int) $row['workflow_version_id'];
            $definitions[] = [
                'workflow_id' => (int) $row['workflow_id'],
                'workflow_code' => (string) $row['workflow_code'],
                'workflow_name' => (string) $row['workflow_name'],
                'resource_type' => (string) $row['resource_type'],
                'workflow_version_id' => $versionId,
                'version_no' => (int) $row['version_no'],
                'valid_from' => (string) $row['valid_from'],
                'valid_to' => $row['valid_to'] !== null ? (string) $row['valid_to'] : null,
                'cancellation_rule' => (string) $row['cancellation_rule'],
                'escalation_rule' => $row['escalation_rule'],
                'stages' => $this->stagesForVersion($versionId),
            ];
        }

        return $definitions;
    }

    /** @return list<array<string,mixed>> */
    private function stagesForVersion(int $workflowVersionId): array
    {
        $statement = $this->db->prepare(
            "SELECT id AS stage_id,
                    sequence_no,
                    name,
                    resolver_type,
                    resolver_config,
                    decision_mode,
                    sla_minutes,
                    on_timeout,
                    self_approval_rule,
                    same_actor_rule,
                    quorum_count,
                    tie_rule,
                    rejection_rule
             FROM staff_approval_stages
             WHERE workflow_version_id = ?
             ORDER BY sequence_no ASC, id ASC"
        );
        $statement->execute([$workflowVersionId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
