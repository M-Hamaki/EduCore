<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Presentation;

use DomainException;
use EduCore\Modules\Attendance\Application\AttendanceReportScope;
use InvalidArgumentException;
use RuntimeException;

/**
 * Streams a whitelisted official-attendance report representation.
 *
 * It deliberately consumes only the read DTO emitted by
 * AttendanceReportQueryService. It has no PDO dependency, does not resolve
 * Staff data, and never serializes private evidence references or reason
 * explanations. Callers must pass the already-authorized report scope.
 */
final class AttendanceReportExporter
{
    /** @var list<string> */
    private const CSV_HEADERS = [
        'رقم العامل',
        'تاريخ الدوام',
        'حالة الحضور',
        'القوة أو الوحدة',
        'المسمى الوظيفي',
        'المجموعات',
        'موعد الحضور المتوقع',
        'موعد الانصراف المتوقع',
        'أول بصمة حضور',
        'آخر بصمة انصراف',
        'دقائق مطلوبة',
        'دقائق عمل',
        'تغطية تأخير',
        'تغطية انصراف مبكر',
        'دقائق مأمورية',
        'دقائق إجازة',
        'دقائق تأخير غير مغطاة',
        'دقائق انصراف مبكر غير مغطاة',
        'دقائق ناقصة',
        'أكواد الأسباب',
    ];

    /**
     * Writes a UTF-8 CSV incrementally. The writer receives one small chunk
     * at a time, so callers can send a download without accumulating a large
     * report in PHP memory.
     *
     * @param iterable<array<string,mixed>> $rows
     * @param callable(string):void $write
     */
    public function streamCsv(iterable $rows, AttendanceReportScope $scope, callable $write): int
    {
        $write("\xEF\xBB\xBF");
        $this->writeCsvLine(self::CSV_HEADERS, $write);

        $count = 0;
        foreach ($rows as $row) {
            $normalized = $this->normalizeRow($row, $scope);
            $this->writeCsvLine(
                array_map([$this, 'protectSpreadsheetCell'], $this->csvCells($normalized)),
                $write
            );
            ++$count;
        }

        return $count;
    }

    /**
     * Produces a safe table fragment for an authorized print surface.
     *
     * @param iterable<array<string,mixed>> $rows
     */
    public function renderPrintTable(iterable $rows, AttendanceReportScope $scope): string
    {
        $html = '<div class="attendance-report-print" dir="rtl">'
            . '<table class="table table-bordered table-striped admin-data-table">'
            . '<thead><tr>';
        foreach (self::CSV_HEADERS as $header) {
            $html .= '<th scope="col">' . $this->escapeHtml($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $normalized = $this->normalizeRow($row, $scope);
            $html .= '<tr>';
            foreach ($this->csvCells($normalized) as $cell) {
                $html .= '<td>' . $this->escapeHtml($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(mixed $row, AttendanceReportScope $scope): array
    {
        if (!is_array($row)) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_EXPORT_ROW_INVALID');
        }

        $staffUserId = $this->positiveInteger($row['staff_user_id'] ?? null, 'ATTENDANCE_REPORT_EXPORT_STAFF_INVALID');
        $scope->assertStaffUserIdAllowed($staffUserId);

        $dimensions = $row['dimensions'] ?? [];
        if (!is_array($dimensions)) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_EXPORT_DIMENSION_INVALID');
        }
        $metrics = $row['metrics'] ?? [];
        if (!is_array($metrics)) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_EXPORT_METRICS_INVALID');
        }

        return [
            'staff_user_id' => (string) $staffUserId,
            'work_date' => $this->workDate($row['work_date'] ?? null),
            'status' => $this->requiredText($row['status'] ?? null, 'ATTENDANCE_REPORT_EXPORT_STATUS_INVALID'),
            'org_unit_id' => $this->nullableIdentifier($dimensions['org_unit_id'] ?? null),
            'job_title_id' => $this->nullableIdentifier($dimensions['job_title_id'] ?? null),
            'group_ids' => $this->groupIds($dimensions['group_ids'] ?? []),
            'expected_start' => $this->nullableText($row['expected_start'] ?? null),
            'expected_end' => $this->nullableText($row['expected_end'] ?? null),
            'first_in' => $this->nullableText($row['first_in'] ?? null),
            'last_out' => $this->nullableText($row['last_out'] ?? null),
            'required_minutes' => $this->nonNegativeInteger($metrics['required_minutes'] ?? null),
            'worked_minutes' => $this->nonNegativeInteger($metrics['worked_minutes'] ?? null),
            'covered_late_minutes' => $this->nonNegativeInteger($metrics['covered_late_minutes'] ?? null),
            'covered_early_minutes' => $this->nonNegativeInteger($metrics['covered_early_minutes'] ?? null),
            'mission_minutes' => $this->nonNegativeInteger($metrics['mission_minutes'] ?? null),
            'leave_minutes' => $this->nonNegativeInteger($metrics['leave_minutes'] ?? null),
            'late_minutes' => $this->nonNegativeInteger($metrics['late_minutes'] ?? null),
            'early_leave_minutes' => $this->nonNegativeInteger($metrics['early_leave_minutes'] ?? null),
            'missing_minutes' => $this->nonNegativeInteger($metrics['missing_minutes'] ?? null),
            'reason_codes' => $this->reasonCodes($row['reasons'] ?? []),
        ];
    }

    /** @param array<string,mixed> $row @return list<string> */
    private function csvCells(array $row): array
    {
        return [
            $row['staff_user_id'],
            $row['work_date'],
            $row['status'],
            $row['org_unit_id'],
            $row['job_title_id'],
            $row['group_ids'],
            $row['expected_start'],
            $row['expected_end'],
            $row['first_in'],
            $row['last_out'],
            $row['required_minutes'],
            $row['worked_minutes'],
            $row['covered_late_minutes'],
            $row['covered_early_minutes'],
            $row['mission_minutes'],
            $row['leave_minutes'],
            $row['late_minutes'],
            $row['early_leave_minutes'],
            $row['missing_minutes'],
            $row['reason_codes'],
        ];
    }

    /** @param list<string> $cells @param callable(string):void $write */
    private function writeCsvLine(array $cells, callable $write): void
    {
        $handle = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('ATTENDANCE_REPORT_EXPORT_STREAM_UNAVAILABLE');
        }

        try {
            if (fputcsv($handle, $cells) === false) {
                throw new RuntimeException('ATTENDANCE_REPORT_EXPORT_CSV_ENCODING_FAILED');
            }
            rewind($handle);
            $line = stream_get_contents($handle);
            if ($line === false) {
                throw new RuntimeException('ATTENDANCE_REPORT_EXPORT_STREAM_UNAVAILABLE');
            }
            $write($line);
        } finally {
            fclose($handle);
        }
    }

    private function protectSpreadsheetCell(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);

        return preg_match('/^[\x09\x0A\x0D\x20]*[=+\-@]/u', $value) === 1
            ? "'" . $value
            : $value;
    }

    private function workDate(mixed $value): string
    {
        $value = $this->requiredText($value, 'ATTENDANCE_REPORT_EXPORT_DATE_INVALID');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_EXPORT_DATE_INVALID');
        }

        return $value;
    }

    private function requiredText(mixed $value, string $error): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function nullableText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_EXPORT_TEXT_INVALID');
        }

        return trim((string) $value);
    }

    private function positiveInteger(mixed $value, string $error): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function nullableIdentifier(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) $this->positiveInteger($value, 'ATTENDANCE_REPORT_EXPORT_DIMENSION_INVALID');
    }

    private function nonNegativeInteger(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[0-9]{1,9}$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_EXPORT_METRICS_INVALID');
        }

        return (string) (int) $value;
    }

    private function groupIds(mixed $value): string
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_EXPORT_DIMENSION_INVALID');
        }
        $groups = [];
        foreach ($value as $groupId) {
            $groups[] = $this->positiveInteger($groupId, 'ATTENDANCE_REPORT_EXPORT_DIMENSION_INVALID');
        }
        $groups = array_values(array_unique($groups));
        sort($groups, SORT_NUMERIC);

        return implode('|', $groups);
    }

    private function reasonCodes(mixed $value): string
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_EXPORT_REASON_INVALID');
        }
        $items = [];
        foreach ($value as $reason) {
            if (!is_array($reason)) {
                throw new InvalidArgumentException('ATTENDANCE_REPORT_EXPORT_REASON_INVALID');
            }
            $code = $this->requiredText($reason['reason_code'] ?? null, 'ATTENDANCE_REPORT_EXPORT_REASON_INVALID');
            $minutes = $this->nonNegativeInteger($reason['minutes'] ?? null);
            $items[] = $code . ':' . $minutes;
        }

        return implode('; ', $items);
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
