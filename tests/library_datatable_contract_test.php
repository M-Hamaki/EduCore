<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$query = (string)file_get_contents($root . '/classes/LibraryListDataTableQuery.php');
$endpoint = (string)file_get_contents($root . '/admin/ajax_library_datatable.php');
$lookup = (string)file_get_contents($root . '/admin/ajax_library_lookup.php');
$page = (string)file_get_contents($root . '/admin/library.php');
$checks = [
    'list_query_is_bounded' => strpos($query, 'min($requestedLength, 500)') !== false,
    'list_endpoint_is_csrf_protected' => strpos($endpoint, "validateSession('admin')") !== false && strpos($endpoint, 'requireCsrfPost()') !== false,
    'lookup_endpoint_is_csrf_protected' => strpos($lookup, "validateSession('admin')") !== false && strpos($lookup, 'requireCsrfPost()') !== false,
    'page_initializes_all_library_tabs_server_side' => strpos($page, "libraryTableMap={books:'#libraryBooksTable',loans:'#libraryLoansTable',returns:'#libraryReturnsTable',fines:'#libraryFinesTable'}") !== false,
    'page_uses_lookup_selects' => substr_count($page, 'data-library-lookup=') >= 5,
];
$failed = false;
foreach ($checks as $name => $passed) { echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL; $failed = $failed || !$passed; }
exit($failed ? 1 : 0);
