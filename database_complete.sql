-- ====================================================
-- ملف قاعدة البيانات الشامل لنظام EduCore
-- تاريخ التحديث: 2025-02-25
-- يتضمن جميع الجداول (32 جدول) والفهارس والبيانات الافتراضية
-- الترتيب يراعي العلاقات بين الجداول (Foreign Keys)
-- ====================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ====================================================
-- 1. جدول المراحل الدراسية (Stages)
-- ====================================================
CREATE TABLE IF NOT EXISTS `stages` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `stage_name` varchar(100) NOT NULL COMMENT 'اسم المرحلة بالعربية',
    `stage_name_en` varchar(100) DEFAULT NULL COMMENT 'اسم المرحلة بالإنجليزية',
    `stage_code` varchar(50) NOT NULL COMMENT 'كود المرحلة مثل primary, preparatory, secondary',
    `stage_order` int(11) NOT NULL DEFAULT 1 COMMENT 'ترتيب المرحلة',
    `services` text DEFAULT NULL COMMENT 'الخدمات المتاحة بصيغة JSON',
    `teacher_services` text DEFAULT NULL COMMENT 'خدمات المعلمين المتاحة بصيغة JSON',
    `new_badges` text DEFAULT NULL COMMENT 'شارات جديد للطلاب بصيغة JSON',
    `teacher_new_badges` text DEFAULT NULL COMMENT 'شارات جديد للمعلمين بصيغة JSON',
    `status` enum('active','inactive') NOT NULL DEFAULT 'active' COMMENT 'حالة المرحلة',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `stage_code` (`stage_code`),
    KEY `idx_stage_code` (`stage_code`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المراحل الدراسية';

-- ====================================================
-- 2. جدول الصفوف الدراسية (Grades)
-- ====================================================
CREATE TABLE IF NOT EXISTS `grades` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `grade_name` varchar(100) NOT NULL,
    `grade_code` varchar(20) NOT NULL,
    `stage` varchar(20) DEFAULT 'primary',
    `grade_order` int(11) NOT NULL DEFAULT 1,
    `stage_id` int(11) DEFAULT NULL COMMENT 'ربط بالمرحلة الدراسية',
    `reports_db_prefix` varchar(20) DEFAULT NULL COMMENT 'بادئة قاعدة بيانات التقارير مثل prim1, prep2',
    `description` text DEFAULT NULL,
    `status` enum('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `grade_code` (`grade_code`),
    KEY `grade_order` (`grade_order`),
    KEY `idx_stage_id` (`stage_id`),
    CONSTRAINT `fk_grades_stage` FOREIGN KEY (`stage_id`) REFERENCES `stages` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 3. جدول الفصول الدراسية (Classes)
-- ====================================================
CREATE TABLE IF NOT EXISTS `classes` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `grade_id` int(11) DEFAULT NULL COMMENT 'ربط بالصف الدراسي',
    `name` varchar(100) NOT NULL,
    `room_location` varchar(100) DEFAULT NULL COMMENT 'مقر الفصل / الغرفة',
    `display_order` int(11) DEFAULT 0,
    `status` enum('active','inactive') NOT NULL DEFAULT 'active',
    `section_name` varchar(100) DEFAULT NULL,
    `academic_year` varchar(9) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_grade_id` (`grade_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 4. جدول المستخدمين (Users)
-- ====================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `username` varchar(50) NOT NULL,
    `email` varchar(255) DEFAULT NULL,
    `azure_id` varchar(255) DEFAULT NULL,
    `password` varchar(255) NOT NULL,
    `password_hash` varchar(255) DEFAULT NULL,
    `password_key_version` smallint unsigned NOT NULL DEFAULT 2,
    `role` enum('admin','teacher','supervisor','specialist','student') NOT NULL,
    `status` enum('active','inactive') NOT NULL DEFAULT 'active',
    `login_disabled_reason` varchar(500) DEFAULT NULL,
    `login_disabled_at` datetime DEFAULT NULL,
    `login_disabled_by` int(11) DEFAULT NULL,
    `class_id` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`),
    UNIQUE KEY `azure_id` (`azure_id`),
    KEY `idx_users_role` (`role`),
    KEY `idx_users_status` (`status`),
    KEY `idx_users_login_disabled_by` (`login_disabled_by`),
    KEY `idx_users_class_id` (`class_id`),
    KEY `idx_users_username` (`username`),
    KEY `idx_users_role_class` (`role`, `class_id`),
    KEY `idx_users_role_status` (`role`, `status`),
    KEY `idx_email` (`email`),
    KEY `idx_azure_id` (`azure_id`),
    CONSTRAINT `fk_users_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 5. جدول المواد الدراسية (Subjects)
-- ====================================================
CREATE TABLE IF NOT EXISTS `subjects` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `code` varchar(50) DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `default_order` tinyint(4) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subjects_name` (`name`),
    UNIQUE KEY `uq_subjects_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 6. جدول أنواع التقييم (Evaluation Types)
-- ====================================================
CREATE TABLE IF NOT EXISTS `evaluation_types` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `type` enum('positive','negative') NOT NULL,
    `points` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_evaluation_types_type` (`type`),
    KEY `idx_evaluation_types_points` (`points`),
    KEY `idx_evaluation_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 7. جدول التقييمات (Evaluations)
-- ====================================================
CREATE TABLE IF NOT EXISTS `evaluations` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `student_id` int(11) NOT NULL,
    `teacher_id` int(11) NOT NULL,
    `evaluation_type_id` int(11) NOT NULL,
    `class_id` int(11) NOT NULL,
    `date_created` datetime NOT NULL,
    `custom_points` int(11) DEFAULT NULL,
    `reason` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_evaluations_student_id` (`student_id`),
    KEY `idx_evaluations_teacher_id` (`teacher_id`),
    KEY `idx_evaluations_class_id` (`class_id`),
    KEY `idx_evaluations_date` (`date_created`),
    KEY `idx_evaluations_type_id` (`evaluation_type_id`),
    KEY `idx_evaluations_student_date` (`student_id`, `date_created`),
    KEY `idx_evaluations_class_date` (`class_id`, `date_created`),
    KEY `idx_evaluations_teacher_date` (`teacher_id`, `date_created`),
    KEY `idx_evaluations_teacher_class_date` (`teacher_id`, `class_id`, `date_created`),
    CONSTRAINT `fk_eval_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_eval_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_eval_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_eval_type` FOREIGN KEY (`evaluation_type_id`) REFERENCES `evaluation_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 8. جدول الإعدادات (Settings)
-- ====================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `setting_key` varchar(100) NOT NULL,
    `setting_value` text NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 9. جدول تعيين الفصول للمستخدمين (User Class Access)
-- ====================================================
CREATE TABLE IF NOT EXISTS `user_class_access` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `class_id` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_class` (`user_id`, `class_id`),
    KEY `idx_user_class_access_user` (`user_id`),
    KEY `idx_user_class_access_class` (`class_id`),
    CONSTRAINT `fk_uca_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_uca_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 10. جدول تعيين الفصول للمعلمين (Teacher Classes)
-- ====================================================
CREATE TABLE IF NOT EXISTS `teacher_classes` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `teacher_id` int(11) NOT NULL,
    `class_id` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_teacher_class` (`teacher_id`, `class_id`),
    KEY `idx_teacher_classes_teacher` (`teacher_id`),
    KEY `idx_teacher_classes_class` (`class_id`),
    CONSTRAINT `fk_teacher_classes_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_teacher_classes_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 11. جدول تعيين المواد للمعلمين (Teacher Subjects)
-- ====================================================
CREATE TABLE IF NOT EXISTS `teacher_subjects` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `teacher_id` int(11) NOT NULL,
    `subject_id` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_teacher_subject` (`teacher_id`, `subject_id`),
    KEY `fk_ts_subject` (`subject_id`),
    CONSTRAINT `fk_ts_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ts_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 12. جدول تعيين الفصول للاختصاصيين (Specialist Classes)
-- ====================================================
CREATE TABLE IF NOT EXISTS `specialist_classes` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `specialist_id` int(11) NOT NULL,
    `class_id` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_assignment` (`specialist_id`, `class_id`),
    KEY `idx_specialist_classes_specialist` (`specialist_id`),
    KEY `idx_specialist_classes_class` (`class_id`),
    CONSTRAINT `fk_specialist_classes_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_specialist_classes_specialist` FOREIGN KEY (`specialist_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `academic_years` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(20) NOT NULL,
    `start_date` date DEFAULT NULL,
    `end_date` date DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 0,
    `status` enum('active','inactive','archived') NOT NULL DEFAULT 'active',
    `notes` varchar(500) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_academic_years_name` (`name`),
    KEY `idx_academic_years_active` (`is_active`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `specialist_grade_assignments` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `specialist_id` int(11) NOT NULL,
    `academic_year_id` int(11) NOT NULL,
    `grade_id` int(11) NOT NULL,
    `assigned_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_specialist_grade_year` (`specialist_id`,`academic_year_id`,`grade_id`),
    KEY `idx_specialist_grade_year` (`academic_year_id`,`grade_id`),
    CONSTRAINT `fk_specialist_grade_user` FOREIGN KEY (`specialist_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_specialist_grade_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_specialist_grade_grade` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_specialist_grade_actor` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `specialist_class_assignments` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `specialist_id` int(11) NOT NULL,
    `academic_year_id` int(11) NOT NULL,
    `class_id` int(11) NOT NULL,
    `assigned_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_specialist_class_year` (`specialist_id`,`academic_year_id`,`class_id`),
    KEY `idx_specialist_class_year` (`academic_year_id`,`class_id`),
    CONSTRAINT `fk_specialist_class_user` FOREIGN KEY (`specialist_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_specialist_class_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_specialist_class_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_specialist_class_actor` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW `specialist_active_classes` AS
SELECT sca.specialist_id, sca.class_id
FROM specialist_class_assignments sca
JOIN academic_years ay ON ay.id = sca.academic_year_id AND ay.is_active = 1 AND ay.status = 'active'
UNION
SELECT sga.specialist_id, c.id AS class_id
FROM specialist_grade_assignments sga
JOIN academic_years ay ON ay.id = sga.academic_year_id AND ay.is_active = 1 AND ay.status = 'active'
JOIN classes c ON c.grade_id = sga.grade_id AND c.status = 'active';

CREATE TABLE IF NOT EXISTS `student_change_requests` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `student_id` int(11) NOT NULL,
    `specialist_id` int(11) NOT NULL,
    `academic_year_id` int(11) NOT NULL,
    `before_payload` longtext NOT NULL,
    `proposed_payload` longtext NOT NULL,
    `status` enum('pending','approved','rejected','conflict','cancelled') NOT NULL DEFAULT 'pending',
    `reviewed_by` int(11) DEFAULT NULL,
    `reviewed_at` datetime DEFAULT NULL,
    `rejection_reason` varchar(500) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_student_change_status` (`status`,`created_at`),
    KEY `idx_student_change_specialist` (`specialist_id`,`status`),
    KEY `idx_student_change_student` (`student_id`,`status`),
    CONSTRAINT `fk_student_change_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_student_change_specialist` FOREIGN KEY (`specialist_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_student_change_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_student_change_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 13. جدول الحضور والغياب (Attendance)
-- ====================================================
CREATE TABLE IF NOT EXISTS `attendance` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `student_id` int(11) NOT NULL,
    `class_id` int(11) NOT NULL,
    `attendance_date` date NOT NULL,
    `status` enum('present','absent','late','excused') NOT NULL DEFAULT 'present',
    `notes` text DEFAULT NULL,
    `recorded_by` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_student_date` (`student_id`, `attendance_date`),
    KEY `idx_class_date` (`class_id`, `attendance_date`),
    KEY `idx_student` (`student_id`),
    KEY `idx_date` (`attendance_date`),
    KEY `idx_recorded_by` (`recorded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 14. جدول سجل النشاطات (Activity Logs)
-- ====================================================
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL COMMENT 'المستخدم الذي قام بالنشاط',
    `user_name` varchar(255) NOT NULL COMMENT 'اسم المستخدم',
    `user_role` varchar(50) NOT NULL COMMENT 'دور المستخدم',
    `action` varchar(100) NOT NULL COMMENT 'نوع الإجراء',
    `target_type` varchar(100) DEFAULT NULL COMMENT 'نوع الهدف',
    `target_id` int(11) DEFAULT NULL COMMENT 'معرف الهدف',
    `target_name` varchar(255) DEFAULT NULL COMMENT 'اسم الهدف',
    `details` text DEFAULT NULL COMMENT 'تفاصيل إضافية بصيغة JSON',
    `ip_address` varchar(45) DEFAULT NULL COMMENT 'عنوان IP',
    `academic_year_id` int(11) DEFAULT NULL COMMENT 'العام الدراسي المختار وقت تنفيذ العملية',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_action` (`action`),
    KEY `idx_target_type` (`target_type`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_activity_academic_year` (`academic_year_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل نشاطات المستخدمين';

-- ====================================================
-- 15. جدول سجل العمليات (Action Logs)
-- ====================================================
CREATE TABLE IF NOT EXISTS `action_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) DEFAULT NULL,
    `action_type` varchar(50) NOT NULL,
    `description` text DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_action_type` (`action_type`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 16. جدول سجل العمليات الإدارية (Admin Logs)
-- ====================================================
CREATE TABLE IF NOT EXISTS `admin_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `admin_id` int(11) NOT NULL,
    `action` varchar(100) NOT NULL,
    `details` text DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_admin_id` (`admin_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 17. جدول أعمدة الدرجات (Grade Columns)
-- ====================================================
CREATE TABLE IF NOT EXISTS `grade_columns` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `subject_id` int(11) NOT NULL,
    `grade_id` int(11) DEFAULT NULL,
    `name` varchar(255) NOT NULL,
    `max_grade` decimal(10,2) DEFAULT NULL,
    `sort_order` int(11) DEFAULT 0,
    `is_active` tinyint(1) DEFAULT 1,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_subject_grade_name` (`subject_id`, `grade_id`, `name`),
    KEY `idx_subject` (`subject_id`),
    KEY `idx_active` (`is_active`),
    KEY `idx_grade` (`grade_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 18. جدول درجات الطلاب (Student Grades)
-- ====================================================
CREATE TABLE IF NOT EXISTS `student_grades` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `student_id` int(11) NOT NULL,
    `grade_column_id` int(11) NOT NULL,
    `value` varchar(20) DEFAULT NULL,
    `updated_by` int(11) DEFAULT NULL,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_student_column` (`student_id`, `grade_column_id`),
    KEY `idx_column` (`grade_column_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 19. جدول سجل تعديل الدرجات (Grade Audit Log)
-- ====================================================
CREATE TABLE IF NOT EXISTS `grade_audit_log` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `student_id` int(11) NOT NULL,
    `grade_column_id` int(11) NOT NULL,
    `old_value` varchar(20) DEFAULT NULL,
    `new_value` varchar(20) DEFAULT NULL,
    `changed_by` int(11) NOT NULL,
    `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address` varchar(45) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_student` (`student_id`),
    KEY `idx_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 20. جدول إعدادات Google Sheets
-- ====================================================
CREATE TABLE IF NOT EXISTS `google_sheets_config` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `subject_id` int(11) NOT NULL,
    `spreadsheet_id` varchar(255) NOT NULL,
    `sheet_name` varchar(255) DEFAULT NULL,
    `sync_enabled` tinyint(1) DEFAULT 0,
    `last_sync_at` timestamp NULL DEFAULT NULL,
    `sync_status` enum('idle','syncing','success','error') DEFAULT 'idle',
    `sync_error` text DEFAULT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `subject_id` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 21. جدول الإشعارات (Notifications)
-- ====================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `title` varchar(255) NOT NULL COMMENT 'عنوان الإشعار',
    `message` text NOT NULL COMMENT 'نص الإشعار',
    `type` enum('student','teacher','specialist','public') NOT NULL DEFAULT 'student',
    `priority` enum('normal','important','urgent') NOT NULL DEFAULT 'normal' COMMENT 'أهمية الإشعار',
    `start_date` date DEFAULT NULL COMMENT 'تاريخ بداية العرض',
    `end_date` date DEFAULT NULL COMMENT 'تاريخ نهاية العرض',
    `start_time` time DEFAULT NULL COMMENT 'وقت بداية العرض',
    `end_time` time DEFAULT NULL COMMENT 'وقت نهاية العرض',
    `show_days` varchar(100) DEFAULT NULL COMMENT 'أيام العرض JSON',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `send_push` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'إرسال إشعار فوري',
    `created_by` int(11) NOT NULL COMMENT 'المستخدم الذي أنشأ الإشعار',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type` (`type`),
    KEY `idx_active` (`is_active`),
    KEY `idx_dates` (`start_date`, `end_date`),
    KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 22. جدول أهداف الإشعارات (Notification Targets)
-- ====================================================
CREATE TABLE IF NOT EXISTS `notification_targets` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `notification_id` int(11) NOT NULL,
    `target_type` enum('student','class','grade','stage','teacher','specialist','subject') NOT NULL,
    `target_id` int(11) NOT NULL COMMENT 'معرف الهدف',
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_target` (`notification_id`, `target_type`, `target_id`),
    KEY `idx_notification` (`notification_id`),
    KEY `idx_target` (`target_type`, `target_id`),
    CONSTRAINT `notification_targets_ibfk_1` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 23. جدول قراءة الإشعارات (Notification Reads)
-- ====================================================
CREATE TABLE IF NOT EXISTS `notification_reads` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `notification_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_read` (`notification_id`, `user_id`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `notification_reads_ibfk_1` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 24. جدول إشعارات المناسبات (Occasion Notifications)
-- ====================================================
CREATE TABLE IF NOT EXISTS `occasion_notifications` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `occasion_key` varchar(50) NOT NULL,
    `title` varchar(255) NOT NULL,
    `message` text NOT NULL,
    `icon` varchar(50) DEFAULT 'fas fa-star',
    `emoji` varchar(20) DEFAULT '',
    `theme` varchar(30) NOT NULL DEFAULT 'default',
    `gradient_start` varchar(10) DEFAULT '#667eea',
    `gradient_end` varchar(10) DEFAULT '#764ba2',
    `text_color` varchar(10) DEFAULT '#ffffff',
    `animation_type` varchar(30) DEFAULT 'fadeIn',
    `show_confetti` tinyint(1) DEFAULT 0,
    `target_type` enum('all','student','teacher','both') DEFAULT 'all',
    `start_date` varchar(5) DEFAULT NULL COMMENT 'صيغة MM-DD للمناسبات السنوية',
    `end_date` varchar(5) DEFAULT NULL COMMENT 'صيغة MM-DD للمناسبات السنوية',
    `is_active` tinyint(1) DEFAULT 1,
    `sort_order` int(11) DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `occasion_key` (`occasion_key`),
    KEY `idx_active` (`is_active`),
    KEY `idx_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 24.5 جدول اشتراكات الإشعارات الفورية (Push Subscriptions)
-- ====================================================
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `endpoint` text NOT NULL,
    `p256dh_key` varchar(255) NOT NULL,
    `auth_key` varchar(255) NOT NULL,
    `user_agent` varchar(500) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 25. جدول الدروس بالذكاء الاصطناعي (AI Lessons)
-- ====================================================
CREATE TABLE IF NOT EXISTS `ai_lessons` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `teacher_id` int(11) NOT NULL,
    `title` varchar(255) NOT NULL,
    `subject` varchar(100) DEFAULT NULL,
    `grade_level` varchar(50) DEFAULT NULL,
    `language` varchar(5) NOT NULL DEFAULT 'ar',
    `original_content` longtext NOT NULL COMMENT 'المحتوى الأصلي للدرس',
    `duration_minutes` int(11) NOT NULL DEFAULT 45,
    `generated_prep` longtext DEFAULT NULL COMMENT 'خطة التحضير المولدة JSON',
    `visual_materials` longtext DEFAULT NULL COMMENT 'المواد البصرية JSON',
    `class_activities` longtext DEFAULT NULL COMMENT 'الأنشطة الصفية JSON',
    `educational_stories` longtext DEFAULT NULL COMMENT 'القصص التعليمية JSON',
    `mind_maps` longtext DEFAULT NULL COMMENT 'الخرائط الذهنية JSON',
    `lesson_summary` longtext DEFAULT NULL COMMENT 'ملخص الدرس JSON',
    `custom_content` longtext DEFAULT NULL COMMENT 'المحتوى المخصص JSON',
    `question_bank` longtext DEFAULT NULL COMMENT 'بنك الأسئلة JSON',
    `exam_html` longtext DEFAULT NULL COMMENT 'كود HTML للامتحان',
    `exam_duration` int(11) DEFAULT 20 COMMENT 'مدة الامتحان بالدقائق',
    `exam_models_count` int(11) DEFAULT 3 COMMENT 'عدد نماذج الامتحان',
    `exam_mc_count` int(11) DEFAULT NULL COMMENT 'عدد أسئلة اختيار من متعدد في الامتحان',
    `exam_tf_count` int(11) DEFAULT NULL COMMENT 'عدد أسئلة صح/خطأ في الامتحان',
    `exam_essay_count` int(11) DEFAULT 0 COMMENT 'عدد الأسئلة المقالية في الامتحان',
    `status` varchar(20) NOT NULL DEFAULT 'draft',
    `error_message` text DEFAULT NULL,
    `public_share_token` char(64) DEFAULT NULL,
    `public_share_enabled_at` datetime DEFAULT NULL,
    `public_share_revoked_at` datetime DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ai_lessons_public_share_token` (`public_share_token`),
    KEY `idx_teacher_id` (`teacher_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 26. جدول الاختبارات القصيرة (AI Quizzes)
-- ====================================================
CREATE TABLE IF NOT EXISTS `ai_quizzes` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `lesson_id` int(11) NOT NULL,
    `quiz_title` varchar(255) NOT NULL,
    `questions_json` longtext NOT NULL COMMENT 'الأسئلة بصيغة JSON',
    `total_questions` int(11) NOT NULL DEFAULT 20,
    `duration_minutes` int(11) NOT NULL DEFAULT 20,
    `passing_percentage` int(11) NOT NULL DEFAULT 50,
    `model_a` longtext DEFAULT NULL COMMENT 'نموذج A',
    `model_b` longtext DEFAULT NULL COMMENT 'نموذج B',
    `model_c` longtext DEFAULT NULL COMMENT 'نموذج C',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lesson_id` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 27. جدول الامتحانات الإلكترونية (AI Online Exams)
-- ====================================================
CREATE TABLE IF NOT EXISTS `ai_online_exams` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `lesson_id` int(11) NOT NULL,
    `teacher_id` int(11) NOT NULL,
    `exam_code` varchar(20) NOT NULL,
    `title` varchar(255) NOT NULL,
    `duration_minutes` int(11) NOT NULL DEFAULT 20,
    `models_count` int(11) NOT NULL DEFAULT 3,
    `passing_percentage` int(11) NOT NULL DEFAULT 50,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `allow_review` tinyint(1) NOT NULL DEFAULT 1,
    `shuffle_questions` tinyint(1) NOT NULL DEFAULT 1,
    `show_results_immediately` tinyint(1) NOT NULL DEFAULT 1,
    `max_attempts` int(11) NOT NULL DEFAULT 1,
    `start_date` datetime DEFAULT NULL,
    `end_date` datetime DEFAULT NULL,
    `questions_data` longtext NOT NULL,
    `exam_theme` varchar(20) NOT NULL DEFAULT 'classic',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_exam_code` (`exam_code`),
    KEY `idx_lesson_id` (`lesson_id`),
    KEY `idx_teacher_id` (`teacher_id`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 28. جدول نتائج الامتحانات (AI Exam Results)
-- ====================================================
CREATE TABLE IF NOT EXISTS `ai_exam_results` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `exam_id` int(11) NOT NULL,
    `student_name` varchar(100) NOT NULL,
    `student_class` varchar(50) NOT NULL,
    `model_letter` char(1) NOT NULL DEFAULT 'A',
    `score` int(11) NOT NULL DEFAULT 0,
    `total_questions` int(11) NOT NULL DEFAULT 20,
    `correct_answers` int(11) NOT NULL DEFAULT 0,
    `percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
    `passed` tinyint(1) NOT NULL DEFAULT 0,
    `time_spent_seconds` int(11) NOT NULL DEFAULT 0,
    `answers_data` longtext DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text DEFAULT NULL,
    `cheating_attempts` int(11) NOT NULL DEFAULT 0,
    `started_at` timestamp NULL DEFAULT NULL,
    `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_exam_id` (`exam_id`),
    KEY `idx_submitted_at` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 29. جدول سجل طلبات الذكاء الاصطناعي (AI API Logs)
-- ====================================================
CREATE TABLE IF NOT EXISTS `ai_api_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `teacher_id` int(11) NOT NULL,
    `lesson_id` int(11) DEFAULT NULL,
    `api_type` varchar(50) NOT NULL DEFAULT 'gemini',
    `request_type` varchar(30) NOT NULL DEFAULT 'lesson_prep',
    `tokens_used` int(11) DEFAULT 0,
    `response_time_ms` int(11) DEFAULT 0,
    `status` varchar(20) NOT NULL DEFAULT 'success',
    `error_message` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_teacher_id` (`teacher_id`),
    KEY `idx_lesson_id` (`lesson_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 30. جدول حصص الجدول المدرسي (Timetable Periods)
-- ====================================================
CREATE TABLE IF NOT EXISTS `timetable_periods` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `period_number` int(11) NOT NULL,
    `period_name` varchar(100) NOT NULL,
    `start_time` time NOT NULL,
    `end_time` time NOT NULL,
    `is_break` tinyint(1) DEFAULT 0,
    `sort_order` int(11) DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 31. جدول إدخالات الجدول المدرسي (Timetable Entries)
-- ====================================================
CREATE TABLE IF NOT EXISTS `timetable_entries` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `class_id` int(11) NOT NULL,
    `subject_id` int(11) DEFAULT NULL,
    `teacher_id` int(11) DEFAULT NULL,
    `period_id` int(11) NOT NULL,
    `day_of_week` tinyint(4) NOT NULL COMMENT '1=الأحد, 2=الاثنين, 3=الثلاثاء, 4=الأربعاء, 5=الخميس',
    `room` varchar(100) DEFAULT NULL,
    `notes` varchar(255) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_class_day_period` (`class_id`, `day_of_week`, `period_id`),
    KEY `idx_class` (`class_id`),
    KEY `idx_teacher` (`teacher_id`),
    KEY `idx_day_period` (`day_of_week`, `period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 32. جدول ربط الجدول المدرسي بـ ASC (Timetable ASC Mapping)
-- ====================================================
CREATE TABLE IF NOT EXISTS `timetable_asc_mapping` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `asc_type` enum('class','subject','teacher','period') NOT NULL,
    `asc_id` varchar(100) NOT NULL,
    `asc_name` varchar(255) NOT NULL,
    `local_id` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_asc` (`asc_type`, `asc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ====================================================
-- البيانات الافتراضية - الإعدادات
-- ====================================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('system_name', 'نظام الإدارة المدرسية', 'اسم النظام'),
('school_name', 'المدرسة', 'اسم المدرسة'),
('academic_year', '2025-2026', 'العام الدراسي الحالي'),
('semester', 'first', 'الفصل الدراسي الحالي'),
('points_reset_enabled', '1', 'تفعيل إعادة تعيين النقاط'),
('allow_negative_points', '1', 'السماح بالنقاط السالبة'),
('max_points_per_evaluation', '100', 'الحد الأقصى للنقاط في التقييم الواحد'),
('min_points_per_evaluation', '-100', 'الحد الأدنى للنقاط في التقييم الواحد'),
('evaluations_enabled', '1', 'تفعيل/إيقاف نظام التقييمات (1=مفعل, 0=متوقف)'),
('allowed_days', 'السبت,الأحد,الاثنين,الثلاثاء,الأربعاء', 'أيام الأسبوع المسموح فيها بالتقييمات'),
('allowed_time_from', '07:00', 'وقت البداية المسموح فيه للتقييمات'),
('allowed_time_to', '16:00', 'وقت النهاية المسموح فيه للتقييمات'),
('unlimited_time', '0', 'السماح بالتقييم في أي وقت (1=مفعل, 0=معطل)')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- ====================================================
-- البيانات الافتراضية - أنواع التقييمات الإيجابية
-- ====================================================
INSERT INTO `evaluation_types` (`name`, `points`, `type`) VALUES
('التزام بالزي المدرسي', 5, 'positive'),
('المحافظة على النظافة', 5, 'positive'),
('التعاون مع الزملاء', 10, 'positive'),
('المشاركة الفعالة', 10, 'positive'),
('حسن الخلق', 10, 'positive'),
('الإبداع والتميز', 15, 'positive'),
('المساعدة في الأنشطة', 10, 'positive'),
('احترام المعلمين', 10, 'positive'),
('الانضباط والالتزام', 10, 'positive'),
('التفوق الأكاديمي', 20, 'positive')
ON DUPLICATE KEY UPDATE `points` = VALUES(`points`);

-- ====================================================
-- البيانات الافتراضية - أنواع التقييمات السلبية
-- ====================================================
INSERT INTO `evaluation_types` (`name`, `points`, `type`) VALUES
('التأخر عن المدرسة', -5, 'negative'),
('عدم إحضار الواجبات', -10, 'negative'),
('الإزعاج في الفصل', -10, 'negative'),
('عدم الالتزام بالزي', -5, 'negative'),
('التخريب', -15, 'negative'),
('عدم احترام الآخرين', -15, 'negative'),
('الغياب بدون عذر', -10, 'negative'),
('استخدام الهاتف', -10, 'negative')
ON DUPLICATE KEY UPDATE `points` = VALUES(`points`);

-- ====================================================
-- البيانات الافتراضية - المراحل الدراسية
-- ====================================================
INSERT INTO `stages` (`stage_name`, `stage_name_en`, `stage_code`, `stage_order`) VALUES
('المرحلة الابتدائية', 'Primary', 'primary', 1),
('المرحلة الإعدادية', 'Preparatory', 'preparatory', 2),
('المرحلة الثانوية', 'Secondary', 'secondary', 3)
ON DUPLICATE KEY UPDATE `stage_name` = VALUES(`stage_name`);

-- ====================================================
-- البيانات الافتراضية - الصفوف الدراسية
-- ====================================================
INSERT INTO `grades` (`grade_name`, `grade_code`, `grade_order`, `description`) VALUES
('الصف الأول الابتدائي', 'prim1', 1, 'الصف الأول من المرحلة الابتدائية'),
('الصف الثاني الابتدائي', 'prim2', 2, 'الصف الثاني من المرحلة الابتدائية'),
('الصف الثالث الابتدائي', 'prim3', 3, 'الصف الثالث من المرحلة الابتدائية'),
('الصف الرابع الابتدائي', 'prim4', 4, 'الصف الرابع من المرحلة الابتدائية'),
('الصف الخامس الابتدائي', 'prim5', 5, 'الصف الخامس من المرحلة الابتدائية'),
('الصف السادس الابتدائي', 'prim6', 6, 'الصف السادس من المرحلة الابتدائية')
ON DUPLICATE KEY UPDATE `grade_name` = VALUES(`grade_name`);

-- ====================================================
-- البيانات الافتراضية - حصص الجدول المدرسي
-- ====================================================
INSERT INTO `timetable_periods` (`period_number`, `period_name`, `start_time`, `end_time`, `is_break`, `sort_order`) VALUES
(1, 'الحصة الأولى', '07:30:00', '08:10:00', 0, 1),
(2, 'الحصة الثانية', '08:10:00', '08:50:00', 0, 2),
(3, 'الحصة الثالثة', '08:50:00', '09:30:00', 0, 3),
(0, 'الفسحة', '09:30:00', '10:00:00', 1, 4),
(4, 'الحصة الرابعة', '10:00:00', '10:40:00', 0, 5),
(5, 'الحصة الخامسة', '10:40:00', '11:20:00', 0, 6),
(6, 'الحصة السادسة', '11:20:00', '12:00:00', 0, 7),
(7, 'الحصة السابعة', '12:00:00', '12:40:00', 0, 8)
ON DUPLICATE KEY UPDATE `period_name` = VALUES(`period_name`);

-- ====================================================
-- 32 جدول - تم بنجاح ✓
-- ====================================================
