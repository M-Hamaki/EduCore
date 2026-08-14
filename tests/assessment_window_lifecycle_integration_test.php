<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/AssessmentWindowLifecycleService.php';
require_once dirname(__DIR__) . '/classes/AssessmentBulkActionService.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with(strtolower($databaseName), '_test')) {
    throw new RuntimeException('Assessment window lifecycle test requires an isolated *_test database.');
}

$actor = $db->query("SELECT id, name, role FROM users WHERE status = 'active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assignment = $db->query("SELECT sga.*, ay.locked, ay.status AS year_status
    FROM subject_grade_assignments sga
    JOIN academic_years ay ON ay.id = sga.academic_year_id
    WHERE ay.status = 'active' AND COALESCE(ay.locked, 0) = 0
    ORDER BY sga.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$actor || !$assignment) {
    throw new RuntimeException('The isolated fixture needs an active user and writable subject assignment.');
}

$termId = (int) ($assignment['term_id'] ?? 0);
if ($termId <= 0) {
    $termStmt = $db->prepare('SELECT id FROM academic_terms WHERE academic_year_id = ? ORDER BY term_order, id LIMIT 1');
    $termStmt->execute([(int) $assignment['academic_year_id']]);
    $termId = (int) $termStmt->fetchColumn();
}
if ($termId <= 0) {
    throw new RuntimeException('The isolated fixture needs a term in the writable year.');
}

$_SESSION['user_id'] = (int) $actor['id'];
$_SESSION['name'] = (string) $actor['name'];
$_SESSION['role'] = (string) $actor['role'];

$schemeId = 0;
$windowId = 0;
$mixedWindowId = 0;
$permissionId = 0;
$batchIds = [];
$checks = [];

try {
    $db->beginTransaction();
    $schemeStmt = $db->prepare("INSERT INTO assessment_schemes
        (academic_year_id, term_id, subject_assignment_id, subject_id, stage_id, grade_id, name, total_grade, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 100, 'active', ?)");
    $schemeStmt->execute([
        (int) $assignment['academic_year_id'], $termId, (int) $assignment['id'],
        (int) $assignment['subject_id'], $assignment['stage_id'] !== null ? (int) $assignment['stage_id'] : null,
        (int) $assignment['grade_id'], 'window_lifecycle_' . bin2hex(random_bytes(4)), (int) $actor['id'],
    ]);
    $schemeId = (int) $db->lastInsertId();

    $componentStmt = $db->prepare("INSERT INTO assessment_components
        (scheme_id, name, component_type, max_grade, counts_in_total, is_active)
        VALUES (?, 'Lifecycle component', 'custom', 100, 1, 1)");
    $componentStmt->execute([$schemeId]);
    $componentId = (int) $db->lastInsertId();

    $windowStmt = $db->prepare("INSERT INTO assessment_windows
        (scheme_id, component_id, grade_id, window_name, status, requires_review, opened_by)
        VALUES (?, ?, ?, ?, 'draft', 1, ?)");
    $windowStmt->execute([$schemeId, $componentId, (int) $assignment['grade_id'], 'Lifecycle window', (int) $actor['id']]);
    $windowId = (int) $db->lastInsertId();
    $windowStmt->execute([$schemeId, $componentId, (int) $assignment['grade_id'], 'Mixed draft window', (int) $actor['id']]);
    $mixedWindowId = (int) $db->lastInsertId();
    $db->commit();

    $service = new AssessmentWindowLifecycleService($db);
    try {
        $service->transition($windowId, 'locked', (int) $actor['id'], 'admin', 'قفزة غير صالحة');
        $checks['invalid_direct_lock_is_rejected'] = false;
    } catch (RuntimeException $e) {
        $checks['invalid_direct_lock_is_rejected'] = str_contains($e->getMessage(), 'لا يمكن نقل النافذة');
    }

    $opened = $service->transition($windowId, 'open', (int) $actor['id'], 'admin');
    $batchIds[] = $opened['batch_id'];
    $closed = $service->transition($windowId, 'closed', (int) $actor['id'], 'admin', 'انتهاء الرصد');
    $batchIds[] = $closed['batch_id'];
    $checks['draft_open_closed_flow_succeeds'] = $opened['old_status'] === 'draft'
        && $closed['new_status'] === 'closed';

    $markStmt = $db->prepare("INSERT INTO student_marks
        (student_id, scheme_id, component_id, week_slot, academic_year_id, term_id, subject_id, grade_id,
         value, mark_status, recorded_by, review_status)
        VALUES (?, ?, ?, 0, ?, ?, ?, ?, 80, 'present', ?, 'pending')");
    $markStmt->execute([
        (int) $actor['id'], $schemeId, $componentId, (int) $assignment['academic_year_id'], $termId,
        (int) $assignment['subject_id'], (int) $assignment['grade_id'], (int) $actor['id'],
    ]);
    $markId = (int) $db->lastInsertId();

    try {
        $service->transition($windowId, 'locked', (int) $actor['id'], 'admin', 'اعتماد قبل المراجعة');
        $checks['pending_review_blocks_final_lock'] = false;
    } catch (RuntimeException $e) {
        $checks['pending_review_blocks_final_lock'] = str_contains($e->getMessage(), 'قبل اكتمال المراجعة');
    }

    $db->prepare("UPDATE student_marks SET review_status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
        ->execute([(int) $actor['id'], $markId]);
    $locked = $service->transition($windowId, 'locked', (int) $actor['id'], 'admin', 'اكتملت المراجعة');
    $batchIds[] = $locked['batch_id'];
    $checks['approved_marks_allow_final_lock'] = $locked['new_status'] === 'locked';

    $undoStmt = $db->prepare('SELECT id FROM undo_log WHERE batch_id = ? ORDER BY id DESC LIMIT 1');
    $undoStmt->execute([$locked['batch_id']]);
    $undoResult = UndoManager::undo((int) $actor['id'], (int) $undoStmt->fetchColumn());
    $checks['lock_transition_is_undoable'] = ($undoResult['success'] ?? false)
        && $db->query('SELECT status FROM assessment_windows WHERE id = ' . $windowId)->fetchColumn() === 'closed';

    $lockedAgain = $service->transition($windowId, 'locked', (int) $actor['id'], 'admin', 'إعادة اعتماد نهائي');
    $batchIds[] = $lockedAgain['batch_id'];
    $futureClose = date('Y-m-d H:i:s', time() + 3600);
    try {
        $service->transition($windowId, 'open', (int) $actor['id'], 'admin', 'تصحيح', $futureClose);
        $checks['locked_reopen_without_permission_is_rejected'] = false;
    } catch (RuntimeException $e) {
        $checks['locked_reopen_without_permission_is_rejected'] = str_contains($e->getMessage(), 'ليس لديك صلاحية');
    }

    $permissionStmt = $db->prepare("INSERT INTO assessment_permissions
        (role_name, user_id, permission_key, scope_type, scope_id, is_allowed, created_by)
        VALUES ('admin', ?, 'reopen_window', 'scheme', ?, 1, ?)");
    $permissionStmt->execute([(int) $actor['id'], $schemeId, (int) $actor['id']]);
    $permissionId = (int) $db->lastInsertId();
    $reopened = $service->transition($windowId, 'open', (int) $actor['id'], 'admin', 'تصحيح درجة بعد الاعتماد', $futureClose);
    $batchIds[] = $reopened['batch_id'];
    $checks['authorized_locked_reopen_sets_new_deadline'] = $reopened['new_status'] === 'open'
        && (string) $db->query('SELECT closes_at FROM assessment_windows WHERE id = ' . $windowId)->fetchColumn() === $futureClose;

    try {
        (new AssessmentBulkActionService($db))->execute(
            'window', 'deactivate', [$windowId, $mixedWindowId], (int) $assignment['academic_year_id']
        );
        $checks['mixed_bulk_close_is_atomic_rejection'] = false;
    } catch (RuntimeException $e) {
        $statuses = $db->query("SELECT status FROM assessment_windows WHERE id IN ({$windowId}, {$mixedWindowId}) ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $checks['mixed_bulk_close_is_atomic_rejection'] = str_contains($e->getMessage(), 'حالات غير مفتوحة')
            && in_array('open', $statuses, true)
            && in_array('draft', $statuses, true);
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($permissionId > 0) {
        $db->prepare('DELETE FROM assessment_permissions WHERE id = ?')->execute([$permissionId]);
    }
    if ($schemeId > 0) {
        $db->prepare('DELETE FROM assessment_schemes WHERE id = ?')->execute([$schemeId]);
    }
    foreach (array_unique(array_filter($batchIds)) as $batchId) {
        $db->prepare('DELETE FROM activity_logs WHERE batch_id = ?')->execute([$batchId]);
        $db->prepare('DELETE FROM undo_log WHERE batch_id = ?')->execute([$batchId]);
    }
}

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
exit($failed === [] ? 0 : 1);
