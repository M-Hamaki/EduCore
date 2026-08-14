<?php
declare(strict_types=1);

namespace EduCore\Modules\Students;

use PDO;

/**
 * Read model for the derived student lists (newly enrolled, transferred, and graduates).
 * It deliberately returns only the fields rendered by each list.
 */
final class DerivedStudentListDataTableQuery
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{total:int,stage_count:int,top_stage:string} */
    public function newStudentsSummary(int $academicYearId, ?string $yearStart, ?string $yearEnd, array $filters): array
    {
        [$where, $params] = $this->newStudentsWhere($academicYearId, $yearStart, $yearEnd, $filters);
        $whereSql = implode(' AND ', $where);

        $count = $this->db->prepare("SELECT COUNT(*) FROM student_enrollments se JOIN users u ON u.id = se.student_id WHERE {$whereSql}");
        $count->execute($params);

        $stages = $this->db->prepare("SELECT COALESCE(s.stage_name, 'غير محدد') AS stage_name, COUNT(*) AS total FROM student_enrollments se JOIN users u ON u.id = se.student_id LEFT JOIN stages s ON s.id = se.stage_id WHERE {$whereSql} GROUP BY s.id, s.stage_name ORDER BY total DESC, stage_name ASC");
        $stages->execute($params);
        $stageRows = $stages->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total' => (int) $count->fetchColumn(),
            'stage_count' => count($stageRows),
            'top_stage' => (string) ($stageRows[0]['stage_name'] ?? '-'),
        ];
    }

    /** @return array{total:int,years_count:int} */
    public function graduatesSummary(string $graduationYear = ''): array
    {
        [$where, $params] = $this->graduatesWhere($graduationYear);
        $whereSql = implode(' AND ', $where);
        $count = $this->db->prepare("SELECT COUNT(*) FROM student_enrollments se JOIN users u ON u.id = se.student_id WHERE {$whereSql}");
        $count->execute($params);
        $years = $this->db->query("SELECT COUNT(DISTINCT se.graduation_year) FROM student_enrollments se JOIN users u ON u.id = se.student_id WHERE (se.academic_status = 'graduated' OR se.enrollment_status = 'graduated') AND u.role = 'student' AND u.deleted_at IS NULL AND se.graduation_year IS NOT NULL AND se.graduation_year <> ''");

        return ['total' => (int) $count->fetchColumn(), 'years_count' => (int) $years->fetchColumn()];
    }

    /** @return array{total:int,destination_count:int,latest_transfer_date:?string} */
    public function transferredStudentsSummary(int $academicYearId, array $filters): array
    {
        [$where, $params] = $this->transferredStudentsWhere($academicYearId, $filters);
        $whereSql = implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total,
                COUNT(DISTINCT NULLIF(TRIM(setr.destination), '')) AS destination_count,
                MAX(setr.transfer_date) AS latest_transfer_date
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id
            LEFT JOIN student_external_transfers setr ON setr.student_id = u.id
            WHERE {$whereSql}");
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($summary['total'] ?? 0),
            'destination_count' => (int) ($summary['destination_count'] ?? 0),
            'latest_transfer_date' => !empty($summary['latest_transfer_date']) ? (string) $summary['latest_transfer_date'] : null,
        ];
    }

    /** @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array<int,array<int,string>>} */
    public function loadNewStudents(array $request, int $academicYearId, ?string $yearStart, ?string $yearEnd): array
    {
        [$where, $params] = $this->newStudentsWhere($academicYearId, $yearStart, $yearEnd, $request);
        return $this->load($request, $where, $params, 'new');
    }

    /** @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array<int,array<int,string>>} */
    public function loadGraduates(array $request): array
    {
        [$where, $params] = $this->graduatesWhere((string) ($request['grad_year'] ?? ''));
        return $this->load($request, $where, $params, 'graduate');
    }

    /** @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array<int,array<int,string>>} */
    public function loadTransferredStudents(array $request, int $academicYearId): array
    {
        [$where, $params] = $this->transferredStudentsWhere($academicYearId, $request);
        return $this->load($request, $where, $params, 'transferred');
    }

    /** @return array{0:array<int,string>,1:array<int,mixed>} */
    private function newStudentsWhere(int $academicYearId, ?string $yearStart, ?string $yearEnd, array $filters): array
    {
        $where = [
            "u.role = 'student'",
            "u.deleted_at IS NULL",
            "se.enrollment_status = 'enrolled'",
            "se.academic_status = 'new'",
        ];
        $params = [];
        if ($academicYearId > 0) {
            $where[] = 'se.academic_year_id = ?';
            $params[] = $academicYearId;
        } else {
            $where[] = '1 = 0';
        }
        if ($yearStart && $yearEnd) { $where[] = 'se.enrollment_date IS NOT NULL AND se.enrollment_date >= ? AND se.enrollment_date <= ?'; array_push($params, $yearStart, $yearEnd); }
        elseif ($yearStart) { $where[] = 'se.enrollment_date IS NOT NULL AND se.enrollment_date >= ?'; $params[] = $yearStart; }
        else { $where[] = 'se.enrollment_date IS NOT NULL'; }
        $this->appendIds($where, $params, 'se.stage_id', $filters['stage_ids'] ?? []);
        $this->appendIds($where, $params, 'se.grade_id', $filters['grade_ids'] ?? []);
        $this->appendIds($where, $params, 'se.class_id', $filters['class_ids'] ?? []);
        return [$where, $params];
    }

    /** @return array{0:array<int,string>,1:array<int,mixed>} */
    private function graduatesWhere(string $graduationYear): array
    {
        $where = ["(se.academic_status = 'graduated' OR se.enrollment_status = 'graduated')", "u.role = 'student'", "u.deleted_at IS NULL"];
        $params = [];
        if ($graduationYear !== '') { $where[] = 'se.graduation_year = ?'; $params[] = $graduationYear; }
        return [$where, $params];
    }

    /** @return array{0:array<int,string>,1:array<int,mixed>} */
    private function transferredStudentsWhere(int $academicYearId, array $filters): array
    {
        $where = ["u.role = 'student'", "u.deleted_at IS NULL", "se.enrollment_status = 'transferred'"];
        $params = [];
        if ($academicYearId > 0) {
            $where[] = 'se.academic_year_id = ?';
            $params[] = $academicYearId;
        } else {
            $where[] = '1 = 0';
        }
        $this->appendIds($where, $params, 'se.stage_id', $filters['stage_ids'] ?? []);
        $this->appendIds($where, $params, 'se.grade_id', $filters['grade_ids'] ?? []);
        $this->appendIds($where, $params, 'se.class_id', $filters['class_ids'] ?? []);
        $destination = trim((string) ($filters['destination'] ?? ''));
        if ($destination !== '') {
            $where[] = 'setr.destination LIKE ?';
            $params[] = '%' . $destination . '%';
        }
        return [$where, $params];
    }

    /** @param array<int,string> $where @param array<int,mixed> $params @param mixed $values */
    private function appendIds(array &$where, array &$params, string $column, $values): void
    {
        if (!is_array($values)) { return; }
        $ids = array_values(array_filter(array_map('intval', $values)));
        if ($ids === []) { return; }
        $where[] = $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($ids as $id) { $params[] = $id; }
    }

    /** @param array<int,string> $where @param array<int,mixed> $params @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array<int,array<int,string>>} */
    private function load(array $request, array $where, array $params, string $mode): array
    {
        $draw = max(0, (int) ($request['draw'] ?? 0));
        $start = max(0, (int) ($request['start'] ?? 0));
        $wanted = (int) ($request['length'] ?? 50);
        $length = $wanted === -1 ? PHP_INT_MAX : min(500, max(10, $wanted));
        $search = trim((string) ($request['search']['value'] ?? ''));
        $baseWhere = implode(' AND ', $where);
        $filteredWhere = $where;
        $filteredParams = $params;
        if ($search !== '') {
            $filteredWhere[] = '(u.name LIKE ? OR sp.student_code LIKE ? OR s.stage_name LIKE ? OR g.grade_name LIKE ? OR c.name LIKE ? OR setr.destination LIKE ?)';
            for ($i = 0; $i < 6; $i++) { $filteredParams[] = '%' . $search . '%'; }
        }
        $filteredSql = implode(' AND ', $filteredWhere);
        $total = $this->count($baseWhere, $params);
        $filtered = $search === '' ? $total : $this->count($filteredSql, $filteredParams);
        $orderColumn = (int) ($request['order'][0]['column'] ?? 2);
        $orderDirection = strtolower((string) ($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        if ($mode === 'new') {
            $orderMap = [1 => 'sp.student_code', 2 => 'u.name', 3 => 's.stage_name', 4 => 'g.grade_name', 5 => 'c.name', 6 => 'se.enrollment_date', 7 => 'ay.name'];
            $defaultOrder = 'se.enrollment_date';
            $fields = 'u.id, u.name, sp.student_code, se.enrollment_date, g.grade_name, s.stage_name, c.name AS class_name, ay.name AS year_name';
        } elseif ($mode === 'transferred') {
            $orderMap = [1 => 'sp.student_code', 2 => 'u.name', 3 => 's.stage_name', 4 => 'g.grade_name', 5 => 'c.name', 6 => 'setr.destination', 7 => 'setr.transfer_date', 8 => 'ay.name'];
            $defaultOrder = 'setr.transfer_date';
            $fields = 'u.id, u.name, sp.student_code, g.grade_name, s.stage_name, c.name AS class_name, setr.destination AS transfer_destination, setr.transfer_date AS external_transfer_date, ay.name AS year_name';
        } else {
            $orderMap = [1 => 'sp.student_code', 2 => 'u.name', 3 => 's.stage_name', 4 => 'g.grade_name', 5 => 'se.graduation_year'];
            $defaultOrder = 'se.graduation_year';
            $fields = 'u.id, u.name, sp.student_code, se.graduation_year, g.grade_name, s.stage_name';
        }
        $orderBy = $orderMap[$orderColumn] ?? $defaultOrder;
        $sql = "SELECT {$fields} FROM student_enrollments se JOIN users u ON u.id = se.student_id LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN academic_years ay ON ay.id = se.academic_year_id LEFT JOIN grades g ON g.id = se.grade_id LEFT JOIN stages s ON s.id = se.stage_id LEFT JOIN classes c ON c.id = se.class_id LEFT JOIN student_external_transfers setr ON setr.student_id = u.id WHERE {$filteredSql} ORDER BY {$orderBy} {$orderDirection}, u.name ASC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $position = 1;
        foreach ($filteredParams as $param) { $stmt->bindValue($position++, $param); }
        $stmt->bindValue($position++, $length, PDO::PARAM_INT);
        $stmt->bindValue($position, $start, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($rows as $index => $row) { $data[] = $this->present($row, $start + $index + 1, $mode); }
        return ['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $data];
    }

    /** @param array<int,mixed> $params */
    private function count(string $where, array $params): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM student_enrollments se JOIN users u ON u.id = se.student_id LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN grades g ON g.id = se.grade_id LEFT JOIN stages s ON s.id = se.stage_id LEFT JOIN classes c ON c.id = se.class_id LEFT JOIN student_external_transfers setr ON setr.student_id = u.id WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $row @return array<int,string> */
    private function present(array $row, int $number, string $mode): array
    {
        $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $scopeQuery = $mode === 'transferred' ? '&student_scope=transferred' : '';
        $actions = '<a href="students.php?action=edit&id=' . (int) $row['id'] . $scopeQuery . '" class="btn btn-action-pills btn-edit" data-bs-toggle="tooltip" title="تعديل البيانات"><i class="fas fa-edit"></i></a>';
        if ($mode === 'graduate') {
            return [(string) $number, $e($row['student_code'] ?? '-'), '<span class="fw-bold">' . $e($row['name']) . '</span>', $e($row['stage_name'] ?? '-'), $e($row['grade_name'] ?? '-'), '<span class="badge bg-success">' . $e($row['graduation_year'] ?? '-') . '</span>', '<span class="actions-column admin-table-actions">' . $actions . '</span>'];
        }
        if ($mode === 'transferred') {
            $date = empty($row['external_transfer_date']) ? '<span class="text-muted">—</span>' : '<span class="badge bg-warning text-dark">' . $e($row['external_transfer_date']) . '</span>';
            return [(string) $number, $e($row['student_code'] ?? '-'), '<span class="fw-bold">' . $e($row['name']) . '</span>', $e($row['stage_name'] ?? '-'), $e($row['grade_name'] ?? '-'), $e($row['class_name'] ?? '-'), $e($row['transfer_destination'] ?? '-'), $date, $e($row['year_name'] ?? '-'), '<span class="actions-column admin-table-actions">' . $actions . '</span>'];
        }
        $date = empty($row['enrollment_date']) ? '<span class="text-muted">—</span>' : '<span class="badge bg-info text-dark">' . $e($row['enrollment_date']) . '</span>';
        return [(string) $number, $e($row['student_code'] ?? '-'), '<span class="fw-bold">' . $e($row['name']) . '</span>', $e($row['stage_name'] ?? '-'), $e($row['grade_name'] ?? '-'), $e($row['class_name'] ?? '-'), $date, $e($row['year_name'] ?? '-'), '<span class="actions-column admin-table-actions">' . $actions . '</span>'];
    }
}
