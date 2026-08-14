<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/teacher/lesson_prep.php');
$css = (string) file_get_contents($root . '/assets/css/lesson-prep.css');
$fragmentPaths = [
    $root . '/classes/Presentation/LessonPrep/form_part_one.php',
    $root . '/classes/Presentation/LessonPrep/form_part_two.php',
    $root . '/classes/Presentation/LessonPrep/scripts_part_one.php',
    $root . '/classes/Presentation/LessonPrep/scripts_part_two.php',
];
$fragments = array_map(static fn(string $path): string => (string) file_get_contents($path), $fragmentPaths);
$combined = $page . "\n" . implode("\n", $fragments);

$roleGate = strpos($page, "in_array(\$_SESSION['role'], ['teacher', 'external_teacher'])");
$databasePosition = strpos($page, '$database = new Database()');

$checks = [
    'role_gate_precedes_database' => $roleGate !== false && $databasePosition > $roleGate,
    'csrf_contract_preserved' => strpos($page, "../includes/csrf.php") !== false
        && strpos($combined, 'csrf_token') !== false,
    'context_integrations_preserved' => strpos($page, 'LessonPrepPageContext::load') !== false
        && strpos($page, 'CanvaIntegration') !== false
        && strpos($page, 'LessonPptTemplateLibrary') !== false,
    'entrypoint_loads_owned_presentation' => strpos($page, '../assets/css/lesson-prep.css') !== false
        && substr_count($page, 'Presentation/LessonPrep/') === 4,
    'form_contracts_preserved' => strpos($combined, 'lessonForm') !== false
        && strpos($combined, 'loadingOverlay') !== false
        && strpos($combined, 'generatedData') !== false,
    'script_boundary_preserved' => strpos($fragments[2], '<script>') !== false
        && strpos($fragments[3], '</script>') !== false,
    'css_contracts_preserved' => strpos($css, '.main-container') !== false
        && strpos($css, '.page-header') !== false,
    'all_php_files_below_large_limit' => max(array_map(
        static fn(string $source): int => substr_count($source, "\n") + 1,
        array_merge([$page], $fragments)
    )) < 2000,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
