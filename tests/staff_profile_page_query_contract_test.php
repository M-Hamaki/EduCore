<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/staff.php');
$query = (string) file_get_contents($root . '/src/Modules/Staff/StaffProfilePageQuery.php');

$checks = [
    'page_delegates_edit' => strpos($page, '$staffProfilePageQuery->editData(') !== false,
    'page_delegates_view' => strpos($page, '$staffProfilePageQuery->viewData(') !== false,
    'credential_free_reads' => substr_count($query, 'readOneWithoutCredentials()') === 2,
    'normalized_employment_loaded' => strpos($query, 'FROM staff_status_history') !== false
        && strpos($query, 'FROM staff_job_movements') !== false,
    'attachments_loaded' => strpos($query, 'FROM staff_attachments') !== false,
    'extras_decoded' => strpos($query, "'extra_phones'") !== false
        && strpos($query, "'extra_employment_data'") !== false,
    'query_does_not_read_superglobals' => strpos($query, '$_GET') === false
        && strpos($query, '$_POST') === false,
    'marital_label_after_labels' => strpos($page, '$viewMaritalStatusLabel =') > strpos(
        $page,
        '$maritalLabels ='
    ),
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
