<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/staff.php');
$query = (string) file_get_contents($root . '/src/Modules/Staff/StaffListPageQuery.php');
$view = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/list_view.php');
$scripts = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/page_scripts.php');
$endpoint = (string) file_get_contents($root . '/admin/ajax_staff_datatable.php');
$adminCss = (string) file_get_contents($root . '/assets/css/admin-unified.css');
$dataTableQuery = (string) file_get_contents($root . '/src/Modules/Staff/StaffListDataTableQuery.php');
$presenter = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/StaffListDataTablePresenter.php');
$profileStore = (string) file_get_contents($root . '/classes/UserProfileStore.php');

$expectedStaffColumns = [
    'col-biometric', 'col-code', 'col-name', 'col-job-title', 'col-mobile', 'col-national-id',
    'col-passport', 'col-birth-date', 'col-birth-place', 'col-gender', 'col-religion',
    'col-nationality', 'col-ministry-code', 'col-military', 'col-marital', 'col-children',
    'col-city-area', 'col-address', 'col-phone-home', 'col-phone-emergency', 'col-email',
    'col-emergency-contact', 'col-qualification', 'col-qual-year', 'col-qual-uni',
    'col-specialization', 'col-experience', 'col-contract-type', 'col-blood-type',
    'col-insurance-number', 'col-insurance-start', 'col-insurance-end', 'col-health-status',
    'col-chronic', 'col-allergies', 'col-disabilities', 'col-medications', 'col-treatment',
    'col-medical-reports', 'col-emergency-notes', 'col-psychological', 'col-name-en',
    'col-current-age', 'col-public-service', 'col-social-notes', 'col-extra-phones',
    'col-extra-data', 'col-admin-notes', 'col-department', 'col-job-grade', 'col-hire-date',
    'col-contract-start', 'col-contract-end', 'col-status-reason', 'col-status-effective',
    'col-first-hire', 'col-latest-hire', 'col-last-working-day', 'col-can-rehire',
    'col-last-job-movement', 'col-status-history', 'col-job-movements', 'col-extra-employment',
    'col-other-qualifications', 'col-training-courses', 'col-work-history', 'col-profile-image',
    'col-attachments', 'col-status',
];
$allStaffColumnsExposed = true;
foreach ($expectedStaffColumns as $columnClass) {
    if (strpos($view, 'class="' . $columnClass) === false || strpos($scripts, "['" . $columnClass . "'") === false) {
        $allStaffColumnsExposed = false;
        break;
    }
}
$profileSectionTitles = [
    'البيانات الشخصية', 'البيانات الاجتماعية', 'العناوين وبيانات التواصل', 'إضافة بيانات أخرى',
    'ملاحظة إدارية', 'بيانات التعاقد والسجل الوظيفي', 'الترقيات والتدرج الوظيفي',
    'المؤهلات العلمية', 'المؤهلات الدراسية والشهادات العلمية الأخرى',
    'الدورات التدريبية والشهادات العلمية', 'الخبرات وأماكن العمل السابقة', 'الحالة الصحية',
    'الحالة النفسية والسلوكية', 'مرفقات الموظف',
];
$allProfileSectionsMirrored = true;
foreach ($profileSectionTitles as $sectionTitle) {
    if (strpos($scripts, "['__header__', '" . $sectionTitle . "']") === false) {
        $allProfileSectionsMirrored = false;
        break;
    }
}

$checks = [
    'page_delegates' => strpos($page, '$staffListPageQuery->load($_GET)') !== false,
    'activity_page_size_preserved' => strpos(
        $query,
        'private const ACTIVITY_PER_PAGE = 40;'
    ) !== false,
    'target_types_preserved' => strpos(
        $query,
        "['staff', 'teacher', 'specialist']"
    ) !== false,
    'filters_preserved' => strpos($query, "'log_action' => 'action'") !== false
        && strpos($query, "'log_search' => 'search'") !== false
        && strpos($query, "'log_from' => 'date_from'") !== false
        && strpos($query, "'log_to' => 'date_to'") !== false,
    'page_clamping_preserved' => strpos($query, 'if ($page > $pages && $pages > 0)') !== false,
    'activity_count_is_deferred' => strpos($query, "if (\$listMode && \$mainTab === 'activity_log')") !== false
        && strpos($query, "if (\$listMode && \$mainTab === 'activity_log')")
            < strpos($query, 'ActivityLog::countLogs($filters)'),
    'query_does_not_read_superglobals' => strpos($query, '$_GET') === false
        && strpos($query, '$_POST') === false,
    'staff_rows_and_filters_owned' => strpos($query, 'readStaffWithProfilesPaginated(0, 0, $staffTotal)') !== false
        && strpos($query, 'getStaffListFilterOptions()') !== false
        && strpos($query, "'filter_job_titles'") !== false
        && strpos($query, "'filter_forces'") !== false,
    'server_side_datatable_contract' => strpos($view, '$staffTotal') !== false
        && strpos($scripts, 'AdminServerSideTable.init') !== false
        && strpos($scripts, "url: 'ajax_staff_datatable.php'") !== false
        && strpos($dataTableQuery, 'min(500, max(10, $requestedLength))') !== false
        && strpos($endpoint, 'requireCsrfPost();') !== false
        && strpos($endpoint, '->load($_POST)') !== false,
    'staff_table_settings_mirror_profile_tabs_and_sections' => strpos($scripts, 'modal-lg modal-dialog-scrollable') !== false
        && strpos($scripts, 'staff-table-settings-sections') !== false
        && strpos($scripts, 'staff-table-settings-stack-item') !== false
        && strpos($scripts, 'staff-table-settings-tab-content') === false
        && strpos($scripts, 'staff-table-settings-section') !== false
        && strpos($scripts, 'form-check form-switch staff-table-settings-switch') !== false
        && strpos($scripts, "'البيانات الأساسية' => [") !== false
        && strpos($scripts, "'البيانات الوظيفية' => [") !== false
        && strpos($scripts, "'المؤهلات والخبرات' => [") !== false
        && strpos($scripts, "'البيانات الصحية والنفسية' => [") !== false
        && strpos($scripts, "'المرفقات' => [") !== false
        && $allProfileSectionsMirrored,
    'all_profile_columns_are_available_in_table_settings' => count($expectedStaffColumns) === 69
        && $allStaffColumnsExposed
        && strpos($view, 'colspan="71"') !== false
        && strpos($dataTableQuery, "69 => 'current_work_status'") !== false,
    'datatable_query_and_presenter_supply_extended_profile_fields' => strpos($profileStore, 'sp.full_name_en') !== false
        && strpos($profileStore, 'sp.extra_employment_data') !== false
        && strpos($profileStore, 'AS status_history_count') !== false
        && strpos($profileStore, 'AS job_movements_count') !== false
        && strpos($profileStore, 'AS attachment_count') !== false
        && strpos($presenter, "\$row['full_name_en']") !== false
        && strpos($presenter, "\$row['other_qualifications']") !== false
        && strpos($presenter, "\$row['training_courses']") !== false
        && strpos($presenter, "\$row['work_history']") !== false
        && strpos($presenter, "\$row['attachment_count']") !== false,
    'staff_table_settings_behavior_contract_preserved' => strpos($scripts, "var storageKey = 'staff_table_columns';") !== false
        && strpos($scripts, 'id="selectAllColumns"') !== false
        && strpos($scripts, 'id="deselectAllColumns"') !== false
        && strpos($scripts, 'data-target-section=') !== false
        && strpos($scripts, 'data-column=') !== false,
    'staff_table_settings_styles_are_centralized' => strpos($adminCss, '.staff-table-settings .staff-table-settings-section') !== false
        && strpos($adminCss, '.staff-table-settings .staff-table-settings-switch') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
