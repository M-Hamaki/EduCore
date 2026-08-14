<?php

declare(strict_types=1);

/**
 * Repairs the compatibility boundary between staff_profiles current summaries
 * and the normalized current staff_status_history event.
 *
 * Safety:
 * - Existing non-empty history values win during summary backfill.
 * - Re-running the migration is idempotent.
 * - Contract categories are normalized to the legacy-compatible canonical keys.
 * - Reviewed job-title aliases are merged into their canonical title everywhere.
 * - The retired "قسم الإعلام" title is removed from current and historical title fields.
 *
 * Rollback: restore the pre-migration database backup. The migration deliberately
 * does not guess which later user edits should be turned back into NULL values.
 */
return static function (PDO $db): void {
    $temporaryTable = 'tmp_staff_employment_summary_backfill';
    $db->exec("DROP TEMPORARY TABLE IF EXISTS `{$temporaryTable}`");

    $db->beginTransaction();
    try {
        foreach ([
            'staff_profiles' => ['contract_type'],
            'staff_status_history' => ['contract_type'],
            'staff_job_movements' => ['previous_contract_type', 'new_contract_type'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $db->exec("UPDATE `{$table}` SET `{$column}` = CASE TRIM(`{$column}`)
                    WHEN 'دائم' THEN 'permanent'
                    WHEN 'مؤقت' THEN 'temporary'
                    WHEN 'جزئي' THEN 'parttime'
                    WHEN 'أخرى' THEN 'other'
                    ELSE `{$column}` END
                    WHERE `{$column}` IS NOT NULL");
            }
        }

        foreach ([
            'staff_profiles' => ['job_title'],
            'staff_status_history' => ['job_title'],
            'staff_job_movements' => ['previous_job_title', 'new_job_title'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $db->exec("UPDATE `{$table}` SET `{$column}` = CASE TRIM(`{$column}`)
                    WHEN 'مدير مرحلة' THEN 'معلم'
                    WHEN 'منسق إداري' THEN 'معلم'
                    WHEN 'رئيس قسم' THEN 'معلم'
                    WHEN 'مدرس أول' THEN 'معلم'
                    WHEN 'منسق قسم' THEN 'معلم'
                    WHEN 'مسؤول المكتبة' THEN 'أمين مكتبة'
                    WHEN 'أخصائي اجتماعي' THEN 'أخصائي'
                    WHEN 'أخصائي نفسي' THEN 'أخصائي'
                    WHEN 'مشرف حسابات' THEN 'محاسب'
                    WHEN 'مدير حسابات' THEN 'محاسب'
                    WHEN 'قسم الإعلام' THEN NULL
                    ELSE `{$column}` END
                    WHERE `{$column}` IS NOT NULL");
            }
        }

        $db->exec("INSERT INTO staff_status_history
            (user_id, movement_type, status_after, status_label, status_reason,
             effective_date, contract_type, contract_start, contract_end,
             job_title, job_grade, department, source)
            SELECT
                profile.user_id,
                'تعيين',
                CASE WHEN profile.current_work_status = 'off_duty' THEN 'off_duty' ELSE 'on_duty' END,
                CASE WHEN profile.current_work_status = 'off_duty' THEN 'ليس على رأس العمل' ELSE 'على رأس العمل' END,
                COALESCE(NULLIF(TRIM(profile.current_status_reason), ''), 'تسجيل أولي من ملخص الملف الوظيفي'),
                COALESCE(profile.current_status_effective_date, profile.hire_date, profile.contract_start, CURRENT_DATE),
                NULLIF(TRIM(profile.contract_type), ''),
                profile.contract_start,
                profile.contract_end,
                NULLIF(TRIM(profile.job_title), ''),
                NULLIF(TRIM(profile.job_grade), ''),
                NULLIF(TRIM(profile.department), ''),
                'summary_backfill'
            FROM staff_profiles profile
            WHERE NOT EXISTS (
                SELECT 1 FROM staff_status_history history WHERE history.user_id = profile.user_id
            )");

        $db->exec("CREATE TEMPORARY TABLE `{$temporaryTable}` AS
            SELECT
                (
                    SELECT history.id
                    FROM staff_status_history history
                    WHERE history.user_id = profile.user_id
                    ORDER BY
                        CASE
                            WHEN history.effective_date IS NULL
                              OR history.effective_date <= CURRENT_DATE THEN 0
                            ELSE 1
                        END,
                        COALESCE(history.effective_date, '9999-12-31') DESC,
                        history.id DESC
                    LIMIT 1
                ) AS status_id,
                profile.job_title,
                profile.job_grade,
                profile.department,
                profile.contract_type,
                profile.contract_start,
                profile.contract_end
            FROM staff_profiles profile");

        $db->exec("UPDATE staff_status_history history
            INNER JOIN `{$temporaryTable}` summary ON summary.status_id = history.id
            SET
                history.job_title = CASE
                    WHEN history.job_title IS NULL OR TRIM(history.job_title) = ''
                    THEN NULLIF(TRIM(summary.job_title), '') ELSE history.job_title END,
                history.job_grade = CASE
                    WHEN history.job_grade IS NULL OR TRIM(history.job_grade) = ''
                    THEN NULLIF(TRIM(summary.job_grade), '') ELSE history.job_grade END,
                history.department = CASE
                    WHEN history.department IS NULL OR TRIM(history.department) = ''
                    THEN NULLIF(TRIM(summary.department), '') ELSE history.department END,
                history.contract_type = CASE
                    WHEN history.contract_type IS NULL OR TRIM(history.contract_type) = ''
                    THEN NULLIF(TRIM(summary.contract_type), '') ELSE history.contract_type END,
                history.contract_start = COALESCE(history.contract_start, summary.contract_start),
                history.contract_end = COALESCE(history.contract_end, summary.contract_end)
            WHERE summary.status_id IS NOT NULL");

        $db->exec("DROP TEMPORARY TABLE IF EXISTS `{$temporaryTable}`");
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $db->exec("DROP TEMPORARY TABLE IF EXISTS `{$temporaryTable}`");
        throw $exception;
    }
};
