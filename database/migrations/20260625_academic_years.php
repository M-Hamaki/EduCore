<?php

/**
 * Migration: نظام الأعوام الدراسية والتسجيلات السنوية
 *
 * يضيف طبقة "العام الدراسي" فوق النظام الحالي دون كسر البيانات الموجودة:
 *  - academic_years:        جدول الأعوام الدراسية
 *  - student_enrollments:   تسجيل الطالب في كل عام (المرحلة/الصف/الفصل/الحالة)
 *  - classes.academic_year_id: ربط الفصول بالعام
 *  - أعمدة academic_year_id في الجداول الحساسة (حضور/تقييمات/درجات/تعيينات حافلة/جدول)
 *  - ترحيل تلقائي للطلاب والفصول الحاليين إلى العام النشط
 *
 * متوافق مع idempotency: يعمل بأمان سواء كانت الجداول موجودة أم لا.
 */

return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
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
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };
    $addColumn = static function (string $table, string $column, string $definition) use ($db, $columnExists, $tableExists): void {
        if ($tableExists($table) && !$columnExists($table, $column)) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    };
    $dropIndex = static function (string $table, string $index) use ($db, $indexExists, $tableExists): void {
        if ($tableExists($table) && $indexExists($table, $index)) {
            $db->exec("ALTER TABLE `$table` DROP INDEX `$index`");
        }
    };
    $addUniqueIndex = static function (string $table, string $index, string $columns) use ($db, $indexExists, $tableExists): void {
        if ($tableExists($table) && !$indexExists($table, $index)) {
            $db->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$index` ($columns)");
        }
    };
    $addFk = static function (string $table, string $name, string $column, string $parent, string $onDelete = 'RESTRICT') use ($db, $fkExists, $tableExists): void {
        if ($tableExists($table) && $tableExists($parent) && !$fkExists($table, $name)) {
            $db->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$parent` (`id`) ON DELETE $onDelete");
        }
    };

    // ------------------------------------------------------------------
    // 1) جدول الأعوام الدراسية
    // ------------------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS academic_years (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(20) NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
        notes VARCHAR(500) NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_academic_years_name (name),
        KEY idx_academic_years_active (is_active, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // ضمان وجود عمود notes حتى لو كان الجدول قد أُنشئ سابقاً بدونها
    $addColumn('academic_years', 'notes', "VARCHAR(500) NULL DEFAULT NULL AFTER status");

    // ------------------------------------------------------------------
    // 2) جدول تسجيلات الطلاب السنوية
    // ------------------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS student_enrollments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        academic_year_id INT NOT NULL,
        stage_id INT NULL,
        grade_id INT NULL,
        class_id INT NULL,
        enrollment_status ENUM('enrolled','graduated','transferred','withdrawn') NOT NULL DEFAULT 'enrolled',
        enrollment_date DATE NULL,
        notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_student_year (student_id, academic_year_id),
        KEY idx_enroll_year_class (academic_year_id, class_id),
        KEY idx_enroll_year_grade (academic_year_id, grade_id),
        KEY idx_enroll_year_stage (academic_year_id, stage_id),
        KEY idx_enroll_status (academic_year_id, enrollment_status),
        CONSTRAINT fk_enroll_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_enroll_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
        CONSTRAINT fk_enroll_stage FOREIGN KEY (stage_id) REFERENCES stages(id) ON DELETE SET NULL,
        CONSTRAINT fk_enroll_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE SET NULL,
        CONSTRAINT fk_enroll_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ------------------------------------------------------------------
    // 3) ربط الفصول بالعام + أعمدة السنة في الجداول الحساسة
    // ------------------------------------------------------------------
    $addColumn('classes', 'academic_year_id', 'INT NULL DEFAULT NULL');
    $addColumn('attendance', 'academic_year_id', 'INT NULL DEFAULT NULL');
    $addColumn('evaluations', 'academic_year_id', 'INT NULL DEFAULT NULL');
    $addColumn('student_grades', 'academic_year_id', 'INT NULL DEFAULT NULL');
    $addColumn('student_bus_assignments', 'academic_year_id', 'INT NULL DEFAULT NULL');
    $addColumn('timetable_entries', 'academic_year_id', 'INT NULL DEFAULT NULL');

    // ------------------------------------------------------------------
    // 4) تعديل المفاتيح الفريدة لتشمل السنة (عزل بيانات كل عام)
    // ------------------------------------------------------------------
    // student_grades: الطالب + العمود + السنة (بدل الطالب + العمود فقط)
    $dropIndex('student_grades', 'uq_student_column');
    $addUniqueIndex('student_grades', 'uq_student_column_year', '`student_id`, `grade_column_id`, `academic_year_id`');
    // attendance: الفصل + التاريخ + الفترة + السنة
    $dropIndex('attendance', 'uq_attendance');
    $addUniqueIndex('attendance', 'uq_attendance_year', '`class_id`, `attendance_date`, `period_id`, `academic_year_id`');
    // timetable_entries: الفصل + اليوم + الفترة + السنة
    $dropIndex('timetable_entries', 'unique_class_day_period');
    $addUniqueIndex('timetable_entries', 'unique_class_day_period_year', '`class_id`, `day_of_week`, `period_id`, `academic_year_id`');
    // student_bus_assignments: الطالب + السنة
    $dropIndex('student_bus_assignments', 'uq_student_bus');
    $addUniqueIndex('student_bus_assignments', 'uq_student_bus_year', '`student_id`, `academic_year_id`');

    // ------------------------------------------------------------------
    // 5) ترحيل البيانات الحالية (مرة واحدة فقط)
    // ------------------------------------------------------------------
    // العام الافتراضي: من settings.academic_year، أو السنة الحالية
    $stmtY = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'academic_year' LIMIT 1");
    $stmtY->execute();
    $currentYear = trim((string) ($stmtY->fetchColumn() ?: ''));
    if ($currentYear === '') {
        $yr = (int) date('Y');
        $currentYear = $yr . '-' . ($yr + 1);
        $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES ('academic_year', ?, 'العام الدراسي الحالي') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$currentYear]);
    }
    // إدراج/تفعيل العام الحالي
    $db->prepare("INSERT INTO academic_years (name, is_active, status) VALUES (?, 1, 'active') ON DUPLICATE KEY UPDATE is_active = 1, status = 'active'")
        ->execute([$currentYear]);
    $yearIdStmt = $db->prepare("SELECT id FROM academic_years WHERE name = ? LIMIT 1");
    $yearIdStmt->execute([$currentYear]);
    $yearId = (int) $yearIdStmt->fetchColumn();

    // ترحيل الطلاب الحاليين إلى student_enrollments (فقط لمن ليس له تسجيل في هذا العام)
    $db->exec("INSERT IGNORE INTO student_enrollments (student_id, academic_year_id, stage_id, grade_id, class_id, enrollment_status, enrollment_date)
        SELECT u.id, {$yearId}, c.stage_id, c.grade_id, u.class_id,
               CASE WHEN u.status = 'graduated' THEN 'graduated' ELSE 'enrolled' END,
               CURDATE()
        FROM users u
        LEFT JOIN classes c ON c.id = u.class_id
        WHERE u.role = 'student'");

    // ربط الفصول الحالية بالعام النشط (فصول بدون عام)
    $db->exec("UPDATE classes SET academic_year_id = {$yearId} WHERE academic_year_id IS NULL");

    // ملء academic_year_id في السجلات القديمة (التي بدون سنة) بالعام الحالي
    foreach (['attendance', 'evaluations', 'student_grades', 'student_bus_assignments', 'timetable_entries'] as $tbl) {
        if ($tableExists($tbl) && $columnExists($tbl, 'academic_year_id')) {
            $db->exec("UPDATE `{$tbl}` SET academic_year_id = {$yearId} WHERE academic_year_id IS NULL");
        }
    }

    echo "Academic years system is ready (active year #{$yearId}: {$currentYear}).\n";
};
