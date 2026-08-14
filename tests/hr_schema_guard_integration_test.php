<?php

require_once __DIR__ . '/bootstrap_test_database.php';
require_once __DIR__ . '/../classes/HrSchemaGuard.php';

$guard = new HrSchemaGuard(educoreTestDatabase());
$checks = [
    static fn() => $guard->assertTable('staff_shift_overrides'),
    static fn() => $guard->assertTable('staff_attendance_audit'),
    static fn() => $guard->assertTable('staff_biometric_logs'),
    static fn() => $guard->assertColumn('users', 'employee_code'),
    static fn() => $guard->assertIndex('users', 'uq_employee_code'),
    static fn() => $guard->assertColumn('staff_profiles', 'annual_leave_balance'),
    static fn() => $guard->assertColumn('staff_profiles', 'leave_balance_notes'),
];
$passed = true;
foreach ($checks as $check) {
    try { $check(); } catch (Throwable $e) { $passed = false; }
}
echo 'hr_schema_ready:' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
exit($passed ? 0 : 1);
