<?php

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS evaluation_backup_snapshots (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, backup_key VARCHAR(80) NOT NULL,
        snapshot_type ENUM('backup','pre_restore') NOT NULL, record_count INT UNSIGNED NOT NULL DEFAULT 0,
        student_count INT UNSIGNED NOT NULL DEFAULT 0, created_by INT NULL, created_by_name VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_evaluation_backup_key (backup_key),
        INDEX idx_evaluation_backup_created (created_at), INDEX idx_evaluation_backup_type (snapshot_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS evaluation_backup_rows (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, snapshot_id BIGINT UNSIGNED NOT NULL,
        evaluation_id INT NOT NULL, student_id INT NULL, row_data JSON NOT NULL,
        UNIQUE KEY uq_evaluation_backup_row (snapshot_id, evaluation_id),
        INDEX idx_evaluation_backup_student (snapshot_id, student_id),
        CONSTRAINT fk_evaluation_backup_snapshot FOREIGN KEY (snapshot_id)
            REFERENCES evaluation_backup_snapshots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $tables = $db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND (TABLE_NAME LIKE 'evaluations\\_backup\\_%' OR TABLE_NAME LIKE 'evaluations\\_pre\\_restore\\_%')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!preg_match('/^evaluations_(backup|pre_restore)_\d{4}_\d{2}_\d{2}_\d{2}_\d{2}_\d{2}$/', $table, $matches)) {
            continue;
        }
        $exists = $db->prepare('SELECT id FROM evaluation_backup_snapshots WHERE backup_key = ?');
        $exists->execute([$table]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $rows = $db->query("SELECT * FROM `$table` WHERE id > 0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $studentIds = [];
        foreach ($rows as $row) {
            if (isset($row['student_id'])) {
                $studentIds[(string)$row['student_id']] = true;
            }
        }
        $type = $matches[1] === 'pre_restore' ? 'pre_restore' : 'backup';
        $created = preg_replace(
            '/^evaluations_(?:backup|pre_restore)_(\d{4})_(\d{2})_(\d{2})_(\d{2})_(\d{2})_(\d{2})$/',
            '$1-$2-$3 $4:$5:$6',
            $table
        );
        $insert = $db->prepare(
            'INSERT INTO evaluation_backup_snapshots
             (backup_key, snapshot_type, record_count, student_count, created_by_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$table, $type, count($rows), count($studentIds), 'legacy-table-migration', $created]);
        $snapshotId = (int)$db->lastInsertId();
        $rowInsert = $db->prepare(
            'INSERT INTO evaluation_backup_rows (snapshot_id, evaluation_id, student_id, row_data) VALUES (?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $rowInsert->execute([
                $snapshotId,
                (int)$row['id'],
                isset($row['student_id']) ? (int)$row['student_id'] : null,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        }
    }
};
