<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($databaseName === 'educore' || !preg_match('/_test$/', $databaseName)) {
    throw new RuntimeException('Academic promotion test database guard failed.');
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
require_once dirname(__DIR__) . '/classes/RecoveryBackupService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$db->beginTransaction();
try {
    $db->exec("INSERT INTO stages (stage_name, stage_code, stage_order, status)
        VALUES ('مرحلة قرارات اختبار', 'promotion_decision_stage', 1, 'active')");
    $stageId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO stages (stage_name, stage_code, stage_order, status, is_experimental)
        VALUES ('مرحلة تجريبية للقرارات', 'promotion_decision_test_stage', 2, 'active', 1)");
    $experimentalStageId = (int) $db->lastInsertId();
    $gradeInsert = $db->prepare("INSERT INTO grades
        (grade_name, grade_code, stage, grade_order, stage_id, status, is_experimental)
        VALUES (?, ?, 'primary', ?, ?, 'active', ?)");
    $gradeInsert->execute(['الأول قرار', 'promotion_d1', 1, $stageId, 0]);
    $gradeOneId = (int) $db->lastInsertId();
    $gradeInsert->execute(['صف تجريبي وسيط', 'promotion_dx', 2, $stageId, 1]);
    $experimentalGradeId = (int) $db->lastInsertId();
    $gradeInsert->execute(['الثاني قرار', 'promotion_d2', 3, $stageId, 0]);
    $gradeTwoId = (int) $db->lastInsertId();
    $gradeInsert->execute(['الثالث قرار', 'promotion_d3', 4, $stageId, 0]);
    $gradeThreeId = (int) $db->lastInsertId();
    $gradeInsert->execute(['صف يرث المرحلة التجريبية', 'promotion_stage_test', 1, $experimentalStageId, 0]);
    $stageExperimentalGradeId = (int) $db->lastInsertId();

    $userInsert = $db->prepare("INSERT INTO users (name, username, role, status, is_test_account) VALUES (?, ?, ?, 'active', ?)");
    $userInsert->execute(['مدير قرارات اختبار', 'promotion_decision_admin', 'admin', 0]);
    $adminId = (int) $db->lastInsertId();
    $studentIds = [];
    foreach (['promoted', 'retained', 'graduated', 'transferred_out', 'withdrawn', 'test', 'experimental', 'stage_test', 'class_test', 'pending', 'missing'] as $key) {
        $userInsert->execute(['طالب ' . $key, 'promotion_decision_' . $key, 'student', $key === 'test' ? 1 : 0]);
        $studentIds[$key] = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO student_profiles
            (user_id, student_code, first_name_ar, enrollment_status)
            VALUES (?, ?, ?, 'enrolled')")->execute([
                $studentIds[$key],
                'PD-' . strtoupper(substr(hash('sha256', $key), 0, 8)),
                'طالب ' . $key,
            ]);
    }

    $yearInsert = $db->prepare("INSERT INTO academic_years
        (name, start_date, end_date, is_active, locked, status) VALUES (?, ?, ?, ?, 0, 'active')");
    $yearInsert->execute(['2101-2102', '2101-09-01', '2102-06-30', 1]);
    $sourceYearId = (int) $db->lastInsertId();
    $yearInsert->execute(['2102-2103', '2102-09-01', '2103-06-30', 0]);
    $targetYearId = (int) $db->lastInsertId();

    $classInsert = $db->prepare("INSERT INTO classes
        (grade_id, name, room_location, capacity, display_order, status, academic_year_id, is_experimental)
        VALUES (?, ?, NULL, ?, ?, 'active', ?, ?)");
    $classIds = [];
    foreach ([1, 2, 3] as $order) {
        $classInsert->execute([$gradeOneId, 'فصل أول ' . $order, 30, $order, $sourceYearId, 0]);
        $classIds[] = (int) $db->lastInsertId();
    }
    $classInsert->execute([$gradeTwoId, 'فصل ثان وحيد', 35, 1, $sourceYearId, 0]);
    $classTwoId = (int) $db->lastInsertId();
    $classInsert->execute([$stageExperimentalGradeId, 'فصل يرث المرحلة التجريبية', 20, 1, $sourceYearId, 0]);
    $stageExperimentalClassId = (int) $db->lastInsertId();
    $classInsert->execute([$gradeOneId, 'فصل تجريبي مباشر', 20, 99, $sourceYearId, 1]);
    $directExperimentalClassId = (int) $db->lastInsertId();

    $enroll = $db->prepare("INSERT INTO student_enrollments
        (student_id, academic_year_id, stage_id, grade_id, class_id, is_repeater, repeat_count,
         enrollment_status, enrollment_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'enrolled', '2101-09-01')");
    $enroll->execute([$studentIds['promoted'], $sourceYearId, $stageId, $gradeOneId, $classIds[2], 0, 0]);
    $enroll->execute([$studentIds['retained'], $sourceYearId, $stageId, $gradeOneId, $classIds[0], 1, 1]);
    $enroll->execute([$studentIds['graduated'], $sourceYearId, $stageId, $gradeThreeId, null, 0, 0]);
    $enroll->execute([$studentIds['transferred_out'], $sourceYearId, $stageId, $gradeTwoId, $classTwoId, 0, 0]);
    $enroll->execute([$studentIds['withdrawn'], $sourceYearId, $stageId, $gradeTwoId, $classTwoId, 0, 0]);
    $enroll->execute([$studentIds['test'], $sourceYearId, null, null, null, 0, 0]);
    $enroll->execute([$studentIds['experimental'], $sourceYearId, $stageId, $experimentalGradeId, null, 0, 0]);
    $enroll->execute([$studentIds['stage_test'], $sourceYearId, $experimentalStageId, $stageExperimentalGradeId, $stageExperimentalClassId, 0, 0]);
    $enroll->execute([$studentIds['class_test'], $sourceYearId, $stageId, $gradeOneId, $directExperimentalClassId, 0, 0]);
    $enroll->execute([$studentIds['pending'], $sourceYearId, $stageId, $gradeTwoId, null, 0, 0]);
    $enroll->execute([$studentIds['missing'], $sourceYearId, null, null, null, 0, 0]);

    $db->prepare("INSERT INTO academic_terms
        (academic_year_id, name, term_order, start_date, end_date, status)
        VALUES (?, 'الفصل الأول', 1, '2101-09-01', '2102-01-31', 'active')")->execute([$sourceYearId]);
    $termId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO academic_months
        (academic_year_id, term_id, name, month_order, start_date, end_date, month_type, status)
        VALUES (?, ?, 'سبتمبر', 1, '2101-09-01', '2101-09-30', 'study', 'active')")
        ->execute([$sourceYearId, $termId]);
    $monthId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO academic_weeks
        (academic_year_id, term_id, month_id, month_label, name, week_order, start_date, end_date, week_type, counts_for_average)
        VALUES (?, ?, ?, 'سبتمبر', 'الأسبوع الأول', 1, '2101-09-01', '2101-09-07', 'study', 1)")
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
$_SESSION['name'] = 'مدير قرارات اختبار';

$service = new NewYearRolloverService($db);
$service->savePromotionRules(
    $sourceYearId,
    $targetYearId,
    [$gradeOneId => $gradeTwoId, $gradeTwoId => $gradeThreeId, $gradeThreeId => 'graduate'],
    $adminId
);
$classMatrix = $service->classPromotionMatrix($sourceYearId, $targetYearId);
$classMappingInput = [];
foreach ($classMatrix['mappings'] as $mapping) {
    $classMappingInput[$mapping['mapping_key']] = [
        'target_name' => $mapping['target_name'],
        'target_capacity' => $mapping['target_capacity'],
        'is_enabled' => 1,
        'auto_place_students' => $mapping['mapping_type'] === 'cohort' ? 1 : 0,
    ];
}
$service->saveClassMappings($sourceYearId, $targetYearId, $classMappingInput, $adminId);
$overrides = [
    $studentIds['retained'] => 'retained',
    $studentIds['transferred_out'] => 'transferred_out',
    $studentIds['withdrawn'] => 'withdrawn',
    $studentIds['pending'] => 'pending',
];
$blocked = $service->prepareDecisions($sourceYearId, $targetYearId, $overrides, [], $adminId);
$results = [
    'pending_and_real_missing_placement_block' => empty($blocked['ready'])
        && (int) $blocked['students']['pending'] === 1
        && (int) $blocked['students']['students_skipped'] === 2
        && !empty($blocked['blocker_groups']),
    'test_and_all_experimental_scopes_are_explicitly_excluded' => (int) $blocked['students']['excluded_test'] === 4,
];

$db->prepare("UPDATE student_enrollments SET stage_id = ?, grade_id = ?
    WHERE student_id = ? AND academic_year_id = ?")
    ->execute([$stageId, $gradeTwoId, $studentIds['missing'], $sourceYearId]);
$overrides[$studentIds['pending']] = 'promoted';
$ready = $service->prepareDecisions($sourceYearId, $targetYearId, $overrides, [], $adminId);
$results['resolved_decisions_are_ready_without_class_rank_matching'] = !empty($ready['ready'])
    && (int) $ready['students']['promoted'] === 3
    && (int) $ready['students']['retained'] === 1
    && (int) $ready['students']['graduating'] === 1
    && (int) $ready['students']['transferred_out'] === 1
    && (int) $ready['students']['withdrawn'] === 1
    && (int) $ready['students']['excluded_test'] === 4;

$token = bin2hex(random_bytes(5));
$runtimeRoot = dirname(__DIR__) . '/storage/test-runtime/promotion-decisions-' . $token;
$uploadsRoot = $runtimeRoot . '/uploads';
$privateRoot = $runtimeRoot . '/private';
$backupRoot = $runtimeRoot . '/backups';
foreach ([$uploadsRoot, $privateRoot, $backupRoot] as $directory) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create promotion decision fixture directory.');
    }
}
file_put_contents($privateRoot . '/proof.txt', 'promotion-decisions-' . $token);

$cleanup = static function () use ($runtimeRoot): void {
    $normalized = str_replace('\\', '/', $runtimeRoot);
    $expected = str_replace('\\', '/', dirname(__DIR__) . '/storage/test-runtime/promotion-decisions-');
    if (strpos($normalized, $expected) !== 0 || !is_dir($runtimeRoot)) {
        throw new RuntimeException('Refusing unsafe promotion fixture cleanup.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($runtimeRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($runtimeRoot);
};

try {
    $recovery = new RecoveryBackupService(
        $db,
        dirname(__DIR__),
        ['uploads_fixture' => $uploadsRoot, 'private_fixture' => $privateRoot],
        $backupRoot
    );
    $created = $recovery->createPackage($adminId);
    $restoreDb = substr($databaseName, 0, 26) . '_decision_' . $token . '_test';
    $receipt = $recovery->verifyPackage((string) $created['backup_key'], $restoreDb, $adminId);
    $runService = new NewYearRolloverService($db, $recovery);
    $run = $runService->execute(
        $sourceYearId,
        $targetYearId,
        (string) $receipt['backup_key'],
        [],
        $adminId
    );
    $targetEnrollments = $db->query("SELECT * FROM student_enrollments
        WHERE academic_year_id = {$targetYearId} ORDER BY student_id")->fetchAll(PDO::FETCH_ASSOC);
    $results['only_promoted_and_retained_create_target_enrollments'] = count($targetEnrollments) === 4
        && (int) $run['students_promoted'] === 3
        && (int) $run['students_retained'] === 1
        && (int) $run['decisions_applied'] === 11;
    $results['target_enrollments_have_expected_class_placement_and_lineage'] = count(array_filter(
        $targetEnrollments,
        static fn(array $row): bool => !empty($row['source_enrollment_id'])
            && !empty($row['promotion_decision_id'])
    )) === 4
        && count(array_filter(
            $targetEnrollments,
            static fn(array $row): bool => $row['academic_status'] === 'promoted' && $row['class_id'] !== null
        )) === 1
        && count(array_filter(
            $targetEnrollments,
            static fn(array $row): bool => $row['academic_status'] === 'retained' && $row['class_id'] === null
        )) === 1;
    $retainedTarget = array_values(array_filter(
        $targetEnrollments,
        static fn(array $row): bool => (int) $row['student_id'] === $studentIds['retained']
    ))[0] ?? [];
    $results['retained_repeat_metadata_is_monotonic'] = (int) ($retainedTarget['is_repeater'] ?? 0) === 1
        && (int) ($retainedTarget['repeat_count'] ?? 0) === 2
        && (int) ($retainedTarget['grade_id'] ?? 0) === $gradeOneId;
    $verification = $runService->verifyRun((string) $run['run_key']);
    $results['decision_links_pass_independent_verification'] = !empty($verification['passed']);
    $runService->rollback((string) $run['run_key'], $adminId);
    $targetCountAfterRollback = (int) $db->query("SELECT COUNT(*) FROM student_enrollments
        WHERE academic_year_id = {$targetYearId}")->fetchColumn();
    $approvedAfterRollback = (int) $db->query("SELECT COUNT(*) FROM student_promotion_decisions
        WHERE source_year_id = {$sourceYearId} AND target_year_id = {$targetYearId} AND status = 'approved'")->fetchColumn();
    $results['rollback_removes_only_targets_and_reopens_decisions'] = $targetCountAfterRollback === 0
        && $approvedAfterRollback === 11;
} finally {
    $cleanup();
}

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
