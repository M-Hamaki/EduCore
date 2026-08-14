<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Domain\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

/** Immutable weekly work schedule with explicit segments and capture windows. */
final class WorkSchedule
{
    private const SEGMENT_TYPES = ['work', 'paid_break', 'unpaid_break', 'on_call', 'overtime'];

    private string $timezone;
    private DateTimeZone $timezoneObject;
    private ?string $seasonStart;
    private ?string $seasonEnd;

    /** @var array<int,array<string,mixed>> */
    private array $days;

    /** @param array<int,array<string,mixed>> $days */
    private function __construct(string $timezone, ?string $seasonStart, ?string $seasonEnd, array $days)
    {
        $this->timezone = $timezone;
        $this->timezoneObject = new DateTimeZone($timezone);
        $this->seasonStart = $seasonStart;
        $this->seasonEnd = $seasonEnd;
        $this->days = $days;
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $timezone = trim((string) ($payload['timezone'] ?? 'Africa/Cairo'));
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('SCHEDULE_TIMEZONE_INVALID', 0, $exception);
        }

        $seasonStart = self::normalizeMonthDay($payload['season_start_mmdd'] ?? $payload['season_start'] ?? null);
        $seasonEnd = self::normalizeMonthDay($payload['season_end_mmdd'] ?? $payload['season_end'] ?? null);
        if (($seasonStart === null) !== ($seasonEnd === null)) {
            throw new InvalidArgumentException('SCHEDULE_SEASON_RANGE_INCOMPLETE');
        }

        $rawDays = $payload['days'] ?? [];
        if (!is_array($rawDays)) {
            throw new InvalidArgumentException('SCHEDULE_DAYS_INVALID');
        }

        $days = [];
        foreach ($rawDays as $rawDay) {
            if (!is_array($rawDay)) {
                throw new InvalidArgumentException('SCHEDULE_DAY_INVALID');
            }
            $weekday = filter_var($rawDay['weekday'] ?? null, FILTER_VALIDATE_INT);
            if ($weekday === false || $weekday < 1 || $weekday > 7) {
                throw new InvalidArgumentException('SCHEDULE_WEEKDAY_INVALID');
            }
            if (isset($days[$weekday])) {
                throw new DomainException('SCHEDULE_WEEKDAY_DUPLICATE');
            }

            $working = self::toBoolean($rawDay['is_working_day'] ?? true);
            $day = self::normalizeDay($rawDay, $weekday, $working);
            $days[$weekday] = $day;
        }

        ksort($days, SORT_NUMERIC);

        return new self($timezone, $seasonStart, $seasonEnd, $days);
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function isSeasonallyActive(DateTimeImmutable $date): bool
    {
        if ($this->seasonStart === null || $this->seasonEnd === null) {
            return true;
        }

        $monthDay = $date->setTimezone($this->timezoneObject)->format('m-d');
        if ($this->seasonStart <= $this->seasonEnd) {
            return $monthDay >= $this->seasonStart && $monthDay <= $this->seasonEnd;
        }

        return $monthDay >= $this->seasonStart || $monthDay <= $this->seasonEnd;
    }

    public function isWorkingDay(DateTimeImmutable $date): bool
    {
        $day = $this->dayForDate($date);

        return $day !== null && $day['is_working_day'] === true;
    }

    /** @return array<string,mixed>|null */
    public function day(int $weekday): ?array
    {
        return $this->days[$weekday] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function segmentsForDate(DateTimeImmutable $date): array
    {
        $day = $this->dayForDate($date);
        if ($day === null || $day['is_working_day'] !== true) {
            return [];
        }

        $localDate = $date->setTimezone($this->timezoneObject)->setTime(0, 0, 0, 0);
        $segments = [];
        foreach ($day['segments'] as $segment) {
            $start = self::atLocalTime($localDate, $segment['start_time'], $segment['start_day_offset']);
            $end = self::atLocalTime($localDate, $segment['end_time'], $segment['end_day_offset']);
            $segments[] = $segment + [
                'start' => $start,
                'end' => $end,
                'duration_minutes' => self::minutesBetween($start, $end),
            ];
        }

        return $segments;
    }

    public function requiredMinutes(DateTimeImmutable $date): int
    {
        $day = $this->dayForDate($date);

        return $day === null || $day['is_working_day'] !== true ? 0 : $day['required_minutes'];
    }

    /**
     * @return array{
     *   start:DateTimeImmutable,end:DateTimeImmutable,
     *   entry_capture_start:DateTimeImmutable,entry_capture_end:DateTimeImmutable,
     *   exit_capture_start:DateTimeImmutable,exit_capture_end:DateTimeImmutable,
     *   late_grace_minutes:int,early_grace_minutes:int
     * }|null
     */
    public function workWindow(DateTimeImmutable $date): ?array
    {
        $day = $this->dayForDate($date);
        $segments = $this->segmentsForDate($date);
        if ($day === null || $day['is_working_day'] !== true || $segments === []) {
            return null;
        }

        $start = $segments[0]['start'];
        $end = $segments[count($segments) - 1]['end'];

        return [
            'start' => $start,
            'end' => $end,
            'entry_capture_start' => self::shiftMinutes($start, -$day['entry_window_before_minutes']),
            'entry_capture_end' => self::shiftMinutes($start, $day['entry_window_after_minutes']),
            'exit_capture_start' => self::shiftMinutes($end, -$day['exit_window_before_minutes']),
            'exit_capture_end' => self::shiftMinutes($end, $day['exit_window_after_minutes']),
            'late_grace_minutes' => $day['late_grace_minutes'],
            'early_grace_minutes' => $day['early_grace_minutes'],
        ];
    }

    /** @param array<string,mixed> $override */
    public function withDayOverride(DateTimeImmutable $date, array $override): self
    {
        $weekday = (int) $date->setTimezone($this->timezoneObject)->format('N');
        $days = array_values($this->days);
        $replacement = $override + ['weekday' => $weekday];
        if (
            !array_key_exists('segments', $replacement)
            && (array_key_exists('start_time', $replacement) || array_key_exists('end_time', $replacement))
        ) {
            $replacement['segments'] = [];
        }
        $found = false;
        foreach ($days as $index => $day) {
            if ((int) $day['weekday'] === $weekday) {
                $days[$index] = $replacement + $day;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $days[] = $replacement;
        }

        return self::fromArray([
            'timezone' => $this->timezone,
            'season_start_mmdd' => $this->seasonStart,
            'season_end_mmdd' => $this->seasonEnd,
            'days' => $days,
        ]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'timezone' => $this->timezone,
            'season_start_mmdd' => $this->seasonStart,
            'season_end_mmdd' => $this->seasonEnd,
            'days' => array_values($this->days),
        ];
    }

    /** @param array<string,mixed> $rawDay @return array<string,mixed> */
    private static function normalizeDay(array $rawDay, int $weekday, bool $working): array
    {
        if (!$working) {
            return [
                'weekday' => $weekday,
                'is_working_day' => false,
                'start_time' => null,
                'end_time' => null,
                'end_day_offset' => 0,
                'required_minutes' => 0,
                'late_grace_minutes' => 0,
                'early_grace_minutes' => 0,
                'entry_window_before_minutes' => 0,
                'entry_window_after_minutes' => 0,
                'exit_window_before_minutes' => 0,
                'exit_window_after_minutes' => 0,
                'segments' => [],
            ];
        }

        $startTime = self::normalizeTime($rawDay['start_time'] ?? null, 'SCHEDULE_START_TIME_INVALID');
        $endTime = self::normalizeTime($rawDay['end_time'] ?? null, 'SCHEDULE_END_TIME_INVALID');
        $endDayOffset = self::unsignedInt($rawDay['end_day_offset'] ?? 0, 2, 'SCHEDULE_END_OFFSET_INVALID');
        if ($endDayOffset === 0 && $endTime <= $startTime) {
            throw new InvalidArgumentException('SCHEDULE_WINDOW_INVALID');
        }

        $rawSegments = $rawDay['segments'] ?? [];
        if (!is_array($rawSegments)) {
            throw new InvalidArgumentException('SCHEDULE_SEGMENTS_INVALID');
        }
        if ($rawSegments === []) {
            $rawSegments = [[
                'sequence_no' => 1,
                'segment_type' => 'work',
                'start_time' => $startTime,
                'end_time' => $endTime,
                'start_day_offset' => 0,
                'end_day_offset' => $endDayOffset,
                'counts_required_minutes' => true,
            ]];
        }

        $segments = self::normalizeSegments($rawSegments);
        $firstSegment = $segments[0];
        $lastSegment = $segments[count($segments) - 1];
        if (
            $firstSegment['start_day_offset'] !== 0
            || $firstSegment['start_time'] !== $startTime
            || $lastSegment['end_day_offset'] !== $endDayOffset
            || $lastSegment['end_time'] !== $endTime
        ) {
            throw new DomainException('SCHEDULE_SEGMENT_BOUNDARY_MISMATCH');
        }
        $calculatedRequired = 0;
        foreach ($segments as $segment) {
            if ($segment['counts_required_minutes']) {
                $calculatedRequired += $segment['duration_minutes'];
            }
            unset($segment['duration_minutes']);
        }
        $required = self::unsignedInt($rawDay['required_minutes'] ?? $calculatedRequired, 2880, 'SCHEDULE_REQUIRED_MINUTES_INVALID');
        if ($required !== $calculatedRequired) {
            throw new DomainException('SCHEDULE_REQUIRED_MINUTES_MISMATCH');
        }

        return [
            'weekday' => $weekday,
            'is_working_day' => true,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'end_day_offset' => $endDayOffset,
            'required_minutes' => $required,
            'late_grace_minutes' => self::unsignedInt($rawDay['late_grace_minutes'] ?? 0, 1440, 'SCHEDULE_LATE_GRACE_INVALID'),
            'early_grace_minutes' => self::unsignedInt($rawDay['early_grace_minutes'] ?? 0, 1440, 'SCHEDULE_EARLY_GRACE_INVALID'),
            'entry_window_before_minutes' => self::unsignedInt($rawDay['entry_window_before_minutes'] ?? 0, 2880, 'SCHEDULE_ENTRY_WINDOW_INVALID'),
            'entry_window_after_minutes' => self::unsignedInt($rawDay['entry_window_after_minutes'] ?? 0, 2880, 'SCHEDULE_ENTRY_WINDOW_INVALID'),
            'exit_window_before_minutes' => self::unsignedInt($rawDay['exit_window_before_minutes'] ?? 0, 2880, 'SCHEDULE_EXIT_WINDOW_INVALID'),
            'exit_window_after_minutes' => self::unsignedInt($rawDay['exit_window_after_minutes'] ?? 0, 2880, 'SCHEDULE_EXIT_WINDOW_INVALID'),
            'segments' => array_map(static function (array $segment): array {
                unset($segment['duration_minutes']);
                return $segment;
            }, $segments),
        ];
    }

    /** @param list<mixed> $rawSegments @return list<array<string,mixed>> */
    private static function normalizeSegments(array $rawSegments): array
    {
        $segments = [];
        $sequences = [];
        foreach ($rawSegments as $index => $rawSegment) {
            if (!is_array($rawSegment)) {
                throw new InvalidArgumentException('SCHEDULE_SEGMENT_INVALID');
            }
            $sequence = self::unsignedInt($rawSegment['sequence_no'] ?? ($index + 1), 65535, 'SCHEDULE_SEGMENT_SEQUENCE_INVALID');
            if ($sequence === 0 || isset($sequences[$sequence])) {
                throw new DomainException('SCHEDULE_SEGMENT_SEQUENCE_DUPLICATE');
            }
            $sequences[$sequence] = true;

            $type = trim((string) ($rawSegment['segment_type'] ?? 'work'));
            if (!in_array($type, self::SEGMENT_TYPES, true)) {
                throw new InvalidArgumentException('SCHEDULE_SEGMENT_TYPE_INVALID');
            }
            $startTime = self::normalizeTime($rawSegment['start_time'] ?? null, 'SCHEDULE_SEGMENT_START_INVALID');
            $endTime = self::normalizeTime($rawSegment['end_time'] ?? null, 'SCHEDULE_SEGMENT_END_INVALID');
            $startOffset = self::unsignedInt($rawSegment['start_day_offset'] ?? 0, 2, 'SCHEDULE_SEGMENT_OFFSET_INVALID');
            $endOffset = self::unsignedInt($rawSegment['end_day_offset'] ?? $startOffset, 2, 'SCHEDULE_SEGMENT_OFFSET_INVALID');
            $startMinute = $startOffset * 1440 + self::timeToMinute($startTime);
            $endMinute = $endOffset * 1440 + self::timeToMinute($endTime);
            if ($endMinute <= $startMinute) {
                throw new InvalidArgumentException('SCHEDULE_SEGMENT_WINDOW_INVALID');
            }

            $segments[] = [
                'sequence_no' => $sequence,
                'segment_type' => $type,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'start_day_offset' => $startOffset,
                'end_day_offset' => $endOffset,
                'counts_required_minutes' => self::toBoolean(
                    $rawSegment['counts_required_minutes'] ?? ($type === 'work')
                ),
                'duration_minutes' => $endMinute - $startMinute,
                '_start_minute' => $startMinute,
                '_end_minute' => $endMinute,
            ];
        }

        usort($segments, static fn (array $left, array $right): int => $left['sequence_no'] <=> $right['sequence_no']);
        $previousEnd = null;
        foreach ($segments as &$segment) {
            if ($previousEnd !== null && $segment['_start_minute'] < $previousEnd) {
                throw new DomainException('SCHEDULE_SEGMENT_OVERLAP');
            }
            $previousEnd = $segment['_end_minute'];
            unset($segment['_start_minute'], $segment['_end_minute']);
        }
        unset($segment);

        return $segments;
    }

    /** @return array<string,mixed>|null */
    private function dayForDate(DateTimeImmutable $date): ?array
    {
        if (!$this->isSeasonallyActive($date)) {
            return null;
        }

        $weekday = (int) $date->setTimezone($this->timezoneObject)->format('N');
        return $this->days[$weekday] ?? null;
    }

    private static function normalizeMonthDay(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $monthDay = trim((string) $value);
        if (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $monthDay) !== 1) {
            throw new InvalidArgumentException('SCHEDULE_SEASON_DATE_INVALID');
        }
        [$month, $day] = array_map('intval', explode('-', $monthDay));
        if (!checkdate($month, $day, 2000)) {
            throw new InvalidArgumentException('SCHEDULE_SEASON_DATE_INVALID');
        }

        return $monthDay;
    }

    private static function normalizeTime(mixed $value, string $error): string
    {
        $time = trim((string) $value);
        if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/', $time) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return strlen($time) === 5 ? $time . ':00' : $time;
    }

    private static function unsignedInt(mixed $value, int $maximum, string $error): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0 || $integer > $maximum) {
            throw new InvalidArgumentException($error);
        }

        return $integer;
    }

    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InvalidArgumentException('SCHEDULE_BOOLEAN_INVALID');
        }

        return $parsed;
    }

    private static function timeToMinute(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return $hours * 60 + $minutes;
    }

    private static function atLocalTime(DateTimeImmutable $date, string $time, int $offset): DateTimeImmutable
    {
        [$hours, $minutes, $seconds] = array_map('intval', explode(':', $time));
        return $date->modify('+' . $offset . ' day')->setTime($hours, $minutes, $seconds, 0);
    }

    private static function minutesBetween(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
    }

    private static function shiftMinutes(DateTimeImmutable $date, int $minutes): DateTimeImmutable
    {
        if ($minutes === 0) {
            return $date;
        }

        return $date->modify(($minutes < 0 ? '-' : '+') . abs($minutes) . ' minutes');
    }
}
