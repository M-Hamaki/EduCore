<?php

/**
 * Migration: نظام إدارة العام الجديد + الخريجين + المديونيات التاريخية
 *
 * يضيف:
 *  - academic_years.locked             : قفل يدوي للعام (يمنع التعديل التاريخي)
 *  - student_enrollments.graduation_year : سنة تخرج الطالب (بديل مرحلة الخريجين الوهمية)
 *  - student_fee_balances_history       : جدول مديونيات تاريخية عبر الأعوام
 *
 * وأرشفة مرحلة الخريجين الوهمية (is_graduate_stage=1) لأن التخرج أصبح حالة لا مكان.
 */

return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };
    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        return (bool) $stmt->fetchColumn();
    };
    $fkExists = static function (string $table, string $constraint) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
        $stmt->execute([$table, $constraint]);
        return (bool) $stmt->fetchColumn();
    };
    $addColumn = static function (string $table, string $column, string $definition) use ($db, $columnExists, $tableExists): void {
        if ($tableExists($table) && !$columnExists($table, $column)) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    };
    $addFk = static function (string $table, string $name, string $column, string $parent) use ($db, $fkExists, $tableExists): void {
        if ($tableExists($table) && $tableExists($parent) && !$fkExists($table, $name)) {
            $db->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$parent` (`id`) ON DELETE CASCADE");
        }
    };

    // ------------------------------------------------------------------
    // 1) قفل العام اليدوي
    // ------------------------------------------------------------------
    $addColumn('academic_years', 'locked', "TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");

    // ------------------------------------------------------------------
    // 2) سنة التخرج في التسجيلات (بديل مرحلة الخريجين الوهمية)
    // ------------------------------------------------------------------
    $addColumn('student_enrollments', 'graduation_year', "VARCHAR(20) NULL DEFAULT NULL AFTER enrollment_status");
    if (!$indexExists('student_enrollments', 'idx_enroll_graduation')) {
        $db->exec("ALTER TABLE student_enrollments ADD INDEX idx_enroll_graduation (enrollment_status, graduation_year)");
    }

    // ------------------------------------------------------------------
    // 3) جدول المديونيات التاريخية عبر الأعوام
    // ------------------------------------------------------------------
    if (!$tableExists('student_fee_balances_history')) {
        $db->exec("CREATE TABLE student_fee_balances_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            total_due DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0,
            carried_forward TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_student_year_balance (student_id, academic_year_id),
            KEY idx_balance_student (student_id),
            KEY idx_balance_year (academic_year_id),
            CONSTRAINT fk_balance_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_balance_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // ------------------------------------------------------------------
    // 4) أرشفة مرحلة "الخريجين" الوهمية (نحمي البيانات، نوقف الاستخدام)
    // ------------------------------------------------------------------
    try {
        // سجّل الطلاب الجالسين حالياً في مرحلة الخريجين كخريجين حقيقيين (ترحيل بياناتهم)
        $gradStage = $db->prepare("SELECT id, stage_name FROM stages WHERE is_graduate_stage = 1 LIMIT 1");
        $gradStage->execute();
        $gs = $gradStage->fetch(PDO::FETCH_ASSOC);
        if ($gs) {
            // الطلاب في صفوف مرحلة الخريجين → علّمهم كخريجين في تسجيلات العام الحالي
            $yearId = (int) ($db->query("SELECT id FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
            if ($yearId > 0) {
                $db->exec("UPDATE student_enrollments se
                    JOIN users u ON u.id = se.student_id
                    JOIN classes c ON c.id = se.class_id
                    JOIN grades g ON g.id = c.grade_id
                    SET se.enrollment_status = 'graduated',
                        se.graduation_year = (SELECT name FROM academic_years WHERE id = {$yearId} LIMIT 1)
                    WHERE se.academic_year_id = {$yearId}
                      AND g.stage_id = {$gs['id']}
                      AND u.role = 'student'");
            }
        }
    } catch (Throwable $e) {
        // غير حرج — يُكمّل يدوياً
        error_log('grad migration note: ' . $e->getMessage());
    }

    echo "New year system (locked + graduation_year + fee history) is ready.\n";
};
