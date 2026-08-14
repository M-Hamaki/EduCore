<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$staffPage = (string)file_get_contents($root . '/admin/staff_accounts.php');
$studentPage = (string)file_get_contents($root . '/admin/student_accounts.php');
$staffBulkUi = (string)file_get_contents($root . '/includes/staff_bulk_modals.php');
$accountTableQuery = (string)file_get_contents($root . '/classes/AccountListDataTableQuery.php');
$rolePageCatalog = (string)file_get_contents($root . '/classes/AdminRolePageCatalog.php');
$bulkDownload = (string)file_get_contents($root . '/admin/ajax/download_bulk_credentials.php');

$assertContains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($message . ': ' . $needle);
    }
};

foreach ([
    'id="staffBulkActionBar"',
    'class="form-check-input select-all-page"',
    "openBulkStaffRoleModal()",
    "openBulkStaffSupervisorModal()",
    "openBulkStaffActionModal('activate')",
    "openBulkStaffActionModal('deactivate')",
    "openBulkStaffActionModal('generate_credentials')",
    "openBulkStaffActionModal('reset_passwords')",
    "openBulkStaffActionModal('export_credentials')",
    'id="bulkRolePermissionsBar"',
] as $requiredStaffControl) {
    $assertContains($staffPage, $requiredStaffControl, 'Staff bulk control is missing');
}

foreach ([
    'id="studentBulkActionBar"',
    'class="form-check-input select-all-page"',
    "openBulkStudentModal('activate')",
    "openBulkStudentModal('deactivate')",
    "openBulkStudentModal('set_test')",
    "openBulkStudentModal('set_official')",
    "openBulkStudentModal('generate_credentials')",
    "openBulkStudentModal('reset_passwords')",
    "openBulkStudentModal('export_credentials')",
    'name="disable_reason"',
    'id="bulkDisableReason"',
    "disable_reason: disableReason",
    'تنفيذ كامل أو لا شيء',
] as $requiredStudentControl) {
    $assertContains($studentPage, $requiredStudentControl, 'Student bulk control is missing');
}
if (str_contains($studentPage, 'id="bulkErrSkip"')) {
    throw new RuntimeException('Student account status batches must not offer partial skip mode');
}

$assertContains($staffBulkUi, "endpointUrl: 'ajax_bulk_staff_accounts.php'", 'Staff bulk endpoint is not wired');
$assertContains($staffBulkUi, "url: 'ajax_bulk_role_pages.php'", 'Role pages bulk endpoint is not wired');
$assertContains($staffBulkUi, 'scope_role_keys: scopedRoleKeys', 'All scoped roles must be submitted');
if (substr_count($accountTableQuery, 'class="form-check-input row-select-cb') < 2) {
    throw new RuntimeException('Student and staff DataTables must both render selectable rows');
}
if (str_contains($accountTableQuery, 'row-select-cb me-2')
    || substr_count($accountTableQuery, '(string)$number,') < 2) {
    throw new RuntimeException('Selection checkboxes and row numbers must render in separate columns');
}
$assertContains($studentPage, '{ targets: [0, 1, 12], orderable: false }', 'Student selection and sequence columns must not be sortable');
$assertContains($staffPage, '{ targets: [0, 1, 10], orderable: false }', 'Staff selection and sequence columns must not be sortable');
$assertContains($staffPage, '<th style="width: 40px;">#</th>', 'Role numbering must have a dedicated column');
foreach ([
    'ajax_bulk_student_accounts.php',
    'ajax_bulk_staff_accounts.php',
    'ajax_bulk_role_pages.php',
    'download_bulk_credentials.php',
] as $requiredDependency) {
    $assertContains($rolePageCatalog, $requiredDependency, 'Administrative role dependency is missing');
}
$assertContains($bulkDownload, "preg_match('/^[=+\\-@]/u'", 'Bulk CSV cells must neutralize formulas');
$assertContains($bulkDownload, "time() - 600", 'Bulk credential downloads must expire');

echo "ACCOUNT_BULK_UI_CONTRACT_TEST_PASSED\n";
