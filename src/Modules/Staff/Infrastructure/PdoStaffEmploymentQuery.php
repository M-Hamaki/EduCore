<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\StaffEmploymentQuery;
use PDO;

require_once dirname(__DIR__, 4) . '/classes/StaffEmploymentLifecycleService.php';

/**
 * PDO implementation of StaffEmploymentQuery — owned by the StaffHr module.
 */
final class PdoStaffEmploymentQuery implements StaffEmploymentQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function activeContractOf(int $staffId, ?string $atDate = null): ?array
    {
        $dateClause = $atDate
            ? 'AND (COALESCE(sp.latest_hire_date, sp.hire_date, sp.contract_start) IS NULL OR COALESCE(sp.latest_hire_date, sp.hire_date, sp.contract_start) <= ?)
               AND (sp.contract_end IS NULL OR sp.contract_end >= ?)
               AND (sp.last_working_day IS NULL OR sp.last_working_day >= ?)'
            : '';
        $params = [$staffId];
        if ($atDate) { $params[] = $atDate; $params[] = $atDate; $params[] = $atDate; }

        $stmt = $this->db->prepare(
            "SELECT sp.user_id AS staff_id, sp.employee_code, sp.job_title, sp.department,
                    COALESCE(sp.latest_hire_date, sp.hire_date, sp.contract_start) AS hire_date,
                    sp.current_work_status, u.status
             FROM staff_profiles sp
             JOIN users u ON u.id = sp.user_id
             WHERE sp.user_id = ? AND u.status = 'active'
               AND COALESCE(sp.current_work_status, 'on_duty') <> 'off_duty'
             $dateClause
             LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return null; }

        $row['job_title'] = \StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null);
        $row['is_active'] = ($row['status'] ?? '') === 'active'
            && in_array(($row['current_work_status'] ?? ''), ['', 'active', 'on_duty'], true);
        return $row;
    }

    public function relationshipsOf(int $staffId): array
    {
        $stmt = $this->db->prepare(
            'SELECT sg.student_id, sg.relationship AS relationship_type
             FROM student_guardians sg
             JOIN users u ON u.id = sg.student_id AND u.role = ? AND u.status = ?
             JOIN staff_profiles sp ON sp.user_id = ?
             WHERE (sg.national_id IS NOT NULL AND sg.national_id <> ? AND sg.national_id = sp.national_id)
                OR (sg.phone_primary IS NOT NULL AND sg.phone_primary <> ? AND sg.phone_primary = sp.phone_mobile)'
        );
        $stmt->execute(['student', 'active', $staffId, '', '']);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($students as $s) {
            $result[] = [
                'staff_id' => $staffId,
                'student_id' => (int) $s['student_id'],
                'relationship_type' => (string) $s['relationship_type'],
                'is_active' => true,
            ];
        }
        return $result;
    }

    public function documentedRelationshipToStudent(int $staffId, int $studentId): ?array
    {
        foreach ($this->relationshipsOf($staffId) as $relationship) {
            if ((int) $relationship['student_id'] === $studentId) {
                return $relationship;
            }
        }
        return null;
    }
}
