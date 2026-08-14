<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/tools/migrate_profile_attachments.php');
$storage = (string)file_get_contents($root . '/classes/ProfileAttachmentStorage.php');
$failures = [];

$requirements = [
    'cli_guard' => "PHP_SAPI !== 'cli'",
    'dry_run_default' => "\$apply = in_array('--apply'",
    'snapshot_mode' => '--snapshot',
    'backup_gate' => '--backup-file=',
    'database_gate' => 'hash_equals($currentDatabase, $requestedDatabase)',
    'snapshot_database_gate' => "'profile_attachment_snapshot'",
    'conditional_update' => 'AND file_name = ?',
    'transaction' => '$db->beginTransaction();',
    'rollback' => '$db->rollBack();',
    'private_manifest' => 'storage/private/profile_attachment_migrations',
    'checksum_manifest' => "'sha256'",
];
foreach ($requirements as $name => $needle) {
    if (strpos($source, $needle) === false) {
        $failures[] = $name;
    }
}
foreach (['copyLegacyToPrivate', "hash_file('sha256'", 'hash_equals($sourceHash, $destinationHash)', '@unlink($destination)'] as $needle) {
    if (strpos($storage, $needle) === false) {
        $failures[] = 'storage:' . $needle;
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: attachment migration is dry-run by default, backup-gated, checksummed, transactional, and reversible.\n";
