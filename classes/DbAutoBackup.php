<?php

declare(strict_types=1);

/**
 * Compatibility adapter for the retired in-schema MySQL EVENT backup.
 *
 * The former implementation created snapshot tables in the production schema;
 * this was not an independent backup and caused unbounded schema drift.
 */
final class DbAutoBackup
{
    private const EVENT_NAME = 'EduCore_AutoBackup_Event';
    private const PROCEDURE_NAME = 'EduCore_AutoBackup_Do';

    /**
     * @deprecated The in-schema event backup is retired. Use the supported SQL backup workflow.
     */
    public static function ensureAutoBackupEvent(PDO $db): void
    {
        $event = $db->prepare(
            'SELECT 1 FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = ?'
        );
        $event->execute([self::EVENT_NAME]);
        $routine = $db->prepare(
            "SELECT 1 FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ? AND ROUTINE_TYPE = 'PROCEDURE'"
        );
        $routine->execute([self::PROCEDURE_NAME]);

        if ($event->fetchColumn() || $routine->fetchColumn()) {
            throw new RuntimeException('Legacy database auto-backup objects are still installed. Run pending migrations.');
        }
    }
}
