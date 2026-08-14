<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\StaffFinanceReadQuery;
use PDO;

require_once dirname(__DIR__, 4) . '/classes/StaffEmploymentLifecycleService.php';

final class PdoStaffFinanceReadQuery implements StaffFinanceReadQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function staff(int $staffId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.role, u.status, sp.current_work_status,
                    COALESCE(NULLIF(sp.full_name_ar, ''), u.name) AS display_name,
                    sp.employee_code, sp.job_title
             FROM users u
             LEFT JOIN staff_profiles sp ON sp.user_id = u.id
             WHERE u.id = ? AND (u.role IS NULL OR u.role <> 'student')
             LIMIT 1"
        );
        $stmt->execute([$staffId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['job_title'] = \StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null);
        return $row;
    }
}
