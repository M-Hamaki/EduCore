<?php

require_once __DIR__ . '/bootstrap_test_database.php';
require_once __DIR__ . '/../classes/user.php';
require_once __DIR__ . '/../classes/StudentProfileRepository.php';

$db = educoreTestDatabase();
$db->beginTransaction();
$results = [];
try {
    $token = 'repository-' . bin2hex(random_bytes(5));
    $stmt = $db->prepare("INSERT INTO users (name, username, password, role, class_id) VALUES (?, NULL, NULL, 'student', NULL)");
    $stmt->execute([$token]);
    $studentId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO student_profiles (user_id) VALUES (?)')->execute([$studentId]);

    $repository = new StudentProfileRepository($db);
    $snapshot = $repository->activitySnapshot($studentId);
    $results['snapshot_reads_student'] = ($snapshot['name'] ?? null) === $token;
    $results['snapshot_name_fallback'] = ($snapshot['first_name_ar'] ?? null) === $token;
    $results['snapshot_guardian_count'] = (int) ($snapshot['guardian_count'] ?? -1) === 0;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$failed = array_keys(array_filter($results, static fn($passed) => !$passed));
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit($failed ? 1 : 0);
