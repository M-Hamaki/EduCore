<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/assessment_teacher_assignments.php');

$checks = [
    'teacher_modal_uses_shared_scope_frame' =>
        strpos($page, 'assessment-subject-assignment-modal-dialog') !== false
        && strpos($page, 'assessment-subject-assignment-modal-body') !== false,
    'teacher_modal_uses_stage_grade_class_hierarchy' =>
        strpos($page, 'assignment-stage-group') !== false
        && strpos($page, 'assignment-grade-card') !== false
        && strpos($page, 'assignment-class-checkbox') !== false,
    'teacher_modal_has_whole_grade_and_stage_actions' =>
        strpos($page, 'name="all_grade_ids[]"') !== false
        && strpos($page, 'select-assignment-stage-btn') !== false
        && strpos($page, 'selectAllAssignmentGrades') !== false,
    'teacher_modal_preserves_partial_class_contract' => strpos($page, 'name="classes[]"') !== false,
    'teacher_handler_preserves_whole_grade_scope_for_future_classes' =>
        strpos($page, "\$_POST['all_grade_ids']") !== false
        && strpos($page, 'FROM grades WHERE id IN') !== false
        && strpos($page, "'class_id' => null") !== false
        && strpos($page, 'لا يمكن اختيار الصف بالكامل وفصول محددة منه في الوقت نفسه') !== false,
    'teacher_handler_supports_pending_activation_when_subject_link_is_missing' =>
        strpos($page, 'teacher_assignments_has_active_subject_scope') !== false
        && strpos($page, 'requested_active') !== false
        && strpos($page, 'missing_subject_link') !== false,
    'old_scope_controls_removed' =>
        strpos($page, 'select-grade-switch') === false
        && strpos($page, 'select-stage-btn') === false
        && strpos($page, 'selectAllClasses') === false
        && strpos($page, 'staff-scope-group') === false,
    'scope_instruction_note_removed' => strpos($page, 'اختيار فصل أو أكثر بدلاً من الصف بالكامل') === false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
