<?php

/**
 * Migration: توافق إضافي لتقارير الدرجات المنشورة.
 *
 * يضمن وجود وقت النشر حتى في القواعد التي طبقت compatibility قبل إضافة العمود.
 */

return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };

    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };

    if ($tableExists('published_reports') && !$columnExists('published_reports', 'published_at')) {
        $db->exec('ALTER TABLE published_reports ADD COLUMN published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    echo "Assessment published reports compatibility is ready.\n";
};
