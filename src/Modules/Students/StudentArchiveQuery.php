<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use PDO;
use RuntimeException;

final class StudentArchiveQuery
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function load(array $query): array
    {
        $this->assertSchemaReady();
        $search = trim((string) ($query['search'] ?? ''));
        $params = [];
        $where = "u.role = 'student' AND u.deleted_at IS NOT NULL";
        if ($search !== '') {
            $where .= ' AND (u.name LIKE ? OR u.username LIKE ? OR sp.student_code LIKE ?)';
            $term = '%' . $search . '%';
            $params = [$term, $term, $term];
        }

        $sql = "SELECT u.id, u.name, u.username, u.status, u.deleted_at, u.archived_by,
                       u.archive_reason, u.status_before_archive,
                       sp.student_code, sp.enrollment_status AS profile_enrollment_status,
                       actor.name AS archived_by_name,
                       se.enrollment_status, ay.name AS academic_year, c.name AS class_name,
                       g.grade_name, s.stage_name
                FROM users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                LEFT JOIN users actor ON actor.id = u.archived_by
                LEFT JOIN student_enrollments se ON se.id = (
                    SELECT se2.id FROM student_enrollments se2
                    WHERE se2.student_id = u.id
                    ORDER BY se2.academic_year_id DESC, se2.id DESC LIMIT 1
                )
                LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
                LEFT JOIN classes c ON c.id = COALESCE(se.class_id, u.class_id)
                LEFT JOIN grades g ON g.id = COALESCE(se.grade_id, c.grade_id)
                LEFT JOIN stages s ON s.id = COALESCE(se.stage_id, g.stage_id)
                WHERE $where
                ORDER BY u.deleted_at DESC, u.id DESC
                LIMIT 500";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stats = $this->db->query(
            "SELECT COUNT(*) AS total,
                    SUM(deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent,
                    SUM(status_before_archive = 'active') AS previously_active
             FROM users WHERE role = 'student' AND deleted_at IS NOT NULL"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'students' => $students,
            'search' => $search,
            'stats' => [
                'total' => (int) ($stats['total'] ?? 0),
                'recent' => (int) ($stats['recent'] ?? 0),
                'previously_active' => (int) ($stats['previously_active'] ?? 0),
            ],
        ];
    }

    /**
     * Provides an immutable archive window for DataTables without loading the
     * entire archive into the rendered page.
     */
    public function loadDataTable(array $request): array
    {
        $this->assertSchemaReady();
        $draw = max(0, (int)($request['draw'] ?? 0));
        $start = max(0, (int)($request['start'] ?? 0));
        $requestedLength = (int)($request['length'] ?? 50);
        $length = $requestedLength === -1 ? PHP_INT_MAX : max(10, min($requestedLength, 500));
        [$where, $params] = $this->archiveFilters($request);

        $total = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND deleted_at IS NOT NULL")->fetchColumn();
        $from = " FROM users u
                  LEFT JOIN student_profiles sp ON sp.user_id = u.id
                  LEFT JOIN users actor ON actor.id = u.archived_by
                  LEFT JOIN student_enrollments se ON se.id = (
                      SELECT se2.id FROM student_enrollments se2
                      WHERE se2.student_id = u.id
                      ORDER BY se2.academic_year_id DESC, se2.id DESC LIMIT 1
                  )
                  LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
                  LEFT JOIN classes c ON c.id = COALESCE(se.class_id, u.class_id)
                  LEFT JOIN grades g ON g.id = COALESCE(se.grade_id, c.grade_id)
                  LEFT JOIN stages s ON s.id = COALESCE(se.stage_id, g.stage_id) ";
        $countStmt = $this->db->prepare('SELECT COUNT(*)' . $from . ' WHERE ' . implode(' AND ', $where));
        $countStmt->execute($params);
        $filtered = (int)$countStmt->fetchColumn();

        $orderColumns = [0 => 'u.id', 1 => 'sp.student_code', 2 => 'u.name', 3 => 'c.name', 4 => 'se.enrollment_status', 5 => 'u.deleted_at', 6 => 'u.archive_reason'];
        $orderIndex = (int)($request['order'][0]['column'] ?? 5);
        $orderColumn = $orderColumns[$orderIndex] ?? 'u.deleted_at';
        $direction = strtolower((string)($request['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $limit = $length === PHP_INT_MAX ? '' : ' LIMIT ' . $start . ', ' . $length;
        $sql = "SELECT u.id, u.name, u.username, u.status, u.deleted_at, u.archived_by,
                       u.archive_reason, u.status_before_archive,
                       sp.student_code, sp.enrollment_status AS profile_enrollment_status,
                       actor.name AS archived_by_name,
                       se.enrollment_status, ay.name AS academic_year, c.name AS class_name
                {$from}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderColumn} {$direction}, u.id DESC{$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return ['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'students' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    public function summary(array $query = []): array
    {
        $this->assertSchemaReady();
        $stats = $this->db->query(
            "SELECT COUNT(*) AS total,
                    SUM(deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent,
                    SUM(status_before_archive = 'active') AS previously_active
             FROM users WHERE role = 'student' AND deleted_at IS NOT NULL"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'search' => trim((string)($query['search'] ?? '')),
            'stats' => [
                'total' => (int)($stats['total'] ?? 0),
                'recent' => (int)($stats['recent'] ?? 0),
                'previously_active' => (int)($stats['previously_active'] ?? 0)
            ]
        ];
    }

    /** @return array{0: array<int, string>, 1: array<int, string>} */
    private function archiveFilters(array $request): array
    {
        $where = ["u.role = 'student'", 'u.deleted_at IS NOT NULL'];
        $params = [];
        foreach (['archive_search', 'search'] as $key) {
            $value = $key === 'search' ? ($request['search']['value'] ?? '') : ($request[$key] ?? '');
            $value = trim((string)$value);
            if ($value !== '') {
                $where[] = '(u.name LIKE ? OR u.username LIKE ? OR sp.student_code LIKE ?)';
                $term = '%' . $value . '%';
                array_push($params, $term, $term, $term);
            }
        }
        // فلتر الحالة الأكاديمية (متعدد)
        $validEnrollments = ['enrolled', 'graduated', 'transferred', 'withdrawn'];
        $enrollmentStatuses = array_values(array_filter(
            (array)($request['filter_enrollment_status[]'] ?? $request['filter_enrollment_status'] ?? []),
            static fn($v) => in_array(trim((string)$v), $validEnrollments, true)
        ));
        if (!empty($enrollmentStatuses)) {
            $placeholders = implode(',', array_fill(0, count($enrollmentStatuses), '?'));
            $where[] = "(se.enrollment_status IN ($placeholders) OR sp.enrollment_status IN ($placeholders))";
            array_push($params, ...$enrollmentStatuses, ...$enrollmentStatuses);
        }
        // فلتر فترة الأرشفة (متعدد — يُدمج بـ OR)
        $validPeriods = ['7days', '30days', '90days', 'thisyear'];
        $archivePeriods = array_values(array_filter(
            (array)($request['filter_archive_period[]'] ?? $request['filter_archive_period'] ?? []),
            static fn($v) => in_array(trim((string)$v), $validPeriods, true)
        ));
        if (!empty($archivePeriods)) {
            $periodClauses = [];
            foreach ($archivePeriods as $period) {
                if ($period === '7days')    $periodClauses[] = 'u.deleted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                elseif ($period === '30days')   $periodClauses[] = 'u.deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
                elseif ($period === '90days')   $periodClauses[] = 'u.deleted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)';
                elseif ($period === 'thisyear') $periodClauses[] = 'YEAR(u.deleted_at) = YEAR(NOW())';
            }
            if (!empty($periodClauses)) {
                $where[] = '(' . implode(' OR ', $periodClauses) . ')';
            }
        }
        // فلتر الفصول
        $classIds = array_filter(array_map('intval', (array)($request['filter_class_ids[]'] ?? $request['filter_class_ids'] ?? [])));
        if (!empty($classIds)) {
            $placeholders = implode(',', array_fill(0, count($classIds), '?'));
            $where[] = "COALESCE(se.class_id, u.class_id) IN ($placeholders)";
            array_push($params, ...$classIds);
        }
        // فلتر الصفوف
        $gradeIds = array_filter(array_map('intval', (array)($request['filter_grade_ids[]'] ?? $request['filter_grade_ids'] ?? [])));
        if (!empty($gradeIds) && empty($classIds)) {
            $placeholders = implode(',', array_fill(0, count($gradeIds), '?'));
            $where[] = "COALESCE(se.grade_id, c.grade_id) IN ($placeholders)";
            array_push($params, ...$gradeIds);
        }
        // فلتر المراحل
        $stageIds = array_filter(array_map('intval', (array)($request['filter_stage_ids[]'] ?? $request['filter_stage_ids'] ?? [])));
        if (!empty($stageIds) && empty($gradeIds) && empty($classIds)) {
            $placeholders = implode(',', array_fill(0, count($stageIds), '?'));
            $where[] = "COALESCE(se.stage_id, g.stage_id) IN ($placeholders)";
            array_push($params, ...$stageIds);
        }
        return [$where, $params];
    }

    private function assertSchemaReady(): void
    {
        $stmt = $this->db->query(
            "SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
               AND COLUMN_NAME IN ('deleted_at','archived_by','archive_reason','status_before_archive')"
        );
        if ((int) $stmt->fetchColumn() !== 4) {
            throw new RuntimeException('مخطط أرشيف الطلاب غير جاهز. شغّل migration الأرشفة أولاً.');
        }
    }
}
