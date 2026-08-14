<?php

return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = ?
                                AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    };

    $addProfileColumn = static function (string $column, string $definition) use ($db, $columnExists): void {
        if (!$columnExists('staff_profiles', $column)) {
            $db->exec("ALTER TABLE staff_profiles ADD `$column` $definition");
        }
    };

    $addStatusColumn = static function (string $column, string $definition) use ($db, $columnExists): void {
        if (!$columnExists('staff_status_history', $column)) {
            $db->exec("ALTER TABLE staff_status_history ADD `$column` $definition");
        }
    };

    $addProfileColumn('current_work_status', "VARCHAR(20) NULL DEFAULT 'on_duty' COMMENT 'الحالة الحالية: on_duty/off_duty'");
    $addProfileColumn('current_status_reason', "VARCHAR(255) NULL COMMENT 'سبب الحالة الحالية'");
    $addProfileColumn('current_status_effective_date', "DATE NULL COMMENT 'تاريخ سريان الحالة الحالية'");
    $addProfileColumn('first_hire_date', "DATE NULL COMMENT 'أول تاريخ تعيين/بداية عمل'");
    $addProfileColumn('latest_hire_date', "DATE NULL COMMENT 'آخر تاريخ تعيين أو عودة للعمل'");
    $addProfileColumn('last_working_day', "DATE NULL COMMENT 'آخر يوم عمل عند الخروج'");
    $addProfileColumn('can_rehire', "TINYINT(1) NULL COMMENT 'إمكانية إعادة التعيين'");
    $addProfileColumn('last_job_movement_date', "DATE NULL COMMENT 'تاريخ آخر حركة وظيفية فعالة'");

    $db->exec("CREATE TABLE IF NOT EXISTS staff_status_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        movement_type VARCHAR(100) NOT NULL COMMENT 'نوع الحركة: تعيين/خروج/عودة/إنهاء خدمة...',
        status_after VARCHAR(20) NOT NULL DEFAULT 'on_duty' COMMENT 'الحالة بعد الحركة: on_duty/off_duty',
        status_label VARCHAR(100) NULL COMMENT 'وصف الحالة الظاهر',
        status_reason VARCHAR(255) NULL COMMENT 'سبب الحالة',
        effective_date DATE NULL COMMENT 'تاريخ سريان الحالة',
        decision_date DATE NULL COMMENT 'تاريخ القرار',
        decision_no VARCHAR(100) NULL COMMENT 'رقم القرار',
        issuer VARCHAR(255) NULL COMMENT 'جهة إصدار القرار',
        contract_type VARCHAR(100) NULL COMMENT 'نوع التعاقد وقت الحركة',
        contract_start DATE NULL COMMENT 'تاريخ بداية التعاقد',
        contract_end DATE NULL COMMENT 'تاريخ نهاية التعاقد',
        job_title VARCHAR(255) NULL COMMENT 'المسمى الوظيفي وقت الحركة',
        job_grade VARCHAR(100) NULL COMMENT 'الدرجة الوظيفية وقت الحركة',
        department VARCHAR(255) NULL COMMENT 'القوة/القسم وقت الحركة',
        last_working_day DATE NULL COMMENT 'آخر يوم عمل',
        can_rehire TINYINT(1) NULL COMMENT 'هل يمكن إعادة التعيين',
        notes TEXT NULL COMMENT 'ملاحظات',
        source VARCHAR(50) NOT NULL DEFAULT 'staff_form',
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_staff_status_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_staff_status_user_effective (user_id, effective_date),
        INDEX idx_staff_status_after (status_after),
        INDEX idx_staff_status_movement (movement_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل حالات الموظف الوظيفية'");

    $addStatusColumn('contract_type', "VARCHAR(100) NULL COMMENT 'نوع التعاقد وقت الحركة'");
    $addStatusColumn('contract_start', "DATE NULL COMMENT 'تاريخ بداية التعاقد'");
    $addStatusColumn('contract_end', "DATE NULL COMMENT 'تاريخ نهاية التعاقد'");
    $addStatusColumn('job_title', "VARCHAR(255) NULL COMMENT 'المسمى الوظيفي وقت الحركة'");
    $addStatusColumn('job_grade', "VARCHAR(100) NULL COMMENT 'الدرجة الوظيفية وقت الحركة'");
    $addStatusColumn('department', "VARCHAR(255) NULL COMMENT 'القوة/القسم وقت الحركة'");

    $db->exec("CREATE TABLE IF NOT EXISTS staff_job_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        movement_type VARCHAR(100) NOT NULL COMMENT 'نوع الحركة: ترقية/تسوية/ندب/نقل/تعديل مسمى...',
        previous_job_title VARCHAR(255) NULL,
        new_job_title VARCHAR(255) NULL,
        previous_job_grade VARCHAR(100) NULL,
        new_job_grade VARCHAR(100) NULL,
        previous_department VARCHAR(255) NULL,
        new_department VARCHAR(255) NULL,
        previous_contract_type VARCHAR(100) NULL,
        new_contract_type VARCHAR(100) NULL,
        decision_date DATE NULL COMMENT 'تاريخ القرار',
        effective_date DATE NULL COMMENT 'تاريخ السريان',
        decision_no VARCHAR(100) NULL COMMENT 'رقم القرار',
        issuer VARCHAR(255) NULL COMMENT 'جهة إصدار القرار',
        reason VARCHAR(255) NULL COMMENT 'سبب الحركة',
        notes TEXT NULL COMMENT 'ملاحظات',
        source VARCHAR(50) NOT NULL DEFAULT 'staff_form',
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_staff_job_movements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_staff_job_user_effective (user_id, effective_date),
        INDEX idx_staff_job_movement_type (movement_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل الترقيات والحركات الوظيفية'");

    $db->exec("INSERT INTO staff_status_history
        (user_id, movement_type, status_after, status_label, status_reason, effective_date, source)
        SELECT sp.user_id,
               'تعيين',
               'on_duty',
               'على رأس العمل',
               'تسجيل أولي من بيانات الموظف',
               COALESCE(sp.hire_date, sp.contract_start, DATE(sp.created_at)),
               'migration'
        FROM staff_profiles sp
        WHERE NOT EXISTS (
            SELECT 1 FROM staff_status_history ssh WHERE ssh.user_id = sp.user_id
        )");

    $db->exec("UPDATE staff_profiles sp
              SET current_work_status = COALESCE(current_work_status, 'on_duty'),
                  current_status_reason = COALESCE(current_status_reason, 'تسجيل أولي من بيانات الموظف'),
                  current_status_effective_date = COALESCE(current_status_effective_date, hire_date, contract_start, DATE(created_at)),
                  first_hire_date = COALESCE(first_hire_date, hire_date, contract_start, DATE(created_at)),
                  latest_hire_date = COALESCE(latest_hire_date, hire_date, contract_start, DATE(created_at))
              WHERE current_status_effective_date IS NULL
                 OR first_hire_date IS NULL
                 OR latest_hire_date IS NULL");
};
