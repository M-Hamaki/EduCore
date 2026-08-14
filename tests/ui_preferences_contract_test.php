<?php

$root = dirname(__DIR__);
require_once $root . '/classes/utilities.php';

$header = (string) file_get_contents($root . '/includes/admin_header.php');
$settingsPage = (string) file_get_contents($root . '/admin/ui_settings.php');
$adminStyles = (string) file_get_contents($root . '/assets/css/admin-unified.css');

$checks = [
    'getUserPreference_exists' => method_exists('Utilities', 'getUserPreference'),
    'setUserPreference_exists' => method_exists('Utilities', 'setUserPreference'),
    'settings_page_has_auth_validation' => strpos($settingsPage, "Utilities::validateSession('admin');") !== false,
    'settings_page_has_csrf_protection' => strpos($settingsPage, "csrf_token") !== false && strpos($settingsPage, "hash_equals") !== false,
    'header_loads_user_preferences' => strpos($header, "Utilities::getUserPreference") !== false,
    'header_supports_app_theme' => strpos($header, "app_theme") !== false && strpos($header, "app-dark-mode") !== false,
    'header_supports_layout_density' => strpos($header, "layout_density") !== false && strpos($header, "compact-density") !== false,
    'header_supports_micro_interactions' => strpos($header, "micro_interactions") !== false && strpos($header, "active-interactions") !== false,
    'header_supports_font_size' => strpos($header, "font_size") !== false,
    'header_supports_button_style' => strpos($header, "button_style") !== false,
    'header_supports_counter_animation' => strpos($header, "counter_animation") !== false,
    'header_supports_table_header_style' => strpos($header, "table_header_style") !== false
        && strpos($adminStyles, "table thead th") !== false,
    'header_supports_status_badge_style' => strpos($header, "status_badge_style") !== false
        && strpos($adminStyles, "badge.bg-success") !== false,
    'header_supports_page_title_style' => strpos($header, "page_title_style") !== false
        && strpos($adminStyles, "admin-page-heading") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
