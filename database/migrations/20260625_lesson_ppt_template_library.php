<?php
require_once dirname(__DIR__, 2) . '/config/database.php';

$db = (new Database())->getConnection();

$db->exec("
    CREATE TABLE IF NOT EXISTS lesson_ppt_templates (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        subject VARCHAR(120) NULL,
        stage VARCHAR(120) NULL,
        lesson_type VARCHAR(120) NULL,
        language VARCHAR(20) NULL,
        min_slides INT NOT NULL DEFAULT 0,
        max_slides INT NOT NULL DEFAULT 0,
        theme_hint VARCHAR(120) NULL,
        keywords TEXT NULL,
        file_path VARCHAR(500) NOT NULL,
        thumbnail_path VARCHAR(500) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (is_active),
        INDEX idx_subject (subject),
        INDEX idx_stage (stage),
        INDEX idx_lesson_type (lesson_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "Lesson PowerPoint template library schema is ready.\n";
