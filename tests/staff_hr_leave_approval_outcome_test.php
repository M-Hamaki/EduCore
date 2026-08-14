<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Approval\StaffApprovalOutcomeRouter;
use EduCore\Modules\Staff\Application\Leave\LeaveApprovalOutcomeHandler;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerGateway;
use EduCore\Modules\Staff\Contracts\LeaveBalanceMovementLookup;
use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\LeaveRequestRepository;

final class LeaveApprovalOutcomeRequestFixture implements LeaveRequestRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $requests;

    /** @var array<int,list<array<string,mixed>>> */
    public array $days;

    /** @param array<int,array<string,mixed>> $requests @param array<int,list<array<string,mixed>>> $days */
    public function __construct(array $requests, array $days)
    {
        $this->requests = $requests;
        $this->days = $days;
    }

    public function transactional(callable $work): mixed { return $work(); }
    public function lockStaffForRequest(int $staffUserId): bool { return $staffUserId === 10; }
    public function requestByCreateIdempotencyForUpdate(string $idempotencyKey): ?array { return null; }
    public function requestBySubmissionIdempotencyForUpdate(string $idempotencyKey): ?array { return null; }
    public function requestForUpdate(int $requestId): ?array { return $this->requests[$requestId] ?? null; }
    public function insertDraft(array $request): int { return 0; }
    public function updateDraft(int $requestId, int $expectedLockVersion, array $changes): bool { return false; }
    public function replaceDraftDays(int $requestId, array $days): array { return []; }
    public function daysForRequestForUpdate(int $requestId): array { return $this->days[$requestId] ?? []; }
    public function submitDraft(int $requestId, int $expectedLockVersion, array $submission): bool { return false; }
    public function attachWorkflowInstance(int $requestId, int $expectedLockVersion, int $workflowInstanceId): bool { return false; }
    public function withdrawDraft(int $requestId, int $expectedLockVersion): bool { return false; }

    public function finalizeWorkflowOutcome(
        int $requestId,
        int $expectedLockVersion,
        string $outcome,
        DateTimeImmutable $decidedAt
    ): bool {
        $request = $this->requests[$requestId] ?? null;
        if (!is_array($request)
            || (string) ($request['status'] ?? '') !== 'pending_approval'
            || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion
            || !in_array($outcome, ['approved', 'rejected'], true)) {
            return false;
        }
        $request['status'] = $outcome;
        $request['lock_version'] = $expectedLockVersion + 1;
        $request['decided_at'] = $decidedAt->format('Y-m-d H:i:s.u');
        if ($outcome === 'approved') {
            $request['approved_at'] = $decidedAt->format('Y-m-d H:i:s.u');
        }
        $this->requests[$requestId] = $request;

        return true;
    }
}

final class LeaveApprovalOutcomeLookupFixture implements LeaveBalanceMovementLookup
{
    /** @var array<string,array<string,mixed>> */
    public array $byIdempotency = [];

    /** @var array<int,array<string,mixed>> */
    public array $accounts = [];

    /** @var array<int,list<array<string,mixed>>> */
    public array $restorations = [];

    public function movementByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->byIdempotency[$idempotencyKey] ?? null;
    }

    public function accountByIdForUpdate(int $accountId): ?array
    {
        return $this->accounts[$accountId] ?? null;
    }

    public function restorationMovementsForSourceForUpdate(int $sourceMovementId): array
    {
        return $this->restorations[$sourceMovementId] ?? [];
    }
}

final class LeaveApprovalOutcomeLedgerFixture implements LeaveBalanceLedgerGateway
{
    /** @var list<array<string,mixed>> */
    public array $movements = [];

    public function record(array $command): array
    {
        $this->movements[] = $command;

        return ['replayed' => false, 'movement_id' => count($this->movements)];
    }

    public function carry(array $command): array { return []; }
    public function reverse(array $command): array { return []; }
}

final class LeaveApprovalOutcomeAttendanceFixture implements AttendanceCoverageChangeGateway
{
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function publish(array $event): array
    {
        $this->events[] = $event;

        return ['change_request_id' => count($this->events), 'status' => 'ready', 'replayed' => false];
    }
}

final class LeaveApprovalOutcomeFinanceQueueFixture implements LeaveFinanceEffectQueue
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function queueForApprovedRequest(
        int $requestId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        $this->calls[] = compact('requestId', 'actorId', 'occurredAt');

        return ['status' => 'queued', 'request_id' => $requestId, 'effect_ids' => [900 + count($this->calls)], 'replayed_effect_ids' => []];
    }
}

final class LeaveApprovalOutcomeAuditFixture implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

final class LeaveApprovalOutcomeCountingHandler implements ApprovalWorkflowOutcomeHandler
{
    public int $calls = 0;

    /** @param array<string,mixed> $instance */
    public function apply(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void
    {
        ++$this->calls;
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $callback, string $code, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), $code), $message . ' (' . $exception->getMessage() . ')');
    }
};
$snapshot = json_encode([
    'schema_version' => 1,
    'policy' => ['version_id' => 77],
    'leave_type' => ['id' => 3, 'unit' => 'day'],
    'allocation' => ['requested_units' => '1.000'],
], JSON_THROW_ON_ERROR);
$account = [
    'id' => 44,
    'staff_user_id' => 10,
    'leave_type_id' => 3,
    'entitlement_period_key' => 'CY-2026',
    'period_from' => '2026-01-01',
    'period_to' => '2026-12-31',
    'negative_balance_limit_units' => '0.000',
];
$normalRequest = [
    'id' => 101,
    'staff_user_id' => 10,
    'leave_type_id' => 3,
    'request_kind' => 'leave',
    'parent_request_id' => null,
    'status' => 'pending_approval',
    'workflow_instance_id' => 400,
    'lock_version' => 3,
    'submission_idempotency_key' => 'submit-101',
    'policy_snapshot' => $snapshot,
    'request_hash' => str_repeat('a', 64),
];
$normalDay = [
    'id' => 501,
    'work_date' => '2026-09-10',
    'requested_units' => '1.000',
    'requested_minutes' => 480,
    'entitlement_period_key' => 'CY-2026',
];
$reservationKey = 'leave-reserve:' . hash('sha256', 'submit-101:501');

$approvedRequests = new LeaveApprovalOutcomeRequestFixture([101 => $normalRequest], [101 => [$normalDay]]);
$approvedLookup = new LeaveApprovalOutcomeLookupFixture();
$approvedLookup->accounts[44] = $account;
$approvedLookup->byIdempotency[$reservationKey] = [
    'id' => 701,
    'account_id' => 44,
    'leave_request_id' => 101,
    'request_day_id' => 501,
    'movement_type' => 'reserve',
    'reserved_delta' => '1.000',
];
$approvedLedger = new LeaveApprovalOutcomeLedgerFixture();
$approvedAttendance = new LeaveApprovalOutcomeAttendanceFixture();
$approvedFinance = new LeaveApprovalOutcomeFinanceQueueFixture();
$approvedAudit = new LeaveApprovalOutcomeAuditFixture();
(new LeaveApprovalOutcomeHandler(
    $approvedRequests,
    $approvedLedger,
    $approvedLookup,
    $approvedAttendance,
    $approvedFinance,
    $approvedAudit
))->apply(
    ['id' => 400, 'resource_type' => 'leave_request', 'resource_id' => 101],
    'approved',
    20,
    new DateTimeImmutable('2026-09-01 10:00:00')
);
$assert(($approvedRequests->requests[101]['status'] ?? null) === 'approved', 'final approval changes only the pending leave request state');
$assert(($approvedLedger->movements[0]['movement_type'] ?? null) === 'consume', 'approved normal leave consumes its exact submit-time reservation');
$assert(($approvedAttendance->events[0]['event_type'] ?? null) === 'coverage_approved', 'approved normal leave requests an Attendance recalculation fact');
$assert(count($approvedFinance->calls) === 1, 'Finance fact is queued only after final leave approval');
$assert(($approvedAudit->events[0]['action'] ?? null) === 'staff_leave_request_approval_finalized', 'final leave outcome is audited');

$rejectedRequests = new LeaveApprovalOutcomeRequestFixture([101 => $normalRequest], [101 => [$normalDay]]);
$rejectedLookup = new LeaveApprovalOutcomeLookupFixture();
$rejectedLookup->accounts[44] = $account;
$rejectedLookup->byIdempotency[$reservationKey] = $approvedLookup->byIdempotency[$reservationKey];
$rejectedLedger = new LeaveApprovalOutcomeLedgerFixture();
$rejectedAttendance = new LeaveApprovalOutcomeAttendanceFixture();
$rejectedFinance = new LeaveApprovalOutcomeFinanceQueueFixture();
(new LeaveApprovalOutcomeHandler(
    $rejectedRequests,
    $rejectedLedger,
    $rejectedLookup,
    $rejectedAttendance,
    $rejectedFinance,
    new LeaveApprovalOutcomeAuditFixture()
))->apply(
    ['id' => 400, 'resource_type' => 'leave_request', 'resource_id' => 101],
    'rejected',
    20,
    new DateTimeImmutable('2026-09-01 10:00:00')
);
$assert(($rejectedLedger->movements[0]['movement_type'] ?? null) === 'release', 'rejected normal leave releases its exact reservation');
$assert($rejectedAttendance->events === [] && $rejectedFinance->calls === [], 'rejected leave publishes neither coverage nor a Finance fact');

$parent = array_replace($normalRequest, [
    'status' => 'approved',
    'workflow_instance_id' => 400,
    'lock_version' => 4,
]);
$earlyReturn = array_replace($normalRequest, [
    'id' => 102,
    'request_kind' => 'early_return',
    'parent_request_id' => 101,
    'status' => 'pending_approval',
    'workflow_instance_id' => 401,
    'lock_version' => 2,
    'submission_idempotency_key' => 'submit-102',
    'request_hash' => str_repeat('b', 64),
]);
$earlyReturnDay = array_replace($normalDay, [
    'id' => 502,
    'requested_units' => '0.500',
    'requested_minutes' => 240,
]);
$parentConsumeKey = 'leave-approval-consume:' . hash('sha256', '400:501');
$returnRequests = new LeaveApprovalOutcomeRequestFixture(
    [101 => $parent, 102 => $earlyReturn],
    [101 => [$normalDay], 102 => [$earlyReturnDay]]
);
$returnLookup = new LeaveApprovalOutcomeLookupFixture();
$returnLookup->accounts[44] = $account;
$returnLookup->byIdempotency[$parentConsumeKey] = [
    'id' => 702,
    'account_id' => 44,
    'leave_request_id' => 101,
    'request_day_id' => 501,
    'movement_type' => 'consume',
    'consumed_delta' => '1.000',
];
$returnLedger = new LeaveApprovalOutcomeLedgerFixture();
$returnAttendance = new LeaveApprovalOutcomeAttendanceFixture();
$returnFinance = new LeaveApprovalOutcomeFinanceQueueFixture();
(new LeaveApprovalOutcomeHandler(
    $returnRequests,
    $returnLedger,
    $returnLookup,
    $returnAttendance,
    $returnFinance,
    new LeaveApprovalOutcomeAuditFixture()
))->apply(
    ['id' => 401, 'resource_type' => 'leave_request', 'resource_id' => 102],
    'approved',
    20,
    new DateTimeImmutable('2026-09-02 10:00:00')
);
$assert(($returnLedger->movements[0]['movement_type'] ?? null) === 'restore', 'approved early return restores only the direct parent consumption');
$assert(($returnLedger->movements[0]['units'] ?? null) === '0.500', 'early return restores exactly its approved successor units');
$assert(($returnLedger->movements[0]['source_id'] ?? null) === 702, 'restore movement points to immutable parent consumption evidence');
$assert(($returnAttendance->events[0]['event_type'] ?? null) === 'coverage_reversed', 'early return requests an Attendance coverage reversal');
$assert(count($returnFinance->calls) === 1, 'approved early return queues a signed Finance reversal fact');

$overRestoreRequests = new LeaveApprovalOutcomeRequestFixture(
    [101 => $parent, 102 => $earlyReturn],
    [101 => [$normalDay], 102 => [$earlyReturnDay]]
);
$overRestoreLookup = new LeaveApprovalOutcomeLookupFixture();
$overRestoreLookup->accounts[44] = $account;
$overRestoreLookup->byIdempotency[$parentConsumeKey] = $returnLookup->byIdempotency[$parentConsumeKey];
$overRestoreLookup->restorations[702] = [[
    'movement_type' => 'restore',
    'source_type' => 'leave_balance_movement',
    'source_id' => 702,
    'available_delta' => '0.750',
]];
$assertThrows(
    static fn () => (new LeaveApprovalOutcomeHandler(
        $overRestoreRequests,
        new LeaveApprovalOutcomeLedgerFixture(),
        $overRestoreLookup,
        new LeaveApprovalOutcomeAttendanceFixture(),
        new LeaveApprovalOutcomeFinanceQueueFixture(),
        new LeaveApprovalOutcomeAuditFixture()
    ))->apply(
        ['id' => 401, 'resource_type' => 'leave_request', 'resource_id' => 102],
        'approved',
        20,
        new DateTimeImmutable('2026-09-02 10:00:00')
    ),
    'LEAVE_APPROVAL_RESTORE_EXCEEDS_PARENT_CONSUMPTION',
    'a later successor cannot restore more than the immutable parent consumption'
);

$permissionHandler = new LeaveApprovalOutcomeCountingHandler();
$leaveHandler = new LeaveApprovalOutcomeCountingHandler();
$router = new StaffApprovalOutcomeRouter($permissionHandler, $leaveHandler);
$router->apply(['resource_type' => 'leave_request'], 'approved', 20, new DateTimeImmutable('2026-09-02 10:00:00'));
$router->apply(['resource_type' => 'permission_request'], 'approved', 20, new DateTimeImmutable('2026-09-02 10:00:00'));
$assert($leaveHandler->calls === 1 && $permissionHandler->calls === 1, 'resource-aware router preserves the original permission owner and adds the leave owner');
$assertThrows(
    static fn () => $router->apply(['resource_type' => 'unknown'], 'approved', 20, new DateTimeImmutable('2026-09-02 10:00:00')),
    'APPROVAL_OUTCOME_RESOURCE_UNSUPPORTED',
    'unknown workflow resources fail closed'
);

if ($failures > 0) {
    exit(1);
}

echo "PASS: staff leave approval outcome contract\n";
