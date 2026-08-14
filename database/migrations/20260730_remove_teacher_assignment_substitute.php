<?php

/**
 * Migration: إزالة تصنيف «المعلم البديل» غير المستخدم من تعيينات المعلمين.
 *
 * Rollback:
 * ALTER TABLE teacher_subject_assignments
 * ADD COLUMN is_substitute TINYINT(1) NOT NULL DEFAULT 0 AFTER class_id;
 *
 * لا يمكن لمسار الرجوع استعادة القيم التاريخية لأن التصنيف لم يكن جزءاً من
 * قواعد الصلاحيات أو التشغيل، وقد تقررت إزالته نهائياً من نموذج التعيين.
 */
return static function (PDO $db): void {
    $columnStmt = $db->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $columnStmt->execute(['teacher_subject_assignments', 'is_substitute']);

    if (!$columnStmt->fetchColumn()) {
        echo "Teacher assignment substitute column already absent.\n";
        return;
    }

    $db->exec('ALTER TABLE teacher_subject_assignments DROP COLUMN is_substitute');
    echo "Teacher assignment substitute column removed.\n";
};
