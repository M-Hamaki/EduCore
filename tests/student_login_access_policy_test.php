<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/Accounts/StudentLoginAccessPolicy.php';

use EduCore\Modules\Accounts\StudentLoginAccessPolicy;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, status TEXT, login_disabled_reason TEXT, deleted_at TEXT)');
$db->exec('CREATE TABLE student_profiles (id INTEGER PRIMARY KEY, user_id INTEGER, enrollment_status TEXT)');
$db->exec('CREATE TABLE academic_years (id INTEGER PRIMARY KEY, is_active INTEGER)');
$db->exec('CREATE TABLE student_enrollments (id INTEGER PRIMARY KEY, student_id INTEGER, academic_year_id INTEGER, enrollment_status TEXT, academic_status TEXT)');
$db->exec("INSERT INTO academic_years (id, is_active) VALUES (1, 1)");
$db->exec("INSERT INTO users VALUES
    (1, 'student', 'inactive', NULL, NULL),
    (2, 'student', 'inactive', 'رسالة خاصة فقط', NULL),
    (3, 'student', 'active', NULL, NULL),
    (4, 'student', 'inactive', 'سبب إداري', NULL)");
$db->exec("INSERT INTO student_profiles (id, user_id, enrollment_status) VALUES (1, 4, 'graduated')");

$policy = new StudentLoginAccessPolicy($db);

$generic = $policy->decisionForUserId(1);
assert($generic['allowed'] === false);
assert($generic['message'] === StudentLoginAccessPolicy::DEFAULT_DISABLED_MESSAGE);

$custom = $policy->decisionForUserId(2);
assert($custom['allowed'] === false);
assert($custom['message'] === 'رسالة خاصة فقط');

$active = $policy->decisionForUserId(3);
assert($active['allowed'] === true);
assert($active['message'] === null);

$terminal = $policy->decisionForUserId(4);
assert($terminal['code'] === 'graduated');
assert($terminal['message'] === 'تم تخرجك من المدرسة. لا يمكنك تسجيل الدخول.');

echo "STUDENT_LOGIN_ACCESS_POLICY_TEST_PASSED\n";
