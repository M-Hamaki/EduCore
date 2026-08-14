<?php

declare(strict_types=1);

$pagePath = __DIR__ . '/../admin/assessment_subject_assignments.php';
$cssPath = __DIR__ . '/../assets/css/admin-unified.css';
$source = file_get_contents($pagePath);
$css = file_get_contents($cssPath);

if ($source === false || $css === false) {
    fwrite(STDERR, "Unable to read assessment subject assignment UI sources.\n");
    exit(1);
}

$failures = [];

$expectContains = static function (string $needle, string $message) use ($source, &$failures): void {
    if (strpos($source, $needle) === false) {
        $failures[] = $message;
    }
};

$expectNotContains = static function (string $needle, string $message) use ($source, &$failures): void {
    if (strpos($source, $needle) !== false) {
        $failures[] = $message;
    }
};

$expectCssContains = static function (string $needle, string $message) use ($css, &$failures): void {
    if (strpos($css, $needle) === false) {
        $failures[] = $message;
    }
};

$expectContains(
    'id="assignmentPeriodTitle"',
    'The add modal must expose the unified academic-year and term section.'
);
$expectContains(
    'class="form-check-input assignment-grade-checkbox mt-0"',
    'The add modal must place a whole-grade checkbox beside the grade name.'
);
$expectNotContains(
    'clear-assignment-class-btn',
    'The add modal must let users clear class checkboxes individually without a separate reset button.'
);
$expectContains(
    'id="editAssignmentPeriodTitle"',
    'The edit modal must use the same academic-year and term structure as the add modal.'
);
$expectContains(
    'id="editAssignmentModal"',
    'The unified edit modal must remain available.'
);
$expectContains(
    'class="modal-dialog modal-xl modal-dialog-scrollable"',
    'The edit modal must use the same large scrollable layout as the add modal.'
);
$expectContains(
    'id="editSubjectAssignmentForm" class="modal-content admin-modal admin-modal-premium admin-modal-edit assessment-subject-assignment-modal"',
    'The grouped edit form must be the modal-content flex owner so the header and footer remain visible.'
);
$expectContains(
    'name="subject_id" id="assignmentSubject"',
    'The add modal must use a compact subject dropdown.'
);
$expectContains(
    'id="editAssignmentAcademicScope"',
    'The edit modal academic scope must expose an independent scroll target.'
);
$expectContains(
    'class="modal-body assessment-subject-assignment-modal-body"',
    'Both assignment modals must use the fixed-height body layout.'
);
$expectContains(
    'class="px-2 py-2 assignment-scope-list"',
    'Academic scope content must own the only desktop scroll area.'
);
$expectCssContains(
    '.assessment-subject-assignment-modal .assessment-subject-assignment-modal-body',
    'The unified CSS layer must own the assignment modal layout.'
);
$expectCssContains(
    'overflow: hidden;',
    'The desktop modal body must suppress the redundant outer scroll area.'
);
$expectCssContains(
    '.assessment-subject-assignment-modal .assignment-scope-list',
    'The unified CSS layer must define the academic scope scroll area.'
);
$expectContains(
    'name="action" value="sync_subject_assignment_group"',
    'The edit modal must submit one grouped synchronization command.'
);
$expectContains(
    'name="academic_year_id" id="editAssignmentYear"',
    'The edit modal must preserve the academic-year field contract.'
);
$expectContains(
    'name="original_subject_id" id="editOriginalAssignmentSubject"',
    'The edit modal must identify the original subject group safely.'
);
$expectContains(
    'name="original_term_id" id="editOriginalAssignmentTerm"',
    'The edit modal must identify the original term group safely.'
);
$expectContains(
    'name="subject_id" id="editAssignmentSubject"',
    'The edit modal must preserve the target subject field contract.'
);
$expectContains(
    'name="all_grade_ids[]"',
    'The grouped editor must submit all whole-grade scopes.'
);
$expectContains(
    'name="class_ids[]"',
    'The grouped editor must submit class scopes independently per grade.'
);
$expectContains(
    'name="status_mode" id="editAssignmentStatusMode"',
    'The grouped editor must handle uniform and mixed statuses explicitly.'
);
$expectContains(
    'name="notes_mode" id="editAssignmentNotesMode"',
    'The grouped editor must preserve mixed notes until the user replaces them.'
);
$expectContains(
    'name="subject_id" id="editAssignmentSubject"',
    'The edit modal must use the same compact subject dropdown as the add modal.'
);
$expectContains(
    'class="form-check-input edit-assignment-grade-checkbox mt-0"',
    'The edit modal must place a whole-grade checkbox beside the grade name.'
);
$expectContains(
    'function syncAssignmentScope',
    'The add modal must synchronize its visual scope with the submitted fields.'
);
$expectContains(
    'function syncEditAssignmentScope',
    'The edit modal must synchronize its visual scope with the submitted fields.'
);
$expectContains(
    'function syncEditAssignmentTermOptions',
    'The edit modal must limit term choices to the assignment academic year.'
);
$expectContains(
    'syncEditAssignmentTermOptions(assignmentYearId, String(firstDetail.termId || \'\'))',
    'Opening an edit modal must apply the assignment year before selecting its term.'
);
$expectContains(
    'function positionEditAssignmentModal()',
    'Opening the grouped editor must reset its outer and nested scroll positions.'
);
$expectContains(
    'selectedScopeInput.closest(\'.edit-assignment-stage-group\')',
    'The grouped editor must reveal the first selected academic stage when it opens.'
);
$expectContains(
    'showEditAssignmentGroupModal();',
    'Every grouped edit entrypoint must use the scroll-safe modal opener.'
);
$expectContains(
    'edit-assignment-group-btn',
    'Every grouped row must expose one direct group edit action.'
);
$expectContains(
    'toggle-assignment-group-btn',
    'Every row must expose the same group-level status action.'
);
$expectContains(
    'delete-assignment-group-btn',
    'Every row must expose the same group-level delete action.'
);
$expectNotContains(
    'view-assignment-details-btn',
    'The legacy details action must not create a second per-record workflow.'
);
$expectNotContains(
    'assignmentDetailsModal',
    'The legacy per-record details modal must be removed.'
);
$expectNotContains(
    'كل فصول الصف',
    'The repeated per-grade "all classes" choice must not be rendered.'
);
$expectNotContains(
    'edit-assignment-btn',
    'Per-record edit buttons must not remain in the grouped workflow.'
);
$expectNotContains(
    'refreshEditFilters',
    'The legacy edit-select filtering implementation must not remain.'
);
$expectNotContains(
    'assignment-subject-radio',
    'The expanded add-modal subject cards must not remain.'
);
$expectNotContains(
    'edit-assignment-subject-radio',
    'The expanded edit-modal subject cards must not remain.'
);
$expectNotContains(
    'assignment-grade-toggle',
    'Per-grade whole-selection buttons must be replaced by direct checkboxes.'
);
$expectNotContains(
    'edit-assignment-grade-toggle',
    'Per-grade grouped-edit selection buttons must be replaced by direct checkboxes.'
);
$expectNotContains(
    'clear-edit-assignment-class-btn',
    'The grouped editor must let users clear class checkboxes individually without a bulk-clear button.'
);
$expectNotContains(
    'assignment-class-radio',
    'The add modal must support multiple class checkboxes within one grade.'
);
$expectNotContains(
    'تحديد الصف يربط المادة بكل فصوله. لتخصيص فصل واحد اختر صفاً واحداً ثم اختر الفصل من داخله.',
    'The redundant add-modal scope notice must not consume modal space.'
);
$expectNotContains(
    'حدد الصف بالكامل من رأس البطاقة، أو اختر فصلاً أو أكثر من داخله.',
    'The redundant grouped-edit scope notice must not consume modal space.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "assessment subject assignment modal parity contract: OK\n");
