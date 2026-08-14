<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use EduCore\Modules\Staff\SpecialistAcademicScopeService;
use PDO;

require_once dirname(__DIR__) . '/Staff/SpecialistAcademicScopeService.php';

final class SpecialistStudentReadService
{
    public function __construct(private PDO $db, private SpecialistAcademicScopeService $scope)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function classes(int $specialistId, int $academicYearId): array
    {
        $classIds = $this->scope->allowedClassIdsForSpecialist($specialistId, $academicYearId);
        if ($classIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->db->prepare("SELECT c.id, c.name, c.grade_id, g.grade_name,
                COUNT(DISTINCT u.id) AS student_count
            FROM classes c
            JOIN grades g ON g.id = c.grade_id
            LEFT JOIN student_enrollments se ON se.class_id = c.id AND se.academic_year_id = ?
            LEFT JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            WHERE c.id IN ({$placeholders})
            GROUP BY c.id, c.name, c.grade_id, g.grade_name, g.grade_order, c.display_order
            ORDER BY g.grade_order, c.display_order, c.name");
        $stmt->execute(array_merge([$academicYearId], $classIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function students(int $specialistId, int $academicYearId, ?int $classId = null): array
    {
        $classIds = $this->scope->allowedClassIdsForSpecialist($specialistId, $academicYearId);
        if ($classId !== null && $classId > 0) {
            $this->scope->assertClassAllowed($specialistId, $academicYearId, $classId);
            $classIds = [$classId];
        }
        if ($classIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->db->prepare("SELECT u.id, u.name, u.status, sp.*, c.name AS class_name, g.grade_name,
                se.class_id AS effective_class_id
            FROM users u
            JOIN student_profiles sp ON sp.user_id = u.id
            JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
            JOIN classes c ON c.id = se.class_id
            LEFT JOIN grades g ON g.id = COALESCE(se.grade_id, c.grade_id)
            WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
              AND se.class_id IN ({$placeholders})
            ORDER BY g.grade_order, c.display_order, u.name");
        $stmt->execute(array_merge([$academicYearId], $classIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{total:int,male:int,female:int,classes:int,grades:int,average_age:float} */
    public function statistics(int $specialistId, int $academicYearId): array
    {
        $students = $this->students($specialistId, $academicYearId);
        $classIds = [];
        $grades = [];
        $male = 0; $female = 0; $ageTotal = 0; $ageCount = 0;
        $today = new \DateTimeImmutable('today');
        foreach ($students as $student) {
            $classIds[(int)$student['effective_class_id']] = true;
            $grades[(string)($student['grade_name'] ?? '')] = true;
            if (($student['gender'] ?? '') === 'male') $male++;
            elseif (($student['gender'] ?? '') === 'female') $female++;
            if (!empty($student['birth_date'])) {
                try { $ageTotal += (new \DateTimeImmutable((string)$student['birth_date']))->diff($today)->y; $ageCount++; } catch (\Throwable $e) {}
            }
        }
        unset($grades['']);
        return ['total' => count($students), 'male' => $male, 'female' => $female, 'classes' => count($classIds), 'grades' => count($grades), 'average_age' => $ageCount ? round($ageTotal / $ageCount, 1) : 0.0];
    }
}
