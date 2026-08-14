<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$query = (string) file_get_contents($root . '/src/Modules/Students/StudentListPageQuery.php');
$dataTableQuery = (string) file_get_contents($root . '/src/Modules/Students/StudentListDataTableQuery.php');
$view = (string) file_get_contents($root . '/src/Modules/Students/Presentation/list_view.php');
$dataTableEndpoint = (string) file_get_contents($root . '/admin/ajax_students_datatable.php');
$sharedDataTable = (string) file_get_contents($root . '/assets/js/admin-server-side-table.js');
$checks = [
    'page_delegates' => strpos($page, '$studentListPageQuery->load(') !== false
        && strpos($page, '$allowedStudentClassIds') !== false,
    'query_returns_students_offset' => strpos($query, "'students_offset' => \$offset") !== false
        && strpos($query, 'getStudentsPaginated(') !== false
        && strpos($query, '$allowedClassIds') !== false,
    'page_unpacks_students_offset' => strpos(
        $page,
        "\$students_offset = \$studentListData['students_offset'];"
    ) !== false,
    'async_datatable_shell' => strpos($query, "'students_use_datatables' => false") !== false
        && is_file($root . '/admin/ajax_students_datatable.php'),
    'activity_is_extracted_to_dedicated_page' => strpos($query, 'ActivityLog::countLogs') === false
        && strpos($view, 'tab-activity-log') === false
        && is_file($root . '/admin/student_operations.php')
        && is_file($root . '/src/Modules/Students/StudentOperationLogQuery.php'),
    'datatable_query_contract' => is_file($root . '/src/Modules/Students/StudentListDataTableQuery.php')
        && strpos((string) file_get_contents($root . '/src/Modules/Students/StudentListDataTableQuery.php'), 'recordsFiltered') !== false
        && strpos((string) file_get_contents($root . '/src/Modules/Students/StudentListDataTableQuery.php'), 'min(500, max(10, $requestedLength))') !== false
        && strpos((string) file_get_contents($root . '/src/Modules/Students/StudentListDataTableQuery.php'), 'private StudentListReadRepository $students') !== false
        && strpos((string) file_get_contents($root . '/src/Modules/Students/StudentListDataTableQuery.php'), 'private const COLUMN_FIELDS') !== false,
    'siblings_popover_contract' => strpos((string) file_get_contents($root . '/src/Modules/Students/Presentation/StudentListDataTablePresenter.php'), "data-bs-trigger=\"hover focus\"") !== false
        && strpos((string) file_get_contents($root . '/src/Modules/Students/Presentation/StudentListDataTablePresenter.php'), "['siblings_info']") !== false,
    'datatable_ui_contract' => strpos($view, "AdminServerSideTable.init") !== false
        && strpos($view, "ajax_students_datatable.php") !== false
        && strpos($view, "order: [[3, 'asc']]") !== false
        && strpos($view, 'aria-label="تنقل صفحات الطلاب"') === false
        && strpos($view, 'جاري تحميل الطلاب…') !== false
        && strpos($sharedDataTable, "serverSide: true") !== false
        && strpos($sharedDataTable, "type: 'POST'") !== false
        && strpos($sharedDataTable, 'data.csrf_token') !== false
        && strpos($sharedDataTable, "pageLength: 50") !== false
        && strpos($sharedDataTable, "[10, 25, 50, 100, 200, 500, -1]") !== false
        && strpos($sharedDataTable, "'الكل'") !== false
        && strpos($dataTableQuery, '$requestedLength === -1 ? PHP_INT_MAX') !== false,
    'visible_column_projection_contract' => strpos($dataTableQuery, "['visible_columns']") !== false
        && strpos($dataTableQuery, "preg_match('/^col-[a-z0-9-]+$/', \$column)") !== false
        && strpos($view, 'selectedOptionalColumns()') !== false
        && strpos($view, 'visible_columns: selectedOptionalColumns()') !== false
        && strpos($view, "settingsModal.addEventListener('hidden.bs.modal'") !== false
        && strpos((string) file_get_contents($root . '/src/Modules/Students/Presentation/StudentListDataTablePresenter.php'), 'projectVisibleCells') !== false,
    'datatable_endpoint_security_contract' => strpos($dataTableEndpoint, "require_once '../includes/csrf.php';") !== false
        && strpos($dataTableEndpoint, "Utilities::validateSession('admin');") !== false
        && strpos($dataTableEndpoint, 'requireCsrfPost();') !== false
        && strpos($dataTableEndpoint, 'new StudentListReadRepository($db)') !== false
        && strpos($dataTableEndpoint, '))->load(') !== false
        && strpos($dataTableEndpoint, '$_POST,') !== false,
    'class_grade_queries' => strpos($query, 'ORDER BY s.id, g.id, c.name') !== false
        && strpos($query, 'ORDER BY s.stage_order, g.grade_order, g.id') !== false,
    'dedicated_activity_contract' => strpos(
        (string) file_get_contents($root . '/src/Modules/Students/StudentOperationLogQuery.php'),
        'LEFT JOIN undo_log ul ON ul.id = al.undo_log_id'
    ) !== false,
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
