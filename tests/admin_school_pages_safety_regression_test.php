<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string) file_get_contents($root . '/admin/index.php');
$dashboardCharts = (string) file_get_contents($root . '/classes/Presentation/Dashboard/charts.php');
$dashboardInteractions = (string) file_get_contents($root . '/classes/Presentation/Dashboard/interactions.php');
$profile = (string) file_get_contents($root . '/admin/school_profile.php');
$statistics = (string) file_get_contents($root . '/admin/school_statistics.php');
$classes = (string) file_get_contents($root . '/admin/classes.php');
$classroom = (string) file_get_contents($root . '/classes/classroom.php');

$checks = [
    'dashboard_chart_json_is_script_context_safe' => str_contains($dashboardCharts, 'JSON_HEX_TAG')
        && str_contains($dashboardCharts, 'JSON_HEX_AMP')
        && str_contains($dashboardCharts, 'JSON_HEX_APOS')
        && str_contains($dashboardCharts, 'JSON_HEX_QUOT')
        && !str_contains($dashboardCharts, 'json_encode($chart_'),
    'dashboard_charts_fail_independently' => str_contains($dashboard, '$dashboardLoadChart = static function')
        && substr_count($dashboard, '$dashboardLoadChart(') >= 10
        && str_contains($dashboard, "Dashboard chart load failed"),
    'dashboard_no_year_relations_use_classes' => str_contains($dashboard, 'LEFT JOIN classes c ON c.grade_id = g.id')
        && str_contains($dashboard, 'LEFT JOIN users u ON u.class_id = c.id'),
    'dashboard_keeps_real_zero_active_subject_count' => !str_contains($dashboard, 'if ($active_subjects_count === 0)')
        && str_contains($dashboard, '$active_subjects_count = (int)'),
    'dashboard_class_count_respects_current_year' => str_contains($dashboard, "SELECT COUNT(*) FROM classes WHERE academic_year_id = ? OR academic_year_id IS NULL"),
    'dashboard_preferences_survive_corrupt_storage' => str_contains($dashboardInteractions, 'localStorage.removeItem(STORAGE_KEY)')
        && str_contains($dashboardInteractions, 'catch (error)'),
    'school_profile_csrf_fails_closed' => str_contains($profile, '$sessionToken === \'\' || $providedToken === \'\'')
        && str_contains($profile, '!hash_equals($sessionToken, $providedToken)'),
    'school_profile_sections_and_custom_fields_are_validated' => str_contains($profile, "['', 'basic', 'services', 'directors', 'other']")
        && str_contains($profile, 'Invalid custom field payload')
        && str_contains($profile, 'count($customNames) > 50'),
    'school_profile_save_is_atomic_and_audited' => str_contains($profile, '$db->beginTransaction();')
        && str_contains($profile, 'new \\EduCore\\Modules\\Operations\\Audit\\AuditService($db)')
        && str_contains($profile, "SELECT * FROM settings WHERE setting_key = ? FOR UPDATE")
        && str_contains($profile, '$db->commit();')
        && str_contains($profile, '$db->rollBack();'),
    'school_profile_logo_contract_matches_server' => !str_contains($profile, 'image/svg+xml')
        && str_contains($profile, '.jpg,.jpeg,.png,.webp,.gif')
        && str_contains($profile, 'FileUploadGuard::validate'),
    'school_profile_does_not_expose_exception_details' => !str_contains($profile, "'حدث خطأ: ' . $e->getMessage()")
        && str_contains($profile, "error_log('School profile update failed:"),
    'statistics_include_empty_active_classes_in_average' => str_contains($statistics, 'AVG(COALESCE(class_counts.cnt, 0))')
        && str_contains($statistics, 'LEFT JOIN (')
        && str_contains($statistics, "c.status = 'active'"),
    'statistics_occupancy_uses_active_classes' => str_contains($statistics, '$totalClasses = (int)$overview[\'active_classes\'];')
        && str_contains($statistics, '$distributionStudents = (int) $overview[\'active_students\'];'),
    'statistics_preferences_survive_corrupt_storage' => str_contains($statistics, 'localStorage.removeItem(STORAGE_KEY)')
        && str_contains($statistics, 'catch (error)'),
    'classroom_insert_contains_academic_year' => str_contains($classroom, 'academic_year_id = :academic_year_id')
        && str_contains($classroom, 'bindValue(":academic_year_id"'),
    'classes_do_not_hide_real_years_without_active_year' => str_contains($classes, 'if ($currentAcademicYearId > 0)')
        && !str_contains($classes, '$whereClauses = ["(c.academic_year_id = :academic_year_id_filter OR c.academic_year_id IS NULL)"]'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
