<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$query = (string) file_get_contents($root . '/classes/AccountListDataTableQuery.php');
$studentPage = (string) file_get_contents($root . '/admin/student_accounts.php');
$staffPage = (string) file_get_contents($root . '/admin/staff_accounts.php');
$studentEndpoint = (string) file_get_contents($root . '/admin/ajax_student_accounts_datatable.php');
$staffEndpoint = (string) file_get_contents($root . '/admin/ajax_staff_accounts_datatable.php');
$staffSingleModals = (string) file_get_contents($root . '/includes/staff_single_modals.php');
$sharedServerTable = (string) file_get_contents($root . '/assets/js/admin-server-side-table.js');
$staffPresenter = substr($query, (int)strpos($query, 'private function presentStaff'));

$checks = [
    'query_does_not_decrypt_passwords' => strpos($query, 'decryptPasswordForUser') === false,
    'query_uses_password_presence_only' => substr_count($query, 'password IS NOT NULL') >= 2,
    'student_endpoint_is_csrf_protected' => strpos($studentEndpoint, 'requireCsrfPost()') !== false && strpos($studentEndpoint, "validateSession('admin')") !== false,
    'staff_endpoint_is_csrf_protected' => strpos($staffEndpoint, 'requireCsrfPost()') !== false && strpos($staffEndpoint, "validateSession('admin')") !== false,
    'student_page_uses_shared_table' => strpos($studentPage, 'AdminServerSideTable.init') !== false && strpos($studentPage, 'ajax_student_accounts_datatable.php') !== false,
    'student_account_type_is_filterable' => strpos($query, 'COALESCE(u.is_test_account, 0)') !== false
        && strpos($studentPage, 'name="account_type"') !== false,
    'student_account_type_change_is_confirmed' => strpos($studentPage, 'id="testAccountModal"') !== false
        && strpos($studentPage, 'openTestAccountModal') !== false,
    'staff_page_uses_shared_table' => strpos($staffPage, 'AdminServerSideTable.init') !== false && strpos($staffPage, 'ajax_staff_accounts_datatable.php') !== false,
    'staff_server_table_has_single_initializer' => strpos($staffPage, 'class="table table-hover table-striped admin-data-table align-middle" id="staffAccountsTable"') !== false
        && strpos($staffPage, 'class="table table-hover table-striped datatable admin-data-table align-middle" id="staffAccountsTable"') === false,
    'staff_list_is_profile_backed' => strpos($query, 'INNER JOIN staff_profiles') !== false,
    'staff_job_titles_are_canonicalized_before_display' => strpos($query, "require_once __DIR__ . '/StaffEmploymentLifecycleService.php';") !== false
        && strpos($staffPresenter, 'StaffEmploymentLifecycleService::canonicalJobTitle') !== false
        && strpos($staffPresenter, "\$e(\$row['job_title'])") === false,
    'employee_role_has_scoped_self_service_portal' => strpos($staffPage, "in_array('employee', \$selectedRoles, true)") !== false
        && strpos($staffPage, "'employee' => 'موظف'") !== false
        && strpos($staffPage, 'password_hash = NULL') === false
        && strpos($query, 'is_employee_only') !== false,
    'scoped_staff_roles_are_available' => strpos($staffPage, "'doctor' => 'طبيب'") !== false
        && strpos($staffPage, "'librarian' => 'أمين مكتبة'") !== false
        && strpos($staffEndpoint, "'librarian'=>'أمين مكتبة'") !== false
        && strpos($staffPage, 'requiresAcademicScope($selectedRole)') !== false,
    'staff_filters_are_live' => strpos($staffPage, 'id="staffAccountFilters"') !== false
        && strpos($staffPage, "staffAccountsTable.ajax.reload()") !== false
        && strpos($staffPage, 'id="staffAccessFilter"') !== false,
    'staff_accounts_use_one_list_with_multi_role_domain_filter' => strpos($staffPage, 'id="accountGroupDropdown"') !== false
        && strpos($staffPage, 'class="form-check-input account-group-checkbox"') !== false
        && strpos($staffPage, "document.querySelectorAll('.account-group-checkbox:checked')") !== false
        && strpos($staffPage, '>حسابات العاملين<') !== false
        && strpos($staffPage, '>الأكاديميين<') === false
        && strpos($staffPage, '>الموظفين<') === false
        && strpos($query, "\$accountGroup === 'academic'") !== false
        && strpos($query, "\$accountGroup === 'non_academic'") !== false
        && strpos($query, 'count($accountGroups) === 1') !== false,
    'legacy_staff_tab_links_remain_filter_aliases' => strpos($staffPage, "'academics' => 'academic'") !== false
        && strpos($staffPage, "'employees' => 'non_academic'") !== false
        && strpos($query, "\$tab === 'academics'") !== false
        && strpos($query, "\$tab === 'employees'") !== false,
    'staff_filter_summary_tracks_the_visible_dataset' => strpos($staffEndpoint, "\$response['summary']") !== false
        && strpos($staffEndpoint, 'staffSummary(array_keys($portalRoles), $_POST)') !== false
        && strpos($sharedServerTable, 'updateSummary(response && response.summary)') !== false
        && strpos($staffPage, "asset_url('../assets/js/admin-server-side-table.js')") !== false,
    'account_tables_default_to_50_and_offer_all' => strpos($sharedServerTable, 'pageLength: 50') !== false
        && strpos($sharedServerTable, '500, -1') !== false
        && strpos($sharedServerTable, "500, 'الكل'") !== false,
    'staff_has_single_credentials_action' => strpos($staffPresenter, 'title="تعديل بيانات الدخول"') !== false
        && strpos($staffPresenter, 'openToggleModal') === false,
    'staff_supervisor_flag_is_loaded_for_role_action' => strpos($query, 'u.is_supervisor') !== false
        && strpos($staffPresenter, 'openRoleAccessModal(') !== false
        && strpos($staffSingleModals, 'id="role_is_supervisor"') !== false,
    'self_super_admin_has_secondary_role_action_only' => strpos($query, 'bool $allowSelfRoleEdit = false') !== false
        && strpos($staffPresenter, 'تعديل أدوارك الثانوية') !== false
        && strpos($staffPresenter, ', true)') !== false
        && strpos($staffPage, 'protectSelfSuperAdmin') !== false,
    'password_reveal_is_single_account' => strpos($studentPage, "ajax/get_password.php") !== false && strpos($staffPage, "ajax/get_password.php") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
