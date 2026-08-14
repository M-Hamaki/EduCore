<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/assessment_subject_assignments.php');
$adminCss = (string) file_get_contents($root . '/assets/css/admin-unified.css');

$checks = [
    'add_modal_preserves_write_contract' =>
        strpos($page, 'name="action" value="assign_subject_grade"') !== false
        && strpos($page, 'name="academic_year_id"') !== false
        && strpos($page, 'name="term_id" id="assignmentTerm"') !== false
        && strpos($page, 'name="subject_id"') !== false
        && strpos($page, 'name="all_grade_ids[]"') !== false
        && strpos($page, 'name="class_ids[]"') !== false
        && strpos($page, 'name="notes" id="assignmentNotes"') !== false,
    'add_modal_uses_teacher_assignment_hierarchy' =>
        strpos($page, 'modal-xl modal-dialog-scrollable') !== false
        && strpos($page, 'name="subject_id" id="assignmentSubject"') !== false
        && strpos($page, 'assignment-stage-group') !== false
        && strpos($page, 'assignment-grade-checkbox') !== false
        && strpos($page, 'assignment-class-checkbox') !== false
        && strpos($page, 'select-assignment-stage-btn') !== false
        && strpos($page, 'selectAllAssignmentGrades') !== false,
    'grade_and_class_scope_are_synchronized' =>
        strpos($page, 'input.disabled = gradeSelected') !== false
        && strpos($page, 'selectedClasses.length + \' فصول\'') !== false
        && strpos($page, 'selectedWholeGrades.length + selectedClasses.length') !== false
        && strpos($page, 'assignment-class-radio') === false,
    'empty_and_invalid_choices_are_explained' =>
        strpos($page, 'assignmentSubjectFeedback') !== false
        && strpos($page, 'assignmentScopeFeedback') !== false
        && strpos($page, 'لا توجد مواد نشطة متاحة للربط') !== false
        && strpos($page, 'لا توجد صفوف نشطة متاحة للربط') !== false,
    'legacy_multi_select_controls_are_removed' =>
        strpos($page, 'id="assignmentGradeFilter"') === false
        && strpos($page, 'assignment-subject-radio') === false
        && strpos($page, 'edit-assignment-subject-radio') === false
        && strpos($page, 'clear-assignment-class-btn') === false
        && strpos($page, 'تحديد الصف يربط المادة بكل فصوله') === false,
    'term_choices_follow_fallback_year' =>
        strpos($page, 'data-year-id=') !== false
        && strpos($page, 'syncAssignmentTermOptions') !== false,
    'list_hides_redundant_year_but_preserves_internal_scope' =>
        strpos($page, '<th>العام</th>') === false
        && strpos($page, '<tr><td colspan="7"') !== false
        && strpos($page, "\$assignment['academic_year_name']") === false
        && strpos($page, "WHERE sga.academic_year_id = ?") !== false
        && strpos($page, "\$assignment['academic_year_id']") !== false,
    'scope_editor_is_expanded_without_internal_notes' =>
        substr_count($page, 'assessment-subject-assignment-modal-dialog') === 2
        && strpos($page, 'assignment-selection-summary') !== false
        && strpos($page, 'assignmentAcademicYearDisplay') === false
        && strpos($page, 'editAssignmentYearName') === false
        && strpos($page, 'id="assignmentSelectionSummary"') < strpos($page, 'id="assignmentScopeTitle"')
        && strpos($page, 'id="editAssignmentSelectionSummary"') < strpos($page, 'id="editAssignmentScopeTitle"')
        && strpos($adminCss, 'height: calc(100vh - 1.5rem)') !== false
        && strpos($adminCss, '--bs-modal-width: min(1480px') === false
        && strpos($adminCss, 'assignment-context-selection-summary') !== false
        && strpos($adminCss, 'flex: 1 1 32rem') !== false
        && strpos($page, 'يمكن تغيير المادة أو الترم للمجموعة كاملة ما لم ينتج تعارض مع مجموعة موجودة.') === false
        && strpos($page, 'سيتم تطبيق الملاحظة على كل النطاقات المحددة.') === false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
