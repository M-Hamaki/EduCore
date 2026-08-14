<?php

/**
 * Migration: أساس نظام الدرجات المرن والتقارير المنشورة
 *
 * يضيف طبقة جديدة لا تكسر نظام grade_columns/student_grades الحالي:
 *  - تقويم الترم والأسابيع الدراسية.
 *  - ربط المواد بالعام/الترم/المرحلة/الصف.
 *  - خطط درجات مرنة وبنودها ونوافذ الرصد.
 *  - رصد درجات جديد مع سجل تدقيق وصلاحيات حذف.
 *  - نوافذ تقارير منشورة Snapshot للطلاب.
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

    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
        $stmt->execute([$table, $index]);
        return (bool) $stmt->fetchColumn();
    };

    $indexColumns = static function (string $table, string $index) use ($db): array {
        $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
        $stmt->execute([$table, $index]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    };

    $addColumn = static function (string $table, string $column, string $definition) use ($db, $tableExists, $columnExists): void {
        if ($tableExists($table) && !$columnExists($table, $column)) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    };

    if (!$tableExists('academic_terms')) {
        $db->exec("CREATE TABLE academic_terms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academic_year_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            term_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
            start_date DATE NULL,
            end_date DATE NULL,
            status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_term_year_order (academic_year_id, term_order),
            KEY idx_term_year_status (academic_year_id, status),
            CONSTRAINT fk_terms_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('academic_weeks')) {
        $db->exec("CREATE TABLE academic_weeks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academic_year_id INT NOT NULL,
            term_id INT NOT NULL,
            month_label VARCHAR(50) NULL,
            name VARCHAR(100) NOT NULL,
            week_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            week_type ENUM('study','holiday','exam','revision') NOT NULL DEFAULT 'study',
            counts_for_average TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_week_term_order (term_id, week_order),
            KEY idx_week_year_term (academic_year_id, term_id),
            KEY idx_week_dates (start_date, end_date),
            CONSTRAINT fk_weeks_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_weeks_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('subject_grade_assignments')) {
        $db->exec("CREATE TABLE subject_grade_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academic_year_id INT NOT NULL,
            term_id INT NULL,
            subject_id INT NOT NULL,
            stage_id INT NULL,
            grade_id INT NOT NULL,
            class_id INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(500) NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_subject_grade_scope (academic_year_id, term_id, subject_id, grade_id, class_id),
            KEY idx_sga_subject (subject_id),
            KEY idx_sga_grade (grade_id),
            KEY idx_sga_stage (stage_id),
            KEY idx_sga_year_active (academic_year_id, is_active),
            CONSTRAINT fk_sga_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_sga_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
            CONSTRAINT fk_sga_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            CONSTRAINT fk_sga_stage FOREIGN KEY (stage_id) REFERENCES stages(id) ON DELETE SET NULL,
            CONSTRAINT fk_sga_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
            CONSTRAINT fk_sga_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            CONSTRAINT fk_sga_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('teacher_subject_assignments')) {
        $db->exec("CREATE TABLE teacher_subject_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academic_year_id INT NOT NULL,
            term_id INT NULL,
            teacher_id INT NOT NULL,
            subject_id INT NOT NULL,
            grade_id INT NULL,
            class_id INT NULL,
            is_substitute TINYINT(1) NOT NULL DEFAULT 0,
            starts_at DATE NULL,
            ends_at DATE NULL,
            can_record TINYINT(1) NOT NULL DEFAULT 1,
            can_review TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_teacher_subject_scope (academic_year_id, term_id, teacher_id, subject_id, grade_id, class_id),
            KEY idx_tsa_teacher_active (teacher_id, is_active),
            KEY idx_tsa_subject_scope (subject_id, grade_id, class_id),
            CONSTRAINT fk_tsa_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_tsa_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
            CONSTRAINT fk_tsa_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_tsa_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            CONSTRAINT fk_tsa_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE SET NULL,
            CONSTRAINT fk_tsa_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('assessment_schemes')) {
        $db->exec("CREATE TABLE assessment_schemes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academic_year_id INT NOT NULL,
            term_id INT NOT NULL,
            subject_assignment_id INT NULL,
            subject_id INT NOT NULL,
            stage_id INT NULL,
            grade_id INT NOT NULL,
            name VARCHAR(190) NOT NULL,
            total_grade DECIMAL(8,2) NOT NULL DEFAULT 100,
            pass_grade DECIMAL(8,2) NULL,
            counts_in_total TINYINT(1) NOT NULL DEFAULT 1,
            enable_excused_absence TINYINT(1) NOT NULL DEFAULT 0,
            normal_absence_policy ENUM('zero','exclude','note') NOT NULL DEFAULT 'zero',
            excused_absence_policy ENUM('zero','exclude','note') NOT NULL DEFAULT 'exclude',
            rounding_enabled TINYINT(1) NOT NULL DEFAULT 0,
            rounding_mode ENUM('none','nearest_half','integer','two_decimals') NOT NULL DEFAULT 'none',
            rounding_scope ENUM('total','components','both') NOT NULL DEFAULT 'total',
            annual_result_enabled TINYINT(1) NOT NULL DEFAULT 0,
            first_term_weight DECIMAL(5,2) NOT NULL DEFAULT 50,
            second_term_weight DECIMAL(5,2) NOT NULL DEFAULT 50,
            status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
            copied_from_scheme_id INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_scheme_scope (academic_year_id, term_id, subject_id, grade_id),
            KEY idx_scheme_status (status),
            CONSTRAINT fk_scheme_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_scheme_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
            CONSTRAINT fk_scheme_assignment FOREIGN KEY (subject_assignment_id) REFERENCES subject_grade_assignments(id) ON DELETE SET NULL,
            CONSTRAINT fk_scheme_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            CONSTRAINT fk_scheme_stage FOREIGN KEY (stage_id) REFERENCES stages(id) ON DELETE SET NULL,
            CONSTRAINT fk_scheme_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
            CONSTRAINT fk_scheme_copy FOREIGN KEY (copied_from_scheme_id) REFERENCES assessment_schemes(id) ON DELETE SET NULL,
            CONSTRAINT fk_scheme_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('assessment_components')) {
        $db->exec("CREATE TABLE assessment_components (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scheme_id INT NOT NULL,
            parent_component_id INT NULL,
            name VARCHAR(190) NOT NULL,
            component_type ENUM('monthly','weekly','final','practical','activity','behavior','custom') NOT NULL DEFAULT 'custom',
            max_grade DECIMAL(8,2) NOT NULL DEFAULT 0,
            is_weekly TINYINT(1) NOT NULL DEFAULT 0,
            repeat_per_week TINYINT(1) NOT NULL DEFAULT 0,
            counts_in_average TINYINT(1) NOT NULL DEFAULT 0,
            counts_in_total TINYINT(1) NOT NULL DEFAULT 1,
            visible_to_student TINYINT(1) NOT NULL DEFAULT 1,
            accepts_absence TINYINT(1) NOT NULL DEFAULT 1,
            accepts_excused_absence TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            calculation_mode ENUM('direct','average_weeks','sum_children','manual') NOT NULL DEFAULT 'direct',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_component_scheme (scheme_id, sort_order),
            KEY idx_component_parent (parent_component_id),
            CONSTRAINT fk_component_scheme FOREIGN KEY (scheme_id) REFERENCES assessment_schemes(id) ON DELETE CASCADE,
            CONSTRAINT fk_component_parent FOREIGN KEY (parent_component_id) REFERENCES assessment_components(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('assessment_component_week_rules')) {
        $db->exec("CREATE TABLE assessment_component_week_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            component_id INT NOT NULL,
            week_id INT NOT NULL,
            is_included TINYINT(1) NOT NULL DEFAULT 1,
            max_grade_override DECIMAL(8,2) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_component_week (component_id, week_id),
            KEY idx_acwr_week (week_id),
            CONSTRAINT fk_acwr_component FOREIGN KEY (component_id) REFERENCES assessment_components(id) ON DELETE CASCADE,
            CONSTRAINT fk_acwr_week FOREIGN KEY (week_id) REFERENCES academic_weeks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('assessment_windows')) {
        $db->exec("CREATE TABLE assessment_windows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scheme_id INT NOT NULL,
            component_id INT NOT NULL,
            week_id INT NULL,
            class_id INT NULL,
            grade_id INT NULL,
            teacher_id INT NULL,
            window_name VARCHAR(190) NOT NULL,
            opens_at DATETIME NULL,
            closes_at DATETIME NULL,
            status ENUM('draft','open','closed','locked') NOT NULL DEFAULT 'draft',
            allow_edit_after_save TINYINT(1) NOT NULL DEFAULT 1,
            requires_review TINYINT(1) NOT NULL DEFAULT 0,
            opened_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_window_status (status, opens_at, closes_at),
            KEY idx_window_scope (scheme_id, component_id, week_id, class_id),
            CONSTRAINT fk_window_scheme FOREIGN KEY (scheme_id) REFERENCES assessment_schemes(id) ON DELETE CASCADE,
            CONSTRAINT fk_window_component FOREIGN KEY (component_id) REFERENCES assessment_components(id) ON DELETE CASCADE,
            CONSTRAINT fk_window_week FOREIGN KEY (week_id) REFERENCES academic_weeks(id) ON DELETE SET NULL,
            CONSTRAINT fk_window_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            CONSTRAINT fk_window_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE SET NULL,
            CONSTRAINT fk_window_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_window_opener FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('student_marks')) {
        $db->exec("CREATE TABLE student_marks (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            scheme_id INT NOT NULL,
            component_id INT NOT NULL,
            week_id INT NULL,
            week_slot INT NOT NULL DEFAULT 0,
            academic_year_id INT NOT NULL,
            term_id INT NOT NULL,
            subject_id INT NOT NULL,
            grade_id INT NULL,
            class_id_at_entry INT NULL,
            value DECIMAL(8,2) NULL,
            mark_status ENUM('present','absent','excused_absent','exempt','empty') NOT NULL DEFAULT 'empty',
            note VARCHAR(500) NULL,
            recorded_by INT NULL,
            reviewed_by INT NULL,
            review_status ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required',
            reviewed_at DATETIME NULL,
            review_note VARCHAR(500) NULL,
            locked_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_student_mark_slot (student_id, component_id, week_slot, academic_year_id, term_id),
            KEY idx_marks_student_scheme (student_id, scheme_id),
            KEY idx_marks_class_component (class_id_at_entry, component_id),
            KEY idx_marks_status (mark_status),
            CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_marks_scheme FOREIGN KEY (scheme_id) REFERENCES assessment_schemes(id) ON DELETE CASCADE,
            CONSTRAINT fk_marks_component FOREIGN KEY (component_id) REFERENCES assessment_components(id) ON DELETE CASCADE,
            CONSTRAINT fk_marks_week FOREIGN KEY (week_id) REFERENCES academic_weeks(id) ON DELETE SET NULL,
            CONSTRAINT fk_marks_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_marks_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
            CONSTRAINT fk_marks_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            CONSTRAINT fk_marks_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE SET NULL,
            CONSTRAINT fk_marks_class FOREIGN KEY (class_id_at_entry) REFERENCES classes(id) ON DELETE SET NULL,
            CONSTRAINT fk_marks_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_marks_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('student_marks') && !$columnExists('student_marks', 'week_slot')) {
        $db->exec('ALTER TABLE student_marks ADD COLUMN week_slot INT NOT NULL DEFAULT 0 AFTER week_id');
        $db->exec('UPDATE student_marks SET week_slot = COALESCE(week_id, 0)');
    }

    if ($tableExists('student_marks') && !$columnExists('student_marks', 'review_status')) {
        $db->exec("ALTER TABLE student_marks ADD COLUMN review_status ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required' AFTER reviewed_by");
    }
    if ($tableExists('student_marks') && !$columnExists('student_marks', 'reviewed_at')) {
        $db->exec('ALTER TABLE student_marks ADD COLUMN reviewed_at DATETIME NULL AFTER review_status');
    }
    if ($tableExists('student_marks') && !$columnExists('student_marks', 'review_note')) {
        $db->exec('ALTER TABLE student_marks ADD COLUMN review_note VARCHAR(500) NULL AFTER reviewed_at');
    }

    if ($tableExists('student_marks') && $indexExists('student_marks', 'uq_student_mark_slot')) {
        $markSlotColumns = $indexColumns('student_marks', 'uq_student_mark_slot');
        if ($markSlotColumns !== ['student_id', 'component_id', 'week_slot', 'academic_year_id', 'term_id']) {
            $db->exec('ALTER TABLE student_marks DROP INDEX uq_student_mark_slot');
        }
    }

    if ($tableExists('student_marks') && !$indexExists('student_marks', 'uq_student_mark_slot')) {
        $db->exec('ALTER TABLE student_marks ADD UNIQUE KEY uq_student_mark_slot (student_id, component_id, week_slot, academic_year_id, term_id)');
    }

    if (!$tableExists('student_mark_audit')) {
        $db->exec("CREATE TABLE student_mark_audit (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            mark_id BIGINT NULL,
            student_id INT NOT NULL,
            action ENUM('create','update','delete','review','lock','unlock','publish') NOT NULL,
            old_value VARCHAR(50) NULL,
            new_value VARCHAR(50) NULL,
            old_status VARCHAR(30) NULL,
            new_status VARCHAR(30) NULL,
            reason VARCHAR(500) NULL,
            changed_by INT NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mark_audit_mark (mark_id),
            KEY idx_mark_audit_student (student_id),
            KEY idx_mark_audit_changed (changed_by, changed_at),
            CONSTRAINT fk_mark_audit_mark FOREIGN KEY (mark_id) REFERENCES student_marks(id) ON DELETE SET NULL,
            CONSTRAINT fk_mark_audit_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_mark_audit_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('student_mark_audit')) {
        $db->exec("ALTER TABLE student_mark_audit MODIFY action ENUM('create','update','delete','review','lock','unlock','publish') NOT NULL");
    }

    if (!$tableExists('assessment_permissions')) {
        $db->exec("CREATE TABLE assessment_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_name VARCHAR(50) NOT NULL,
            user_id INT NULL,
            permission_key ENUM('delete_mark','edit_locked_mark','publish_report','reopen_window','review_marks') NOT NULL,
            scope_type ENUM('global','subject','grade','class','scheme') NOT NULL DEFAULT 'global',
            scope_id INT NULL,
            is_allowed TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_assessment_permission (permission_key, scope_type, scope_id),
            KEY idx_assessment_permission_user (user_id),
            CONSTRAINT fk_assessment_permission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_assessment_permission_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('assessment_student_locks')) {
        $db->exec("CREATE TABLE assessment_student_locks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            lock_reason ENUM('graduated','transferred','manual') NOT NULL,
            locked_by INT NULL,
            locked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes VARCHAR(500) NULL,
            UNIQUE KEY uq_assessment_student_lock (student_id, academic_year_id),
            CONSTRAINT fk_asl_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_asl_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_asl_user FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('report_windows')) {
        $db->exec("CREATE TABLE report_windows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academic_year_id INT NOT NULL,
            term_id INT NULL,
            name VARCHAR(190) NOT NULL,
            report_type ENUM('monthly','period','annual','custom') NOT NULL DEFAULT 'monthly',
            date_from DATE NULL,
            date_to DATE NULL,
            include_details TINYINT(1) NOT NULL DEFAULT 1,
            include_absence TINYINT(1) NOT NULL DEFAULT 1,
            include_teacher_notes TINYINT(1) NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            freeze_on_publish TINYINT(1) NOT NULL DEFAULT 1,
            published_at DATETIME NULL,
            hidden_at DATETIME NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_report_window_year (academic_year_id, term_id, is_published),
            CONSTRAINT fk_report_window_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_report_window_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
            CONSTRAINT fk_report_window_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('report_window_items')) {
        $db->exec("CREATE TABLE report_window_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_window_id INT NOT NULL,
            scheme_id INT NULL,
            component_id INT NULL,
            week_id INT NULL,
            subject_id INT NULL,
            include_item TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_report_item_window (report_window_id, sort_order),
            CONSTRAINT fk_report_item_window FOREIGN KEY (report_window_id) REFERENCES report_windows(id) ON DELETE CASCADE,
            CONSTRAINT fk_report_item_scheme FOREIGN KEY (scheme_id) REFERENCES assessment_schemes(id) ON DELETE CASCADE,
            CONSTRAINT fk_report_item_component FOREIGN KEY (component_id) REFERENCES assessment_components(id) ON DELETE CASCADE,
            CONSTRAINT fk_report_item_week FOREIGN KEY (week_id) REFERENCES academic_weeks(id) ON DELETE CASCADE,
            CONSTRAINT fk_report_item_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('published_reports')) {
        $db->exec("CREATE TABLE published_reports (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            report_window_id INT NOT NULL,
            student_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            term_id INT NULL,
            snapshot_json LONGTEXT NOT NULL,
            total_grade DECIMAL(8,2) NULL,
            percentage DECIMAL(6,2) NULL,
            published_by INT NULL,
            published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_published_report_student (report_window_id, student_id),
            KEY idx_published_student (student_id, academic_year_id),
            CONSTRAINT fk_pub_report_window FOREIGN KEY (report_window_id) REFERENCES report_windows(id) ON DELETE CASCADE,
            CONSTRAINT fk_pub_report_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_pub_report_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_pub_report_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
            CONSTRAINT fk_pub_report_user FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('published_report_details')) {
        $db->exec("CREATE TABLE published_report_details (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            published_report_id BIGINT NOT NULL,
            subject_id INT NULL,
            scheme_id INT NULL,
            component_id INT NULL,
            week_id INT NULL,
            class_id_at_entry INT NULL,
            label VARCHAR(190) NOT NULL,
            value_label VARCHAR(50) NULL,
            status VARCHAR(40) NULL,
            numeric_value DECIMAL(8,2) NULL,
            max_grade DECIMAL(8,2) NULL,
            class_name_at_entry VARCHAR(190) NULL,
            note TEXT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_pub_detail_report (published_report_id, sort_order),
            KEY idx_pub_detail_class (class_id_at_entry),
            CONSTRAINT fk_pub_detail_report FOREIGN KEY (published_report_id) REFERENCES published_reports(id) ON DELETE CASCADE,
            CONSTRAINT fk_pub_detail_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
            CONSTRAINT fk_pub_detail_scheme FOREIGN KEY (scheme_id) REFERENCES assessment_schemes(id) ON DELETE SET NULL,
            CONSTRAINT fk_pub_detail_component FOREIGN KEY (component_id) REFERENCES assessment_components(id) ON DELETE SET NULL,
            CONSTRAINT fk_pub_detail_week FOREIGN KEY (week_id) REFERENCES academic_weeks(id) ON DELETE SET NULL,
            CONSTRAINT fk_pub_detail_class FOREIGN KEY (class_id_at_entry) REFERENCES classes(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('published_report_details') && !$columnExists('published_report_details', 'status')) {
        $db->exec('ALTER TABLE published_report_details ADD COLUMN status VARCHAR(40) NULL AFTER value_label');
    }

    $addColumn('teacher_subject_assignments', 'is_substitute', 'is_substitute TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('teacher_subject_assignments', 'starts_at', 'starts_at DATE NULL');
    $addColumn('teacher_subject_assignments', 'ends_at', 'ends_at DATE NULL');
    $addColumn('teacher_subject_assignments', 'can_record', 'can_record TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('teacher_subject_assignments', 'can_review', 'can_review TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('teacher_subject_assignments', 'is_active', 'is_active TINYINT(1) NOT NULL DEFAULT 1');

    $addColumn('assessment_schemes', 'counts_in_total', 'counts_in_total TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('assessment_schemes', 'enable_excused_absence', 'enable_excused_absence TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('assessment_schemes', 'normal_absence_policy', "normal_absence_policy ENUM('zero','exclude','note') NOT NULL DEFAULT 'zero'");
    $addColumn('assessment_schemes', 'excused_absence_policy', "excused_absence_policy ENUM('zero','exclude','note') NOT NULL DEFAULT 'exclude'");
    $addColumn('assessment_schemes', 'rounding_enabled', 'rounding_enabled TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('assessment_schemes', 'rounding_mode', "rounding_mode ENUM('none','nearest_half','integer','two_decimals') NOT NULL DEFAULT 'none'");
    $addColumn('assessment_schemes', 'rounding_scope', "rounding_scope ENUM('total','components','both') NOT NULL DEFAULT 'total'");
    $addColumn('assessment_schemes', 'annual_result_enabled', 'annual_result_enabled TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('assessment_schemes', 'first_term_weight', 'first_term_weight DECIMAL(5,2) NOT NULL DEFAULT 50');
    $addColumn('assessment_schemes', 'second_term_weight', 'second_term_weight DECIMAL(5,2) NOT NULL DEFAULT 50');
    $addColumn('assessment_schemes', 'copied_from_scheme_id', 'copied_from_scheme_id INT NULL');

    $addColumn('assessment_components', 'parent_component_id', 'parent_component_id INT NULL');
    $addColumn('assessment_components', 'repeat_per_week', 'repeat_per_week TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('assessment_components', 'counts_in_average', 'counts_in_average TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('assessment_components', 'counts_in_total', 'counts_in_total TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('assessment_components', 'visible_to_student', 'visible_to_student TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('assessment_components', 'accepts_absence', 'accepts_absence TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('assessment_components', 'accepts_excused_absence', 'accepts_excused_absence TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('assessment_components', 'calculation_mode', "calculation_mode ENUM('direct','average_weeks','sum_children','manual') NOT NULL DEFAULT 'direct'");

    $addColumn('assessment_component_week_rules', 'is_included', 'is_included TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('assessment_component_week_rules', 'max_grade_override', 'max_grade_override DECIMAL(8,2) NULL');

    $addColumn('assessment_windows', 'grade_id', 'grade_id INT NULL');
    $addColumn('assessment_windows', 'teacher_id', 'teacher_id INT NULL');
    $addColumn('assessment_windows', 'allow_edit_after_save', 'allow_edit_after_save TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('assessment_windows', 'requires_review', 'requires_review TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('assessment_windows', 'opened_by', 'opened_by INT NULL');

    $addColumn('student_marks', 'week_slot', 'week_slot INT NOT NULL DEFAULT 0');
    $addColumn('student_marks', 'grade_id', 'grade_id INT NULL');
    $addColumn('student_marks', 'class_id_at_entry', 'class_id_at_entry INT NULL');
    $addColumn('student_marks', 'reviewed_by', 'reviewed_by INT NULL');
    $addColumn('student_marks', 'review_status', "review_status ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required'");
    $addColumn('student_marks', 'reviewed_at', 'reviewed_at DATETIME NULL');
    $addColumn('student_marks', 'review_note', 'review_note VARCHAR(500) NULL');
    $addColumn('student_marks', 'locked_at', 'locked_at DATETIME NULL');

    $addColumn('report_windows', 'date_from', 'date_from DATE NULL');
    $addColumn('report_windows', 'date_to', 'date_to DATE NULL');
    $addColumn('report_windows', 'include_details', 'include_details TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('report_windows', 'include_absence', 'include_absence TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('report_windows', 'include_teacher_notes', 'include_teacher_notes TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('report_windows', 'is_published', 'is_published TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('report_windows', 'freeze_on_publish', 'freeze_on_publish TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('report_windows', 'published_at', 'published_at DATETIME NULL');
    $addColumn('report_windows', 'hidden_at', 'hidden_at DATETIME NULL');

    $addColumn('published_reports', 'snapshot_json', 'snapshot_json LONGTEXT NULL');
    $addColumn('published_reports', 'total_grade', 'total_grade DECIMAL(8,2) NULL');
    $addColumn('published_reports', 'percentage', 'percentage DECIMAL(6,2) NULL');
    $addColumn('published_reports', 'published_by', 'published_by INT NULL');
    $addColumn('published_reports', 'published_at', 'published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');

    $addColumn('published_report_details', 'class_id_at_entry', 'class_id_at_entry INT NULL');
    $addColumn('published_report_details', 'status', 'status VARCHAR(40) NULL');
    $addColumn('published_report_details', 'class_name_at_entry', 'class_name_at_entry VARCHAR(190) NULL');
    $addColumn('published_report_details', 'note', 'note TEXT NULL');

    if ($tableExists('assessment_permissions')) {
        $db->exec("ALTER TABLE assessment_permissions MODIFY permission_key ENUM('delete_mark','edit_locked_mark','publish_report','reopen_window','review_marks') NOT NULL");
    }

    echo "Assessment engine foundation is ready.\n";
};
