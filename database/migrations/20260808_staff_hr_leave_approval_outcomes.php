<?php

declare(strict_types=1);

/**
 * Adds the append-only restore movement required by an approved early return
 * or cancellation. It moves only historical consumed units back to available
 * units; no request, balance, or payroll fact is overwritten in place.
 */
return static function (PDO $db): void {
    $table = 'staff_leave_balance_movements';
    $tableExists = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $tableExists->execute([$table]);
    if ((int) $tableExists->fetchColumn() === 0) {
        return;
    }

    $column = $db->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $column->execute([$table, 'movement_type']);
    $currentType = strtolower((string) $column->fetchColumn());
    if (str_contains($currentType, "'restore'")) {
        return;
    }

    $db->exec(
        "ALTER TABLE staff_leave_balance_movements
         MODIFY COLUMN movement_type
         ENUM('grant','accrue','reserve','consume','release','restore','carry','expire','adjust','reverse')
         NOT NULL"
    );
};
