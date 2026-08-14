<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'admin/ajax/get_password.php' => 'requireCsrfToken();',
    'admin/grades_ajax.php' => 'requireCsrfToken();',
    'admin/import_locations.php' => 'adminImportBootstrap();',
    'admin/school_settings.php' => 'requireCsrfPost();',
    'admin/sql_backups.php' => 'requireCsrfPost();',
    'api/dismiss_notification.php' => 'requireCsrfPost();',
    'api/reorder.php' => 'requireCsrfPost();',
];

$checks = [];
foreach ($files as $relativePath => $guardNeedle) {
    $source = (string)file_get_contents($root . '/' . $relativePath);
    $guard = strpos($source, $guardNeedle);
    $database = strpos($source, '$database = new Database();');
    $checks[str_replace(['/', '.php'], ['_', ''], $relativePath) . '_guard_before_database'] = $guard !== false
        && ($database === false || $guard < $database);
}

$importHelper = (string)file_get_contents($root . '/admin/includes/import_helpers.php');
$checks['import_bootstrap_auth_then_csrf_then_database'] = strpos($importHelper, "Utilities::validateSession('admin');")
    < strpos($importHelper, 'requireCsrfPost();')
    && strpos($importHelper, 'requireCsrfPost();') < strpos($importHelper, '$database = new Database();');

$notificationHelper = (string)file_get_contents($root . '/includes/notifications_helper.php');
$checks['notification_dismiss_calls_send_csrf'] = substr_count($notificationHelper, '&csrf_token=') === 2;
foreach (['admin/classes.php', 'admin/grades.php', 'admin/subjects.php'] as $caller) {
    $source = (string)file_get_contents($root . '/' . $caller);
    $checks[str_replace(['/', '.php'], ['_', ''], $caller) . '_reorder_sends_csrf'] = strpos(
        $source,
        "formData.append('csrf_token'"
    ) !== false;
}

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
