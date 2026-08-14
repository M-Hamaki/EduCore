<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$surfaces = [
    'staff_shifts' => [
        'path' => 'admin/staff_shifts.php',
        'post' => ['save_default_shift', 'save_shift_override', 'delete_shift_override'],
        'get' => [],
    ],
    'staff_attendance' => [
        'path' => 'admin/staff_attendance.php',
        'post' => ['save_bulk_attendance', 'delete_attendance'],
        'get' => ['date', 'user_id', 'filter_status', 'job_title', 'view'],
    ],
    'staff_attendance_reports' => [
        'path' => 'admin/staff_attendance_reports.php',
        'post' => [],
        'get' => ['report_type', 'date_from', 'date_to', 'month', 'user_id', 'export'],
    ],
    'staff_biometric_import' => [
        'path' => 'admin/staff_biometric_import.php',
        'post' => ['preview_biometric', 'confirm_biometric', 'cancel_biometric_preview'],
        'get' => [],
    ],
    'biometric_devices' => [
        'path' => 'admin/biometric_devices.php',
        'post' => ['action', 'device_id'],
        'get' => ['tab'],
    ],
    'permissions' => [
        'path' => 'admin/permissions.php',
        'post' => ['permission_form_mode', 'add_permission', 'edit_permission', 'delete_permission'],
        'get' => ['action', 'id', 'user_id', 'type', 'filter_status', 'date_from', 'date_to'],
    ],
    'leaves' => [
        'path' => 'admin/leaves.php',
        'post' => ['save_leave_policy', 'add_leave', 'edit_leave', 'delete_leave'],
        'get' => ['action', 'id', 'user_id', 'type', 'filter_status', 'date_from', 'date_to'],
    ],
    'leave_balances' => [
        'path' => 'admin/leave_balances.php',
        'post' => ['save_balance_policy', 'apply_balance_policy', 'save_staff_balance'],
        'get' => ['year', 'role', 'user_id'],
    ],
    'disciplinary' => [
        'path' => 'admin/disciplinary.php',
        'post' => ['save_action', 'delete_action'],
        'get' => ['edit', 'user_id', 'filter_type', 'date_from', 'date_to'],
    ],
    'hr_center' => [
        'path' => 'admin/hr_center.php',
        'post' => ['active_tab', 'action', 'item_id', 'notes'],
        'get' => ['tab', 'user_id', 'status', 'date_from', 'date_to'],
    ],
];

$failures = [];
foreach ($surfaces as $name => $contract) {
    $pagePath = $root . '/' . $contract['path'];
    $source = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
    $authPosition = strpos($source, "Utilities::validateSession('admin');");
    $firstRequestRead = min(array_filter([
        strpos($source, '$_POST'),
        strpos($source, '$_GET'),
        strpos($source, "\$_SERVER['REQUEST_METHOD']"),
    ], static fn ($position): bool => $position !== false) ?: [PHP_INT_MAX]);

    $checks = [
        'exists' => $source !== '',
        'auth_before_request_processing' => $authPosition !== false && $authPosition < $firstRequestRead,
        'csrf_helper_available' => strpos($source, 'csrf') !== false || $contract['post'] === [],
    ];

    foreach ($contract['post'] as $field) {
        $checks['post_' . $field] = strpos($source, "'" . $field . "'") !== false
            || strpos($source, '"' . $field . '"') !== false;
    }
    foreach ($contract['get'] as $field) {
        $checks['get_' . $field] = strpos($source, "'" . $field . "'") !== false
            || strpos($source, '"' . $field . '"') !== false;
    }

    foreach ($checks as $check => $passed) {
        echo $name . '.' . $check . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
        if (!$passed) {
            $failures[] = $name . '.' . $check;
        }
    }
}

exit($failures ? 1 : 0);

