<?php

require_once dirname(__DIR__) . '/classes/Presentation/ClassLists/ClassListExportSupport.php';

$checks = [
    'download_name_removes_header_metacharacters' => ClassListExportSupport::safeFileBase("قائمة\r\n\"/\\طلاب.xlsx") === 'قائمة_طلاب.xlsx',
    'worksheet_title_removes_invalid_characters' => ClassListExportSupport::safeWorksheetTitle('فصل:/?*[] تجريبي') === 'فصل_ تجريبي',
    'worksheet_title_is_limited_to_31_characters' => mb_strlen(ClassListExportSupport::safeWorksheetTitle(str_repeat('أ', 40)), 'UTF-8') === 31,
    'csv_formula_is_neutralized' => ClassListExportSupport::safeCsvValue('  =SUM(A1:A2)') === "'  =SUM(A1:A2)",
    'csv_at_formula_is_neutralized' => ClassListExportSupport::safeCsvValue('@IMPORTXML(A1)') === "'@IMPORTXML(A1)",
    'ordinary_csv_value_is_unchanged' => ClassListExportSupport::safeCsvValue('محمد أحمد') === 'محمد أحمد',
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
