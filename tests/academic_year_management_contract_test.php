<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/academic_years.php');
$service = (string) file_get_contents($root . '/classes/AcademicYear.php');

$checks = [
    'list_page_uses_batched_year_overview' => strpos($page, 'AcademicYear::countEnrollmentsByYear') !== false
        && strpos($page, 'AcademicYear::getDeletionAssessments') !== false,
    'service_batches_enrollment_and_reference_counts' => strpos($service, 'public static function countEnrollmentsByYear') !== false
        && strpos($service, 'public static function getDeletionAssessments') !== false
        && strpos($service, 'private static function countReferencesByYear') !== false,
    'delete_request_is_post_and_csrf_protected' =>
        strpos($page, "isset(\$_POST['delete_academic_year'])") !== false
        && strpos($page, '$csrfToken === \'\'') !== false
        && strpos($page, '$sessionCsrfToken === \'\'') !== false
        && strpos($page, 'hash_equals($sessionCsrfToken, $csrfToken)') !== false,
    'delete_request_delegates_to_audited_owner' =>
        strpos($page, 'AcademicYear::delete($db, $yearId)') !== false
        && strpos($service, "recordDelete(\n                'academic_year'") !== false,
    'delete_requires_exact_year_name' =>
        strpos($page, "hash_equals((string)\$year['name'], \$confirmName)") !== false
        && strpos($page, 'id="deleteYearConfirmName"') !== false,
    'delete_is_atomic_and_row_locked' =>
        strpos($service, 'public static function delete(PDO $db, int $yearId)') !== false
        && strpos($service, "SELECT * FROM academic_years ORDER BY id FOR UPDATE") !== false
        && strpos($service, '$ownsTransaction = !$db->inTransaction()') !== false,
    'active_locked_and_only_year_are_protected' =>
        substr_count($service, 'لا يمكن حذف العام الدراسي النشط') >= 2
        && substr_count($service, 'لا يمكن حذف عام دراسي مقفل') >= 2
        && substr_count($service, 'لا يمكن حذف العام الدراسي الوحيد') >= 2,
    'all_foreign_key_references_are_preflighted' =>
        strpos($service, "REFERENCED_TABLE_NAME = 'academic_years'") !== false
        && strpos($service, 'private static function countReferences') !== false
        && strpos($service, 'لا يمكن حذف العام لوجود بيانات مرتبطة به') !== false,
    'page_uses_unified_heading_and_list_surface' =>
        strpos($page, 'class="admin-page-heading"') !== false
        && strpos($page, 'class="admin-filter-bar"') !== false
        && strpos($page, 'class="admin-list-surface"') !== false
        && strpos($page, 'admin-table-wrap') !== false
        && strpos($page, 'admin-data-table') !== false,
    'add_form_moved_to_standard_modal' =>
        strpos($page, 'id="addAcademicYearModal"') !== false
        && strpos($page, 'admin-modal-create') !== false
        && strpos($page, 'data-bs-target="#addAcademicYearModal"') !== false
        && strpos($page, 'name="add_academic_year"') !== false,
    'table_actions_use_semantic_pills' =>
        strpos($page, 'btn-action-pills btn-edit') !== false
        && strpos($page, 'btn-action-pills btn-activate') !== false
        && strpos($page, 'btn-action-pills btn-deactivate') !== false
        && strpos($page, 'btn-action-pills btn-delete') !== false,
    'delete_uses_bootstrap_modal_without_browser_dialogs' =>
        strpos($page, 'id="deleteAcademicYearModal"') !== false
        && strpos($page, 'admin-modal-delete') !== false
        && strpos($page, 'name="delete_academic_year"') !== false
        && strpos($page, 'confirm(') === false
        && strpos($page, 'Swal') === false,
    'page_has_no_local_css' => strpos($page, '<style') === false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
