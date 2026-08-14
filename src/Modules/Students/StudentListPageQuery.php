<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use AcademicYear;
use PDO;
use User;

final class StudentListPageQuery
{
    private const PER_PAGE = 25;

    private PDO $db;
    private User $users;

    public function __construct(PDO $db, User $users)
    {
        $this->db = $db;
        $this->users = $users;
    }

    public function load(
        array $query,
        string $scope,
        ?array $allowedClassIds = null
    ): array
    {
        $stageIds = isset($query['stage_ids']) && is_array($query['stage_ids'])
            ? array_filter(array_map('intval', $query['stage_ids']))
            : (!empty($query['stage_id']) ? [(int)$query['stage_id']] : []);

        $gradeIds = isset($query['grade_ids']) && is_array($query['grade_ids'])
            ? array_filter(array_map('intval', $query['grade_ids']))
            : (!empty($query['grade_id']) ? [(int)$query['grade_id']] : []);

        $classIds = isset($query['class_ids']) && is_array($query['class_ids'])
            ? array_filter(array_map('intval', $query['class_ids']))
            : (!empty($query['class_id']) ? [(int)$query['class_id']] : []);

        $stageIds = array_values(array_unique($stageIds));
        $gradeIds = array_values(array_unique($gradeIds));
        $classIds = array_values(array_unique($classIds));
        $requestedStageIds = $stageIds;
        $requestedGradeIds = $gradeIds;
        $requestedClassIds = $classIds;
        if ($allowedClassIds !== null) {
            $allowedClassIds = array_values(array_unique(array_filter(array_map('intval', $allowedClassIds))));
            $classIds = array_values(array_intersect($classIds, $allowedClassIds));
        }

        $page = max(1, (int) ($query['spage'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $total = 0;
        $students = [];
        $action = $query['action'] ?? '';
        $listMode = $action !== 'view' && ($scope === 'current' || ($action !== 'add' && $action !== 'edit'));

        $currentAcademicYearId = AcademicYear::currentId($this->db);
        $classesStmt = $this->db->prepare('SELECT c.id, c.name, c.grade_id, g.grade_name, g.stage_id, s.stage_name
             FROM classes c
             LEFT JOIN grades g ON c.grade_id = g.id
             LEFT JOIN stages s ON g.stage_id = s.id
             WHERE c.status = \'active\'
               AND (? <= 0 OR c.academic_year_id = ?)
             ORDER BY s.id, g.id, c.name');
        $classesStmt->execute([$currentAcademicYearId, $currentAcademicYearId]);
        $classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);
        $scopeGrades = $this->db->query("SELECT g.id, g.grade_name, g.stage_id, s.stage_name
            FROM grades g
            JOIN stages s ON s.id = g.stage_id
            WHERE g.status = 'active' AND s.status = 'active' AND 1 = 1
            ORDER BY s.stage_order, g.grade_order, g.id")->fetchAll(PDO::FETCH_ASSOC);

        if ($allowedClassIds !== null) {
            $allowedLookup = array_fill_keys($allowedClassIds, true);
            $classes = array_values(array_filter(
                $classes,
                static fn(array $class): bool => isset($allowedLookup[(int) $class['id']])
            ));
            $allowedGradeIds = array_values(array_unique(array_filter(array_map(
                static fn(array $class): int => (int) ($class['grade_id'] ?? 0),
                $classes
            ))));
            $allowedStageIds = array_values(array_unique(array_filter(array_map(
                static fn(array $class): int => (int) ($class['stage_id'] ?? 0),
                $classes
            ))));
            $scopeGrades = array_values(array_filter(
                $scopeGrades,
                static fn(array $grade): bool => in_array((int) $grade['id'], $allowedGradeIds, true)
            ));
            $gradeIds = array_values(array_intersect($gradeIds, $allowedGradeIds));
            $stageIds = array_values(array_intersect($stageIds, $allowedStageIds));
        }

        $filterDeniesAll = $allowedClassIds !== null && (
            $allowedClassIds === []
            || ($requestedClassIds !== [] && $classIds === [])
            || ($requestedGradeIds !== [] && $gradeIds === [])
            || ($requestedStageIds !== [] && $stageIds === [])
        );
        if ($listMode && !$filterDeniesAll) {
            $this->users->getStudentsPaginated(
                $classIds,
                $allowedClassIds,
                0,
                0,
                $total,
                $gradeIds,
                $stageIds,
                $scope
            );
        }

        $stages = [];
        $grades = [];
        foreach ($scopeGrades as $grade) {
            $stages[$grade['stage_id']] = $grade['stage_name'];
            $grades[$grade['id']] = ['name' => $grade['grade_name'], 'stage_id' => $grade['stage_id']];
        }
        foreach ($classes as $class) {
            if (!empty($class['stage_id'])) {
                $stages[$class['stage_id']] = $class['stage_name'];
            }
            if (!empty($class['grade_id'])) {
                $grades[$class['grade_id']] = ['name' => $class['grade_name'], 'stage_id' => $class['stage_id']];
            }
        }

        return [
            'filter_class_id' => !empty($classIds) ? $classIds[0] : null,
            'filter_grade_id' => !empty($gradeIds) ? $gradeIds[0] : null,
            'filter_stage_id' => !empty($stageIds) ? $stageIds[0] : null,
            'filter_class_ids' => $classIds,
            'filter_grade_ids' => $gradeIds,
            'filter_stage_ids' => $stageIds,
            'students_use_datatables' => false,
            'students_per_page' => self::PER_PAGE,
            'students_page' => $page,
            'students_offset' => $offset,
            'students_total_count' => $total,
            'students_total_pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'students' => $students,
            'classes' => $classes,
            'scope_grades' => $scopeGrades,
            'stages' => $stages,
            'grades' => $grades,
            'page_action' => $action,
            'is_list_mode' => $listMode,
        ];
    }
}
