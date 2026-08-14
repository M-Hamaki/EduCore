<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/AcademicYear.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'academic_year_table_is_registered' => strpos($policy, "'academic_years'") !== false,
    'create_update_lock_and_default_are_audited' => substr_count($source, "recordInsert(") >= 2
        && substr_count($source, "recordUpdate(") >= 2
        && strpos($source, 'إنشاء العام الدراسي الافتراضي') !== false,
    'active_year_change_is_composite' => strpos($source, "'affected_years' => count(\$items)") !== false
        && strpos($source, 'تغيير العام الدراسي النشط') !== false,
    'student_compatibility_sync_only_writes_changes' => strpos($source, 'NOT (u.class_id <=> se.class_id)') !== false
        && strpos($source, "'student_count' => count(\$items)") !== false,
    'setting_change_uses_audited_owner' => strpos($source, 'private static function saveSetting') !== false
        && strpos($source, "recordInsert('setting'") !== false
        && strpos($source, "recordUpdate('setting'") !== false,
    'nested_transactions_are_supported' => substr_count($source, '$ownsTransaction = !$db->inTransaction()') >= 6,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
