<?php

declare(strict_types=1);

/** Isolated contract proof for the merged, summary-safe Staff HR timeline. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/src/Modules/Staff/Contracts/StaffTimelineEventSource.php';
require_once $root . '/src/Modules/Staff/Application/Timeline/StaffHrTimelineQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Timeline/PdoStaffAssignmentTimelineEventSource.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Timeline/PdoStaffCredentialTimelineEventSource.php';

use EduCore\Modules\Staff\Application\Timeline\StaffHrTimelineQuery;
use EduCore\Modules\Staff\Contracts\StaffTimelineEventSource;
use EduCore\Modules\Staff\Infrastructure\Timeline\PdoStaffAssignmentTimelineEventSource;
use EduCore\Modules\Staff\Infrastructure\Timeline\PdoStaffCredentialTimelineEventSource;

final class TimelineFixtureSource implements StaffTimelineEventSource
{
    /** @var list<array<string,mixed>> */
    public array $events;
    public int $calls = 0;
    public int $lastLimit = 0;

    /** @param list<array<string,mixed>> $events */
    public function __construct(private string $key, array $events, private bool $fails = false)
    {
        $this->events = $events;
    }

    public function sourceKey(): string
    {
        return $this->key;
    }

    public function eventsForStaff(
        int $staffUserId,
        DateTimeImmutable $fromInclusive,
        DateTimeImmutable $toExclusive,
        int $limit
    ): array {
        ++$this->calls;
        $this->lastLimit = $limit;
        if ($this->fails) {
            throw new RuntimeException('private adapter failure');
        }

        return $this->events;
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$event = static function (
    string $id,
    string $at,
    string $type,
    string $resourceType,
    int $resourceId,
    string $status = 'recorded',
    ?int $version = null
): array {
    return [
        'event_id' => $id,
        'occurred_at' => new DateTimeImmutable($at),
        'event_type' => $type,
        'resource_type' => $resourceType,
        'resource_id' => $resourceId,
        'status' => $status,
        'version' => $version,
    ];
};

try {
    $assignments = new TimelineFixtureSource('assignments', [
        $event('assignment-1', '2026-08-01 08:00:00+03:00', 'staff.assignment.effective', 'staff_assignment', 11, 'active', 2)
            + ['private_text' => 'never leave the source'],
        $event('assignment-1', '2026-08-01 08:00:00+03:00', 'staff.assignment.effective', 'staff_assignment', 11, 'active', 2),
        $event('assignment-2', '2026-08-06 08:00:00+03:00', 'staff.assignment.effective', 'staff_assignment', 12, 'rehired', 3),
    ]);
    $permissions = new TimelineFixtureSource('permissions', [
        $event('permission-7', '2026-08-05 12:00:00+03:00', 'permission.request.approved', 'permission_request', 7, 'approved', 4),
        $event('outside-window', '2026-09-01 08:00:00+03:00', 'permission.request.submitted', 'permission_request', 8),
        $event('malformed-resource', '2026-08-04 08:00:00+03:00', 'permission.request.submitted', 'permission_request', 0),
    ]);
    $attendance = new TimelineFixtureSource('attendance', [
        $event('attendance-day-3', '2026-08-07 14:30:00+03:00', 'attendance.day.official', 'attendance_day', 3, 'official', 6),
    ]);
    $unavailable = new TimelineFixtureSource('ertaq', [], true);

    $query = new StaffHrTimelineQuery([$assignments, $permissions, $attendance, $unavailable]);
    $result = $query->forStaff(
        101,
        new DateTimeImmutable('2026-08-01 00:00:00+03:00'),
        new DateTimeImmutable('2026-09-01 00:00:00+03:00'),
        3
    );

    $eventKeys = array_map(
        static fn (array $timelineEvent): string => $timelineEvent['source'] . ':' . $timelineEvent['event_id'],
        $result['events']
    );
    $warningCodes = array_map(
        static fn (array $warning): string => $warning['source'] . ':' . $warning['code'],
        $result['warnings']
    );

    $assert(
        $eventKeys === [
            'attendance:attendance-day-3',
            'assignments:assignment-2',
            'permissions:permission-7',
        ],
        'events from owned contracts merge into one deterministic reverse-chronological timeline'
    );
    $assert(
        ($result['has_more'] ?? false) === true
        && $assignments->calls === 1
        && $permissions->calls === 1
        && $attendance->calls === 1
        && $assignments->lastLimit === 4,
        'the query reads each source once with a bounded limit and signals additional timeline history'
    );
    $assert(
        in_array('assignments:duplicate_event', $warningCodes, true)
        && in_array('permissions:event_outside_window', $warningCodes, true)
        && in_array('permissions:invalid_event', $warningCodes, true)
        && in_array('ertaq:source_unavailable', $warningCodes, true),
        'duplicate, invalid, out-of-window, and unavailable source evidence stays visible as neutral warnings'
    );
    $assert(
        !array_key_exists('private_text', $result['events'][2] ?? [])
        && !str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'private adapter failure'),
        'the composed timeline never forwards uncontracted private fields or source exception text'
    );

    $invalidRangeRejected = false;
    try {
        $query->forStaff(
            101,
            new DateTimeImmutable('2026-09-01 00:00:00+03:00'),
            new DateTimeImmutable('2026-08-01 00:00:00+03:00')
        );
    } catch (InvalidArgumentException) {
        $invalidRangeRejected = true;
    }
    $assert($invalidRangeRejected, 'an inverted timeline window is rejected before any source query');

    $duplicateSourceRejected = false;
    try {
        new StaffHrTimelineQuery([
            new TimelineFixtureSource('attendance', []),
            new TimelineFixtureSource('attendance', []),
        ]);
    } catch (InvalidArgumentException) {
        $duplicateSourceRejected = true;
    }
    $assert($duplicateSourceRejected, 'duplicate source identities cannot make timeline event keys ambiguous');

    $timelineDb = new PDO('sqlite::memory:');
    $timelineDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $timelineDb->exec(
        'CREATE TABLE staff_assignments (
            id INTEGER PRIMARY KEY,
            staff_user_id INTEGER NOT NULL,
            assignment_kind TEXT NOT NULL,
            employment_status TEXT NOT NULL,
            valid_from TEXT NOT NULL,
            version INTEGER NOT NULL
        )'
    );
    $timelineDb->exec(
        'CREATE TABLE staff_credential_records (
            id INTEGER PRIMARY KEY,
            staff_user_id INTEGER NOT NULL,
            credential_kind TEXT NOT NULL,
            effective_on TEXT NOT NULL,
            verification_status TEXT NOT NULL,
            lifecycle_status TEXT NOT NULL,
            version INTEGER NOT NULL,
            title TEXT NULL,
            attachment_id INTEGER NULL
        )'
    );
    $timelineDb->exec(
        "INSERT INTO staff_assignments (id, staff_user_id, assignment_kind, employment_status, valid_from, version) VALUES
            (40, 300, 'primary', 'active', '2026-08-01', 1),
            (41, 300, 'secondary', 'active', '2026-08-03', 1),
            (42, 300, 'primary', 'rehired', '2026-08-09', 2),
            (43, 300, 'primary', 'active', '2026-09-01', 3),
            (44, 300, 'primary', 'suspended', '2026-08-04', 1)"
    );
    $assignmentSource = new PdoStaffAssignmentTimelineEventSource($timelineDb);
    $assignmentEvents = $assignmentSource->eventsForStaff(
        300,
        new DateTimeImmutable('2026-08-01 00:00:00+03:00'),
        new DateTimeImmutable('2026-09-01 00:00:00+03:00'),
        2
    );
    $assert(
        $assignmentSource->sourceKey() === 'assignments'
        && array_column($assignmentEvents, 'event_id') === ['assignment-42-v2', 'assignment-44-v1']
        && ($assignmentEvents[0]['status'] ?? null) === 'rehired'
        && ($assignmentEvents[0]['occurred_at'] ?? null) instanceof DateTimeImmutable
        && !array_key_exists('org_unit_id', $assignmentEvents[0] ?? []),
        'the assignment adapter provides bounded effective-date evidence without leaking organizational labels'
    );

    $timelineDb->exec(
        "INSERT INTO staff_credential_records
            (id, staff_user_id, credential_kind, effective_on, verification_status, lifecycle_status, version, title, attachment_id) VALUES
            (70, 300, 'document', '2026-08-04', 'verified', 'active', 2, 'وثيقة خاصة', 55),
            (71, 300, 'training', '2026-08-08', 'unverified', 'active', 1, 'تفاصيل تدريب', 56),
            (72, 300, 'qualification', '2026-09-01', 'verified', 'active', 1, 'خارج النطاق', 57)"
    );
    $credentialSource = new PdoStaffCredentialTimelineEventSource($timelineDb);
    $credentialEvents = $credentialSource->eventsForStaff(
        300,
        new DateTimeImmutable('2026-08-01 00:00:00+03:00'),
        new DateTimeImmutable('2026-09-01 00:00:00+03:00'),
        2
    );
    $assert(
        $credentialSource->sourceKey() === 'credentials'
        && array_column($credentialEvents, 'event_id') === ['credential-71-v1', 'credential-70-v2']
        && ($credentialEvents[0]['event_type'] ?? null) === 'staff.credential.training'
        && ($credentialEvents[0]['status'] ?? null) === 'active.unverified'
        && !array_key_exists('title', $credentialEvents[0] ?? [])
        && !array_key_exists('attachment_id', $credentialEvents[0] ?? []),
        'the credential adapter adds bounded safe history without disclosing evidence details'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR timeline query: PASS\n";
