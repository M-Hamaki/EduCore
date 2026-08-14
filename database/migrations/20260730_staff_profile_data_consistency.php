<?php

declare(strict_types=1);

/**
 * Aligns staff profile storage with the current form and biometric-device contract.
 *
 * - staff_profiles.biometric_id remains independent from employee codes.
 * - blank identifiers become NULL so nullable unique indexes work as intended.
 * - custom religion, marital-status, and contract values are stored losslessly.
 *
 * Rollback: restore the pre-migration database backup. Reverting the VARCHAR
 * columns to ENUM would discard valid custom values and is intentionally unsafe.
 */
return static function (PDO $db): void {
    $columnType = static function (string $table, string $column) use ($db): ?string {
        $statement = $db->prepare(
            'SELECT DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        $type = $statement->fetchColumn();
        return $type === false ? null : strtolower((string)$type);
    };

    foreach ([
        'religion' => 100,
        'marital_status' => 100,
        'contract_type' => 100,
    ] as $column => $length) {
        if ($columnType('staff_profiles', $column) === 'enum') {
            $db->exec(
                "ALTER TABLE staff_profiles
                 MODIFY `{$column}` VARCHAR({$length}) NULL"
            );
        }
    }

    $db->exec(
        "UPDATE staff_profiles
         SET biometric_id = NULL
         WHERE biometric_id IS NOT NULL AND TRIM(biometric_id) = ''"
    );
    foreach (['religion', 'marital_status', 'contract_type'] as $column) {
        $db->exec(
            "UPDATE staff_profiles
             SET `{$column}` = NULL
             WHERE `{$column}` IS NOT NULL AND TRIM(`{$column}`) = ''"
        );
    }

};
