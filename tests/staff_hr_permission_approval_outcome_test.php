<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Permission\PermissionApprovalOutcomeHandler;
use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerGateway;
use EduCore\Modules\Staff\Contracts\PermissionRequestRepository;
use EduCore\Modules\Staff\Contracts\ApprovedCoveragePublicationGateway;

final class PermissionApprovalOutcomeRepositoryFixture implements PermissionRequestRepository
{
    /** @var array<string,mixed> */
    public array $request;

    /** @var list<array<string,mixed>> */
    public array $periods;

    /** @param array<string,mixed> $request @param list<array<string,mixed>> $periods */
    public function __construct(array $request, array $periods)
    {
        $this->request = $request;
        $this->periods = $periods;
    }

    public function transactional(callable $work): mixed { return $work(); }
    public function lockStaffForRequest(int $staffUserId): bool { return $staffUserId === 10; }
    public function requestByCreateIdempotencyForUpdate(string $idempotencyKey): ?array { return null; }
    public function requestBySubmissionIdempotencyForUpdate(string $idempotencyKey): ?array { return null; }
    public function requestForUpdate(int $requestId): ?array
    {
        return (int) ($this->request['id'] ?? 0) === $requestId ? $this->request : null;
    }
    public function insertDraft(array $request): int { return 0; }
    public function updateDraft(int $requestId, int $expectedLockVersion, array $changes): bool { return false; }
    public function replaceDraftPeriods(int $requestId, array $periods): array { return []; }
    public function periodsForRequestForUpdate(int $requestId): array
    {
        return (int) ($this->request['id'] ?? 0) === $requestId ? $this->periods : [];
    }
    public function submitDraft(int $requestId, int $expectedLockVersion, array $submission): bool { return false; }
    public function attachWorkflowInstance(int $requestId, int $expectedLockVersion, int $workflowInstanceId): bool { return false; }
    public function finalizeWorkflowOutcome(
        int $requestId,
        int $expectedLockVersion,
        string $outcome,
        DateTimeImmutable $decidedAt
    ): bool {
        if ((int) ($this->request['id'] ?? 0) !== $requestId
            || (int) ($this->request['lock_version'] ?? 0) !== $expectedLockVersion
            || (string) ($this->request['status'] ?? '') !== 'pending_approval'
            || !in_array($outcome, ['approved', 'rejected'], true)) {
            return false;
        }
        $this->request['status'] = $outcome;
        $this->request['decided_at'] = $decidedAt->format('Y-m-d H:i:s.u');
        $this->request['lock_version'] = $expectedLockVersion + 1;

        return true;
    }
    public function markQuotaException(int $requestId, int $expectedLockVersion, string $reason): bool { return false; }
    public function withdrawDraft(int $requestId, int $expectedLockVersion): bool { return false; }
    public function cancelPendingRequest(int $requestId, int $expectedLockVersion): bool { return false; }
}

final class PermissionApprovalOutcomeLedgerFixture implements PermissionQuotaLedgerGateway
{
    /** @var list<array<string,mixed>> */
    public array $movements = [];

    public function record(array $command): array
    {
        $this->movements[] = $command;

        return ['movement_id' => count($this->movements), 'quota_exception' => false, 'replayed' => false];
    }
}

final class PermissionApprovalOutcomeAuditFixture implements AuditEventWriter
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

final class PermissionApprovalOutcomeCoveragePublisherFixture implements ApprovedCoveragePublicationGateway
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function publishApproved(
        array $request,
        array $snapshot,
        int $workflowInstanceId,
        int $actorId,
        DateTimeImmutable $occurredAt
    ): array {
        $this->calls[] = compact('request', 'snapshot', 'workflowInstanceId', 'actorId', 'occurredAt');

        return ['published_count' => 1];
    }

    public function publishReversed(
        array $request,
        array $snapshot,
        int $workflowInstanceId,
        int $actorId,
        DateTimeImmutable $occurredAt
    ): array {
        $this->calls[] = compact('request', 'snapshot', 'workflowInstanceId', 'actorId', 'occurredAt');

        return ['published_count' => 1];
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

$request = static function (int $id, int $instanceId, bool $reserveOnSubmit = true): array {
    return [
        'id' => $id,
        'staff_user_id' => 10,
        'permission_type_id' => 5,
        'status' => 'pending_approval',
        'workflow_instance_id' => $instanceId,
        'lock_version' => 4,
        'policy_snapshot' => json_encode(['policy' => [
            'reserve_on_submit' => $reserveOnSubmit,
            'max_requests_per_month' => 3,
            'max_minutes_per_month' => 360,
            'allow_quota_override' => false,
            'quota_override_max_minutes' => null,
        ], 'type' => [
            'coverage_behavior' => 'late_arrival',
        ]], JSON_THROW_ON_ERROR),
    ];
};
$periods = static function (int $requestId): array {
    return [[
        'id' => $requestId * 10,
        'request_id' => $requestId,
        'period_key' => '2026-10',
        'requested_count' => 1,
        'requested_minutes' => 90,
    ]];
};
$at = new DateTimeImmutable('2026-10-02 09:00:00', new DateTimeZone('Africa/Cairo'));

$approvedRepo = new PermissionApprovalOutcomeRepositoryFixture($request(41, 701), $periods(41));
$approvedLedger = new PermissionApprovalOutcomeLedgerFixture();
$approvedAudit = new PermissionApprovalOutcomeAuditFixture();
$approvedCoverage = new PermissionApprovalOutcomeCoveragePublisherFixture();
(new PermissionApprovalOutcomeHandler($approvedRepo, $approvedLedger, $approvedAudit, $approvedCoverage))->apply([
    'id' => 701,
    'resource_type' => 'permission_request',
    'resource_id' => 41,
], 'approved', 99, $at);
$assert($approvedRepo->request['status'] === 'approved' && $approvedRepo->request['lock_version'] === 5, 'final approval changes only the linked pending request once');
$assert(count($approvedLedger->movements) === 1 && $approvedLedger->movements[0]['movement_type'] === 'consume', 'final approval consumes the existing reservation');
$assert(($approvedLedger->movements[0]['idempotency_key'] ?? '') !== '', 'final approval uses a durable movement idempotency key');
$assert(($approvedAudit->events[0]['action'] ?? '') === 'staff_permission_request_approval_finalized', 'final approval is audited with the resource outcome');
$assert(count($approvedCoverage->calls) === 1 && (($approvedCoverage->calls[0]['snapshot']['type']['coverage_behavior'] ?? null) === 'late_arrival'), 'final approval publishes the frozen approved-coverage snapshot once');

$rejectedRepo = new PermissionApprovalOutcomeRepositoryFixture($request(42, 702), $periods(42));
$rejectedLedger = new PermissionApprovalOutcomeLedgerFixture();
$rejectedAudit = new PermissionApprovalOutcomeAuditFixture();
$rejectedCoverage = new PermissionApprovalOutcomeCoveragePublisherFixture();
(new PermissionApprovalOutcomeHandler($rejectedRepo, $rejectedLedger, $rejectedAudit, $rejectedCoverage))->apply([
    'id' => 702,
    'resource_type' => 'permission_request',
    'resource_id' => 42,
], 'rejected', 99, $at);
$assert($rejectedRepo->request['status'] === 'rejected', 'final rejection closes the linked pending request');
$assert(count($rejectedLedger->movements) === 1 && $rejectedLedger->movements[0]['movement_type'] === 'release', 'final rejection releases the held quota exactly once');
$assert(count($rejectedCoverage->calls) === 0, 'rejected request never publishes attendance coverage');

$lateReserveRepo = new PermissionApprovalOutcomeRepositoryFixture($request(43, 703, false), $periods(43));
$lateReserveLedger = new PermissionApprovalOutcomeLedgerFixture();
$lateReserveAudit = new PermissionApprovalOutcomeAuditFixture();
$lateReserveCoverage = new PermissionApprovalOutcomeCoveragePublisherFixture();
(new PermissionApprovalOutcomeHandler($lateReserveRepo, $lateReserveLedger, $lateReserveAudit, $lateReserveCoverage))->apply([
    'id' => 703,
    'resource_type' => 'permission_request',
    'resource_id' => 43,
], 'approved', 99, $at);
$assert(
    array_column($lateReserveLedger->movements, 'movement_type') === ['reserve', 'consume'],
    'a policy that does not hold pending quota still reserves and consumes atomically on approval'
);

$assertThrows(
    static fn () => (new PermissionApprovalOutcomeHandler($approvedRepo, $approvedLedger, $approvedAudit, $approvedCoverage))->apply([
        'id' => 701,
        'resource_type' => 'leave_request',
        'resource_id' => 41,
    ], 'approved', 99, $at),
    'APPROVAL_OUTCOME_RESOURCE_UNSUPPORTED',
    'permission outcome handler rejects a foreign resource type fail-closed'
);

if ($failures > 0) {
    exit(1);
}

echo "Staff-HR permission approval outcome tests passed.\n";
