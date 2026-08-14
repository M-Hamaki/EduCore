<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$enrollment = (string) file_get_contents($root . '/src/Modules/Students/StudentEnrollment.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentEnrollmentService.php');
$guardians = (string) file_get_contents($root . '/src/Modules/Students/StudentGuardianService.php');
$lifecycle = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileLifecycleService.php');
$commands = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');

$checks = [
    'enrollment_upsert_owns_or_joins_transaction' => strpos($enrollment, '$ownsTransaction = !$db->inTransaction()') !== false
        && strpos($enrollment, 'beginTransaction()') !== false
        && strpos($enrollment, '->commit()') !== false
        && strpos($enrollment, '->rollBack()') !== false,
    'enrollment_upsert_locks_existing_state' => strpos($enrollment, 'student_id = ? AND academic_year_id = ? FOR UPDATE') !== false,
    'enrollment_upsert_distinguishes_insert_and_update' => strpos($enrollment, "recordInsert('student_enrollment'") !== false
        && strpos($enrollment, "recordUpdate('student_enrollment'") !== false,
    'enrollment_is_registered_for_safe_undo' => strpos($policy, "'student_enrollments'") !== false,
    'related_student_state_tables_have_explicit_policy' => strpos($policy, "'student_external_transfers'") !== false
        && strpos($policy, "'assessment_student_locks'") !== false
        && strpos($policy, "'student_marks'") !== false,
    'external_transfer_upsert_is_locked_and_audited' => strpos($service, 'student_external_transfers WHERE student_id = ? FOR UPDATE') !== false
        && strpos($service, "recordInsert('student_external_transfer'") !== false
        && strpos($service, "recordUpdate('student_external_transfer'") !== false,
    'assessment_lock_lifecycle_is_fully_audited' => strpos($service, 'assessment_student_locks WHERE student_id = ? AND academic_year_id = ? FOR UPDATE') !== false
        && strpos($service, "recordInsert('assessment_student_lock'") !== false
        && strpos($service, "recordUpdate('assessment_student_lock'") !== false
        && strpos($service, "recordDelete('assessment_student_lock'") !== false,
    'assessment_mark_move_is_atomic_composite_update' => strpos($service, 'Assessment mark move count mismatch.') !== false
        && strpos($service, 'recordCompositeUpdate(') !== false
        && strpos($service, "'class_id_before'") !== false
        && strpos($service, "'class_id_after'") !== false,
    'enrollment_service_methods_own_or_join_transactions' => substr_count($service, '$ownsTransaction = !$this->db->inTransaction()') >= 3
        && substr_count($service, '->rollBack()') >= 3,
    'guardian_replace_is_locked_atomic_batch' => strpos($guardians, 'student_id = ? ORDER BY id FOR UPDATE') !== false
        && strpos($guardians, 'UndoManager::newBatchId()') !== false
        && strpos($guardians, "recordDelete('student_guardian'") !== false
        && strpos($guardians, "recordInsert('student_guardian'") !== false,
    'guardian_replace_drops_stale_ids' => strpos($guardians, "if (\$replaceExisting) unset(\$guardian['id']);") !== false,
    'guardian_update_is_audited' => strpos($guardians, "recordUpdate('student_guardian'") !== false
        && strpos($guardians, '->rollBack()') !== false,
    'lifecycle_sync_is_locked_atomic_and_audited' => strpos($lifecycle, "role = 'student' FOR UPDATE") !== false
        && strpos($lifecycle, 'recordUpdate(') !== false
        && strpos($lifecycle, '$parentAuditsUser = false') !== false,
    'profile_command_explicitly_owns_parent_user_audit' => substr_count($commands, "true\n        );") >= 2
        && substr_count($commands, '$this->lifecycle->sync(') === 2,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
