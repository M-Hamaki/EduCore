<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/calculation_tools.php');
$css = (string) file_get_contents($root . '/assets/css/calculation-tools.css');

$authPosition = strpos($page, "Utilities::validateSession('admin')");
$databasePosition = strpos($page, '$database = new Database()');
$ajaxPosition = strpos($page, "if (isset(\$_GET['ajax_action']))");

$checks = [
    'auth_precedes_database_and_ajax' => $authPosition !== false
        && $databasePosition > $authPosition
        && $ajaxPosition > $databasePosition,
    'ajax_contracts_preserved' => strpos($page, "'compare_stats'") !== false
        && strpos($page, "'get_grades'") !== false
        && strpos($page, "'get_classes'") !== false
        && strpos($page, "'get_students'") !== false
        && strpos($page, "'get_national_id'") !== false,
    'calculation_contracts_preserved' => strpos($page, "'admission'") !== false
        && strpos($page, "'custom'") !== false
        && strpos($page, 'function parseNationalID()') !== false
        && strpos($page, 'function calculateAgeHomogeneity()') !== false
        && strpos($page, 'function calculateClassBalancing()') !== false,
    'page_owns_css_link_not_late_style' => strpos($page, '../assets/css/calculation-tools.css') !== false
        && strpos(substr($page, (int) strpos($page, '</script>')), '<style>') === false,
    'extracted_css_keeps_page_scope' => strpos($css, '.calculation-tools-page') !== false
        && strpos($css, '#calcTabsContent .list-group-item') !== false,
    'entrypoint_below_large_file_limit' => substr_count($page, "\n") + 1 < 2000,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
