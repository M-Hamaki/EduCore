<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$year = (string) file_get_contents($root . '/classes/AcademicYear.php');
$header = (string) file_get_contents($root . '/includes/admin_header.php');
$page = (string) file_get_contents($root . '/admin/students.php');
$ajax = (string) file_get_contents($root . '/admin/ajax_students_datatable.php');
$pageQuery = (string) file_get_contents($root . '/src/Modules/Students/StudentListPageQuery.php');
$dataQuery = (string) file_get_contents($root . '/src/Modules/Students/StudentListDataTableQuery.php');
$repository = (string) file_get_contents($root . '/src/Modules/Students/StudentListReadRepository.php');
$presenter = (string) file_get_contents($root . '/src/Modules/Students/Presentation/StudentListDataTablePresenter.php');
$form = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_form.php');
$requests = (string) file_get_contents($root . '/src/Modules/Students/StudentChangeRequestService.php');

$checks = [
    'specialist_is_forced_to_active_year' => strpos($year, "if (\$role === 'specialist')") !== false
        && strpos($year, 'Utilities::getAdministrativeRoleFamily($role)') !== false
        && strpos($year, 'if (self::roleUsesActiveYearOnly())') !== false,
    'topbar_switch_is_server_denied_and_hidden' => strpos($header, '$__academicYearSwitchAllowed') !== false
        && strpos($header, '!empty($_allAcademicYears) && $__academicYearSwitchAllowed') !== false,
    'exact_admin_students_entrypoint_is_shared' => strpos($page, "include_once '../includes/admin_header.php'") !== false
        && strpos($page, 'new ScopedStaffPortalContext') !== false
        && strpos($page, "\$isSpecialistPortal = \$current_user_role === 'specialist'") !== false,
    'specialist_reads_and_profile_open_are_scoped' => strpos($page, '$allowedStudentClassIds') !== false
        && substr_count($page, '$staffPortalContext->assertStudentAllowed') >= 3,
    'specialist_save_becomes_pending_request' => strpos($page, 'submitProfile(') !== false
        && strpos($page, 'العمليات المعلقة') !== false,
    'specialist_create_and_archive_are_permission_flags' => strpos($page, '$canCreateStudents = !$isSpecialistPortal') !== false
        && strpos($page, '$canArchiveStudents = !$isSpecialistPortal') !== false,
    'filters_and_counts_receive_allowed_classes' => strpos($pageQuery, '?array $allowedClassIds = null') !== false
        && strpos($pageQuery, '$allowedClassIds === []') !== false,
    'datatable_endpoint_enforces_same_context' => strpos($ajax, 'new ScopedStaffPortalContext') !== false
        && strpos($ajax, '$portal->allowedClassIds()') !== false
        && strpos($dataQuery, '$allowedClassIds') !== false,
    'repository_empty_scope_fails_closed' => substr_count($repository, "? ' AND 1 = 0'") >= 2,
    'archive_action_is_removed_from_scoped_rows' => strpos($presenter, 'bool $canArchive = true') !== false
        && strpos($presenter, 'if ($canArchive)') !== false,
    'shared_full_form_explains_pending_review' => strpos($form, '$studentProfilePendingMode') !== false
        && strpos($form, 'إرسال التعديلات للمراجعة') !== false,
    'pending_request_supports_complete_profile' => strpos($requests, "'__format' => 'full_profile_v1'") !== false
        && strpos($requests, 'applyApprovedSpecialistProfile') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
