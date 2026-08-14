<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/student_statistics.php');
$sortable = (string) file_get_contents($root . '/assets/js/dashboard_sortable.js');

$widgetIds = [
    'widget-kpi-1', 'widget-kpi-2', 'widget-chart-grade', 'widget-chart-gender',
    'widget-chart-trend', 'widget-chart-age', 'widget-table-classes', 'widget-table-recent',
];

$results = [
    'statistics_defaults_survive_query_failure' => strpos($page, '$stages_count = 0;') !== false
        && strpos($page, '$grades_count = 0;') !== false
        && strpos($page, "\$age_demo = ['under_6' => 0") !== false,
    'dashboard_preferences_are_namespaced' => strpos($page, "const STORAGE_KEY = 'eduCoreDashboardPrefs';") !== false,
    'resize_handle_does_not_start_sort' => strpos($sortable, '.section-resize-handle') !== false,
    'dashboard_does_not_define_page_local_styles' => stripos($page, '<style') === false,
];

foreach ($widgetIds as $widgetId) {
    $results['widget_toggle_matches_' . $widgetId] = strpos($page, 'id="' . $widgetId . '"') !== false
        && strpos($page, 'data-target="' . $widgetId . '"') !== false;
}

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
