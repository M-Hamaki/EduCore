<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$query = (string)file_get_contents($root . '/src/Modules/Students/StudentArchiveQuery.php');
$page = (string)file_get_contents($root . '/admin/student_archive.php');
$endpoint = (string)file_get_contents($root . '/admin/ajax_student_archive_datatable.php');

$checks = [
    'query_has_bounded_archive_window' => strpos($query, 'loadDataTable') !== false && strpos($query, 'min($requestedLength, 500)') !== false,
    'query_counts_before_loading_rows' => strpos($query, 'recordsTotal') !== false && strpos($query, 'recordsFiltered') !== false,
    'endpoint_is_admin_and_csrf_protected' => strpos($endpoint, "validateSession('admin')") !== false && strpos($endpoint, 'requireCsrfPost()') !== false,
    'page_uses_shared_server_table' => strpos($page, 'AdminServerSideTable.init') !== false && strpos($page, 'ajax_student_archive_datatable.php') !== false,
    'page_does_not_render_archive_rows' => strpos($page, 'foreach ($students as') === false,
    'archive_actions_remain_delegated' => strpos($page, "event.target.closest('.restore-student')") !== false && strpos($page, "event.target.closest('.permanent-delete-student')") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
