<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Infrastructure;

use EduCore\Modules\Students\Contracts\StudentEnrollmentQuery;
use PDO;

/**
 * PDO implementation of StudentEnrollmentQuery — owned by the Students module.
 */
final class PdoStudentEnrollmentQuery implements StudentEnrollmentQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function enrollmentOf(int $studentId, int $academicYearId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT se.id, se.student_id, se.class_id, c.grade_id, g.stage_id,
                    se.enrollment_date, se.enrollment_status
             FROM student_enrollments se
             LEFT JOIN classes c ON c.id = se.class_id
             LEFT JOIN grades g ON g.id = c.grade_id
             WHERE se.student_id = ? AND se.academic_year_id = ?
             LIMIT 1'
        );
        $stmt->execute([$studentId, $academicYearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function familyGroupOf(int $studentId, int $academicYearId): array
    {
        // Traverse the confirmed sibling graph so every member receives the same stable family group.
        $stmt = $this->db->prepare(
            'WITH RECURSIVE family (student_id) AS (
                 SELECT ?
                 UNION DISTINCT
                 SELECT CASE WHEN ss.student_id = f.student_id THEN ss.sibling_id ELSE ss.student_id END
                 FROM student_siblings ss
                 JOIN family f ON ss.student_id = f.student_id OR ss.sibling_id = f.student_id
                 WHERE ss.confirmed = 1
             )
             SELECT f.student_id, se.enrollment_date
             FROM family f
             JOIN users u ON u.id = f.student_id AND u.role = ? AND u.status = ?
             JOIN student_enrollments se ON se.student_id = f.student_id AND se.academic_year_id = ?
             ORDER BY se.enrollment_date ASC, f.student_id ASC'
        );
        $stmt->execute([$studentId, 'student', 'active', $academicYearId]);
        return array_map(static function (array $row): array {
            $row['student_id'] = (int) $row['student_id'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
