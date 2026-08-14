<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/admin/notifications.php');
$content = (string) file_get_contents($root . '/includes/admin_notifications_content.php');
$scripts = (string) file_get_contents($root . '/includes/admin_notifications_scripts.php');
$combined = $content . $scripts;
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$authAt = strpos($controller, "Utilities::validateSession('admin')");
$requestAt = strpos($controller, "\$_SERVER['REQUEST_METHOD']");
$assert($authAt !== false && $requestAt !== false && $authAt < $requestAt, 'Admin authentication must precede notification request processing.');
$assert(str_contains($controller, 'requireCsrfPost();'), 'Notification writes must remain protected by the shared CSRF gate.');
$assert(
    str_contains($controller, "'/includes/admin_notifications_content.php'")
        && str_contains($controller, "'/includes/admin_notifications_scripts.php'")
        && str_contains($controller, "'/includes/admin_footer.php'"),
    'Controller must compose the extracted presentation and shared footer.'
);

foreach ([$content, $scripts] as $fragment) {
    $assert(
        str_contains($fragment, "defined('EDUCORE_NOTIFICATIONS_PAGE')"),
        'Extracted presentation fragments must fail closed when requested directly.'
    );
}

foreach ([
    'id="notificationForm"',
    'id="notificationsTable"',
    'id="addOccasionModal"',
    'id="editOccasionModal"',
    'id="deleteOccasionModal"',
    'id="addNotificationModal"',
    'id="editNotificationModal"',
    'id="deleteModal"',
    "name=\"action\" value=\"send_push\"",
    "name=\"action\" value=\"send_push_occasion\"",
    'csrfField()',
] as $contract) {
    $assert(str_contains($combined, $contract), 'Extracted notification presentation lost contract: ' . $contract);
}

$assert(!str_contains($combined, 'confirm(') && !str_contains($combined, 'Swal.'), 'Notification presentation must keep Bootstrap confirmation modals.');

foreach ([$root . '/admin/notifications.php', $root . '/includes/admin_notifications_content.php', $root . '/includes/admin_notifications_scripts.php'] as $path) {
    $assert(count(file($path)) < 2000, basename($path) . ' must remain below the architecture large-file threshold.');
}

if ($failures !== []) {
    fwrite(STDERR, "Notifications presentation extraction contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Notifications presentation extraction contract passed.\n";
