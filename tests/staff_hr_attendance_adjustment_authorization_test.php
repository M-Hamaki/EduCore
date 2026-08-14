<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Infrastructure\StaffAttendanceAdjustmentAuthorization;
use EduCore\Modules\Staff\Contracts\StaffAccessEligibilityQuery;

final class AttendanceAdjustmentAuthorizationTestAccess implements StaffAccessEligibilityQuery
{
    public bool $allowed = true;

    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function assertCurrentAccess(
        int $userId,
        string $capability,
        string $resourceRef,
        \DateTimeImmutable $atInstant
    ): array {
        $this->calls[] = compact('userId', 'capability', 'resourceRef');
        return [
            'allowed' => $this->allowed,
            'staff_status' => 'active',
            'relationship_version' => 1,
            'reason' => $this->allowed ? 'allowed' : 'denied',
        ];
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$throws = static function (callable $operation, string $expected, string $message) use (&$failures): void {
    try {
        $operation();
        fwrite(STDERR, "FAIL: {$message} (no exception)\n");
        ++$failures;
    } catch (Throwable $exception) {
        if ($exception->getMessage() !== $expected) {
            fwrite(STDERR, "FAIL: {$message} (got {$exception->getMessage()})\n");
            ++$failures;
        }
    }
};

$access = new AttendanceAdjustmentAuthorizationTestAccess();
$authorization = new StaffAttendanceAdjustmentAuthorization($access);
$now = new \DateTimeImmutable('2026-01-05 08:00:00', new \DateTimeZone('Africa/Cairo'));
$authorization->assertCanAct(9002, 1001, 'self', 'decide', 7001, $now);
$assert(
    ($access->calls[0]['capability'] ?? null) === 'attendance.adjustment.decide.manager'
    && ($access->calls[0]['resourceRef'] ?? null) === 'attendance:adjustment:staff:1001',
    'an independent correction decision is checked against the current manager relationship, never the requester kind or browser role'
);
$throws(
    static fn () => $authorization->assertCanAct(1001, 1002, 'self', 'request', null, $now),
    'ATTENDANCE_ADJUSTMENT_SELF_REQUESTER_MISMATCH',
    'self correction cannot target another worker'
);
$access->allowed = false;
$callCountBeforeDeniedDecision = count($access->calls);
$throws(
    static fn () => $authorization->assertCanAct(9002, 1001, 'hr', 'decide', 7001, $now),
    'ATTENDANCE_ADJUSTMENT_NOT_AUTHORIZED',
    'Staff access denial fails closed for correction decisions'
);
$deniedDecisionCalls = array_slice($access->calls, $callCountBeforeDeniedDecision);
$assert(
    array_column($deniedDecisionCalls, 'capability') === [
        'attendance.adjustment.decide.manager',
        'attendance.adjustment.decide.hr',
    ],
    'a denied manager decision is checked against current HR authority before failing closed'
);
$throws(
    static fn () => $authorization->assertCanAct(9002, 1001, 'administrator', 'decide', null, $now),
    'ATTENDANCE_ADJUSTMENT_ACCESS_SUBJECT_INVALID',
    'unrecognized requester kinds cannot become arbitrary capabilities'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance adjustment authorization failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance adjustment authorization tests passed.\n";
