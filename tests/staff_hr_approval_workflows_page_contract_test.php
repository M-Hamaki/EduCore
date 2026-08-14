<?php

declare(strict_types=1);

/** Static safety contract for the protected workflow/delegation admin surface. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$file = dirname(__DIR__) . '/admin/hr_approval_workflows.php';
$source = file_get_contents($file);
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: could not read approval workflows admin surface\n");
    exit(1);
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo $message . ':' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        ++$failures;
    }
};

$authOffset = strpos($source, "Utilities::validateSession('admin')");
$postOffset = strpos($source, '$_SERVER[\'REQUEST_METHOD\'] === \'POST\'');
$assert($authOffset !== false && $postOffset !== false && $authOffset < $postOffset, 'auth_precedes_post_processing');
$assert(str_contains($source, 'hash_equals(') && str_contains($source, "name=\"csrf_token\""), 'csrf_is_checked_and_rendered');
$assert(str_contains($source, 'new StaffModuleFactory(') && str_contains($source, 'approvalAdministration()'), 'page_uses_staff_composition_root');
$assert(!str_contains($source, '$db->prepare(') && !str_contains($source, '$db->query(') && !str_contains($source, '$db->exec('), 'page_has_no_direct_sql');
$assert(str_contains($source, 'ApprovalAdministrationErrorPresenter::message'), 'domain_errors_are_presented_safely');
$assert(str_contains($source, 'stage_user_ids[') && str_contains($source, 'stage_role_keys['), 'stage_assignees_use_friendly_selectors');
$assert(!str_contains($source, 'name="resolver_config"') && !str_contains($source, 'name="snapshot_json"'), 'raw_workflow_json_is_not_exposed_as_input');
$assert(str_contains($source, 'data-bs-toggle="modal"') && str_contains($source, 'workflowActionModal') && str_contains($source, 'delegationActionModal'), 'state_changes_use_bootstrap_modals');
$assert(!str_contains($source, 'confirm(') && !str_contains($source, 'Swal'), 'browser_confirm_and_swal_are_absent');
$assert(str_contains($source, 'create_workflow_version') && str_contains($source, 'create_delegation') && str_contains($source, 'activate_delegation'), 'workflow_and_delegation_actions_are_wired');
$assert(str_contains($source, 'admin-list-surface') && str_contains($source, 'stat-card'), 'page_uses_shared_admin_surfaces');

exit($failures === 0 ? 0 : 1);
