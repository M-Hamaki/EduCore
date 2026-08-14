<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/classes/StudentProfileRequestMapper.php';
$migration = (string)file_get_contents($root . '/database/migrations/20260719_student_change_requests.php');
$policy = (string)file_get_contents($root . '/src/Modules/Students/StudentChangeFieldPolicy.php');
$requests = (string)file_get_contents($root . '/src/Modules/Students/StudentChangeRequestService.php');
$commands = (string)file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');
$admin = (string)file_get_contents($root . '/admin/pending_operations.php');
$presenter = (string)file_get_contents($root . '/src/Modules/Students/Presentation/StudentChangeRequestPresenter.php');
$specialist = (string)file_get_contents($root . '/admin/students.php');
$classLists = (string)file_get_contents($root . '/admin/class_lists.php');
$classListScripts = (string)file_get_contents($root . '/classes/Presentation/ClassLists/page_scripts.php');
$studentBootstrap = (string)file_get_contents($root . '/src/Modules/Students/bootstrap.php');

$checks = [
    'request_schema_has_workflow_states' => strpos($migration, 'student_change_requests') !== false
        && strpos($migration, "'pending','approved','rejected','conflict','cancelled'") !== false,
    'full_profile_policy_excludes_credentials_but_keeps_academic_fields' => strpos($policy, "'password'") === false
        && strpos($policy, "'class_id'") !== false
        && strpos($policy, "'grade_id'") !== false
        && strpos($policy, "'guardians'") !== false,
    'specialist_scope_is_checked_on_submit' => strpos($requests, 'assertStudentAllowed') !== false,
    'live_student_is_not_changed_on_submit' => strpos($requests, 'applyApprovedSpecialistChanges') !== false
        && strpos($requests, "status = 'pending'") !== false,
    'approval_detects_conflict' => strpos($requests, "finishReview(\$requestId, 'conflict'") !== false
        && strpos($requests, 'before_payload') !== false,
    'approved_change_uses_student_owner' => strpos($commands, 'applyApprovedSpecialistChanges') !== false
        && strpos($commands, 'applyApprovedSpecialistProfile') !== false
        && strpos($commands, 'recordCompositeUpdate') !== false,
    'class_transfer_is_queued_and_applied_only_after_approval' => strpos($classLists, 'submitClassTransfer') !== false
        && strpos($requests, 'class_transfer_v1') !== false
        && strpos($requests, 'applyApprovedSpecialistClassTransfer') !== false
        && strpos($commands, 'applyApprovedSpecialistClassTransfer') !== false
        && strpos($classListScripts, 'data.pending') !== false,
    'admin_page_owns_approve_reject' => strpos($admin, "validateSession('admin')") !== false
        && strpos($admin, 'approve_request') !== false
        && strpos($admin, 'reject_request') !== false,
    'approval_dependencies_load_through_student_bootstrap' => strpos(
        $studentBootstrap,
        "'/classes/ProfileInputValidator.php'"
    ) !== false && class_exists('ProfileInputValidator', false),
    'pending_changes_show_only_human_readable_actual_diffs' => strpos($admin, 'StudentChangeRequestPresenter') !== false
        && strpos($admin, 'json_encode($value') === false
        && strpos($presenter, 'wasCompositeGroupSubmitted') !== false
        && strpos($presenter, 'externalTransferRows') !== false,
    'specialist_can_list_only_owned_current_year_requests' => strpos($requests, 'listForSpecialist(') !== false
        && strpos($requests, 'WHERE scr.specialist_id = ? AND scr.academic_year_id = ?') !== false,
    'pending_sidebar_count_has_role_aware_contract' => strpos($requests, 'public static function pendingCount(') !== false
        && strpos($requests, 'WHERE specialist_id = ? AND academic_year_id = ?') !== false,
    'admin_request_numbers_are_sequential' => strpos($admin, '$requestRowNumber = 1;') !== false
        && strpos($admin, '<td><?php echo $requestRowNumber++; ?></td>') !== false
        && strpos($admin, "<td><?php echo (int)\$row['id']; ?></td>") === false,
    'academic_display_uses_annual_enrollment_baseline' => strpos($requests, "COALESCE(NULLIF(se.grade_id, 0), c.grade_id) AS grade_id") !== false
        && strpos($requests, "se.enrollment_status = 'enrolled'") !== false,
    'untouched_composite_groups_are_preserved' => strpos($policy, 'omitUntouchedCompositeGroups') !== false
        && strpos($policy, "'student_extra_phones_touched'") !== false
        && strpos($commands, 'StudentChangeFieldPolicy::omitUntouchedCompositeGroups($post)') !== false,
    'specialist_page_has_no_create_or_archive' => strpos($specialist, '$canCreateStudents = !$isSpecialistPortal') !== false
        && strpos($specialist, '$canArchiveStudents = !$isSpecialistPortal') !== false
        && strpos($specialist, 'submitProfile') !== false
        && strpos($specialist, "'instant_attachment_upload' => !\$studentProfilePendingMode") !== false,
    'specialist_keeps_export_and_transfer_actions' => strpos($specialist, 'export_students.php?student_scope=') !== false
        && strpos($classLists, 'transfer-btn') !== false
        && strpos($classLists, 'إرسال للمراجعة') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
