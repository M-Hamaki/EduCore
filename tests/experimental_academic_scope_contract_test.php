<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/20260729_academic_structure_experimental_scope.php');
$policy = (string) file_get_contents($root . '/src/Modules/AcademicStructure/ExperimentalAcademicScopePolicy.php');
$stages = (string) file_get_contents($root . '/admin/stages.php');
$grades = (string) file_get_contents($root . '/admin/grades.php');
$classes = (string) file_get_contents($root . '/admin/classes.php');
$classroom = (string) file_get_contents($root . '/classes/classroom.php');
$rollover = (string) file_get_contents($root . '/classes/NewYearRolloverService.php');
$wizard = (string) file_get_contents($root . '/classes/NewYearWizard.php');
$classPlan = (string) file_get_contents($root . '/classes/ClassRolloverPlanService.php');
$setup = (string) file_get_contents($root . '/admin/academic_year_setup.php');

$checks = [
    'migration_is_additive_and_indexed' => strpos($migration, "ADD COLUMN is_experimental") !== false
        && strpos($migration, 'idx_stages_experimental_status') !== false
        && strpos($migration, 'idx_classes_year_experimental_status') !== false
        && strpos($migration, 'DROP ') === false,
    'central_policy_owns_inheritance_and_transition_guards' => strpos($policy, 'gradeEffectiveExperimental') !== false
        && strpos($policy, 'classEffectiveExperimental') !== false
        && strpos($policy, 'assertStageTransition') !== false
        && strpos($policy, 'assertGradeTransition') !== false
        && strpos($policy, 'assertClassTransition') !== false
        && strpos($policy, 'is_test_account') !== false,
    'stage_ui_persists_and_explains_experimental_scope' => substr_count($stages, 'is_experimental') >= 8
        && strpos($stages, 'مرحلة تجريبية') !== false
        && strpos($stages, 'assertStageTransition') !== false,
    'grade_ui_shows_direct_and_inherited_scope' => strpos($grades, 'stage_is_experimental') !== false
        && strpos($grades, 'تجريبي موروث') !== false
        && strpos($grades, 'assertGradeTransition') !== false,
    'class_ui_and_owner_persist_direct_scope' => strpos($classes, 'class_is_experimental') !== false
        && strpos($classes, 'فصل تجريبي') !== false
        && strpos($classes, 'assertClassTransition') !== false
        && substr_count($classroom, 'is_experimental') >= 8,
    'rollover_excludes_every_effective_scope' => strpos($rollover, 'stage_is_experimental') !== false
        && strpos($rollover, 'grade_is_experimental') !== false
        && strpos($rollover, 'class_is_experimental') !== false
        && strpos($rollover, 'studentExperimentalReason') !== false
        && strpos($policy, "'experimental_stage'") !== false
        && strpos($policy, "'experimental_class'") !== false
        && strpos($classPlan, 's.is_experimental = 0') !== false
        && strpos($classPlan, 'c.is_experimental = 0') !== false,
    'wizard_and_setup_expose_effective_scope' => strpos($wizard, 'stage_is_experimental') !== false
        && strpos($wizard, 'class_is_experimental') !== false
        && strpos($setup, "yearSetupColumnExists(\$db, 'stages', 'is_experimental')") !== false
        && strpos($setup, "yearSetupColumnExists(\$db, 'classes', 'is_experimental')") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
