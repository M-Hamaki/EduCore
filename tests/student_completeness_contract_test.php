<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/student_data_completeness.php');
$endpoint = (string) file_get_contents($root . '/admin/ajax_student_completeness.php');
$repository = (string) file_get_contents($root . '/src/Modules/Students/StudentCompletenessReadRepository.php');
$configService = (string) file_get_contents($root . '/src/Modules/Students/StudentCompletenessConfigService.php');
$mainScript = (string) file_get_contents($root . '/assets/js/main.js');

$pageAuthAt = strpos($page, "Utilities::validateSession('admin');");
$pageRequestAt = strpos($page, '$_GET');
$endpointAuthAt = strpos($endpoint, "Utilities::validateSession('admin');");
$endpointRequestAt = strpos($endpoint, '$_REQUEST');

$checks = [
    'page_authenticates_before_request_data' => $pageAuthAt !== false
        && ($pageRequestAt === false || $pageAuthAt < $pageRequestAt),
    'endpoint_authenticates_before_request_data' => $endpointAuthAt !== false
        && ($endpointRequestAt === false || $endpointAuthAt < $endpointRequestAt),
    'page_delegates_reads_without_page_sql' => strpos($page, 'StudentCompletenessReadRepository') !== false
        && strpos($page, 'SELECT ') === false,
    'annual_enrollment_is_authoritative' => strpos($repository, 'student_enrollments se') !== false
        && strpos($repository, 'se.academic_year_id = ?') !== false
        && strpos($repository, 'se.enrollment_status') !== false
        && strpos($repository, 'se.academic_status') !== false,
    'annual_readiness_covers_missing_and_inconsistent_cases' => strpos($repository, "return 'missing_enrollment'") !== false
        && strpos($repository, "return 'missing_structure'") !== false
        && strpos($repository, "return 'inconsistent_structure'") !== false
        && strpos($repository, "return 'awaiting_placement'") !== false,
    'experimental_scope_is_inherited_and_filterable' => strpos($repository, 'effective_is_experimental') !== false
        && strpos($repository, 'stage_is_experimental') !== false
        && strpos($repository, 'grade_is_experimental') !== false
        && strpos($repository, 'class_is_experimental') !== false,
    'scoped_roles_are_restricted_by_class' => strpos($endpoint, 'ScopedStaffPortalContext') !== false
        && strpos($endpoint, 'allowedClassIds()') !== false
        && strpos($repository, 'se.class_id IN (') !== false,
    'stats_and_table_share_repository_filters' => strpos($endpoint, '$repository->stats($filters, $allowedClassIds)') !== false
        && strpos($endpoint, '$repository->dataTable(') !== false,
    'config_is_stored_as_audited_setting' => strpos($configService, "student_completeness_fields_v2") !== false
        && strpos($configService, 'INSERT INTO settings') !== false
        && strpos($configService, 'new AuditService') !== false
        && strpos($configService, 'recordUpdate(') !== false,
    'runtime_config_file_writes_are_removed' => strpos($endpoint, 'file_put_contents') === false
        && strpos($page, 'file_put_contents') === false,
    'page_uses_topbar_year_and_all_annual_status_filters' => strpos($page, 'AcademicYear::getCurrent($db)') !== false
        && strpos($endpoint, 'AcademicYear::getCurrent($db)') !== false
        && strpos($page, 'id="academicYearSelect"') === false
        && strpos($endpoint, "\$_REQUEST['academic_year_id']") === false
        && strpos($page, 'enrollmentStatusFilter') !== false
        && strpos($page, 'academicStatusFilter') !== false
        && strpos($page, 'annualStateFilter') !== false,
    'async_stats_do_not_use_global_counter_animation' => strpos($page, 'id="statTotal"') !== false
        && strpos($page, 'id="statTotal" data-target=') === false
        && strpos($page, 'animateCounter') === false,
    'statistics_use_western_number_format_consistently' => strpos($page, "Intl.NumberFormat('en-US'") !== false
        && strpos($page, "toLocaleString('ar-EG')") === false,
    'default_filters_are_declared_without_false_active_state' => strpos($page, 'id="enrollmentStatusFilter"') !== false
        && strpos($page, 'data-default-value="enrolled"') !== false
        && strpos($page, 'id="experimentalScopeFilter"') !== false
        && strpos($page, 'data-default-value="official"') !== false
        && strpos($mainScript, "ctrl.getAttribute('data-default-value')") !== false,
    'secondary_filters_are_grouped_as_advanced' => strpos($page, 'id="advancedFiltersToggle"') !== false
        && strpos($page, 'id="advancedFiltersCount"') !== false
        && strpos($page, 'class="collapse w-100" id="advancedFilters"') !== false
        && strpos($page, 'advancedFilterIds') !== false,
    'page_uses_shared_table_settings_and_bootstrap_feedback' => strpos($page, 'admin_table_actions.js') !== false
        && strpos($page, 'initializeTableColumnSettings') !== false
        && strpos($page, 'localStorage') === false
        && strpos($page, 'alert(') === false
        && strpos($page, 'confirm(') === false
        && strpos($page, 'Swal') === false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
