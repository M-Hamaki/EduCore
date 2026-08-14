<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/RecoveryBackupService.php';

$pdo = new PDO('sqlite::memory:');
$service = new RecoveryBackupService($pdo, dirname(__DIR__), [], dirname(__DIR__) . '/storage/test-runtime/unit');
$method = new ReflectionMethod(RecoveryBackupService::class, 'rowContentHash');
$method->setAccessible(true);

$validNameAccepted = true;
try {
    RecoveryBackupService::assertTestDatabaseName('educore_restore_123_test', 'educore');
} catch (Throwable $error) {
    $validNameAccepted = false;
}

$invalidNamesRejected = true;
foreach (['educore', 'educore_restore', '../educore_test', 'educore-test'] as $name) {
    try {
        RecoveryBackupService::assertTestDatabaseName($name, 'educore');
        $invalidNamesRejected = false;
    } catch (InvalidArgumentException $error) {
    }
}

$first = $method->invoke($service, ['id' => 1, 'value' => 'A']);
$second = $method->invoke($service, ['id' => 1, 'value' => 'B']);
$binaryA = $method->invoke($service, ['payload' => "\x00\xFF"]);
$binaryB = $method->invoke($service, ['payload' => "\x00\xFE"]);

$results = [
    'valid_test_database_name_accepted' => $validNameAccepted,
    'unsafe_restore_names_rejected' => $invalidNamesRejected,
    'same_count_content_changes_fingerprint' => is_string($first) && !hash_equals($first, $second),
    'binary_content_changes_fingerprint' => is_string($binaryA) && !hash_equals($binaryA, $binaryB),
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);

