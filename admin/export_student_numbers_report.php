<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('طريقة الطلب غير مسموحة.');
}

requireCsrfPost();
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function failStudentNumbersExport(string $message, int $status = 422): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

$rawPayload = file_get_contents('php://input');
if (!is_string($rawPayload) || $rawPayload === '' || strlen($rawPayload) > 5 * 1024 * 1024) {
    failStudentNumbersExport('بيانات التصدير غير صالحة أو أكبر من الحد المسموح.');
}

try {
    $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    failStudentNumbersExport('تعذر قراءة بيانات التصدير.');
}

$reportKey = (string)($payload['report_key'] ?? '');
$reportDefinitions = [
    'detailed' => [
        'sheet' => 'أعداد الطلاب التفصيلية',
        'filename' => 'student_numbers_detailed',
        'orientation' => PageSetup::ORIENTATION_PORTRAIT,
    ],
    'buffer' => [
        'sheet' => 'الزيادة المقترحة 10 بالمائة',
        'filename' => 'student_numbers_10_percent',
        'orientation' => PageSetup::ORIENTATION_PORTRAIT,
    ],
    'historical' => [
        'sheet' => 'الإحصاء التاريخي',
        'filename' => 'student_numbers_historical',
        'orientation' => PageSetup::ORIENTATION_LANDSCAPE,
    ],
];
if (!isset($reportDefinitions[$reportKey])) {
    failStudentNumbersExport('نوع التقرير المطلوب غير معروف.');
}

$rows = $payload['rows'] ?? null;
if (!is_array($rows) || $rows === [] || count($rows) > 5000) {
    failStudentNumbersExport('لا توجد بيانات صالحة للتصدير.');
}

$title = trim(strip_tags((string)($payload['title'] ?? $reportDefinitions[$reportKey]['sheet'])));
$title = preg_replace('/\s+/u', ' ', $title) ?: $reportDefinitions[$reportKey]['sheet'];
$title = function_exists('mb_substr') ? mb_substr($title, 0, 180, 'UTF-8') : substr($title, 0, 180);

$normalizedRows = [];
$maximumColumns = 0;
foreach ($rows as $row) {
    if (!is_array($row) || count($row) > 100) {
        failStudentNumbersExport('بنية صفوف التقرير غير صالحة.');
    }
    $normalizedRow = [];
    foreach ($row as $value) {
        $cellValue = trim((string)$value);
        $normalizedRow[] = function_exists('mb_substr')
            ? mb_substr($cellValue, 0, 1000, 'UTF-8')
            : substr($cellValue, 0, 1000);
    }
    $maximumColumns = max($maximumColumns, count($normalizedRow));
    $normalizedRows[] = $normalizedRow;
}
if ($maximumColumns < 1) {
    failStudentNumbersExport('لا توجد أعمدة قابلة للتصدير.');
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle($reportDefinitions[$reportKey]['sheet']);
$sheet->setRightToLeft(true);

$lastColumn = Coordinate::stringFromColumnIndex($maximumColumns);
$sheet->mergeCells('A1:' . $lastColumn . '1');
$sheet->setCellValueExplicit('A1', $title, DataType::TYPE_STRING);
$sheet->getRowDimension(1)->setRowHeight(30);

$columnWidths = array_fill(1, $maximumColumns, 10);
$excelRow = 3;
foreach ($normalizedRows as $rowIndex => $row) {
    foreach (range(1, $maximumColumns) as $columnIndex) {
        $coordinate = Coordinate::stringFromColumnIndex($columnIndex) . $excelRow;
        $value = $row[$columnIndex - 1] ?? '';
        if ($value !== '' && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
            $sheet->setCellValueExplicit($coordinate, (float)$value, DataType::TYPE_NUMERIC);
        } else {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        $columnWidths[$columnIndex] = min(32, max($columnWidths[$columnIndex], $length + 3));
    }
    $sheet->getRowDimension($excelRow)->setRowHeight($rowIndex === 0 ? 28 : 22);
    $excelRow++;
}

$lastDataRow = $excelRow - 1;
$tableRange = 'A3:' . $lastColumn . $lastDataRow;
$sheet->getStyle($tableRange)->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER)
    ->setWrapText(true);
$sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A3:' . $lastColumn . '3')->getFont()->setBold(true);
$sheet->getStyle('A3:' . $lastColumn . '3')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFEFF3F8');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);

for ($rowNumber = 4; $rowNumber <= $lastDataRow; $rowNumber++) {
    $rowText = '';
    for ($columnIndex = 1; $columnIndex <= $maximumColumns; $columnIndex++) {
        $rowText .= ' ' . (string)$sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $rowNumber)->getValue();
    }
    if (str_contains($rowText, 'الإجمالي')) {
        $sheet->getStyle('A' . $rowNumber . ':' . $lastColumn . $rowNumber)->getFont()->setBold(true);
        $sheet->getStyle('A' . $rowNumber . ':' . $lastColumn . $rowNumber)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
        $sheet->getRowDimension($rowNumber)->setRowHeight(26);
    }
}

foreach ($columnWidths as $columnIndex => $width) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setWidth($width);
}

$sheet->freezePane('A4');
$sheet->setAutoFilter('A3:' . $lastColumn . '3');
$sheet->getPageSetup()
    ->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setOrientation($reportDefinitions[$reportKey]['orientation'])
    ->setFitToWidth(1)
    ->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.35)->setRight(0.35)->setBottom(0.35)->setLeft(0.35);

$filename = $reportDefinitions[$reportKey]['filename'] . '_' . date('Y-m-d') . '.xlsx';
if (ob_get_length() !== false) {
    ob_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;
