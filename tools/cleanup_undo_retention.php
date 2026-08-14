<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/UndoManager.php';

$apply = in_array('--apply', $argv, true);
$databaseArgument = null;
foreach ($argv as $argument) {
    if (strpos($argument, '--database=') === 0) $databaseArgument = substr($argument, 11);
}
if ($apply && (!$databaseArgument || !preg_match('/^[A-Za-z0-9_]+$/', $databaseArgument))) {
    throw new RuntimeException('Apply requires --database=<exact connected database>.');
}

$db = (new Database())->getConnection();
if (!$db instanceof PDO) throw new RuntimeException('Database connection failed.');
$connected = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($apply && $databaseArgument !== $connected) {
    throw new RuntimeException('Connected database does not match --database.');
}

$hours = UndoManager::retentionHours();
$expired = (int) $db->query(
    "SELECT COUNT(*) FROM undo_log WHERE created_at < DATE_SUB(NOW(), INTERVAL {$hours} HOUR)"
)->fetchColumn();
$overflowUsers = $db->query(
    'SELECT user_id, COUNT(*) AS row_count FROM undo_log GROUP BY user_id HAVING COUNT(*) > 500'
)->fetchAll(PDO::FETCH_ASSOC);
$overflow = array_sum(array_map(static fn(array $row): int => (int) $row['row_count'] - 500, $overflowUsers));

echo 'UNDO_RETENTION_MODE=' . ($apply ? 'apply' : 'dry-run') . PHP_EOL;
echo 'CONNECTED_DATABASE=' . $connected . PHP_EOL;
echo 'EXPIRED_ROWS=' . $expired . PHP_EOL;
echo 'OVERFLOW_ROWS=' . $overflow . PHP_EOL;
if (!$apply) exit(0);

$db->beginTransaction();
try {
    $deleted = $db->exec(
        "DELETE FROM undo_log WHERE created_at < DATE_SUB(NOW(), INTERVAL {$hours} HOUR)"
    );
    foreach ($overflowUsers as $row) {
        $userId = (int) $row['user_id'];
        $cutoff = $db->prepare('SELECT id FROM undo_log WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET 500');
        $cutoff->execute([$userId]);
        $cutoffId = $cutoff->fetchColumn();
        if ($cutoffId) {
            $remove = $db->prepare('DELETE FROM undo_log WHERE user_id = ? AND id <= ?');
            $remove->execute([$userId, (int) $cutoffId]);
            $deleted += $remove->rowCount();
        }
    }
    $db->commit();
    echo 'DELETED_ROWS=' . $deleted . PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
