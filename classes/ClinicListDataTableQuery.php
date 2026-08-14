<?php

declare(strict_types=1);

/** Read-only, paginated clinic health and visit lists. */
final class ClinicListDataTableQuery
{
    public function __construct(private PDO $db)
    {
    }

    /** @param array<int,int>|null $allowedClassIds @return array{health:int,visits:int} */
    public function counts(
        int $yearId,
        array $healthFilters,
        array $visitFilters,
        ?array $allowedClassIds = null
    ): array {
        [$healthFrom, $healthWhere, $healthParams] = $this->healthBase($yearId, $healthFilters, $allowedClassIds);
        [$visitFrom, $visitWhere, $visitParams] = $this->visitBase($yearId, $visitFilters, $allowedClassIds);

        return [
            'health' => $this->count($healthFrom, implode(' AND ', $healthWhere), $healthParams),
            'visits' => $this->count($visitFrom, implode(' AND ', $visitWhere), $visitParams),
        ];
    }

    /** @param array<int,int>|null $allowedClassIds @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array<int,array<int,string>>} */
    public function health(array $request, int $yearId, ?array $allowedClassIds = null, bool $canManage = true): array
    {
        [$from, $where, $params] = $this->healthBase($yearId, $request, $allowedClassIds);
        $fields = 'u.id,u.name AS student_name,c.name AS class_name,sp.blood_type,sp.health_status,sp.chronic_diseases,sp.allergies,sp.disabilities,sp.insurance_number,sp.insurance_start_date,sp.insurance_end_date,sp.medications,sp.treatment_plan,sp.previous_medical_reports,sp.emergency_medical_notes';
        $map = [1 => 'u.name', 2 => 'c.name', 3 => 'sp.blood_type', 8 => 'sp.insurance_number', 9 => 'sp.insurance_start_date', 10 => 'sp.insurance_end_date'];

        return $this->load(
            $request,
            $from,
            $where,
            $params,
            $fields,
            $map,
            '(u.name LIKE ? OR c.name LIKE ? OR sp.blood_type LIKE ? OR sp.insurance_number LIKE ? OR sp.health_status LIKE ?)',
            fn(array $row, int $number): array => $this->presentHealth($row, $number, $canManage)
        );
    }

    /** @param array<int,int>|null $allowedClassIds @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array<int,array<int,string>>} */
    public function visits(array $request, int $yearId, ?array $allowedClassIds = null, bool $canManage = true): array
    {
        [$from, $where, $params] = $this->visitBase($yearId, $request, $allowedClassIds);
        $fields = 'v.*,u.name AS student_name,sp.student_code,c.name AS class_name';
        $map = [1 => 'u.name', 2 => 'v.visit_at', 4 => 'c.name', 5 => 'v.complaint', 6 => 'v.diagnosis'];

        return $this->load(
            $request,
            $from,
            $where,
            $params,
            $fields,
            $map,
            '(u.name LIKE ? OR sp.student_code LIKE ? OR c.name LIKE ? OR v.complaint LIKE ? OR v.diagnosis LIKE ?)',
            fn(array $row, int $number): array => $this->presentVisit($row, $number, $canManage)
        );
    }

    /** @param array<int,int>|null $allowedClassIds @return array{0:string,1:array<int,string>,2:array<int,mixed>} */
    private function healthBase(int $yearId, array $filters, ?array $allowedClassIds): array
    {
        if ($yearId > 0) {
            $from = "users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                LEFT JOIN classes c ON c.id = se.class_id
                LEFT JOIN grades g ON g.id = c.grade_id";
            $params = [$yearId];
            $classColumn = 'se.class_id';
        } else {
            $from = 'users u LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN classes c ON c.id = u.class_id LEFT JOIN grades g ON g.id = c.grade_id';
            $params = [];
            $classColumn = 'u.class_id';
        }
        $where = ["u.role = 'student'", "u.status = 'active'", 'u.deleted_at IS NULL'];
        $this->appendClassScope($where, $params, $classColumn, $allowedClassIds);

        foreach (['health_stage_id' => 'g.stage_id', 'health_grade_id' => 'c.grade_id', 'health_class_id' => $classColumn] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = $column . ' = ?';
                $params[] = (int)$filters[$key];
            }
        }
        return [$from, $where, $params];
    }

    /** @param array<int,int>|null $allowedClassIds @return array{0:string,1:array<int,string>,2:array<int,mixed>} */
    private function visitBase(int $yearId, array $filters, ?array $allowedClassIds): array
    {
        if ($yearId > 0) {
            $from = "student_clinic_visits v
                JOIN users u ON u.id = v.student_id
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                LEFT JOIN classes c ON c.id = se.class_id
                LEFT JOIN grades g ON g.id = c.grade_id";
            $params = [$yearId];
            $classColumn = 'se.class_id';
        } else {
            $from = 'student_clinic_visits v JOIN users u ON u.id = v.student_id LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN classes c ON c.id = u.class_id LEFT JOIN grades g ON g.id = c.grade_id';
            $params = [];
            $classColumn = 'u.class_id';
        }
        $where = ['1 = 1'];
        $this->appendClassScope($where, $params, $classColumn, $allowedClassIds);

        foreach (['stage_id' => 'g.stage_id', 'grade_id' => 'c.grade_id', 'class_id' => $classColumn, 'student_id' => 'v.student_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = $column . ' = ?';
                $params[] = (int)$filters[$key];
            }
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'v.visit_at >= ?';
            $params[] = trim((string)$filters['date_from']) . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'v.visit_at <= ?';
            $params[] = trim((string)$filters['date_to']) . ' 23:59:59';
        }
        return [$from, $where, $params];
    }

    /** @param array<int,string> $where @param array<int,mixed> $params @param array<int,int>|null $allowedClassIds */
    private function appendClassScope(array &$where, array &$params, string $column, ?array $allowedClassIds): void
    {
        if ($allowedClassIds === null) {
            return;
        }
        $allowedClassIds = array_values(array_unique(array_filter(array_map('intval', $allowedClassIds), static fn(int $id): bool => $id > 0)));
        if ($allowedClassIds === []) {
            $where[] = '1 = 0';
            return;
        }
        $where[] = $column . ' IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
        array_push($params, ...$allowedClassIds);
    }

    /** @param array<int,string> $where @param array<int,mixed> $params @param array<int,string> $map @param callable(array<string,mixed>,int):array<int,string> $present */
    private function load(array $request, string $from, array $where, array $params, string $fields, array $map, string $searchClause, callable $present): array
    {
        $draw = max(0, (int)($request['draw'] ?? 0));
        $start = max(0, (int)($request['start'] ?? 0));
        $wanted = (int)($request['length'] ?? 50);
        $length = $wanted === -1 ? PHP_INT_MAX : min(500, max(10, $wanted));
        $baseWhere = implode(' AND ', $where);
        $search = trim((string)($request['search']['value'] ?? ''));
        $filteredWhere = $where;
        $filteredParams = $params;
        if ($search !== '') {
            $filteredWhere[] = $searchClause;
            for ($i = 0; $i < 5; $i++) {
                $filteredParams[] = '%' . $search . '%';
            }
        }
        $filteredSql = implode(' AND ', $filteredWhere);
        $total = $this->count($from, $baseWhere, $params);
        $filteredTotal = $search === '' ? $total : $this->count($from, $filteredSql, $filteredParams);
        $column = (int)($request['order'][0]['column'] ?? 1);
        $order = $map[$column] ?? 'u.name';
        $direction = strtolower((string)($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $stmt = $this->db->prepare("SELECT {$fields} FROM {$from} WHERE {$filteredSql} ORDER BY {$order} {$direction}, u.name ASC LIMIT ? OFFSET ?");
        $index = 1;
        foreach ($filteredParams as $param) {
            $stmt->bindValue($index++, $param);
        }
        $stmt->bindValue($index++, $length, PDO::PARAM_INT);
        $stmt->bindValue($index, $start, PDO::PARAM_INT);
        $stmt->execute();
        $data = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rowIndex => $row) {
            $data[] = $present($row, $start + $rowIndex + 1);
        }
        return ['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filteredTotal, 'data' => $data];
    }

    /** @param array<int,mixed> $params */
    private function count(string $from, string $where, array $params): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$from} WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** @param array<string,mixed> $row @return array<int,string> */
    private function presentHealth(array $row, int $number, bool $canManage): array
    {
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $json = $escape(json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $text = static fn($value): string => $value !== null && $value !== '' ? nl2br($escape($value)) : '-';
        $actions = $canManage
            ? '<span class="admin-table-actions"><button type="button" class="btn btn-action-pills btn-edit" data-bs-toggle="tooltip" title="تعديل الحالة الصحية" onclick="openEditHealthModal(' . $json . ')"><i class="fas fa-edit"></i></button></span>'
            : '<span class="text-muted">—</span>';

        return [(string)$number, '<span class="fw-bold text-primary">' . $escape($row['student_name']) . '</span>', $escape($row['class_name'] ?? '-'), '<span class="badge bg-light text-dark border">' . $escape($row['blood_type'] ?? '-') . '</span>', $text($row['health_status'] ?? null), $text($row['chronic_diseases'] ?? null), $text($row['allergies'] ?? null), $text($row['disabilities'] ?? null), $escape($row['insurance_number'] ?? '-'), $escape($row['insurance_start_date'] ?? '-'), $escape($row['insurance_end_date'] ?? '-'), $text($row['medications'] ?? null), $text($row['treatment_plan'] ?? null), $text($row['previous_medical_reports'] ?? null), $text($row['emergency_medical_notes'] ?? null), $actions];
    }

    /** @param array<string,mixed> $row @return array<int,string> */
    private function presentVisit(array $row, int $number, bool $canManage): array
    {
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $timestamp = strtotime((string)$row['visit_at']);
        $date = $timestamp ? date('Y/m/d', $timestamp) : '-';
        $time = $timestamp ? date('h:i', $timestamp) . ' ' . (date('a', $timestamp) === 'am' ? 'ص' : 'م') : '-';
        $json = $escape(json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $nameJson = $escape(json_encode((string)$row['student_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $text = static fn($value): string => $value !== null && $value !== '' ? nl2br($escape($value)) : '-';
        $actions = $canManage
            ? '<span class="admin-table-actions"><button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل" onclick="openEditVisitModal(' . $json . ')"><i class="fas fa-edit"></i></button><button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف" onclick="openDeleteVisitModal(' . (int)$row['id'] . ', ' . $nameJson . ')"><i class="fas fa-trash"></i></button></span>'
            : '<span class="text-muted">—</span>';

        return [(string)$number, '<span class="fw-bold text-primary">' . $escape($row['student_name']) . '</span>', '<span class="badge bg-light text-dark border">' . $date . '</span>', '<span dir="ltr">' . $time . '</span>', $escape($row['class_name'] ?? '-'), $text($row['complaint'] ?? $row['health_condition'] ?? null), $text($row['diagnosis'] ?? null), $escape($row['action_taken'] ?? '-'), $text($row['treatment_taken'] ?? null), $text($row['notes'] ?? null), $actions];
    }
}
