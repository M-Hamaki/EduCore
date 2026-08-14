<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/Students/StudentOperationLogQuery.php';

use EduCore\Modules\Students\StudentOperationLogQuery;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP: sqlite driver is unavailable.\n";
    exit(0);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    user_name TEXT,
    user_role TEXT,
    action TEXT,
    target_type TEXT,
    target_id INTEGER,
    target_name TEXT,
    details TEXT,
    ip_address TEXT,
    academic_year_id INTEGER,
    request_id TEXT,
    batch_id TEXT,
    result TEXT,
    route TEXT,
    user_agent TEXT,
    undo_log_id INTEGER,
    created_at TEXT
)');
$db->exec('CREATE TABLE undo_log (
    id INTEGER PRIMARY KEY,
    user_id INTEGER,
    action_type TEXT,
    table_name TEXT,
    record_id INTEGER,
    description TEXT,
    batch_id TEXT,
    can_undo INTEGER,
    is_undone INTEGER,
    undo_status TEXT,
    failure_reason TEXT,
    undone_by INTEGER,
    undone_at TEXT
)');

$db->exec("INSERT INTO undo_log (id, user_id, action_type, table_name, record_id, description, batch_id, can_undo, is_undone, undo_status, failure_reason, undone_by, undone_at)
    VALUES
    (10, 1, 'update', 'users', 101, 'تعديل طالب', NULL, 1, 0, 'pending', NULL, NULL, NULL),
    (11, 1, 'update', 'users', 102, 'تعديل طالب', NULL, 1, 1, 'completed', NULL, 2, '2026-08-09 10:00:00'),
    (12, 1, 'update', 'student_enrollments', 103, 'فحص قيد دون تغيير', NULL, 0, 0, 'pending', 'no_reversible_field_changes', NULL, NULL)");

$insert = $db->prepare('INSERT INTO activity_logs
    (user_id, user_name, user_role, action, target_type, target_id, target_name, details, route, undo_log_id, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute([1, 'مدير', 'admin', 'update', 'student', 101, 'طالب أول', '{}', '/EduCore/admin/students.php', 10, '2026-08-08 10:00:00']);
$insert->execute([1, 'مدير', 'admin', 'update', 'student_profile', 102, 'طالب ثان', '{}', '/EduCore/admin/students.php', 11, '2026-08-08 11:00:00']);
$insert->execute([1, 'مدير', 'admin', 'settings', 'settings', null, 'student_completeness_fields_v2', '{}', '/EduCore/admin/student_data_completeness.php', null, '2026-08-08 12:00:00']);
$insert->execute([1, 'مدير', 'admin', 'update', 'academic_year_student_sync', 1, 'مزامنة طلاب العام', '{}', '/EduCore/admin/academic_years.php', null, '2026-08-08 12:30:00']);
$insert->execute([1, 'مدير', 'admin', 'link', 'sibling', 103, 'اعتماد أشقاء', '{}', '/EduCore/admin/activity_logs.php', null, '2026-08-08 12:45:00']);
$insert->execute([1, 'مدير', 'admin', 'update', 'staff_profile', 500, 'موظف', '{}', '/EduCore/admin/staff.php', null, '2026-08-08 13:00:00']);
$insert->execute([1, 'مدير', 'admin', 'update', 'student_mark', 101, 'درجة طالب', '{}', '/EduCore/admin/class_lists.php', null, '2026-08-08 13:10:00']);
$insert->execute([1, 'مدير', 'admin', 'status', 'student_account', 101, 'حساب طالب', '{}', '/EduCore/admin/students.php', null, '2026-08-08 13:20:00']);
$insert->execute([1, 'مدير', 'admin', 'lock', 'assessment_student_lock', 101, 'قفل رصد', '{}', '/EduCore/admin/students.php', null, '2026-08-08 13:30:00']);
$insert->execute([1, 'مدير', 'admin', 'update', 'evaluation', 101, 'تقييم طالب', '{}', '/EduCore/admin/students.php', null, '2026-08-08 13:40:00']);
$insert->execute([1, 'مدير', 'admin', 'evaluation_bulk_delete_noop', 'student', 101, 'محاولة تقييم', '{}', '/EduCore/includes/ajax_handlers.php', null, '2026-08-08 13:50:00']);
$insert->execute([1, 'مدير', 'admin', 'undo', 'student_profile', 102, 'حدث تراجع', '{}', '/EduCore/admin/student_operations.php', 11, '2026-08-08 14:00:00']);
$insert->execute([1, 'مدير', 'admin', 'redo', 'student_profile', 102, 'حدث إعادة عمل', '{}', '/EduCore/admin/student_operations.php', 11, '2026-08-08 14:10:00']);
$insert->execute([1, 'مدير', 'admin', 'update', 'student_enrollment', 103, 'قيد طالب #103', '{"changes":[]}', '/EduCore/admin/students.php', 12, '2026-08-08 14:20:00']);
$insert->execute([1, 'مدير', 'admin', 'update', 'student', 999, 'طالب عام آخر', '{}', '/EduCore/admin/students.php', 10, '2026-08-08 15:00:00']);
$db->exec('UPDATE activity_logs SET academic_year_id = 1');
$db->exec("UPDATE activity_logs SET academic_year_id = 2 WHERE target_name = 'طالب عام آخر'");

$query = new StudentOperationLogQuery($db, 1);
$otherYearQuery = new StudentOperationLogQuery($db, 2);
$all = $query->load([]);
$otherYear = $otherYearQuery->load([]);
$undone = $query->load(['log_tab' => 'undone']);
$legacyCompletedFilter = $query->load(['undo_state' => 'completed']);
$available = $query->load(['undo_state' => 'available']);
$reverseDates = $query->load(['log_from' => '2026-08-09', 'log_to' => '2026-08-08']);
$enrollmentPresentation = StudentOperationLogQuery::operationPresentation([
    'id' => 90,
    'action' => 'update',
    'target_type' => 'student_enrollment',
    'target_id' => 520,
    'target_name' => 'قيد طالب #529',
    'details' => '{"changes":{"enrollment_status":{"from":"enrolled","to":"transferred"}}}',
    'display_student_id' => 529,
    'display_student_name' => 'أحمد محمد',
    'display_student_code' => 'S2026529',
    'display_academic_year_name' => '2025-2026',
]);
$syncPresentation = StudentOperationLogQuery::operationPresentation([
    'id' => 91,
    'action' => 'update',
    'target_type' => 'academic_year_student_sync',
    'target_id' => 1,
    'target_name' => 'مزامنة طلاب العام #1',
    'details' => '{"student_count":1246}',
    'display_academic_year_name' => '2025-2026',
]);
$undoStatesByTarget = [];
foreach ($all['rows'] as $row) {
    $undoStatesByTarget[(string) $row['target_name']] = StudentOperationLogQuery::undoState($row);
}
foreach ($undone['rows'] as $row) {
    $undoStatesByTarget[(string) $row['target_name']] = StudentOperationLogQuery::undoState($row);
}

$checks = [
    'student_scope_excludes_unrelated_staff_logs' => $all['total'] === 6 && count($all['rows']) === 6,
    'selected_academic_year_scopes_rows_stats_and_undo' => !in_array('طالب عام آخر', array_column($all['rows'], 'target_name'), true)
        && $otherYear['total'] === 1
        && array_column($otherYear['rows'], 'target_name') === ['طالب عام آخر']
        && $query->findUndoableOperation(15, 10) === null,
    'student_scope_excludes_grades_evaluations_and_accounts' => array_intersect(
        array_column($all['rows'], 'target_name'),
        ['درجة طالب', 'حساب طالب', 'قفل رصد', 'تقييم طالب', 'محاولة تقييم']
    ) === [],
    'excluded_domains_do_not_appear_in_filter_options' => !isset($all['type_options']['student_mark'])
        && !isset($all['type_options']['student_account'])
        && !isset($all['type_options']['assessment_student_lock'])
        && !isset($all['type_options']['evaluation'])
        && !isset($all['action_options']['evaluation_bulk_delete_noop']),
    'all_student_routes_and_owned_types_are_included' => array_column($all['rows'], 'target_name') === [
        'حدث إعادة عمل',
        'حدث تراجع',
        'اعتماد أشقاء',
        'مزامنة طلاب العام',
        'student_completeness_fields_v2',
        'طالب أول',
    ],
    'undone_tab_contains_only_completed_original_operations' => $undone['total'] === 1
        && array_column($undone['rows'], 'target_name') === ['طالب ثان']
        && $legacyCompletedFilter['filters']['tab'] === 'undone'
        && $legacyCompletedFilter['total'] === 1,
    'no_change_attempts_are_hidden_from_student_affairs_log' => !in_array('قيد طالب #103', array_column($all['rows'], 'target_name'), true)
        && $all['stats']['total'] === 7,
    'student_enrollment_wording_uses_student_and_business_context' => $enrollmentPresentation['subject'] === 'أحمد محمد · S2026529'
        && strpos($enrollmentPresentation['summary'], 'تم تحديث القيد السنوي للطالب') !== false
        && strpos($enrollmentPresentation['context'], 'حالة القيد') !== false
        && strpos($enrollmentPresentation['technical_reference'], 'رقم الطالب #529') !== false,
    'bulk_sync_wording_explains_scope_and_count' => $syncPresentation['summary'] === 'تمت مزامنة بيانات القيد السنوي لـ 1,246 طالبًا'
        && $syncPresentation['subject'] === 'العام الدراسي 2025-2026'
        && strpos($syncPresentation['context'], 'حالات القيد') !== false,
    'undo_states_are_counted_without_overlap' => $all['stats'] === [
        'total' => 7,
        'available' => 1,
        'completed' => 1,
        'unavailable' => 5,
    ],
    'available_filter_returns_only_safe_pending_snapshot' => $available['total'] === 1
        && (int) $available['rows'][0]['undo_id'] === 10,
    'undo_lookup_requires_matching_student_activity_and_snapshot' => $query->findUndoableOperation(1, 10) !== null
        && $query->findUndoableOperation(1, 11) === null
        && $query->findUndoableOperation(6, 10) === null,
    'redo_lookup_requires_matching_completed_student_pair' => $query->findRedoableOperation(2, 11) !== null
        && $query->findRedoableOperation(2, 10) === null
        && $query->findRedoableOperation(1, 10) === null,
    'reversed_date_range_is_normalized' => $reverseDates['filters']['date_from'] === '2026-08-08'
        && $reverseDates['filters']['date_to'] === '2026-08-09',
    'completed_and_unlinked_rows_have_disabled_state' => ($undoStatesByTarget['اعتماد أشقاء'] ?? null) === 'unavailable'
        && ($undoStatesByTarget['طالب ثان'] ?? null) === 'completed'
        && ($undoStatesByTarget['حدث تراجع'] ?? null) === 'unavailable'
        && ($undoStatesByTarget['حدث إعادة عمل'] ?? null) === 'unavailable',
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
