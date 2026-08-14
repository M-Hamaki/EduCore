<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/MicrosoftSSO.php');

$linkStart = strpos($source, 'public function linkMicrosoftAccount');
$loginStart = strpos($source, 'public function loginUser', $linkStart ?: 0);
$linkBody = ($linkStart !== false && $loginStart !== false)
    ? substr($source, $linkStart, $loginStart - $linkStart)
    : '';
$logStart = strpos($source, 'private function logAction');
$dashboardStart = strpos($source, 'public function getDashboardUrl', $logStart ?: 0);
$logBody = ($logStart !== false && $dashboardStart !== false)
    ? substr($source, $logStart, $dashboardStart - $logStart)
    : '';

$checks = [
    'audit_service_is_loaded_explicitly' => strpos($source, "src/Modules/Operations/Audit/AuditService.php") !== false,
    'account_link_is_atomic' => strpos($linkBody, 'beginTransaction()') !== false
        && strpos($linkBody, 'FOR UPDATE') !== false
        && strpos($linkBody, 'commit()') !== false
        && strpos($linkBody, 'rollBack()') !== false,
    'account_link_records_undoable_user_update' => strpos($linkBody, 'AuditService') !== false
        && strpos($linkBody, "recordUpdate(") !== false
        && strpos($linkBody, "'users'") !== false,
    'legacy_parallel_activity_table_removed' => strpos($source, 'INSERT INTO activity_log') === false,
    'sso_login_uses_unified_audit_sink' => strpos($logBody, 'AuditService') !== false
        && strpos($logBody, 'recordEvent(') !== false
        && strpos($logBody, "'actor_id'") !== false,
    'audit_payload_excludes_microsoft_credentials' => strpos($logBody, 'access_token') === false
        && strpos($logBody, 'clientSecret') === false,
    'partial_sso_session_is_cleared_on_failure' => strpos($source, 'private function clearLoginSession') !== false
        && strpos($source, "catch (Throwable \$e)") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
