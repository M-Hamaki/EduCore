<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$students = (string) file_get_contents($root . '/specialist/students.php');
$reports = (string) file_get_contents($root . '/specialist/evaluation_reports.php');
$mode = (string) file_get_contents($root . '/supervisor/select_mode.php');
$requests = (string) file_get_contents($root . '/src/Modules/Students/StudentChangeRequestService.php');
$commands = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');
$authorization = (string) file_get_contents($root . '/classes/AuthorizationFacade.php');

$checks = [
    'specialist_cannot_create_or_archive_students' => strpos($students, 'submit_student_change_request') !== false
        && strpos($students, 'add_student') === false && strpos($students, 'archive_student') === false,
    'specialist_change_request_is_atomic_and_audited' => strpos($requests, 'beginTransaction()') !== false
        && strpos($requests, 'FOR UPDATE') !== false && strpos($requests, 'recordEvent') !== false
        && strpos($requests, 'rollBack()') !== false,
    'approved_change_delegates_to_student_owner' => strpos($requests, 'applyApprovedSpecialistChanges') !== false
        && strpos($commands, 'recordCompositeUpdate') !== false,
    'student_enrollment_is_not_editable_by_specialist' => strpos($students, "name=\"class_id\"") === false
        && strpos($students, "name=\"grade_id\"") === false,
    'specialist_reports_are_server_read_only' => !preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+[a-z_]|DELETE\s+FROM)\b/i', $reports),
    'supervisor_get_and_post_mode_switches_are_audited' => substr_count($mode, 'recordSupervisorModeSwitch(') === 3
        && strpos($mode, "'mode_before'") !== false
        && strpos($mode, "'mode_after'") !== false,
    'supervisor_mode_switch_audit_precedes_session_change' => strpos($mode, 'recordSupervisorModeSwitch((string) $mode);') < strpos($mode, "\$_SESSION['active_mode'] = \$mode;")
        && strpos($mode, "'persistent_data_changed' => false") !== false,
    'supervisor_mode_is_decoupled_from_specialist' => strpos($authorization, "return 'supervisor';") !== false
        && strpos($mode, "header('Location: index.php')") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}

exit($failed ? 1 : 0);
