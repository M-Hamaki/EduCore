<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/student_operations.php');
$students = (string) file_get_contents($root . '/admin/students.php');
$listView = (string) file_get_contents($root . '/src/Modules/Students/Presentation/list_view.php');
$listQuery = (string) file_get_contents($root . '/src/Modules/Students/StudentListPageQuery.php');
$query = (string) file_get_contents($root . '/src/Modules/Students/StudentOperationLogQuery.php');
$catalog = (string) file_get_contents($root . '/classes/AdminRolePageCatalog.php');
$header = (string) file_get_contents($root . '/includes/admin_header.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260808_add_student_operations_page_permission.php');
$yearContextMigration = (string) file_get_contents($root . '/database/migrations/20260809_activity_log_academic_year_context.php');
$activityLog = (string) file_get_contents($root . '/classes/ActivityLog.php');
$script = (string) file_get_contents($root . '/assets/js/student-operations.js');

$specialistBlock = strstr($catalog, 'self::SPECIALIST => [');
$specialistBlock = is_string($specialistBlock) ? strstr($specialistBlock, 'self::DOCTOR => [', true) : '';
$studentAffairsBlock = strstr($catalog, 'self::STUDENT_AFFAIRS_MANAGER => [');
$studentAffairsBlock = is_string($studentAffairsBlock) ? strstr($studentAffairsBlock, 'self::TRANSPORT_MANAGER => [', true) : '';

$checks = [
    'student_list_no_longer_owns_activity_tab' => strpos($listView, 'tab-activity-log') === false
        && strpos($listView, 'main_tab=activity_log') === false
        && strpos($students, "['activity_logs']") === false
        && strpos($listQuery, 'ActivityLog::getLogs') === false,
    'students_page_no_longer_links_to_dedicated_log' => strpos($students, 'href="student_operations.php"') === false,
    'page_authenticates_before_post_and_requires_csrf' => strpos($page, "Utilities::validateSession('admin');") !== false
        && strpos($page, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')") > strpos($page, "Utilities::validateSession('admin');")
        && strpos($page, 'requireCsrfPost();') !== false,
    'page_uses_owned_query_and_shared_undo_manager' => strpos($page, 'new StudentOperationLogQuery($db, $currentAcademicYearId)') !== false
        && strpos($page, '$operationQuery->findUndoableOperation($activityId, $undoId)') !== false
        && strpos($page, '$operationQuery->findRedoableOperation($activityId, $undoId)') !== false
        && strpos($page, 'UndoManager::undo(') !== false
        && strpos($page, 'UndoManager::redo(') !== false,
    'selected_header_year_scopes_list_stats_and_reversible_actions' => strpos($page, 'AcademicYear::getCurrent($db)') !== false
        && strpos($query, 'al.academic_year_id = ?') !== false
        && substr_count($query, '$this->scopeWhere()') >= 5
        && strpos($activityLog, "\$_SESSION['academic_year_id']") !== false
        && strpos($activityLog, 'academic_year_id,') !== false
        && strpos($yearContextMigration, 'idx_activity_academic_year') !== false
        && strpos($yearContextMigration, 'student_enrollments') !== false
        && strpos($yearContextMigration, 'student_change_requests') !== false,
    'undo_requires_exact_activity_snapshot_pair' => strpos($query, 'al.id = ?') !== false
        && strpos($query, 'ul.id = ?') !== false
        && strpos($query, "ul.undo_status = 'pending'") !== false,
    'all_student_owned_types_and_routes_are_scoped' => strpos($query, 'OWNED_TARGET_TYPES') !== false
        && strpos($query, 'OWNED_ROUTES') !== false
        && strpos($query, "'student_enrollment'") !== false
        && strpos($query, "'attendance_class_day'") !== false
        && strpos($query, "'student_change_request'") !== false,
    'grades_evaluations_and_accounts_are_excluded_from_student_affairs_log' => strpos($query, 'EXCLUDED_TARGET_TYPES') !== false
        && strpos($query, 'COALESCE(al.target_type') !== false
        && strpos($query, 'NOT IN (') !== false
        && strpos($query, 'COALESCE(al.action') !== false
        && strpos($query, 'NOT LIKE ?') !== false
        && strpos($query, "'evaluation_%'") !== false
        && strpos($query, "'student_accounts.php'") === false
        && strpos($query, "'ajax_student_accounts_datatable.php'") === false
        && strpos($query, "'ajax_bulk_student_accounts.php'") === false,
    'every_row_renders_an_undo_control_with_safe_disabled_state' => strpos($page, 'student-operation-undo') !== false
        && strpos($page, 'disabled aria-disabled="true"') !== false
        && strpos($page, 'StudentOperationLogQuery::undoReason') !== false,
    'undone_operations_have_a_dedicated_redo_tab' => strpos($page, 'العمليات المتراجع عنها') !== false
        && strpos($page, "['log_tab'] = 'undone'") !== false
        && strpos($page, 'student-operation-redo') !== false
        && strpos($query, "\$filters['tab'] === 'undone'") !== false,
    'operation_rows_use_business_wording_and_collapsed_technical_details' => strpos($page, 'ما الذي حدث؟') !== false
        && strpos($page, 'StudentOperationLogQuery::operationPresentation($row)') !== false
        && strpos($page, 'عرض التفاصيل الفنية') !== false
        && strpos($page, "formatDetailsHtml(\$details, 'diff_table')") !== false
        && strpos($page, "formatDetailsHtml(\$details, 'inline')") === false
        && strpos($page, "['target_id']") === false,
    'no_change_attempts_are_not_shown_as_student_operations' => strpos($query, "no_reversible_field_changes") !== false
        && strpos($query, 'enrichPresentationContext($rows)') !== false
        && strpos($query, 'تمت مزامنة بيانات القيد السنوي') !== false,
    'undo_uses_bootstrap_confirmation_not_browser_confirm' => strpos($page, 'undoStudentOperationModal') !== false
        && strpos($page, 'redoStudentOperationModal') !== false
        && strpos($script, 'bootstrap.Modal.getOrCreateInstance') !== false
        && strpos($page, 'confirm(') === false
        && strpos($page, 'Swal') === false,
    'student_affairs_role_gets_page_but_specialist_does_not' => strpos((string) $studentAffairsBlock, "'student_operations.php'") !== false
        && strpos((string) $specialistBlock, "'student_operations.php'") === false
        && strpos($migration, "['student_affairs_manager', 'student_operations.php']") !== false,
    'sidebar_exposes_dedicated_page' => substr_count($header, 'student_operations.php') >= 4,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
