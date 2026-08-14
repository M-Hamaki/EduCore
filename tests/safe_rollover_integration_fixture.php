<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';

function runSafeRolloverIntegration(bool $includeLifecycle): array
{
    $db = educoreTestDatabase();
    $databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    if (!preg_match('/_test$/', $databaseName) || $databaseName === 'educore') {
        throw new RuntimeException('Rollover integration test database guard failed.');
    }
    $migration = require dirname(__DIR__) . '/database/migrations/20260718_safe_year_rollover.php';
    $migration($db);
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
    require_once dirname(__DIR__) . '/classes/RecoveryBackupService.php';
    require_once dirname(__DIR__) . '/classes/NewYearRolloverService.php';
    require_once dirname(__DIR__) . '/classes/AcademicYearWriteGuard.php';

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $db->beginTransaction();
    try {
        $db->exec("INSERT INTO stages (stage_name, stage_code, stage_order, status) VALUES ('مرحلة اختبار', 'rollover_test_stage', 1, 'active')");
        $stageId = (int) $db->lastInsertId();
        $gradeInsert = $db->prepare("INSERT INTO grades (grade_name, grade_code, stage, grade_order, stage_id, status) VALUES (?, ?, 'primary', ?, ?, 'active')");
        $gradeInsert->execute(['الصف الأول اختبار', 'rollover_g1', 1, $stageId]);
        $gradeOneId = (int) $db->lastInsertId();
        $gradeInsert->execute(['الصف الثاني اختبار', 'rollover_g2', 2, $stageId]);
        $gradeTwoId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO subjects (name, code, is_active) VALUES ('مادة اختبار التهيئة', 'ROLLOVER_SUBJECT', 1)");
        $subjectId = (int) $db->lastInsertId();

        $userInsert = $db->prepare("INSERT INTO users (name, username, role, status) VALUES (?, ?, ?, 'active')");
        $userInsert->execute(['مدير اختبار', 'rollover_admin', 'admin']);
        $adminId = (int) $db->lastInsertId();
        $studentIds = [];
        foreach ([['طالب مترق', 'promote'], ['طالب راسب', 'retain'], ['طالب خريج', 'graduate']] as $student) {
            $userInsert->execute([$student[0], 'rollover_' . $student[1], 'student']);
            $studentIds[$student[1]] = (int) $db->lastInsertId();
        }

        $yearInsert = $db->prepare("INSERT INTO academic_years (name, start_date, end_date, is_active, locked, status) VALUES (?, ?, ?, ?, 0, 'active')");
        $yearInsert->execute(['2098-2099', '2098-09-01', '2099-06-30', 1]);
        $sourceYearId = (int) $db->lastInsertId();
        $yearInsert->execute(['2099-2100', '2099-09-01', '2100-06-30', 0]);
        $targetYearId = (int) $db->lastInsertId();

        $classInsert = $db->prepare("INSERT INTO classes (grade_id, name, display_order, status, academic_year, academic_year_id) VALUES (?, ?, ?, 'active', ?, ?)");
        $classInsert->execute([$gradeOneId, 'فصل أول اختبار', 1, '2098-2099', $sourceYearId]);
        $classOneId = (int) $db->lastInsertId();
        $classInsert->execute([$gradeTwoId, 'فصل ثان اختبار', 1, '2098-2099', $sourceYearId]);
        $classTwoId = (int) $db->lastInsertId();

        $enroll = $db->prepare("INSERT INTO student_enrollments (student_id, academic_year_id, stage_id, grade_id, class_id, enrollment_status, enrollment_date) VALUES (?, ?, ?, ?, ?, 'enrolled', '2098-09-01')");
        $enroll->execute([$studentIds['promote'], $sourceYearId, $stageId, $gradeOneId, $classOneId]);
        $enroll->execute([$studentIds['retain'], $sourceYearId, $stageId, $gradeOneId, $classOneId]);
        $enroll->execute([$studentIds['graduate'], $sourceYearId, $stageId, $gradeTwoId, $classTwoId]);

        $db->prepare("INSERT INTO academic_terms (academic_year_id, name, term_order, start_date, end_date, status) VALUES (?, 'الترم الأول', 1, '2098-09-01', '2099-01-31', 'active')")
            ->execute([$sourceYearId]);
        $termId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO academic_months (academic_year_id, term_id, name, month_order, start_date, end_date, month_type, status) VALUES (?, ?, 'سبتمبر', 1, '2098-09-01', '2098-09-30', 'study', 'active')")
            ->execute([$sourceYearId, $termId]);
        $monthId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO academic_weeks (academic_year_id, term_id, month_id, month_label, name, week_order, start_date, end_date, week_type, counts_for_average) VALUES (?, ?, ?, 'سبتمبر', 'الأسبوع الأول', 1, '2098-09-01', '2098-09-07', 'study', 1)")
            ->execute([$sourceYearId, $termId, $monthId]);
        $weekId = (int) $db->lastInsertId();

        $db->prepare("INSERT INTO subject_grade_assignments (academic_year_id, term_id, subject_id, stage_id, grade_id, class_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)")
            ->execute([$sourceYearId, $termId, $subjectId, $stageId, $gradeOneId, $classOneId]);
        $assignmentId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO assessment_schemes (academic_year_id, term_id, subject_assignment_id, subject_id, stage_id, grade_id, name, total_grade, pass_grade, status) VALUES (?, ?, ?, ?, ?, ?, 'خطة اختبار', 100, 50, 'active')")
            ->execute([$sourceYearId, $termId, $assignmentId, $subjectId, $stageId, $gradeOneId]);
        $schemeId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO assessment_components (scheme_id, name, component_type, max_grade, is_weekly, counts_in_average, counts_in_total, is_active) VALUES (?, 'أسبوعي', 'weekly', 10, 1, 1, 1, 1)")
            ->execute([$schemeId]);
        $componentId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO assessment_component_week_rules (component_id, week_id, is_included) VALUES (?, ?, 1)")
            ->execute([$componentId, $weekId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    $_SESSION['user_id'] = $adminId;
    $_SESSION['role'] = 'admin';
    $_SESSION['name'] = 'مدير اختبار';

    $decisionService = new NewYearRolloverService($db);
    $decisionService->savePromotionRules(
        $sourceYearId,
        $targetYearId,
        [$gradeOneId => $gradeTwoId, $gradeTwoId => 'graduate'],
        $adminId
    );
    $classMatrix = $decisionService->classPromotionMatrix($sourceYearId, $targetYearId);
    $classMappingInput = [];
    foreach ($classMatrix['mappings'] as $mapping) {
        $classMappingInput[$mapping['mapping_key']] = [
            'target_name' => $mapping['target_name'],
            'target_capacity' => $mapping['target_capacity'],
            'is_enabled' => 1,
            'auto_place_students' => $mapping['mapping_type'] === 'cohort' ? 1 : 0,
        ];
    }
    $decisionService->saveClassMappings($sourceYearId, $targetYearId, $classMappingInput, $adminId);
    $decisionService->prepareDecisions(
        $sourceYearId,
        $targetYearId,
        [],
        [$studentIds['retain']],
        $adminId
    );

    $token = bin2hex(random_bytes(5));
    $runtimeRoot = dirname(__DIR__) . '/storage/test-runtime/rollover-' . $token;
    $uploadsRoot = $runtimeRoot . '/uploads';
    $privateRoot = $runtimeRoot . '/private';
    $backupRoot = $runtimeRoot . '/backups';
    foreach ([$uploadsRoot, $privateRoot, $backupRoot] as $directory) {
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create rollover fixture directory.');
        }
    }
    file_put_contents($privateRoot . '/student-proof.txt', 'fixture-' . $token);

    $cleanup = static function () use ($runtimeRoot): void {
        $normalized = str_replace('\\', '/', $runtimeRoot);
        $expected = str_replace('\\', '/', dirname(__DIR__) . '/storage/test-runtime/rollover-');
        if (strpos($normalized, $expected) !== 0 || !is_dir($runtimeRoot)) {
            throw new RuntimeException('Refusing unsafe rollover fixture cleanup.');
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

    $results = [];
    try {
        $recovery = new RecoveryBackupService(
            $db,
            dirname(__DIR__),
            ['uploads_fixture' => $uploadsRoot, 'private_fixture' => $privateRoot],
            $backupRoot
        );
        $created = $recovery->createPackage($adminId);
        $restoreDb = substr($databaseName, 0, 34) . '_roll_' . $token . '_test';
        $verifiedReceipt = $recovery->verifyPackage((string) $created['backup_key'], $restoreDb, $adminId);
        $results['verified_recovery_required'] = ($verifiedReceipt['status'] ?? '') === 'verified';

        $service = new NewYearRolloverService($db, $recovery);
        $blocked = $service->preflight($sourceYearId, $sourceYearId, [$studentIds['retain']]);
        $results['invalid_target_fails_closed'] = empty($blocked['ready']) && !empty($blocked['blockers']);
        $preflight = $service->preflight($sourceYearId, $targetYearId, [$studentIds['retain']]);
        $results['valid_preflight_accounts_for_all_students'] = !empty($preflight['ready'])
            && (int) $preflight['students']['promoted'] === 1
            && (int) $preflight['students']['retained'] === 1
            && (int) $preflight['students']['graduating'] === 1
            && (int) $preflight['students']['students_skipped'] === 0;

        $run = $service->execute(
            $sourceYearId,
            $targetYearId,
            (string) $verifiedReceipt['backup_key'],
            [$studentIds['retain']],
            $adminId
        );
        $results['atomic_execution_created_expected_students'] = (int) $run['students_promoted'] === 1
            && (int) $run['students_retained'] === 1
            && (int) $run['students_graduating'] === 1
            && (int) $run['students_skipped'] === 0;
        $targetEnrollments = (int) $db->query('SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = ' . $targetYearId)->fetchColumn();
        $historicalRows = (int) $db->query('SELECT COUNT(*) FROM attendance WHERE academic_year_id = ' . $targetYearId)->fetchColumn()
            + (int) $db->query('SELECT COUNT(*) FROM student_marks WHERE academic_year_id = ' . $targetYearId)->fetchColumn();
        $unassigned = (int) $db->query('SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = '
            . $targetYearId . ' AND class_id IS NULL')->fetchColumn();
        $lineage = (int) $db->query('SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = '
            . $targetYearId . ' AND source_enrollment_id IS NOT NULL AND promotion_decision_id IS NOT NULL')->fetchColumn();
        $targetAnnualStatuses = $db->query("SELECT academic_status, COUNT(*) AS total
            FROM student_enrollments
            WHERE academic_year_id = {$targetYearId}
            GROUP BY academic_status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $results['target_has_enrollments_without_history'] = $targetEnrollments === 2 && $historicalRows === 0;
        $promotedClassId = (int) $db->query("SELECT class_id FROM student_enrollments
            WHERE academic_year_id = {$targetYearId} AND academic_status = 'promoted' LIMIT 1")->fetchColumn();
        $promotedTargetGrade = (int) $db->query("SELECT c.grade_id FROM classes c
            WHERE c.id = {$promotedClassId} LIMIT 1")->fetchColumn();
        $results['promoted_is_auto_placed_and_retained_stays_unassigned'] = $unassigned === 1
            && $lineage === 2
            && $promotedClassId > 0
            && $promotedTargetGrade === $gradeTwoId;
        $results['target_enrollments_have_annual_academic_statuses'] =
            (int) ($targetAnnualStatuses['promoted'] ?? 0) === 1
            && (int) ($targetAnnualStatuses['retained'] ?? 0) === 1;

        $verification = $service->verifyRun((string) $run['run_key']);
        $results['independent_verification_passed'] = !empty($verification['passed'])
            && !in_array(false, $verification['checks'], true);

        if ($includeLifecycle) {
            $rollback = $service->rollback((string) $run['run_key'], $adminId);
            $targetAfterRollback = (int) $db->query('SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = ' . $targetYearId)->fetchColumn();
            $sourceAfterRollback = (int) $db->query('SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = ' . $sourceYearId)->fetchColumn();
            $results['manifest_owned_rollback_preserves_source'] = (int) $rollback['deleted_manifest_rows'] > 0
                && $targetAfterRollback === 0 && $sourceAfterRollback === 3;

            $rerunCreated = $recovery->createPackage($adminId);
            $rerunRestoreDb = substr($databaseName, 0, 30) . '_reroll_' . $token . '_test';
            $rerunReceipt = $recovery->verifyPackage(
                (string) $rerunCreated['backup_key'],
                $rerunRestoreDb,
                $adminId
            );
            $rerun = $service->execute(
                $sourceYearId,
                $targetYearId,
                (string) $rerunReceipt['backup_key'],
                [$studentIds['retain']],
                $adminId
            );
            $reverification = $service->verifyRun((string) $rerun['run_key']);
            $results['rerun_after_owned_rollback_verified'] = !empty($reverification['passed']);
            $service->activate((string) $rerun['run_key'], $adminId);
            $sourceLocked = (int) $db->query('SELECT locked FROM academic_years WHERE id = ' . $sourceYearId)->fetchColumn();
            $targetActive = (int) $db->query('SELECT is_active FROM academic_years WHERE id = ' . $targetYearId)->fetchColumn();
            $results['activation_locks_source_and_activates_target'] = $sourceLocked === 1 && $targetActive === 1;
            $graduateAnnualStatus = $db->query("SELECT enrollment_status, academic_status
                FROM student_enrollments
                WHERE student_id = {$studentIds['graduate']} AND academic_year_id = {$sourceYearId}
                LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            $graduateAccountStatus = (string) $db->query(
                'SELECT status FROM users WHERE id = ' . $studentIds['graduate']
            )->fetchColumn();
            $results['activation_updates_graduate_source_status'] =
                ($graduateAnnualStatus['enrollment_status'] ?? '') === 'enrolled'
                && ($graduateAnnualStatus['academic_status'] ?? '') === 'graduated'
                && $graduateAccountStatus === 'graduated';
            try {
                (new AcademicYearWriteGuard($db))->assertWritable($sourceYearId);
                $results['locked_source_rejects_writes'] = false;
            } catch (RuntimeException $error) {
                $results['locked_source_rejects_writes'] = true;
            }
        }
    } finally {
        $cleanup();
    }

    return $results;
}
