<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/StaffEmploymentLifecycleService.php';

$service = new StaffEmploymentLifecycleService(new PDO('sqlite::memory:'));
$events = [
    [
        'movement_type' => 'تعيين',
        'effective_date' => '2020-09-01',
        'job_title' => 'معلم مساعد',
        'department' => null,
        'contract_type' => null,
    ],
    [
        'movement_type' => 'عودة للعمل',
        'effective_date' => '2025-09-01',
        'job_title' => null,
        'department' => null,
        'contract_type' => null,
    ],
    [
        'movement_type' => 'نقل مستقبلي',
        'effective_date' => '2999-09-01',
        'job_title' => null,
        'department' => null,
        'contract_type' => null,
    ],
];
$hydrated = $service->hydrateMissingCurrentSummary($events, [
    'job_title' => 'مدرس أول',
    'job_grade' => 'الدرجة الأولى',
    'department' => 'ابتدائي',
    'contract_type' => 'دائم',
    'contract_start' => '2025-09-01',
]);
$synthetic = $service->hydrateMissingCurrentSummary([], [
    'job_title' => 'مسؤول المكتبة',
    'department' => 'ابتدائي',
    'contract_type' => 'دائم',
    'current_work_status' => 'on_duty',
    'hire_date' => '2020-09-01',
]);
$normalized = $service->normalizeStatusHistory([
    'status_history' => json_encode([[
        'movement_type' => 'تعيين',
        'status_after' => 'on_duty',
        'effective_date' => '2025-09-01',
        'contract_type' => 'مؤقت',
        'job_title' => 'أخصائي نفسي',
    ]], JSON_UNESCAPED_UNICODE),
], []);
$movementTitles = $service->normalizeJobMovements([
    'promotions' => json_encode([[
        'movement_type' => 'ترقية',
        'previous_job_title' => 'مشرف حسابات',
        'new_job_title' => 'مدير حسابات',
    ]], JSON_UNESCAPED_UNICODE),
]);
$custom = $service->normalizeStatusHistory([
    'status_history' => json_encode([[
        'movement_type' => 'تعيين',
        'status_after' => 'on_duty',
        'effective_date' => '2025-09-01',
        'contract_type' => 'أخرى',
        'contract_type_custom' => 'موسمي',
    ]], JSON_UNESCAPED_UNICODE),
], []);
$expectedJobTitleAliases = [
    'مدير مرحلة' => 'معلم',
    'منسق إداري' => 'معلم',
    'رئيس قسم' => 'معلم',
    'مدرس أول' => 'معلم',
    'منسق قسم' => 'معلم',
    'مسؤول المكتبة' => 'أمين مكتبة',
    'أخصائي اجتماعي' => 'أخصائي',
    'أخصائي نفسي' => 'أخصائي',
    'مشرف حسابات' => 'محاسب',
    'مدير حسابات' => 'محاسب',
];
$canonicalMappingComplete = true;
foreach ($expectedJobTitleAliases as $legacyTitle => $canonicalTitle) {
    $canonicalMappingComplete = $canonicalMappingComplete
        && StaffEmploymentLifecycleService::canonicalJobTitle($legacyTitle) === $canonicalTitle;
}

$pageQuery = (string) file_get_contents(
    __DIR__ . '/../src/Modules/Staff/StaffProfilePageQuery.php'
);
$migration = (string) file_get_contents(
    __DIR__ . '/../database/migrations/20260729_staff_employment_summary_consistency.php'
);
$listPresenter = (string) file_get_contents(
    __DIR__ . '/../src/Modules/Staff/Presentation/StaffListDataTablePresenter.php'
);
$listPageQuery = (string) file_get_contents(
    __DIR__ . '/../src/Modules/Staff/StaffListPageQuery.php'
);
$listDataQuery = (string) file_get_contents(
    __DIR__ . '/../src/Modules/Staff/StaffListDataTableQuery.php'
);
$profileStore = (string) file_get_contents(__DIR__ . '/../classes/UserProfileStore.php');
$staffExport = (string) file_get_contents(__DIR__ . '/../admin/export_staff.php');
$staffStatistics = (string) file_get_contents(__DIR__ . '/../admin/staff_statistics.php');
$staffAttendancePage = (string) file_get_contents(__DIR__ . '/../admin/staff_attendance.php');
$staffAttendanceService = (string) file_get_contents(__DIR__ . '/../classes/StaffAttendanceService.php');
$staffFinancialPage = (string) file_get_contents(__DIR__ . '/../admin/staff_financial_data.php');
$assignmentPage = (string) file_get_contents(__DIR__ . '/../admin/assessment_teacher_assignments.php');
$assignmentQuery = (string) file_get_contents(__DIR__ . '/../classes/AssessmentTeacherAssignmentListQuery.php');
$teacherEvaluations = (string) file_get_contents(__DIR__ . '/../admin/teacher_evaluations.php');
$specialistEvaluations = (string) file_get_contents(__DIR__ . '/../src/Modules/BehaviorEvaluation/SpecialistEvaluationReadService.php');
$globalSearch = (string) file_get_contents(__DIR__ . '/../src/Modules/Search/Infrastructure/PdoGlobalSearchReadRepository.php');
$financeReadQuery = (string) file_get_contents(__DIR__ . '/../src/Modules/Staff/Infrastructure/PdoStaffFinanceReadQuery.php');
$employmentReadQuery = (string) file_get_contents(__DIR__ . '/../src/Modules/Staff/Infrastructure/PdoStaffEmploymentQuery.php');
$teacherFilterValues = StaffEmploymentLifecycleService::jobTitleFilterValues('معلم');
$canonicalOptionsFromLegacy = StaffEmploymentLifecycleService::canonicalJobTitleOptionsFromValues([
    'رئيس قسم', 'مدرس أول', 'معلم', 'مسؤول المكتبة', 'قسم الإعلام', '', null,
]);

$checks = [
    'historical_explicit_title_preserved' => ($hydrated[0]['job_title'] ?? null) === 'معلم مساعد',
    'effective_current_title_hydrated_and_merged' => ($hydrated[1]['job_title'] ?? null) === 'معلم',
    'effective_current_department_hydrated' => ($hydrated[1]['department'] ?? null) === 'ابتدائي',
    'future_event_not_used_as_current' => empty($hydrated[2]['job_title']),
    'missing_history_gets_compatible_current_event' => count($synthetic) === 1
        && ($synthetic[0]['job_title'] ?? null) === 'أمين مكتبة'
        && ($synthetic[0]['department'] ?? null) === 'ابتدائي',
    'contract_summary_canonicalized' => ($hydrated[1]['contract_type'] ?? null) === 'permanent',
    'arabic_contract_input_canonicalized' => ($normalized[0]['contract_type'] ?? null) === 'temporary',
    'status_job_title_canonicalized' => ($normalized[0]['job_title'] ?? null) === 'أخصائي',
    'movement_job_titles_canonicalized' => ($movementTitles[0]['previous_job_title'] ?? null) === 'محاسب'
        && ($movementTitles[0]['new_job_title'] ?? null) === 'محاسب',
    'custom_contract_preserved' => ($custom[0]['contract_type'] ?? null) === 'موسمي',
    'all_requested_job_title_aliases_are_mapped' => $canonicalMappingComplete,
    'job_title_options_use_only_reviewed_canonical_labels' => in_array('معلم', StaffEmploymentLifecycleService::jobTitleOptions(), true)
        && in_array('أمين مكتبة', StaffEmploymentLifecycleService::jobTitleOptions(), true)
        && in_array('أخصائي', StaffEmploymentLifecycleService::jobTitleOptions(), true)
        && in_array('محاسب', StaffEmploymentLifecycleService::jobTitleOptions(), true)
        && !array_intersect([
            'مدير مرحلة', 'منسق إداري', 'رئيس قسم', 'مدرس أول', 'منسق قسم',
            'مسؤول المكتبة', 'أخصائي اجتماعي', 'أخصائي نفسي',
            'مشرف حسابات', 'مدير حسابات', 'قسم الإعلام',
        ], StaffEmploymentLifecycleService::jobTitleOptions()),
    'retired_media_title_is_removed_everywhere' => StaffEmploymentLifecycleService::canonicalJobTitle('قسم الإعلام') === null
        && strpos($migration, "WHEN 'قسم الإعلام' THEN NULL") !== false,
    'canonical_filter_expands_all_merged_teacher_titles' => count($teacherFilterValues) === 6
        && in_array('معلم', $teacherFilterValues, true)
        && in_array('رئيس قسم', $teacherFilterValues, true)
        && in_array('مدرس أول', $teacherFilterValues, true),
    'legacy_option_sets_are_collapsed_and_retired_values_removed' => $canonicalOptionsFromLegacy === ['أمين مكتبة', 'معلم'],
    'staff_table_canonicalizes_titles_before_rendering' => strpos($listPresenter, 'canonicalJobTitle') !== false,
    'staff_export_canonicalizes_titles_before_output' => strpos($staffExport, "case 'job_title': return StaffEmploymentLifecycleService::canonicalJobTitle") !== false,
    'staff_statistics_merge_legacy_title_totals' => strpos($staffStatistics, '$raw_top_jobs') !== false
        && strpos($staffStatistics, 'StaffEmploymentLifecycleService::canonicalJobTitle') !== false,
    'attendance_filters_and_rows_are_canonicalized' => strpos($staffAttendancePage, 'canonicalJobTitleOptionsFromValues') !== false
        && strpos($staffAttendancePage, 'jobTitleFilterValues') !== false
        && strpos($staffAttendanceService, 'jobTitleFilterValues') !== false
        && strpos($staffAttendanceService, "sp.job_title = ?") === false,
    'financial_filters_json_and_rows_are_canonicalized' => strpos($staffFinancialPage, 'canonicalJobTitleOptionsFromValues') !== false
        && strpos($staffFinancialPage, 'jobTitleFilterValues') !== false
        && substr_count($staffFinancialPage, 'StaffEmploymentLifecycleService::canonicalJobTitle') >= 4
        && strpos($staffFinancialPage, "sp.job_title = ?") === false,
    'teacher_assignment_filters_and_rows_are_canonicalized' => strpos($assignmentPage, 'canonicalJobTitleOptionsFromValues') !== false
        && strpos($assignmentQuery, 'jobTitleFilterValues') !== false
        && strpos($assignmentQuery, 'StaffEmploymentLifecycleService::canonicalJobTitle') !== false
        && strpos($assignmentQuery, "sp.job_title = ?") === false,
    'evaluation_and_search_read_models_are_canonicalized' => strpos($teacherEvaluations, 'StaffEmploymentLifecycleService::canonicalJobTitle') !== false
        && strpos($specialistEvaluations, 'StaffEmploymentLifecycleService::canonicalJobTitle') !== false
        && strpos($globalSearch, 'StaffEmploymentLifecycleService::canonicalJobTitle') !== false,
    'cross_module_staff_read_contracts_are_canonicalized' => strpos($financeReadQuery, 'StaffEmploymentLifecycleService::canonicalJobTitle') !== false
        && strpos($employmentReadQuery, 'StaffEmploymentLifecycleService::canonicalJobTitle') !== false,
    'staff_filter_options_are_canonicalized' => strpos($listPageQuery, 'StaffEmploymentLifecycleService::canonicalJobTitle') !== false
        && strpos($listPageQuery, "'قسم الإعلام'") === false,
    'staff_filter_matches_merged_database_values' => strpos($listDataQuery, 'jobTitleFilterValues') !== false
        && strpos($profileStore, 'sp.job_title IN (') !== false,
    'profile_store_rejects_legacy_titles_at_write_boundary' => strpos($profileStore, "\$data['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle") !== false,
    'retired_title_filter_cannot_fall_back_to_all_staff' => strpos($profileStore, '$invalidJobTitleFilter') !== false
        && strpos($profileStore, "\$where .= ' AND 1 = 0';") !== false,
    'edit_query_hydrates_missing_current_summary' => strpos($pageQuery, 'hydrateMissingCurrentSummary') !== false,
    'migration_never_blindly_overwrites_history' => strpos($migration, "WHEN history.job_title IS NULL OR TRIM(history.job_title) = ''") !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
