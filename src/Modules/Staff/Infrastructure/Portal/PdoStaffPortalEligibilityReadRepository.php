<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Portal;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityReadRepository;
use PDO;

/**
 * Read-only evidence adapter for the Staff self-service portal.
 *
 * The role selected in a browser session is intentionally not consulted. The
 * manager query mirrors the effective hierarchy rule: a Staff-specific scope
 * wins over an organizational scope, and competing top-priority managers fail
 * closed rather than exposing an approval inbox optimistically.
 */
final class PdoStaffPortalEligibilityReadRepository implements StaffPortalEligibilityReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function hasActiveStaffProfile(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $statement = $this->db->prepare(
            "SELECT 1
             FROM users account
             INNER JOIN staff_profiles profile ON profile.user_id = account.id
             WHERE account.id = :user_id
               AND account.status = 'active'
             LIMIT 1"
        );
        $statement->execute([':user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function activeManagerScopeVersion(int $managerUserId, DateTimeImmutable $atDate): ?int
    {
        if ($managerUserId <= 0) {
            return null;
        }

        $date = $atDate->format('Y-m-d');
        $statement = $this->db->prepare(
            "SELECT manager_assignment.id
             FROM staff_manager_assignments manager_assignment
             INNER JOIN staff_assignments candidate
                ON (
                    (manager_assignment.subject_type = 'staff'
                        AND manager_assignment.subject_id = candidate.staff_user_id)
                    OR
                    (manager_assignment.subject_type = 'org_unit'
                        AND manager_assignment.subject_id = candidate.org_unit_id)
                )
             INNER JOIN users candidate_account
                ON candidate_account.id = candidate.staff_user_id
               AND candidate_account.status = 'active'
             INNER JOIN staff_profiles candidate_profile
                ON candidate_profile.user_id = candidate.staff_user_id
             WHERE manager_assignment.manager_user_id = :manager_user_id
               AND manager_assignment.manager_kind IN ('direct', 'administrative')
               AND manager_assignment.status = 'active'
               AND manager_assignment.valid_from <= :manager_from
               AND (manager_assignment.valid_to IS NULL OR manager_assignment.valid_to >= :manager_to)
               AND candidate.assignment_kind = 'primary'
               AND candidate.employment_status IN ('active', 'rehired')
               AND candidate.valid_from <= :candidate_from
               AND (candidate.valid_to IS NULL OR candidate.valid_to >= :candidate_to)
               AND candidate.staff_user_id <> :manager_excluded
               AND NOT EXISTS (
                    SELECT 1
                    FROM staff_assignments conflicting_primary
                    WHERE conflicting_primary.staff_user_id = candidate.staff_user_id
                      AND conflicting_primary.assignment_kind = 'primary'
                      AND conflicting_primary.id <> candidate.id
                      AND conflicting_primary.valid_from <= :conflict_from
                      AND (conflicting_primary.valid_to IS NULL OR conflicting_primary.valid_to >= :conflict_to)
               )
               AND (
                    (
                        manager_assignment.subject_type = 'staff'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM staff_manager_assignments competing_staff_manager
                            WHERE competing_staff_manager.subject_type = 'staff'
                              AND competing_staff_manager.subject_id = candidate.staff_user_id
                              AND competing_staff_manager.manager_kind = manager_assignment.manager_kind
                              AND competing_staff_manager.status = 'active'
                              AND competing_staff_manager.valid_from <= :staff_competing_from
                              AND (competing_staff_manager.valid_to IS NULL
                                   OR competing_staff_manager.valid_to >= :staff_competing_to)
                              AND (
                                  competing_staff_manager.priority > manager_assignment.priority
                                  OR (
                                      competing_staff_manager.priority = manager_assignment.priority
                                      AND competing_staff_manager.manager_user_id <> manager_assignment.manager_user_id
                                  )
                              )
                        )
                    )
                    OR
                    (
                        manager_assignment.subject_type = 'org_unit'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM staff_manager_assignments staff_specific_manager
                            WHERE staff_specific_manager.subject_type = 'staff'
                              AND staff_specific_manager.subject_id = candidate.staff_user_id
                              AND staff_specific_manager.manager_kind = manager_assignment.manager_kind
                              AND staff_specific_manager.status = 'active'
                              AND staff_specific_manager.valid_from <= :staff_specific_from
                              AND (staff_specific_manager.valid_to IS NULL
                                   OR staff_specific_manager.valid_to >= :staff_specific_to)
                        )
                        AND NOT EXISTS (
                            SELECT 1
                            FROM staff_manager_assignments competing_unit_manager
                            WHERE competing_unit_manager.subject_type = 'org_unit'
                              AND competing_unit_manager.subject_id = candidate.org_unit_id
                              AND competing_unit_manager.manager_kind = manager_assignment.manager_kind
                              AND competing_unit_manager.status = 'active'
                              AND competing_unit_manager.valid_from <= :unit_competing_from
                              AND (competing_unit_manager.valid_to IS NULL
                                   OR competing_unit_manager.valid_to >= :unit_competing_to)
                              AND (
                                  competing_unit_manager.priority > manager_assignment.priority
                                  OR (
                                      competing_unit_manager.priority = manager_assignment.priority
                                      AND competing_unit_manager.manager_user_id <> manager_assignment.manager_user_id
                                  )
                              )
                        )
                    )
               )
             ORDER BY manager_assignment.id
             LIMIT 1"
        );
        $statement->execute([
            ':manager_user_id' => $managerUserId,
            ':manager_from' => $date,
            ':manager_to' => $date,
            ':candidate_from' => $date,
            ':candidate_to' => $date,
            ':manager_excluded' => $managerUserId,
            ':conflict_from' => $date,
            ':conflict_to' => $date,
            ':staff_competing_from' => $date,
            ':staff_competing_to' => $date,
            ':staff_specific_from' => $date,
            ':staff_specific_to' => $date,
            ':unit_competing_from' => $date,
            ':unit_competing_to' => $date,
        ]);
        $managerAssignmentId = $statement->fetchColumn();

        return $managerAssignmentId === false ? null : (int) $managerAssignmentId;
    }
}
