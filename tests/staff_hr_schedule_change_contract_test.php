<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\ScheduleChangeRequestService;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyRepository;
use EduCore\Modules\Attendance\Contracts\ScheduleChangeAuthorization;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

final class ScheduleChangeProbeRepository implements SchedulePolicyRepository
{
    public PDO $db;
    public array $requests = [];
    public array $receipts = [];
    public array $versions = [];
    public bool $allWritesInsideTransaction = true;
    private int $sequence = 200;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $db->exec('CREATE TABLE change_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL)');
    }
    private function write(string $kind): void
    {
        $this->allWritesInsideTransaction = $this->allWritesInsideTransaction && $this->db->inTransaction();
        $statement = $this->db->prepare('INSERT INTO change_probe (kind) VALUES (?)');
        $statement->execute([$kind]);
    }
    public function findCommandReceipt(string $key): ?array { return $this->receipts[$key] ?? null; }
    public function recordCommandReceipt(array $receipt): void { $this->write('receipt'); $this->receipts[$receipt['idempotency_key']] = $receipt; }
    public function findChangeRequestByIdempotency(string $key): ?array
    {
        foreach ($this->requests as $request) if (($request['idempotency_key'] ?? '') === $key) return $request;
        return null;
    }
    public function changeRequestForUpdate(int $id): ?array { return $this->requests[$id] ?? null; }
    public function insertChangeRequest(array $request): int
    {
        $this->write('request');
        $id = $this->sequence++;
        $this->requests[$id] = $request + ['id' => $id, 'lock_version' => 1];
        return $id;
    }
    public function updateChangeRequest(int $id, int $expected, array $changes): bool
    {
        $current = $this->requests[$id] ?? null;
        if ($current === null || (int) $current['lock_version'] !== $expected) return false;
        $this->write('request_update');
        $this->requests[$id] = $changes + $current;
        $this->requests[$id]['lock_version'] = $expected + 1;
        return true;
    }
    public function lockChangeParticipants(array $staffIds): void { $this->write('participant_lock'); }
    public function overlappingChangeRequests(array $staffIds, DateTimeImmutable $from, DateTimeImmutable $to, array $statuses, ?int $exclude = null): array
    {
        $result = [];
        foreach ($this->requests as $request) {
            if ($exclude === (int) $request['id'] || !in_array($request['status'], $statuses, true)) continue;
            $participants = [(int) $request['staff_user_id'], (int) ($request['counterpart_staff_id'] ?? 0)];
            if (array_intersect($staffIds, $participants) === []) continue;
            if (new DateTimeImmutable($request['from_at']) < $to && new DateTimeImmutable($request['to_at']) > $from) $result[] = $request;
        }
        return $result;
    }
    public function approvedChangesFor(int $staffId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(array_filter($this->overlappingChangeRequests([$staffId], $from, $to, ['approved'])));
    }
    public function findVersion(int $id): ?array { return $this->versions[$id] ?? null; }
    public function nextVersionNumber(int $policyId): int { return 1; }
    public function policyForUpdate(int $policyId): ?array { return null; }
    public function insertPolicy(array $policy): int { return 0; }
    public function updatePolicy(int $policyId, array $policy): void {}
    public function insertDraftVersion(int $policyId, array $version): int { return 0; }
    public function findVersionByCreateKey(string $key): ?array { return null; }
    public function versionForUpdate(int $id): ?array { return null; }
    public function updateDraftVersion(int $id, int $lock, array $version): bool { return false; }
    public function replaceDraftDays(int $id, array $days): void {}
    public function replaceDraftScopes(int $id, array $scopes): void {}
    public function publicationConflicts(int $id): array { return []; }
    public function markPublished(int $id, int $lock, int $actor, DateTimeImmutable $at, string $key, string $hash): bool { return false; }
    public function findCalendarExceptionByIdempotency(string $key): ?array { return null; }
    public function calendarExceptionForUpdate(int $id): ?array { return null; }
    public function terminalCalendarExceptionForDateScopeForUpdate(string $calendarDate, string $scopeType, int $scopeId): ?array { return null; }
    public function insertCalendarException(array $exception): int { return 0; }
    public function updateDraftCalendarException(int $id, int $lock, array $exception): bool { return false; }
    public function listPolicies(array $filters = []): array { return []; }
    public function findPolicy(int $id): ?array { return null; }
    public function candidateVersionsFor(int $staffId, array $assignment, DateTimeImmutable $at): array { return []; }
    public function calendarExceptionsFor(int $staffId, array $assignment, DateTimeImmutable $date): array { return []; }
    public function listCalendarExceptions(array $filters = []): array { return []; }
}

final class ScheduleChangeProbeAudit implements AuditEventWriter
{
    public PDO $db;
    public array $events = [];
    public bool $throw = false;
    public bool $inside = true;
    public function __construct(PDO $db) { $this->db = $db; }
    public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void
    {
        $this->inside = $this->inside && $this->db->inTransaction();
        if ($this->throw) throw new RuntimeException('AUDIT_WRITE_FAILED');
        $this->events[] = compact('action', 'entityType', 'recordId', 'details', 'context');
    }
}

final class ScheduleChangeProbeAuthorization implements ScheduleChangeAuthorization
{
    public array $approvers = [900];
    public array $workflowResources = [];
    public function canSubmit(int $actorId, int $staffId, array $payload): bool { return $actorId === $staffId; }
    public function canLinkWorkflow(int $actorId, array $request, int $workflowInstanceId): bool
    {
        return $actorId === (int) $request['staff_user_id']
            && ($this->workflowResources[$workflowInstanceId] ?? 0) === (int) $request['id'];
    }
    public function canApprove(int $actorId, array $request): bool
    {
        return in_array($actorId, $this->approvers, true)
            && ($this->workflowResources[(int) ($request['workflow_instance_id'] ?? 0)] ?? 0) === (int) $request['id'];
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repository = new ScheduleChangeProbeRepository($pdo);
$audit = new ScheduleChangeProbeAudit($pdo);
$authorization = new ScheduleChangeProbeAuthorization();
$service = new ScheduleChangeRequestService(new PdoAttendanceTransactionManager($pdo), $repository, $audit, $authorization);
$schedule = ['timezone' => 'Africa/Cairo', 'days' => [[
    'weekday' => 1, 'is_working_day' => true, 'start_time' => '07:30', 'end_time' => '14:30',
    'required_minutes' => 420, 'entry_window_before_minutes' => 60, 'entry_window_after_minutes' => 120,
    'exit_window_before_minutes' => 120, 'exit_window_after_minutes' => 60,
]]];
$repository->versions[50] = ['version_id' => 50, 'state' => 'published', 'schedule' => $schedule];
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};
$assertThrows = static function (callable $callback, string $part, string $message) use ($assert): void {
    try { $callback(); $assert(false, $message); } catch (Throwable $e) { $assert(str_contains($e->getMessage(), $part), $message); }
};
$assertThrows(static fn () => $service->submit(600, [
    'staff_user_id' => 501, 'change_type' => 'overtime',
    'from_at' => '2026-08-01 14:30:00', 'to_at' => '2026-08-01 16:00:00', 'reason' => 'Unauthorized',
], 'change:unauthorized-submit'), 'SCHEDULE_CHANGE_SUBMIT_FORBIDDEN', 'staff cannot submit a schedule change for another worker');

$temporary = $service->submit(501, [
    'staff_user_id' => 501, 'change_type' => 'temporary_shift',
    'from_at' => '2026-08-03 07:30:00', 'to_at' => '2026-08-03 14:30:00',
    'requested_schedule_version_id' => 50, 'reason' => 'Temporary coverage',
], 'change:temporary:1');
$assert($temporary['id'] === 200 && $temporary['status'] === 'submitted', 'temporary shift is submitted atomically');
$assert($repository->allWritesInsideTransaction && $audit->inside, 'change writes and audit share one transaction');
$replay = $service->submit(501, [
    'staff_user_id' => 501, 'change_type' => 'temporary_shift',
    'from_at' => '2026-08-03 07:30:00', 'to_at' => '2026-08-03 14:30:00',
    'requested_schedule_version_id' => 50, 'reason' => 'Temporary coverage',
], 'change:temporary:1');
$assert($replay['id'] === 200 && count($audit->events) === 1, 'submit replay has no duplicate write or audit');
$assertThrows(static fn () => $service->submit(501, [
    'staff_user_id' => 501, 'change_type' => 'overtime',
    'from_at' => '2026-08-03 13:00:00', 'to_at' => '2026-08-03 16:00:00', 'reason' => 'Overlap',
], 'change:overlap'), 'SCHEDULE_CHANGE_OVERLAP', 'overlapping active request fails closed');

$swap = $service->submit(501, [
    'staff_user_id' => 501, 'change_type' => 'shift_swap', 'counterpart_staff_id' => 502,
    'from_at' => '2026-08-10 07:30:00', 'to_at' => '2026-08-10 14:30:00',
    'requested_schedule_version_id' => 50, 'reason' => 'Swap day',
], 'change:swap:1');
$assert($swap['status'] === 'pending_counterpart', 'swap waits for counterpart acceptance');
$assertThrows(
    static fn () => $service->approve($swap['id'], 900, 1, ['staff_schedules' => ['501' => ['schedule' => $schedule], '502' => ['schedule' => $schedule]]], new DateTimeImmutable('2026-08-01 09:00:00'), 'change:swap:approve:early'),
    'SWAP_COUNTERPART_ACCEPTANCE_REQUIRED',
    'swap cannot be approved before counterpart acceptance'
);
$assertThrows(
    static fn () => $service->acceptSwap($swap['id'], 503, 1, new DateTimeImmutable('2026-08-01 09:01:00'), 'change:swap:wrong-user'),
    'SWAP_COUNTERPART_ONLY',
    'only the named counterpart can accept a swap'
);
$accepted = $service->acceptSwap($swap['id'], 502, 1, new DateTimeImmutable('2026-08-01 09:02:00'), 'change:swap:accept');
$assert($accepted['status'] === 'submitted' && $accepted['lock_version'] === 2, 'counterpart acceptance advances swap to submitted');
$assertThrows(
    static fn () => $service->linkWorkflow($swap['id'], 700, 501, 2, 'change:swap:workflow:mismatch'),
    'SCHEDULE_CHANGE_WORKFLOW_MISMATCH',
    'workflow link fails unless evidence points at the exact request'
);
$authorization->workflowResources[700] = $swap['id'];
$linkedSwap = $service->linkWorkflow($swap['id'], 700, 501, 2, 'change:swap:workflow');
$assert($linkedSwap['workflow_instance_id'] === 700 && $linkedSwap['lock_version'] === 3, 'submitted request links to matching workflow evidence atomically');
$linkedSwapReplay = $service->linkWorkflow($swap['id'], 700, 501, 2, 'change:swap:workflow');
$assert($linkedSwapReplay['lock_version'] === 3, 'workflow link is idempotent after the row lock advances');
$assertThrows(
    static fn () => $service->approve($swap['id'], 901, 3, ['staff_schedules' => ['501' => ['schedule' => $schedule], '502' => ['schedule' => $schedule]]], new DateTimeImmutable('2026-08-01 09:30:00'), 'change:swap:approve:unauthorized'),
    'SCHEDULE_CHANGE_APPROVAL_FORBIDDEN',
    'approval fails closed without workflow authorization evidence'
);
$approvedSwap = $service->approve($swap['id'], 900, 3, ['staff_schedules' => ['501' => ['schedule' => $schedule], '502' => ['schedule' => $schedule]]], new DateTimeImmutable('2026-08-01 10:00:00'), 'change:swap:approve');
$assert($approvedSwap['status'] === 'approved' && $approvedSwap['lock_version'] === 4, 'accepted linked swap can be approved with immutable staff snapshots');

$overtime = $service->submit(501, [
    'staff_user_id' => 501, 'change_type' => 'overtime',
    'from_at' => '2026-08-17 14:30:00', 'to_at' => '2026-08-17 16:30:00', 'reason' => 'Event coverage',
], 'change:overtime:1');
$assert($repository->approvedChangesFor(501, new DateTimeImmutable('2026-08-17'), new DateTimeImmutable('2026-08-18')) === [], 'overtime has no attendance effect before approval');
$authorization->workflowResources[701] = $overtime['id'];
$service->linkWorkflow($overtime['id'], 701, 501, 1, 'change:overtime:workflow');
$approvedOvertime = $service->approve($overtime['id'], 900, 2, [], new DateTimeImmutable('2026-08-02 08:00:00'), 'change:overtime:approve');
$assert($approvedOvertime['status'] === 'approved', 'overtime approval is persisted');
$assert(count($repository->approvedChangesFor(501, new DateTimeImmutable('2026-08-17'), new DateTimeImmutable('2026-08-18'))) === 1, 'approved overtime becomes visible to effective attendance resolution');

$alternative = $service->submit(501, [
    'staff_user_id' => 501, 'change_type' => 'alternative_attendance',
    'from_at' => '2026-08-20 07:30:00', 'to_at' => '2026-08-20 14:30:00', 'reason' => 'Approved offsite device',
], 'change:alternative:1');
$assert($alternative['status'] === 'submitted', 'alternative attendance remains a first-class change type');

$failurePdo = new PDO('sqlite::memory:');
$failurePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$failureRepository = new ScheduleChangeProbeRepository($failurePdo);
$failureRepository->versions[50] = $repository->versions[50];
$failureAudit = new ScheduleChangeProbeAudit($failurePdo);
$failureAudit->throw = true;
$failureService = new ScheduleChangeRequestService(new PdoAttendanceTransactionManager($failurePdo), $failureRepository, $failureAudit, new ScheduleChangeProbeAuthorization());
$assertThrows(static fn () => $failureService->submit(501, [
    'staff_user_id' => 501, 'change_type' => 'temporary_shift',
    'from_at' => '2026-09-01 07:30:00', 'to_at' => '2026-09-01 14:30:00',
    'requested_schedule_version_id' => 50, 'reason' => 'Audit rollback',
], 'change:audit-fail'), 'AUDIT_WRITE_FAILED', 'mandatory audit failure propagates');
$assert((int) $failurePdo->query('SELECT COUNT(*) FROM change_probe')->fetchColumn() === 0, 'audit failure rolls back change write and receipt');

if ($failures > 0) { fwrite(STDERR, "{$failures} schedule change failure(s).\n"); exit(1); }
echo "Staff-HR schedule change contracts passed.\n";
