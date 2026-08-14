<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance;

use EduCore\Modules\Staff\SpecialistAcademicScopeService;
use PDO;

require_once dirname(__DIR__) . '/Staff/SpecialistAcademicScopeService.php';

final class SpecialistAttendanceReadService
{
    public function __construct(private PDO $db, private SpecialistAcademicScopeService $scope)
    {
    }

    /** @return array{classes:array<int,array<string,mixed>>,records:array<int,array<string,mixed>>,stats:array<string,int|float>} */
    public function report(int $specialistId, int $academicYearId, array $filters = []): array
    {
        $classIds = $this->scope->allowedClassIdsForSpecialist($specialistId, $academicYearId);
        $selectedClassId = max(0, (int)($filters['class_id'] ?? 0));
        if ($selectedClassId > 0) {
            $this->scope->assertClassAllowed($specialistId, $academicYearId, $selectedClassId);
            $classIds = [$selectedClassId];
        }
        $classes = $this->classes($specialistId, $academicYearId);
        if ($academicYearId <= 0 || $classIds === []) {
            return ['classes' => $classes, 'records' => [], 'stats' => $this->emptyStats()];
        }

        $dateFrom = $this->date((string)($filters['date_from'] ?? ''), date('Y-m-01'));
        $dateTo = $this->date((string)($filters['date_to'] ?? ''), date('Y-m-d'));
        if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        $status = (string)($filters['status'] ?? '');
        if (!in_array($status, ['', 'present', 'absent', 'late', 'excused'], true)) $status = '';

        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $where = ["a.academic_year_id = ?", "a.class_id IN ({$placeholders})", 'a.attendance_date BETWEEN ? AND ?', 'a.deleted_at IS NULL'];
        $params = array_merge([$academicYearId], $classIds, [$dateFrom, $dateTo]);
        if ($status !== '') {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $statsStmt = $this->db->prepare("SELECT COUNT(*) total_records, COUNT(DISTINCT a.student_id) total_students,
            COUNT(DISTINCT a.class_id) total_classes,
            SUM(a.status='present') present_count, SUM(a.status='absent') absent_count,
            SUM(a.status='late') late_count, SUM(a.status='excused') excused_count
            FROM attendance a WHERE {$whereSql}");
        $statsStmt->execute($params);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($stats['total_records'] ?? 0);
        $normalizedStats = [
            'total_records' => $total,
            'total_students' => (int)($stats['total_students'] ?? 0),
            'total_classes' => (int)($stats['total_classes'] ?? 0),
            'present_count' => (int)($stats['present_count'] ?? 0),
            'absent_count' => (int)($stats['absent_count'] ?? 0),
            'late_count' => (int)($stats['late_count'] ?? 0),
            'excused_count' => (int)($stats['excused_count'] ?? 0),
            'attendance_rate' => $total > 0 ? round(((int)($stats['present_count'] ?? 0) / $total) * 100, 1) : 0.0,
        ];

        $recordsStmt = $this->db->prepare("SELECT a.attendance_date, a.status, a.notes,
            u.id AS student_id, u.name AS student_name, c.id AS class_id, c.name AS class_name,
            g.grade_name, recorder.name AS recorder_name
            FROM attendance a
            JOIN users u ON u.id = a.student_id AND u.role = 'student'
            JOIN classes c ON c.id = a.class_id
            LEFT JOIN grades g ON g.id = c.grade_id
            LEFT JOIN users recorder ON recorder.id = a.recorded_by
            WHERE {$whereSql}
            ORDER BY a.attendance_date DESC, g.grade_order, c.display_order, u.name LIMIT 500");
        $recordsStmt->execute($params);
        return ['classes' => $classes, 'records' => $recordsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'stats' => $normalizedStats];
    }

    /** @return array<int,array<string,mixed>> */
    private function classes(int $specialistId, int $academicYearId): array
    {
        $ids = $this->scope->allowedClassIdsForSpecialist($specialistId, $academicYearId);
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT c.id, c.name, c.grade_id, g.grade_name
            FROM classes c JOIN grades g ON g.id = c.grade_id WHERE c.id IN ({$marks})
            ORDER BY g.grade_order, c.display_order, c.name");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function date(string $value, string $default): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
    }

    /** @return array<string,int|float> */
    private function emptyStats(): array
    {
        return ['total_records'=>0,'total_students'=>0,'total_classes'=>0,'present_count'=>0,'absent_count'=>0,'late_count'=>0,'excused_count'=>0,'attendance_rate'=>0.0];
    }
}
