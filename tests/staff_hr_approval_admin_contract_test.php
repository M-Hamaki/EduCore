<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/hr_center.php');
$inbox = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/manager_approval_inbox.php');
$service = (string) file_get_contents($root . '/src/Modules/Staff/Application/Approval/ApprovalWorkflowService.php');

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$authAt = strpos($page, "Utilities::validateSession('admin')");
$databaseAt = strpos($page, 'new Database()');
$csrfAt = strpos($page, 'hash_equals($_SESSION[\'csrf_token\'] ?? \'\', $csrfToken)');
$decisionAt = strpos($page, '$approvalWorkflowService->decide([');

$checks = [
    'authenticated_before_data_access' => $authAt !== false && $databaseAt !== false && $authAt < $databaseAt,
    'new_inbox_is_feature_flagged' => str_contains($page, 'StaffHrFeatureFlags::fromEnvironment()')
        && str_contains($page, '$staffHrFlags->exposesNewResults()'),
    'current_user_scoped_read_model' => str_contains($page, '$approvalInboxQuery->forAssignee(')
        && str_contains($page, "(int) (\$_SESSION['user_id'] ?? 0)")
        && str_contains($page, "'resource_type' => 'permission_request'"),
    'decision_is_csrf_guarded_before_write' => $csrfAt !== false && $decisionAt !== false && $csrfAt < $decisionAt,
    'page_delegates_decision_to_state_machine' => str_contains($page, "'approval_intent'] ?? '') === 'decide'")
        && str_contains($page, '$approvalWorkflowService->decide([')
        && !str_contains($page, 'UPDATE staff_approval_'),
    'only_assigned_component_receives_actions' => str_contains($page, 'ManagerApprovalInbox::renderInbox([')
        && str_contains($page, "'action_url' => 'hr_center.php?tab=assigned_approvals'")
        && str_contains($page, "'actions' => ["),
    'legacy_actions_are_disabled_after_official_cutover' => str_contains($page, '$legacyQuickActionsAvailable = !$showsAssignedApprovals || $staffHrFlags->usesLegacyFallback()')
        && str_contains($page, 'قرار مقيّد بالاعتماد المعيّن'),
    'assigned_errors_are_safely_presented' => str_contains($page, "['kind' => 'danger', 'code' => \$e->getMessage()]")
        && str_contains($inbox, 'PERMISSION_APPROVAL_OUTCOME_STALE')
        && str_contains($inbox, 'SQLSTATE|PDO|DUPLICATE|STACK|TRACE'),
    'state_machine_rechecks_assignment' => str_contains($service, '$this->eligibleAssignee($assignees, $command[\'actor_id\'])')
        && str_contains($service, '$this->assertTransitionAuthorized('),
    'presentation_does_not_accept_mutable_staff_identity' => !str_contains($inbox, 'name="staff_user_id"')
        && !str_contains($inbox, 'name="resource_id"'),
];

foreach ($checks as $name => $passed) {
    $assert($passed, $name);
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff-HR approval admin contract tests passed.\n";
