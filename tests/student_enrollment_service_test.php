<?php

require_once __DIR__ . '/../classes/StudentEnrollmentService.php';

$service = new StudentEnrollmentService(new PDO('sqlite::memory:'));
$results = [];
$results['current_default'] = $service->normalizeStatus([], 'current') === 'enrolled';
$results['graduate_registration_default'] = $service->normalizeStatus([], 'graduates') === 'enrolled';
$results['graduate_academic_default'] = $service->normalizeAcademicStatus([], 'graduates') === 'graduated';
$results['new_academic_default'] = $service->normalizeAcademicStatus([], 'current') === 'new';
$results['promoted_academic_status'] = $service->normalizeAcademicStatus(['academic_status' => 'promoted'], 'current') === 'promoted';
$results['retained_academic_status'] = $service->normalizeAcademicStatus(['academic_status' => 'retained'], 'current') === 'retained';
$results['discontinued_status'] = $service->normalizeStatus(['enrollment_status' => 'discontinued'], 'current') === 'discontinued';
$results['transferred_complete'] = $service->normalizeStatus([
    'enrollment_status' => 'transferred',
    'transfer_destination' => 'مدرسة أخرى',
    'external_transfer_date' => '2026-07-13',
], 'current') === 'transferred';

try {
    $service->normalizeStatus(['enrollment_status' => 'unknown'], 'current');
    $results['invalid_status_rejected'] = false;
} catch (InvalidArgumentException $e) {
    $results['invalid_status_rejected'] = true;
}

try {
    $service->normalizeAcademicStatus(['academic_status' => 'unknown'], 'current');
    $results['invalid_academic_status_rejected'] = false;
} catch (InvalidArgumentException $e) {
    $results['invalid_academic_status_rejected'] = true;
}

try {
    $service->normalizeStatus(['enrollment_status' => 'transferred'], 'current');
    $results['incomplete_transfer_rejected'] = false;
} catch (InvalidArgumentException $e) {
    $results['incomplete_transfer_rejected'] = true;
}

$failed = array_keys(array_filter($results, static fn($passed) => !$passed));
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit($failed ? 1 : 0);
