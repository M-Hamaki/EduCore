<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/SystemActivityLogQuery.php';

function systemUndoAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "system_activity_log_undo_query_test: SKIPPED (pdo_sqlite unavailable)\n");
    exit(0);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE activity_logs (
        id INTEGER PRIMARY KEY,
        action TEXT NOT NULL,
        target_type TEXT NULL,
        target_name TEXT NULL,
        user_name TEXT NULL,
        details TEXT NULL,
        undo_log_id INTEGER NULL,
        created_at TEXT NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE undo_log (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        action_type TEXT NOT NULL,
        table_name TEXT NOT NULL,
        description TEXT NULL,
        batch_id TEXT NULL,
        can_undo INTEGER NOT NULL DEFAULT 1,
        is_undone INTEGER NOT NULL DEFAULT 0,
        undo_status TEXT NOT NULL DEFAULT \'pending\',
        failure_reason TEXT NULL,
        undone_by INTEGER NULL,
        undone_at TEXT NULL
    )'
);

$db->exec("INSERT INTO undo_log VALUES
    (10, 1, 'update', 'students', 'available', NULL, 1, 0, 'pending', NULL, NULL, NULL),
    (11, 1, 'delete', 'students', 'completed', NULL, 1, 1, 'completed', NULL, 2, '2026-08-09 10:00:00'),
    (12, 1, 'update', 'settings', 'policy blocked', NULL, 0, 0, 'failed', 'policy', NULL, NULL)");
$db->exec("INSERT INTO activity_logs (id, action, target_type, target_name, undo_log_id, created_at) VALUES
    (1, 'update', 'student', 'Student One', 10, '2026-08-09 09:00:00'),
    (2, 'delete', 'student', 'Student Two', 11, '2026-08-09 09:10:00'),
    (3, 'view', 'student', 'Student Three', NULL, '2026-08-09 09:20:00'),
    (4, 'update', 'setting', 'Setting', 12, '2026-08-09 09:30:00'),
    (5, 'update', 'student_mark', 'Student Mark', NULL, '2026-08-09 09:40:00'),
    (6, 'evaluation_update', 'evaluation', 'Student Evaluation', NULL, '2026-08-09 09:50:00'),
    (7, 'status', 'student_account', 'Student Account', NULL, '2026-08-09 10:00:00'),
    (8, 'undo', 'student', 'Student Two', 11, '2026-08-09 10:10:00'),
    (9, 'redo', 'student', 'Student Two', 11, '2026-08-09 10:20:00'),
    (10, 'create', 'staff_permission_requests', 'Staff request', NULL, '2026-08-09 10:30:00')");

$query = new SystemActivityLogQuery($db);
$rows = $query->enrich($db->query('SELECT * FROM activity_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));

systemUndoAssert(count($rows) === 10, 'All system activity rows must remain visible.');
systemUndoAssert(
    array_slice(array_column($rows, 'target_type'), 4, 3) === ['student_mark', 'evaluation', 'student_account'],
    'Grades, evaluations, and account events must remain visible in the unified system log.'
);
systemUndoAssert(SystemActivityLogQuery::undoState($rows[0]) === 'available', 'Pending linked entry must be available.');
systemUndoAssert(SystemActivityLogQuery::undoState($rows[1]) === 'completed', 'Completed entry must be reported as completed.');
systemUndoAssert(SystemActivityLogQuery::undoState($rows[2]) === 'unavailable', 'Unlinked entry must be unavailable.');
systemUndoAssert(SystemActivityLogQuery::undoState($rows[3]) === 'unavailable', 'Policy-blocked entry must be unavailable.');
systemUndoAssert($query->findUndoableOperation(1, 10) !== null, 'Exact pending activity/undo pair must be accepted.');
systemUndoAssert($query->findUndoableOperation(1, 11) === null, 'Mismatched activity/undo pair must be rejected.');
systemUndoAssert($query->findUndoableOperation(2, 11) === null, 'Completed undo entry must be rejected.');
systemUndoAssert($query->findRedoableOperation(2, 11) !== null, 'Exact completed activity/undo pair must be accepted for redo.');
systemUndoAssert($query->findRedoableOperation(8, 11) === null, 'Undo audit events must never become redo anchors.');
systemUndoAssert(SystemActivityLogQuery::undoState($rows[7]) === 'unavailable', 'Undo audit events must remain immutable audit rows.');
systemUndoAssert(SystemActivityLogQuery::undoState($rows[8]) === 'unavailable', 'Redo audit events must remain immutable audit rows.');
$activeRows = $query->load([], 'active', 50, 0);
$undoneRows = $query->load([], 'undone', 50, 0);
systemUndoAssert($activeRows['total'] === 9, 'Active tab must keep all rows except the completed original operation.');
systemUndoAssert($undoneRows['total'] === 1 && (int) $undoneRows['rows'][0]['id'] === 2, 'Undone tab must show only the completed original operation.');
$staffRows = $query->load(['target_type_prefix' => 'staff_'], 'active', 50, 0);
$invalidPrefixRows = $query->load(['target_type_prefix' => 'staff_%'], 'active', 50, 0);
$operationalSearchRows = $query->load(['target_type_prefix' => 'staff_', 'operational_search' => true, 'search' => 'Staff request'], 'active', 50, 0);
systemUndoAssert(
    $staffRows['total'] === 1 && (int) $staffRows['rows'][0]['id'] === 10,
    'A bounded resource prefix can isolate Staff audit events without broadening the query.'
);
systemUndoAssert($invalidPrefixRows['total'] === 0, 'An invalid resource prefix fails closed instead of becoming a wildcard audit query.');
systemUndoAssert($operationalSearchRows['total'] === 0, 'Operational audit search does not inspect a resource display name or serialized details.');
systemUndoAssert(
    SystemActivityLogQuery::undoReason($rows[0], false) === 'يتطلب التراجع الشامل صلاحية المدير العام',
    'Available entries must remain disabled for non-super-admin roles.'
);

fwrite(STDOUT, "system_activity_log_undo_query_test: OK\n");
