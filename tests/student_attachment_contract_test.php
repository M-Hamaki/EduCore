<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentAttachmentService.php');

$checks = [
    'actions_retained' => strpos($page, "'upload_student_attachment'") !== false
        && strpos($page, "'delete_student_attachment'") !== false,
    'fields_retained' => strpos($page, "\$_POST['attachment_label']") !== false
        && strpos($page, "\$_FILES['attachment_file']") !== false
        && strpos($page, "\$_POST['attachment_id']") !== false,
    'delegates_to_service' => strpos($page, '$studentAttachmentService->upload(') !== false
        && strpos($page, '$studentAttachmentService->delete(') !== false,
    'private_storage_retained' => strpos($service, "storeUploadedFile(") !== false
        && strpos($service, "'student'") !== false,
    'cleanup_retained' => strpos($service, "\$this->storage->delete('student', \$storedName)") !== false,
    'audit_retained' => substr_count($service, "ActivityLog::log('update', 'student'") === 2,
    'redirect_tab_retained' => strpos($page, '"&tab=attachments" . $backQueryAmp') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
