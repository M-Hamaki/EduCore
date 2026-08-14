<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/staff_accounts.php');
$listQuery = (string) file_get_contents($root . '/classes/AccountListDataTableQuery.php');
$authorization = (string) file_get_contents($root . '/classes/AuthorizationFacade.php');
$login = (string) file_get_contents($root . '/login.php');

$updateStart = strpos($page, "if (\$action === 'update_role_access')");
$updateEnd = strpos($page, '// جلب العامل المستهدف', $updateStart ?: 0);
$updateWorkflow = $updateStart !== false && $updateEnd !== false
    ? substr($page, $updateStart, $updateEnd - $updateStart)
    : '';

$checks = [
    'supervisor_control_is_teacher_only' => strpos($page, 'id="teacherSupervisorField"') !== false
        && strpos($page, 'name="is_supervisor"') !== false
        && strpos($page, "var isTeacher = selectedRoles.indexOf('teacher') !== -1;") !== false
        && strpos($page, 'supervisorInput.disabled = !isTeacher;') !== false,
    'submitted_flag_is_forced_off_for_non_teachers' => strpos(
        $updateWorkflow,
        "\$newIsSupervisor = in_array('teacher', \$selectedRoles, true)"
    ) !== false,
    'current_flag_round_trips_through_list_and_modal' => strpos($listQuery, 'u.is_supervisor') !== false
        && strpos($listQuery, "(\$isSupervisor ? '1' : '0')") !== false
        && strpos($page, 'currentSupervisor)') !== false
        && strpos($page, "Number(currentSupervisor) === 1") !== false,
    'account_update_persists_and_audits_supervisor_flag' => strpos($updateWorkflow, "'is_supervisor' => ['old'") !== false
        && strpos($updateWorkflow, 'UPDATE users SET is_supervisor = ?, status = ?') !== false
        && strpos($updateWorkflow, "ActivityLog::logUpdate('staff_account'") !== false,
    'employee_conversion_clears_supervisor_flag' => strpos($updateWorkflow, 'is_supervisor = 0') !== false,
    'teacher_list_identifies_supervisor_capability' => strpos($listQuery, 'fa-user-shield') !== false
        && strpos($listQuery, 'مشرف</span>') !== false,
    'login_and_authorization_use_saved_flag' => strpos($login, "\$_SESSION['is_supervisor']") !== false
        && strpos($authorization, "\$role === 'teacher' && !empty(\$session['is_supervisor'])") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
