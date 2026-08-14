<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Approval;

use EduCore\Modules\Staff\Contracts\ApprovalWorkflowRepository;
use PDO;
use Throwable;

/** PDO persistence adapter; all transition policy remains in the application service. */
final class PdoApprovalWorkflowRepository implements ApprovalWorkflowRepository
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
            $savepoint = 'staff_approval_' . (++$this->savepointSequence);
            $this->db->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $work();
            if ($ownsTransaction) {
                if (!$this->db->inTransaction()) {
                    throw new \RuntimeException('Approval transaction boundary was lost before commit.');
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

    public function instanceByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_approval_instances WHERE idempotency_key = ?' . $this->forUpdate()
        );
        $statement->execute([$idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertInstance(array $instance): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_approval_instances
             (resource_type, resource_id, workflow_version_id, status, current_sequence, started_at,
              completed_at, snapshot_json, lock_version, idempotency_key)
             VALUES (:resource_type, :resource_id, :workflow_version_id, :status, :current_sequence,
                     :started_at, :completed_at, :snapshot_json, :lock_version, :idempotency_key)'
        );
        $statement->execute([
            ':resource_type' => $instance['resource_type'],
            ':resource_id' => $instance['resource_id'],
            ':workflow_version_id' => $instance['workflow_version_id'],
            ':status' => $instance['status'],
            ':current_sequence' => $instance['current_sequence'],
            ':started_at' => $instance['started_at'],
            ':completed_at' => $instance['completed_at'],
            ':snapshot_json' => $instance['snapshot_json'],
            ':lock_version' => $instance['lock_version'],
            ':idempotency_key' => $instance['idempotency_key'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function insertStep(array $step): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_approval_steps
             (instance_id, stage_id, sequence_no, status, due_at, activated_at, completed_at, snapshot_json, lock_version)
             VALUES (:instance_id, :stage_id, :sequence_no, :status, :due_at, :activated_at, :completed_at,
                     :snapshot_json, :lock_version)'
        );
        $statement->execute([
            ':instance_id' => $step['instance_id'],
            ':stage_id' => $step['stage_id'],
            ':sequence_no' => $step['sequence_no'],
            ':status' => $step['status'],
            ':due_at' => $step['due_at'],
            ':activated_at' => $step['activated_at'],
            ':completed_at' => $step['completed_at'],
            ':snapshot_json' => $step['snapshot_json'],
            ':lock_version' => $step['lock_version'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function insertAssignee(array $assignee): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_approval_assignees
             (step_id, assignee_user_id, relationship_kind, assignment_snapshot, status)
             VALUES (:step_id, :assignee_user_id, :relationship_kind, :assignment_snapshot, :status)'
        );
        $statement->execute([
            ':step_id' => $assignee['step_id'],
            ':assignee_user_id' => $assignee['assignee_user_id'],
            ':relationship_kind' => $assignee['relationship_kind'],
            ':assignment_snapshot' => $assignee['assignment_snapshot'],
            ':status' => $assignee['status'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function stepWithInstanceForUpdate(int $stepId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT step.id AS step_id, step.instance_id, step.stage_id, step.sequence_no,
                    step.status AS step_status, step.due_at, step.activated_at, step.completed_at,
                    step.snapshot_json AS step_snapshot_json, step.lock_version AS step_lock_version,
                    instance.resource_type, instance.resource_id, instance.workflow_version_id,
                    instance.status AS instance_status, instance.current_sequence, instance.started_at,
                    instance.completed_at AS instance_completed_at, instance.snapshot_json AS instance_snapshot_json,
                    instance.lock_version AS instance_lock_version
             FROM staff_approval_steps step
             INNER JOIN staff_approval_instances instance ON instance.id = step.instance_id
             WHERE step.id = ?' . $this->forUpdate()
        );
        $statement->execute([$stepId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function stepsForInstanceForUpdate(int $instanceId): array
    {
        $statement = $this->db->prepare(
            'SELECT id AS step_id, instance_id, stage_id, sequence_no, status, due_at, activated_at,
                    completed_at, snapshot_json, lock_version
             FROM staff_approval_steps
             WHERE instance_id = ?
             ORDER BY sequence_no ASC, id ASC' . $this->forUpdate()
        );
        $statement->execute([$instanceId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assigneesForStepForUpdate(int $stepId): array
    {
        $statement = $this->db->prepare(
            'SELECT id AS assignee_id, step_id, assignee_user_id, relationship_kind, assignment_snapshot, status
             FROM staff_approval_assignees
             WHERE step_id = ?
             ORDER BY id ASC' . $this->forUpdate()
        );
        $statement->execute([$stepId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function decisionsForStepForUpdate(int $stepId): array
    {
        $statement = $this->db->prepare(
            'SELECT id AS decision_id, step_id, actor_user_id, acting_for_user_id, decision, comment,
                    decided_at, idempotency_key, is_effective
             FROM staff_approval_decisions
             WHERE step_id = ?
             ORDER BY decided_at ASC, id ASC' . $this->forUpdate()
        );
        $statement->execute([$stepId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function decisionByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id AS decision_id, step_id, actor_user_id, acting_for_user_id, decision, comment,
                    decided_at, idempotency_key, is_effective
             FROM staff_approval_decisions
             WHERE idempotency_key = ?' . $this->forUpdate()
        );
        $statement->execute([$idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function decisionForActorForUpdate(int $stepId, int $actorUserId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id AS decision_id, step_id, actor_user_id, acting_for_user_id, decision, comment,
                    decided_at, idempotency_key, is_effective
             FROM staff_approval_decisions
             WHERE step_id = ? AND actor_user_id = ?' . $this->forUpdate()
        );
        $statement->execute([$stepId, $actorUserId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertDecision(array $decision): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_approval_decisions
             (step_id, actor_user_id, acting_for_user_id, decision, comment, decided_at, idempotency_key, is_effective)
             VALUES (:step_id, :actor_user_id, :acting_for_user_id, :decision, :comment, :decided_at,
                     :idempotency_key, :is_effective)'
        );
        $statement->execute([
            ':step_id' => $decision['step_id'],
            ':actor_user_id' => $decision['actor_user_id'],
            ':acting_for_user_id' => $decision['acting_for_user_id'],
            ':decision' => $decision['decision'],
            ':comment' => $decision['comment'],
            ':decided_at' => $decision['decided_at'],
            ':idempotency_key' => $decision['idempotency_key'],
            ':is_effective' => $decision['is_effective'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateStep(int $stepId, int $expectedLockVersion, array $changes): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_approval_steps
             SET status = :status,
                 due_at = :due_at,
                 activated_at = :activated_at,
                 completed_at = :completed_at,
                 lock_version = lock_version + 1
             WHERE id = :id AND lock_version = :lock_version'
        );
        $statement->execute([
            ':status' => $changes['status'],
            ':due_at' => $changes['due_at'],
            ':activated_at' => $changes['activated_at'],
            ':completed_at' => $changes['completed_at'],
            ':id' => $stepId,
            ':lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function updateInstance(int $instanceId, int $expectedLockVersion, array $changes): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_approval_instances
             SET status = :status,
                 current_sequence = :current_sequence,
                 completed_at = :completed_at,
                 lock_version = lock_version + 1
             WHERE id = :id AND lock_version = :lock_version'
        );
        $statement->execute([
            ':status' => $changes['status'],
            ':current_sequence' => $changes['current_sequence'],
            ':completed_at' => $changes['completed_at'],
            ':id' => $instanceId,
            ':lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function updateAssigneeStatus(int $assigneeId, string $status): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_approval_assignees SET status = ? WHERE id = ?'
        );
        $statement->execute([$status, $assigneeId]);

        return $statement->rowCount() === 1;
    }

    public function insertEscalationEvent(array $event): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_approval_escalation_events
             (step_id, event_type, from_assignee, to_assignee, reason, created_by, created_at)
             VALUES (:step_id, :event_type, :from_assignee, :to_assignee, :reason, :created_by, :created_at)'
        );
        $statement->execute([
            ':step_id' => $event['step_id'],
            ':event_type' => $event['event_type'],
            ':from_assignee' => $event['from_assignee'],
            ':to_assignee' => $event['to_assignee'],
            ':reason' => $event['reason'],
            ':created_by' => $event['created_by'],
            ':created_at' => $event['created_at'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function forUpdate(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
