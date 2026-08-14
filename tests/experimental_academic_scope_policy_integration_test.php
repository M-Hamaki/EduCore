<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/src/Modules/AcademicStructure/ExperimentalAcademicScopePolicy.php';

use EduCore\Modules\AcademicStructure\ExperimentalAcademicScopePolicy;

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($databaseName === 'educore' || !preg_match('/_test$/', $databaseName)) {
    throw new RuntimeException('Experimental academic scope test database guard failed.');
}

$migration = require dirname(__DIR__) . '/database/migrations/20260729_academic_structure_experimental_scope.php';
$migration($db);
$results = [];

$db->beginTransaction();
try {
    $stage = $db->prepare(
        "INSERT INTO stages (stage_name, stage_code, stage_order, status, is_experimental)
         VALUES (?, ?, ?, 'active', ?)"
    );
    $stage->execute(['مرحلة رسمية للحارس', 'scope_guard_official', 901, 0]);
    $officialStageId = (int) $db->lastInsertId();
    $stage->execute(['مرحلة تجريبية للحارس', 'scope_guard_test', 902, 1]);
    $testStageId = (int) $db->lastInsertId();

    $grade = $db->prepare(
        "INSERT INTO grades (grade_name, grade_code, grade_order, stage_id, status, is_experimental)
         VALUES (?, ?, ?, ?, 'active', ?)"
    );
    $grade->execute(['صف رسمي للحارس', 'scope_guard_g1', 901, $officialStageId, 0]);
    $officialGradeId = (int) $db->lastInsertId();
    $grade->execute(['صف موروث التجربة', 'scope_guard_g2', 902, $testStageId, 0]);
    $testGradeId = (int) $db->lastInsertId();

    $class = $db->prepare(
        "INSERT INTO classes (name, grade_id, status, academic_year_id, display_order, is_experimental)
         VALUES (?, ?, 'active', ?, ?, ?)"
    );
    $year = $db->prepare(
        "INSERT INTO academic_years (name, start_date, end_date, is_active, locked, status)
         VALUES (?, ?, ?, 0, 0, 'active')"
    );
    $year->execute(['2198-2199', '2198-09-01', '2199-06-30']);
    $yearId = (int) $db->lastInsertId();
    $class->execute(['فصل رسمي للحارس', $officialGradeId, $yearId, 901, 0]);
    $officialClassId = (int) $db->lastInsertId();
    $class->execute(['فصل موروث التجربة', $testGradeId, $yearId, 902, 0]);
    $testClassId = (int) $db->lastInsertId();
    $class->execute(['فصل مباشر التجربة', $officialGradeId, $yearId, 903, 1]);
    $directTestClassId = (int) $db->lastInsertId();
    $class->execute(['فصل فارغ للحارس', $officialGradeId, $yearId, 904, 0]);
    $emptyClassId = (int) $db->lastInsertId();

    $user = $db->prepare(
        "INSERT INTO users (name, username, role, status, is_test_account)
         VALUES (?, ?, 'student', 'active', ?)"
    );
    $user->execute(['طالب رسمي للحارس', 'scope_guard_official_student', 0]);
    $officialStudentId = (int) $db->lastInsertId();
    $user->execute(['طالب تجريبي للحارس', 'scope_guard_test_student', 1]);
    $testStudentId = (int) $db->lastInsertId();

    $enrollment = $db->prepare(
        "INSERT INTO student_enrollments
         (student_id, academic_year_id, stage_id, grade_id, class_id, enrollment_status, enrollment_date)
         VALUES (?, ?, ?, ?, ?, 'enrolled', '2198-09-01')"
    );
    $enrollment->execute([$officialStudentId, $yearId, $officialStageId, $officialGradeId, $officialClassId]);
    $enrollment->execute([$testStudentId, $yearId, $testStageId, $testGradeId, $testClassId]);

    $policy = new ExperimentalAcademicScopePolicy($db);
    $policy->assertSchemaReady();
    $results['stage_inheritance_marks_grade_and_class'] =
        $policy->gradeEffectiveExperimental($testGradeId)
        && $policy->classEffectiveExperimental($testClassId);
    $results['direct_class_flag_is_effective'] = $policy->classEffectiveExperimental($directTestClassId);
    $results['official_context_remains_official'] =
        !$policy->gradeEffectiveExperimental($officialGradeId)
        && !$policy->classEffectiveExperimental($officialClassId);

    $blocked = static function (callable $action): bool {
        try {
            $action();
            return false;
        } catch (InvalidArgumentException $e) {
            return true;
        }
    };
    $results['official_stage_cannot_be_retroactively_experimental'] = $blocked(
        static fn() => $policy->assertStageTransition($officialStageId, true)
    );
    $results['test_stage_cannot_become_official_with_test_students'] = $blocked(
        static fn() => $policy->assertStageTransition($testStageId, false)
    );
    $results['moving_official_grade_under_test_stage_is_blocked'] = $blocked(
        static fn() => $policy->assertGradeTransition($officialGradeId, $testStageId, false)
    );
    $results['moving_test_class_to_official_context_is_blocked'] = $blocked(
        static fn() => $policy->assertClassTransition($testClassId, $officialGradeId, false)
    );
    try {
        $policy->assertClassTransition($emptyClassId, $officialGradeId, true);
        $results['empty_class_can_change_classification'] = true;
    } catch (Throwable $e) {
        $results['empty_class_can_change_classification'] = false;
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
