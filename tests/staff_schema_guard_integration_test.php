<?php

require_once __DIR__ . '/bootstrap_test_database.php';
require_once __DIR__ . '/../classes/StaffSchemaGuard.php';

$db = educoreTestDatabase();
$guard = new StaffSchemaGuard($db);
$results = [];
try {
    $guard->assertReady();
    $results['staff_schema_ready'] = true;
} catch (Throwable $e) {
    $results['staff_schema_ready'] = false;
}

$failed = array_keys(array_filter($results, static fn($passed) => !$passed));
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit($failed ? 1 : 0);
