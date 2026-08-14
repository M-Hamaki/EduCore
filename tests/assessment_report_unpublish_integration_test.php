<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/AssessmentEngine.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with(strtolower($databaseName), '_test')) {
    throw new RuntimeException('Assessment report unpublish test requires an isolated *_test database.');
}

$actor = $db->query("SELECT id, name, role FROM users WHERE status = 'active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$year = $db->query("SELECT id FROM academic_years WHERE status = 'active' AND COALESCE(locked, 0) = 0 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$actor || !$year) {
    throw new RuntimeException('The isolated test fixture needs one active user and one writable academic year.');
}

$termStmt = $db->prepare('SELECT id FROM academic_terms WHERE academic_year_id = ? ORDER BY term_order, id LIMIT 1');
$termStmt->execute([(int) $year['id']]);
$termId = (int) $termStmt->fetchColumn();
if ($termId <= 0) {
    throw new RuntimeException('The isolated test fixture needs one term in the writable year.');
}

$_SESSION['user_id'] = (int) $actor['id'];
$_SESSION['name'] = (string) $actor['name'];
$_SESSION['role'] = (string) $actor['role'];

$windowId = 0;
$emptyWindowId = 0;
$publishedReportId = 0;
$detailId = 0;
$batchId = null;
$checks = [];

try {
    $db->beginTransaction();
    $windowStmt = $db->prepare("INSERT INTO report_windows
        (academic_year_id, term_id, name, report_type, is_published, published_at, created_by)
        VALUES (?, ?, ?, 'monthly', 1, NOW(), ?)");
    $windowStmt->execute([(int) $year['id'], $termId, 'unpublish_contract_' . bin2hex(random_bytes(4)), (int) $actor['id']]);
    $windowId = (int) $db->lastInsertId();
    $windowStmt->execute([(int) $year['id'], $termId, 'unpublish_empty_' . bin2hex(random_bytes(4)), (int) $actor['id']]);
    $emptyWindowId = (int) $db->lastInsertId();

    $reportStmt = $db->prepare("INSERT INTO published_reports
        (report_window_id, student_id, academic_year_id, term_id, snapshot_json, total_grade, percentage, published_by)
        VALUES (?, ?, ?, ?, ?, 75, 75, ?)");
    $reportStmt->execute([$windowId, (int) $actor['id'], (int) $year['id'], $termId, '{"test":true}', (int) $actor['id']]);
    $publishedReportId = (int) $db->lastInsertId();

    $detailStmt = $db->prepare("INSERT INTO published_report_details
        (published_report_id, label, value_label, numeric_value, max_grade, sort_order)
        VALUES (?, 'اختبار', '75', 75, 100, 1)");
    $detailStmt->execute([$publishedReportId]);
    $detailId = (int) $db->lastInsertId();
    $db->commit();

    try {
        (new AssessmentEngine($db))->unpublishReportWindow($emptyWindowId);
        $checks['empty_window_is_rejected_without_state_change'] = false;
    } catch (RuntimeException $e) {
        $emptyStateStmt = $db->prepare('SELECT is_published FROM report_windows WHERE id = ?');
        $emptyStateStmt->execute([$emptyWindowId]);
        $checks['empty_window_is_rejected_without_state_change'] = str_contains($e->getMessage(), 'لا توجد نسخ منشورة')
            && (int) $emptyStateStmt->fetchColumn() === 1;
    }

    $result = (new AssessmentEngine($db))->unpublishReportWindow($windowId);
    $batchId = (string) ($result['batch_id'] ?? '');

    $windowStateStmt = $db->prepare('SELECT is_published, published_at, hidden_at FROM report_windows WHERE id = ?');
    $windowStateStmt->execute([$windowId]);
    $windowState = $windowStateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $checks['all_snapshots_and_details_are_removed'] = (int) ($result['deleted_reports'] ?? 0) === 1
        && (int) ($result['deleted_details'] ?? 0) === 1
        && !(bool) $db->query('SELECT EXISTS(SELECT 1 FROM published_reports WHERE id = ' . $publishedReportId . ')')->fetchColumn()
        && !(bool) $db->query('SELECT EXISTS(SELECT 1 FROM published_report_details WHERE id = ' . $detailId . ')')->fetchColumn();
    $checks['window_returns_to_unpublished_state'] = (int) ($windowState['is_published'] ?? 1) === 0
        && $windowState['published_at'] === null
        && !empty($windowState['hidden_at']);
    $checks['one_explicit_audit_batch_is_created'] = $batchId !== '';

    $undoStmt = $db->prepare('SELECT id FROM undo_log WHERE batch_id = ? ORDER BY id DESC LIMIT 1');
    $undoStmt->execute([$batchId]);
    $undoId = (int) $undoStmt->fetchColumn();
    $undoResult = UndoManager::undo((int) $actor['id'], $undoId);

    $windowStateStmt->execute([$windowId]);
    $restoredWindow = $windowStateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $checks['undo_restores_window_snapshot_and_details'] = ($undoResult['success'] ?? false)
        && (int) ($restoredWindow['is_published'] ?? 0) === 1
        && !empty($restoredWindow['published_at'])
        && empty($restoredWindow['hidden_at'])
        && (bool) $db->query('SELECT EXISTS(SELECT 1 FROM published_reports WHERE id = ' . $publishedReportId . ')')->fetchColumn()
        && (bool) $db->query('SELECT EXISTS(SELECT 1 FROM published_report_details WHERE id = ' . $detailId . ')')->fetchColumn();
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($windowId > 0) {
        $db->prepare('DELETE FROM report_windows WHERE id = ?')->execute([$windowId]);
    }
    if ($emptyWindowId > 0) {
        $db->prepare('DELETE FROM report_windows WHERE id = ?')->execute([$emptyWindowId]);
    }
    if ($batchId) {
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
