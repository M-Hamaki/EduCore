<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $statement = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'undo_log' AND COLUMN_NAME = 'batch_id'"
    );
    $statement->execute();
    if ((int) $statement->fetchColumn() === 0) {
        $db->exec(
            'ALTER TABLE undo_log ADD batch_id CHAR(32) NULL AFTER page_url, '
            . 'ADD INDEX idx_undo_batch (user_id, batch_id, is_undone)'
        );
    }
};
