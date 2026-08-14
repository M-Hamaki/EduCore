<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/admin/ajax/upload_attachment.php');
$download = file_get_contents($root . '/admin/profile_attachment.php');
$studentService = file_get_contents($root . '/src/Modules/Students/StudentAttachmentService.php');
$staffService = file_get_contents($root . '/src/Modules/Staff/StaffAttachmentService.php');
$studentForm = file_get_contents($root . '/src/Modules/Students/Presentation/profile_form.php');
$staffForm = file_get_contents($root . '/src/Modules/Staff/Presentation/profile_form.php');
$studentScript = file_get_contents($root . '/src/Modules/Students/Presentation/profile_scripts.php');
$staffScript = file_get_contents($root . '/src/Modules/Staff/Presentation/profile_form_scripts.php');
$sharedScript = file_get_contents($root . '/assets/js/instant_attachment_upload.js');

$sources = [$endpoint, $download, $studentService, $staffService, $studentForm, $staffForm, $studentScript, $staffScript, $sharedScript];
if (in_array(false, $sources, true)) {
    fwrite(STDERR, "Unable to read attachment management sources.\n");
    exit(1);
}

function sourceSection(string $source, string $start, ?string $next = null): string
{
    $offset = strpos($source, $start);
    if ($offset === false) return '';
    $end = $next === null ? false : strpos($source, $next, $offset + strlen($start));
    return $end === false ? substr($source, $offset) : substr($source, $offset, $end - $offset);
}

$studentDelete = sourceSection($studentService, 'public function delete(', 'public function renameAttachment(');
$staffDelete = sourceSection($staffService, 'public function deleteAttachment(', 'public function renameAttachment(');

$endpointCsrf = strpos($endpoint, 'requireCsrfPost();');
$endpointAction = strpos($endpoint, "in_array(\$attachmentAction, ['rename', 'delete'], true)");

$expectations = [
    'csrf_precedes_metadata_actions' => $endpointCsrf !== false && $endpointAction !== false && $endpointCsrf < $endpointAction,
    'endpoint_delegates_student_and_staff_actions' => strpos($endpoint, 'new StudentAttachmentService(') !== false
        && strpos($endpoint, 'new StaffAttachmentService(') !== false
        && strpos($endpoint, '->renameAttachment(') !== false,
    'student_rename_is_atomic_and_audited' => strpos($studentService, 'public function renameAttachment(') !== false
        && strpos($studentService, 'FOR UPDATE') !== false
        && strpos($studentService, 'ActivityLog::logChange(') !== false
        && strpos($studentService, "throw new RuntimeException('تعذر تسجيل تعديل اسم المرفق") !== false,
    'staff_rename_is_atomic_and_audited' => strpos($staffService, 'public function renameAttachment(') !== false
        && strpos($staffService, 'FOR UPDATE') !== false
        && strpos($staffService, 'ActivityLog::logChange(') !== false
        && strpos($staffService, "throw new RuntimeException('تعذر تسجيل تعديل اسم المرفق") !== false,
    'delete_commits_before_file_cleanup' => strpos($studentDelete, '$this->db->commit();') < strpos($studentDelete, '$this->storage->delete(\'student\'')
        && strpos($staffDelete, '$this->db->commit();') < strpos($staffDelete, '$this->storage->delete(\'staff\''),
    'forms_expose_edit_action_and_shared_modal' => strpos($studentForm, 'att-rename-btn') !== false
        && strpos($staffForm, 'att-rename-btn') !== false
        && strpos($studentForm, 'profile_attachment_label_modal.php') !== false
        && strpos($staffForm, 'profile_attachment_label_modal.php') !== false,
    'client_uses_ajax_for_rename_and_delete' => strpos($sharedScript, 'function mutateProfileAttachment(') !== false
        && strpos($studentScript, "action: 'delete'") !== false
        && strpos($staffScript, "action: 'delete'") !== false
        && strpos($studentScript, 'openProfileAttachmentLabelEditor({') !== false
        && strpos($staffScript, 'openProfileAttachmentLabelEditor({') !== false,
    'download_name_uses_editable_label' => strpos($download, 'ProfileAttachmentLabelPolicy::downloadName(') !== false,
    'profile_image_semantics_require_explicit_label' => strpos($endpoint, '$isProfileImageUpload = $label === ProfileAttachmentLabelPolicy::PROFILE_IMAGE_LABEL;') !== false
        && strpos($endpoint, 'if ($isProfileImageUpload) {') !== false,
];

$failed = [];
foreach ($expectations as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}

exit($failed ? 1 : 0);
