<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Attendance\Application\AttendancePeriodService;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;
use InvalidArgumentException;

/**
 * Attendance implementation of the Staff-owned approved-coverage command.
 *
 * It records the affected-day fact through AttendancePeriodService only. A
 * ready fact is intentionally not marked applied here: it waits for the
 * official-day recalculation owner, while a closed period remains pending
 * explicit reopen review.
 */
final class StaffApprovedCoverageChangeGateway implements AttendanceCoverageChangeGateway
{
    private DateTimeZone $utc;

    public function __construct(private AttendancePeriodService $periods)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function publish(array $event): array
    {
        $workDate = trim((string) ($event['work_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, $this->utc);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $workDate) {
            throw new InvalidArgumentException('ATTENDANCE_COVERAGE_WORK_DATE_INVALID');
        }
        $eventType = (string) ($event['event_type'] ?? '');
        if (!in_array($eventType, ['coverage_approved', 'coverage_reversed'], true)) {
            throw new InvalidArgumentException('ATTENDANCE_COVERAGE_EVENT_TYPE_INVALID');
        }

        return $this->periods->requestAffectedDayChange(
            (int) ($event['actor_id'] ?? 0),
            (int) ($event['staff_user_id'] ?? 0),
            $date,
            $eventType,
            (string) ($event['source_type'] ?? ''),
            isset($event['source_id']) ? (int) $event['source_id'] : null,
            (string) ($event['source_fingerprint'] ?? ''),
            (string) ($event['reason_code'] ?? ''),
            (string) ($event['idempotency_key'] ?? '')
        );
    }
}
