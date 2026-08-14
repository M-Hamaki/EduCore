<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/ProfileAttachmentStorage.php';

$apply = in_array('--apply', $argv, true);
$snapshot = in_array('--snapshot', $argv, true);
$requestedDatabase = null;
$requestedEntity = null;
$backupFile = null;
$limit = 0;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--database=')) {
        $requestedDatabase = substr($argument, strlen('--database='));
    } elseif (str_starts_with($argument, '--entity=')) {
        $requestedEntity = substr($argument, strlen('--entity='));
    } elseif (str_starts_with($argument, '--limit=')) {
        $limit = max(0, (int)substr($argument, strlen('--limit=')));
    } elseif (str_starts_with($argument, '--backup-file=')) {
        $backupFile = substr($argument, strlen('--backup-file='));
    }
}

if ($requestedEntity !== null && !in_array($requestedEntity, ['student', 'staff'], true)) {
    fwrite(STDERR, "Invalid --entity; use student or staff.\n");
    exit(2);
}

$db = (new Database())->getConnection();
$currentDatabase = (string)$db->query('SELECT DATABASE()')->fetchColumn();
$manifestDirectory = dirname(__DIR__) . '/storage/private/profile_attachment_migrations';
if (($apply || $snapshot) && !is_dir($manifestDirectory) && !mkdir($manifestDirectory, 0750, true) && !is_dir($manifestDirectory)) {
    throw new RuntimeException('Unable to create the private migration-manifest directory.');
}

if ($apply && ($requestedDatabase === null || !hash_equals($currentDatabase, $requestedDatabase) || $backupFile === null)) {
    fwrite(STDERR, "Apply refused. First run --snapshot, then pass its path through --backup-file and --database={$currentDatabase}.\n");
    exit(2);
}
if ($apply) {
    $backupRealPath = realpath($backupFile);
    $manifestRealPath = realpath($manifestDirectory);
    if ($backupRealPath === false || $manifestRealPath === false
        || !str_starts_with(str_replace('\\', '/', $backupRealPath), rtrim(str_replace('\\', '/', $manifestRealPath), '/') . '/')) {
        fwrite(STDERR, "Apply refused. Backup file must be an existing private snapshot.\n");
        exit(2);
    }
    $backupHandle = fopen($backupRealPath, 'rb');
    $backupHeader = $backupHandle === false ? null : json_decode((string)fgets($backupHandle), true);
    if (is_resource($backupHandle)) {
        fclose($backupHandle);
    }
    if (!is_array($backupHeader) || ($backupHeader['type'] ?? '') !== 'profile_attachment_snapshot'
        || !hash_equals($currentDatabase, (string)($backupHeader['database'] ?? ''))) {
        fwrite(STDERR, "Apply refused. Snapshot database does not match the connected database.\n");
        exit(2);
    }
}

$entities = [
    'student' => 'student_attachments',
    'staff' => 'staff_attachments',
];
if ($requestedEntity !== null) {
    $entities = [$requestedEntity => $entities[$requestedEntity]];
}

$storage = new ProfileAttachmentStorage();
$summary = ['database' => $currentDatabase, 'mode' => $apply ? 'apply' : ($snapshot ? 'snapshot' : 'dry-run'), 'legacy' => 0, 'present' => 0, 'missing' => 0, 'bytes' => 0, 'migrated' => 0, 'failed' => 0];
$manifestHandle = null;
$manifestPath = null;
if ($apply || $snapshot) {
    $manifestPath = $manifestDirectory . '/' . ($snapshot ? 'snapshot_' : 'migration_') . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jsonl';
    $manifestHandle = fopen($manifestPath, 'xb');
    if ($manifestHandle === false) {
        throw new RuntimeException('Unable to create migration manifest.');
    }
    fwrite($manifestHandle, json_encode([
        'type' => $snapshot ? 'profile_attachment_snapshot' : 'profile_attachment_migration',
        'database' => $currentDatabase,
        'created_at' => date(DATE_ATOM),
        'backup_file' => $apply ? $backupRealPath : null,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

foreach ($entities as $entityType => $table) {
    $sql = "SELECT id, file_name FROM {$table} WHERE file_name NOT LIKE 'private:%' ORDER BY id";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    foreach ($db->query($sql, PDO::FETCH_ASSOC) as $row) {
        $summary['legacy']++;
        $oldName = (string)$row['file_name'];
        $oldPath = $storage->absolutePath($entityType, $oldName);
        if ($oldPath === null) {
            $summary['missing']++;
            continue;
        }
        $summary['present']++;
        $summary['bytes'] += (int)filesize($oldPath);
        if ($snapshot) {
            fwrite($manifestHandle, json_encode([
                'entity' => $entityType,
                'table' => $table,
                'id' => (int)$row['id'],
                'file_name' => $oldName,
                'sha256' => hash_file('sha256', $oldPath),
                'size' => (int)filesize($oldPath),
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
            continue;
        }
        if (!$apply) {
            continue;
        }

        $copy = null;
        try {
            $copy = $storage->copyLegacyToPrivate($entityType, $oldName);
            $db->beginTransaction();
            $update = $db->prepare("UPDATE {$table} SET file_name = ? WHERE id = ? AND file_name = ?");
            $update->execute([$copy['stored_name'], (int)$row['id'], $oldName]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Attachment row changed concurrently.');
            }
            $db->commit();
            $summary['migrated']++;
            fwrite($manifestHandle, json_encode([
                'entity' => $entityType,
                'table' => $table,
                'id' => (int)$row['id'],
                'old_name' => $oldName,
                'new_name' => $copy['stored_name'],
                'sha256' => $copy['sha256'],
                'size' => $copy['size'],
                'migrated_at' => date(DATE_ATOM),
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_array($copy) && isset($copy['stored_name'])) {
                $storage->delete($entityType, $copy['stored_name']);
            }
            $summary['failed']++;
            error_log('Profile attachment migration failed for ' . $entityType . ' #' . (int)$row['id'] . ': ' . $e->getMessage());
        }
    }
}

if (is_resource($manifestHandle)) {
    fclose($manifestHandle);
    @chmod((string)$manifestPath, 0640);
}
$summary['manifest'] = $manifestPath;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($summary['failed'] > 0 ? 1 : 0);
