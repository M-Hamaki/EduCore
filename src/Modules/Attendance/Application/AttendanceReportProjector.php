<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendanceReportProjectionRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceReportReadRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffAttendanceReportDimensionQuery;
use InvalidArgumentException;
use JsonException;

/**
 * Rebuilds a bounded monthly, annual, or explicitly ranged projection from a
 * stable as-of snapshot of official Attendance day versions.
 *
 * Source data is read before the write transaction. The as-of instant freezes
 * which official successor is selected, then the transaction appends only new
 * aggregate versions and records one mandatory audit event.
 */
final class AttendanceReportProjector
{
    private const GRANULARITIES = ['monthly', 'annual', 'range'];
    private const MAX_RANGE_DAYS = 366 * 5;
    private const MAX_BATCH_SIZE = 1000;

    private DateTimeZone $timezone;
    private DateTimeZone $utc;
    private Closure $clock;
    /** @var array<string,bool> */
    private array $validDateCache = [];
    /** @var array<string,array{string,string}> */
    private array $monthRangeCache = [];

    public function __construct(
        private AttendanceTransactionManager $transactions,
        private AttendanceReportReadRepository $days,
        private StaffAttendanceReportDimensionQuery $dimensions,
        private AttendanceReportProjectionRepository $projections,
        private AuditEventWriter $audit,
        ?DateTimeZone $timezone = null,
        ?callable $clock = null
    ) {
        $this->timezone = $timezone ?? new DateTimeZone('Africa/Cairo');
        $this->utc = new DateTimeZone('UTC');
        $this->clock = $clock === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : Closure::fromCallable($clock);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *     run_id:int,
     *     granularity:string,
     *     range_from:string,
     *     range_to:string,
     *     as_of:string,
     *     source_fingerprint:string,
     *     scanned_days:int,
     *     aggregate_count:int,
     *     created_count:int,
     *     superseded_count:int,
     *     unchanged_count:int,
     *     replayed:bool
     * }
     */
    public function project(int $actorId, array $input): array
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_PROJECTOR_ACTOR_INVALID');
        }
        $request = $this->normalizeRequest($input);
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new DomainException('ATTENDANCE_REPORT_PROJECTOR_CLOCK_INVALID');
        }
        $asOf = $now->setTimezone($this->utc);
        $built = $this->buildAggregates($request, $asOf);
        $requestFingerprint = $this->hash([
            'projection_version' => $request['projection_version'],
            'granularity' => $request['granularity'],
            'range_from' => $request['range_from'],
            'range_to' => $request['range_to'],
        ]);

        return $this->transactions->transactional(function () use (
            $actorId,
            $request,
            $asOf,
            $built,
            $requestFingerprint
        ): array {
            $existing = $this->projections->projectionRunByIdempotencyForUpdate($request['idempotency_key']);
            if ($existing !== null) {
                $summary = $this->decodeSummary($existing['summary'] ?? null);
                if (($summary['request_fingerprint'] ?? null) !== $requestFingerprint) {
                    throw new DomainException('ATTENDANCE_REPORT_PROJECTION_IDEMPOTENCY_CONFLICT');
                }

                return $this->receipt((int) ($existing['id'] ?? 0), $summary, true);
            }

            $runId = $this->projections->insertProjectionRun([
                'projection_version' => $request['projection_version'],
                'range_from' => $request['range_from'],
                'range_to' => $request['range_to'],
                'initiated_by' => $actorId,
                'status' => 'queued',
                'source_fingerprint' => $built['source_fingerprint'],
                'idempotency_key' => $request['idempotency_key'],
                'summary' => null,
                'started_at' => null,
                'finished_at' => null,
            ]);
            if ($runId <= 0 || !$this->projections->startProjectionRun($runId, $asOf)) {
                throw new DomainException('ATTENDANCE_REPORT_PROJECTION_RUN_STALE');
            }

            $created = 0;
            $superseded = 0;
            $unchanged = 0;
            foreach ($built['aggregates'] as $aggregate) {
                $current = $this->projections->currentAggregateForUpdate((string) $aggregate['aggregate_key']);
                if ($current !== null
                    && hash_equals(
                        (string) ($current['source_fingerprint'] ?? ''),
                        (string) $aggregate['source_fingerprint']
                    )) {
                    ++$unchanged;
                    continue;
                }

                $supersedesId = null;
                if ($current !== null) {
                    $supersedesId = (int) ($current['id'] ?? 0);
                    if ($supersedesId <= 0 || !$this->projections->retireCurrentAggregate($supersedesId)) {
                        throw new DomainException('ATTENDANCE_REPORT_AGGREGATE_STALE');
                    }
                    ++$superseded;
                }

                $aggregate['projection_run_id'] = $runId;
                $aggregate['is_current'] = 1;
                $aggregate['supersedes_id'] = $supersedesId;
                $aggregateId = $this->projections->insertAggregate($aggregate);
                if ($aggregateId <= 0) {
                    throw new DomainException('ATTENDANCE_REPORT_AGGREGATE_PERSISTENCE_FAILED');
                }
                ++$created;
            }

            $summary = [
                'result' => 'completed',
                'request_fingerprint' => $requestFingerprint,
                'source_fingerprint' => $built['source_fingerprint'],
                'granularity' => $request['granularity'],
                'range_from' => $request['range_from'],
                'range_to' => $request['range_to'],
                'as_of' => $asOf->format('Y-m-d H:i:s.u'),
                'scanned_days' => $built['scanned_days'],
                'aggregate_count' => count($built['aggregates']),
                'created_count' => $created,
                'superseded_count' => $superseded,
                'unchanged_count' => $unchanged,
            ];
            if (!$this->projections->completeProjectionRun($runId, $asOf, $summary)) {
                throw new DomainException('ATTENDANCE_REPORT_PROJECTION_RUN_STALE');
            }
            $this->audit->recordEvent(
                'staff_attendance_report_projected',
                'staff_attendance_report_projection_runs',
                $runId,
                null,
                [
                    'granularity' => $request['granularity'],
                    'range_from' => $request['range_from'],
                    'range_to' => $request['range_to'],
                    'aggregate_count' => count($built['aggregates']),
                    'created_count' => $created,
                    'superseded_count' => $superseded,
                    'unchanged_count' => $unchanged,
                ],
                [
                    'user_id' => $actorId,
                    'occurred_at' => $asOf->format('Y-m-d H:i:s.u'),
                ]
            );

            return $this->receipt($runId, $summary, false);
        });
    }

    /**
     * @param array<string,mixed> $request
     * @return array{aggregates:list<array<string,mixed>>,scanned_days:int,source_fingerprint:string}
     */
    private function buildAggregates(array $request, DateTimeImmutable $asOf): array
    {
        /** @var array<string,array<string,mixed>> $aggregates */
        $aggregates = [];
        /** @var array<string,string> $aggregateKeyByDescriptor */
        $aggregateKeyByDescriptor = [];
        $cursor = null;
        $scannedDays = 0;
        $previousCursor = null;
        while (true) {
            $rows = $this->days->officialDays([
                'date_from' => $request['range_from'],
                'date_to' => $request['range_to'],
                'staff_user_ids' => null,
                'as_of' => $asOf->format('Y-m-d H:i:s.u'),
                'scan_limit' => $request['batch_size'],
                'cursor' => $cursor,
            ]);
            if ($rows === []) {
                break;
            }

            $days = $this->normalizeDays($rows);
            $references = array_map(static fn (array $day): array => [
                'day_version_id' => $day['day_version_id'],
                'staff_user_id' => $day['staff_user_id'],
                'work_date' => $day['work_date'],
                'assignment_id' => $day['assignment_id'],
            ], $days);
            $dimensionResult = $this->dimensions->forAttendanceDays($references);
            if ((array) ($dimensionResult['conflicts'] ?? []) !== []) {
                throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
            }
            $dimensions = $this->normalizeDimensions((array) ($dimensionResult['dimensions'] ?? []), $days);
            $reasonLines = $this->reasonLinesByDay($this->days->reasonLinesForDayVersions(
                array_column($days, 'day_version_id')
            ));

            foreach ($days as $day) {
                $dayVersionId = (int) $day['day_version_id'];
                $dayCursor = [
                    (string) $day['work_date'],
                    (int) $day['staff_user_id'],
                    $dayVersionId,
                ];
                if ($previousCursor !== null && $this->compareCursor($dayCursor, $previousCursor) <= 0) {
                    throw new DomainException('ATTENDANCE_REPORT_PROJECTOR_CURSOR_INVALID');
                }
                $previousCursor = $dayCursor;
                $dimension = $dimensions[$dayVersionId] ?? null;
                if ($dimension === null || (int) $dimension['staff_user_id'] !== (int) $day['staff_user_id']) {
                    throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
                }
                $descriptor = $this->descriptor($day, $dimension, $request);
                $descriptorKey = $this->descriptorCacheKey($descriptor);
                $aggregateKey = $aggregateKeyByDescriptor[$descriptorKey] ?? null;
                if ($aggregateKey === null) {
                    $aggregateKey = $this->hash($descriptor);
                    $aggregateKeyByDescriptor[$descriptorKey] = $aggregateKey;
                }
                if (!isset($aggregates[$aggregateKey])) {
                    $aggregates[$aggregateKey] = $this->newAggregate($aggregateKey, $descriptor);
                }
                $this->accumulate(
                    $aggregates[$aggregateKey],
                    $day,
                    $this->reasonSummary($reasonLines[$dayVersionId] ?? [])
                );
                ++$scannedDays;
            }

            $last = $days[array_key_last($days)];
            $cursor = [
                'work_date' => $last['work_date'],
                'staff_user_id' => $last['staff_user_id'],
                'day_version_id' => $last['day_version_id'],
            ];
            if (count($rows) < $request['batch_size']) {
                break;
            }
        }

        ksort($aggregates, SORT_STRING);
        $sourceEntries = [];
        foreach ($aggregates as $aggregateKey => &$aggregate) {
            ksort($aggregate['reason_summary'], SORT_STRING);
            $sourceDigest = $this->finishSourceDigest($aggregate['source_hash_context'] ?? null);
            $aggregate['source_fingerprint'] = $this->hash([
                'descriptor' => $aggregate['descriptor'],
                'source_day_count' => (int) $aggregate['source_day_count'],
                'source_digest' => $sourceDigest,
            ]);
            $sourceEntries[] = [
                'aggregate_key' => $aggregateKey,
                'source_fingerprint' => $aggregate['source_fingerprint'],
            ];
            unset(
                $aggregate['descriptor'],
                $aggregate['source_hash_context'],
                $aggregate['source_day_count']
            );
        }
        unset($aggregate);

        return [
            'aggregates' => array_values($aggregates),
            'scanned_days' => $scannedDays,
            'source_fingerprint' => $this->hash($sourceEntries),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeRequest(array $input): array
    {
        $granularity = $input['granularity'] ?? 'range';
        if (!is_string($granularity) || !in_array($granularity, self::GRANULARITIES, true)) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_GRANULARITY_INVALID');
        }
        $from = $this->parseDate($input['range_from'] ?? null, 'ATTENDANCE_REPORT_RANGE_FROM_INVALID');
        $to = $this->parseDate($input['range_to'] ?? null, 'ATTENDANCE_REPORT_RANGE_TO_INVALID');
        if ($to < $from || (int) $from->diff($to)->days > self::MAX_RANGE_DAYS - 1) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_RANGE_INVALID');
        }
        if ($granularity === 'monthly'
            && ($from->format('d') !== '01' || $to->format('d') !== $to->format('t'))) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_MONTH_RANGE_INVALID');
        }
        if ($granularity === 'annual'
            && ($from->format('m-d') !== '01-01' || $to->format('m-d') !== '12-31')) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_YEAR_RANGE_INVALID');
        }

        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9:_-]{8,190}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_PROJECTION_IDEMPOTENCY_INVALID');
        }
        $version = trim((string) ($input['projection_version'] ?? 'attendance-report-v1'));
        if (preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $version) !== 1) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_PROJECTION_VERSION_INVALID');
        }
        $batchSize = (int) ($input['batch_size'] ?? self::MAX_BATCH_SIZE);
        if ($batchSize <= 0 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_PROJECTION_BATCH_INVALID');
        }

        return [
            'granularity' => $granularity,
            'range_from' => $from->format('Y-m-d'),
            'range_to' => $to->format('Y-m-d'),
            'projection_version' => $version,
            'idempotency_key' => $idempotencyKey,
            'batch_size' => $batchSize,
        ];
    }

    private function parseDate(mixed $value, string $error): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($error);
        }

        return $date;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeDays(array $rows): array
    {
        $days = [];
        $previous = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new DomainException('ATTENDANCE_REPORT_PROJECTOR_ROW_INVALID');
            }
            $dayVersionId = (int) ($row['day_version_id'] ?? 0);
            $staffUserId = (int) ($row['staff_user_id'] ?? 0);
            $workDate = (string) ($row['work_date'] ?? '');
            $assignmentId = (int) ($row['assignment_id'] ?? 0);
            $sourceFingerprint = (string) ($row['source_fingerprint'] ?? '');
            $status = trim((string) ($row['status'] ?? ''));
            if ($dayVersionId <= 0 || $staffUserId <= 0 || $assignmentId <= 0
                || !$this->validDate($workDate) || preg_match('/^[a-f0-9]{64}$/D', $sourceFingerprint) !== 1
                || $status === '') {
                throw new DomainException('ATTENDANCE_REPORT_PROJECTOR_ROW_INVALID');
            }
            $key = [$workDate, $staffUserId, $dayVersionId];
            if ($previous !== null && $this->compareCursor($key, $previous) <= 0) {
                throw new DomainException('ATTENDANCE_REPORT_PROJECTOR_CURSOR_INVALID');
            }
            $previous = $key;
            $days[] = [
                'day_version_id' => $dayVersionId,
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate,
                'assignment_id' => $assignmentId,
                'status' => $status,
                'source_fingerprint' => $sourceFingerprint,
                'required_minutes' => $this->nonNegative($row['required_minutes'] ?? 0),
                'worked_minutes' => $this->nonNegative($row['worked_minutes'] ?? 0),
                'covered_late_minutes' => $this->nonNegative($row['covered_late_minutes'] ?? 0),
                'covered_early_minutes' => $this->nonNegative($row['covered_early_minutes'] ?? 0),
                'mission_minutes' => $this->nonNegative($row['mission_minutes'] ?? 0),
                'leave_minutes' => $this->nonNegative($row['leave_minutes'] ?? 0),
                'late_minutes' => $this->nonNegative($row['late_minutes'] ?? 0),
                'early_leave_minutes' => $this->nonNegative($row['early_leave_minutes'] ?? 0),
                'missing_minutes' => $this->nonNegative($row['missing_minutes'] ?? 0),
            ];
        }

        return $days;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param list<array<string,mixed>> $days
     * @return array<int,array<string,mixed>>
     */
    private function normalizeDimensions(array $rows, array $days): array
    {
        $allowed = [];
        foreach ($days as $day) {
            $allowed[(int) $day['day_version_id']] = $day;
        }
        $dimensions = [];
        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
            }
            $dayVersionId = (int) ($row['day_version_id'] ?? $key);
            if (!isset($allowed[$dayVersionId]) || isset($dimensions[$dayVersionId])) {
                throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
            }
            $groups = array_values(array_unique(array_filter(
                array_map('intval', (array) ($row['group_ids'] ?? [])),
                static fn (int $groupId): bool => $groupId > 0
            )));
            sort($groups, SORT_NUMERIC);
            $dimensions[$dayVersionId] = [
                'staff_user_id' => (int) ($row['staff_user_id'] ?? 0),
                'assignment_id' => (int) ($row['assignment_id'] ?? 0),
                'org_unit_id' => isset($row['org_unit_id']) && (int) $row['org_unit_id'] > 0
                    ? (int) $row['org_unit_id']
                    : null,
                'job_title_id' => isset($row['job_title_id']) && (int) $row['job_title_id'] > 0
                    ? (int) $row['job_title_id']
                    : null,
                'group_ids' => $groups,
            ];
            if ($dimensions[$dayVersionId]['staff_user_id'] !== (int) $allowed[$dayVersionId]['staff_user_id']
                || $dimensions[$dayVersionId]['assignment_id'] !== (int) $allowed[$dayVersionId]['assignment_id']) {
                throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
            }
        }
        if (count($dimensions) !== count($days)) {
            throw new DomainException('ATTENDANCE_REPORT_DIMENSION_UNRESOLVED');
        }

        return $dimensions;
    }

    /** @param list<array<string,mixed>> $rows @return array<int,list<array<string,mixed>>> */
    private function reasonLinesByDay(array $rows): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dayVersionId = (int) ($row['day_version_id'] ?? 0);
            $reasonCode = trim((string) ($row['reason_code'] ?? ''));
            if ($dayVersionId <= 0 || preg_match('/^[A-Z][A-Z0-9_]{1,79}$/D', $reasonCode) !== 1) {
                throw new DomainException('ATTENDANCE_REPORT_REASON_INVALID');
            }
            $byDay[$dayVersionId][] = [
                'reason_code' => $reasonCode,
                'minutes' => $this->nonNegative($row['minutes'] ?? 0),
            ];
        }

        return $byDay;
    }

    /** @param array<string,mixed> $day @param array<string,mixed> $dimension @param array<string,mixed> $request */
    private function descriptor(array $day, array $dimension, array $request): array
    {
        [$rangeFrom, $rangeTo] = $this->aggregateRange((string) $day['work_date'], $request);

        return [
            'granularity' => $request['granularity'],
            'range_from' => $rangeFrom,
            'range_to' => $rangeTo,
            'staff_user_id' => (int) $day['staff_user_id'],
            'assignment_id' => (int) $dimension['assignment_id'],
            'org_unit_id' => $dimension['org_unit_id'],
            'job_title_id' => $dimension['job_title_id'],
            'group_ids' => $dimension['group_ids'],
        ];
    }

    /** @param array<string,mixed> $request @return array{string,string} */
    private function aggregateRange(string $workDate, array $request): array
    {
        if ($request['granularity'] === 'range') {
            return [$request['range_from'], $request['range_to']];
        }
        if ($request['granularity'] === 'monthly') {
            $month = substr($workDate, 0, 7);
            if (!isset($this->monthRangeCache[$month])) {
                $date = $this->parseDate($workDate, 'ATTENDANCE_REPORT_PROJECTOR_ROW_INVALID');
                $this->monthRangeCache[$month] = [
                    $date->modify('first day of this month')->format('Y-m-d'),
                    $date->modify('last day of this month')->format('Y-m-d'),
                ];
            }

            return $this->monthRangeCache[$month];
        }

        $year = substr($workDate, 0, 4);
        return [$year . '-01-01', $year . '-12-31'];
    }

    /** @param array<string,mixed> $descriptor @return array<string,mixed> */
    private function newAggregate(string $aggregateKey, array $descriptor): array
    {
        return [
            'aggregate_key' => $aggregateKey,
            'staff_user_id' => $descriptor['staff_user_id'],
            'granularity' => $descriptor['granularity'],
            'range_from' => $descriptor['range_from'],
            'range_to' => $descriptor['range_to'],
            'assignment_id' => $descriptor['assignment_id'],
            'org_unit_id' => $descriptor['org_unit_id'],
            'job_title_id' => $descriptor['job_title_id'],
            'group_ids' => $descriptor['group_ids'],
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
            'reason_summary' => [],
            'descriptor' => $descriptor,
            'source_hash_context' => hash_init('sha256'),
            'source_day_count' => 0,
        ];
    }

    /** @param array<string,mixed> $aggregate @param array<string,mixed> $day @param array<string,array{count:int,minutes:int}> $reasons */
    private function accumulate(array &$aggregate, array $day, array $reasons): void
    {
        $required = (int) $day['required_minutes'];
        $leave = (int) $day['leave_minutes'];
        $status = (string) $day['status'];
        if ($required > 0 && $leave < $required) {
            ++$aggregate['eligible_workdays'];
        }
        if ($status === 'present') {
            ++$aggregate['present_days'];
        } elseif ($status === 'absent' && $leave < $required) {
            ++$aggregate['absent_days'];
        } elseif ($status === 'partial') {
            ++$aggregate['partial_days'];
        } elseif ($status === 'non_working') {
            ++$aggregate['non_working_days'];
        } else {
            ++$aggregate['exception_days'];
        }
        if ((int) $day['covered_late_minutes'] + (int) $day['covered_early_minutes'] > 0) {
            ++$aggregate['approved_permission_days'];
        }
        if ((int) $day['mission_minutes'] > 0) {
            ++$aggregate['mission_days'];
        }
        if ($leave > 0) {
            ++$aggregate['leave_days'];
        }
        foreach ([
            'required_minutes' => $required,
            'worked_minutes' => (int) $day['worked_minutes'],
            'covered_minutes' => (int) $day['covered_late_minutes']
                + (int) $day['covered_early_minutes']
                + (int) $day['mission_minutes']
                + $leave,
            'late_minutes' => (int) $day['late_minutes'],
            'early_leave_minutes' => (int) $day['early_leave_minutes'],
            'missing_minutes' => (int) $day['missing_minutes'],
        ] as $field => $value) {
            $aggregate[$field] += $value;
        }
        foreach ($reasons as $reasonCode => $summary) {
            if (!isset($aggregate['reason_summary'][$reasonCode])) {
                $aggregate['reason_summary'][$reasonCode] = ['count' => 0, 'minutes' => 0];
            }
            $aggregate['reason_summary'][$reasonCode]['count'] += $summary['count'];
            $aggregate['reason_summary'][$reasonCode]['minutes'] += $summary['minutes'];
        }
        $this->appendSourceDay($aggregate, $day, $reasons);
    }

    /** @param list<array<string,mixed>> $reasonLines @return array<string,array{count:int,minutes:int}> */
    private function reasonSummary(array $reasonLines): array
    {
        $summary = [];
        foreach ($reasonLines as $reason) {
            $code = (string) $reason['reason_code'];
            if (!isset($summary[$code])) {
                $summary[$code] = ['count' => 0, 'minutes' => 0];
            }
            ++$summary[$code]['count'];
            $summary[$code]['minutes'] += (int) $reason['minutes'];
        }
        ksort($summary, SORT_STRING);

        return $summary;
    }

    /**
     * Appends a compact, delimiter-safe canonical source record to the
     * aggregate digest. This avoids retaining a nested per-day history just
     * to calculate a source fingerprint after a multi-year projection.
     *
     * @param array<string,mixed> $aggregate
     * @param array<string,mixed> $day
     * @param array<string,array{count:int,minutes:int}> $reasons
     */
    private function appendSourceDay(array &$aggregate, array $day, array $reasons): void
    {
        if (!isset($aggregate['source_hash_context'])) {
            throw new DomainException('ATTENDANCE_REPORT_PROJECTOR_SOURCE_INVALID');
        }
        ksort($reasons, SORT_STRING);
        $reasonParts = [];
        foreach ($reasons as $reasonCode => $summary) {
            $reasonParts[] = $reasonCode . ':' . (int) $summary['count'] . ':' . (int) $summary['minutes'];
        }
        $encoded = implode("\x1F", [
            (string) $day['day_version_id'],
            (string) $day['source_fingerprint'],
            base64_encode((string) $day['status']),
            (string) $day['required_minutes'],
            (string) $day['worked_minutes'],
            (string) $day['covered_late_minutes'],
            (string) $day['covered_early_minutes'],
            (string) $day['mission_minutes'],
            (string) $day['leave_minutes'],
            (string) $day['late_minutes'],
            (string) $day['early_leave_minutes'],
            (string) $day['missing_minutes'],
            implode('|', $reasonParts),
        ]);
        try {
            hash_update($aggregate['source_hash_context'], $encoded . "\n");
        } catch (\TypeError) {
            throw new DomainException('ATTENDANCE_REPORT_PROJECTOR_SOURCE_INVALID');
        }
        ++$aggregate['source_day_count'];
    }

    private function finishSourceDigest(mixed $context): string
    {
        try {
            return hash_final($context);
        } catch (\TypeError) {
            throw new DomainException('ATTENDANCE_REPORT_PROJECTOR_SOURCE_INVALID');
        }
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function decodeSummary(mixed $summary): array
    {
        if (is_array($summary)) {
            return $summary;
        }
        if (is_string($summary)) {
            try {
                $decoded = json_decode($summary, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (JsonException) {
            }
        }
        throw new DomainException('ATTENDANCE_REPORT_PROJECTION_RECEIPT_CORRUPT');
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function receipt(int $runId, array $summary, bool $replayed): array
    {
        return [
            'run_id' => $runId,
            'granularity' => (string) ($summary['granularity'] ?? ''),
            'range_from' => (string) ($summary['range_from'] ?? ''),
            'range_to' => (string) ($summary['range_to'] ?? ''),
            'as_of' => (string) ($summary['as_of'] ?? ''),
            'source_fingerprint' => (string) ($summary['source_fingerprint'] ?? ''),
            'scanned_days' => max(0, (int) ($summary['scanned_days'] ?? 0)),
            'aggregate_count' => max(0, (int) ($summary['aggregate_count'] ?? 0)),
            'created_count' => max(0, (int) ($summary['created_count'] ?? 0)),
            'superseded_count' => max(0, (int) ($summary['superseded_count'] ?? 0)),
            'unchanged_count' => max(0, (int) ($summary['unchanged_count'] ?? 0)),
            'replayed' => $replayed,
        ];
    }

    private function nonNegative(mixed $value): int
    {
        $number = (int) $value;
        if ($number < 0) {
            throw new DomainException('ATTENDANCE_REPORT_PROJECTOR_ROW_INVALID');
        }

        return $number;
    }

    private function validDate(string $value): bool
    {
        if (array_key_exists($value, $this->validDateCache)) {
            return $this->validDateCache[$value];
        }
        try {
            $this->parseDate($value, 'ATTENDANCE_REPORT_PROJECTOR_ROW_INVALID');
            return $this->validDateCache[$value] = true;
        } catch (InvalidArgumentException) {
            return $this->validDateCache[$value] = false;
        }
    }

    /** @param array<string,mixed> $descriptor */
    private function descriptorCacheKey(array $descriptor): string
    {
        return implode("\x1F", [
            (string) $descriptor['granularity'],
            (string) $descriptor['range_from'],
            (string) $descriptor['range_to'],
            (string) $descriptor['staff_user_id'],
            (string) $descriptor['assignment_id'],
            $descriptor['org_unit_id'] === null ? 'null' : (string) $descriptor['org_unit_id'],
            $descriptor['job_title_id'] === null ? 'null' : (string) $descriptor['job_title_id'],
            implode(',', (array) $descriptor['group_ids']),
        ]);
    }

    /** @param array{0:string,1:int,2:int} $left @param array{0:string,1:int,2:int} $right */
    private function compareCursor(array $left, array $right): int
    {
        if ($left[0] !== $right[0]) {
            return $left[0] <=> $right[0];
        }
        if ($left[1] !== $right[1]) {
            return $left[1] <=> $right[1];
        }

        return $left[2] <=> $right[2];
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_PROJECTION_FINGERPRINT_INVALID', 0, $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
