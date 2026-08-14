<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/assessment_teacher_assignments.php');
$endpoint = (string) file_get_contents($root . '/admin/ajax_assessment_teacher_assignments_datatable.php');
$query = (string) file_get_contents($root . '/classes/AssessmentTeacherAssignmentListQuery.php');
$activityLog = (string) file_get_contents($root . '/classes/ActivityLog.php');
$seed = (string) file_get_contents($root . '/tools/seed_assessment_demo.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260730_remove_teacher_assignment_substitute.php');

$activeSources = $page . $endpoint . $query . $activityLog . $seed;
$checks = [
    'active_assignment_sources_remove_substitute_concept' =>
        strpos($activeSources, 'is_substitute') === false
        && strpos($activeSources, 'assignSubstitute') === false
        && strpos($activeSources, 'substitute_count') === false
        && strpos($activeSources, 'معلم بديل') === false,
    'assignment_summary_has_four_operational_cards' =>
        strpos($page, 'row-cols-md-4') !== false
        && substr_count($page, 'class="stat-card"') === 4,
    'migration_drops_column_idempotently' =>
        strpos($migration, "COLUMN_NAME = ?") !== false
        && strpos($migration, "['teacher_subject_assignments', 'is_substitute']") !== false
        && strpos($migration, 'DROP COLUMN is_substitute') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
