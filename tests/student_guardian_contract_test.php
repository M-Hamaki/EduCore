<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentGuardianService.php');
$command = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');
$checks = [
    'both_paths_delegate' => substr_count($command, '$this->guardians->save(') === 2,
    'edit_replaces' => strpos($command, "\$post['guardians'] ?? [], true") !== false,
    'create_does_not_replace' => strpos($command, "\$post['guardians'] ?? [], false") !== false,
    'warning_contract_retained' => strpos($command, 'لم تتم كتابة اسم كل من:') !== false,
    'delete_owned_by_service' => strpos($service, 'DELETE FROM student_guardians WHERE student_id = ?') !== false,
    'save_failure_retained' => strpos($service, 'فشل حفظ بيانات أحد أولياء الأمور.') !== false,
    'payload_helpers_reused' => strpos($service, 'guardianExtraPhones(') !== false
        && strpos($service, 'guardianExtraData(') !== false,
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
