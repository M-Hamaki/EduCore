<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\AttendanceReportScope;
use EduCore\Modules\Attendance\Presentation\AttendanceReportExporter;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $operation, string $expected, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), $expected), $message . ' (stable error code)');
    }
};
$row = static function (int $staffId, string $date, string $status = 'present'): array {
    return [
        'day_version_id' => 1000 + $staffId,
        'staff_user_id' => $staffId,
        'work_date' => $date,
        'status' => $status,
        'dimensions' => [
            'org_unit_id' => 3,
            'job_title_id' => 8,
            'group_ids' => [9, 4, 9],
        ],
        'expected_start' => $date . ' 07:30:00.000000',
        'expected_end' => $date . ' 14:30:00.000000',
        'first_in' => $date . ' 07:31:00.000000',
        'last_out' => $date . ' 14:30:00.000000',
        'metrics' => [
            'required_minutes' => 420,
            'worked_minutes' => 419,
            'covered_late_minutes' => 0,
            'covered_early_minutes' => 0,
            'mission_minutes' => 0,
            'leave_minutes' => 0,
            'late_minutes' => 1,
            'early_leave_minutes' => 0,
            'missing_minutes' => 0,
        ],
        'reasons' => [
            [
                'reason_code' => 'LATE_ARRIVAL',
                'minutes' => 1,
                'explanation' => 'لا يجب أن يظهر هذا النص في ملف التصدير.',
                'raw_payload_ref' => 'storage/private/raw-evidence.json',
            ],
        ],
    ];
};

$exporter = new AttendanceReportExporter();
$scope = AttendanceReportScope::forStaffIds([11]);
$chunks = [];
$exported = $exporter->streamCsv([$row(11, '2026-08-01')], $scope, static function (string $chunk) use (&$chunks): void {
    $chunks[] = $chunk;
});
$csv = implode('', $chunks);

$assert($exported === 1, 'CSV stream reports the actual count of emitted rows');
$assert(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV stream is explicitly UTF-8 BOM encoded for Arabic spreadsheet clients');
$assert(str_contains($csv, 'LATE_ARRIVAL:1'), 'CSV exports the whitelisted explainable reason code and duration');
$assert(!str_contains($csv, 'raw-evidence.json') && !str_contains($csv, 'لا يجب أن يظهر'), 'CSV never exports private raw evidence references or free-text reason explanations');
$assert(str_contains($csv, '4|9'), 'group identifiers are normalized deterministically in the export');

$formulaRow = $row(11, '2026-08-02', '=HYPERLINK("https://example.test","open")');
$formulaRow['reasons'][0]['reason_code'] = '@cmd';
$formulaChunks = [];
$exporter->streamCsv([$formulaRow], $scope, static function (string $chunk) use (&$formulaChunks): void {
    $formulaChunks[] = $chunk;
});
$formulaCsv = implode('', $formulaChunks);
$assert(str_contains($formulaCsv, "'=HYPERLINK"), 'formula-leading status values are emitted as literal spreadsheet cells');
$assert(str_contains($formulaCsv, "'@cmd:1"), 'formula-leading reason codes are emitted as literal spreadsheet cells');

$assertThrows(
    static fn () => $exporter->streamCsv([$row(99, '2026-08-03')], $scope, static function (string $chunk): void {}),
    'ATTENDANCE_REPORT_SCOPE_DENIED',
    'an export cannot serialize a forged staff row outside the supplied authorized scope'
);

$pageRows = [$row(11, '2026-08-04'), $row(11, '2026-08-05')];
$print = $exporter->renderPrintTable($pageRows, $scope);
$assert(str_contains($print, '2026-08-04') && str_contains($print, '2026-08-05'), 'print table preserves only the supplied paged detail rows');
$assert(substr_count($print, '<tbody>') === 1 && substr_count($print, '<tr>') === 3, 'print table has one header and one row per supplied page result');
$unsafePrintRow = $row(11, '2026-08-06', '<img src=x onerror=alert(1)>');
$unsafePrint = $exporter->renderPrintTable([$unsafePrintRow], $scope);
$assert(!str_contains($unsafePrint, '<img src=x') && str_contains($unsafePrint, '&lt;img'), 'print table HTML-escapes data fields');

$largeRowCount = 6000;
$streamedRows = 0;
$streamedBytes = 0;
$memoryBefore = memory_get_usage(true);
$largeRows = static function () use ($row, $largeRowCount): Generator {
    for ($index = 0; $index < $largeRowCount; ++$index) {
        yield $row(11, '2026-09-' . str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT));
    }
};
$emittedRows = $exporter->streamCsv($largeRows(), $scope, static function (string $chunk) use (&$streamedRows, &$streamedBytes): void {
    ++$streamedRows;
    $streamedBytes += strlen($chunk);
});
$memoryDelta = memory_get_usage(true) - $memoryBefore;
$assert($emittedRows === $largeRowCount && $streamedRows === $largeRowCount + 2, 'large exports stream a BOM, one header, and each row without pagination loss');
$assert($streamedBytes > $largeRowCount * 100, 'large exports emit data incrementally to the supplied writer');
$assert($memoryDelta < 8 * 1024 * 1024, 'large export streaming keeps bounded memory instead of materializing all CSV rows');

$exporterSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Presentation/AttendanceReportExporter.php'
);
$assert(!str_contains($exporterSource, 'use PDO;'), 'presentation exporter has no PDO dependency');
$assert(!str_contains($exporterSource, 'staff_assignments'), 'presentation exporter does not reach Staff-owned tables');
$assert(str_contains($exporterSource, 'protectSpreadsheetCell'), 'presentation exporter has an explicit formula-injection guard');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance export contract test failure(s).\n");
    exit(1);
}

echo "Attendance export contract tests passed.\n";
