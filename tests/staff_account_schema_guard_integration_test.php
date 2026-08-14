<?php

require_once __DIR__ . '/bootstrap_test_database.php';
require_once __DIR__ . '/../classes/StaffAccountSchemaGuard.php';
$guard = new StaffAccountSchemaGuard(educoreTestDatabase());
$passed = true;
try { $guard->assertReady(); } catch (Throwable $e) { $passed = false; }
echo 'staff_account_schema_ready:' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
exit($passed ? 0 : 1);
