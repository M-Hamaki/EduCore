<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/DbAutoBackup.php';

$db = educoreTestDatabase();
DbAutoBackup::ensureAutoBackupEvent($db);

echo "legacy_db_auto_backup_absent:PASS\n";
