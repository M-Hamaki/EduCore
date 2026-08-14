<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $columns = static function (string $table) use ($db): array {
        $stmt = $db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };

    $addColumns = static function (string $table, array $definitions) use ($db, $columns): void {
        $existing = $columns($table);
        foreach ($definitions as $name => $definition) {
            if (!in_array($name, $existing, true)) {
                $db->exec("ALTER TABLE `$table` ADD `$name` $definition");
            }
        }
    };

    $addColumns('activity_logs', [
        'request_id' => 'CHAR(32) NULL AFTER ip_address',
        'batch_id' => 'CHAR(32) NULL AFTER request_id',
        'result' => "VARCHAR(20) NOT NULL DEFAULT 'success' AFTER batch_id",
        'route' => 'VARCHAR(500) NULL AFTER result',
        'user_agent' => 'VARCHAR(500) NULL AFTER route',
        'undo_log_id' => 'INT NULL AFTER user_agent',
    ]);

    $addColumns('undo_log', [
        'request_id' => 'CHAR(32) NULL AFTER batch_id',
        'can_undo' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER request_id',
        'undone_by' => 'INT NULL AFTER is_undone',
        'undone_at' => 'DATETIME NULL AFTER undone_by',
        'undo_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER undone_at",
        'failure_reason' => 'VARCHAR(500) NULL AFTER undo_status',
    ]);

    $indexes = [
        ['activity_logs', 'idx_activity_request', '`request_id`'],
        ['activity_logs', 'idx_activity_batch', '`batch_id`, `created_at`'],
        ['activity_logs', 'idx_activity_target_created', '`target_type`, `target_id`, `created_at`'],
        ['undo_log', 'idx_undo_available', '`user_id`, `can_undo`, `is_undone`, `created_at`'],
        ['undo_log', 'idx_undo_request', '`request_id`'],
    ];
    foreach ($indexes as [$table, $name, $definition]) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $name]);
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE `$table` ADD INDEX `$name` ($definition)");
        }
    }

    $db->exec("UPDATE undo_log
        SET can_undo = 0, failure_reason = 'reversal_required'
        WHERE table_name = 'fee_payments' AND is_undone = 0");
    $db->exec("UPDATE undo_log
        SET can_undo = 0, failure_reason = 'credential_snapshot_excluded'
        WHERE table_name IN ('users', 'school_emails') AND action_type = 'delete' AND is_undone = 0");
};
