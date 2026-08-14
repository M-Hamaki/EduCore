<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/assessment_marks.php');
$endpoint = (string) file_get_contents($root . '/admin/ajax_assessment_marks_datatable.php');
$query = (string) file_get_contents($root . '/classes/AssessmentMarkAdministrationQuery.php');
$service = (string) file_get_contents($root . '/classes/AssessmentMarkAdministrationService.php');
$windows = (string) file_get_contents($root . '/admin/assessment_windows.php');
$header = (string) file_get_contents($root . '/includes/admin_header.php');
$registry = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');
$adr = (string) file_get_contents($root . '/docs/architecture-decisions.md');

$checks = [
    'admin_page_authenticates_before_writes_and_uses_csrf' => strpos($page, "Utilities::validateSession('admin');") !== false
        && strpos($page, 'requireCsrfPost();') !== false
        && strpos($page, "require_once '../includes/admin_footer.php';") !== false,
    'central_page_exposes_full_academic_filters' => strpos($page, 'id="filterStage"') !== false
        && strpos($page, 'id="filterGrade"') !== false
        && strpos($page, 'id="filterClass"') !== false
        && strpos($page, 'id="filterSubject"') !== false
        && strpos($page, 'id="filterWindow"') !== false,
    'large_mark_list_is_server_paginated' => strpos($page, 'AdminServerSideTable.init') !== false
        && strpos($endpoint, 'AssessmentMarkAdministrationQuery') !== false
        && strpos($query, 'min($requestedLength, 500)') !== false,
    'server_side_table_is_not_auto_initialized_twice' => strpos($page, 'id="assessmentMarksTable" class="table table-hover table-striped datatable') === false
        && substr_count($page, 'AdminServerSideTable.init') === 1,
    'read_model_is_selected_year_scoped_and_supports_window_scope' => strpos($query, 'sm.academic_year_id = ?') !== false
        && strpos($query, "'window_id'") !== false
        && strpos($query, 'sm.scheme_id = ?') !== false
        && strpos($query, 'sm.component_id = ?') !== false,
    'mark_edits_are_reasoned_transactional_and_shared_audited' => strpos($service, 'function updateMark') !== false
        && strpos($service, 'beginTransaction()') !== false
        && strpos($service, "recordUpdate(\n                'student_mark'") !== false
        && strpos($service, 'insertDomainAudit') !== false,
    'mark_deletion_is_super_admin_only_atomic_and_undoable' => strpos($service, 'function deleteMarks') !== false
        && strpos($service, 'assertActorCanManage($actorId, $actorRole)') !== false
        && strpos($service, "recordDelete(\n                    'student_mark'") !== false
        && strpos($service, 'لم تُحذف كل الدرجات المحددة؛ أُلغي الحذف بالكامل') !== false,
    'window_override_preserves_marks_and_requires_exact_confirmation' => strpos($service, 'function deleteWindowPreservingMarks') !== false
        && strpos($service, 'hash_equals(trim((string) $window[\'window_name\'])') !== false
        && strpos($service, 'DELETE FROM assessment_windows WHERE id = ?') !== false
        && strpos($service, 'DELETE FROM student_marks WHERE') !== false
        && strpos($windows, 'value="super_admin_delete_window"') !== false
        && strpos($windows, 'حذف النافذة فقط') !== false,
    'regular_admin_cannot_see_super_admin_window_override' => strpos($windows, 'if ($isSuperAdmin)') !== false
        && strpos($windows, 'super-delete-window-btn') !== false
        && strpos($service, 'SystemAdministratorRoleService') !== false,
    'published_snapshots_are_explicitly_preserved' => strpos($page, 'نسخ التقارير المنشورة تبقى كما هي') !== false
        && strpos($windows, 'لن تتأثر التقارير المنشورة') !== false,
    'navigation_and_window_drilldown_are_present' => strpos($header, 'href="assessment_marks.php"') !== false
        && strpos($windows, 'assessment_marks.php?window_id=') !== false
        && strpos($page, 'assessment_marks_sheet.php') !== false,
    'shared_undo_policy_and_adr_are_registered' => strpos($registry, "'student_marks'") !== false
        && strpos($registry, "'assessment_windows'") !== false
        && strpos($adr, 'ADR-050') !== false,
    'no_forbidden_confirmation_ui' => strpos($page, 'confirm(') === false
        && strpos($page, 'Swal') === false
        && strpos($windows, 'confirm(') === false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
