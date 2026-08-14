<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sheetPage = (string) file_get_contents($root . '/admin/assessment_marks_sheet.php');
$registerPage = (string) file_get_contents($root . '/admin/assessment_marks.php');
$endpoint = (string) file_get_contents($root . '/admin/ajax_assessment_marks_sheet.php');
$updateEndpoint = (string) file_get_contents($root . '/admin/ajax_assessment_mark_update.php');
$bulkEndpoint = (string) file_get_contents($root . '/admin/ajax_assessment_marks_bulk.php');
$query = (string) file_get_contents($root . '/classes/AssessmentMarkSheetQuery.php');
$service = (string) file_get_contents($root . '/classes/AssessmentMarkAdministrationService.php');
$script = (string) file_get_contents($root . '/assets/js/assessment-marks-sheet.js');
$styles = (string) file_get_contents($root . '/assets/css/admin-unified.css');
$buttons = (string) file_get_contents($root . '/assets/css/buttons.css');
$header = (string) file_get_contents($root . '/includes/admin_header.php');
$setup = (string) file_get_contents($root . '/admin/assessment_setup.php');
$tabulator = (string) file_get_contents($root . '/assets/vendor/tabulator/6.5.0/tabulator.min.js');
$tabulatorLicense = (string) file_get_contents($root . '/assets/vendor/tabulator/6.5.0/LICENSE');

$checks = [
    'existing_register_page_is_preserved' => strpos($registerPage, 'AdminServerSideTable.init') !== false
        && strpos($registerPage, 'ajax_assessment_marks_datatable.php') !== false
        && strpos($registerPage, 'assessment_marks_sheet.php') !== false,
    'sheet_is_a_separate_authenticated_admin_page' => strpos($sheetPage, "Utilities::validateSession('admin');") !== false
        && strpos($sheetPage, 'assessment_marks.php') !== false
        && strpos($sheetPage, "require_once '../includes/admin_footer.php';") !== false,
    'sheet_writes_use_guarded_audited_endpoints' => strpos($updateEndpoint, 'AcademicYearWriteGuard') !== false
        && strpos($updateEndpoint, 'AssessmentMarkAdministrationService') !== false
        && strpos($updateEndpoint, 'updateMark(') !== false
        && strpos($updateEndpoint, 'createMark(') !== false
        && strpos($bulkEndpoint, 'bulkUpdateMarks(') !== false
        && strpos($bulkEndpoint, 'bulkApplyCells(') !== false
        && strpos($bulkEndpoint, 'deleteMarks(') !== false,
    'sheet_requires_grade_term_and_subject_scheme' => strpos($sheetPage, 'id="sheetGrade"') !== false
        && strpos($sheetPage, 'id="sheetTerm"') !== false
        && strpos($sheetPage, 'id="sheetSubjectTabs"') !== false
        && strpos($query, 'scheme.grade_id = ? AND scheme.term_id = ?') !== false,
    'sheet_includes_roster_and_historical_mark_students' => strpos($query, "enrollment.enrollment_status = 'enrolled'") !== false
        && strpos($query, '(enrollment.student_id IS NOT NULL OR latest_mark.id IS NOT NULL)') !== false
        && strpos($query, 'profile.student_code') !== false
        && strpos($query, 'student.username') !== false,
    'passwords_are_never_selected_or_rendered' => stripos($query, 'password') === false
        && stripos($sheetPage, 'password') === false
        && stripos($script, 'password') === false,
    'columns_follow_weeks_components_and_week_rules' => strpos($query, 'assessment_component_week_rules') !== false
        && strpos($query, 'max_grade_override') !== false
        && strpos($query, "'name' => 'أعمال عامة'") !== false
        && strpos($script, 'assessment-sheet-group-title') !== false
        && strpos($script, 'assessment-sheet-column-title') !== false,
    'excused_absence_uses_scheme_and_component_policy' => strpos($query, "!empty(\$scheme['enable_excused_absence'])") !== false
        && strpos($query, "!empty(\$component['accepts_excused_absence'])") !== false
        && strpos($service, 'scheme.enable_excused_absence') !== false
        && strpos($query, 'component.enable_excused_absence') === false
        && strpos($service, 'component.enable_excused_absence') === false,
    'sheet_supports_all_classes_search_and_missing_filter' => strpos($sheetPage, 'id="sheetClass"') !== false
        && strpos($sheetPage, 'id="sheetStudentSearch"') !== false
        && strpos($sheetPage, 'id="sheetMissingOnly"') !== false
        && strpos($script, 'applyRowFilters') !== false,
    'locked_and_published_marks_remain_visible_and_guarded' => strpos($query, 'student_locked') !== false
        && strpos($query, 'published_count') !== false
        && strpos($script, '!mark.locked || Boolean(config.isSuperAdmin)') !== false
        && strpos($script, 'نسخة منشورة') !== false
        && strpos($script, 'لا تتغير تلقائيًا') !== false,
    'sheet_endpoint_is_current_year_scoped_and_csrf_protected' => strpos($endpoint, 'requireCsrfPost();') !== false
        && strpos($endpoint, 'AcademicYear::getCurrent') !== false
        && strpos($endpoint, '$requestedYearId !== $academicYearId') !== false
        && strpos($updateEndpoint, 'requireCsrfPost();') !== false
        && strpos($bulkEndpoint, 'requireCsrfPost();') !== false,
    'bulk_updates_are_atomic_audited_and_bounded' => strpos($service, 'function bulkUpdateMarks(') !== false
        && strpos($service, '$this->db->beginTransaction();') !== false
        && strpos($service, '$batchId = UndoManager::newBatchId();') !== false
        && strpos($service, 'MAX_SELECTION = 200') !== false
        && strpos($service, "'تعديل جماعي لدرجات الطلاب: '") !== false,
    'sheet_supports_excel_like_cell_row_column_and_visible_selection' => strpos($script, 'selectedKeys: new Set()') !== false
        && strpos($script, 'function beginCellSelection(') !== false
        && strpos($script, 'function extendCellSelection(') !== false
        && strpos($script, 'function selectWholeRow(') !== false
        && strpos($script, 'function selectColumnFromHeader(') !== false
        && strpos($script, 'function structuredSelectedCells(') !== false
        && strpos($script, "viewport.addEventListener('copy'") !== false
        && strpos($script, "viewport.addEventListener('paste'") !== false
        && strpos($script, "event.key === 'Delete'") !== false
        && strpos($script, 'selectionCheckbox') === false
        && strpos($sheetPage, 'id="sheetSelectedCount"') !== false,
    'inline_editing_has_no_edit_modal' => strpos($script, 'function spreadsheetEditor(') !== false
        && strpos($script, "event.key === 'Enter'") !== false
        && strpos($script, 'state.editSeed = englishDigits(event.key)') !== false
        && strpos($script, "state.table.on('cellEdited'") !== false
        && strpos($script, 'queueCellSave(') !== false
        && strpos($sheetPage, 'id="sheetInlineReason"') === false
        && strpos($sheetPage, 'editMarkModal') === false,
    'missing_slots_require_one_live_window_and_are_audited' => strpos($query, '\'writable_windows\' => $writableWindows') !== false
        && strpos($query, 'function writableWindows(') !== false
        && strpos($service, 'function createMark(') !== false
        && strpos($service, 'count($matchingWindowIds) !== 1') !== false
        && strpos($service, 'recordInsert(') !== false
        && strpos($service, 'insertDomainAudit([], $after, \'create\'') !== false,
    'bulk_delete_is_super_admin_only_and_confirmed_by_bootstrap_modal' => strpos($sheetPage, 'if ($isSuperAdmin)') !== false
        && strpos($sheetPage, 'id="sheetDeleteSelectedModal"') !== false
        && strpos($sheetPage, 'admin-modal-delete') !== false
        && strpos($service, 'assertActorCanManage($actorId, $actorRole)') !== false,
    'english_digits_centering_and_cross_highlight_are_enforced' => strpos($script, "Intl.NumberFormat('en-US'") !== false
        && strpos($script, 'function englishDigits(') !== false
        && strpos($styles, '.assessment-sheet-mark-cell.is-column-hover') !== false
        && strpos($script, "state.table.on('rowMouseEnter'") !== false
        && strpos($styles, '.tabulator-row.is-sheet-row-hover') !== false
        && strpos($styles, '.assessment-sheet-viewport .tabulator-cell') !== false
        && strpos($styles, 'text-align: center') !== false,
    'sheet_column_geometry_and_frozen_layers_are_guarded' => strpos($styles, '.assessment-sheet-viewport .assessment-sheet-mark-cell') !== false
        && strpos($styles, 'max-width: none') !== false
        && strpos($styles, '.assessment-sheet-viewport .tabulator-cell.tabulator-frozen') !== false
        && strpos($styles, 'z-index: 20') !== false
        && strpos($styles, '.assessment-sheet-viewport .tabulator-col.tabulator-frozen') !== false
        && strpos($styles, 'z-index: 30') !== false,
    'load_errors_offer_a_scoped_retry_state' => strpos($script, 'assessment-sheet-empty-state alert alert-') !== false
        && strpos($script, "retry.addEventListener('click', () => loadSheet())") !== false
        && strpos($styles, '.assessment-sheet-empty-state') !== false,
    'spreadsheet_has_virtual_grid_frozen_identity_and_formula_bar' => strpos($sheetPage, 'tabulator.min.js') !== false
        && strpos($sheetPage, 'tabulator_bootstrap5.min.css') !== false
        && strpos($script, "renderVertical: 'virtual'") !== false
        && strpos($script, "layout: 'fitData'") !== false
        && strpos($script, 'frozen: true') !== false
        && strpos($sheetPage, 'id="sheetNameBox"') !== false
        && strpos($sheetPage, 'id="sheetFormulaText"') !== false
        && strpos($sheetPage, 'id="sheetSaveState"') !== false
        && strlen($tabulator) > 400000
        && strpos($tabulatorLicense, 'MIT License') !== false,
    'single_and_range_writes_detect_conflicts_and_retry_idempotently' => strpos($service, 'AssessmentMarkConflictException') !== false
        && strpos($service, 'matchesExpectedState(') !== false
        && strpos($service, 'matchesNormalizedState(') !== false
        && strpos($script, 'expected_updated_at') !== false
        && strpos($updateEndpoint, 'http_response_code($error instanceof AssessmentMarkConflictException ? 409') !== false
        && strpos($bulkEndpoint, 'http_response_code($error instanceof AssessmentMarkConflictException ? 409') !== false,
    'range_paste_and_clear_are_atomic_bounded_and_undoable' => strpos($script, "action: 'apply_cells'") !== false
        && strpos($script, 'changes: JSON.stringify(normalized)') !== false
        && strpos($script, 'مسح قيم نطاق من شيت الدرجات') !== false
        && strpos($service, 'function bulkApplyCells(') !== false
        && strpos($service, 'count($changes) > self::MAX_SELECTION') !== false
        && strpos($service, '$batchId = UndoManager::newBatchId();') !== false,
    'sheet_keeps_sensitive_data_out_of_browser_storage' => stripos($script, 'localStorage') === false
        && stripos($script, 'sessionStorage') === false
        && stripos($query, 'password') === false,
    'sheet_does_not_use_datatables_or_forbidden_confirmations' => strpos($sheetPage, 'jquery.dataTables') === false
        && strpos($script, 'new DataTable(') === false
        && strpos($sheetPage, 'confirm(') === false
        && strpos($script, 'confirm(') === false
        && strpos($sheetPage, 'Swal') === false,
    'sheet_is_discoverable_from_navigation_and_setup' => strpos($header, 'href="assessment_marks_sheet.php"') !== false
        && strpos($setup, "'href' => 'assessment_marks_sheet.php'") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
