<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/AssessmentMarkAdministrationService.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with(strtolower($databaseName), '_test')) {
    throw new RuntimeException('Assessment marks administration test requires an isolated *_test database.');
}

$requiredTables = ['users', 'user_role_assignments', 'student_marks', 'student_mark_audit', 'assessment_schemes', 'assessment_components', 'assessment_windows', 'academic_years', 'activity_logs', 'undo_log'];
$tableStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
foreach ($requiredTables as $requiredTable) {
    $tableStmt->execute([$requiredTable]);
    if ((int) $tableStmt->fetchColumn() !== 1) {
        echo "ASSESSMENT_MARKS_ADMIN_INTEGRATION_SKIPPED_SCHEMA_NOT_READY\n";
        exit(0);
    }
}

$actor = $db->query("SELECT u.id, u.name FROM users u
    JOIN user_role_assignments ura ON ura.user_id = u.id AND ura.role_key = 'super_admin' AND ura.status = 'active'
    WHERE u.status = 'active' ORDER BY u.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$mark = $db->query("SELECT sm.*, scheme.grade_id AS scheme_grade_id
    FROM student_marks sm
    JOIN assessment_schemes scheme ON scheme.id = sm.scheme_id
    JOIN academic_years ay ON ay.id = sm.academic_year_id
    WHERE COALESCE(ay.locked, 0) = 0 AND ay.status = 'active'
    ORDER BY sm.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$actor || !$mark) {
    echo "ASSESSMENT_MARKS_ADMIN_INTEGRATION_SKIPPED_NO_FIXTURE\n";
    exit(0);
}

$_SESSION['user_id'] = (int) $actor['id'];
$_SESSION['name'] = (string) $actor['name'];
$_SESSION['role'] = 'super_admin';
$_SESSION['active_role'] = 'super_admin';

$checks = [];
$db->beginTransaction();
try {
    $service = new AssessmentMarkAdministrationService($db);
    $windowName = 'اختبار إدارة الدرجات ' . bin2hex(random_bytes(4));
    $windowStmt = $db->prepare("INSERT INTO assessment_windows
        (scheme_id, component_id, week_id, grade_id, class_id, window_name, status, opened_by)
        VALUES (?, ?, ?, ?, ?, ?, 'closed', ?)");
    $windowStmt->execute([
        (int) $mark['scheme_id'],
        (int) $mark['component_id'],
        $mark['week_id'] !== null ? (int) $mark['week_id'] : null,
        (int) ($mark['grade_id'] ?? $mark['scheme_grade_id']),
        $mark['class_id_at_entry'] !== null ? (int) $mark['class_id_at_entry'] : null,
        $windowName,
        (int) $actor['id'],
    ]);
    $windowId = (int) $db->lastInsertId();

    try {
        $service->deleteWindowPreservingMarks(
            $windowId,
            (int) $mark['academic_year_id'],
            (int) $actor['id'],
            'admin',
            'محاولة بدور إداري عادي',
            $windowName
        );
        $checks['ordinary_admin_cannot_override_window_delete'] = false;
    } catch (RuntimeException $error) {
        $checks['ordinary_admin_cannot_override_window_delete'] = str_contains($error->getMessage(), 'مدير النظام الأعلى');
    }

    $windowDelete = $service->deleteWindowPreservingMarks(
        $windowId,
        (int) $mark['academic_year_id'],
        (int) $actor['id'],
        'super_admin',
        'حذف نافذة اختبار مع حفظ الدرجات الأصلية',
        $windowName
    );
    $windowExistsStmt = $db->prepare('SELECT COUNT(*) FROM assessment_windows WHERE id = ?');
    $windowExistsStmt->execute([$windowId]);
    $markExistsStmt = $db->prepare('SELECT COUNT(*) FROM student_marks WHERE id = ?');
    $markExistsStmt->execute([(int) $mark['id']]);
    $checks['super_admin_window_delete_preserves_mark'] = (int) $windowExistsStmt->fetchColumn() === 0
        && (int) $markExistsStmt->fetchColumn() === 1
        && (int) ($windowDelete['preserved_marks'] ?? 0) >= 1;

    $updateResult = $service->updateMark(
        (int) $mark['id'],
        ['mark_status' => 'present', 'value' => '0', 'note' => 'تصحيح تكاملي', 'reason' => 'اختبار تصحيح إداري معزول'],
        (int) $mark['academic_year_id'],
        (int) $actor['id'],
        'super_admin'
    );
    $updatedStmt = $db->prepare('SELECT value, note FROM student_marks WHERE id = ?');
    $updatedStmt->execute([(int) $mark['id']]);
    $updated = $updatedStmt->fetch(PDO::FETCH_ASSOC);
    $checks['admin_update_is_persisted_and_batched'] = (float) ($updated['value'] ?? -1) === 0.0
        && (string) ($updated['note'] ?? '') === 'تصحيح تكاملي'
        && (string) ($updateResult['batch_id'] ?? '') !== '';

    $deleteResult = $service->deleteMarks(
        [(int) $mark['id']],
        (int) $mark['academic_year_id'],
        (int) $actor['id'],
        'super_admin',
        'اختبار حذف ذري معزول لدرجة أصلية'
    );
    $markExistsStmt->execute([(int) $mark['id']]);
    $checks['super_admin_mark_delete_is_atomic_and_batched'] = (int) $markExistsStmt->fetchColumn() === 0
        && (int) ($deleteResult['affected'] ?? 0) === 1
        && (string) ($deleteResult['batch_id'] ?? '') !== '';
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
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
