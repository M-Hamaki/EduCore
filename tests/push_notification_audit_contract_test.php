<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/classes/PushNotification.php');
$cleanupStart = strpos($source, 'private function removeExpiredSubscriptions');
$auditStart = strpos($source, 'private function auditDelivery', $cleanupStart ?: 0);
$occasionStart = strpos($source, 'public function sendForOccasion', $auditStart ?: 0);
$cleanupBody = ($cleanupStart !== false && $auditStart !== false)
    ? substr($source, $cleanupStart, $auditStart - $cleanupStart)
    : '';
$auditBody = ($auditStart !== false && $occasionStart !== false)
    ? substr($source, $auditStart, $occasionStart - $auditStart)
    : '';

$checks = [
    'delivery_outcomes_are_audited' => substr_count($source, 'auditDelivery(') >= 4
        && strpos($auditBody, 'recordEvent(') !== false,
    'delivery_audit_is_compact' => strpos($auditBody, 'target_count') !== false
        && strpos($auditBody, 'target_set_hash') !== false
        && strpos($auditBody, 'title_hash') !== false,
    'delivery_audit_excludes_message_body_and_keys' => strpos($auditBody, "'body'") === false
        && strpos($auditBody, 'p256dh_key') === false
        && strpos($auditBody, 'auth_key') === false
        && strpos($auditBody, "'endpoint'") === false,
    'expired_cleanup_is_atomic_and_audited' => strpos($cleanupBody, 'beginTransaction()') !== false
        && strpos($cleanupBody, 'DELETE FROM push_subscriptions') !== false
        && strpos($cleanupBody, 'recordEvent(') !== false
        && strpos($cleanupBody, 'commit()') !== false
        && strpos($cleanupBody, 'rollBack()') !== false,
    'expired_endpoint_values_are_hashed' => strpos($cleanupBody, "hash('sha256', \$endpoint)") !== false
        && strpos($cleanupBody, 'endpoint_set_hash') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
