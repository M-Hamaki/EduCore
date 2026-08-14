<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/src/Modules/Students/StudentCompletenessConfigService.php';
require_once dirname(__DIR__) . '/src/Modules/Students/StudentCompletenessReadRepository.php';

use EduCore\Modules\Students\StudentCompletenessConfigService;
use EduCore\Modules\Students\StudentCompletenessReadRepository;

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($databaseName === 'educore' || !preg_match('/_test$/', $databaseName)) {
    throw new RuntimeException('Student completeness test database guard failed.');
}

foreach ([
    '20260726_student_annual_statuses.php',
    '20260729_academic_structure_experimental_scope.php',
] as $migrationFile) {
    $migration = require dirname(__DIR__) . '/database/migrations/' . $migrationFile;
    $migration($db);
}

$suffix = bin2hex(random_bytes(4));
$prefix = 'اكتمال-' . $suffix;
$checks = [];
$db->beginTransaction();

try {
    $year = $db->prepare(
        "INSERT INTO academic_years (name, start_date, end_date, is_active, locked, status)
         VALUES (?, '2195-09-01', '2196-06-30', 0, 0, 'active')"
    );
    $year->execute(['2195-2196-' . $suffix]);
    $yearId = (int) $db->lastInsertId();
    $year->execute(['2194-2195-' . $suffix]);
    $otherYearId = (int) $db->lastInsertId();

    $stage = $db->prepare(
        "INSERT INTO stages (stage_name, stage_code, stage_order, status, is_experimental)
         VALUES (?, ?, ?, 'active', ?)"
    );
    $stage->execute([$prefix . '-مرحلة رسمية', 'CMP-S-' . $suffix, 950, 0]);
    $stageId = (int) $db->lastInsertId();
    $stage->execute([$prefix . '-مرحلة تجريبية', 'CMP-X-' . $suffix, 951, 1]);
    $testStageId = (int) $db->lastInsertId();

    $grade = $db->prepare(
        "INSERT INTO grades (grade_name, grade_code, grade_order, stage_id, status, is_experimental)
         VALUES (?, ?, ?, ?, 'active', ?)"
    );
    $grade->execute([$prefix . '-صف رسمي', 'CMP-G-' . $suffix, 950, $stageId, 0]);
    $gradeId = (int) $db->lastInsertId();
    $grade->execute([$prefix . '-صف تجريبي', 'CMP-T-' . $suffix, 951, $testStageId, 0]);
    $testGradeId = (int) $db->lastInsertId();

    $class = $db->prepare(
        "INSERT INTO classes (name, grade_id, status, academic_year_id, display_order, is_experimental)
         VALUES (?, ?, 'active', ?, ?, ?)"
    );
    $class->execute([$prefix . '-فصل رسمي', $gradeId, $yearId, 950, 0]);
    $classId = (int) $db->lastInsertId();
    $class->execute([$prefix . '-فصل من عام آخر', $gradeId, $otherYearId, 951, 0]);
    $otherYearClassId = (int) $db->lastInsertId();
    $class->execute([$prefix . '-فصل تجريبي', $testGradeId, $yearId, 952, 0]);
    $testClassId = (int) $db->lastInsertId();

    $user = $db->prepare(
        "INSERT INTO users (name, username, role, status, is_test_account)
         VALUES (?, ?, 'student', 'active', ?)"
    );
    $profile = $db->prepare(
        "INSERT INTO student_profiles (user_id, student_code, first_name_ar, grade_id, enrollment_status)
         VALUES (?, ?, ?, ?, 'enrolled')"
    );
    $enrollment = $db->prepare(
        "INSERT INTO student_enrollments
         (student_id, academic_year_id, stage_id, grade_id, class_id, enrollment_status, academic_status, enrollment_date)
         VALUES (?, ?, ?, ?, ?, 'enrolled', ?, '2195-09-01')"
    );
    $createStudent = static function (
        string $name,
        bool $experimental,
        ?int $classIdValue,
        ?string $academicStatus,
        bool $withEnrollment,
        int $stageIdValue,
        int $gradeIdValue
    ) use ($db, $suffix, $yearId, $user, $profile, $enrollment): int {
        static $sequence = 0;
        $sequence++;
        $user->execute([$name, 'cmp_' . $suffix . '_' . $sequence, $experimental ? 1 : 0]);
        $studentId = (int) $db->lastInsertId();
        $profile->execute([$studentId, 'CMP' . $suffix . $sequence, $name, $gradeIdValue]);
        if ($withEnrollment) {
            $enrollment->execute([
                $studentId,
                $yearId,
                $stageIdValue,
                $gradeIdValue,
                $classIdValue,
                $academicStatus,
            ]);
        }
        return $studentId;
    };

    $createStudent($prefix . '-سليم', false, $classId, 'new', true, $stageId, $gradeId);
    $createStudent($prefix . '-راسب ينتظر', false, null, 'retained', true, $stageId, $gradeId);
    $createStudent($prefix . '-فصل خاطئ', false, $otherYearClassId, 'promoted', true, $stageId, $gradeId);
    $createStudent($prefix . '-بدون سجل', false, null, null, false, $stageId, $gradeId);
    $createStudent($prefix . '-تجريبي', true, $testClassId, 'new', true, $testStageId, $testGradeId);

    $config = (new StudentCompletenessConfigService($db))->load();
    $repository = new StudentCompletenessReadRepository($db, $yearId, $yearId, $config['fields']);
    $base = ['enrollment_status' => 'enrolled', 'search' => $prefix];
    $official = $repository->stats($base + ['experimental_scope' => 'official'], null);
    $all = $repository->stats($base + ['experimental_scope' => 'all'], null);
    $awaiting = $repository->stats($base + [
        'experimental_scope' => 'all',
        'annual_state' => 'awaiting_placement',
    ], null);
    $experimental = $repository->stats($base + ['experimental_scope' => 'experimental'], null);
    $scoped = $repository->stats($base + ['experimental_scope' => 'all'], [$classId]);
    $options = $repository->filterOptions(null);
    $optionClassIds = array_map('intval', array_column($options['classes'], 'id'));

    $checks['official_scope_excludes_test_student'] = $official['total'] === 4;
    $checks['annual_attention_counts_missing_waiting_and_inconsistent'] = $official['annual_attention'] === 3;
    $checks['all_scope_includes_test_student'] = $all['total'] === 5;
    $checks['stats_profile_partition_matches_total'] =
        $all['profile_complete'] + $all['profile_partial'] + $all['profile_critical'] === $all['total'];
    $checks['retained_student_without_class_is_awaiting_placement'] = $awaiting['total'] === 1;
    $checks['experimental_only_filter_is_effective'] = $experimental['total'] === 1;
    $checks['class_scope_excludes_unplaced_and_outside_classes'] = $scoped['total'] === 1;
    $checks['class_options_are_limited_to_selected_year'] = in_array($classId, $optionClassIds, true)
        && !in_array($otherYearClassId, $optionClassIds, true);

    $page = $repository->dataTable(
        $base + ['experimental_scope' => 'official'],
        null,
        0,
        25,
        'name',
        'asc'
    );
    $annualStates = array_column($page['data'], 'annual_state');
    $checks['datatable_and_stats_share_filtered_total'] = $page['recordsFiltered'] === $official['total'];
    $checks['missing_annual_record_is_visible_in_current_year'] = in_array('missing_enrollment', $annualStates, true);
    $checks['inconsistent_class_year_is_detected'] = in_array('inconsistent_structure', $annualStates, true);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$failed = false;
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
