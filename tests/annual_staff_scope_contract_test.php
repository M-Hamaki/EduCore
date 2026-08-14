<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/src/Modules/Staff/StaffAcademicScopeService.php');
$migration = (string)file_get_contents($root . '/database/migrations/20260719_scoped_staff_portals.php');
$accounts = (string)file_get_contents($root . '/admin/staff_accounts.php');
$rollover = (string)file_get_contents($root . '/classes/NewYearRolloverService.php');
$policy = (string)file_get_contents($root . '/docs/new-academic-year-data-policy.md');

$checks = [
    'scope_storage_keys_include_academic_year' => strpos($migration, 'UNIQUE KEY uq_staff_grade_year (staff_id, academic_year_id, grade_id)') !== false
        && strpos($migration, 'UNIQUE KEY uq_staff_class_year (staff_id, academic_year_id, class_id)') !== false,
    'all_scope_reads_filter_by_year' => strpos($service, 'WHERE staff_id = ? AND academic_year_id = ?') !== false,
    'staff_accounts_edits_current_year_only' => strpos($accounts, '$academicYearId = AcademicYear::currentId($db)') !== false
        && strpos($accounts, '$scopeService->replaceAssignments(') !== false
        && strpos($accounts, '$academicYearId,') !== false,
    'rollover_does_not_copy_staff_scope' => strpos($rollover, 'staff_grade_assignments') === false
        && strpos($rollover, 'staff_class_assignments') === false,
    'documented_policy_requires_fresh_assignment' => strpos($policy, '`staff_grade_assignments`, `staff_class_assignments`') !== false
        && strpos($policy, 'يبدأ النطاق فارغًا') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
