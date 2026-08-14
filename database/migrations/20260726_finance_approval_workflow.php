<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['finance_approval_requests']);
    if ((int) $stmt->fetchColumn() > 0) { return; }
    $db->exec(<<<'SQL'
CREATE TABLE `finance_approval_requests` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `operation_type` VARCHAR(64) NOT NULL,
    `payload_json` JSON NOT NULL,
    `status` ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `request_key` CHAR(32) NOT NULL,
    `requested_by` INT NOT NULL,
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `decided_by` INT NULL,
    `decided_at` DATETIME NULL,
    `decision_reason` VARCHAR(500) NULL,
    `result_ref_type` VARCHAR(100) NULL,
    `result_ref_id` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_finance_approval_request_key` (`request_key`),
    KEY `idx_finance_approval_status` (`status`, `operation_type`, `requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
