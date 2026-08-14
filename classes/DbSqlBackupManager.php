<?php
/**
 * Helper to create/disable Windows Scheduled Task for SQL dumps.
 *
 * We create the task from inside the web app so the admin only changes settings.
 */
class DbSqlBackupManager {
    private const TASK_NAME = 'EduCore_DBBackup_SQL';

    /**
     * Create or update the scheduled task based on settings values.
     */
    public static function createOrUpdateScheduledTask(PDO $db): void {
        $enabled = self::getSetting($db, 'db_backup_sql_enabled', '0');
        if ($enabled !== '1') {
            return;
        }

        $interval = (int)self::getSetting($db, 'db_backup_sql_interval_minutes', '0');
        if ($interval < 1) {
            $interval = 60;
        }

        $dumpDir = (string)self::getSetting($db, 'db_backup_sql_dump_path', '');
        $dumpDir = trim($dumpDir);
        if ($dumpDir === '') {
            // Still create the task; the CLI script will log an error if path is invalid.
            // This prevents blocking the admin when they enable first.
        }

        $projectRoot = dirname(__DIR__); // classes -> EduCore root
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'backup_db_sql.php';
        if (!is_file($scriptPath)) {
            throw new RuntimeException('SQL backup CLI script is missing.');
        }

        $phpBinary = PHP_BINARY;
        if (!file_exists($phpBinary)) {
            // Fallback to "php" in PATH.
            $phpBinary = 'php';
        }

        // Delete old task (idempotent).
        self::runCommand('schtasks /Delete /TN "' . self::TASK_NAME . '" /F');

        $startTime = date('H:i', time() + 60);

        // schtasks needs /TR in a single string, escaping quotes for cmd.
        $tr = '\\"' . $phpBinary . '\\" \\"' . $scriptPath . '\\"';
        $cmd = 'schtasks /Create /TN "' . self::TASK_NAME . '" /SC MINUTE /MO ' . $interval .
            ' /ST ' . $startTime .
            ' /RU "SYSTEM" /RL HIGHEST /F /TR "' . $tr . '"';

        $out = self::runCommand($cmd, true);
        self::storeSchedulerOutcome($db, 'enabled', 'OK: ' . trim($out), $interval);
    }

    public static function disableScheduledTask(PDO $db): void {
        self::runCommand('schtasks /Delete /TN "' . self::TASK_NAME . '" /F');
        self::storeSchedulerOutcome($db, 'disabled', 'DISABLED', null);
    }

    private static function storeSchedulerOutcome(PDO $db, string $state, string $status, ?int $interval): void {
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            self::setLastStatus($db, 'db_backup_sql_last_scheduler_status', $status);
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'configure',
                'database_backup_scheduler',
                null,
                self::TASK_NAME,
                [
                    'state' => $state,
                    'interval_minutes' => $interval,
                    'external_effect' => true,
                    'direct_undo_available' => false,
                ]
            );
            if ($ownsTransaction) $db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function setLastStatus(PDO $db, string $key, string $value): void {
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value, description)
            VALUES (:k, :v, :d)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)
        ");
        $stmt->execute([
            ':k' => $key,
            ':v' => $value,
            ':d' => 'Scheduler status for automatic DB SQL backups'
        ]);
    }

    private static function getSetting(PDO $db, string $key, string $default = '0'): string {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1");
            $stmt->execute([':k' => $key]);
            $v = $stmt->fetchColumn();
            if ($v === false || $v === null || $v === '') return $default;
            return (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    /**
     * Run a Windows command and optionally return captured output.
     */
    private static function runCommand(string $cmd, bool $captureOutput = false): string {
        // Prefer shell_exec if available (quickest).
        if (function_exists('shell_exec')) {
            if ($captureOutput) {
                return (string)shell_exec($cmd . ' 2>&1');
            }
            @shell_exec($cmd . ' > NUL 2>&1');
            return '';
        }

        // Fallback: use proc_open to run through cmd.exe and capture output.
        $descriptorspec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        $process = proc_open(['cmd.exe', '/c', $cmd], $descriptorspec, $pipes);
        if (!is_resource($process)) {
            return '';
        }

        $stdout = '';
        $stderr = '';
        if (!empty($pipes[1])) {
            $stdout = (string)stream_get_contents($pipes[1]);
        }
        if (!empty($pipes[2])) {
            $stderr = (string)stream_get_contents($pipes[2]);
        }
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($process);

        return $captureOutput ? trim($stdout . "\n" . $stderr) : '';
    }
}

