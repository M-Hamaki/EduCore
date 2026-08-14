<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\SchedulePolicyCommandService;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyRepository;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

final class SchedulePolicyCommandProbeRepository implements SchedulePolicyRepository
{
    public PDO $db;
    public array $versions = [];
    public array $policies = [];
    public array $createKeys = [];
    public array $days = [];
    public array $scopes = [];
    public array $conflicts = [];
    public array $exceptions = [];
    public array $exceptionKeys = [];
    public array $receipts = [];
    public bool $allWritesInsideTransaction = true;
    private int $policySequence = 1;
    private int $versionSequence = 10;
    private int $exceptionSequence = 100;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->db->exec('CREATE TABLE schedule_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL)');
    }

    private function writeProbe(string $kind): void
    {
        $this->allWritesInsideTransaction = $this->allWritesInsideTransaction && $this->db->inTransaction();
        $statement = $this->db->prepare('INSERT INTO schedule_probe (kind) VALUES (?)');
        $statement->execute([$kind]);
    }

    public function findCommandReceipt(string $idempotencyKey): ?array { return $this->receipts[$idempotencyKey] ?? null; }
    public function recordCommandReceipt(array $receipt): void { $this->writeProbe('receipt'); $this->receipts[$receipt['idempotency_key']] = $receipt; }
    public function nextVersionNumber(int $policyId): int
    {
        $versions = array_filter($this->versions, static fn (array $version): bool => (int) $version['policy_id'] === $policyId);
        return count($versions) + 1;
    }
    public function policyForUpdate(int $policyId): ?array { return $this->policies[$policyId] ?? null; }
    public function insertPolicy(array $policy): int
    {
        $this->writeProbe('policy');
        $id = $this->policySequence++;
        $this->policies[$id] = $policy + ['id' => $id];
        return $id;
    }
    public function updatePolicy(int $policyId, array $policy): void
    {
        $this->writeProbe('policy_update');
        $this->policies[$policyId] = $policy + ($this->policies[$policyId] ?? ['id' => $policyId]);
    }
    public function insertDraftVersion(int $policyId, array $version): int
    {
        $this->writeProbe('version');
        $id = $this->versionSequence++;
        $this->versions[$id] = $version + ['id' => $id, 'version_id' => $id, 'policy_id' => $policyId, 'state' => 'draft', 'lock_version' => 1];
        $this->createKeys[(string) $version['create_idempotency_key']] = $id;
        return $id;
    }
    public function findVersionByCreateKey(string $idempotencyKey): ?array
    {
        $id = $this->createKeys[$idempotencyKey] ?? null;
        return $id === null ? null : ($this->versions[$id] ?? null);
    }
    public function versionForUpdate(int $versionId): ?array { return $this->versions[$versionId] ?? null; }
    public function updateDraftVersion(int $versionId, int $expectedLockVersion, array $version): bool
    {
        $this->writeProbe('update_version');
        $current = $this->versions[$versionId] ?? null;
        if ($current === null || $current['state'] !== 'draft' || $current['lock_version'] !== $expectedLockVersion) return false;
        $this->versions[$versionId] = $version + $current;
        $this->versions[$versionId]['lock_version'] = $expectedLockVersion + 1;
        return true;
    }
    public function replaceDraftDays(int $versionId, array $days): void { $this->writeProbe('days'); $this->days[$versionId] = $days; }
    public function replaceDraftScopes(int $versionId, array $scopes): void { $this->writeProbe('scopes'); $this->scopes[$versionId] = $scopes; }
    public function publicationConflicts(int $versionId): array { return $this->conflicts; }
    public function markPublished(int $versionId, int $expectedLockVersion, int $actorId, DateTimeImmutable $publishedAt, string $publicationKey, string $payloadHash): bool
    {
        $this->writeProbe('publish');
        $current = $this->versions[$versionId] ?? null;
        if ($current === null || $current['state'] !== 'draft' || $current['lock_version'] !== $expectedLockVersion) return false;
        $this->versions[$versionId]['state'] = 'published';
        $this->versions[$versionId]['lock_version']++;
        $this->versions[$versionId]['published_by'] = $actorId;
        $this->versions[$versionId]['published_at'] = $publishedAt->format('Y-m-d H:i:s.u');
        $this->versions[$versionId]['publication_key'] = $publicationKey;
        $this->versions[$versionId]['publication_payload_hash'] = $payloadHash;
        return true;
    }
    public function findCalendarExceptionByIdempotency(string $idempotencyKey): ?array
    {
        $id = $this->exceptionKeys[$idempotencyKey] ?? null;
        return $id === null ? null : ($this->exceptions[$id] ?? null);
    }
    public function calendarExceptionForUpdate(int $exceptionId): ?array { return $this->exceptions[$exceptionId] ?? null; }
    public function terminalCalendarExceptionForDateScopeForUpdate(string $calendarDate, string $scopeType, int $scopeId): ?array
    {
        $superseded = [];
        foreach ($this->exceptions as $exception) {
            if (in_array((string) ($exception['status'] ?? ''), ['active', 'retired'], true)
                && (int) ($exception['supersedes_id'] ?? 0) > 0) {
                $superseded[(int) $exception['supersedes_id']] = true;
            }
        }
        $terminal = array_values(array_filter($this->exceptions, static function (array $exception) use ($calendarDate, $scopeType, $scopeId, $superseded): bool {
            return (string) ($exception['calendar_date'] ?? '') === $calendarDate
                && (string) ($exception['scope_type'] ?? '') === $scopeType
                && (int) ($exception['scope_id'] ?? 0) === $scopeId
                && in_array((string) ($exception['status'] ?? ''), ['active', 'retired'], true)
                && !isset($superseded[(int) ($exception['id'] ?? 0)]);
        }));
        usort($terminal, static fn (array $left, array $right): int => (int) $right['id'] <=> (int) $left['id']);

        return $terminal[0] ?? null;
    }
    public function insertCalendarException(array $exception): int
    {
        $this->writeProbe('calendar');
        $id = $this->exceptionSequence++;
        $this->exceptions[$id] = $exception + ['id' => $id, 'lock_version' => 1];
        $this->exceptionKeys[(string) $exception['idempotency_key']] = $id;
        return $id;
    }
    public function updateDraftCalendarException(int $exceptionId, int $expectedLockVersion, array $exception): bool
    {
        $this->writeProbe('calendar_update');
        $current = $this->exceptions[$exceptionId] ?? null;
        if ($current === null || $current['status'] !== 'draft' || $current['lock_version'] !== $expectedLockVersion) return false;
        $this->exceptions[$exceptionId] = $exception + $current;
        $this->exceptions[$exceptionId]['lock_version']++;
        return true;
    }
    public function listPolicies(array $filters = []): array { return []; }
    public function findPolicy(int $policyId): ?array { return null; }
    public function findVersion(int $versionId): ?array { return $this->versions[$versionId] ?? null; }
    public function candidateVersionsFor(int $staffId, array $assignmentSnapshot, DateTimeImmutable $at): array { return []; }
    public function calendarExceptionsFor(int $staffId, array $assignmentSnapshot, DateTimeImmutable $date): array { return []; }
    public function approvedChangesFor(int $staffId, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array { return []; }
    public function listCalendarExceptions(array $filters = []): array { return array_values($this->exceptions); }
    public function findChangeRequestByIdempotency(string $idempotencyKey): ?array { return null; }
    public function changeRequestForUpdate(int $requestId): ?array { return null; }
    public function insertChangeRequest(array $request): int { return 0; }
    public function updateChangeRequest(int $requestId, int $expectedLockVersion, array $changes): bool { return false; }
    public function lockChangeParticipants(array $staffIds): void {}
    public function overlappingChangeRequests(array $staffIds, DateTimeImmutable $from, DateTimeImmutable $to, array $statuses, ?int $excludeRequestId = null): array { return []; }
}

final class SchedulePolicyCommandProbeAudit implements AuditEventWriter
{
    public PDO $db;
    public array $events = [];
    public bool $throw = false;
    public bool $allEventsInsideTransaction = true;

    public function __construct(PDO $db) { $this->db = $db; }
    public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void
    {
        $this->allEventsInsideTransaction = $this->allEventsInsideTransaction && $this->db->inTransaction();
        if ($this->throw) throw new RuntimeException('AUDIT_WRITE_FAILED');
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};
$assertThrows = static function (callable $callback, string $part, string $message) use ($assert): void {
    try { $callback(); $assert(false, $message); } catch (Throwable $e) { $assert(str_contains($e->getMessage(), $part), $message); }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repository = new SchedulePolicyCommandProbeRepository($pdo);
$audit = new SchedulePolicyCommandProbeAudit($pdo);
$service = new SchedulePolicyCommandService(new PdoAttendanceTransactionManager($pdo), $repository, $audit);

$payload = [
    'policy' => ['code' => 'MORNING', 'name' => 'Morning schedule', 'description' => 'Default'],
    'version' => ['valid_from' => '2026-01-01 00:00:00', 'timezone' => 'Africa/Cairo'],
    'days' => [[
        'weekday' => 1, 'is_working_day' => true, 'start_time' => '07:30', 'end_time' => '14:30',
        'required_minutes' => 420, 'late_grace_minutes' => 15,
        'entry_window_before_minutes' => 60, 'entry_window_after_minutes' => 120,
        'exit_window_before_minutes' => 120, 'exit_window_after_minutes' => 60,
    ]],
    'scopes' => [['scope_type' => 'global', 'scope_id' => null, 'priority' => 0, 'valid_from' => '2026-01-01 00:00:00']],
];

$created = $service->createDraft(501, $payload, 'schedule:create:1');
$assert($created['policy_id'] === 1 && $created['version_id'] === 10, 'createDraft returns stable ids');
$assert($created['lock_version'] === 1, 'new draft begins at lock version one');
$assert($repository->allWritesInsideTransaction && $audit->allEventsInsideTransaction, 'business writes and audit share one transaction');
$assert(count($audit->events) === 1 && $audit->events[0]['action'] === 'staff_schedule_draft_created', 'draft creation is audited');
$assert($repository->days[10][0]['start_time'] === '07:30:00', 'command stores normalized domain schedule');
$assert($repository->scopes[10][0]['scope_id'] === 0, 'command normalizes a null global scope id to zero');
$replayed = $service->createDraft(501, $payload, 'schedule:create:1');
$assert($replayed['version_id'] === 10 && count($audit->events) === 1, 'create idempotency replay does not duplicate writes or audit');
$differentCreate = $payload;
$differentCreate['policy']['name'] = 'Different payload';
$assertThrows(
    static fn (): array => $service->createDraft(501, $differentCreate, 'schedule:create:1'),
    'IDEMPOTENCY_CONFLICT',
    'same create key cannot replay a different payload'
);

$updatedPayload = $payload;
$updatedPayload['version']['rounding_rule'] = 'nearest_5';
$updated = $service->updateDraft(10, 501, $updatedPayload, 1, 'schedule:update:1');
$assert($updated['lock_version'] === 2, 'draft update uses optimistic lock and increments it');
$updateReplay = $service->updateDraft(10, 501, $updatedPayload, 1, 'schedule:update:1');
$assert($updateReplay['lock_version'] === 2, 'update replay returns its original receipt after lock advances');
$differentUpdate = $updatedPayload;
$differentUpdate['policy']['name'] = 'Changed update';
$assertThrows(
    static fn (): array => $service->updateDraft(10, 501, $differentUpdate, 1, 'schedule:update:1'),
    'IDEMPOTENCY_CONFLICT',
    'same update key cannot carry a different payload'
);

$repository->conflicts = [[
    'version_id' => 99,
    'scope_type' => 'group',
    'scope_id' => 401,
    'reason' => 'group_membership_overlap',
]];
$assertThrows(
    static fn (): array => $service->publish(10, 501, new DateTimeImmutable('2026-01-01 09:00:00'), 'schedule:publish:conflict'),
    'SCHEDULE_PUBLICATION_CONFLICT',
    'publication rejects an equal-rank active-group membership conflict'
);
$repository->conflicts = [];
$published = $service->publish(10, 501, new DateTimeImmutable('2026-01-01 09:00:00'), 'schedule:publish:1');
$assert($published['state'] === 'published' && $published['lock_version'] === 3, 'publication transitions the draft once');
$publishReplay = $service->publish(10, 501, new DateTimeImmutable('2026-01-01 09:00:00'), 'schedule:publish:1');
$assert($publishReplay['lock_version'] === 3, 'publish replay returns its original receipt');
$publishReplayWithFreshServerTime = $service->publish(
    10,
    501,
    new DateTimeImmutable('2026-01-01 09:01:00'),
    'schedule:publish:1'
);
$assert($publishReplayWithFreshServerTime['published_at'] === $published['published_at'], 'publish replay ignores regenerated server time and returns the original receipt');
$assertThrows(
    static fn (): array => $service->updateDraft(10, 501, $payload, 3, 'schedule:update:published'),
    'SCHEDULE_VERSION_IMMUTABLE',
    'published version cannot be edited'
);

$successorPayload = $payload;
$successorPayload['policy_id'] = 1;
$successorPayload['version']['valid_from'] = '2026-07-01 00:00:00';
$successorPayload['version']['supersedes_id'] = 10;
$successorPayload['scopes'][0]['valid_from'] = '2026-07-01 00:00:00';
$successor = $service->createDraft(501, $successorPayload, 'schedule:create:v2');
$assert($successor['policy_id'] === 1 && $successor['version_no'] === 2, 'createDraft creates v2 under an existing locked policy');
$assert($repository->versions[11]['supersedes_id'] === 10, 'successor draft preserves its direct predecessor link');
$assert($repository->versions[10]['state'] === 'published' && $repository->versions[10]['valid_to'] === null, 'creating v2 does not mutate open-ended published v1 history');
$successorUpdatePayload = $successorPayload;
unset($successorUpdatePayload['version']['supersedes_id']);
$service->updateDraft(11, 501, $successorUpdatePayload, 1, 'schedule:update:v2-without-lineage-field');
$assert($repository->versions[11]['supersedes_id'] === 10, 'updating a successor draft cannot erase immutable lineage when the UI omits it');
$assertThrows(
    static fn (): array => $service->createDraft(501, $payload + ['policy_id' => 1], 'schedule:create:v2:no-predecessor'),
    'SCHEDULE_SUPERSEDES_REQUIRED',
    'new version for an existing policy requires an explicit predecessor'
);
$invalidSuccessor = $successorPayload;
$invalidSuccessor['version']['valid_from'] = '2025-12-31 00:00:00';
$invalidSuccessor['scopes'][0]['valid_from'] = '2025-12-31 00:00:00';
$assertThrows(
    static fn (): array => $service->createDraft(501, $invalidSuccessor, 'schedule:create:v2:invalid-start'),
    'SCHEDULE_SUCCESSOR_RANGE_INVALID',
    'successor must begin strictly after its published predecessor'
);

$calendar = $service->saveCalendarException(501, [
    'calendar_date' => '2026-01-07',
    'scope_type' => 'global',
    'scope_id' => 0,
    'priority' => 0,
    'exception_type' => 'holiday',
    'reason' => 'Test holiday',
    'status' => 'active',
], 'calendar:create:1');
$assert($calendar['id'] === 100 && $calendar['status'] === 'active', 'calendar exception is created through the command owner');
$assertThrows(
    static fn (): array => $service->saveCalendarException(501, [
        'calendar_date' => '2026-01-09', 'scope_type' => 'global', 'scope_id' => 0,
        'exception_type' => 'override', 'schedule_policy_version_id' => 11,
        'reason' => 'Draft reference', 'status' => 'active',
    ], 'calendar:draft-reference'),
    'CALENDAR_SCHEDULE_VERSION_NOT_PUBLISHED',
    'active calendar exception cannot reference a mutable draft schedule'
);
$assertThrows(
    static fn (): array => $service->saveCalendarException(501, [
        'calendar_date' => '2026-01-09', 'scope_type' => 'global', 'scope_id' => 0,
        'exception_type' => 'override',
        'override_json' => ['start_time' => '14:00', 'end_time' => '08:00', 'required_minutes' => 60],
        'reason' => 'Invalid override', 'status' => 'active',
    ], 'calendar:bad-override'),
    'CALENDAR_OVERRIDE_INVALID',
    'invalid calendar override fails at command time'
);
$calendarReplay = $service->saveCalendarException(501, [
    'calendar_date' => '2026-01-07', 'scope_type' => 'global', 'scope_id' => 0, 'priority' => 0,
    'exception_type' => 'holiday', 'reason' => 'Test holiday', 'status' => 'active',
], 'calendar:create:1');
$assert($calendarReplay['id'] === 100, 'calendar save replay returns the original exception');
$assertThrows(
    static fn (): array => $service->saveCalendarException(501, [
        'calendar_date' => '2026-01-07', 'scope_type' => 'global', 'scope_id' => 0, 'priority' => 0,
        'exception_type' => 'holiday', 'reason' => 'Changed reason', 'status' => 'active',
    ], 'calendar:create:1'),
    'IDEMPOTENCY_CONFLICT',
    'calendar key cannot be reused with changed content'
);
$assertThrows(
    static fn (): array => $service->saveCalendarException(501, [
        'calendar_date' => '2026-01-08', 'scope_type' => 'global', 'scope_id' => 0, 'priority' => 0,
        'exception_type' => 'holiday', 'reason' => 'Cross command', 'status' => 'active',
    ], 'schedule:publish:1'),
    'IDEMPOTENCY_CONFLICT',
    'idempotency key cannot be reused by another command/resource'
);
$superseding = $service->saveCalendarException(501, [
    'calendar_date' => '2026-01-07',
    'scope_type' => 'global',
    'scope_id' => 0,
    'priority' => 0,
    'exception_type' => 'override',
    'override_json' => ['is_working_day' => true, 'start_time' => '09:00', 'end_time' => '12:00', 'required_minutes' => 180],
    'reason' => 'Superseding work day',
    'status' => 'active',
], 'calendar:supersede:1');
$assert($superseding['id'] === 101 && $superseding['supersedes_id'] === 100, 'a second active exception for one date/scope automatically creates an immutable successor');
$assertThrows(
    static fn (): array => $service->saveCalendarException(501, [
        'id' => 101, 'expected_lock_version' => 1, 'calendar_date' => '2026-01-08',
        'scope_type' => 'global', 'scope_id' => 0, 'priority' => 0,
        'exception_type' => 'holiday', 'reason' => 'Wrong date', 'status' => 'active',
    ], 'calendar:supersede:mismatch'),
    'CALENDAR_SUPERSESSION_SCOPE_MISMATCH',
    'calendar correction cannot move an immutable predecessor to another date or scope'
);
$retired = $service->retireCalendarException(501, 101, 1, 'calendar:retire:1');
$assert($retired['id'] === 102 && $retired['status'] === 'retired' && $retired['supersedes_id'] === 101, 'retirement is an immutable successor row');
$retireReplay = $service->retireCalendarException(501, 101, 1, 'calendar:retire:1');
$assert($retireReplay['id'] === 102, 'calendar retirement is idempotent');
$assertThrows(
    static fn (): array => $service->retireCalendarException(501, 101, 9, 'calendar:retire:stale'),
    'CALENDAR_EXCEPTION_STALE',
    'calendar retirement checks the locked predecessor version'
);

$timezonePayload = $payload;
$timezonePayload['policy'] = ['code' => 'TZ_TEST', 'name' => 'Timezone test'];
$timezonePayload['version']['valid_from'] = '2026-10-01T00:00:00+00:00';
$timezonePayload['scopes'][0]['valid_from'] = '2026-10-01 08:00:00';
$originalDefaultTimezone = date_default_timezone_get();
date_default_timezone_set('America/New_York');
try {
    $timezoneVersion = $service->createDraft(501, $timezonePayload, 'schedule:create:timezone');
} finally {
    date_default_timezone_set($originalDefaultTimezone);
}
$assert($repository->versions[$timezoneVersion['version_id']]['valid_from'] === '2026-10-01 03:00:00.000000', 'offset instant converts into policy timezone before DATETIME persistence');
$assert($repository->scopes[$timezoneVersion['version_id']][0]['valid_from'] === '2026-10-01 08:00:00.000000', 'timezone-less scope instant uses policy timezone, not PHP default timezone');

$failurePdo = new PDO('sqlite::memory:');
$failurePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$failureRepository = new SchedulePolicyCommandProbeRepository($failurePdo);
$failureAudit = new SchedulePolicyCommandProbeAudit($failurePdo);
$failureAudit->throw = true;
$failureService = new SchedulePolicyCommandService(new PdoAttendanceTransactionManager($failurePdo), $failureRepository, $failureAudit);
$assertThrows(
    static fn (): array => $failureService->createDraft(501, $payload, 'schedule:create:audit-fail'),
    'AUDIT_WRITE_FAILED',
    'mandatory audit failure propagates'
);
$assert((int) $failurePdo->query('SELECT COUNT(*) FROM schedule_probe')->fetchColumn() === 0, 'audit failure rolls back all database writes');
$failureAudit->throw = false;
$rollbackCalendar = $failureService->saveCalendarException(501, [
    'calendar_date' => '2026-12-01', 'scope_type' => 'global', 'scope_id' => 0,
    'exception_type' => 'holiday', 'reason' => 'Retire rollback fixture', 'status' => 'active',
], 'calendar:retire:rollback:fixture');
$probeBeforeRetire = (int) $failurePdo->query('SELECT COUNT(*) FROM schedule_probe')->fetchColumn();
$failureAudit->throw = true;
$assertThrows(
    static fn (): array => $failureService->retireCalendarException(501, (int) $rollbackCalendar['id'], 1, 'calendar:retire:audit-fail'),
    'AUDIT_WRITE_FAILED',
    'calendar retirement audit failure propagates'
);
$assert((int) $failurePdo->query('SELECT COUNT(*) FROM schedule_probe')->fetchColumn() === $probeBeforeRetire, 'calendar retirement successor and receipt roll back with mandatory audit');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR schedule command failure(s).\n");
    exit(1);
}

echo "Staff-HR schedule policy command tests passed.\n";
