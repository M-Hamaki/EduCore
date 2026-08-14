<?php

require_once __DIR__ . '/bootstrap_test_database.php';
require_once __DIR__ . '/../classes/StaffEmploymentLifecycleService.php';

$db = educoreTestDatabase();
$_SESSION['user_id'] = 987654325;
$_SESSION['name'] = 'Staff Lifecycle Test';
$_SESSION['role'] = 'admin';
$db->beginTransaction();
$results = [];
try {
    $token = 'lifecycle-' . bin2hex(random_bytes(5));
    $db->prepare("INSERT INTO users (name, username, password, role, class_id) VALUES (?, NULL, NULL, 'teacher', NULL)")->execute([$token]);
    $userId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO staff_profiles (user_id, full_name_ar) VALUES (?, ?)')->execute([$userId, $token]);

    $service = new StaffEmploymentLifecycleService($db);
    $events = $service->normalizeStatusHistory(['status_history' => json_encode([[
        'movement_type' => 'تعيين', 'status_after' => 'on_duty', 'status_label' => 'على رأس العمل',
        'effective_date' => '2024-09-01', 'job_title' => 'معلم',
    ]], JSON_UNESCAPED_UNICODE)], []);
    $movements = $service->normalizeJobMovements(['promotions' => json_encode([[
        'type' => 'ترقية', 'new_job_title' => 'معلم أول', 'effective_date' => '2025-09-01',
    ]], JSON_UNESCAPED_UNICODE)]);
    $service->syncStatusHistory($userId, $events, null);
    $service->syncJobMovements($userId, $movements, null);

    $profile = $db->query('SELECT current_work_status, first_hire_date, job_title, last_job_movement_date FROM staff_profiles WHERE user_id = ' . $userId)->fetch(PDO::FETCH_ASSOC);
    $results['status_history_saved'] = (int) $db->query('SELECT COUNT(*) FROM staff_status_history WHERE user_id = ' . $userId)->fetchColumn() === 1;
    $results['movement_saved'] = (int) $db->query('SELECT COUNT(*) FROM staff_job_movements WHERE user_id = ' . $userId)->fetchColumn() === 1;
    $results['status_summary_saved'] = ($profile['current_work_status'] ?? null) === 'on_duty' && ($profile['first_hire_date'] ?? null) === '2024-09-01';
    $results['movement_summary_saved'] = ($profile['job_title'] ?? null) === 'معلم أول' && ($profile['last_job_movement_date'] ?? null) === '2025-09-01';
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

$failed = array_keys(array_filter($results, static fn($passed) => !$passed));
foreach ($results as $name => $passed) echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
exit($failed ? 1 : 0);
