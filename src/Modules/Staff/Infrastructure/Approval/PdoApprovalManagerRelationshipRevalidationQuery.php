<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Approval;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\ApprovalManagerRelationshipRevalidationQuery;
use PDO;

final class PdoApprovalManagerRelationshipRevalidationQuery implements ApprovalManagerRelationshipRevalidationQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function isStillResponsible(
        int $actorId,
        string $relationshipKind,
        array $assignmentSnapshot,
        DateTimeImmutable $atInstant
    ): bool {
        $snapshotAssignmentId = (int) ($assignmentSnapshot['assignment_id'] ?? 0);
        if ($actorId <= 0 || $snapshotAssignmentId <= 0) {
            return false;
        }

        $managerKind = str_contains($relationshipKind, 'administrative') ? 'administrative' : 'direct';
        $managerUserId = str_starts_with($relationshipKind, 'delegated_')
            ? (int) ($assignmentSnapshot['acting_for_user_id'] ?? 0)
            : $actorId;
        if ($managerUserId <= 0) {
            return false;
        }

        $date = $atInstant->format('Y-m-d');
        $staffStatement = $this->db->prepare(
            'SELECT staff_user_id FROM staff_assignments WHERE id = :assignment_id LIMIT 1'
        );
        $staffStatement->execute([':assignment_id' => $snapshotAssignmentId]);
        $staffUserId = (int) ($staffStatement->fetchColumn() ?: 0);
        if ($staffUserId <= 0) {
            return false;
        }

        $assignmentStatement = $this->db->prepare(
            "SELECT org_unit_id FROM staff_assignments
             WHERE staff_user_id = :staff_user_id
               AND assignment_kind = 'primary'
               AND employment_status IN ('active', 'rehired')
               AND valid_from <= :effective_date
               AND (valid_to IS NULL OR valid_to >= :effective_date_again)
             ORDER BY valid_from DESC, id DESC
             LIMIT 1"
        );
        $assignmentStatement->execute([
            ':staff_user_id' => $staffUserId,
            ':effective_date' => $date,
            ':effective_date_again' => $date,
        ]);
        $orgUnitId = (int) ($assignmentStatement->fetchColumn() ?: 0);
        if ($orgUnitId <= 0) {
            return false;
        }

        $specific = $this->effectiveManager('staff', $staffUserId, $managerKind, $date);
        $manager = $specific ?? $this->effectiveManager('org_unit', $orgUnitId, $managerKind, $date);

        return $manager !== null && (int) $manager['manager_user_id'] === $managerUserId;
    }

    /** @return array<string,mixed>|null */
    private function effectiveManager(string $subjectType, int $subjectId, string $managerKind, string $date): ?array
    {
        $statement = $this->db->prepare(
            "SELECT manager_user_id FROM staff_manager_assignments
             WHERE subject_type = :subject_type
               AND subject_id = :subject_id
               AND manager_kind = :manager_kind
               AND status = 'active'
               AND valid_from <= :effective_date
               AND (valid_to IS NULL OR valid_to >= :effective_date_again)
             ORDER BY priority DESC, valid_from DESC, id DESC
             LIMIT 1"
        );
        $statement->execute([
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':manager_kind' => $managerKind,
            ':effective_date' => $date,
            ':effective_date_again' => $date,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
