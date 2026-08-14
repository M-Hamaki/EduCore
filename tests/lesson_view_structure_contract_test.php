<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/teacher/lesson_view.php');
$css = (string) file_get_contents($root . '/assets/css/lesson-view.css');

$roleGate = strpos($page, "in_array(\$_SESSION['role'], ['teacher', 'external_teacher'])");
$databasePosition = strpos($page, '$database = new Database()');

$checks = [
    'role_gate_precedes_database' => $roleGate !== false && $databasePosition > $roleGate,
    'admin_teacher_scope_preserved' => strpos($page, "['admin', 'super_admin']") !== false
        && strpos($page, "\$_GET['teacher_id']") !== false,
    'lesson_lookup_contract_preserved' => strpos($page, "\$_GET['id']") !== false
        && strpos($page, '$generator->getLesson($lessonId)') !== false,
    'display_dependencies_preserved' => strpos($page, '../assets/js/lesson_display.js') !== false
        && strpos($page, '../assets/js/eduvisual.js') !== false
        && strpos($page, 'html2canvas') !== false,
    'page_loads_owned_css' => strpos($page, '../assets/css/lesson-view.css') !== false,
    'css_contracts_preserved' => strpos($css, '.main-container') !== false
        && strpos($css, '.theme-toggle') !== false,
    'entrypoint_below_large_file_limit' => substr_count($page, "\n") + 1 < 2000,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
