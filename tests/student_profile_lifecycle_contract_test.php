<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileLifecycleService.php');
$command = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');
$checks = [
    'both_paths_delegate' => substr_count($command, '$this->lifecycle->sync(') === 2,
    'account_status_owned' => strpos($service, "return 'graduated';") !== false
        && strpos($service, "return 'inactive';") !== false,
    'external_transfer_retained' => strpos($service, 'saveExternalTransfer(') !== false,
    'enrollment_sync_retained' => strpos($service, 'syncEnrollmentStatus(') !== false,
    'assessment_lock_retained' => strpos($service, 'syncAssessmentLock(') !== false,
    'marks_sync_conditional' => strpos($service, '$syncMovedMarks &&') !== false
        && strpos($service, 'syncAssessmentMarksClass(') !== false,
    'result_contract_used' => strpos($command, "\$lifecycle['academic_year_id']") !== false
        && strpos($command, "\$lifecycle['moved_assessment_marks']") !== false,
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
