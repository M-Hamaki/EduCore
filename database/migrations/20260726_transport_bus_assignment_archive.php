<?php

declare(strict_types=1);

/**
 * Reversible archival lifecycle for annual student bus assignments.
 *
 * Existing active assignments remain active. Unassignment now archives the row
 * instead of deleting it, preserving before/after history and rollback evidence.
 */
return static function (PDO $db): void {
    $table = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $table->execute(['student_bus_assignments']);
    if ((int) $table->fetchColumn() === 0) {
        return;
    }
    $columnExists = static function (string $name) use ($db): bool {
        $column = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $column->execute(['student_bus_assignments', $name]);
        return (int) $column->fetchColumn() > 0;
    };
    if (!$columnExists('status')) {
        $db->exec(
            "ALTER TABLE student_bus_assignments
             ADD COLUMN status ENUM('active','archived') NOT NULL DEFAULT 'active' AFTER notes"
        );
    }
    if (!$columnExists('archived_at')) {
        $db->exec(
            'ALTER TABLE student_bus_assignments
             ADD COLUMN archived_at DATETIME NULL AFTER status'
        );
    }
    if (!$columnExists('archived_by')) {
        $db->exec(
            'ALTER TABLE student_bus_assignments
             ADD COLUMN archived_by INT NULL AFTER archived_at'
        );
    }
    $index = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $index->execute(['student_bus_assignments', 'idx_student_bus_assignment_status']);
    if ((int) $index->fetchColumn() === 0) {
        $db->exec(
            'ALTER TABLE student_bus_assignments
             ADD KEY idx_student_bus_assignment_status (academic_year_id, status)'
        );
    }
};
