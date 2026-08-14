<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap_test_database.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($databaseName !== 'educore_full_qa_test') {
    throw new RuntimeException('Visible QA verification is restricted to educore_full_qa_test.');
}

$scalar = static function (string $sql, array $params = []) use ($db) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};

$sourceYearId = (int) $scalar('SELECT id FROM academic_years WHERE name = ?', ['2025-2026']);
$targetYearId = (int) $scalar('SELECT id FROM academic_years WHERE name = ?', ['2026-2027']);
$adminHash = (string) $scalar('SELECT password_hash FROM users WHERE username = ?', ['qa_admin']);

$checks = [
    'isolated_database' => $databaseName === 'educore_full_qa_test',
    'qa_school_name_visible' => (string) $scalar(
        "SELECT setting_value FROM settings WHERE setting_key = 'school_name' LIMIT 1"
    ) === 'مدرسة EduCore التجريبية QA',
    'two_academic_years_exist' => (int) $scalar('SELECT COUNT(*) FROM academic_years') === 2,
    'target_year_is_active' => (int) $scalar('SELECT is_active FROM academic_years WHERE id = ?', [$targetYearId]) === 1,
    'source_year_is_locked' => (int) $scalar('SELECT locked FROM academic_years WHERE id = ?', [$sourceYearId]) === 1,
    'one_stage_exists' => (int) $scalar("SELECT COUNT(*) FROM stages WHERE stage_code = 'qa_primary'") === 1,
    'two_official_and_one_experimental_grades' => (int) $scalar(
        "SELECT COUNT(*) FROM grades WHERE grade_code IN ('QA_G1','QA_G2') AND is_experimental = 0"
    ) === 2 && (int) $scalar("SELECT COUNT(*) FROM grades WHERE grade_code = 'QA_TEST' AND is_experimental = 1") === 1,
    'admin_teacher_and_eight_students_exist' => (int) $scalar(
        "SELECT COUNT(*) FROM users WHERE username LIKE 'qa_%' AND deleted_at IS NULL"
    ) === 10,
    'all_students_have_profiles' => (int) $scalar(
        "SELECT COUNT(*) FROM student_profiles sp JOIN users u ON u.id = sp.user_id WHERE u.username LIKE 'qa_student_%'"
    ) === 8,
    'admin_password_hash_authenticates' => $adminHash !== '' && password_verify('QaAdmin!2026', $adminHash),
    'teacher_has_staff_profile' => (int) $scalar(
        "SELECT COUNT(*) FROM staff_profiles sp JOIN users u ON u.id = sp.user_id WHERE u.username = 'qa_teacher'"
    ) === 1,
    'source_has_eight_enrollments' => (int) $scalar(
        'SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = ?', [$sourceYearId]
    ) === 8,
    'target_has_three_enrollments' => (int) $scalar(
        'SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = ?', [$targetYearId]
    ) === 3,
    'target_enrollments_are_unassigned' => (int) $scalar(
        'SELECT COUNT(*) FROM student_enrollments WHERE academic_year_id = ? AND class_id IS NULL', [$targetYearId]
    ) === 3,
    'target_enrollments_have_lineage' => (int) $scalar(
        'SELECT COUNT(*) FROM student_enrollments
         WHERE academic_year_id = ? AND source_enrollment_id IS NOT NULL AND promotion_decision_id IS NOT NULL',
        [$targetYearId]
    ) === 3,
    'retained_student_is_repeater' => (int) $scalar(
        "SELECT COUNT(*) FROM student_enrollments se
         JOIN users u ON u.id = se.student_id
         WHERE se.academic_year_id = ? AND u.username = 'qa_student_omar'
           AND se.is_repeater = 1 AND se.repeat_count = 1 AND se.class_id IS NULL",
        [$targetYearId]
    ) === 1,
    'all_eight_decisions_accounted' => (int) $scalar(
        'SELECT COUNT(*) FROM student_promotion_decisions WHERE source_year_id = ? AND target_year_id = ?',
        [$sourceYearId, $targetYearId]
    ) === 8,
    'decision_distribution_is_expected' => (int) $scalar(
        "SELECT COUNT(*) FROM student_promotion_decisions
         WHERE source_year_id = ? AND target_year_id = ? AND decision = 'promoted'",
        [$sourceYearId, $targetYearId]
    ) === 2
        && (int) $scalar(
            "SELECT COUNT(*) FROM student_promotion_decisions
             WHERE source_year_id = ? AND target_year_id = ? AND decision = 'retained'",
            [$sourceYearId, $targetYearId]
        ) === 1
        && (int) $scalar(
            "SELECT COUNT(*) FROM student_promotion_decisions
             WHERE source_year_id = ? AND target_year_id = ? AND decision = 'graduated'",
            [$sourceYearId, $targetYearId]
        ) === 1
        && (int) $scalar(
            "SELECT COUNT(*) FROM student_promotion_decisions
             WHERE source_year_id = ? AND target_year_id = ? AND decision = 'transferred_out'",
            [$sourceYearId, $targetYearId]
        ) === 1
        && (int) $scalar(
            "SELECT COUNT(*) FROM student_promotion_decisions
             WHERE source_year_id = ? AND target_year_id = ? AND decision = 'withdrawn'",
            [$sourceYearId, $targetYearId]
        ) === 1
        && (int) $scalar(
            "SELECT COUNT(*) FROM student_promotion_decisions
             WHERE source_year_id = ? AND target_year_id = ? AND decision = 'excluded_test'",
            [$sourceYearId, $targetYearId]
        ) === 2,
    'attendance_history_remains_source_only' => (int) $scalar(
        'SELECT COUNT(*) FROM attendance WHERE academic_year_id = ?', [$sourceYearId]
    ) === 32 && (int) $scalar('SELECT COUNT(*) FROM attendance WHERE academic_year_id = ?', [$targetYearId]) === 0,
    'behavior_history_remains_source_only' => (int) $scalar(
        'SELECT COUNT(*) FROM evaluations WHERE academic_year_id = ?', [$sourceYearId]
    ) === 2 && (int) $scalar('SELECT COUNT(*) FROM evaluations WHERE academic_year_id = ?', [$targetYearId]) === 0,
    'five_marks_were_recorded' => (int) $scalar(
        'SELECT COUNT(*) FROM student_marks WHERE academic_year_id = ?', [$sourceYearId]
    ) === 5,
    'five_reports_were_published' => (int) $scalar(
        'SELECT COUNT(*) FROM published_reports WHERE academic_year_id = ?', [$sourceYearId]
    ) === 5,
    'published_snapshots_are_valid_json' => (int) $scalar(
        'SELECT COUNT(*) FROM published_reports WHERE academic_year_id = ? AND JSON_VALID(snapshot_json) = 1',
        [$sourceYearId]
    ) === 5,
    'grades_and_reports_not_copied_to_target' => (int) $scalar(
        'SELECT COUNT(*) FROM student_marks WHERE academic_year_id = ?', [$targetYearId]
    ) === 0 && (int) $scalar('SELECT COUNT(*) FROM published_reports WHERE academic_year_id = ?', [$targetYearId]) === 0,
    'annual_configuration_was_copied' => (int) $scalar(
        'SELECT COUNT(*) FROM academic_terms WHERE academic_year_id = ?', [$targetYearId]
    ) === 2 && (int) $scalar('SELECT COUNT(*) FROM classes WHERE academic_year_id = ?', [$targetYearId]) === 3,
    'target_classes_are_active_after_verified_activation' => (int) $scalar(
        "SELECT COUNT(*) FROM classes WHERE academic_year_id = ? AND status = 'active'", [$targetYearId]
    ) === 3,
    'two_verified_recovery_receipts_exist' => (int) $scalar(
        "SELECT COUNT(*) FROM recovery_backups WHERE status = 'verified'"
    ) >= 2,
    'one_verified_activated_rollover_exists' => (int) $scalar(
        "SELECT COUNT(*) FROM academic_year_rollover_runs WHERE status = 'activated' AND target_year_id = ?",
        [$targetYearId]
    ) === 1,
    'one_owned_rollback_is_recorded' => (int) $scalar(
        "SELECT COUNT(*) FROM academic_year_rollover_runs WHERE status = 'rolled_back' AND target_year_id = ?",
        [$targetYearId]
    ) === 1,
    'audit_records_exist' => (int) $scalar('SELECT COUNT(*) FROM activity_logs') > 0
        && (int) $scalar('SELECT COUNT(*) FROM undo_log') > 0,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
echo 'QA_CHECKS=' . count($checks) . PHP_EOL;
echo 'QA_FAILURES=' . count($failed) . PHP_EOL;
exit($failed === [] ? 0 : 1);
