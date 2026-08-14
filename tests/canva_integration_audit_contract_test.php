<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/CanvaIntegration.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');
$saveStart = strpos($source, 'private function saveTokens');
$accessStart = strpos($source, 'public function getAccessToken', $saveStart ?: 0);
$saveBody = ($saveStart !== false && $accessStart !== false) ? substr($source, $saveStart, $accessStart - $saveStart) : '';

$checks = [
    'canva_templates_registered_but_file_delete_not_directly_undoable' => strpos($policy, "'canva_templates'") !== false
        && preg_match("/NON_RESTORABLE_DELETE_TABLES[\\s\\S]*'canva_templates'/", $policy) === 1,
    'oauth_tokens_are_never_snapshotted' => strpos($source, "recordDelete('canva_oauth") === false
        && strpos($policy, "'canva_oauth_tokens'") === false,
    'oauth_audit_contains_metadata_only' => strpos($saveBody, "'scope_count'") !== false
        && strpos($saveBody, "'has_refresh_token'") !== false
        && strpos($saveBody, "'access_token' =>") === false
        && strpos($saveBody, "'refresh_token' =>") === false,
    'oauth_failure_does_not_log_provider_response' => strpos($source, "token exchange failed —") === false,
    'template_upserts_are_atomic_audited' => strpos($source, 'private function mutateTemplate') !== false
        && strpos($source, "recordInsert('canva_template'") !== false
        && strpos($source, "recordUpdate('canva_template'") !== false,
    'activation_is_one_composite_event' => strpos($source, 'private function setTemplateActivation') !== false
        && strpos($source, "'affected_count' => count(\$items)") !== false,
    'delete_commits_before_local_file_cleanup' => strpos($source, 'حذف قالب Canva وملفه المحلي') < strpos($source, '@unlink'),
    'external_operations_log_intent_and_redacted_outcome' => strpos($source, 'auditExternalIntent(') !== false
        && strpos($source, 'auditExternalOutcome(') !== false
        && strpos($source, "'external_id_hash'") !== false
        && strpos($source, "'error_hash'") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
