<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/20260802_teacher_assignment_pending_activation.php');
$activationService = (string) file_get_contents($root . '/classes/AssessmentTeacherAssignmentActivationService.php');
$teacherPage = (string) file_get_contents($root . '/admin/assessment_teacher_assignments.php');
$subjectService = (string) file_get_contents($root . '/classes/AssessmentSubjectAssignmentGroupService.php');
$subjectPage = (string) file_get_contents($root . '/admin/assessment_subject_assignments.php');
$listQuery = (string) file_get_contents($root . '/classes/AssessmentTeacherAssignmentListQuery.php');
$endpoint = (string) file_get_contents($root . '/admin/ajax_assessment_teacher_assignments_datatable.php');

$checks = [
    'migration_separates_requested_and_effective_activation' =>
        strpos($migration, 'requested_active') !== false
        && strpos($migration, 'pending_reason') !== false
        && strpos($migration, 'idx_tsa_activation_sync') !== false,
    'teacher_save_keeps_unlinked_scope_pending_instead_of_rejecting' =>
        strpos($teacherPage, 'teacher_assignments_has_active_subject_scope') !== false
        && strpos($teacherPage, "'missing_subject_link'") !== false
        && strpos($teacherPage, "'requested_active' => " . '$isActive') !== false
        && strpos($teacherPage, 'لا يمكن تعيين مادة لفصل قبل وجود ربط نشط') === false,
    'whole_grade_scope_is_persisted_without_expanding_to_current_classes' =>
        strpos($teacherPage, "['grade_id' => " . '$gradeId' . ", 'class_id' => null]") !== false
        && strpos($teacherPage, 'لا يمكن اختيار الصف بالكامل وفصول محددة منه في الوقت نفسه') !== false,
    'activation_service_is_audited_and_locks_rows' =>
        strpos($activationService, 'FOR UPDATE') !== false
        && strpos($activationService, 'ActivityLog::logChange') !== false
        && strpos($activationService, 'hasActiveSubjectLink') !== false
        && strpos($activationService, "'requested_active' => " . '$requestedActive') !== false,
    'subject_group_changes_reconcile_teacher_assignments_atomically' =>
        strpos($subjectService, 'synchronizeTeacherAssignments') !== false
        && strpos($subjectService, 'AssessmentTeacherAssignmentActivationService') !== false
        && strpos($subjectService, '$this->db->beginTransaction()') !== false
        && strpos($subjectService, "['batch_id' => (string) " . '$result' . "['batch_id']]") !== false,
    'direct_subject_link_updates_and_toggles_reconcile_assignments' =>
        strpos($subjectPage, 'single_subject_assignment_update') !== false
        && strpos($subjectPage, 'single_subject_assignment_toggle') !== false
        && strpos($subjectPage, 'subject_assignments_teacher_activation_ready') !== false,
    'list_and_endpoint_distinguish_pending_assignments' =>
        strpos($listQuery, 'pending_count') !== false
        && strpos($listQuery, 'whole_grade_ids') !== false
        && strpos($endpoint, 'بانتظار الربط') !== false
        && strpos($endpoint, 'data-whole-grade-ids') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
