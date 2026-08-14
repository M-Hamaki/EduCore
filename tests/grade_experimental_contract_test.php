<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/20260719_academic_promotion_decisions.php');
$grades = (string) file_get_contents($root . '/admin/grades.php');
$classes = (string) file_get_contents($root . '/admin/classes.php');

$checks = [
    'grade_flag_schema' => strpos($migration, 'is_experimental') !== false,
    'grade_add_edit_persistence' => substr_count($grades, 'is_experimental') >= 6,
    'grade_badge_and_controls' => strpos($grades, 'صف تجريبي') !== false
        && strpos($grades, 'data-experimental') !== false,
    'class_capacity_schema' => strpos($migration, 'capacity') !== false,
    'class_capacity_ui' => substr_count($classes, 'capacity') >= 4,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);

