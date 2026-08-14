<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendanceReportReadRepository;
use EduCore\Modules\Staff\Contracts\StaffAttendanceReportDimensionQuery;
use InvalidArgumentException;

/**
 * Builds an explainable report from official Attendance day versions.
 *
 * Organization dimensions are obtained only through Staff's historical
 * contract. This service never joins Staff, permission, leave, or raw
 * biometric tables and therefore cannot replace an official calculation.
 */
final class AttendanceReportQueryService
{
    private const MAX_RANGE_DAYS = 366 * 5;
    private const MAX_SCAN_ROWS = 10000;
    private const MAX_PAGE_SIZE = 250;
    private const STATUS_FILTERS = ['all', 'present', 'absent', 'partial', 'non_working', 'exception'];
    private const VIOLATION_FILTERS = ['all', 'absence', 'late', 'early_leave', 'missing', 'permission', 'mission', 'leave', 'exception'];

    private DateTimeZone $timezone;
    private DateTimeZone $utc;

    public function __construct(
        private AttendanceReportReadRepository $repository,
        private StaffAttendanceReportDimensionQuery $dimensions,
        ?DateTimeZone $timezone = null
    ) {
        $this->timezone = $timezone ?? new DateTimeZone('Africa/Cairo');
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *     filters:array<string,mixed>,
     *     report_as_of:?string,
     *     totals:array<string,int|float>,
     *     rows:list<array<string,mixed>>,
     *     page:array{number:int,size:int,total_rows:int,total_pages:int},
     *     warnings:list<string>
     * }
     */
    public function query(array $input, AttendanceReportScope $scope): array
    {
        $filters = $this->normalizeFilters($input);
        $staffUserIds = $scope->staffIdsFor($filters['staff_user_id']);
        $repositoryRows = $this->repository->officialDays([
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'staff_user_ids' => $staffUserIds,
            'as_of' => $filters['as_of'],
            'scan_limit' => self::MAX_SCAN_ROWS + 1,
        ]);
        if (count($repositoryRows) > self::MAX_SCAN_ROWS) {
            throw new DomainException('ATTENDANCE_REPORT_PROJECTION_REQUIRED');
        }

        $days = $this->normalizeDays($repositoryRows);
        $this->assertRowsWithinScope($days, $staffUserIds);
        $dimensions = $this->resolveDimensions($days);
        $filteredDays = [];
        foreach ($days as $day) {
            $dimension = $dimensions[(int) $day['day_version_id']] ?? null;
            if ($dimension === null) {
                throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
            }
            if (!$this->matchesDimensionFilters($dimension, $filters)
                || !$this->matchesStatusFilter($day, $filters['status'])
                || !$this->matchesViolationFilter($day, $filters['violation'])) {
                continue;
            }
            $day['dimensions'] = $dimension;
            $filteredDays[] = $day;
        }

        $totals = $this->totals($filteredDays);
        $totalRows = count($filteredDays);
        $totalPages = max(1, (int) ceil($totalRows / $filters['page_size']));
        $pageNumber = min($filters['page'], $totalPages);
        $offset = ($pageNumber - 1) * $filters['page_size'];
        $pageDays = array_slice($filteredDays, $offset, $filters['page_size']);
        $reasons = $this->reasonLines($pageDays);

        return [
            'filters' => $this->publicFilters($filters),
            'report_as_of' => $filters['as_of'],
            'totals' => $totals,
            'rows' => $this->presentRows($pageDays, $reasons),
            'page' => [
                'number' => $pageNumber,
                'size' => $filters['page_size'],
                'total_rows' => $totalRows,
                'total_pages' => $totalPages,
            ],
            'warnings' => $filters['as_of'] === null
                ? []
                : ['يعرض التقرير النسخة الرسمية النافذة حتى لحظة التقرير المطلوبة، بما في ذلك النسخ التي استبدلت لاحقًا.'],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeFilters(array $input): array
    {
        $today = new DateTimeImmutable('today', $this->timezone);
        $defaultFrom = $today->modify('first day of this month')->format('Y-m-d');
        $from = $this->parseDate($input['date_from'] ?? null, $defaultFrom, 'تاريخ بداية التقرير');
        $to = $this->parseDate($input['date_to'] ?? null, $today->format('Y-m-d'), 'تاريخ نهاية التقرير');
        if ($to < $from) {
            throw new InvalidArgumentException('تاريخ نهاية التقرير لا يمكن أن يسبق تاريخ البداية.');
        }
        if ((int) $from->diff($to)->days > self::MAX_RANGE_DAYS - 1) {
            throw new InvalidArgumentException('نطاق التقرير لا يمكن أن يتجاوز خمس سنوات.');
        }

        return [
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
            'staff_user_id' => $this->positiveId($input['staff_user_id'] ?? null, 'رقم العامل'),
            'org_unit_id' => $this->positiveId($input['org_unit_id'] ?? null, 'القوة أو الوحدة'),
            'job_title_id' => $this->positiveId($input['job_title_id'] ?? null, 'المسمى الوظيفي'),
            'group_id' => $this->positiveId($input['group_id'] ?? null, 'المجموعة'),
            'status' => $this->allowedValue($input['status'] ?? 'all', self::STATUS_FILTERS, 'حالة الحضور'),
            'violation' => $this->allowedValue($input['violation'] ?? 'all', self::VIOLATION_FILTERS, 'نوع المخالفة'),
            'as_of' => $this->parseAsOf($input['as_of'] ?? null),
            'page' => $this->positiveInt($input['page'] ?? 1, 'رقم الصفحة', 1, 100000),
            'page_size' => $this->positiveInt($input['page_size'] ?? 50, 'حجم الصفحة', 1, self::MAX_PAGE_SIZE),
        ];
    }

    private function parseDate(mixed $value, string $fallback, string $label): DateTimeImmutable
    {
        if ($value === null || $value === '') {
            $value = $fallback;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' غير صالح.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . ' غير صالح.');
        }

        return $date;
    }

    private function parseAsOf(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('وقت نسخة التقرير غير صالح.');
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($instant === false
            || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
            || $instant->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('وقت نسخة التقرير غير صالح.');
        }

        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    private function positiveId(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException($label . ' في الفلتر غير صالح.');
        }

        return (int) $value;
    }

    private function positiveInt(mixed $value, string $label, int $minimum, int $maximum): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[0-9]{1,9}$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException($label . ' غير صالح.');
        }
        $number = (int) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException($label . ' غير صالح.');
        }

        return $number;
    }

    /** @param list<string> $allowed */
    private function allowedValue(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' في الفلتر غير صالح.');
        }

        return $value;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeDays(array $rows): array
    {
        $days = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new DomainException('ATTENDANCE_REPORT_ROW_INVALID');
            }
            $dayVersionId = (int) ($row['day_version_id'] ?? 0);
            $staffUserId = (int) ($row['staff_user_id'] ?? 0);
            $workDate = (string) ($row['work_date'] ?? '');
            if ($dayVersionId <= 0 || $staffUserId <= 0 || !$this->isDate($workDate)
                || isset($days[$dayVersionId])) {
                throw new DomainException('ATTENDANCE_REPORT_ROW_INVALID');
            }
            $days[$dayVersionId] = [
                'day_version_id' => $dayVersionId,
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate,
                'assignment_id' => isset($row['assignment_id']) && (int) $row['assignment_id'] > 0
                    ? (int) $row['assignment_id']
                    : null,
                'schedule_policy_version_id' => isset($row['schedule_policy_version_id'])
                    ? (int) $row['schedule_policy_version_id']
                    : null,
                'calendar_exception_id' => isset($row['calendar_exception_id'])
                    ? (int) $row['calendar_exception_id']
                    : null,
                'expected_start' => $this->nullableText($row['expected_start'] ?? null),
                'expected_end' => $this->nullableText($row['expected_end'] ?? null),
                'first_in' => $this->nullableText($row['first_in'] ?? null),
                'last_out' => $this->nullableText($row['last_out'] ?? null),
                'required_minutes' => $this->nonNegative($row['required_minutes'] ?? 0),
                'worked_minutes' => $this->nonNegative($row['worked_minutes'] ?? 0),
                'covered_late_minutes' => $this->nonNegative($row['covered_late_minutes'] ?? 0),
                'covered_early_minutes' => $this->nonNegative($row['covered_early_minutes'] ?? 0),
                'mission_minutes' => $this->nonNegative($row['mission_minutes'] ?? 0),
                'leave_minutes' => $this->nonNegative($row['leave_minutes'] ?? 0),
                'late_minutes' => $this->nonNegative($row['late_minutes'] ?? 0),
                'early_leave_minutes' => $this->nonNegative($row['early_leave_minutes'] ?? 0),
                'missing_minutes' => $this->nonNegative($row['missing_minutes'] ?? 0),
                'status' => trim((string) ($row['status'] ?? '')),
                'run_id' => (int) ($row['run_id'] ?? 0),
                'calculation_mode' => trim((string) ($row['calculation_mode'] ?? '')),
                'engine_version' => trim((string) ($row['engine_version'] ?? '')),
                'source_fingerprint' => trim((string) ($row['source_fingerprint'] ?? '')),
                'officialized_at' => $this->nullableText($row['officialized_at'] ?? null),
            ];
            if ($days[$dayVersionId]['status'] === '') {
                throw new DomainException('ATTENDANCE_REPORT_ROW_INVALID');
            }
        }
        ksort($days, SORT_NUMERIC);

        return array_values($days);
    }

    /** @param list<array<string,mixed>> $days @return array<int,array<string,mixed>> */
    private function resolveDimensions(array $days): array
    {
        $references = array_map(static fn (array $day): array => [
            'day_version_id' => $day['day_version_id'],
            'staff_user_id' => $day['staff_user_id'],
            'work_date' => $day['work_date'],
            'assignment_id' => $day['assignment_id'],
        ], $days);
        $result = $this->dimensions->forAttendanceDays($references);
        $conflicts = (array) ($result['conflicts'] ?? []);
        if ($conflicts !== []) {
            throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
        }

        $dimensions = [];
        foreach ((array) ($result['dimensions'] ?? []) as $key => $dimension) {
            if (!is_array($dimension)) {
                throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
            }
            $dayVersionId = (int) ($dimension['day_version_id'] ?? $key);
            if ($dayVersionId <= 0 || isset($dimensions[$dayVersionId])) {
                throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
            }
            $groups = array_values(array_unique(array_filter(
                array_map('intval', (array) ($dimension['group_ids'] ?? [])),
                static fn (int $groupId): bool => $groupId > 0
            )));
            sort($groups, SORT_NUMERIC);
            $dimensions[$dayVersionId] = [
                'assignment_id' => (int) ($dimension['assignment_id'] ?? 0),
                'org_unit_id' => isset($dimension['org_unit_id']) && (int) $dimension['org_unit_id'] > 0
                    ? (int) $dimension['org_unit_id']
                    : null,
                'job_title_id' => isset($dimension['job_title_id']) && (int) $dimension['job_title_id'] > 0
                    ? (int) $dimension['job_title_id']
                    : null,
                'group_ids' => $groups,
            ];
            if ($dimensions[$dayVersionId]['assignment_id'] <= 0) {
                throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
            }
        }

        return $dimensions;
    }

    /** @param array<string,mixed> $dimension @param array<string,mixed> $filters */
    private function matchesDimensionFilters(array $dimension, array $filters): bool
    {
        return ($filters['org_unit_id'] === null || $dimension['org_unit_id'] === $filters['org_unit_id'])
            && ($filters['job_title_id'] === null || $dimension['job_title_id'] === $filters['job_title_id'])
            && ($filters['group_id'] === null || in_array($filters['group_id'], $dimension['group_ids'], true));
    }

    /** @param array<string,mixed> $day */
    private function matchesStatusFilter(array $day, string $filter): bool
    {
        if ($filter === 'all') {
            return true;
        }
        if ($filter === 'exception') {
            return !$this->knownStatus((string) $day['status']);
        }

        return (string) $day['status'] === $filter;
    }

    /** @param array<string,mixed> $day */
    private function matchesViolationFilter(array $day, string $filter): bool
    {
        return match ($filter) {
            'all' => true,
            'absence' => (string) $day['status'] === 'absent'
                && (int) $day['leave_minutes'] < (int) $day['required_minutes'],
            'late' => (int) $day['late_minutes'] > 0,
            'early_leave' => (int) $day['early_leave_minutes'] > 0,
            'missing' => (int) $day['missing_minutes'] > 0,
            'permission' => (int) $day['covered_late_minutes'] + (int) $day['covered_early_minutes'] > 0,
            'mission' => (int) $day['mission_minutes'] > 0,
            'leave' => (int) $day['leave_minutes'] > 0,
            'exception' => !$this->knownStatus((string) $day['status']),
        };
    }

    /** @param list<array<string,mixed>> $days @return array<string,int|float> */
    private function totals(array $days): array
    {
        $totals = [
            'official_days' => 0,
            'eligible_workdays' => 0,
            'present_days' => 0,
            'absent_days' => 0,
            'partial_days' => 0,
            'non_working_days' => 0,
            'exception_days' => 0,
            'approved_permission_days' => 0,
            'mission_days' => 0,
            'leave_days' => 0,
            'required_minutes' => 0,
            'worked_minutes' => 0,
            'covered_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'missing_minutes' => 0,
        ];
        foreach ($days as $day) {
            ++$totals['official_days'];
            $required = (int) $day['required_minutes'];
            $leave = (int) $day['leave_minutes'];
            $status = (string) $day['status'];
            if ($required > 0 && $leave < $required) {
                ++$totals['eligible_workdays'];
            }
            if ($status === 'present') {
                ++$totals['present_days'];
            } elseif ($status === 'absent' && $leave < $required) {
                ++$totals['absent_days'];
            } elseif ($status === 'partial') {
                ++$totals['partial_days'];
            } elseif ($status === 'non_working') {
                ++$totals['non_working_days'];
            } elseif (!$this->knownStatus($status)) {
                ++$totals['exception_days'];
            }
            if ((int) $day['covered_late_minutes'] + (int) $day['covered_early_minutes'] > 0) {
                ++$totals['approved_permission_days'];
            }
            if ((int) $day['mission_minutes'] > 0) {
                ++$totals['mission_days'];
            }
            if ($leave > 0) {
                ++$totals['leave_days'];
            }
            $totals['required_minutes'] += $required;
            $totals['worked_minutes'] += (int) $day['worked_minutes'];
            $totals['covered_minutes'] += (int) $day['covered_late_minutes']
                + (int) $day['covered_early_minutes']
                + (int) $day['mission_minutes']
                + $leave;
            $totals['late_minutes'] += (int) $day['late_minutes'];
            $totals['early_leave_minutes'] += (int) $day['early_leave_minutes'];
            $totals['missing_minutes'] += (int) $day['missing_minutes'];
        }
        $totals['absence_percentage'] = $totals['eligible_workdays'] === 0
            ? 0.0
            : round(($totals['absent_days'] / $totals['eligible_workdays']) * 100, 2);

        return $totals;
    }

    /** @param list<array<string,mixed>> $days @return array<int,list<array<string,mixed>>> */
    private function reasonLines(array $days): array
    {
        $ids = array_map(static fn (array $day): int => (int) $day['day_version_id'], $days);
        $knownIds = array_fill_keys($ids, true);
        $byDay = [];
        foreach ($this->repository->reasonLinesForDayVersions($ids) as $reason) {
            if (!is_array($reason)) {
                continue;
            }
            $dayVersionId = (int) ($reason['day_version_id'] ?? 0);
            if (!isset($knownIds[$dayVersionId])) {
                continue;
            }
            $reasonCode = trim((string) ($reason['reason_code'] ?? ''));
            if ($reasonCode === '') {
                continue;
            }
            $byDay[$dayVersionId][] = [
                'reason_code' => $reasonCode,
                'from_at' => $this->nullableText($reason['from_at'] ?? null),
                'to_at' => $this->nullableText($reason['to_at'] ?? null),
                'minutes' => $this->nonNegative($reason['minutes'] ?? 0),
                'source_type' => trim((string) ($reason['source_type'] ?? 'attendance')),
                'source_id' => isset($reason['source_id']) && (int) $reason['source_id'] > 0
                    ? (int) $reason['source_id']
                    : null,
                'explanation' => $this->nullableText($reason['explanation'] ?? null),
            ];
        }

        return $byDay;
    }

    /**
     * @param list<array<string,mixed>> $days
     * @param array<int,list<array<string,mixed>>> $reasons
     * @return list<array<string,mixed>>
     */
    private function presentRows(array $days, array $reasons): array
    {
        $rows = [];
        foreach ($days as $day) {
            $rows[] = [
                'day_version_id' => $day['day_version_id'],
                'staff_user_id' => $day['staff_user_id'],
                'work_date' => $day['work_date'],
                'dimensions' => $day['dimensions'],
                'official_version' => [
                    'run_id' => $day['run_id'],
                    'calculation_mode' => $day['calculation_mode'],
                    'engine_version' => $day['engine_version'],
                    'officialized_at' => $day['officialized_at'],
                ],
                'schedule_policy_version_id' => $day['schedule_policy_version_id'],
                'calendar_exception_id' => $day['calendar_exception_id'],
                'status' => $day['status'],
                'expected_start' => $day['expected_start'],
                'expected_end' => $day['expected_end'],
                'first_in' => $day['first_in'],
                'last_out' => $day['last_out'],
                'metrics' => [
                    'required_minutes' => $day['required_minutes'],
                    'worked_minutes' => $day['worked_minutes'],
                    'covered_late_minutes' => $day['covered_late_minutes'],
                    'covered_early_minutes' => $day['covered_early_minutes'],
                    'mission_minutes' => $day['mission_minutes'],
                    'leave_minutes' => $day['leave_minutes'],
                    'late_minutes' => $day['late_minutes'],
                    'early_leave_minutes' => $day['early_leave_minutes'],
                    'missing_minutes' => $day['missing_minutes'],
                ],
                'reasons' => $reasons[(int) $day['day_version_id']] ?? [],
            ];
        }

        return $rows;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function publicFilters(array $filters): array
    {
        unset($filters['as_of']);
        return $filters;
    }

    private function nonNegative(mixed $value): int
    {
        $number = (int) $value;
        if ($number < 0) {
            throw new DomainException('ATTENDANCE_REPORT_ROW_INVALID');
        }

        return $number;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }

    private function knownStatus(string $status): bool
    {
        return in_array($status, ['present', 'absent', 'partial', 'non_working'], true);
    }

    /** @param list<array<string,mixed>> $days @param list<int>|null $staffUserIds */
    private function assertRowsWithinScope(array $days, ?array $staffUserIds): void
    {
        if ($staffUserIds === null) {
            return;
        }
        $allowed = array_fill_keys($staffUserIds, true);
        foreach ($days as $day) {
            if (!isset($allowed[(int) $day['staff_user_id']])) {
                throw new DomainException('ATTENDANCE_REPORT_SCOPE_LEAK_BLOCKED');
            }
        }
    }
}
