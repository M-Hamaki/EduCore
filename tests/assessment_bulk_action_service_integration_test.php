<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/AssessmentBulkActionService.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with(strtolower($databaseName), '_test')) {
    throw new RuntimeException('Assessment bulk integration test requires an isolated *_test database.');
}

$actor = $db->query("SELECT id, name, role FROM users WHERE status = 'active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assignment = $db->query("SELECT sga.*, ay.locked, ay.status AS year_status
    FROM subject_grade_assignments sga
    JOIN academic_years ay ON ay.id = sga.academic_year_id
    WHERE ay.status = 'active' AND COALESCE(ay.locked, 0) = 0
    ORDER BY sga.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$actor || !$assignment) {
    throw new RuntimeException('The isolated test fixture needs one active user and one writable subject assignment.');
}

$termId = (int) ($assignment['term_id'] ?? 0);
if ($termId <= 0) {
    $termStmt = $db->prepare('SELECT id FROM academic_terms WHERE academic_year_id = ? ORDER BY term_order, id LIMIT 1');
    $termStmt->execute([(int) $assignment['academic_year_id']]);
    $termId = (int) $termStmt->fetchColumn();
}
if ($termId <= 0) {
    throw new RuntimeException('The isolated test fixture needs one term in the assignment year.');
}

$_SESSION['user_id'] = (int) $actor['id'];
$_SESSION['name'] = (string) $actor['name'];
$_SESSION['role'] = (string) $actor['role'];

$insertScheme = static function (PDO $db, array $assignment, int $termId, string $name): int {
    $stmt = $db->prepare("INSERT INTO assessment_schemes
        (academic_year_id, term_id, subject_assignment_id, subject_id, stage_id, grade_id, name, total_grade, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 100, 'active', ?)");
    $stmt->execute([
        (int) $assignment['academic_year_id'],
        $termId,
        (int) $assignment['id'],
        (int) $assignment['subject_id'],
        $assignment['stage_id'] !== null ? (int) $assignment['stage_id'] : null,
        (int) $assignment['grade_id'],
        $name,
        (int) ($_SESSION['user_id'] ?? 0),
    ]);
    return (int) $db->lastInsertId();
};

$exists = static function (PDO $db, string $table, int $id): bool {
    $stmt = $db->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
};

$checks = [];
$prefix = 'bulk_contract_' . bin2hex(random_bytes(4));
$db->beginTransaction();
try {
    $freeId = $insertScheme($db, $assignment, $termId, $prefix . '_free');
    $blockedId = $insertScheme($db, $assignment, $termId, $prefix . '_blocked');
    $deleteId = $insertScheme($db, $assignment, $termId, $prefix . '_delete');

    $componentStmt = $db->prepare("INSERT INTO assessment_components
        (scheme_id, name, component_type, max_grade, counts_in_total, is_active)
        VALUES (?, ?, 'custom', 100, 1, 1)");
    $componentStmt->execute([$blockedId, $prefix . '_component']);
    $componentId = (int) $db->lastInsertId();
    $windowStmt = $db->prepare("INSERT INTO assessment_windows
        (scheme_id, component_id, grade_id, window_name, status, opened_by)
        VALUES (?, ?, ?, ?, 'closed', ?)");
    $windowStmt->execute([$blockedId, $componentId, (int) $assignment['grade_id'], $prefix . '_window', (int) $actor['id']]);

    $service = new AssessmentBulkActionService($db);
    try {
        $service->execute('scheme', 'delete', [$freeId, $blockedId], (int) $assignment['academic_year_id']);
        $checks['blocked_batch_is_rejected'] = false;
    } catch (RuntimeException $e) {
        $checks['blocked_batch_is_rejected'] = str_contains($e->getMessage(), 'الحذف ذري');
    }
    $checks['blocked_batch_keeps_every_selected_row'] = $exists($db, 'assessment_schemes', $freeId)
        && $exists($db, 'assessment_schemes', $blockedId);

    $deactivate = $service->execute('scheme', 'deactivate', [$freeId, $blockedId], (int) $assignment['academic_year_id']);
    $statusStmt = $db->prepare('SELECT status FROM assessment_schemes WHERE id IN (?, ?) ORDER BY id');
    $statusStmt->execute([$freeId, $blockedId]);
    $checks['deactivate_updates_all_selected_rows'] = ($deactivate['affected'] ?? 0) === 2
        && array_unique($statusStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) === ['archived'];

    $delete = $service->execute('scheme', 'delete', [$deleteId], (int) $assignment['academic_year_id']);
    $checks['active_row_can_deactivate_and_delete_atomically'] = ($delete['affected'] ?? 0) === 1
        && !$exists($db, 'assessment_schemes', $deleteId);

    $batchStmt = $db->prepare("SELECT COUNT(DISTINCT batch_id) FROM activity_logs WHERE target_id IN (?, ?, ?) AND target_type = 'assessment_scheme'");
    $batchStmt->execute([$freeId, $blockedId, $deleteId]);
    $checks['audited_batches_have_explicit_batch_ids'] = (int) $batchStmt->fetchColumn() >= 2;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$undoSchemeId = 0;
$undoComponentId = 0;
$undoBatchId = null;
try {
    $db->beginTransaction();
    $undoSchemeId = $insertScheme($db, $assignment, $termId, $prefix . '_undo');
    $componentStmt->execute([$undoSchemeId, $prefix . '_undo_component']);
    $undoComponentId = (int) $db->lastInsertId();
    $db->commit();

    $deleteResult = (new AssessmentBulkActionService($db))->execute(
        'scheme',
        'delete',
        [$undoSchemeId],
        (int) $assignment['academic_year_id']
    );
    $undoBatchId = (string) ($deleteResult['batch_id'] ?? '');
    $undoStmt = $db->prepare('SELECT id FROM undo_log WHERE batch_id = ? ORDER BY id DESC LIMIT 1');
    $undoStmt->execute([$undoBatchId]);
    $undoId = (int) $undoStmt->fetchColumn();
    $undoResult = UndoManager::undo((int) $actor['id'], $undoId);

    $statusStmt = $db->prepare('SELECT status FROM assessment_schemes WHERE id = ?');
    $statusStmt->execute([$undoSchemeId]);
    $checks['batch_undo_restores_parent_child_and_status'] = ($undoResult['success'] ?? false)
        && $statusStmt->fetchColumn() === 'active'
        && $exists($db, 'assessment_components', $undoComponentId);
    if (!$checks['batch_undo_restores_parent_child_and_status']) {
        echo 'batch_undo_diagnostic:' . json_encode($undoResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($undoSchemeId > 0) {
        $db->prepare('DELETE FROM assessment_schemes WHERE id = ?')->execute([$undoSchemeId]);
    }
    if ($undoBatchId) {
        $db->prepare('DELETE FROM activity_logs WHERE batch_id = ?')->execute([$undoBatchId]);
        $db->prepare('DELETE FROM undo_log WHERE batch_id = ?')->execute([$undoBatchId]);
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
