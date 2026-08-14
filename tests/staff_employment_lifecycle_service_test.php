<?php

require_once __DIR__ . '/../classes/StaffEmploymentLifecycleService.php';
$service = new StaffEmploymentLifecycleService(new PDO('sqlite::memory:'));
$events = $service->normalizeStatusHistory([], ['hire_date' => '2020-09-01', 'job_title' => 'معلم']);
$custom = $service->normalizeStatusHistory(['status_history' => json_encode([[
    'movement_type' => 'أخرى', 'movement_type_custom' => 'عودة خاصة', 'status_after' => 'on_duty',
    'effective_date' => '2026-01-01', 'job_title' => 'أخرى', 'job_title_custom' => 'منسق',
]], JSON_UNESCAPED_UNICODE)], []);
$movements = $service->normalizeJobMovements(['promotions' => json_encode([[
    'type' => 'ترقية', 'new_job_title' => 'معلم أول', 'effective_date' => '2026-02-01',
]], JSON_UNESCAPED_UNICODE)]);
$profile = [];
$service->applyStatusSummary($profile, $custom);
$results = [
    'default_hire_event' => count($events) === 1 && $events[0]['effective_date'] === '2020-09-01',
    'custom_status_normalized' => ($custom[0]['movement_type'] ?? null) === 'عودة خاصة' && ($custom[0]['job_title'] ?? null) === 'منسق',
    'summary_applied' => ($profile['job_title'] ?? null) === 'منسق',
    'movement_normalized' => ($movements[0]['new_job_title'] ?? null) === 'معلم أول',
];
$failed = array_keys(array_filter($results, static fn($passed) => !$passed));
foreach ($results as $name => $passed) echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
exit($failed ? 1 : 0);
