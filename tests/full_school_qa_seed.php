<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap_test_database.php';

$phase = $argv[1] ?? '';
if (!in_array($phase, ['core', 'rollover'], true)) {
    fwrite(STDERR, "Usage: php tests/full_school_qa_seed.php core|rollover\n");
    exit(2);
}

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($databaseName !== 'educore_full_qa_test') {
    throw new RuntimeException('This visible QA fixture is restricted to educore_full_qa_test.');
}

$findId = static function (string $table, string $column, string $value) use ($db): int {
    if (!preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $column)) {
        throw new InvalidArgumentException('Unsafe QA fixture lookup.');
    }
    $stmt = $db->prepare("SELECT id FROM `{$table}` WHERE `{$column}` = ? LIMIT 1");
    $stmt->execute([$value]);
    return (int) $stmt->fetchColumn();
};

if ($phase === 'core') {
    if ($findId('users', 'username', 'qa_admin') > 0) {
        throw new RuntimeException('QA fixture already exists; clone a fresh test schema before reseeding.');
    }

    $db->beginTransaction();
    try {
        $setting = $db->prepare(
            'INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)'
        );
        $setting->execute(['school_name', 'مدرسة EduCore التجريبية QA', 'بيئة اختبار معزولة']);
        $setting->execute(['academic_year', '2025-2026', 'العام المصدر لسيناريو QA']);

        $year = $db->prepare(
            "INSERT INTO academic_years (name, start_date, end_date, is_active, locked, status, notes)
             VALUES (?, ?, ?, ?, 0, 'active', ?)"
        );
        $year->execute(['2025-2026', '2025-09-01', '2026-06-30', 1, 'QA: عام مصدر مكتمل البيانات']);
        $sourceYearId = (int) $db->lastInsertId();
        $year->execute(['2026-2027', '2026-09-01', '2027-06-30', 0, 'QA: عام هدف لاختبار التهيئة']);
        $targetYearId = (int) $db->lastInsertId();

        $db->exec(
            "INSERT INTO stages (stage_name, stage_name_en, stage_code, stage_order, status, portal_visible, portal_description)
             VALUES ('المرحلة الابتدائية - QA', 'QA Primary', 'qa_primary', 1, 'active', 1, 'مرحلة تجريبية ظاهرة بوضوح')"
        );
        $stageId = (int) $db->lastInsertId();

        $grade = $db->prepare(
            "INSERT INTO grades
                (grade_name, grade_code, stage, grade_order, stage_id, description, status, is_experimental)
             VALUES (?, ?, 'primary', ?, ?, ?, 'active', ?)"
        );
        $grade->execute(['الصف الأول الابتدائي - QA', 'QA_G1', 1, $stageId, 'صف رسمي لاختبار الترقية', 0]);
        $gradeOneId = (int) $db->lastInsertId();
        $grade->execute(['الصف الثاني الابتدائي - QA', 'QA_G2', 2, $stageId, 'صف رسمي نهائي في سيناريو QA', 0]);
        $gradeTwoId = (int) $db->lastInsertId();
        $grade->execute(['Test Grade - تجريبي QA', 'QA_TEST', 99, $stageId, 'صف مستبعد عمدًا من الترحيل الرسمي', 1]);
        $experimentalGradeId = (int) $db->lastInsertId();

        $class = $db->prepare(
            "INSERT INTO classes
                (grade_id, name, room_location, capacity, display_order, status, section_name, academic_year, academic_year_id)
             VALUES (?, ?, ?, ?, ?, 'active', ?, '2025-2026', ?)"
        );
        $class->execute([$gradeOneId, 'فصل 1-A — QA', 'الدور الأول', 30, 1, '1-A', $sourceYearId]);
        $classOneId = (int) $db->lastInsertId();
        $class->execute([$gradeTwoId, 'فصل 2-A — QA', 'الدور الثاني', 30, 2, '2-A', $sourceYearId]);
        $classTwoId = (int) $db->lastInsertId();
        $class->execute([$experimentalGradeId, 'فصل Test — QA', 'معمل الاختبار', 10, 99, 'TEST', $sourceYearId]);
        $experimentalClassId = (int) $db->lastInsertId();

        $user = $db->prepare(
            "INSERT INTO users (name, employee_code, username, email, password_hash, role, status, class_id, is_test_account)
             VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)"
        );
        $user->execute([
            'مدير النظام — QA تجريبي', 'QA-ADMIN', 'qa_admin', 'qa.admin@example.test',
            password_hash('QaAdmin!2026', PASSWORD_DEFAULT), 'admin', null, 0,
        ]);
        $adminId = (int) $db->lastInsertId();
        $user->execute([
            'المعلم أحمد — QA تجريبي', 'QA-TEACHER', 'qa_teacher', 'qa.teacher@example.test',
            password_hash('QaUser!2026', PASSWORD_DEFAULT), 'teacher', null, 0,
        ]);
        $teacherId = (int) $db->lastInsertId();

        $db->prepare(
            'INSERT INTO staff_profiles
                (user_id, employee_code, full_name_ar, gender, phone_mobile, hire_date, job_title, department,
                 contract_type, qualification, specialization, years_of_experience, basic_salary, net_salary, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $teacherId, 'QA-TEACHER', 'المعلم أحمد — QA تجريبي', 'male', '01000000001', '2020-09-01',
            'معلم فصل', 'التعليم الابتدائي', 'permanent', 'بكالوريوس تربية', 'رياضيات', 6.0,
            7000.00, 6500.00, 'سجل عامل تجريبي متكامل للاختبار فقط',
        ]);

        $studentRows = [
            'promoted_one' => ['أحمد ناجح — QA', 'qa_student_ahmed', 'QA26001', $gradeOneId, $classOneId, 0, 'male'],
            'promoted_two' => ['مريم ناجحة — QA', 'qa_student_mariam', 'QA26002', $gradeOneId, $classOneId, 0, 'female'],
            'retained' => ['عمر راسب — QA', 'qa_student_omar', 'QA26003', $gradeOneId, $classOneId, 0, 'male'],
            'transferred' => ['ليلى محولة — QA', 'qa_student_laila', 'QA26004', $gradeOneId, $classOneId, 0, 'female'],
            'withdrawn' => ['سلمى منسحبة — QA', 'qa_student_salma', 'QA26005', $gradeOneId, $classOneId, 0, 'female'],
            'graduated' => ['يوسف خريج — QA', 'qa_student_youssef', 'QA26006', $gradeTwoId, $classTwoId, 0, 'male'],
            'test_account' => ['طالب حساب تجريبي — QA', 'qa_student_test', 'QA26007', $gradeOneId, $classOneId, 1, 'male'],
            'experimental_grade' => ['طالبة صف تجريبي — QA', 'qa_student_experimental', 'QA26008', $experimentalGradeId, $experimentalClassId, 0, 'female'],
        ];
        $students = [];
        $profile = $db->prepare(
            "INSERT INTO student_profiles
                (user_id, grade_id, student_code, ministry_code, birth_date, national_id, gender,
                 phone_mobile, enrollment_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'enrolled')"
        );
        $enrollment = $db->prepare(
            "INSERT INTO student_enrollments
                (student_id, academic_year_id, stage_id, grade_id, class_id, enrollment_status, enrollment_date, notes)
             VALUES (?, ?, ?, ?, ?, 'enrolled', '2025-09-01', ?)"
        );
        foreach ($studentRows as $key => $row) {
            [$name, $username, $studentCode, $gradeId, $classId, $isTest, $gender] = $row;
            $user->execute([
                $name, null, $username, $username . '@example.test',
                password_hash('QaUser!2026', PASSWORD_DEFAULT), 'student', $classId, $isTest,
            ]);
            $studentId = (int) $db->lastInsertId();
            $students[$key] = $studentId;
            $profile->execute([
                $studentId, $gradeId, $studentCode, 'MIN-' . $studentCode, '2018-03-15',
                '2990101' . str_pad((string) $studentId, 7, '0', STR_PAD_LEFT), $gender,
                '0101' . str_pad((string) $studentId, 7, '0', STR_PAD_LEFT),
            ]);
            $enrollment->execute([
                $studentId, $sourceYearId, $stageId, $gradeId, $classId,
                'قيد مصدر فعلي لسيناريو QA: ' . $key,
            ]);
        }

        $subject = $db->prepare('INSERT INTO subjects (name, code, sort_order, is_core, is_active) VALUES (?, ?, ?, 1, 1)');
        $subject->execute(['الرياضيات — QA', 'QA_MATH', 1]);
        $mathId = (int) $db->lastInsertId();
        $subject->execute(['اللغة العربية — QA', 'QA_ARABIC', 2]);
        $arabicId = (int) $db->lastInsertId();
        $subject->execute(['العلوم — QA', 'QA_SCIENCE', 3]);
        $scienceId = (int) $db->lastInsertId();

        $attendance = $db->prepare(
            'INSERT INTO attendance
                (student_id, class_id, attendance_date, status, notes, recorded_by, created_by, academic_year_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $dates = ['2025-10-05', '2025-10-06', '2025-10-07', '2025-10-08'];
        $statuses = ['present', 'absent', 'late', 'excused'];
        foreach ($students as $index => $studentId) {
            $row = $studentRows[$index];
            $classId = (int) $row[4];
            foreach ($dates as $dateIndex => $date) {
                $status = $statuses[($dateIndex + $studentId) % count($statuses)];
                $attendance->execute([
                    $studentId, $classId, $date, $status, 'حضور تجريبي QA — ' . $status,
                    $teacherId, $teacherId, $sourceYearId,
                ]);
            }
        }

        $evaluationType = $db->prepare('INSERT INTO evaluation_types (name, type, points) VALUES (?, ?, ?)');
        $evaluationType->execute(['المشاركة الإيجابية — QA', 'positive', 5]);
        $positiveTypeId = (int) $db->lastInsertId();
        $evaluationType->execute(['عدم إحضار الواجب — QA', 'negative', -2]);
        $negativeTypeId = (int) $db->lastInsertId();
        $evaluation = $db->prepare(
            'INSERT INTO evaluations
                (student_id, teacher_id, evaluation_type_id, class_id, date_created, custom_points, reason,
                 created_by, academic_year_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $evaluation->execute([
            $students['promoted_one'], $teacherId, $positiveTypeId, $classOneId, '2025-10-06 10:00:00',
            5, 'مشاركة ممتازة في النشاط — QA', $teacherId, $sourceYearId,
        ]);
        $evaluation->execute([
            $students['retained'], $teacherId, $negativeTypeId, $classOneId, '2025-10-07 10:00:00',
            -2, 'لم يحضر الواجب — QA', $teacherId, $sourceYearId,
        ]);

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    echo json_encode([
        'phase' => 'core',
        'database' => $databaseName,
        'source_year_id' => $sourceYearId,
        'target_year_id' => $targetYearId,
        'stage_id' => $stageId,
        'grade_ids' => [$gradeOneId, $gradeTwoId, $experimentalGradeId],
        'class_ids' => [$classOneId, $classTwoId, $experimentalClassId],
        'admin_id' => $adminId,
        'teacher_id' => $teacherId,
        'student_ids' => $students,
        'subject_ids' => [$mathId, $arabicId, $scienceId],
        'attendance_rows' => count($students) * count($dates),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

require_once dirname(__DIR__) . '/classes/RecoveryBackupService.php';
require_once dirname(__DIR__) . '/classes/NewYearRolloverService.php';

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

$adminId = $findId('users', 'username', 'qa_admin');
$sourceYearId = $findId('academic_years', 'name', '2025-2026');
$targetYearId = $findId('academic_years', 'name', '2026-2027');
$gradeOneId = $findId('grades', 'grade_code', 'QA_G1');
$gradeTwoId = $findId('grades', 'grade_code', 'QA_G2');
$retainedId = $findId('users', 'username', 'qa_student_omar');
$transferredId = $findId('users', 'username', 'qa_student_laila');
$withdrawnId = $findId('users', 'username', 'qa_student_salma');
foreach ([$adminId, $sourceYearId, $targetYearId, $gradeOneId, $gradeTwoId, $retainedId, $transferredId, $withdrawnId] as $requiredId) {
    if ($requiredId <= 0) {
        throw new RuntimeException('QA core fixture is incomplete; run the core phase first.');
    }
}

if ((int) $db->query('SELECT is_active FROM academic_years WHERE id = ' . $targetYearId)->fetchColumn() === 1) {
    throw new RuntimeException('QA rollover already completed and activated.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = $adminId;
$_SESSION['role'] = 'admin';
$_SESSION['name'] = 'مدير النظام — QA تجريبي';

$service = new NewYearRolloverService($db);
$service->savePromotionRules(
    $sourceYearId,
    $targetYearId,
    [$gradeOneId => $gradeTwoId, $gradeTwoId => 'graduate'],
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
$preflight = $service->prepareDecisions(
    $sourceYearId,
    $targetYearId,
    [$transferredId => 'transferred_out', $withdrawnId => 'withdrawn'],
    [$retainedId],
    $adminId
);
if (empty($preflight['ready'])) {
    throw new RuntimeException('QA rollover preflight failed: ' . json_encode($preflight['blockers'], JSON_UNESCAPED_UNICODE));
}

$token = date('YmdHis') . '-' . bin2hex(random_bytes(3));
$runtimeRoot = dirname(__DIR__) . '/storage/test-runtime/full-qa/' . $token;
$uploadsRoot = $runtimeRoot . '/uploads';
$privateRoot = $runtimeRoot . '/private';
$backupRoot = $runtimeRoot . '/backups';
foreach ([$uploadsRoot, $privateRoot, $backupRoot] as $directory) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create QA recovery directory.');
    }
}
file_put_contents($uploadsRoot . '/qa-public-proof.txt', 'QA public recovery proof ' . $token);
file_put_contents($privateRoot . '/qa-private-proof.txt', 'QA private recovery proof ' . $token);

$recovery = new RecoveryBackupService(
    $db,
    dirname(__DIR__),
    ['qa_uploads' => $uploadsRoot, 'qa_private' => $privateRoot],
    $backupRoot
);
$service = new NewYearRolloverService($db, $recovery);

$firstPackage = $recovery->createPackage($adminId);
$firstRestoreDatabase = 'educore_full_qa_restore_a_' . bin2hex(random_bytes(3)) . '_test';
$firstReceipt = $recovery->verifyPackage((string) $firstPackage['backup_key'], $firstRestoreDatabase, $adminId);
$firstRun = $service->execute(
    $sourceYearId,
    $targetYearId,
    (string) $firstReceipt['backup_key'],
    [$retainedId],
    $adminId
);
$firstVerification = $service->verifyRun((string) $firstRun['run_key']);
if (empty($firstVerification['passed'])) {
    throw new RuntimeException('First QA rollover verification failed.');
}
$rollback = $service->rollback((string) $firstRun['run_key'], $adminId);

$secondPackage = $recovery->createPackage($adminId);
$secondRestoreDatabase = 'educore_full_qa_restore_b_' . bin2hex(random_bytes(3)) . '_test';
$secondReceipt = $recovery->verifyPackage((string) $secondPackage['backup_key'], $secondRestoreDatabase, $adminId);
$secondRun = $service->execute(
    $sourceYearId,
    $targetYearId,
    (string) $secondReceipt['backup_key'],
    [$retainedId],
    $adminId
);
$secondVerification = $service->verifyRun((string) $secondRun['run_key']);
if (empty($secondVerification['passed'])) {
    throw new RuntimeException('Second QA rollover verification failed.');
}
$service->activate((string) $secondRun['run_key'], $adminId);

$summary = [
    'phase' => 'rollover',
    'database' => $databaseName,
    'preflight_students' => $preflight['students'],
    'first_restore_database' => $firstRestoreDatabase,
    'first_verification_passed' => true,
    'rollback_deleted_manifest_rows' => (int) ($rollback['deleted_manifest_rows'] ?? 0),
    'second_restore_database' => $secondRestoreDatabase,
    'second_verification_passed' => true,
    'active_target_year' => '2026-2027',
    'source_year_locked' => (int) $db->query('SELECT locked FROM academic_years WHERE id = ' . $sourceYearId)->fetchColumn(),
    'target_enrollments' => (int) $db->query('SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = ' . $targetYearId)->fetchColumn(),
    'target_unassigned_enrollments' => (int) $db->query(
        'SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = ' . $targetYearId . ' AND class_id IS NULL'
    )->fetchColumn(),
    'recovery_root' => str_replace('\\', '/', $runtimeRoot),
];
file_put_contents(
    $runtimeRoot . '/qa-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
