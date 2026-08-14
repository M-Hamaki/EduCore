<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Organization;

use EduCore\Modules\Staff\Contracts\StaffOrganizationAdministrationReadRepository;
use PDO;

/**
 * PDO read adapter owned by Staff organization. It purposefully does not
 * return profile fields, audit payloads, free-text reasons, or attachments.
 */
final class PdoStaffOrganizationAdministrationReadRepository implements StaffOrganizationAdministrationReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function dashboard(int $limit): array
    {
        return [
            'org_units' => $this->rows(
                'SELECT id, code, name, unit_type, parent_id, valid_from, valid_to, status
                 FROM staff_org_units
                 ORDER BY valid_from DESC, id DESC
                 LIMIT :limit',
                $limit
            ),
            'job_titles' => $this->rows(
                'SELECT id, code, name, active_from, active_to, status
                 FROM staff_job_titles
                 ORDER BY active_from DESC, id DESC
                 LIMIT :limit',
                $limit
            ),
            'policy_groups' => $this->rows(
                'SELECT id, code, name, purpose, valid_from, valid_to, status
                 FROM staff_policy_groups
                 ORDER BY valid_from DESC, id DESC
                 LIMIT :limit',
                $limit
            ),
            'group_memberships' => $this->rows(
                'SELECT membership.id, membership.group_id, group_record.name AS group_name,
                        membership.staff_user_id, account.name AS staff_name,
                        membership.valid_from, membership.valid_to, membership.status
                 FROM staff_policy_group_memberships membership
                 INNER JOIN staff_policy_groups group_record ON group_record.id = membership.group_id
                 LEFT JOIN users account ON account.id = membership.staff_user_id
                 ORDER BY membership.valid_from DESC, membership.id DESC
                 LIMIT :limit',
                $limit
            ),
            'manager_assignments' => $this->rows(
                "SELECT manager.id, manager.subject_type, manager.subject_id, manager.manager_user_id,
                        manager.manager_kind, manager.priority, manager.valid_from, manager.valid_to, manager.status,
                        subject_account.name AS subject_staff_name, subject_unit.name AS subject_unit_name,
                        manager_account.name AS manager_name
                 FROM staff_manager_assignments manager
                 LEFT JOIN users subject_account
                    ON manager.subject_type = 'staff' AND subject_account.id = manager.subject_id
                 LEFT JOIN staff_org_units subject_unit
                    ON manager.subject_type = 'org_unit' AND subject_unit.id = manager.subject_id
                 LEFT JOIN users manager_account ON manager_account.id = manager.manager_user_id
                 ORDER BY manager.valid_from DESC, manager.id DESC
                 LIMIT :limit",
                $limit
            ),
            'assignments' => $this->rows(
                'SELECT assignment.id, assignment.staff_user_id, account.name AS staff_name,
                        assignment.org_unit_id, unit.name AS org_unit_name,
                        assignment.job_title_id, title.name AS job_title_name,
                        assignment.assignment_kind, assignment.employment_status,
                        assignment.work_fraction, assignment.valid_from, assignment.valid_to, assignment.version
                 FROM staff_assignments assignment
                 LEFT JOIN users account ON account.id = assignment.staff_user_id
                 INNER JOIN staff_org_units unit ON unit.id = assignment.org_unit_id
                 INNER JOIN staff_job_titles title ON title.id = assignment.job_title_id
                 ORDER BY assignment.valid_from DESC, assignment.id DESC
                 LIMIT :limit',
                $limit
            ),
            'staff' => $this->rows(
                'SELECT profile.user_id AS id, account.name, account.status AS account_status
                 FROM staff_profiles profile
                 INNER JOIN users account ON account.id = profile.user_id
                 ORDER BY account.name, profile.user_id
                 LIMIT :limit',
                min(200, max($limit, 100))
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, int $limit): array
    {
        $statement = $this->db->prepare($sql);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
