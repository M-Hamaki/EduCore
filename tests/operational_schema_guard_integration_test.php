<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/SchemaReadinessGuard.php';

$db = educoreTestDatabase();
$guard = new SchemaReadinessGuard($db);
$guard->assertColumns('biometric_devices', ['comm_password', 'protocol']);
$guard->assertTable('biometric_sync_log');
$guard->assertTable('materials');
$guard->assertColumns('classes', ['timetable_image']);
$guard->assertTable('ai_exam_progress');

echo "operational_schema_ready:PASS\n";
