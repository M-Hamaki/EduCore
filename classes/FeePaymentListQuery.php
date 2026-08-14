<?php
declare(strict_types=1);

/** Read-only paging and summary query for the fee payment student list. */
final class FeePaymentListQuery
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{total:int,due:float,paid:float,balance:float,paid_count:int,partial_count:int,unpaid_count:int} */
    public function summary(int $academicYearId, string $year, array $filters): array
    {
        [$from, $where, $params] = $this->base($academicYearId, $year, $filters);
        $status = $this->statusWhere($filters['fee_status'] ?? '');
        if ($status !== '') { $where[] = $status; }
        $sql = 'SELECT COUNT(*) AS total, COALESCE(SUM(sf.final_amount), 0) AS due, COALESCE(SUM(sf.total_paid), 0) AS paid, COALESCE(SUM(sf.balance), 0) AS balance, '
            . "COALESCE(SUM(CASE WHEN sf.id IS NOT NULL AND sf.balance <= 0 THEN 1 ELSE 0 END), 0) AS paid_count, "
            . "COALESCE(SUM(CASE WHEN sf.id IS NOT NULL AND sf.total_paid > 0 AND sf.balance > 0 THEN 1 ELSE 0 END), 0) AS partial_count "
            . "FROM {$from} WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        $paid = (int) ($row['paid_count'] ?? 0);
        $partial = (int) ($row['partial_count'] ?? 0);
        return ['total' => $total, 'due' => (float) ($row['due'] ?? 0), 'paid' => (float) ($row['paid'] ?? 0), 'balance' => (float) ($row['balance'] ?? 0), 'paid_count' => $paid, 'partial_count' => $partial, 'unpaid_count' => $total - $paid - $partial];
    }

    /** @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array<int,array<int,string>>} */
    public function load(array $request, int $academicYearId): array
    {
        $year = trim((string) ($request['year'] ?? ''));
        [$from, $where, $params] = $this->base($academicYearId, $year, $request);
        $status = $this->statusWhere($request['fee_status'] ?? '');
        if ($status !== '') { $where[] = $status; }
        $baseWhere = implode(' AND ', $where);
        $search = trim((string) ($request['search']['value'] ?? ''));
        $filteredWhere = $where;
        $filteredParams = $params;
        if ($search !== '') {
            $filteredWhere[] = '(u.name LIKE ? OR sp.student_code LIKE ? OR c.name LIKE ? OR g.grade_name LIKE ? OR s.stage_name LIKE ?)';
            for ($i = 0; $i < 5; $i++) { $filteredParams[] = '%' . $search . '%'; }
        }
        $filteredSql = implode(' AND ', $filteredWhere);
        $total = $this->count($from, $baseWhere, $params);
        $filtered = $search === '' ? $total : $this->count($from, $filteredSql, $filteredParams);
        $columns = [1 => 'sp.student_code', 2 => 'u.name', 3 => 'c.name', 4 => 'sf.final_amount', 5 => 'sf.total_paid', 6 => 'sf.balance'];
        $column = (int) ($request['order'][0]['column'] ?? 2);
        $orderBy = $columns[$column] ?? 'u.name';
        $direction = strtolower((string) ($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $start = max(0, (int) ($request['start'] ?? 0));
        $wanted = (int) ($request['length'] ?? 50);
        $length = $wanted === -1 ? PHP_INT_MAX : min(500, max(10, $wanted));
        $sql = 'SELECT u.id, u.name, c.name AS class_name, sp.student_code, sf.id AS fee_id, sf.final_amount, sf.total_paid, sf.balance '
            . "FROM {$from} WHERE {$filteredSql} ORDER BY {$orderBy} {$direction}, u.name ASC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $i = 1;
        foreach ($filteredParams as $param) { $stmt->bindValue($i++, $param); }
        $stmt->bindValue($i++, $length, PDO::PARAM_INT);
        $stmt->bindValue($i, $start, PDO::PARAM_INT);
        $stmt->execute();
        $data = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index => $row) { $data[] = $this->present($row, $start + $index + 1); }
        return ['draw' => max(0, (int) ($request['draw'] ?? 0)), 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $data];
    }

    /** @return array{0:string,1:array<int,string>,2:array<int,mixed>} */
    private function base(int $academicYearId, string $year, array $filters): array
    {
        if ($academicYearId > 0) {
            $from = 'users u JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = \'enrolled\' JOIN classes c ON c.id = se.class_id JOIN grades g ON c.grade_id = g.id LEFT JOIN stages s ON g.stage_id = s.id LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN student_fees sf ON sf.student_id = u.id AND sf.academic_year = ?';
            $where = ["u.role = 'student'", "u.status = 'active'"];
            $params = [$academicYearId, $year];
            $fieldPrefix = 'se.';
        } else {
            $from = 'users u JOIN classes c ON u.class_id = c.id JOIN grades g ON c.grade_id = g.id LEFT JOIN stages s ON g.stage_id = s.id LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN student_fees sf ON sf.student_id = u.id AND sf.academic_year = ?';
            $where = ["u.role = 'student'", "u.status = 'active'"];
            $params = [$year];
            $fieldPrefix = '';
        }

        $classIds = $this->parseIds($filters['class_id'] ?? $filters['class_ids'] ?? null);
        $gradeIds = $this->parseIds($filters['grade_id'] ?? $filters['grade_ids'] ?? null);
        $stageIds = $this->parseIds($filters['stage_id'] ?? $filters['stage_ids'] ?? null);

        if (!empty($classIds)) {
            $in = implode(',', array_fill(0, count($classIds), '?'));
            $where[] = ($fieldPrefix !== '' ? $fieldPrefix : 'u.') . "class_id IN ({$in})";
            foreach ($classIds as $cid) { $params[] = $cid; }
        } elseif (!empty($gradeIds)) {
            $in = implode(',', array_fill(0, count($gradeIds), '?'));
            $where[] = ($fieldPrefix !== '' ? $fieldPrefix : 'c.') . "grade_id IN ({$in})";
            foreach ($gradeIds as $gid) { $params[] = $gid; }
        } elseif (!empty($stageIds)) {
            $in = implode(',', array_fill(0, count($stageIds), '?'));
            $where[] = ($fieldPrefix !== '' ? $fieldPrefix : 'g.') . "stage_id IN ({$in})";
            foreach ($stageIds as $sid) { $params[] = $sid; }
        }
        return [$from, $where, $params];
    }

    private function parseIds(mixed $val): array
    {
        if (is_array($val)) {
            return array_values(array_filter(array_map('intval', $val), fn($v) => $v > 0));
        }
        if (is_string($val) && trim($val) !== '') {
            $parts = explode(',', $val);
            return array_values(array_filter(array_map('intval', $parts), fn($v) => $v > 0));
        }
        if (is_numeric($val) && (int)$val > 0) {
            return [(int)$val];
        }
        return [];
    }

    private function parseStatuses(mixed $val): array
    {
        $allowed = ['paid', 'partial', 'unpaid'];
        if (is_array($val)) {
            return array_values(array_intersect($val, $allowed));
        }
        if (is_string($val) && trim($val) !== '') {
            $parts = explode(',', $val);
            return array_values(array_intersect(array_map('trim', $parts), $allowed));
        }
        return [];
    }

    private function statusWhere(mixed $statusFilter): string
    {
        $statuses = $this->parseStatuses($statusFilter);
        if (empty($statuses)) {
            return '';
        }
        $conds = [];
        foreach ($statuses as $st) {
            if ($st === 'paid') {
                $conds[] = '(sf.id IS NOT NULL AND sf.balance <= 0)';
            } elseif ($st === 'partial') {
                $conds[] = '(sf.id IS NOT NULL AND sf.total_paid > 0 AND sf.balance > 0)';
            } elseif ($st === 'unpaid') {
                $conds[] = '(sf.id IS NULL OR (sf.total_paid = 0 AND sf.balance > 0))';
            }
        }
        return '(' . implode(' OR ', $conds) . ')';
    }

    /** @param array<int,mixed> $params */
    private function count(string $from, string $where, array $params): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$from} WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $row @return array<int,string> */
    private function present(array $row, int $number): array
    {
        $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $hasFee = !empty($row['fee_id']);
        $balance = (float) ($row['balance'] ?? 0);
        $paid = (float) ($row['total_paid'] ?? 0);
        $badge = !$hasFee ? 'bg-secondary">غير محدد' : ($balance <= 0 ? 'bg-success">مسدد' : ($paid > 0 ? 'bg-warning text-dark">جزئي' : 'bg-danger">لم يسدد'));
        $name = $e($row['name']);
        $nameJson = htmlspecialchars((string) json_encode((string) $row['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
        $id = (int) $row['id'];
        $actions = '<button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="عرض التفاصيل" onclick="viewStudentFee(' . $id . ')"><i class="fas fa-eye"></i></button>'
            . '<button class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="تسجيل دفعة" onclick="openPaymentModal(' . $id . ', ' . $nameJson . ')"><i class="fas fa-plus-circle"></i></button>'
            . '<button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعيين خصم" onclick="openDiscountModal(' . $id . ', ' . $nameJson . ')"><i class="fas fa-tags"></i></button>';
        if ($hasFee && $paid > 0) { $actions .= '<button class="btn btn-action-pills btn-deactivate" data-bs-toggle="tooltip" title="طباعة إيصال" onclick="printReceipt(' . $id . ')"><i class="fas fa-print"></i></button>'; }
        return [(string) $number, '<small class="text-muted">' . $e($row['student_code'] ?? '-') . '</small>', '<strong>' . $name . '</strong>', $e($row['class_name'] ?? '-'), $hasFee ? number_format((float) $row['final_amount'], 2) : '<span class="text-muted">-</span>', '<span class="text-success">' . ($hasFee ? number_format($paid, 2) : '-') . '</span>', '<span class="' . ($hasFee && $balance > 0 ? 'text-danger fw-bold' : '') . '">' . ($hasFee ? number_format($balance, 2) : '-') . '</span>', '<span class="badge ' . $badge . '</span>', '<span class="admin-table-actions">' . $actions . '</span>'];
    }
}
