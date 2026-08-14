<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    };

    if (!$columnExists('stages', 'is_experimental')) {
        $db->exec(
            "ALTER TABLE stages
             ADD COLUMN is_experimental TINYINT(1) NOT NULL DEFAULT 0 AFTER status"
        );
    }
    if (!$indexExists('stages', 'idx_stages_experimental_status')) {
        $db->exec(
            'ALTER TABLE stages
             ADD KEY idx_stages_experimental_status (is_experimental, status)'
        );
    }

    if (!$columnExists('classes', 'is_experimental')) {
        $db->exec(
            "ALTER TABLE classes
             ADD COLUMN is_experimental TINYINT(1) NOT NULL DEFAULT 0 AFTER status"
        );
    }
    if (!$indexExists('classes', 'idx_classes_year_experimental_status')) {
        $db->exec(
            'ALTER TABLE classes
             ADD KEY idx_classes_year_experimental_status
             (academic_year_id, is_experimental, status)'
        );
    }
};
