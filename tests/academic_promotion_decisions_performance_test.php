<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($databaseName === 'educore' || !preg_match('/_test$/', $databaseName)) {
    throw new RuntimeException('Academic promotion performance test database guard failed.');
}

$safeMigration = require dirname(__DIR__) . '/database/migrations/20260718_safe_year_rollover.php';
$safeMigration($db);
$decisionMigration = require dirname(__DIR__) . '/database/migrations/20260719_academic_promotion_decisions.php';
$decisionMigration($db);
$annualStatusMigration = require dirname(__DIR__) . '/database/migrations/20260726_student_annual_statuses.php';
$annualStatusMigration($db);
$classMappingMigration = require dirname(__DIR__) . '/database/migrations/20260728_class_rollover_mappings.php';
$classMappingMigration($db);
$experimentalScopeMigration = require dirname(__DIR__) . '/database/migrations/20260729_academic_structure_experimental_scope.php';
$experimentalScopeMigration($db);
$ownershipMigration = require dirname(__DIR__) . '/database/migrations/20260719_student_test_account_ownership.php';
$ownershipMigration($db);
require_once dirname(__DIR__) . '/classes/NewYearRolloverService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$studentCount = 1000;
$db->beginTransaction();
try {
    $db->exec("INSERT INTO stages (stage_name, stage_code, stage_order, status)
        VALUES ('مرحلة أداء الترحيل', 'promotion_performance_stage', 1, 'active')");
    $stageId = (int) $db->lastInsertId();

    $gradeInsert = $db->prepare("INSERT INTO grades
        (grade_name, grade_code, stage, grade_order, stage_id, status, is_experimental)
        VALUES (?, ?, 'primary', ?, ?, 'active', 0)");
    $gradeInsert->execute(['أول أداء', 'promotion_perf_1', 1, $stageId]);
    $gradeOneId = (int) $db->lastInsertId();
    $gradeInsert->execute(['ثاني أداء', 'promotion_perf_2', 2, $stageId]);
    $gradeTwoId = (int) $db->lastInsertId();

    $userInsert = $db->prepare("INSERT INTO users (name, username, role, status)
        VALUES (?, ?, ?, 'active')");
    $userInsert->execute(['مدير اختبار أداء الترحيل', 'promotion_performance_admin', 'admin']);
    $adminId = (int) $db->lastInsertId();

    $yearInsert = $db->prepare("INSERT INTO academic_years
        (name, start_date, end_date, is_active, locked, status) VALUES (?, ?, ?, ?, 0, 'active')");
    $yearInsert->execute(['2111-2112', '2111-09-01', '2112-06-30', 1]);
    $sourceYearId = (int) $db->lastInsertId();
    $yearInsert->execute(['2112-2113', '2112-09-01', '2113-06-30', 0]);
    $targetYearId = (int) $db->lastInsertId();

    $profileInsert = $db->prepare("INSERT INTO student_profiles
        (user_id, student_code, first_name_ar, enrollment_status)
        VALUES (?, ?, ?, 'enrolled')");
    $enrollmentInsert = $db->prepare("INSERT INTO student_enrollments
        (student_id, academic_year_id, stage_id, grade_id, class_id, enrollment_status, enrollment_date)
        VALUES (?, ?, ?, ?, NULL, 'enrolled', '2111-09-01')");
    for ($index = 1; $index <= $studentCount; $index++) {
        $username = 'promotion_perf_student_' . $index;
        $userInsert->execute(['طالب أداء ' . $index, $username, 'student']);
        $studentId = (int) $db->lastInsertId();
        $profileInsert->execute([$studentId, 'PERF-' . str_pad((string) $index, 5, '0', STR_PAD_LEFT), 'طالب أداء ' . $index]);
        $enrollmentInsert->execute([$studentId, $sourceYearId, $stageId, $gradeOneId]);
    }

    $db->prepare("INSERT INTO academic_terms
        (academic_year_id, name, term_order, start_date, end_date, status)
        VALUES (?, 'الفصل الأول', 1, '2111-09-01', '2112-01-31', 'active')")->execute([$sourceYearId]);
    $termId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO academic_months
        (academic_year_id, term_id, name, month_order, start_date, end_date, month_type, status)
        VALUES (?, ?, 'سبتمبر', 1, '2111-09-01', '2111-09-30', 'study', 'active')")
        ->execute([$sourceYearId, $termId]);
    $monthId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO academic_weeks
        (academic_year_id, term_id, month_id, month_label, name, week_order, start_date, end_date, week_type, counts_for_average)
        VALUES (?, ?, ?, 'سبتمبر', 'الأسبوع الأول', 1, '2111-09-01', '2111-09-07', 'study', 1)")
        ->execute([$sourceYearId, $termId, $monthId]);
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $error;
}

$_SESSION['user_id'] = $adminId;
$_SESSION['role'] = 'admin';
$_SESSION['name'] = 'مدير اختبار أداء الترحيل';

$service = new NewYearRolloverService($db);
$service->savePromotionRules(
    $sourceYearId,
    $targetYearId,
    [$gradeOneId => $gradeTwoId, $gradeTwoId => 'graduate'],
    $adminId
);

$memoryBefore = memory_get_usage(true);
$startedAt = microtime(true);
$summary = $service->prepareDecisions($sourceYearId, $targetYearId, [], [], $adminId);
$elapsedSeconds = microtime(true) - $startedAt;
$memoryDelta = max(0, memory_get_peak_usage(true) - $memoryBefore);
$storedDecisions = (int) $db->query("SELECT COUNT(*) FROM student_promotion_decisions
    WHERE source_year_id = {$sourceYearId} AND target_year_id = {$targetYearId}")->fetchColumn();

$results = [
    'one_thousand_decisions_are_accounted_for' => !empty($summary['ready'])
        && (int) $summary['eligible_students'] === $studentCount
        && (int) $summary['students']['promoted'] === $studentCount
        && $storedDecisions === $studentCount,
    'one_thousand_decisions_prepare_within_budget' => $elapsedSeconds < 15.0,
    'one_thousand_decisions_memory_is_bounded' => $memoryDelta < 128 * 1024 * 1024,
];

echo 'elapsed_seconds:' . number_format($elapsedSeconds, 4, '.', '') . PHP_EOL;
echo 'memory_delta_bytes:' . $memoryDelta . PHP_EOL;
$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
