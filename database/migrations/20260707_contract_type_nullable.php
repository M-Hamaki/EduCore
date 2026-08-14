<?php

/**
 * Migration: إصلاح مشكلة "نوع التعاقد دائم" الخادعة.
 *
 * العمود كان DEFAULT 'permanent'، فأي موظف جديد يأخذ القيمة تلقائياً حتى دون
 * تحديد المستخدم لها. هذا يجعل "غير محدد" غير قابل للتمييز عن "دائم فعلاً".
 *
 * الحل:
 * 1) تغيير DEFAULT إلى NULL حتى لا يأخذ الموظفون الجدد قيمة افتراضية مضللة.
 * 2) تهيئة ذكية: تحويل الصفوف التي لم يُحدّ لها نوع تعاقد فعلاً إلى NULL.
 *    معيار "حدّد فعلاً": وجود تاريخ بداية/نهاية تعاقد، أو سجل في staff_status_history
 *    يذكر نوع التعاقد.
 *
 * Idempotent.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    };

    // 1) تغيير DEFAULT إلى NULL
    $db->exec("ALTER TABLE staff_profiles
               MODIFY contract_type ENUM('permanent','temporary','parttime') NULL DEFAULT NULL");

    // 2) تهيئة ذكية: الصفوف التي لا تملك بيانات تعاقد كاملة → NULL
    //    أ) ليس لها تاريخ بداية/نهاية تعاقد
    //    ب) لا يوجد لها أي سجل حركة وظيفية يذكر نوع التعاقد
    //    نتحقق من وجود staff_status_history أولاً (يُنشأها migration منفصل)
    //    لتفادي خطأ "table not found" على قاعدة بيانات جديدة.
    if ($tableExists('staff_status_history')) {
        $db->exec("UPDATE staff_profiles
                   SET contract_type = NULL
                   WHERE contract_type = 'permanent'
                     AND (contract_start IS NULL OR contract_end IS NULL)
                     AND user_id NOT IN (
                         SELECT user_id FROM staff_status_history
                         WHERE contract_type IS NOT NULL
                           AND contract_type <> ''
                     )");
    } else {
        // لا يوجد سجل حركات → كل 'permanent' بدون تواريخ تعاقد يُعدّ غير مُحدد
        $db->exec("UPDATE staff_profiles
                   SET contract_type = NULL
                   WHERE contract_type = 'permanent'
                     AND (contract_start IS NULL OR contract_end IS NULL)");
    }
};
