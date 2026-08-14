<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'account_bulk' => (string)file_get_contents($root . '/assets/js/admin_bulk_actions.js'),
    'account_query' => (string)file_get_contents($root . '/classes/AccountListDataTableQuery.php'),
    'evaluation_reports' => (string)file_get_contents($root . '/admin/evaluation_reports.php'),
    'class_lists' => (string)file_get_contents($root . '/classes/Presentation/ClassLists/page_scripts.php'),
    'assessment_review' => (string)file_get_contents($root . '/teacher/assessment_review.php'),
    'student_file' => (string)file_get_contents($root . '/admin/student_file.php'),
    'student_id_cards' => (string)file_get_contents($root . '/admin/student_id_cards.php'),
];

$assertContains = static function (string $key, string $needle, string $message) use ($sources): void {
    if (!str_contains($sources[$key], $needle)) {
        throw new RuntimeException($message . ': ' . $needle);
    }
};

// Server-side account tables: current-page checkbox, pagination persistence,
// filter/search reset, and exact re-evaluation of all filtered results.
$assertContains('account_bulk', "tbody .row-select-cb", 'Account page selection must be scoped to rendered rows');
$assertContains('account_bulk', "selectedIds.add(val)", 'Account selections must persist by ID across page draws');
$assertContains('account_bulk', "search.dt", 'Account selections must reset when global search changes');
$assertContains('account_bulk', "payloadFilters.search_value", 'Filtered account actions must send the global search value');
$assertContains('account_bulk', "row-select-cb:checked", 'Leaving all-filtered mode must convert safely to current-page manual selection');
$assertContains('account_query', "filters['search_value']", 'Filtered account selection must apply the global search value server-side');
$assertContains('account_query', "appendSelectionSearch", 'Account filtered selection must share an explicit search contract');

// Evaluation reports: current-page selection persists across pagination but is
// cleared whenever any filter or global search changes.
$assertContains('evaluation_reports', 'تحديد الصفحة الحالية', 'Evaluation report selection scope must be explicit');
$assertContains('evaluation_reports', "selectedIds.delete(this.value)", 'Deselecting the current evaluation page must preserve other pages');
$assertContains('evaluation_reports', "clearEvaluationSelection", 'Evaluation report filters must clear stale selections');
$assertContains('evaluation_reports', "dataTable.on('search.dt', clearEvaluationSelection)", 'Evaluation global search must clear stale selections');
$assertContains('evaluation_reports', "dataTable.on('draw', function() { updateSelectionState(); })", 'Evaluation selections must resync after pagination');

// Non-paginated selection surfaces must remain scoped to their rendered data.
$assertContains('class_lists', "card.querySelectorAll('.select-student-chk')", 'Class-list select-all must stay inside one class card');
$assertContains('assessment_review', "document.querySelectorAll('.mark-check')", 'Assessment review must select all rendered mark rows');
$assertContains('student_file', "if (item.style.display !== 'none')", 'Student-file select-all must respect visible filters');
$assertContains('student_id_cards', "if (item.style.display !== 'none')", 'ID-card select-all must respect visible filters');

echo "TABLE_BULK_SELECTION_AUDIT_TEST_PASSED\n";
