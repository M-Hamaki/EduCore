<?php
/**
 * CLI script used by Windows Task Scheduler to create SQL dumps.
 *
 * It reads backup settings from the `settings` table and then runs `mysqldump`
 * to write a .sql file into the configured folder.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/database.php';

$database = new Database();
$db = $database->getConnection();

// Read required settings keys from the settings table.
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$enabled = isset($settings['db_backup_sql_enabled']) && $settings['db_backup_sql_enabled'] === '1';
if (!$enabled) {
    exit(0);
}

$dumpDir = $settings['db_backup_sql_dump_path'] ?? '';
$retentionDays = isset($settings['db_backup_sql_retention_days']) ? (int)$settings['db_backup_sql_retention_days'] : 0;

// Ensure dump directory is valid.
$dumpDir = trim((string)$dumpDir);
if ($dumpDir === '') {
    error_log('[EduCore][DB Backup SQL] Dump path is empty.');
    exit(1);
}

// Normalize directory path: remove trailing slashes.
$dumpDir = rtrim($dumpDir, "\\/").DIRECTORY_SEPARATOR;
if (!is_dir($dumpDir)) {
    error_log('[EduCore][DB Backup SQL] Dump path does not exist: ' . $dumpDir);
    exit(1);
}

// Detect mysqldump.exe from XAMPP typical structure.
$xamppRoot = dirname(dirname($projectRoot)); // EduCore -> htdocs -> xampp
$mysqldump = $xamppRoot . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe';

if (!file_exists($mysqldump)) {
    error_log('[EduCore][DB Backup SQL] mysqldump.exe not found at: ' . $mysqldump);
    exit(1);
}

// Database credentials are loaded from .env via config/database.php defines.
$dbName = defined('DB_NAME') ? DB_NAME : ($settings['db_backup_sql_db_name'] ?? '');
$dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
$dbUser = defined('DB_USERNAME') ? DB_USERNAME : 'root';
$dbPass = defined('DB_PASSWORD') ? DB_PASSWORD : '';

if (!$dbName) {
    error_log('[EduCore][DB Backup SQL] DB_NAME is empty.');
    exit(1);
}

$timestamp = date('Ymd_His');
$fileName = $dbName . '_' . $timestamp . '.sql';
$outputFile = $dumpDir . $fileName;

// Build a safe mysqldump command (no shell interpretation; use proc_open).
$args = [
    '--host=' . $dbHost,
    '--user=' . $dbUser,
    '--single-transaction',
    '--quick',
    '--routines',
    '--triggers',
    '--events',
    '--skip-dump-date',
    $dbName,
    '--result-file=' . $outputFile
];

$cmd = array_merge([$mysqldump], $args);

$env = $_ENV;
$env['MYSQL_PWD'] = (string)$dbPass;

$descriptorspec = [
    1 => ['pipe', 'w'], // stdout
    2 => ['pipe', 'w'], // stderr
];

$process = proc_open($cmd, $descriptorspec, $pipes, null, $env);
if (!is_resource($process)) {
    error_log('[EduCore][DB Backup SQL] Failed to start mysqldump process.');
    exit(1);
}

// Capture output (best-effort).
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
foreach ($pipes as $p) {
    if (is_resource($p)) {
        fclose($p);
    }
}

$exitCode = proc_close($process);

$statusKey = 'db_backup_sql_last_status';
$runKey = 'db_backup_sql_last_run_at';

$statusMessage = $exitCode === 0
    ? 'OK: ' . $fileName
    : 'ERROR (exitCode=' . $exitCode . '): ' . trim($stderr ?: $stdout);

$now = date('Y-m-d H:i:s');

$upStmt = $db->prepare("
    INSERT INTO settings (setting_key, setting_value, description)
    VALUES (:k, :v, :d)
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)
");

$desc1 = 'آخر حالة تنفيذ لعملية تصدير SQL تلقائي';
$upStmt->execute([':k' => $statusKey, ':v' => $statusMessage, ':d' => $desc1]);
$desc2 = 'وقت آخر تنفيذ لعملية تصدير SQL تلقائي';
$upStmt->execute([':k' => $runKey, ':v' => $now, ':d' => $desc2]);

// Retention: delete old dumps.
if ($exitCode === 0 && $retentionDays > 0) {
    $cutoff = time() - ($retentionDays * 86400);
    $pattern = $dbName . '_*.sql';
    $files = glob($dumpDir . $pattern) ?: [];
    foreach ($files as $f) {
        try {
            if (is_file($f) && filemtime($f) !== false && filemtime($f) < $cutoff) {
                @unlink($f);
            }
        } catch (Throwable $e) {
            // Continue.
        }
    }
}

exit($exitCode === 0 ? 0 : 1);
