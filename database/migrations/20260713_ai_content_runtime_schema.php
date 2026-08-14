<?php

return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };

    $db->exec("CREATE TABLE IF NOT EXISTS lesson_ppt_templates (
        id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL, subject VARCHAR(120) NULL,
        stage VARCHAR(120) NULL, lesson_type VARCHAR(120) NULL, language VARCHAR(20) NULL,
        min_slides INT NOT NULL DEFAULT 0, max_slides INT NOT NULL DEFAULT 0, theme_hint VARCHAR(120) NULL,
        keywords TEXT NULL, file_path VARCHAR(500) NOT NULL, thumbnail_path VARCHAR(500) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (is_active), INDEX idx_subject (subject), INDEX idx_stage (stage),
        INDEX idx_lesson_type (lesson_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $canvaColumns = [
        'template_type' => "ENUM('design','brand_template') NOT NULL DEFAULT 'design' AFTER design_id",
        'dataset_json' => 'LONGTEXT NULL AFTER thumbnail_url',
        'last_error' => 'TEXT NULL AFTER is_active',
    ];
    foreach ($canvaColumns as $name => $definition) {
        if (!$columnExists('canva_templates', $name)) {
            $db->exec("ALTER TABLE canva_templates ADD COLUMN `$name` $definition");
        }
    }

    $lessonColumns = [
        'class_activities' => 'LONGTEXT NULL AFTER visual_materials',
        'educational_stories' => 'LONGTEXT NULL AFTER class_activities',
        'mind_maps' => 'LONGTEXT NULL AFTER educational_stories',
        'lesson_summary' => 'LONGTEXT NULL AFTER mind_maps',
        'custom_content' => 'LONGTEXT NULL AFTER lesson_summary',
    ];
    foreach ($lessonColumns as $name => $definition) {
        if (!$columnExists('ai_lessons', $name)) {
            $db->exec("ALTER TABLE ai_lessons ADD COLUMN `$name` $definition");
        }
    }
};
