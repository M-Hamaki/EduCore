<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Staff\Infrastructure\PdoStaffEmploymentQuery;
use EduCore\Modules\Students\Infrastructure\PdoStudentEnrollmentQuery;

$options = getopt('', ['database:']);
$testDb = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb) || $testDb === 'educore') {
    fwrite(STDERR, "FAILED: --database must name an isolated *_test database.\n");
    exit(1);
}

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4',
        (string) env('DB_USER', 'root'),
        (string) env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $db->exec("CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, role VARCHAR(30), status VARCHAR(30))");
    $db->exec("CREATE TEMPORARY TABLE student_siblings (student_id INT, sibling_id INT, confirmed TINYINT)");
    $db->exec("CREATE TEMPORARY TABLE student_enrollments (student_id INT, academic_year_id INT, enrollment_date DATE)");
    $db->exec("CREATE TEMPORARY TABLE staff_profiles (
        user_id INT PRIMARY KEY, employee_code VARCHAR(30), job_title VARCHAR(100), department VARCHAR(100),
        latest_hire_date DATE NULL, hire_date DATE NULL, contract_start DATE NULL, contract_end DATE NULL,
        last_working_day DATE NULL, current_work_status VARCHAR(30), national_id VARCHAR(20), phone_mobile VARCHAR(20)
    )");
    $db->exec("CREATE TEMPORARY TABLE student_guardians (student_id INT, relationship VARCHAR(30), national_id VARCHAR(20), phone_primary VARCHAR(20))");
    $db->exec("INSERT INTO users VALUES (101, 'student', 'active'), (102, 'student', 'active'), (103, 'student', 'active'), (9, 'teacher', 'active')");
    $db->exec("INSERT INTO student_siblings VALUES (101, 102, 1), (102, 103, 1)");
    $db->exec("INSERT INTO student_enrollments VALUES (101, 77, '2025-09-01'), (102, 77, '2025-09-02'), (103, 77, '2025-09-03')");
    $db->exec("INSERT INTO staff_profiles VALUES (9, 'EMP-9', 'Teacher', 'Primary', '2025-01-01', NULL, NULL, NULL, NULL, 'on_duty', '29901010101010', '01000000009')");
    $db->exec("INSERT INTO student_guardians VALUES (103, 'father', '29901010101010', '01000000009')");

    $students = new PdoStudentEnrollmentQuery($db);
    $staff = new PdoStaffEmploymentQuery($db);
    $family = $students->familyGroupOf(103, 77);
    if (array_column($family, 'student_id') !== [101, 102, 103]) {
        throw new RuntimeException('Confirmed sibling graph was not traversed and ordered deterministically: ' . json_encode($family));
    }
    $contract = $staff->activeContractOf(9, '2026-10-15');
    if ($contract === null || !$contract['is_active']
        || $staff->documentedRelationshipToStudent(9, 103) === null
        || $staff->documentedRelationshipToStudent(9, 102) !== null) {
        throw new RuntimeException('Exact student relationship or due-date employment query failed.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAILED: ' . $exception->getMessage() . "\n");
    exit(1);
}

echo "Discount cross-module PDO query contract test PASSED on {$testDb}.\n";
