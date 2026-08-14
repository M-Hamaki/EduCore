<?php

/**
 * Migration: إزالة منطق مرحلة الخريجين وجدول ترحيل الطلاب القديم.
 *
 * الخريج أصبح حالة داخل student_enrollments:
 *   enrollment_status = 'graduated'
 *   graduation_year = اسم العام الدراسي
 */

return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };

    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };

    if ($tableExists('student_promotions')) {
        $db->exec('DROP TABLE student_promotions');
    }

    if ($tableExists('stages') && $columnExists('stages', 'is_graduate_stage')) {
        $db->exec('ALTER TABLE stages DROP COLUMN is_graduate_stage');
    }

    echo "Graduate stage flag and legacy student_promotions table removed.\n";
};
