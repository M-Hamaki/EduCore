<?php

/**
 * Migration: توافق محرك الدرجات مع الجداول التي أُنشئت بنسخ جزئية سابقة.
 *
 * مشغل migrations لا يعيد تشغيل ملف تم تسجيله في schema_migrations؛ لذلك تبقى
 * هذه الطبقة المستقلة ضرورية لإضافة الأعمدة التي يعتمد عليها الرصد والتقارير
 * في قواعد البيانات التي طبقت foundation قبل اكتمال الخطة.
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

    $addColumn = static function (string $table, string $column, string $definition) use ($db, $tableExists, $columnExists): void {
        if ($tableExists($table) && !$columnExists($table, $column)) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    };

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

    if ($tableExists('student_mark_audit')) {
        $db->exec("ALTER TABLE student_mark_audit MODIFY action ENUM('create','update','delete','review','lock','unlock','publish') NOT NULL");
    }

    echo "Assessment engine compatibility is ready.\n";
};
