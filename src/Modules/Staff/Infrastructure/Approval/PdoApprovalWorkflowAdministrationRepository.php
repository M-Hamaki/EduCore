<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Approval;

use EduCore\Modules\Staff\Contracts\ApprovalWorkflowAdministrationRepository;
use PDO;
use Throwable;

/** PDO adapter for administrative workflow and delegation persistence only. */
final class PdoApprovalWorkflowAdministrationRepository implements ApprovalWorkflowAdministrationRepository
{
    private int $savepointSequence = 0;

    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $work): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        } else {
            $savepoint = 'staff_approval_admin_' . (++$this->savepointSequence);
            $this->db->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $work();
            if ($ownsTransaction) {
                if (!$this->db->inTransaction()) {
                    throw new \RuntimeException('Approval administration transaction boundary was lost.');
                }
                $this->db->commit();
            } elseif ($savepoint !== null) {
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            } elseif ($savepoint !== null && $this->db->inTransaction()) {
                $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    public function workflowVersions(): array
    {
        $statement = $this->db->query(
            'SELECT workflow.id AS workflow_id, workflow.code AS workflow_code, workflow.name AS workflow_name,
                    workflow.resource_type, workflow.status AS workflow_status,
                    version.id AS workflow_version_id, version.version_no, version.state AS version_state,
                    version.valid_from, version.valid_to, version.cancellation_rule,
                    version.published_by, version.published_at, version.created_at AS version_created_at,
                    COUNT(stage.id) AS stage_count
             FROM staff_approval_workflows workflow
             LEFT JOIN staff_approval_workflow_versions version ON version.workflow_id = workflow.id
             LEFT JOIN staff_approval_stages stage ON stage.workflow_version_id = version.id
             GROUP BY workflow.id, workflow.code, workflow.name, workflow.resource_type, workflow.status,
                      version.id, version.version_no, version.state, version.valid_from, version.valid_to,
                      version.cancellation_rule, version.published_by, version.published_at, version.created_at
             ORDER BY workflow.updated_at DESC, version.version_no DESC, version.id DESC'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delegations(): array
    {
        $statement = $this->db->query(
            'SELECT delegation.id, delegation.delegator_user_id, delegation.delegate_user_id,
                    delegation.scope_type, delegation.scope_id, delegation.request_types,
                    delegation.valid_from, delegation.valid_to, delegation.reason, delegation.status,
                    delegator.name AS delegator_name, delegate_user.name AS delegate_name
             FROM staff_delegations delegation
             LEFT JOIN users delegator ON delegator.id = delegation.delegator_user_id
             LEFT JOIN users delegate_user ON delegate_user.id = delegation.delegate_user_id
             ORDER BY CASE delegation.status WHEN \'active\' THEN 0 WHEN \'draft\' THEN 1 ELSE 2 END,
                      delegation.valid_from ASC, delegation.id DESC'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeUsers(): array
    {
        $statement = $this->db->query(
            "SELECT id, name, username, role
             FROM users
             WHERE status = 'active'
             ORDER BY name ASC, id ASC"
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isActiveUser(int $userId): bool
    {
        $statement = $this->db->prepare(
            "SELECT id FROM users WHERE id = ? AND status = 'active' LIMIT 1"
        );
        $statement->execute([$userId]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function activeRoleKeys(): array
    {
        $statement = $this->db->query(
            "SELECT DISTINCT role_key
             FROM user_role_assignments
             WHERE status = 'active'
             ORDER BY role_key ASC"
        );

        return array_values(array_map(
            static fn(array $row): string => (string) $row['role_key'],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        ));
    }

    public function workflowForUpdate(int $workflowId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, name, resource_type, status
             FROM staff_approval_workflows
             WHERE id = ?' . $this->forUpdate()
        );
        $statement->execute([$workflowId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function versionForUpdate(int $versionId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT version.id, version.workflow_id, version.version_no, version.state,
                    version.valid_from, version.valid_to, version.cancellation_rule,
                    workflow.code AS workflow_code, workflow.name AS workflow_name,
                    workflow.resource_type, workflow.status AS workflow_status
             FROM staff_approval_workflow_versions version
             INNER JOIN staff_approval_workflows workflow ON workflow.id = version.workflow_id
             WHERE version.id = ?' . $this->forUpdate()
        );
        $statement->execute([$versionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function publishedVersionsForUpdate(int $workflowId): array
    {
        $statement = $this->db->prepare(
            "SELECT id, workflow_id, valid_from, valid_to
             FROM staff_approval_workflow_versions
             WHERE workflow_id = ? AND state = 'published'
             ORDER BY valid_from ASC, id ASC" . $this->forUpdate()
        );
        $statement->execute([$workflowId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function stageCountForVersion(int $versionId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM staff_approval_stages WHERE workflow_version_id = ?' . $this->forUpdate()
        );
        $statement->execute([$versionId]);

        return (int) $statement->fetchColumn();
    }

    public function nextVersionNumber(int $workflowId): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1
             FROM staff_approval_workflow_versions
             WHERE workflow_id = ?' . $this->forUpdate()
        );
        $statement->execute([$workflowId]);

        return (int) $statement->fetchColumn();
    }

    public function insertWorkflow(array $workflow): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_approval_workflows (code, name, resource_type, status, created_by)
             VALUES (:code, :name, :resource_type, :status, :created_by)'
        );
        $statement->execute([
            ':code' => $workflow['code'],
            ':name' => $workflow['name'],
            ':resource_type' => $workflow['resource_type'],
            ':status' => $workflow['status'],
            ':created_by' => $workflow['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function insertVersion(array $version): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_approval_workflow_versions
             (workflow_id, version_no, state, valid_from, valid_to, cancellation_rule, escalation_rule,
              supersedes_id, published_by, published_at, created_by)
             VALUES (:workflow_id, :version_no, :state, :valid_from, :valid_to, :cancellation_rule,
                     :escalation_rule, :supersedes_id, :published_by, :published_at, :created_by)'
        );
        $statement->execute([
            ':workflow_id' => $version['workflow_id'],
            ':version_no' => $version['version_no'],
            ':state' => $version['state'],
            ':valid_from' => $version['valid_from'],
            ':valid_to' => $version['valid_to'],
            ':cancellation_rule' => $version['cancellation_rule'],
            ':escalation_rule' => $version['escalation_rule'],
            ':supersedes_id' => $version['supersedes_id'],
            ':published_by' => $version['published_by'],
            ':published_at' => $version['published_at'],
            ':created_by' => $version['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function insertStage(array $stage): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_approval_stages
             (workflow_version_id, sequence_no, name, resolver_type, resolver_config, decision_mode,
              sla_minutes, on_timeout, self_approval_rule, same_actor_rule, quorum_count, tie_rule, rejection_rule)
             VALUES (:workflow_version_id, :sequence_no, :name, :resolver_type, :resolver_config,
                     :decision_mode, :sla_minutes, :on_timeout, :self_approval_rule, :same_actor_rule,
                     :quorum_count, :tie_rule, :rejection_rule)'
        );
        $statement->execute([
            ':workflow_version_id' => $stage['workflow_version_id'],
            ':sequence_no' => $stage['sequence_no'],
            ':name' => $stage['name'],
            ':resolver_type' => $stage['resolver_type'],
            ':resolver_config' => $stage['resolver_config'],
            ':decision_mode' => $stage['decision_mode'],
            ':sla_minutes' => $stage['sla_minutes'],
            ':on_timeout' => $stage['on_timeout'],
            ':self_approval_rule' => $stage['self_approval_rule'],
            ':same_actor_rule' => $stage['same_actor_rule'],
            ':quorum_count' => $stage['quorum_count'],
            ':tie_rule' => $stage['tie_rule'],
            ':rejection_rule' => $stage['rejection_rule'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function setVersionValidTo(int $versionId, string $validTo): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_approval_workflow_versions
             SET valid_to = ?
             WHERE id = ?'
        );
        $statement->execute([$validTo, $versionId]);

        return $statement->rowCount() === 1;
    }

    public function publishVersion(int $versionId, int $actorId, string $publishedAt): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_approval_workflow_versions
             SET state = 'published', published_by = ?, published_at = ?
             WHERE id = ? AND state = 'draft'"
        );
        $statement->execute([$actorId, $publishedAt, $versionId]);

        return $statement->rowCount() === 1;
    }

    public function setWorkflowStatus(int $workflowId, string $status): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_approval_workflows SET status = ? WHERE id = ?'
        );
        $statement->execute([$status, $workflowId]);

        return $statement->rowCount() === 1;
    }

    public function delegationForUpdate(int $delegationId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, delegator_user_id, delegate_user_id, scope_type, scope_id, request_types,
                    valid_from, valid_to, reason, status
             FROM staff_delegations
             WHERE id = ?' . $this->forUpdate()
        );
        $statement->execute([$delegationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function hasActiveDelegationScopeOverlap(array $delegation): bool
    {
        $statement = $this->db->prepare(
            "SELECT id
             FROM staff_delegations
             WHERE delegator_user_id = :delegator_user_id
               AND scope_type = :scope_type
               AND scope_id = :scope_id
               AND status = 'active'
               AND valid_from < :candidate_valid_to
               AND valid_to > :candidate_valid_from
             LIMIT 1" . $this->forUpdate()
        );
        $statement->execute([
            ':delegator_user_id' => $delegation['delegator_user_id'],
            ':scope_type' => $delegation['scope_type'],
            ':scope_id' => $delegation['scope_id'],
            ':candidate_valid_from' => $delegation['valid_from'],
            ':candidate_valid_to' => $delegation['valid_to'],
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function insertDelegation(array $delegation): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_delegations
             (delegator_user_id, delegate_user_id, scope_type, scope_id, request_types, valid_from,
              valid_to, reason, status, created_by)
             VALUES (:delegator_user_id, :delegate_user_id, :scope_type, :scope_id, :request_types,
                     :valid_from, :valid_to, :reason, :status, :created_by)'
        );
        $statement->execute([
            ':delegator_user_id' => $delegation['delegator_user_id'],
            ':delegate_user_id' => $delegation['delegate_user_id'],
            ':scope_type' => $delegation['scope_type'],
            ':scope_id' => $delegation['scope_id'],
            ':request_types' => $delegation['request_types'],
            ':valid_from' => $delegation['valid_from'],
            ':valid_to' => $delegation['valid_to'],
            ':reason' => $delegation['reason'],
            ':status' => $delegation['status'],
            ':created_by' => $delegation['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function setDelegationStatus(int $delegationId, string $status): bool
    {
        $statement = $this->db->prepare('UPDATE staff_delegations SET status = ? WHERE id = ?');
        $statement->execute([$status, $delegationId]);

        return $statement->rowCount() === 1;
    }

    private function forUpdate(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
