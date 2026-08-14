<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$db = (new Database())->getConnection();
$databaseName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/_test$/', $databaseName)) {
    fwrite(STDERR, "Refusing to inspect a non-test database.\n");
    exit(2);
}

$objectType = static function (PDO $db, string $name): string {
    $stmt = $db->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$name]);
    return (string)($stmt->fetchColumn() ?: '');
};
$columns = static function (PDO $db, string $table): array {
    $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
};

$roleStmt = $db->query("SELECT role_key FROM staff_roles WHERE role_key IN ('specialist','doctor','librarian') AND portal_type = 'admin_like' AND status = 'active'");
$registeredRoles = $roleStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
$doctorPageStmt = $db->query("SELECT COUNT(*) FROM staff_role_pages WHERE role_key = 'doctor' AND page_name IN ('student_clinic.php','ajax_clinic_datatable.php')");
$librarianPageStmt = $db->query("SELECT COUNT(*) FROM staff_role_pages WHERE role_key = 'librarian' AND page_name IN ('library.php','ajax_library_datatable.php','ajax_library_lookup.php')");
$specialistPages = [
    'students.php', 'ajax_students_datatable.php', 'class_lists.php', 'attendance.php',
    'student_file.php', 'student_id_cards.php', 'export_students.php', 'student_statistics.php',
    'calculation_tools.php', 'student_evaluations.php', 'teacher_evaluations.php',
    'evaluation_analytics.php', 'evaluation_reports.php', 'student_clinic.php',
    'ajax_clinic_datatable.php',
];
$specialistMarks = implode(',', array_fill(0, count($specialistPages), '?'));
$specialistPageStmt = $db->prepare("SELECT COUNT(*) FROM staff_role_pages WHERE role_key = 'specialist' AND page_name IN ({$specialistMarks})");
$specialistPageStmt->execute($specialistPages);

$checks = [
    'generic_grade_table_created' => $objectType($db, 'staff_grade_assignments') === 'BASE TABLE',
    'generic_class_table_created' => $objectType($db, 'staff_class_assignments') === 'BASE TABLE',
    'generic_active_scope_view_created' => $objectType($db, 'staff_active_classes') === 'VIEW',
    'compatibility_view_preserved' => $objectType($db, 'specialist_active_classes') === 'VIEW',
    'scope_tables_are_annual' => in_array('academic_year_id', $columns($db, 'staff_grade_assignments'), true)
        && in_array('academic_year_id', $columns($db, 'staff_class_assignments'), true),
    'scoped_roles_are_registered' => count(array_intersect(['specialist', 'doctor', 'librarian'], $registeredRoles)) === 3,
    'doctor_clinic_surface_is_activated' => (int)$doctorPageStmt->fetchColumn() === 2,
    'librarian_library_surface_is_activated' => (int)$librarianPageStmt->fetchColumn() === 3,
    'specialist_shared_admin_surfaces_are_activated' => (int)$specialistPageStmt->fetchColumn() === count($specialistPages),
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
