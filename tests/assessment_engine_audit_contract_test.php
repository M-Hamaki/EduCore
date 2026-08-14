<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/AssessmentEngine.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$tables = ['assessment_components', 'assessment_component_week_rules', 'assessment_windows', 'published_reports', 'published_report_details', 'report_windows', 'assessment_student_locks'];
$registered = true;
foreach ($tables as $table) $registered = $registered && strpos($policy, "'{$table}'") !== false;

$checks = [
    'all_engine_tables_are_registered' => $registered,
    'state_diff_detects_insert_update_delete' => strpos($source, 'private function auditStateTransition(') !== false
        && strpos($source, '$deleted[]') !== false
        && strpos($source, '$inserted[]') !== false
        && strpos($source, '$updated[]') !== false,
    'state_diff_uses_one_shared_batch' => strpos($source, '$batchId = $batchId ?: UndoManager::newBatchId()') !== false
        && strpos($source, 'recordReplacement(') !== false
        && strpos($source, 'recordCompositeUpdate(') !== false,
    'template_apply_and_copy_are_atomic_audited' => substr_count($source, "'assessment_component_week_rules' => \$beforeRules") >= 2
        && substr_count($source, "'assessment_components' => \$afterComponents") >= 2,
    'report_publish_snapshots_header_and_details' => strpos($source, 'fetchPublishedReports(') !== false
        && strpos($source, 'fetchPublishedReportDetails(') !== false
        && strpos($source, "'published_report_details' => \$beforeDetails") !== false,
    'student_lock_sync_is_atomic_audited' => strpos($source, "'assessment_student_locks' => \$beforeRows") !== false
        && strpos($source, 'مزامنة أقفال التقييم من حالات التسجيل') !== false,
    'sqlite_behavior_tests_are_not_forced_through_mysql_audit_storage' => strpos($source, "PDO::ATTR_DRIVER_NAME) !== 'mysql'") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
