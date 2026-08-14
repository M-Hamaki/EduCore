<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();
$columns = [
    'powerpoint_path' => "VARCHAR(500) NULL AFTER custom_content",
    'powerpoint_theme' => "VARCHAR(30) NULL AFTER powerpoint_path",
    'powerpoint_status' => "VARCHAR(20) NULL AFTER powerpoint_theme",
    'generation_error' => "TEXT NULL AFTER powerpoint_status",
];
foreach ($columns as $name => $definition) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_lessons' AND COLUMN_NAME=?");
    $stmt->execute([$name]);
    if (!$stmt->fetchColumn()) $db->exec("ALTER TABLE ai_lessons ADD COLUMN `$name` $definition");
}
$lessonColumns = ['exam_mc_count'=>'INT DEFAULT 10','exam_tf_count'=>'INT DEFAULT 10','exam_essay_count'=>'INT DEFAULT 0'];
foreach ($lessonColumns as $name=>$definition) {
    $stmt=$db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_lessons' AND COLUMN_NAME=?");$stmt->execute([$name]);if(!$stmt->fetchColumn())$db->exec("ALTER TABLE ai_lessons ADD COLUMN `$name` $definition");
}
$stmt=$db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_online_exams' AND COLUMN_NAME='exam_theme'");$stmt->execute();if(!$stmt->fetchColumn())$db->exec("ALTER TABLE ai_online_exams ADD COLUMN exam_theme VARCHAR(20) DEFAULT 'classic'");
echo "AI lesson PowerPoint schema is ready.\n";
