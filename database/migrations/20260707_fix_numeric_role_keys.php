<?php

/**
 * Migration: إصلاح مفاتيح الأدوار الرقمية/الفارغة في staff_roles.
 *
 * المشكلة: عند ترك حقل role_key فارغاً في النموذج، كانت normalize_staff_role_key()
 * تُرجع ''، وكان MySQL قد يُولّد قيماً مثل "1" كمفتاح. هذه المفاتيح النصية الرقمية
 * تتحول إلى int عند استخدامها كمفاتيح array في PHP، فتفشل المقارنة الصارمة في
 * staff_accounts.php عند تعيين الدور.
 *
 * الحل: إعادة تسمية كل مفتاح رقمي/فارغ إلى مفتاح نصي صالح (prefix "role_").
 *
 * Idempotent.
 */
return static function (PDO $db): void {
    // التحقق من وجود الجداول (تُنشأ وقت التشغيل في staff_accounts.php)
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    };

    if (!$tableExists('staff_roles')) {
        return; // الجداول غير موجودة بعد — لا شيء لنفعله
    }

    // جلب كل المفاتيح الرقمية أو الفارغة
    $rows = $db->query("SELECT id, role_key, role_name FROM staff_roles
                        WHERE role_key IS NULL
                           OR role_key = ''
                           OR role_key REGEXP '^[0-9]+$'")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $oldKey = (string)$row['role_key'];
        // توليد مفتاح نصي صالح من اسم الدور إن أمكن، وإلا من id
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', (string)$row['role_name']));
        if ($base === '' || ctype_digit($base)) {
            $base = 'r' . (int)$row['id'];
        }
        $base = substr($base, 0, 12);
        $newKey = 'role_' . $base;

        // ضمان التفرّد (إذا كان المفتاح الجديد مستخدماً بالفعل، أضف لاحقة)
        $finalKey = $newKey;
        $suffix = 1;
        while (true) {
            $check = $db->prepare("SELECT COUNT(*) FROM staff_roles WHERE role_key = ? AND id <> ?");
            $check->execute([$finalKey, (int)$row['id']]);
            if ((int)$check->fetchColumn() === 0) {
                break;
            }
            $finalKey = $newKey . '_' . $suffix++;
        }

        // تحديث staff_roles
        $upd = $db->prepare("UPDATE staff_roles SET role_key = ? WHERE id = ?");
        $upd->execute([$finalKey, (int)$row['id']]);

        // تحديث staff_role_pages (إن وُجد الجدول)
        if ($tableExists('staff_role_pages') && $oldKey !== '') {
            $upd2 = $db->prepare("UPDATE staff_role_pages SET role_key = ? WHERE role_key = ?");
            $upd2->execute([$finalKey, $oldKey]);
        }

        // تحديث المستخدمين الذين يحملون المفتاح القديم كدور
        if ($oldKey !== '') {
            $upd3 = $db->prepare("UPDATE users SET role = ? WHERE role = ?");
            $upd3->execute([$finalKey, $oldKey]);
        }
    }
};
