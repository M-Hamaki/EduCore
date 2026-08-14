<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = (new Database())->getConnection();
    $yearId = AcademicYear::currentId($db);
    $portalContext = new ScopedStaffPortalContext($db, $yearId);
    $allowedClassIds = $portalContext->allowedClassIds();
    $type = (string)($_POST['type'] ?? '');
    $term = trim((string)($_POST['q'] ?? ''));
    $like = '%' . $term . '%';
    $rows = [];
    $appendClassScope = static function (array &$where, array &$params, string $classColumn, ?array $classIds): void {
        if ($classIds === null) {
            return;
        }
        if ($classIds === []) {
            $where[] = '1 = 0';
            return;
        }
        $where[] = $classColumn . ' IN (' . implode(',', array_fill(0, count($classIds), '?')) . ')';
        array_push($params, ...$classIds);
    };

    if ($type === 'students') {
        $stageId = !empty($_POST['stage_id']) ? (int)$_POST['stage_id'] : 0;
        $gradeId = !empty($_POST['grade_id']) ? (int)$_POST['grade_id'] : 0;
        $classId = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : 0;

        $params = $yearId > 0 ? [$yearId] : [];
        $enrollmentJoin = $yearId > 0
            ? "LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'"
            : '';
        $classColumn = $yearId > 0 ? 'se.class_id' : 'u.class_id';
        $where = ["u.role = 'student'", "u.status = 'active'", 'u.deleted_at IS NULL'];
        $appendClassScope($where, $params, $classColumn, $allowedClassIds);

        $joins = $enrollmentJoin . ' LEFT JOIN classes c ON c.id = ' . $classColumn . ' LEFT JOIN grades g ON g.id = c.grade_id';
        if ($classId > 0) {
            $where[] = $classColumn . ' = ?';
            $params[] = $classId;
        } elseif ($gradeId > 0) {
            $where[] = 'c.grade_id = ?';
            $params[] = $gradeId;
        } elseif ($stageId > 0) {
            $where[] = 'g.stage_id = ?';
            $params[] = $stageId;
        }

        if ($term !== '') {
            $where[] = '(u.name LIKE ? OR sp.student_code LIKE ?)';
            array_push($params, $like, $like);
        }
        $limit = ($term !== '') ? 50 : 1000;
        $stmt = $db->prepare('SELECT DISTINCT u.id, u.name, sp.student_code, c.name AS class_name FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id ' . $joins . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.name LIMIT ' . $limit);
        $stmt->execute($params);
        $rows = array_map(static function (array $row): array {
            $codeStr = $row['student_code'] ? $row['student_code'] . ' - ' : '';
            $classStr = !empty($row['class_name']) ? ' (' . $row['class_name'] . ')' : '';
            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'student_code' => $row['student_code'] ?? '',
                'class_name' => $row['class_name'] ?? '',
                'label' => trim($codeStr . $row['name'] . $classStr)
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } elseif ($type === 'books') {
        $category = trim((string)($_POST['category'] ?? ''));
        $where = ['copies_available > 0'];
        $params = [];
        if ($category !== '') {
            $where[] = 'category = ?';
            $params[] = $category;
        }
        if ($term !== '') {
            $where[] = '(title LIKE ? OR author LIKE ? OR category LIKE ? OR isbn LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $limit = ($term !== '' || $category !== '') ? 100 : 1000;
        $stmt = $db->prepare('SELECT id, title, category, author, copies_available FROM library_books WHERE ' . implode(' AND ', $where) . ' ORDER BY title LIMIT ' . $limit);
        $stmt->execute($params);
        $rows = array_map(static function (array $row): array {
            $categoryStr = !empty($row['category']) ? ' [' . $row['category'] . ']' : '';
            return [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'category' => $row['category'] ?? '',
                'author' => $row['author'] ?? '',
                'copies_available' => (int)$row['copies_available'],
                'label' => $row['title'] . $categoryStr . ' (المتاح: ' . $row['copies_available'] . ')'
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } elseif ($type === 'staff') {
        $where = ["u.role <> 'student'", "u.status = 'active'", 'u.deleted_at IS NULL'];
        $params = [];
        if ($term !== '') {
            $where[] = 'u.name LIKE ?';
            $params[] = $like;
        }
        $stmt = $db->prepare('SELECT u.id, u.name, u.role FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY u.name LIMIT 500');
        $stmt->execute($params);
        $roleLabels = [
            'teacher' => 'معلم',
            'employee' => 'موظف',
            'specialist' => 'أخصائي',
            'supervisor' => 'مشرف',
            'admin' => 'مسؤول',
            'super_admin' => 'مدير النظام'
        ];
        $rows = array_map(static function (array $row) use ($roleLabels): array {
            $roleStr = isset($roleLabels[$row['role']]) ? ' [' . $roleLabels[$row['role']] . ']' : '';
            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'role' => $row['role'] ?? '',
                'label' => $row['name'] . $roleStr
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } elseif ($type === 'loans') {
        $params = $yearId > 0 ? [$yearId] : [];
        $enrollmentJoin = $yearId > 0
            ? "LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'"
            : '';
        $classColumn = $yearId > 0 ? 'se.class_id' : 'u.class_id';
        $where = ["l.status <> 'returned'"];
        $appendClassScope($where, $params, $classColumn, $allowedClassIds);
        $where[] = '(b.title LIKE ? OR u.name LIKE ?)';
        array_push($params, $like, $like);
        $stmt = $db->prepare('SELECT l.id, b.title, u.name AS student_name FROM library_loans l JOIN library_books b ON b.id = l.book_id JOIN users u ON u.id = l.student_id ' . $enrollmentJoin . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY l.borrowed_at DESC LIMIT 50');
        $stmt->execute($params);
        $rows = array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'label' => $row['title'] . ' - ' . $row['student_name']], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } else {
        throw new InvalidArgumentException('نوع بحث غير صالح.');
    }
    echo json_encode(['results' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    error_log('Library lookup: ' . $e->getMessage());
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
}
