<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use PDO;
use Throwable;

require_once __DIR__ . '/StaffAcademicScopeService.php';

/** Read contract for the specialist landing dashboard. */
final class SpecialistDashboardQuery
{
    private StaffAcademicScopeService $scope;

    public function __construct(private PDO $db)
    {
        $this->scope = new StaffAcademicScopeService($db);
    }

    /**
     * @return array{scope_rows:array<int,array<string,mixed>>,student_count:int,pending_count:int}
     */
    public function data(int $specialistId, int $academicYearId, string $roleKey = 'specialist'): array
    {
        $scope = $this->scope->scope($specialistId, $academicYearId, $roleKey);
        $classIds = array_values(array_map('intval', $scope['class_ids'] ?? []));
        $scopeRows = [];
        $studentCount = 0;
        if ($academicYearId > 0 && $classIds !== []) {
            $placeholders = implode(',', array_fill(0, count($classIds), '?'));
            $scopeStmt = $this->db->prepare("SELECT c.id, c.name AS class_name, g.id AS grade_id, g.grade_name
                FROM classes c
                JOIN grades g ON g.id = c.grade_id
                WHERE c.id IN ({$placeholders})
                  AND c.status = 'active'
                  AND (c.academic_year_id = ? OR c.academic_year_id IS NULL)
                ORDER BY g.grade_name, c.name");
            $scopeStmt->execute([...$classIds, $academicYearId]);
            $scopeRows = $scopeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $studentStmt = $this->db->prepare("SELECT COUNT(DISTINCT se.student_id)
                FROM student_enrollments se
                JOIN users u ON u.id = se.student_id
                    AND u.role = 'student'
                    AND u.deleted_at IS NULL
                WHERE se.academic_year_id = ?
                  AND se.enrollment_status = 'enrolled'
                  AND se.class_id IN ({$placeholders})");
            $studentStmt->execute([$academicYearId, ...$classIds]);
            $studentCount = (int) $studentStmt->fetchColumn();
        }

        $pendingCount = 0;
        try {
            $pendingStmt = $this->db->prepare("SELECT COUNT(*) FROM student_change_requests
                WHERE specialist_id = ? AND academic_year_id = ? AND status = 'pending'");
            $pendingStmt->execute([$specialistId, $academicYearId]);
            $pendingCount = (int) $pendingStmt->fetchColumn();
        } catch (Throwable $e) {
            $pendingCount = 0;
        }

        return [
            'scope_rows' => $scopeRows,
            'student_count' => $studentCount,
            'pending_count' => $pendingCount,
        ];
    }
}
