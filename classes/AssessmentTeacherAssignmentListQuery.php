<?php

declare(strict_types=1);

require_once __DIR__ . '/StaffEmploymentLifecycleService.php';

/**
 * Read model for the teacher-assignment administration list.
 * It deliberately fetches assignments only for the current DataTables window.
 */
final class AssessmentTeacherAssignmentListQuery
{
    private PDO $db;
    private bool $activationColumnsReady;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $columnStmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME IN (?, ?)'
        );
        $columnStmt->execute(['teacher_subject_assignments', 'requested_active', 'pending_reason']);
        $this->activationColumnsReady = (int) $columnStmt->fetchColumn() === 2;
    }

    public function summary(int $academicYearId): array
    {
        $staffCount = (int)$this->db->query("SELECT COUNT(DISTINCT u.id)
            FROM users u
            JOIN user_role_assignments ura ON ura.user_id = u.id
                AND ura.role_key = 'teacher' AND ura.status = 'active'")->fetchColumn();
        if ($academicYearId <= 0) {
            return ['staff_count' => $staffCount, 'assigned_staff_count' => 0, 'record_count' => 0, 'review_count' => 0, 'pending_count' => 0];
        }

        $activationSelect = $this->activationColumnsReady
            ? 'COALESCE(SUM(can_record = 1 AND is_active = 1), 0) AS record_count,
               COALESCE(SUM(can_review = 1 AND is_active = 1), 0) AS review_count,
               COALESCE(SUM(requested_active = 1 AND is_active = 0), 0) AS pending_count'
            : 'COALESCE(SUM(can_record = 1), 0) AS record_count,
               COALESCE(SUM(can_review = 1), 0) AS review_count,
               0 AS pending_count';
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT teacher_id) AS assigned_staff_count,
                                           {$activationSelect}
                                    FROM teacher_subject_assignments
                                    WHERE academic_year_id = ?");
        $stmt->execute([$academicYearId]);
        return array_merge(['staff_count' => $staffCount], $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    public function load(int $academicYearId, array $request): array
    {
        $draw = max(0, (int)($request['draw'] ?? 0));
        $start = max(0, (int)($request['start'] ?? 0));
        $requestedLength = (int)($request['length'] ?? 50);
        $length = $requestedLength === -1 ? PHP_INT_MAX : max(10, min($requestedLength, 500));
        [$where, $params] = $this->filters($academicYearId, $request, true);

        $total = (int)$this->db->query("SELECT COUNT(DISTINCT u.id)
            FROM users u
            JOIN user_role_assignments ura ON ura.user_id = u.id
                AND ura.role_key = 'teacher' AND ura.status = 'active'")->fetchColumn();
        $countSql = "SELECT COUNT(*)
                     FROM users u
                     LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                     WHERE " . implode(' AND ', $where);
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $filtered = (int)$countStmt->fetchColumn();

        $orderColumns = [
            0 => 'u.id',
            1 => "COALESCE(NULLIF(sp.full_name_ar, ''), u.name)",
            2 => 'sp.job_title',
            3 => 'ura.role_keys',
            4 => 'sp.current_work_status'
        ];
        $orderIndex = (int)($request['order'][0]['column'] ?? 1);
        $orderColumn = $orderColumns[$orderIndex] ?? $orderColumns[1];
        $direction = strtolower((string)($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $limit = $length === PHP_INT_MAX ? '' : ' LIMIT ' . $start . ', ' . $length;

        $staffSql = "SELECT u.id, u.name, u.status AS account_status, u.role, ura.role_keys,
                            COALESCE(NULLIF(sp.full_name_ar, ''), u.name) AS display_name,
                            sp.employee_code, sp.job_title, sp.current_work_status
                      FROM users u
                      LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                      LEFT JOIN (
                          SELECT user_id,
                                 GROUP_CONCAT(role_key ORDER BY is_primary DESC, role_key SEPARATOR ',') AS role_keys
                          FROM user_role_assignments
                          WHERE status = 'active'
                          GROUP BY user_id
                      ) ura ON ura.user_id = u.id
                      WHERE " . implode(' AND ', $where) . "
                     ORDER BY {$orderColumn} {$direction}, u.id ASC{$limit}";
        $staffStmt = $this->db->prepare($staffSql);
        $staffStmt->execute($params);
        $staff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($staff as &$staffRow) {
            $staffRow['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($staffRow['job_title'] ?? null);
        }
        unset($staffRow);

        $assignments = $this->assignmentsForStaff($academicYearId, array_column($staff, 'id'));
        return ['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'staff' => $staff, 'assignments' => $assignments];
    }

    private function filters(int $academicYearId, array $request, bool $includeSearch): array
    {
        $where = ["EXISTS (
            SELECT 1 FROM user_role_assignments ura
            WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active'
        )"];
        $params = [];
        $jobTitle = trim((string)($request['job_title'] ?? ''));
        if ($jobTitle !== '') {
            $jobTitleValues = StaffEmploymentLifecycleService::jobTitleFilterValues($jobTitle);
            if ($jobTitleValues === []) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'sp.job_title IN (' . implode(',', array_fill(0, count($jobTitleValues), '?')) . ')';
                array_push($params, ...$jobTitleValues);
            }
        }
        foreach (['stage_id' => 'g.stage_id', 'grade_id' => 'tsa.grade_id', 'class_id' => 'tsa.class_id'] as $key => $column) {
            $value = (int)($request[$key] ?? 0);
            if ($value > 0) {
                $join = $key === 'stage_id' ? ' JOIN grades g ON g.id = tsa.grade_id' : '';
                $where[] = "EXISTS (SELECT 1 FROM teacher_subject_assignments tsa{$join} WHERE tsa.academic_year_id = ? AND tsa.teacher_id = u.id AND {$column} = ?)";
                $params[] = $academicYearId;
                $params[] = $value;
            }
        }
        $search = trim((string)($request['search']['value'] ?? $request['search'] ?? ''));
        if ($includeSearch && $search !== '') {
            $where[] = "(u.name LIKE ? OR sp.full_name_ar LIKE ? OR sp.employee_code LIKE ? OR sp.job_title LIKE ?
                         OR EXISTS (SELECT 1 FROM teacher_subject_assignments tsa JOIN subjects s ON s.id = tsa.subject_id
                                    WHERE tsa.academic_year_id = ? AND tsa.teacher_id = u.id AND s.name LIKE ?))";
            $term = '%' . $search . '%';
            $params = array_merge($params, [$term, $term, $term, $term, $academicYearId, $term]);
        }
        return [$where, $params];
    }

    private function assignmentsForStaff(int $academicYearId, array $staffIds): array
    {
        if ($academicYearId <= 0 || $staffIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $staffIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT tsa.*, s.name AS subject_name, c.name AS class_name,
                                           g.stage_id, g.grade_name
                                    FROM teacher_subject_assignments tsa
                                    JOIN subjects s ON s.id = tsa.subject_id
                                    LEFT JOIN classes c ON c.id = tsa.class_id
                                    LEFT JOIN grades g ON g.id = tsa.grade_id
                                    WHERE tsa.academic_year_id = ? AND tsa.teacher_id IN ({$placeholders})
                                    ORDER BY tsa.teacher_id, s.name, g.grade_order, c.name");
        $stmt->execute(array_merge([$academicYearId], $ids));
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $staffId = (int)$row['teacher_id'];
            if (!isset($map[$staffId])) {
                $map[$staffId] = [
                    'subject_ids' => [], 'subject_names' => [], 'class_ids' => [], 'class_names' => [],
                    'class_groups' => [], 'grade_ids' => [], 'whole_grade_ids' => [], 'stage_ids' => [],
                    'can_record' => 0, 'can_review' => 0, 'is_active' => 0, 'requested_active' => 0,
                    'pending_count' => 0,
                ];
            }
            $assignment =& $map[$staffId];
            $assignment['subject_ids'][(int)$row['subject_id']] = (int)$row['subject_id'];
            $assignment['subject_names'][(int)$row['subject_id']] = (string)$row['subject_name'];
            if (!empty($row['class_id'])) {
                $classId = (int)$row['class_id'];
                $gradeId = (int)($row['grade_id'] ?? 0);
                $className = trim((string)($row['class_name'] ?? ''));
                if ($className === '') {
                    $className = 'فصل ' . $classId;
                }
                $assignment['class_ids'][$classId] = $classId;
                $assignment['class_names'][$classId] = $className;
                if (!isset($assignment['class_groups'][$gradeId])) {
                    $assignment['class_groups'][$gradeId] = [
                        'grade_id' => $gradeId,
                        'grade_name' => trim((string)($row['grade_name'] ?? '')) ?: 'صف غير محدد',
                        'classes' => [],
                    ];
                }
                $assignment['class_groups'][$gradeId]['classes'][$classId] = $className;
            } elseif (!empty($row['grade_id'])) {
                $gradeId = (int) $row['grade_id'];
                if (!isset($assignment['class_groups'][$gradeId])) {
                    $assignment['class_groups'][$gradeId] = [
                        'grade_id' => $gradeId,
                        'grade_name' => trim((string)($row['grade_name'] ?? '')) ?: 'صف غير محدد',
                        'classes' => [],
                    ];
                }
                $assignment['whole_grade_ids'][$gradeId] = $gradeId;
                $assignment['class_groups'][$gradeId]['classes']['whole-grade'] = 'الصف بالكامل';
            }
            if (!empty($row['grade_id'])) { $assignment['grade_ids'][(int)$row['grade_id']] = (int)$row['grade_id']; }
            if (!empty($row['stage_id'])) { $assignment['stage_ids'][(int)$row['stage_id']] = (int)$row['stage_id']; }
            $assignment['can_record'] = ($assignment['can_record'] || !empty($row['can_record'])) ? 1 : 0;
            $assignment['can_review'] = ($assignment['can_review'] || !empty($row['can_review'])) ? 1 : 0;
            $assignment['is_active'] = ($assignment['is_active'] || !empty($row['is_active'])) ? 1 : 0;
            $requestedActive = $this->activationColumnsReady
                ? !empty($row['requested_active'])
                : !empty($row['is_active']);
            $assignment['requested_active'] = ($assignment['requested_active'] || $requestedActive) ? 1 : 0;
            if ($requestedActive && empty($row['is_active'])) {
                $assignment['pending_count']++;
            }
            unset($assignment);
        }
        return $map;
    }
}
