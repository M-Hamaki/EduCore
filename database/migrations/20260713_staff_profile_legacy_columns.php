<?php

return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };

    $columns = [
        'military_status' => 'VARCHAR(255) NULL AFTER marital_status',
        'public_service_status' => 'VARCHAR(255) NULL AFTER military_status',
        'promotions' => 'TEXT NULL AFTER work_history',
        'status_history' => 'TEXT NULL AFTER promotions',
    ];
    foreach ($columns as $column => $definition) {
        if (!$columnExists('staff_profiles', $column)) {
            $db->exec("ALTER TABLE staff_profiles ADD `{$column}` {$definition}");
        }
    }
};
