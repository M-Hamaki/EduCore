<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/AcademicYear.php';
require_once dirname(__DIR__) . '/classes/StudentListPageQuery.php';
require_once dirname(__DIR__) . '/src/Modules/Students/StudentListReadRepository.php';

use EduCore\Modules\Students\StudentListReadRepository;

$db = (new Database())->getConnection();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/_test$/', $databaseName)) {
    fwrite(STDERR, "Refusing to query a non-test database.\n");
    exit(2);
}

$db->beginTransaction();
if ((int) $db->query('SELECT COUNT(*) FROM academic_years')->fetchColumn() === 0) {
    $db->exec("INSERT INTO academic_years (name, is_active, status) VALUES ('2098-2099', 1, 'active')");
    $yearId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO stages (stage_name, stage_code, status) VALUES ('اختبار', 'TEST', 'active')");
    $stageId = (int) $db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO grades (grade_name, grade_code, stage_id, status) VALUES ('صف اختبار', 'TG', ?, 'active')");
    $stmt->execute([$stageId]);
    $gradeId = (int) $db->lastInsertId();
    $classIds = [];
    foreach (['فصل أ', 'فصل ب'] as $className) {
        $stmt = $db->prepare("INSERT INTO classes (name, grade_id, status, academic_year_id) VALUES (?, ?, 'active', ?)");
        $stmt->execute([$className, $gradeId, $yearId]);
        $classId = (int) $db->lastInsertId();
        $classIds[] = $classId;
        $stmt = $db->prepare("INSERT INTO users (name, role, status, class_id) VALUES (?, 'student', 'active', ?)");
        $stmt->execute(['طالب ' . $className, $classId]);
        $studentId = (int) $db->lastInsertId();
        $stmt = $db->prepare('INSERT INTO student_profiles (user_id, student_code, first_name_ar, grade_id, enrollment_status) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, 'T' . $studentId, 'طالب', $gradeId, 'enrolled']);
        $stmt = $db->prepare('INSERT INTO student_enrollments (student_id, academic_year_id, stage_id, grade_id, class_id, enrollment_status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, $yearId, $stageId, $gradeId, $classId, 'enrolled']);
    }
}

$classIds = array_map('intval', $db->query("SELECT id FROM classes WHERE status = 'active' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN) ?: []);
$repository = new StudentListReadRepository($db);
$checks = [];

$adminTotal = 0;
$repository->fetch(null, null, 10, 0, $adminTotal, null, null, 'current', null, null, 'name', 'asc', ['class_id']);

$emptyTotal = 0;
$emptyRows = $repository->fetch(null, [], 10, 0, $emptyTotal, null, null, 'current', null, null, 'name', 'asc', ['class_id']);
$checks['empty_scope_returns_no_students'] = $emptyTotal === 0 && $emptyRows === [];

if ($classIds !== []) {
    $allowed = [$classIds[0]];
    $scopedTotal = 0;
    $scopedRows = $repository->fetch(null, $allowed, 100, 0, $scopedTotal, null, null, 'current', null, null, 'name', 'asc', ['class_id']);
    $checks['scoped_total_never_exceeds_admin'] = $scopedTotal <= $adminTotal;
    $checks['every_scoped_row_belongs_to_allowed_class'] = array_reduce(
        $scopedRows,
        static fn(bool $valid, array $row): bool => $valid && in_array((int) ($row['class_id'] ?? 0), $allowed, true),
        true
    );
} else {
    $checks['scoped_total_never_exceeds_admin'] = true;
    $checks['every_scoped_row_belongs_to_allowed_class'] = true;
}

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
$db->rollBack();
exit($failed ? 1 : 0);
