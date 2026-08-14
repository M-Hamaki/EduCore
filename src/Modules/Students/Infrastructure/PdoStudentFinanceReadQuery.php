<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Infrastructure;

use EduCore\Modules\Students\Contracts\StudentFinanceReadQuery;
use PDO;

final class PdoStudentFinanceReadQuery implements StudentFinanceReadQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function student(int $studentId, int $academicYearId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, u.status, c.name AS class_name, g.grade_name,
                    s.stage_name, c.grade_id, sp.student_code
             FROM users u
             LEFT JOIN student_enrollments se
               ON se.student_id = u.id AND se.academic_year_id = ?
              AND se.enrollment_status = 'enrolled'
             LEFT JOIN classes c ON c.id = se.class_id
             LEFT JOIN grades g ON g.id = c.grade_id
             LEFT JOIN stages s ON s.id = g.stage_id
             LEFT JOIN student_profiles sp ON sp.user_id = u.id
             WHERE u.id = ? AND u.role = 'student' AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$academicYearId, $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function siblings(int $studentId, int $academicYearId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT sibling.id AS sibling_id, sibling.name AS sibling_name,
                    g.grade_name
             FROM student_siblings ss
             JOIN users sibling
               ON sibling.id = CASE
                   WHEN ss.student_id = ? THEN ss.sibling_id
                   ELSE ss.student_id
               END
              AND sibling.role = 'student'
              AND sibling.status = 'active'
              AND sibling.deleted_at IS NULL
             LEFT JOIN student_enrollments se
               ON se.student_id = sibling.id AND se.academic_year_id = ?
              AND se.enrollment_status = 'enrolled'
             LEFT JOIN classes c ON c.id = se.class_id
             LEFT JOIN grades g ON g.id = c.grade_id
             WHERE (ss.student_id = ? OR ss.sibling_id = ?)
             ORDER BY sibling.name"
        );
        $stmt->execute([$studentId, $academicYearId, $studentId, $studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function studentIds(int $academicYearId, ?int $gradeId, ?int $classId, ?int $studentId): array
    {
        $sql = "SELECT se.student_id
                FROM student_enrollments se
                JOIN users u ON u.id = se.student_id
                WHERE se.academic_year_id = ?
                  AND se.enrollment_status = 'enrolled'
                  AND u.role = 'student'
                  AND u.status = 'active'
                  AND u.deleted_at IS NULL";
        $params = [$academicYearId];
        if ($studentId !== null) {
            $sql .= ' AND se.student_id = ?';
            $params[] = $studentId;
        } elseif ($classId !== null) {
            $sql .= ' AND se.class_id = ?';
            $params[] = $classId;
        } elseif ($gradeId !== null) {
            $sql .= ' AND se.grade_id = ?';
            $params[] = $gradeId;
        }
        $sql .= ' ORDER BY se.student_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function page(int $academicYearId, array $request): array
    {
        $where = [
            'se.academic_year_id = ?',
            "se.enrollment_status = 'enrolled'",
            "u.role = 'student'",
            "u.status = 'active'",
            'u.deleted_at IS NULL',
        ];
        $params = [$academicYearId];
        foreach (['class_id' => 'se.class_id', 'grade_id' => 'se.grade_id', 'stage_id' => 'se.stage_id'] as $key => $column) {
            $ids = $this->ids($request[$key] ?? $request[$key . 's'] ?? null);
            if ($ids !== []) {
                $where[] = $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
                break;
            }
        }

        $from = 'student_enrollments se
                 JOIN users u ON u.id = se.student_id
                 LEFT JOIN classes c ON c.id = se.class_id
                 LEFT JOIN grades g ON g.id = se.grade_id
                 LEFT JOIN stages s ON s.id = se.stage_id
                 LEFT JOIN student_profiles sp ON sp.user_id = u.id';
        $baseWhere = implode(' AND ', $where);
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM {$from} WHERE {$baseWhere}");
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        $filteredWhere = $where;
        $filteredParams = $params;
        $search = trim((string) ($request['search']['value'] ?? ''));
        if ($search !== '') {
            $filteredWhere[] = '(u.name LIKE ? OR sp.student_code LIKE ? OR c.name LIKE ? OR g.grade_name LIKE ? OR s.stage_name LIKE ?)';
            for ($i = 0; $i < 5; ++$i) {
                $filteredParams[] = '%' . $search . '%';
            }
        }
        $filteredSql = implode(' AND ', $filteredWhere);
        $filteredStmt = $this->db->prepare("SELECT COUNT(*) FROM {$from} WHERE {$filteredSql}");
        $filteredStmt->execute($filteredParams);
        $filtered = (int) $filteredStmt->fetchColumn();

        $orderMap = [1 => 'sp.student_code', 2 => 'u.name', 3 => 'c.name'];
        $orderColumn = (int) ($request['order'][0]['column'] ?? 2);
        $orderBy = $orderMap[$orderColumn] ?? 'u.name';
        $direction = strtolower((string) ($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $start = max(0, (int) ($request['start'] ?? 0));
        $requestedLength = (int) ($request['length'] ?? 50);
        $length = $requestedLength === -1 ? PHP_INT_MAX : min(500, max(10, $requestedLength));

        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, c.name AS class_name, g.grade_name, s.stage_name, sp.student_code
             FROM {$from}
             WHERE {$filteredSql}
             ORDER BY {$orderBy} {$direction}, u.id ASC
             LIMIT ? OFFSET ?"
        );
        $index = 1;
        foreach ($filteredParams as $param) {
            $stmt->bindValue($index++, $param);
        }
        $stmt->bindValue($index++, $length, PDO::PARAM_INT);
        $stmt->bindValue($index, $start, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function matching(int $academicYearId, array $request): array
    {
        $where = [
            'se.academic_year_id = ?',
            "se.enrollment_status = 'enrolled'",
            "u.role = 'student'",
            "u.status = 'active'",
            'u.deleted_at IS NULL',
        ];
        $params = [$academicYearId];
        foreach (['class_id' => 'se.class_id', 'grade_id' => 'se.grade_id', 'stage_id' => 'se.stage_id'] as $key => $column) {
            $ids = $this->ids($request[$key] ?? $request[$key . 's'] ?? null);
            if ($ids !== []) {
                $where[] = $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
                break;
            }
        }
        $search = trim((string) ($request['search']['value'] ?? ''));
        if ($search !== '') {
            $where[] = '(u.name LIKE ? OR sp.student_code LIKE ? OR c.name LIKE ? OR g.grade_name LIKE ? OR s.stage_name LIKE ?)';
            for ($i = 0; $i < 5; ++$i) {
                $params[] = '%' . $search . '%';
            }
        }
        $stmt = $this->db->prepare(
            'SELECT u.id, u.name, c.name AS class_name, g.grade_name, s.stage_name, sp.student_code
             FROM student_enrollments se
             JOIN users u ON u.id = se.student_id
             LEFT JOIN classes c ON c.id = se.class_id
             LEFT JOIN grades g ON g.id = se.grade_id
             LEFT JOIN stages s ON s.id = se.stage_id
             LEFT JOIN student_profiles sp ON sp.user_id = u.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY u.name, u.id'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<int> */
    private function ids(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', trim((string) $value));
        return array_values(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0));
    }
}
