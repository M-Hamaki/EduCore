<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/classes/AcademicYear.php';

$sourceDatabase = (string) DB_NAME;
if (!preg_match('/^[A-Za-z0-9_]+$/', $sourceDatabase)) {
    throw new RuntimeException('Unsafe source database identifier.');
}

$testDatabase = 'educore_test_year_delete_' . bin2hex(random_bytes(5));
$server = new PDO(
    'mysql:host=' . DB_HOST . ';charset=utf8mb4',
    DB_USERNAME,
    DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$checks = [];
$db = null;

try {
    $server->exec(
        'CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $testDatabase . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    foreach (['academic_years', 'activity_logs', 'undo_log', 'recycle_bin'] as $table) {
        $db->exec(
            'CREATE TABLE `' . $table . '` LIKE `' . $sourceDatabase . '`.`' . $table . '`'
        );
    }
    $db->exec(
        'CREATE TABLE student_enrollments (
            id INT NOT NULL AUTO_INCREMENT,
            academic_year_id INT NOT NULL,
            PRIMARY KEY (id),
            CONSTRAINT fk_test_enrollment_year
                FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $_SESSION = [
        'user_id' => 1,
        'name' => 'اختبار حذف عام',
        'role' => 'admin',
    ];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = 'cli://academic-year-deletion-test';

    $insertYear = $db->prepare(
        "INSERT INTO academic_years (name, is_active, locked, status)
         VALUES (?, ?, ?, 'active')"
    );
    $insertYear->execute(['2098-2099', 1, 0]);
    $activeYearId = (int) $db->lastInsertId();
    $insertYear->execute(['2099-2100', 0, 0]);
    $emptyYearId = (int) $db->lastInsertId();

    $emptyAssessment = AcademicYear::getDeletionAssessment($db, $emptyYearId);
    $checks['empty_inactive_year_is_deletable'] = !empty($emptyAssessment['can_delete']);

    AcademicYear::delete($db, $emptyYearId);
    $existsStmt = $db->prepare('SELECT COUNT(*) FROM academic_years WHERE id = ?');
    $existsStmt->execute([$emptyYearId]);
    $checks['empty_year_is_deleted'] = (int) $existsStmt->fetchColumn() === 0;

    $undoStmt = $db->prepare(
        "SELECT COUNT(*) FROM undo_log
         WHERE table_name = 'academic_years' AND record_id = ? AND action_type = 'delete'"
    );
    $undoStmt->execute([$emptyYearId]);
    $checks['delete_has_undo_snapshot'] = (int) $undoStmt->fetchColumn() === 1;

    $activityStmt = $db->prepare(
        "SELECT COUNT(*) FROM activity_logs
         WHERE target_type = 'academic_year' AND target_id = ? AND action = 'delete'"
    );
    $activityStmt->execute([$emptyYearId]);
    $checks['delete_has_activity_record'] = (int) $activityStmt->fetchColumn() === 1;

    $insertYear->execute(['2100-2101', 0, 0]);
    $referencedYearId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO student_enrollments (academic_year_id) VALUES (?)')
        ->execute([$referencedYearId]);

    $referencedAssessment = AcademicYear::getDeletionAssessment($db, $referencedYearId);
    $checks['referenced_year_is_blocked'] =
        empty($referencedAssessment['can_delete'])
        && (int)($referencedAssessment['reference_count'] ?? 0) === 1;

    $referenceDeleteBlocked = false;
    try {
        AcademicYear::delete($db, $referencedYearId);
    } catch (InvalidArgumentException $e) {
        $referenceDeleteBlocked = str_contains($e->getMessage(), 'بيانات مرتبطة');
    }
    $existsStmt->execute([$referencedYearId]);
    $checks['blocked_delete_preserves_year'] =
        $referenceDeleteBlocked && (int) $existsStmt->fetchColumn() === 1;

    $activeDeleteBlocked = false;
    try {
        AcademicYear::delete($db, $activeYearId);
    } catch (InvalidArgumentException $e) {
        $activeDeleteBlocked = str_contains($e->getMessage(), 'العام الدراسي النشط');
    }
    $existsStmt->execute([$activeYearId]);
    $checks['active_year_is_protected'] =
        $activeDeleteBlocked && (int) $existsStmt->fetchColumn() === 1;
} finally {
    $db = null;
    if (preg_match('/^educore_test_year_delete_[a-f0-9]{10}$/', $testDatabase)) {
        $server->exec('DROP DATABASE IF EXISTS `' . $testDatabase . '`');
    }
}

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
