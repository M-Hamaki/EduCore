<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$training = (string) file_get_contents($root . '/classes/Training.php');
$catalog = (string) file_get_contents($root . '/teacher/training.php');
$myTraining = (string) file_get_contents($root . '/teacher/training_my.php');

$checks = [
    'teacher_catalog_delegates_enrollment' => strpos($catalog, '$training->enrollTeacher($teacherId, $courseId)') !== false
        && strpos($catalog, 'INSERT INTO training_enrollments') === false,
    'enrollment_owner_is_atomic_and_audited' => strpos($training, 'function enrollTeacher') !== false
        && strpos($training, "'training_enrollment'") !== false
        && strpos($training, 'beginTransaction()') !== false
        && strpos($training, 'recordEvent(') !== false,
    'duplicate_enrollment_is_not_logged_as_insert' => strpos($training, 'if ($stmt->rowCount() === 1)') !== false,
    'my_training_page_is_server_read_only' => !preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+[a-z_]|DELETE\s+FROM)\b/i', $myTraining)
        && strpos($myTraining, '$training->enrollTeacher') === false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}

exit($failed ? 1 : 0);
