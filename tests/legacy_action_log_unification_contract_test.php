<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$utilities = (string) file_get_contents($root . '/classes/utilities.php');
$activity = (string) file_get_contents($root . '/classes/ActivityLog.php');

$checks = [
    'legacy_log_api_delegates_to_activity_log' => strpos($utilities, 'return ActivityLog::log(') !== false
        && strpos($utilities, "'legacy_api' => 'Utilities::logAction'") !== false,
    'legacy_parallel_action_log_write_removed' => strpos($utilities, 'INSERT INTO action_logs') === false,
    'activity_log_supports_pre_session_actor_override' => strpos($activity, "isset(\$context['actor_id'])") !== false
        && strpos($activity, "\$actor['id'] = (int) \$context['actor_id']") !== false,
    'actor_override_does_not_bypass_redaction' => strpos($activity, 'AuditPolicyRegistry::redact') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
