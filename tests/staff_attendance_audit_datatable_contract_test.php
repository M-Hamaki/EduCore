<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/classes/StaffAttendanceService.php');
$page = (string)file_get_contents($root . '/admin/staff_attendance_audit.php');
$endpoint = (string)file_get_contents($root . '/admin/ajax_staff_attendance_audit_datatable.php');

$checks = [
    'service_has_bounded_datatable_window' => strpos($service, 'getAttendanceAuditDataTable') !== false && strpos($service, 'min($requestedLength, 500)') !== false,
    'service_searches_with_filtered_count' => strpos($service, 'recordsFiltered') !== false && strpos($service, 'a.before_data LIKE ?') !== false,
    'endpoint_is_admin_and_csrf_protected' => strpos($endpoint, "validateSession('admin')") !== false && strpos($endpoint, 'requireCsrfPost()') !== false,
    'page_uses_shared_server_table' => strpos($page, 'AdminServerSideTable.init') !== false && strpos($page, 'ajax_staff_attendance_audit_datatable.php') !== false,
    'page_does_not_render_all_audit_rows' => strpos($page, 'foreach ($auditRows') === false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
