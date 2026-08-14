<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/user.php');
$support = (string) file_get_contents($root . '/classes/UserAuditSupport.php');
$facade = (string) file_get_contents($root . '/classes/UserProfileFacadeTrait.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');
$staffScope = (string) file_get_contents($root . '/src/Modules/Staff/StaffAcademicScopeService.php');

$checks = [
    'user_mutations_are_transaction_owned' => substr_count($source . $support . $staffScope, '$ownsTransaction = !') >= 12,
    'user_crud_is_audited' => strpos($source, "'إنشاء مستخدم'") !== false
        && strpos($source, "'تعديل مستخدم'") !== false
        && strpos($source, "'حذف مستخدم'") !== false,
    'batch_import_is_atomic_and_snapshotted' => strpos($source, "'user_import'") !== false
        && strpos($source, "'imported_count' => count(\$inserted)") !== false,
    'role_migration_captures_all_owned_tables' => strpos($source, "'user_class_access' => \$this->auditSupport->fetchRowsForUser") !== false
        && strpos($source, "'specialist_grade_assignments' => \$this->auditSupport->fetchRowsForUser") !== false
        && strpos($source, "'specialist_class_assignments' => \$this->auditSupport->fetchRowsForUser") !== false
        && strpos($source, "'teacher_subjects' => \$this->auditSupport->fetchRowsForUser") !== false
        && strpos($support, "recordCompositeUpdate('user_role_migration'") !== false,
    'assignment_and_point_reset_writes_are_audited' => strpos($source, "'user_class_assignment'") !== false
        && strpos($staffScope, "recordReplacement(") !== false
        && strpos($source, "'student_points_reset'") !== false,
    'credential_upgrade_is_redacted_event' => strpos($source, 'auditSupport->upgradePasswordHash(') !== false
        && strpos($support, "'credential_upgrade'") !== false
        && strpos($support, "'algorithm' => password_get_info") !== false,
    'student_profile_compatibility_api_delegates_to_audited_store' => strpos($facade, 'public function ensureStudentProfile(') !== false
        && strpos($facade, '$this->profileStore->saveStudentProfile') !== false,
    'assignment_tables_are_registered' => strpos($policy, "'user_class_access'") !== false
        && strpos($policy, "'specialist_grade_assignments'") !== false
        && strpos($policy, "'specialist_class_assignments'") !== false
        && strpos($policy, "'staff_grade_assignments'") !== false
        && strpos($policy, "'staff_class_assignments'") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
