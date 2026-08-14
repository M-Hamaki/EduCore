<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/ProfileAttachmentStorage.php';

$apply = in_array('--apply', $argv, true);
$manifestPath = null;
$requestedDatabase = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--manifest=')) {
        $manifestPath = substr($argument, strlen('--manifest='));
    } elseif (str_starts_with($argument, '--database=')) {
        $requestedDatabase = substr($argument, strlen('--database='));
    }
}

$manifestDirectory = dirname(__DIR__) . '/storage/private/profile_attachment_migrations';
$manifestRealPath = $manifestPath === null ? false : realpath($manifestPath);
$directoryRealPath = realpath($manifestDirectory);
if ($manifestRealPath === false || $directoryRealPath === false
    || !str_starts_with(str_replace('\\', '/', $manifestRealPath), rtrim(str_replace('\\', '/', $directoryRealPath), '/') . '/')) {
    fwrite(STDERR, "A private migration manifest is required.\n");
    exit(2);
}

$lines = file($manifestRealPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = $lines ? json_decode(array_shift($lines), true) : null;
if (!is_array($header) || ($header['type'] ?? '') !== 'profile_attachment_migration') {
    fwrite(STDERR, "Invalid migration manifest.\n");
    exit(2);
}

$db = (new Database())->getConnection();
$currentDatabase = (string)$db->query('SELECT DATABASE()')->fetchColumn();
if (!hash_equals($currentDatabase, (string)($header['database'] ?? ''))
    || ($apply && ($requestedDatabase === null || !hash_equals($currentDatabase, $requestedDatabase)))) {
    fwrite(STDERR, "Database confirmation does not match the migration manifest.\n");
    exit(2);
}

$tables = ['student' => 'student_attachments', 'staff' => 'staff_attachments'];
$storage = new ProfileAttachmentStorage();
$summary = ['database' => $currentDatabase, 'mode' => $apply ? 'apply' : 'dry-run', 'eligible' => 0, 'restored' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($lines as $line) {
    $entry = json_decode($line, true);
    $entity = is_array($entry) ? ($entry['entity'] ?? '') : '';
    if (!isset($tables[$entity]) || !isset($entry['id'], $entry['old_name'], $entry['new_name'], $entry['sha256'])) {
        $summary['failed']++;
        continue;
    }

    $table = $tables[$entity];
    $stmt = $db->prepare("SELECT file_name FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$entry['id']]);
    $currentName = $stmt->fetchColumn();
    $privatePath = $storage->absolutePath($entity, (string)$entry['new_name']);
    $legacyPath = $storage->absolutePath($entity, (string)$entry['old_name']);
    $privateHash = $privatePath === null ? false : hash_file('sha256', $privatePath);
    if ($currentName !== $entry['new_name'] || $legacyPath === null || $privateHash === false
        || !hash_equals((string)$entry['sha256'], $privateHash)) {
        $summary['skipped']++;
        continue;
    }

    $summary['eligible']++;
    if (!$apply) {
        continue;
    }

    try {
        $db->beginTransaction();
        $update = $db->prepare("UPDATE {$table} SET file_name = ? WHERE id = ? AND file_name = ?");
        $update->execute([(string)$entry['old_name'], (int)$entry['id'], (string)$entry['new_name']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Attachment row changed concurrently.');
        }
        $db->commit();
        $summary['restored']++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $summary['failed']++;
        error_log('Profile attachment rollback failed for ' . $entity . ' #' . (int)$entry['id'] . ': ' . $e->getMessage());
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($summary['failed'] > 0 ? 1 : 0);
