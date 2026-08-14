<?php
/**
 * Migration: Canva Integration Tables
 * إنشاء جداول تكامل Canva
 */

$tables = [
    'canva_oauth_tokens' => "
        CREATE TABLE IF NOT EXISTS canva_oauth_tokens (
            id              INT          PRIMARY KEY AUTO_INCREMENT,
            access_token    TEXT         NOT NULL,
            refresh_token   TEXT         NULL,
            expires_at      INT          NULL,
            scope           VARCHAR(500) NULL,
            created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'canva_templates' => "
        CREATE TABLE IF NOT EXISTS canva_templates (
            id              INT          PRIMARY KEY AUTO_INCREMENT,
            design_id       VARCHAR(100) NOT NULL UNIQUE,
            template_type    ENUM('design','brand_template') NOT NULL DEFAULT 'design',
            name            VARCHAR(255) NULL,
            thumbnail_url   TEXT         NULL,
            dataset_json    LONGTEXT     NULL,
            pptx_local_path VARCHAR(500) NULL,
            is_active       TINYINT(1)   NOT NULL DEFAULT 0,
            last_error      TEXT         NULL,
            created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
];

require_once dirname(__DIR__, 2) . '/config/database.php';
$db = (new Database())->getConnection();

foreach ($tables as $name => $sql) {
    try {
        $db->exec($sql);
        echo "✅ Table '$name' is ready.\n";
    } catch (PDOException $e) {
        echo "❌ Error creating '$name': " . $e->getMessage() . "\n";
    }
}

echo "\nCanva integration schema is ready.\n";
