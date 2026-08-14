<?php

declare(strict_types=1);

/**
 * Moves persisted role grants to the canonical student-numbers report URL.
 *
 * The legacy URL remains a protected redirect, but keeping a single stored
 * grant prevents the role editor from silently dropping access on its next
 * save.  The operation is idempotent and removes only an old duplicate when
 * the same role already has the canonical grant.
 */
return static function (PDO $db): void {
    $tableExists = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $tableExists->execute(['staff_role_pages']);
    if (!(bool) $tableExists->fetchColumn()) {
        return;
    }

    $startedTransaction = false;
    try {
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        $db->exec("DELETE legacy_permission
            FROM staff_role_pages AS legacy_permission
            INNER JOIN staff_role_pages AS canonical_permission
                ON canonical_permission.role_key = legacy_permission.role_key
               AND canonical_permission.page_name = 'student_numbers_reports.php'
            WHERE legacy_permission.page_name = 'school_budget.php'");

        $rename = $db->prepare(
            "UPDATE staff_role_pages
             SET page_name = 'student_numbers_reports.php'
             WHERE page_name = 'school_budget.php'"
        );
        $rename->execute();

        if ($startedTransaction) {
            $db->commit();
        }
    } catch (Throwable $error) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
};
