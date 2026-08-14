<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/20260719_academic_promotion_decisions.php');
$classMappingMigration = (string) file_get_contents($root . '/database/migrations/20260728_class_rollover_mappings.php');
$service = (string) file_get_contents($root . '/classes/NewYearRolloverService.php');
$classPlanService = (string) file_get_contents($root . '/classes/ClassRolloverPlanService.php');
$wizard = (string) file_get_contents($root . '/classes/NewYearWizard.php');
$page = (string) file_get_contents($root . '/admin/academic_year_setup.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'migration_is_additive' => strpos($migration, 'CREATE TABLE grade_promotion_rules') !== false
        && strpos($migration, 'CREATE TABLE student_promotion_decisions') !== false
        && strpos($migration, 'DROP TABLE') === false,
    'single_school_schema' => stripos($migration, 'school_id') === false
        && stripos($migration, 'tenant_id') === false
        && stripos($migration, 'branch_id') === false,
    'explicit_year_pair_rule' => strpos($migration, 'source_year_id') !== false
        && strpos($migration, 'target_year_id') !== false
        && strpos($migration, 'source_grade_id') !== false,
    'all_decisions_accounted' => strpos($migration, "'promoted','retained','pending','graduated','transferred_out','withdrawn','excluded_test'") !== false,
    'enrollment_lineage' => strpos($migration, 'source_enrollment_id') !== false
        && strpos($migration, 'promotion_decision_id') !== false
        && strpos($migration, 'repeat_count') !== false,
    'rule_service_contract' => strpos($service, 'savePromotionRules') !== false
        && strpos($service, 'prepareDecisions') !== false
        && strpos($service, 'decisionFingerprint') !== false,
    'class_mapping_is_durable_and_reviewed' => strpos($classMappingMigration, 'CREATE TABLE class_rollover_mappings') !== false
        && strpos($classMappingMigration, "ENUM('cohort','entry_template')") !== false
        && strpos($service, 'saveClassMappings') !== false
        && strpos($service, '$this->classPlans->fingerprint') !== false
        && strpos($classPlanService, 'function fingerprint') !== false
        && strpos($page, 'save_class_mappings') !== false,
    'no_order_or_class_rank_promotion' => strpos($service, 'matchingSourceClass') === false
        && strpos($service, 'nextGradeId') === false
        && strpos($service, 'classRank') === false,
    'placement_policy_separates_promoted_and_retained' => strpos($service, "academicStatus === 'promoted'") !== false
        && strpos($service, "academicStatus === 'retained'") !== false
        && strpos($service, 'cohortClassMap') !== false,
    'grouped_blockers' => strpos($service, 'groupBlockers') !== false
        && strpos($page, 'blocker_groups') !== false,
    'guided_rollover_workflow' => strpos($page, 'year-setup-steps') !== false
        && strpos($page, 'ماذا أفعل الآن؟') !== false
        && strpos($page, 'yearSetupCurrentStep') !== false,
    'blockers_have_direct_recovery_actions' => strpos($page, 'yearSetupBlockerGuidance') !== false
        && strpos($page, 'students.php?action=edit&amp;id=') !== false
        && strpos($page, "!== 'students_skipped'") !== false,
    'student_exception_search_is_available' => strpos($page, 'yearSetupStudentSearch') !== false
        && strpos($page, 'filterYearSetupStudents') !== false,
    'sparse_exception_submission' => strpos($page, 'data-decision-name="student_decisions[') !== false
        && strpos($page, 'function syncStudentDecisionField') !== false
        && strpos($page, "select.removeAttribute('name')") !== false
        && strpos($wizard, 'd.decision_source') !== false
        && strpos($page, "=== 'manual'") !== false,
    'wizard_compatibility' => strpos($wizard, 'retainedStudentIds') !== false,
    'audit_policy_registered' => strpos($policy, "'grade_promotion_rules'") !== false
        && strpos($policy, "'student_promotion_decisions'") !== false
        && strpos($policy, "'class_rollover_mappings'") !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
