<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/_test$/', $databaseName) || $databaseName === 'educore') {
    throw new RuntimeException('Recovery integration test database guard failed.');
}

$migration = require dirname(__DIR__) . '/database/migrations/20260718_safe_year_rollover.php';
$migration($db);
require_once dirname(__DIR__) . '/classes/RecoveryBackupService.php';

$token = bin2hex(random_bytes(5));
$runtimeRoot = dirname(__DIR__) . '/storage/test-runtime/recovery-' . $token;
$uploadsRoot = $runtimeRoot . '/uploads';
$privateRoot = $runtimeRoot . '/private';
$backupRoot = $runtimeRoot . '/backups';
foreach ([$uploadsRoot, $privateRoot, $backupRoot] as $directory) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create recovery integration fixture directory.');
    }
}
file_put_contents($uploadsRoot . '/fixture.txt', 'public-fixture-' . $token);
file_put_contents($privateRoot . '/private.bin', "private\0fixture-" . $token);

$removeRuntime = static function (string $path) use ($runtimeRoot): void {
    $root = rtrim(str_replace('\\', '/', $runtimeRoot), '/') . '/';
    $normalized = rtrim(str_replace('\\', '/', $path), '/') . '/';
    if ($normalized !== $root || !is_dir($path)) {
        throw new RuntimeException('Refusing unsafe recovery fixture cleanup.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
};

$results = [];
try {
    $service = new RecoveryBackupService(
        $db,
        dirname(__DIR__),
        ['uploads_fixture' => $uploadsRoot, 'private_fixture' => $privateRoot],
        $backupRoot
    );
    $created = $service->createPackage(null);
    $results['package_created'] = ($created['status'] ?? '') === 'created'
        && is_file($backupRoot . '/' . $created['backup_key'] . '.zip');

    $restoreDatabase = substr(preg_replace('/[^A-Za-z0-9_]/', '', $databaseName), 0, 34)
        . '_restore_' . $token . '_test';
    $verified = $service->verifyPackage((string) $created['backup_key'], $restoreDatabase, null);
    $results['isolated_restore_verified'] = ($verified['status'] ?? '') === 'verified'
        && !empty($verified['verified_at']);
    $summary = json_decode((string) ($verified['verification_summary'] ?? ''), true);
    $results['verification_has_database_and_files'] = is_array($summary)
        && (int) ($summary['table_count'] ?? 0) > 0
        && (int) ($summary['file_count'] ?? 0) === 2;

    $service->assertUsableVerifiedReceipt((string) $created['backup_key']);
    $results['verified_receipt_matches_current_source'] = true;

    file_put_contents($uploadsRoot . '/fixture.txt', 'changed-after-verification');
    try {
        $service->assertUsableVerifiedReceipt((string) $created['backup_key']);
        $results['changed_source_rejected'] = false;
    } catch (RuntimeException $error) {
        $results['changed_source_rejected'] = true;
    }
} finally {
    $removeRuntime($runtimeRoot);
}

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);

