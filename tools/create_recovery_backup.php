<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/RecoveryBackupService.php';

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

try {
    $receipt = (new RecoveryBackupService($db))->createPackage(null);
    echo 'RECOVERY_BACKUP_CREATED key=' . $receipt['backup_key'] . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    error_log('Recovery package creation failed: ' . $e->getMessage());
    fwrite(STDERR, "Recovery package creation failed.\n");
    exit(1);
}
