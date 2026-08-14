<?php

declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../admin/student_numbers_reports.php');
$styles = file_get_contents(__DIR__ . '/../assets/css/student-numbers-reports.css');
$renderSource = $page . "\n" . $styles;
$editor = file_get_contents(__DIR__ . '/../assets/js/school-budget-editor.js');
$filters = file_get_contents(__DIR__ . '/../assets/js/school-budget-filters.js');
$tableActions = file_get_contents(__DIR__ . '/../assets/js/admin_table_actions.js');
$excelExport = file_get_contents(__DIR__ . '/../admin/export_student_numbers_report.php');

$checks = [
    'three independent report papers' => preg_match_all('/data-budget-paper="(?:detailed|buffer|historical)" data-print-orientation=/', $page, $paperMatches) === 3,
    'A4 portrait page' => strpos($page, 'size: A4 portrait') !== false,
    'A4 landscape page' => strpos($page, 'size: A4 landscape') !== false,
    'screen portrait dimensions' => strpos($renderSource, 'min-height: 1123px') !== false,
    'screen landscape dimensions' => strpos($renderSource, 'min-height: 794px') !== false,
    'A4 table density is scoped by tab without print-only shrinking' => substr_count($page, 'data-table-density="compact"') >= 1
        && substr_count($page, 'data-table-density="normal"') >= 2
        && strpos($page, 'padding: .25px 3px !important;') !== false
        && strpos($page, 'font-size: 9px !important;') === false,
    'screen table font defaults to fourteen pixels' => substr_count($renderSource, '--budget-table-font-size: 14px;') >= 2
        && strpos($page, 'font-size: 14px !important;') !== false,
    'A4 print stays inside safe printer margins' => substr_count($page, 'margin: 4mm;') >= 2
        && strpos($page, 'width: 202mm !important;') !== false
        && strpos($page, 'height: 289mm !important;') !== false
        && strpos($page, 'width: 289mm !important;') !== false
        && strpos($page, 'height: 202mm !important;') !== false
        && strpos($page, 'line-height: .96 !important;') !== false
        && strpos($page, 'height: 24px !important;') !== false
        && strpos($editor, "margin: 4mm") !== false,
    'fixed black table grid' => strpos($page, 'table-layout: fixed !important;') !== false
        && strpos($page, 'table.admin-data-table > tbody > tr > td') !== false
        && strpos($page, 'td[rowspan]') !== false,
    'body striping and stage badge removed' => substr_count($page, 'table table-hover table-striped admin-data-table') === 0
        && strpos($page, 'badge bg-light text-dark border px-2 py-1') === false,
    'detailed table columns prioritize text' => strpos($page, 'budget-col-grade') !== false
        && strpos($page, 'budget-col-class') !== false
        && strpos($page, 'budget-col-male') !== false
        && strpos($page, 'budget-col-female') !== false,
    'report table has no inner scrolling' => strpos($page, 'overflow-y: visible !important;') !== false
        && strpos($page, 'max-height: none !important;') !== false,
    'screen table height hierarchy' => strpos($page, 'table.admin-data-table > tbody > tr > td') !== false
        && strpos($page, 'table.admin-data-table > thead > tr > th') !== false
        && strpos($page, 'table.admin-data-table > tfoot > tr > td') !== false
        && strpos($page, 'padding: 2px 3px !important;') !== false,
    'independent storage key' => strpos($editor, "storagePrefix + tabId") !== false,
    'settings modal contains only report layout controls' => strpos($page, 'id="budgetPrintOrientation"') !== false
        && strpos($page, 'id="budgetPrintMargin"') !== false
        && strpos($page, 'id="budgetTableDensity"') !== false
        && strpos($page, 'id="budgetPrintMargin"') < strpos($page, 'id="budgetTableDensity"')
        && strpos($page, 'id="budgetPrintFont"') === false
        && strpos($page, 'id="budgetPrintTitle"') === false
        && strpos($page, 'id="budgetPrintDate"') === false
        && strpos($page, 'id="budgetAcademicYear"') === false
        && strpos($page, 'id="budgetShowPhoto"') === false
        && strpos($page, 'id="budgetShowTable"') === false,
    'print date and academic year remain direct-editable fields' => strpos($page, 'id="budgetPrintDate"') === false
        && strpos($page, 'id="budgetAcademicYear"') === false
        && substr_count($page, 'data-budget-field="printDate"') === 3
        && substr_count($page, 'data-budget-field="academicYear"') === 3
        && strpos($editor, 'readEditableField') !== false
        && strpos($editor, 'persistEditablePaperFields') !== false,
    'one print-settings entry per tab' => substr_count($page, 'data-budget-print-settings=') === 3
        && strpos($page, 'budgetPrintSettingsBtn') === false,
    'official header and footer structure' => substr_count($page, 'class="budget-official-header"') === 3
        && substr_count($page, 'class="budget-official-footer"') === 3,
    'header metadata is placed on the opposite side' => substr_count($page, 'class="budget-header-meta"') === 3
        && strpos($page, 'budget-sheet-meta') === false,
    'header metadata has official font sizing' => strpos($renderSource, 'font-size: 13px;') !== false,
    'header metadata uses requested wording and order' => substr_count($page, 'data-budget-ar="تحريرا في: "') === 3
        && substr_count($page, 'data-budget-ar="العام الدراسي: "') === 3
        && strpos($page, 'تاريخ الاستخراج:') === false
        && strpos($page, 'العام الدراسي الحالي:') === false
        && substr_count($page, 'data-budget-meta="academicYear"') >= 3
        && substr_count($page, 'data-budget-meta="printDate"') >= 3,
    'header metadata starts at the top without a blank line' => strpos($renderSource, '.budget-header-meta') !== false
        && strpos($renderSource, 'text-align: center;') !== false
        && strpos($renderSource, 'align-self: start;') !== false
        && strpos($renderSource, 'margin-top: 0;') !== false,
    'header metadata and logo have requested offsets' => strpos($renderSource, 'transform: translateX(-44px);') !== false
        && strpos($renderSource, 'transform: translateY(8px);') !== false
        && strpos($renderSource, 'data-budget-paper="historical"] .budget-header-meta') !== false
 && strpos($renderSource, 'transform: translateX(-132px);') !== false,
    'tab titles are centered above tables' => strpos($renderSource, 'grid-column: 1 / -1;') !== false
        && substr_count($page, 'class="budget-report-title"') === 3
        && strpos($page, 'font-size: 1.25rem;') !== false,
    'official system subtitle removed' => strpos($page, 'مستخرج رسمي من نظام شؤون الطلاب') === false,
    'buffer rows keep database order' => strpos($page, 'order: [],') !== false,
    'table cell text is centered' => strpos($page, 'text-align: center !important;') !== false
        && strpos($page, 'vertical-align: middle !important;') !== false
        && strpos($page, '> :not(caption) > * > *') !== false,
    'table grid and total separator are black' => strpos($page, 'border: 1px solid #000 !important;') !== false
        && strpos($page, 'border-color: #000 !important;') !== false
        && strpos($page, 'border-top: 2px solid #000 !important;') !== false,
    'header separator matches total separator and row heights are emphasized' => strpos($page, 'border-bottom: 3px solid #000 !important;') !== false
        && strpos($page, 'border-top: 3px solid #000 !important;') !== false
        && strpos($page, 'height: 38px !important;') !== false
        && strpos($page, 'height: 34px !important;') !== false,
    'optional paper border follows statements inner-frame behavior' => strpos($renderSource, 'data-show-border="true"]::before') !== false
        && strpos($renderSource, '--budget-border-padding-block: 18mm;') !== false
        && strpos($renderSource, '--budget-border-padding-inline: 16mm;') !== false
        && substr_count($renderSource, 'inset: 7mm') >= 2
        && strpos($page, 'page: budgetPortrait !important;') !== false
        && strpos($page, 'page: budgetLandscape !important;') !== false,
    'DataTables cells stay centered after redraw' => strpos($page, '.report-paper-sheet .dataTables_wrapper table.admin-data-table') !== false
        && strpos($page, "cell.style.setProperty('text-align', 'center', 'important')") !== false
        && strpos($page, "cell.style.setProperty('vertical-align', 'middle', 'important')") !== false,
    'column headings remain editable' => strpos($page, 'enableBudgetHeaderEditing') !== false
        && strpos($page, 'data-budget-editable-heading') !== false
        && substr_count($page, 'ordering: false') === 3,
    'all report papers are editable' => substr_count($page, 'class="report-paper-sheet shadow-sm budget-editable-paper"') === 3
        && substr_count($page, 'contenteditable="true"') >= 3,
    'budget formatting toolbar exists' => strpos($page, 'id="budgetEditorToolbar"') !== false
        && strpos($page, 'data-budget-command="bold"') !== false
        && strpos($page, 'id="budgetEditorFont"') !== false
        && strpos($editor, 'paper.parentNode.insertBefore(toolbar, paper)') !== false,
    'toolbar reflects selected text formatting' => strpos($editor, 'budgetSelectionContext') !== false
        && strpos($editor, 'syncBudgetFontSizeSelect') !== false
        && strpos($editor, 'queryBudgetCommandState') !== false
        && strpos($editor, 'aria-pressed') !== false,
    'font size applies the exact selected pixel value' => strpos($editor, 'span.style.fontSize = size') !== false
        && strpos($editor, 'range.extractContents()') !== false
        && strpos($editor, "executeBudgetCommand('fontSize', '7')") === false,
    'editor assets are cache versioned' => strpos($page, 'school-budget-editor.js?v=') !== false
        && strpos($page, 'school-budget-filters.js?v=') !== false,
    'title underline is controlled by editor settings' => substr_count($page, 'data-budget-field="title" data-budget-title-underline="true"') === 3
        && strpos($page, 'data-budget-title-underline="true"]') !== false
        && strpos($editor, 'titleUnderline: true') !== false
        && strpos($editor, "command === 'underline' && titleElement") !== false,
    'toolbar uses document wording' => strpos($page, 'المستند قابل للتعديل المباشر') !== false
        && strpos($page, 'الورقة النشطة قابلة للتعديل المباشر') === false,
    'statements-style print settings modal' => strpos($page, 'modal-dialog modal-lg modal-dialog-centered') !== false
        && strpos($page, 'عناصر وخيارات التقرير') !== false
        && strpos($page, 'إعدادات التقرير والبيانات') !== false,
    'print settings have live preview before save' => strpos($page, 'id="budgetLivePreviewStatus"') !== false
        && strpos($editor, "['input', 'change']") !== false
        && strpos($editor, 'previewSettingsFromForm') !== false
        && strpos($editor, 'hidden.bs.modal') !== false
        && strpos($editor, 'writeStorage(activeTab, settings)') !== false,
    'print settings footer closes without saving and separates save action' => strpos($page, 'modal-footer justify-content-between') !== false
        && strpos($page, 'data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق') !== false
        && strpos($page, 'id="budgetResetSettings"') === false
        && strpos($page, 'id="budgetApplySettings"') < strpos($page, 'data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق'),
    'signature presentation controls' => strpos($page, 'id="budgetSignatureMode"') !== false
        && strpos($editor, "signatureMode: 'titles_names'") !== false
        && strpos($editor, "settings.signatureMode === 'titles'") !== false
        && strpos($page, 'data-signature-mode="titles"') !== false
        && substr_count($page, 'id="budgetShowSignatures"') === 1
        && strpos($page, 'budget-signature-master-toggle') !== false
        && strpos($page, 'id="budgetShowSignatures"') < strpos($page, 'id="budgetShowStudentAffairs"'),
    'note visibility is grouped with note writing' => substr_count($page, 'id="budgetShowNote"') === 1
        && strpos($page, 'id="budgetShowNote"') < strpos($page, 'id="budgetPrintNote"'),
    'stage manager signature controls' => strpos($page, 'id="budgetShowKgDirector"') !== false
        && strpos($page, 'id="budgetShowPrimaryDirector"') !== false
        && strpos($page, 'id="budgetShowPrepSecDirector"') !== false
        && substr_count($page, 'data-budget-signature-col="stage_kg"') >= 3
        && substr_count($page, 'data-budget-signature-col="stage_primary"') >= 3
        && substr_count($page, 'data-budget-signature-col="stage_prep_sec"') >= 3
        && substr_count($page, 'مديرة المرحلة') >= 3
        && strpos($editor, 'sigKgDirector') !== false
        && strpos($editor, 'sigPrimaryDirector') !== false
        && strpos($editor, 'sigPrepSecDirector') !== false,
    'single signature is aligned to the left' => strpos($page, 'data-visible-signatures="1"') !== false
        && strpos($page, 'justify-self: end;') !== false,
    'stage signatures precede school principal' => strpos($page, 'data-budget-signature-col="stage_kg"] { order: 2; }') !== false
        && strpos($page, 'data-budget-signature-col="stage_prep_sec"] { order: 4; }') !== false
        && strpos($page, 'data-budget-signature-col="school_director"] { order: 5; }') !== false
        && substr_count($page, 'data-budget-ar="مدير المدرسة"') === 3
        && strpos($page, 'إدارة المدرسة') === false,
    'signature separator removed and font enlarged' => strpos($page, 'border-top: 0;') !== false
        && strpos($page, 'font-size: 1.15rem;') !== false,
    'per-tab stage grade class filters' => strpos($page, "'stageFilter'") !== false
        && strpos($page, "'classFilter'") !== false
        && strpos($page, "'bufferClassFilter'") !== false
        && strpos($page, "'historicalClassFilter'") !== false
        && strpos($filters, 'educore_school_budget_filters_') !== false
        && strpos($page, 'data-budget-class-counts') !== false,
    'filters support multiple selections' => strpos($page, 'data-budget-filter-option') !== false
        && strpos($filters, 'normalizeBudgetFilterValues') !== false
        && strpos($filters, 'selectedBudgetFilterValues') !== false
        && strpos($filters, 'محددة') !== false,
    'filters match enrolled-students dropdown pattern' => strpos($page, 'class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn') !== false
        && strpos($page, 'data-bs-auto-close="outside"') !== false
        && strpos($page, 'class="form-check mb-1 budget-multiselect-option ') !== false,
    'filters cascade dynamically' => strpos($filters, 'syncBudgetDependentOptions') !== false
        && strpos($filters, 'optionStage') !== false
        && strpos($filters, 'optionGrade') !== false
        && strpos($filters, "resetBudgetFilters('buffer')") !== false,
    'stage separators are heavy' => strpos($page, 'budget-stage-break') !== false
        && strpos($page, 'markBudgetStageBreaks') !== false
        && strpos($page, 'border-top: 2px solid #000 !important;') !== false,
    'print action prepares the visible paper before opening print dialog' => strpos($editor, 'window.prepareBudgetPrint') !== false
        && strpos($editor, 'window.prepareSchoolBudgetPrintMode') !== false
        && strpos($editor, "classList.add('school-budget-printing')") !== false
        && strpos($editor, "classList.toggle('budget-print-active'") !== false
        && strpos($editor, "style.id = 'schoolBudgetActivePageRule'") !== false
        && strpos($editor, "size: A4 ' + orientation") !== false,
    'document actions expose print pdf and true excel export' => strpos($page, 'id="budgetPrintBtn"') !== false
        && strpos($page, 'طباعة المستند') !== false
        && strpos($page, 'id="budgetPdfBtn"') !== false
        && strpos($page, 'تصدير PDF') !== false
        && strpos($editor, "preparePrint('pdf')") !== false
        && strpos($tableActions, 'function exportTableToXlsx') !== false
        && strpos($page, 'exportTableToXlsx(') !== false
        && strpos($page, 'excludeLastColumn: false') !== false,
    'excel export is authenticated csrf protected and writes xlsx' => strpos($excelExport, "Utilities::validateSession('admin')") !== false
        && strpos($excelExport, 'requireCsrfPost();') !== false
        && strpos($excelExport, 'new Xlsx($spreadsheet)') !== false
        && strpos($excelExport, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false
        && strpos($excelExport, "'detailed'") !== false
        && strpos($excelExport, "'buffer'") !== false
        && strpos($excelExport, "'historical'") !== false,
    'safe editable text' => strpos($editor, 'element.textContent = settings[field]') !== false,
    'experimental stages excluded' => substr_count($page, 'COALESCE(s.is_experimental, 0) = 0') >= 4,
    'experimental grades excluded' => substr_count($page, 'COALESCE(g.is_experimental, 0) = 0') >= 5,
    'experimental classes excluded' => substr_count($page, 'COALESCE(c.is_experimental, 0) = 0') >= 2
        && strpos($page, 'COALESCE(is_experimental, 0) = 0') !== false,
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "SCHOOL_BUDGET_PRINT_CONTRACT_FAILED\n" . implode("\n", $failed) . "\n");
    exit(1);
}

echo "SCHOOL_BUDGET_PRINT_CONTRACT_PASSED\n";
