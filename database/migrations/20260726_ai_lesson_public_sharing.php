<?php

declare(strict_types=1);

/**
 * Adds revocable bearer links for explicitly shared completed AI lessons.
 *
 * Rollback order:
 * 1. Deploy code that no longer reads the public-share columns.
 * 2. Drop uq_ai_lessons_public_share_token.
 * 3. Drop public_share_revoked_at, public_share_enabled_at, public_share_token.
 */
return static function (PDO $db): void {
    $columnExists = static function (string $column) use ($db): bool {
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_lessons' AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$column]);
        return (bool) $stmt->fetchColumn();
    };
    $indexExists = static function (string $index) use ($db): bool {
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_lessons' AND INDEX_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$index]);
        return (bool) $stmt->fetchColumn();
    };

    if (!$columnExists('public_share_token')) {
        $db->exec(
            'ALTER TABLE ai_lessons
             ADD COLUMN public_share_token CHAR(64) NULL AFTER error_message'
        );
    }
    if (!$columnExists('public_share_enabled_at')) {
        $db->exec(
            'ALTER TABLE ai_lessons
             ADD COLUMN public_share_enabled_at DATETIME NULL AFTER public_share_token'
        );
    }
    if (!$columnExists('public_share_revoked_at')) {
        $db->exec(
            'ALTER TABLE ai_lessons
             ADD COLUMN public_share_revoked_at DATETIME NULL AFTER public_share_enabled_at'
        );
    }
    if (!$indexExists('uq_ai_lessons_public_share_token')) {
        $db->exec(
            'ALTER TABLE ai_lessons
             ADD UNIQUE KEY uq_ai_lessons_public_share_token (public_share_token)'
        );
    }
};
