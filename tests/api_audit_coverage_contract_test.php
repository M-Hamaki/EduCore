<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'dismiss_notification' => $root . '/api/dismiss_notification.php',
    'push_subscribe' => $root . '/api/push_subscribe.php',
    'push_unsubscribe' => $root . '/api/push_unsubscribe.php',
    'reorder' => $root . '/api/reorder.php',
];

$checks = [];
foreach ($files as $name => $path) {
    $source = (string) file_get_contents($path);
    $checks[$name . '_audited'] = strpos($source, 'ActivityLog::') !== false;
    $checks[$name . '_atomic'] = strpos($source, 'beginTransaction()') !== false
        && strpos($source, '->commit()') !== false
        && strpos($source, '->rollBack()') !== false;
    $checks[$name . '_generic_failure'] = strpos($source, ". \$e->getMessage()])") === false;
}

$subscribe = (string) file_get_contents($files['push_subscribe']);
$unsubscribe = (string) file_get_contents($files['push_unsubscribe']);
$client = (string) file_get_contents($root . '/assets/js/push-notifications.js');
$checks['push_endpoints_require_csrf'] = strpos($subscribe, 'requireCsrfPost();') !== false
    && strpos($unsubscribe, 'requireCsrfPost();') !== false;
$checks['push_client_sends_csrf'] = substr_count($client, "'X-CSRF-TOKEN': CSRF_TOKEN") === 2;
$checks['push_secrets_are_not_audit_details'] = strpos($subscribe, "'endpoint' =>") === false
    && strpos($subscribe, "'auth' =>") === false
    && strpos($subscribe, "'p256dh' =>") === false;

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
