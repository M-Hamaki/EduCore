<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/staff.php');
$service = (string) file_get_contents($root . '/src/Modules/Staff/StaffAttachmentService.php');
$repository = (string) file_get_contents($root . '/src/Modules/Staff/StaffProfileRepository.php');

$checks = [
    'page_delegates_profile_image' => strpos(
        $page,
        '$staffAttachmentService->uploadProfileImage('
    ) !== false,
    'page_delegates_attachment_delete' => strpos(
        $page,
        '$staffAttachmentService->deleteAttachment('
    ) !== false,
    'service_uses_staff_boundary_for_all_attachment_actions' => substr_count(
        $service,
        '$this->profiles->assertManageableStaff($userId)'
    ) === 3,
    'repository_owns_staff_boundary' => strpos(
        $repository,
        "u.role NOT IN ('admin', 'student')"
    ) !== false,
    'profile_image_update_is_atomic' => strpos($service, 'beginTransaction()') !== false
        && strpos($service, 'FOR UPDATE') !== false
        && strpos($service, '->commit()') !== false
        && strpos($service, '->rollBack()') !== false,
    'new_image_removed_on_database_failure' => strpos($service, '@unlink($destination)') !== false,
    'old_image_removed_after_commit' => strpos($service, 'if ($oldImage !==') > strpos(
        $service,
        '$this->db->commit()'
    ),
    'private_attachment_storage_reused' => strpos(
        $service,
        "\$this->storage->delete('staff'"
    ) !== false,
    'service_does_not_read_superglobals' => strpos($service, '$_POST') === false
        && strpos($service, '$_FILES') === false
        && strpos($service, '$_SESSION') === false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
