<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/index.php');
$interactions = (string) file_get_contents($root . '/classes/Presentation/Dashboard/interactions.php');
$charts = (string) file_get_contents($root . '/classes/Presentation/Dashboard/charts.php');
$combined = $page . "\n" . $interactions . "\n" . $charts;

$authPosition = strpos($page, "Utilities::validateSession('admin')");
$databasePosition = strpos($page, '$database = new Database()');

$checks = [
    'auth_precedes_database' => $authPosition !== false && $databasePosition > $authPosition,
    'dashboard_contracts_preserved' => strpos($combined, 'dashboard-sections-sortable') !== false
        && strpos($combined, 'Sortable') !== false
        && strpos($combined, 'Chart') !== false,
    'entrypoint_loads_presentation_fragments' => strpos($page, 'Presentation/Dashboard/interactions.php') !== false
        && strpos($page, 'Presentation/Dashboard/charts.php') !== false,
    'chart_library_precedes_chart_fragment' => strpos($page, 'chart.umd.min.js') !== false
        && strpos($page, 'chart.umd.min.js') < strpos($page, 'Presentation/Dashboard/charts.php'),
    'fragments_keep_script_boundaries' => strpos($interactions, '<script>') !== false
        && strpos($interactions, '</script>') !== false
        && strpos($charts, '<script>') !== false
        && strpos($charts, '</script>') !== false,
    'entrypoint_below_large_file_limit' => substr_count($page, "\n") + 1 < 2000,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
