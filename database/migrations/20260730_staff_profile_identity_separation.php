<?php

declare(strict_types=1);

/**
 * Restores the intended separation between staff identifiers.
 *
 * - staff_profiles.employee_code: internal E{YYYY}{NNNN} code.
 * - staff_profiles.biometric_id: independent device identifier.
 * - users.employee_code: legacy attendance identifier; retained unchanged.
 *
 * The pre-correction backup for this deployment proves that biometric_id was
 * empty before the erroneous compatibility backfill. Values mirrored from
 * users.employee_code are therefore removed, while legacy attendance values
 * remain available in users.
 *
 * Invalid/test internal codes are replaced deterministically in profile-id
 * order. Rollback is the pre-correction SQL backup; the old test/attendance
 * identifiers also remain in users.employee_code as a recovery mapping.
 */
return static function (PDO $db): void {
    $db->beginTransaction();
    try {
        $db->exec(
            "UPDATE staff_profiles sp
             INNER JOIN users u ON u.id = sp.user_id
             SET sp.biometric_id = NULL
             WHERE NULLIF(TRIM(sp.biometric_id), '') IS NOT NULL
               AND TRIM(sp.biometric_id) = TRIM(COALESCE(u.employee_code, ''))"
        );

        $year = date('Y');
        $prefix = 'E' . $year;
        $maximumStatement = $db->prepare(
            "SELECT MAX(CAST(SUBSTRING(employee_code, 6) AS UNSIGNED))
             FROM staff_profiles
             WHERE employee_code LIKE ?"
        );
        $maximumStatement->execute([$prefix . '%']);
        $nextSequence = ((int)($maximumStatement->fetchColumn() ?: 0)) + 1;

        $invalidProfiles = $db->query(
            "SELECT id
             FROM staff_profiles
             WHERE employee_code IS NULL
                OR TRIM(employee_code) = ''
                OR employee_code NOT REGEXP '^E[0-9]{8}$'
             ORDER BY id
             FOR UPDATE"
        )->fetchAll(PDO::FETCH_COLUMN);

        $updateCode = $db->prepare(
            'UPDATE staff_profiles SET employee_code = ? WHERE id = ?'
        );
        foreach ($invalidProfiles as $profileId) {
            if ($nextSequence > 9999) {
                throw new RuntimeException(
                    'Staff employee-code sequence exceeded the supported four digits.'
                );
            }
            $employeeCode = $prefix . str_pad(
                (string)$nextSequence,
                4,
                '0',
                STR_PAD_LEFT
            );
            $updateCode->execute([$employeeCode, (int)$profileId]);
            $nextSequence++;
        }

        $remainingInvalid = (int)$db->query(
            "SELECT COUNT(*)
             FROM staff_profiles
             WHERE employee_code IS NULL
                OR employee_code NOT REGEXP '^E[0-9]{8}$'"
        )->fetchColumn();
        if ($remainingInvalid !== 0) {
            throw new RuntimeException('Staff employee-code correction was incomplete.');
        }

        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
};
