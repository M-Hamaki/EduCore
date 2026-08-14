<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Domain\Leave;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

/**
 * Single source of truth for entitlement-period keys and account boundaries.
 *
 * LeavePolicyService uses this to stamp request-day allocations, while the
 * request lifecycle uses the same result to address the immutable balance
 * ledger. Keeping it here prevents a future policy/request disagreement at a
 * year or service-anniversary boundary.
 */
final class LeaveEntitlementPeriod
{
    /**
     * @param array<string,mixed> $policy
     * @return array{key:string,period_from:string,period_to:string}
     */
    public static function forWorkDate(
        DateTimeImmutable $workDate,
        array $policy,
        ?DateTimeImmutable $serviceStart
    ): array {
        $timezone = self::timezone($policy['timezone_object'] ?? $policy['timezone'] ?? null);
        $localDate = $workDate->setTimezone($timezone)->setTime(0, 0, 0, 0);
        $type = strtolower(trim((string) ($policy['entitlement_period_type'] ?? '')));

        if ($type === 'calendar_year') {
            $from = new DateTimeImmutable($localDate->format('Y-01-01 00:00:00'), $timezone);
            $to = new DateTimeImmutable($localDate->format('Y-12-31 00:00:00'), $timezone);

            return self::result('CY-' . $localDate->format('Y'), $from, $to);
        }

        if ($type === 'service_anniversary') {
            if ($serviceStart === null) {
                throw new DomainException('LEAVE_SERVICE_START_UNAVAILABLE');
            }

            return self::anchored('SA', $localDate, $serviceStart->setTimezone($timezone)->format('m-d'));
        }

        if ($type === 'academic_year' || $type === 'custom') {
            $anchor = trim((string) ($policy['entitlement_period_anchor_mmdd'] ?? ''));
            if (!preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $anchor)) {
                throw new InvalidArgumentException('LEAVE_POLICY_PERIOD_ANCHOR_REQUIRED');
            }

            return self::anchored($type === 'academic_year' ? 'AY' : 'CUSTOM', $localDate, $anchor);
        }

        throw new DomainException('LEAVE_POLICY_PERIOD_TYPE_INVALID');
    }

    /** @return array{key:string,period_from:string,period_to:string} */
    private static function anchored(string $prefix, DateTimeImmutable $date, string $monthDay): array
    {
        $anchor = self::monthDayInYear((int) $date->format('Y'), $monthDay, $date->getTimezone());
        if ($date < $anchor) {
            $anchor = self::monthDayInYear((int) $date->format('Y') - 1, $monthDay, $date->getTimezone());
        }
        $end = $anchor->modify('+1 year')->modify('-1 day')->setTime(0, 0, 0, 0);

        return self::result(
            $prefix . '-' . $anchor->format('Y-m-d') . '-' . $end->format('Y-m-d'),
            $anchor,
            $end
        );
    }

    /** @return array{key:string,period_from:string,period_to:string} */
    private static function result(string $key, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'key' => $key,
            'period_from' => $from->format('Y-m-d'),
            'period_to' => $to->format('Y-m-d'),
        ];
    }

    private static function timezone(mixed $value): DateTimeZone
    {
        if ($value instanceof DateTimeZone) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('LEAVE_POLICY_TIMEZONE_INVALID');
        }
        try {
            return new DateTimeZone($value);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('LEAVE_POLICY_TIMEZONE_INVALID', 0, $exception);
        }
    }

    private static function monthDayInYear(int $year, string $monthDay, DateTimeZone $timezone): DateTimeImmutable
    {
        [$month, $day] = array_map('intval', explode('-', $monthDay));
        $lastDay = (int) (new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $year, $month),
            $timezone
        ))->modify('last day of this month')->format('d');

        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d 00:00:00', $year, $month, min($day, $lastDay)),
            $timezone
        );
    }
}
