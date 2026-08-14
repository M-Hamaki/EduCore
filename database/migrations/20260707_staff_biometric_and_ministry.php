<?php

/**
 * Migration: إضافة عمودي رقم البصمة وكود الوزارة لجدول staff_profiles.
 *
 * - biometric_id: رقم تسجيل الموظف على جهاز البصمة (مستقل عن كود الموظف الداخلي).
 * - ministry_code: كود الموظف بوزارة التربية والتعليم (كان حقلًا يتيمًا في النموذج).
 *
 * Idempotent: يمكن تشغيله أكثر من مرة دون أخطاء.
 */
return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = ?
                                AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    };

    $keyExists = static function (string $table, string $keyName) use ($db): bool {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = ?
                                AND INDEX_NAME = ?");
        $stmt->execute([$table, $keyName]);
        return (int)$stmt->fetchColumn() > 0;
    };

    // 1) رقم البصمة — مستقل عن staff_profiles.employee_code (الكود الداخلي E{YYYY}{NNNN})
    //    وعن users.employee_code (الذي يكتبه نظام الحضور الحالي). هذا العمود للتسجيل/العرض اليدوي.
    if (!$columnExists('staff_profiles', 'biometric_id')) {
        $db->exec("ALTER TABLE staff_profiles
                   ADD COLUMN biometric_id VARCHAR(50) NULL
                   COMMENT 'رقم تسجيل الموظف على جهاز البصمة'
                   AFTER employee_code");
    }
    if (!$keyExists('staff_profiles', 'uk_biometric_id')) {
        // فهرس فريد لكن مع دعم NULL متعدد (MySQL يسمح بNullable UNIQUE)
        $db->exec("ALTER TABLE staff_profiles ADD UNIQUE KEY uk_biometric_id (biometric_id)");
    }

    // 2) كود الوزارة — كان حقلًا في النموذج بدون عمود مقابله في قاعدة البيانات.
    if (!$columnExists('staff_profiles', 'ministry_code')) {
        $db->exec("ALTER TABLE staff_profiles
                   ADD COLUMN ministry_code VARCHAR(50) NULL
                   COMMENT 'كود الموظف بوزارة التربية والتعليم'
                   AFTER employee_code");
    }
    if (!$keyExists('staff_profiles', 'idx_ministry_code')) {
        $db->exec("ALTER TABLE staff_profiles ADD KEY idx_ministry_code (ministry_code)");
    }
};
