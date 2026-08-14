<?php

declare(strict_types=1);

$page = (string)file_get_contents(__DIR__ . '/../admin/student_numbers_reports.php');
$editor = (string)file_get_contents(__DIR__ . '/../assets/js/school-budget-editor.js');
$tableActions = (string)file_get_contents(__DIR__ . '/../assets/js/admin_table_actions.js');
$endpoint = (string)file_get_contents(__DIR__ . '/../admin/export_student_numbers_report.php');
$statementsPage = (string)file_get_contents(__DIR__ . '/../admin/statements.php');
$statementsEditor = (string)file_get_contents(__DIR__ . '/../assets/js/statements-editor.js');

$checks = [
    'budget action labels' => strpos($page, 'تصدير Excel') !== false
        && strpos($page, 'تصدير PDF') !== false
        && strpos($page, 'طباعة المستند') !== false
        && strpos($page, 'طباعة التقرير النشط') === false,
    'true xlsx client export keeps every report column' => strpos($tableActions, 'function exportTableToXlsx') !== false
        && strpos($tableActions, 'function buildTableExportGrid') !== false
        && strpos($tableActions, 'rowspan') !== false
        && strpos($tableActions, 'if (isElementHidden(cell))') !== false
        && strpos($page, 'excludeLastColumn: false') !== false
        && strpos($page, ".xlsx'") !== false,
    'xlsx endpoint is protected and uses phpspreadsheet' => strpos($endpoint, "Utilities::validateSession('admin')") !== false
        && strpos($endpoint, 'requireCsrfPost();') !== false
        && strpos($endpoint, 'new Spreadsheet()') !== false
        && strpos($endpoint, 'new Xlsx($spreadsheet)') !== false
        && strpos($endpoint, 'TYPE_STRING') !== false,
    'three budget tabs have explicit export identities' => strpos($page, "reportKey = 'detailed'") !== false
        && strpos($page, "reportKey = 'buffer'") !== false
        && strpos($page, "reportKey = 'historical'") !== false,
    'budget pdf reuses exact a4 print preparation' => strpos($page, 'id="budgetPdfBtn"') !== false
        && strpos($editor, "preparePrint('pdf')") !== false
        && strpos($editor, 'syncPrintPageRule(paper)') !== false
        && strpos($editor, 'window.prepareSchoolBudgetPrintMode') !== false,
    'statements pdf is shared across all statement types' => strpos($statementsPage, 'onclick="exportOfficialDocumentPdf()"') !== false
        && strpos($statementsEditor, 'window.exportOfficialDocumentPdf = function ()') !== false
        && strpos($statementsEditor, "printDocument('pdf')") !== false
        && strpos($statementsEditor, 'window.print();') !== false,
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "STUDENT_NUMBERS_EXPORT_CONTRACT_FAILED\n" . implode("\n", $failed) . "\n");
    exit(1);
}

echo "STUDENT_NUMBERS_EXPORT_CONTRACT_PASSED\n";
