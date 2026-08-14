<?php
require_once '../../config/database.php';
require_once '../../classes/utilities.php';
require_once '../../classes/AcademicYear.php';
require_once '../../includes/session_config.php';
Utilities::validateSession('admin');

header('Content-Type: application/json; charset=utf-8');

$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);

$classIds = $_GET['class_ids'] ?? '';
if (empty($classIds)) {
    echo json_encode([]);
    exit;
}

// تنقية معرفات الفصول (أرقام فقط)
$ids = array_filter(array_map('intval', explode(',', $classIds)));
if (empty($ids)) {
    echo json_encode([]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
if ($currentAcademicYearId > 0) {
    // طلاب العام الحالي من التسجيلات السنوية
    $stmt = $db->prepare("SELECT u.id, u.name, se.class_id AS class_id, c.name as class_name
        FROM users u
        JOIN student_enrollments se ON se.student_id = u.id
            AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
        LEFT JOIN classes c ON c.id = se.class_id
        WHERE u.role = 'student' AND u.status = 'active'
          AND se.class_id IN ($placeholders)
        ORDER BY c.name, u.name");
    $stmt->execute(array_merge([$currentAcademicYearId], $ids));
} else {
    $stmt = $db->prepare("SELECT u.id, u.name, u.class_id, c.name as class_name
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE u.role = 'student' AND u.status = 'active'
          AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id = u.id AND sp.enrollment_status <> 'enrolled')
          AND u.class_id IN ($placeholders)
        ORDER BY c.name, u.name");
    $stmt->execute($ids);
}

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
