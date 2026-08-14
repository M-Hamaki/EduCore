<?php
/**
 * Migration: Add backup_bus_id to student_bus_assignments
 * إضافة الحافلة الاحتياطي لتعيينات الطلاب
 */

$migration = static function (PDO $db): void {
    $columns = $db->query("SHOW COLUMNS FROM student_bus_assignments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('backup_bus_id', $columns, true)) {
        // إضافة العمود
        $db->exec("ALTER TABLE student_bus_assignments ADD COLUMN backup_bus_id INT NULL DEFAULT NULL AFTER bus_id");
        
        // إضافة مفتاح أجنبي
        $db->exec("ALTER TABLE student_bus_assignments ADD CONSTRAINT fk_student_bus_backup FOREIGN KEY (backup_bus_id) REFERENCES buses(id) ON DELETE SET NULL");
        
        // إضافة الفهرس
        $db->exec("CREATE INDEX idx_student_bus_backup ON student_bus_assignments(backup_bus_id)");
    }
};

// تشغيل مباشر إذا تم استدعاء الملف بشكل منفصل
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    require_once dirname(__DIR__, 2) . '/config/database.php';
    $db = (new Database())->getConnection();
    try {
        $migration($db);
        echo "✅ Migration completed successfully.\n";
    } catch (Exception $e) {
        echo "❌ Migration failed: " . $e->getMessage() . "\n";
    }
}

return $migration;
