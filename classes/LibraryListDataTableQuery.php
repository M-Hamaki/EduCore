<?php

declare(strict_types=1);

final class LibraryListDataTableQuery
{
    public function __construct(private PDO $db)
    {
    }

    /** @param array<int,int>|null $allowedClassIds */
    public function load(string $type, array $request, int $yearId = 0, ?array $allowedClassIds = null): array
    {
        $draw = max(0, (int)($request['draw'] ?? 0));
        $start = max(0, (int)($request['start'] ?? 0));
        $requestedLength = (int)($request['length'] ?? 50);
        $limit = $requestedLength === -1 ? PHP_INT_MAX : max(10, min($requestedLength, 500));

        [$from, $columns, $baseWhere, $baseParams, $searchSql, $searchColumns, $orders] = $this->definition(
            $type,
            $yearId,
            $allowedClassIds,
            $request
        );
        $search = trim((string)($request['search']['value'] ?? ''));
        $filteredWhere = $baseWhere;
        $filteredParams = $baseParams;
        if ($search !== '') {
            $filteredWhere[] = $searchSql;
            for ($i = 0; $i < $searchColumns; $i++) {
                $filteredParams[] = '%' . $search . '%';
            }
        }

        $total = $this->count($from, $baseWhere, $baseParams);
        $filtered = $search === '' ? $total : $this->count($from, $filteredWhere, $filteredParams);
        $columnIndex = (int)($request['order'][0]['column'] ?? 0);
        $order = $orders[$columnIndex] ?? array_values($orders)[0];
        $direction = strtolower((string)($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $limitSql = $limit === PHP_INT_MAX ? '' : " LIMIT {$start},{$limit}";
        $whereSql = implode(' AND ', $filteredWhere);
        $stmt = $this->db->prepare("SELECT {$columns} FROM {$from} WHERE {$whereSql} ORDER BY {$order} {$direction}{$limitSql}");
        $stmt->execute($filteredParams);

        return compact('draw', 'total', 'filtered') + ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    /** @param array<int,int>|null $allowedClassIds @return array{0:string,1:string,2:array<int,string>,3:array<int,mixed>,4:string,5:int,6:array<int,string>} */
    private function definition(string $type, int $yearId, ?array $allowedClassIds, array $request = []): array
    {
        if ($type === 'books') {
            return [
                'library_books b',
                'b.id, b.title, b.author, b.category, b.copies_available, b.copies_total, b.location, b.isbn, b.notes',
                ['1 = 1'],
                [],
                '(b.title LIKE ? OR b.author LIKE ? OR b.category LIKE ?)',
                3,
                [0 => 'b.title', 1 => 'b.author', 2 => 'b.category', 3 => 'b.copies_available', 4 => 'b.location'],
            ];
        }

        [$enrollmentJoin, $classColumn, $yearParams] = $this->studentEnrollmentJoin($yearId);
        $where = [];
        $params = $yearParams;
        if ($type === 'loans' || $type === 'returns') {
            $from = 'library_loans l JOIN library_books b ON b.id = l.book_id JOIN users u ON u.id = l.student_id LEFT JOIN student_profiles sp ON sp.user_id = u.id ' . $enrollmentJoin . ' LEFT JOIN classes c ON c.id = ' . $classColumn . ' LEFT JOIN grades g ON g.id = c.grade_id LEFT JOIN stages st ON st.id = g.stage_id';
            $status = $type === 'loans' ? "l.status <> 'returned'" : "l.status = 'returned'";
            $where[] = $status;
            $this->appendClassScope($where, $params, $classColumn, $allowedClassIds);

            if (!empty($request['loan_status'])) {
                if ($request['loan_status'] === 'active') {
                    $where[] = "(l.due_at IS NULL OR l.due_at >= CURDATE())";
                } elseif ($request['loan_status'] === 'overdue') {
                    $where[] = "(l.due_at IS NOT NULL AND l.due_at < CURDATE())";
                } elseif ($request['loan_status'] === 'returned') {
                    $where[] = "l.status = 'returned'";
                }
            }
            if (!empty($request['book_id'])) {
                $where[] = 'l.book_id = ?';
                $params[] = (int)$request['book_id'];
            }
            if (!empty($request['class_id'])) {
                $where[] = 'c.id = ?';
                $params[] = (int)$request['class_id'];
            } elseif (!empty($request['grade_id'])) {
                $where[] = 'c.grade_id = ?';
                $params[] = (int)$request['grade_id'];
            } elseif (!empty($request['stage_id'])) {
                $where[] = 'g.stage_id = ?';
                $params[] = (int)$request['stage_id'];
            }

            if ($type === 'loans') {
                return [$from, 'l.id, l.book_id, l.student_id, b.title, u.name AS student_name, u.role AS user_role, sp.student_code, st.stage_name, g.grade_name, c.name AS class_name, l.borrowed_at, l.due_at, l.returned_at, l.status, l.notes, COALESCE(g.stage_id, 0) AS stage_id, COALESCE(c.grade_id, 0) AS grade_id, COALESCE(' . $classColumn . ', 0) AS class_id', $where, $params, '(b.title LIKE ? OR u.name LIKE ? OR c.name LIKE ? OR g.grade_name LIKE ? OR st.stage_name LIKE ?)', 5, [0 => 'l.id', 1 => 'u.name', 2 => 'st.stage_name', 3 => 'b.title', 4 => 'l.borrowed_at', 5 => 'l.due_at', 6 => 'l.returned_at', 7 => 'l.notes', 8 => 'l.status']];
            }
            return [$from, 'l.id, l.book_id, l.student_id, b.title, u.name AS student_name, sp.student_code, st.stage_name, g.grade_name, c.name AS class_name, l.returned_at', $where, $params, '(b.title LIKE ? OR u.name LIKE ? OR c.name LIKE ?)', 3, [0 => 'l.id', 1 => 'u.name', 2 => 'c.name', 3 => 'b.title', 4 => 'l.returned_at']];
        }

        if ($type === 'fines') {
            $from = 'library_fines f LEFT JOIN library_loans l ON l.id = f.loan_id LEFT JOIN users u ON u.id = COALESCE(f.student_id, l.student_id) LEFT JOIN library_books b ON b.id = l.book_id ' . $enrollmentJoin;
            $where[] = '1 = 1';
            $this->appendClassScope($where, $params, $classColumn, $allowedClassIds);
            return [$from, 'f.id,u.name AS student_name,b.title,f.amount,f.reason,f.paid', $where, $params, '(u.name LIKE ? OR b.title LIKE ? OR f.reason LIKE ?)', 3, [0 => 'u.name', 1 => 'b.title', 2 => 'f.amount', 3 => 'f.reason', 4 => 'f.paid']];
        }

        throw new InvalidArgumentException('نوع قائمة غير صالح.');
    }

    /** @return array{0:string,1:string,2:array<int,mixed>} */
    private function studentEnrollmentJoin(int $yearId): array
    {
        if ($yearId > 0) {
            return [
                "LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'",
                'COALESCE(se.class_id, u.class_id)',
                [$yearId],
            ];
        }
        return ['', 'u.class_id', []];
    }

    /** @param array<int,string> $where @param array<int,mixed> $params @param array<int,int>|null $allowedClassIds */
    private function appendClassScope(array &$where, array &$params, string $classColumn, ?array $allowedClassIds): void
    {
        if ($allowedClassIds === null) {
            return;
        }
        $allowedClassIds = array_values(array_unique(array_filter(array_map('intval', $allowedClassIds), static fn(int $id): bool => $id > 0)));
        if ($allowedClassIds === []) {
            $where[] = '1 = 0';
            return;
        }
        $where[] = $classColumn . ' IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
        array_push($params, ...$allowedClassIds);
    }

    /** @param array<int,string> $where @param array<int,mixed> $params */
    private function count(string $from, array $where, array $params): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
