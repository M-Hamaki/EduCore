<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/StudentEnrollmentService.php';
require_once dirname(__DIR__) . '/classes/StudentEnrollment.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($databaseName === 'educore' || !preg_match('/_test$/', $databaseName)) {
    throw new RuntimeException('Refusing to write outside an explicit isolated _test database.');
}

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['name'] = 'Annual status integration test';

$suffix = bin2hex(random_bytes(4));
$checks = [];
$db->beginTransaction();
try {
    $yearStmt = $db->prepare(
        "INSERT INTO academic_years (name, start_date, end_date, is_active, status, locked)
         VALUES (?, '2098-09-01', '2099-06-30', 1, 'active', 0)"
    );
    $yearStmt->execute(['2098-2099-' . $suffix]);
    $yearId = (int) $db->lastInsertId();

    $stageStmt = $db->prepare(
        "INSERT INTO stages (stage_name, stage_code, status) VALUES (?, ?, 'active')"
    );
    $stageStmt->execute(['مرحلة اختبار ' . $suffix, 'AST' . $suffix]);
    $stageId = (int) $db->lastInsertId();

    $gradeStmt = $db->prepare(
        "INSERT INTO grades (grade_name, grade_code, stage_id, status) VALUES (?, ?, ?, 'active')"
    );
    $gradeStmt->execute(['صف اختبار ' . $suffix, 'AGT' . $suffix, $stageId]);
    $gradeId = (int) $db->lastInsertId();

    $classStmt = $db->prepare(
        "INSERT INTO classes (name, grade_id, status, academic_year_id) VALUES (?, ?, 'active', ?)"
    );
    $classStmt->execute(['فصل اختبار ' . $suffix, $gradeId, $yearId]);
    $classId = (int) $db->lastInsertId();

    $userStmt = $db->prepare(
        "INSERT INTO users (name, username, role, status, class_id) VALUES (?, ?, 'student', 'active', ?)"
    );
    $userStmt->execute(['طالب اختبار ' . $suffix, 'annual_' . $suffix, $classId]);
    $studentId = (int) $db->lastInsertId();

    $profileStmt = $db->prepare(
        "INSERT INTO student_profiles
         (user_id, student_code, first_name_ar, grade_id, enrollment_status)
         VALUES (?, ?, ?, ?, 'enrolled')"
    );
    $profileStmt->execute([$studentId, 'AS' . $suffix, 'طالب', $gradeId]);

    $service = new StudentEnrollmentService($db);
    $service->syncEnrollmentStatus(
        $studentId,
        $yearId,
        $gradeId,
        $classId,
        'enrolled',
        'new'
    );
    $first = StudentEnrollment::getStudentEnrollment($db, $studentId, $yearId);
    $checks['initial_status_and_placement_persisted'] = $first
        && (string) $first['enrollment_status'] === 'enrolled'
        && (string) $first['academic_status'] === 'new'
        && (int) $first['stage_id'] === $stageId
        && (int) $first['grade_id'] === $gradeId
        && (int) $first['class_id'] === $classId;

    $service->syncEnrollmentStatus(
        $studentId,
        $yearId,
        $gradeId,
        $classId,
        'discontinued',
        'retained'
    );
    $updated = StudentEnrollment::getStudentEnrollment($db, $studentId, $yearId);
    $checks['independent_statuses_update_same_annual_row'] = $updated
        && (int) $updated['id'] === (int) $first['id']
        && (string) $updated['enrollment_status'] === 'discontinued'
        && (string) $updated['academic_status'] === 'retained';

    $invalidClassStmt = $db->prepare(
        "INSERT INTO classes (name, grade_id, status, academic_year_id) VALUES (?, ?, 'active', NULL)"
    );
    $invalidClassStmt->execute(['فصل خارج العام ' . $suffix, $gradeId]);
    try {
        $service->syncEnrollmentStatus(
            $studentId,
            $yearId,
            $gradeId,
            (int) $db->lastInsertId(),
            'enrolled',
            'promoted'
        );
        $checks['class_outside_year_rejected'] = false;
    } catch (InvalidArgumentException $error) {
        $checks['class_outside_year_rejected'] = true;
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
