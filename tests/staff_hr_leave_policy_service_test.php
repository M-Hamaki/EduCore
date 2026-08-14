<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';
require_once $root . '/src/Modules/Attendance/bootstrap.php';

use EduCore\Modules\Attendance\Application\LeaveWorkdayCalendarQueryService;
use EduCore\Modules\Attendance\Contracts\EffectiveScheduleQuery;
use EduCore\Modules\Attendance\Contracts\LeaveWorkdayCalendarQuery;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Staff\Application\Leave\LeavePolicyService;
use EduCore\Modules\Staff\Application\Policy\EffectivePolicyResolver;
use EduCore\Modules\Staff\Contracts\LeavePolicyReadRepository;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffEmploymentQuery;

final class LeavePolicyTestRepository implements LeavePolicyReadRepository
{
    /** @param array<string,mixed> $type @param list<array<string,mixed>> $candidates */
    public function __construct(private array $type, private array $candidates)
    {
    }

    public function findType(int $leaveTypeId): ?array
    {
        return (int) ($this->type['id'] ?? 0) === $leaveTypeId ? $this->type : null;
    }

    public function candidateVersionsFor(
        int $leaveTypeId,
        int $staffId,
        array $assignment,
        DateTimeImmutable $effectiveAt
    ): array {
        return $this->candidates;
    }
}

final class LeavePolicyTestAssignments implements StaffAssignmentAtDateQuery
{
    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
    {
        return [
            'assignment_id' => 44,
            'org_unit_id' => 12,
            'job_title_id' => 8,
            'group_ids' => [5, 9],
            'employment_status' => 'active',
        ];
    }
}

final class LeavePolicyTestEmployment implements StaffEmploymentQuery
{
    public function __construct(private string $hireDate = '2024-02-29')
    {
    }

    public function activeContractOf(int $staffId, ?string $atDate = null): ?array
    {
        return [
            'staff_id' => $staffId,
            'employee_code' => 'E-TEST-1',
            'job_title' => 'Teacher',
            'department' => 'Academics',
            'hire_date' => $this->hireDate,
            'current_work_status' => 'on_duty',
            'is_active' => true,
        ];
    }

    public function relationshipsOf(int $staffId): array
    {
        return [];
    }

    public function documentedRelationshipToStudent(int $staffId, int $studentId): ?array
    {
        return null;
    }
}

final class LeavePolicyTestCalendar implements LeaveWorkdayCalendarQuery
{
    /** @param list<array<string,mixed>> $days */
    public function __construct(private array $days)
    {
    }

    public function daysIntersecting(
        int $staffId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        DateTimeZone $requestTimezone
    ): array {
        return $this->days;
    }
}

final class LeavePolicyTestEffectiveScheduleQuery implements EffectiveScheduleQuery
{
    public function __construct(private WorkSchedule $schedule)
    {
    }

    public function forStaffDate(int $staffId, DateTimeImmutable $workDate): array
    {
        if ($workDate->format('Y-m-d') !== '2026-12-31') {
            return [
                'status' => 'non_working',
                'reason_code' => 'SCHEDULE_NON_WORKING_DAY',
                'explanation' => [],
            ];
        }

        return [
            'status' => 'working',
            'reason_code' => 'EFFECTIVE_SCHEDULE_RESOLVED',
            'selected' => ['schedule' => $this->schedule],
            'explanation' => ['version_id' => 501, 'calendar_exception_id' => null],
            'conflicts' => [],
        ];
    }
}

$timezone = new DateTimeZone('Africa/Cairo');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertThrows = static function (callable $operation, string $expectedCode, string $message) use (&$assertions): void {
    ++$assertions;
    try {
        $operation();
    } catch (DomainException $exception) {
        if ($exception->getMessage() === $expectedCode) {
            return;
        }
        throw new RuntimeException($message . ': expected ' . $expectedCode . ', got ' . $exception->getMessage());
    }
    throw new RuntimeException($message . ': no exception');
};

$type = [
    'id' => 3,
    'code' => 'annual',
    'name' => 'Annual leave',
    'unit' => 'day',
    'allow_partial_unit' => 1,
    'requires_reason' => 1,
    'requires_attachment' => 0,
    'requires_medical_document' => 0,
    'payroll_effect_code' => null,
    'status' => 'active',
];
$policy = static function (array $overrides = []): array {
    return $overrides + [
        'policy_version_id' => 77,
        'leave_type_id' => 3,
        'version_no' => 1,
        'state' => 'published',
        'valid_from' => '2025-01-01 00:00:00.000000',
        'valid_to' => null,
        'timezone' => 'Africa/Cairo',
        'entitlement_period_type' => 'calendar_year',
        'entitlement_period_anchor_mmdd' => null,
        'entitlement_units' => '21.000',
        'accrual_mode' => 'grant',
        'accrual_units' => '0.000',
        'carry_limit_units' => null,
        'carry_expiry_months' => null,
        'max_consecutive_units' => '10.000',
        'min_notice_minutes' => 60,
        'min_service_months' => 0,
        'allow_retroactive' => 0,
        'retroactive_limit_days' => 0,
        'minimum_increment_minutes' => 30,
        'allow_partial_unit' => 1,
        'allow_overlap' => 0,
        'allow_negative_balance' => 0,
        'negative_balance_limit_units' => '0.000',
        'requires_return_to_work' => 0,
        'requires_attachment' => 0,
        'requires_medical_document' => 0,
        'payroll_effect_code' => null,
        'scope_type' => 'global',
        'scope_id' => 0,
        'scope_priority' => 0,
        'scope_valid_from' => '2025-01-01 00:00:00.000000',
        'scope_valid_to' => null,
        'scope_status' => 'active',
    ];
};
$day = static function (
    string $workDate,
    string $from,
    string $to,
    int $minutes,
    ?int $calendarExceptionId = null
): array {
    return [
        'status' => 'working',
        'reason_code' => 'EFFECTIVE_SCHEDULE_RESOLVED',
        'staff_id' => 55,
        'work_date' => $workDate,
        'required_minutes' => $minutes,
        'working_intervals' => [[
            'start_at' => $from,
            'end_at' => $to,
            'minutes' => $minutes,
        ]],
        'schedule_policy_version_id' => 501,
        'calendar_exception_id' => $calendarExceptionId,
        'conflicts' => [],
    ];
};
$nonWorking = static function (string $workDate): array {
    return [
        'status' => 'non_working',
        'reason_code' => 'CALENDAR_HOLIDAY',
        'staff_id' => 55,
        'work_date' => $workDate,
        'required_minutes' => 0,
        'working_intervals' => [],
        'schedule_policy_version_id' => 501,
        'calendar_exception_id' => 901,
        'conflicts' => [],
    ];
};
$service = static function (array $typePayload, array $candidates, array $calendarDays): LeavePolicyService {
    return new LeavePolicyService(
        new LeavePolicyTestRepository($typePayload, $candidates),
        new LeavePolicyTestAssignments(),
        new LeavePolicyTestEmployment(),
        new LeavePolicyTestCalendar($calendarDays),
        new EffectivePolicyResolver()
    );
};

$crossYear = $service(
    $type,
    [$policy()],
    [
        $day('2026-12-31', '2026-12-31T08:00:00.000000+02:00', '2026-12-31T16:00:00.000000+02:00', 480),
        $nonWorking('2027-01-01'),
        $day('2027-01-02', '2027-01-02T08:00:00.000000+02:00', '2027-01-02T12:00:00.000000+02:00', 240),
    ]
)->quote(
    55,
    3,
    new DateTimeImmutable('2026-12-31 08:00:00', $timezone),
    new DateTimeImmutable('2027-01-02 12:00:00', $timezone),
    new DateTimeImmutable('2026-12-01 08:00:00', $timezone)
);
$assert($crossYear['requested_units'] === '2.000', 'full workdays across a year boundary consume two day units');
$assert($crossYear['requested_minutes'] === 720, 'non-working dates consume no minutes');
$assert($crossYear['request_days'][0]['entitlement_period_key'] === 'CY-2026', 'first workday uses its 2026 entitlement account');
$assert($crossYear['request_days'][1]['day_kind'] === 'non_working', 'holiday remains an explicit zero-consumption request day');
$assert($crossYear['request_days'][2]['entitlement_period_key'] === 'CY-2027', 'second workday uses its 2027 entitlement account');
$assert(!array_key_exists('_policy_probe_from', $crossYear['request_days'][0]), 'internal policy probes never escape the leave quote');

$hourType = $type;
$hourType['unit'] = 'hour';
$hourType['code'] = 'hourly';
$hourly = $service(
    $hourType,
    [$policy(['minimum_increment_minutes' => 30])],
    [$day('2026-12-31', '2026-12-31T08:00:00.000000+02:00', '2026-12-31T16:00:00.000000+02:00', 480)]
)->quote(
    55,
    3,
    new DateTimeImmutable('2026-12-31 09:00:00', $timezone),
    new DateTimeImmutable('2026-12-31 11:00:00', $timezone),
    new DateTimeImmutable('2026-12-01 08:00:00', $timezone)
);
$assert($hourly['requested_units'] === '2.000', 'hour leave converts required working minutes to decimal hours');
$assert($hourly['request_days'][0]['day_kind'] === 'partial', 'hour leave retains a partial workday allocation');

$assertThrows(
    static fn (): array => $service(
        $type,
        [$policy()],
        [$day('2026-12-31', '2026-12-31T08:00:00.000000+02:00', '2026-12-31T16:00:00.000000+02:00', 480)]
    )->quote(
        55,
        3,
        new DateTimeImmutable('2026-12-31 08:00:00', $timezone),
        new DateTimeImmutable('2026-12-31 16:00:00', $timezone),
        new DateTimeImmutable('2027-01-01 08:00:00', $timezone)
    ),
    'LEAVE_RETROACTIVE_NOT_ALLOWED',
    'retroactive leave fails closed when its published policy disallows it'
);

$assertThrows(
    static fn (): array => $service(
        $type,
        [$policy()],
        [$day('2026-12-31', '2026-12-31T08:00:00.000000+02:00', '2026-12-31T16:00:00.000000+02:00', 480)]
    )->quote(
        55,
        3,
        new DateTimeImmutable('2026-12-31 08:00:00', $timezone),
        new DateTimeImmutable('2026-12-31 16:00:00', $timezone),
        new DateTimeImmutable('2026-12-31 07:30:00', $timezone)
    ),
    'LEAVE_MIN_NOTICE_NOT_MET',
    'future leave must meet the policy notice duration'
);

$noticeWithSeconds = $service(
    $type,
    [$policy()],
    [$day('2026-12-31', '2026-12-31T08:00:00.000000+02:00', '2026-12-31T16:00:00.000000+02:00', 480)]
)->quote(
    55,
    3,
    new DateTimeImmutable('2026-12-31 08:00:00', $timezone),
    new DateTimeImmutable('2026-12-31 16:00:00', $timezone),
    new DateTimeImmutable('2026-12-31 06:59:38', $timezone)
);
$assert(
    $noticeWithSeconds['requested_units'] === '1.000',
    'notice duration uses elapsed whole minutes and never rejects a valid request because submission has seconds'
);

$newPolicy = $policy([
    'policy_version_id' => 78,
    'version_no' => 2,
    'valid_from' => '2027-01-01 00:00:00.000000',
    'scope_valid_from' => '2027-01-01 00:00:00.000000',
]);
$oldPolicy = $policy([
    'valid_to' => '2027-01-01 00:00:00.000000',
    'scope_valid_to' => '2027-01-01 00:00:00.000000',
]);
$assertThrows(
    static fn (): array => $service(
        $type,
        [$oldPolicy, $newPolicy],
        [
            $day('2026-12-31', '2026-12-31T08:00:00.000000+02:00', '2026-12-31T16:00:00.000000+02:00', 480),
            $day('2027-01-01', '2027-01-01T08:00:00.000000+02:00', '2027-01-01T16:00:00.000000+02:00', 480),
        ]
    )->quote(
        55,
        3,
        new DateTimeImmutable('2026-12-31 08:00:00', $timezone),
        new DateTimeImmutable('2027-01-01 16:00:00', $timezone),
        new DateTimeImmutable('2026-12-01 08:00:00', $timezone)
    ),
    'LEAVE_POLICY_CHANGES_WITHIN_REQUEST',
    'one immutable request cannot span two published leave-policy versions'
);

$anniversaryPolicy = $policy([
    'entitlement_period_type' => 'service_anniversary',
    'min_notice_minutes' => 0,
]);
$anniversary = $service(
    $type,
    [$anniversaryPolicy],
    [$day('2025-03-01', '2025-03-01T08:00:00.000000+02:00', '2025-03-01T16:00:00.000000+02:00', 480)]
)->quote(
    55,
    3,
    new DateTimeImmutable('2025-03-01 08:00:00', $timezone),
    new DateTimeImmutable('2025-03-01 16:00:00', $timezone),
    new DateTimeImmutable('2025-02-20 08:00:00', $timezone)
);
$assert(
    $anniversary['request_days'][0]['entitlement_period_key'] === 'SA-2025-02-28-2026-02-27',
    'a leap-day service anniversary uses the last valid day of a non-leap year'
);

$overnightSchedule = WorkSchedule::fromArray([
    'timezone' => 'Africa/Cairo',
    'days' => [[
        'weekday' => 4,
        'is_working_day' => true,
        'start_time' => '22:00',
        'end_time' => '02:00',
        'end_day_offset' => 1,
        'required_minutes' => 240,
    ]],
]);
$calendarProjection = new LeaveWorkdayCalendarQueryService(
    new LeavePolicyTestEffectiveScheduleQuery($overnightSchedule)
);
$overnightDays = $calendarProjection->daysIntersecting(
    55,
    new DateTimeImmutable('2027-01-01 00:30:00', $timezone),
    new DateTimeImmutable('2027-01-01 01:30:00', $timezone),
    $timezone
);
$assert(count($overnightDays) === 2, 'attendance calendar includes the requested local day and the prior workday that overlaps it');
$assert($overnightDays[0]['work_date'] === '2026-12-31', 'overnight interval stays attached to its source workday');
$assert($overnightDays[0]['working_intervals'][0]['minutes'] === 240, 'overnight work interval keeps exact required minutes');
$assert($overnightDays[1]['status'] === 'non_working', 'the requested calendar day remains visible even when its shift belongs to the prior workday');

echo 'staff_hr_leave_policy_service_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
