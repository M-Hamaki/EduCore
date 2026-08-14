<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/RecoveryBackupService.php';

$backupKey = trim((string) ($argv[1] ?? ''));
$testDatabase = trim((string) ($argv[2] ?? ''));
if ($backupKey === '' || $testDatabase === '') {
    fwrite(STDERR, "Usage: php tools/verify_recovery_backup.php <backup-key|--latest> <new-name_test>\n");
    exit(2);
}

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

try {
    if ($backupKey === '--latest') {
        $backupKey = (string) $db->query(
            "SELECT backup_key FROM recovery_backups WHERE status IN ('created', 'verified') ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        if ($backupKey === '') {
            throw new RuntimeException('No recovery backup is available for verification.');
        }
    }
    $receipt = (new RecoveryBackupService($db))->verifyPackage($backupKey, $testDatabase, null);
    echo 'RECOVERY_BACKUP_VERIFIED key=' . $receipt['backup_key'] . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    error_log('Recovery package verification failed: ' . $e->getMessage());
    fwrite(STDERR, "Recovery package verification failed.\n");
    exit(1);
}
