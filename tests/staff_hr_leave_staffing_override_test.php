<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';
require_once $root . '/src/Modules/Attendance/bootstrap.php';

use EduCore\Modules\Attendance\Contracts\LeaveWorkdayCalendarQuery;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Leave\LeavePolicyService;
use EduCore\Modules\Staff\Application\Leave\LeaveRequestService;
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingOverrideAuthorization;
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingOverrideService;
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingPolicy;
use EduCore\Modules\Staff\Application\Policy\EffectivePolicyResolver;
use EduCore\Modules\Staff\Contracts\ApprovalRoleAssigneeQuery;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowResolutionGateway;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerGateway;
use EduCore\Modules\Staff\Contracts\LeavePolicyReadRepository;
use EduCore\Modules\Staff\Contracts\LeaveRequestAuthorization;
use EduCore\Modules\Staff\Contracts\LeaveRequestClock;
use EduCore\Modules\Staff\Contracts\LeaveRequestOverlapQuery;
use EduCore\Modules\Staff\Contracts\LeaveRequestRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideApprovalQuery;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideRequestGateway;
use EduCore\Modules\Staff\Contracts\LeaveStaffingReadRepository;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffEmploymentQuery;

final class LeaveStaffingOverrideTestRequests implements LeaveRequestRepository, LeaveRequestOverlapQuery
{
    /** @var array<int,array<string,mixed>> */
    public array $requests = [];
    /** @var array<int,list<array<string,mixed>>> */
    public array $days = [];
    private int $nextRequestId = 1;
    private int $nextDayId = 1;

    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function lockStaffForRequest(int $staffUserId): bool
    {
        return $staffUserId === 55;
    }

    public function requestByCreateIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->requests as $request) {
            if (($request['create_idempotency_key'] ?? null) === $idempotencyKey) {
                return $request;
            }
        }

        return null;
    }

    public function requestBySubmissionIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->requests as $request) {
            if (($request['submission_idempotency_key'] ?? null) === $idempotencyKey) {
                return $request;
            }
        }

        return null;
    }

    public function requestForUpdate(int $requestId): ?array
    {
        return $this->requests[$requestId] ?? null;
    }

    public function insertDraft(array $request): int
    {
        $id = $this->nextRequestId++;
        $this->requests[$id] = $request + [
            'id' => $id,
            'status' => 'draft',
            'lock_version' => 1,
            'staffing_override_granted' => 0,
            'staffing_override_reason' => null,
            'submission_idempotency_key' => null,
        ];

        return $id;
    }

    public function updateDraft(int $requestId, int $expectedLockVersion, array $changes): bool
    {
        $request = $this->requests[$requestId] ?? null;
        if (!is_array($request)
            || ($request['status'] ?? null) !== 'draft'
            || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->requests[$requestId] = array_replace($request, $changes, [
            'staffing_override_granted' => 0,
            'staffing_override_reason' => null,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function replaceDraftDays(int $requestId, array $days): array
    {
        $stored = [];
        foreach ($days as $day) {
            $stored[] = $day + [
                'id' => $this->nextDayId++,
                'request_id' => $requestId,
            ];
        }
        $this->days[$requestId] = $stored;

        return $stored;
    }

    public function daysForRequestForUpdate(int $requestId): array
    {
        return $this->days[$requestId] ?? [];
    }

    public function submitDraft(int $requestId, int $expectedLockVersion, array $submission): bool
    {
        $request = $this->requests[$requestId] ?? null;
        if (!is_array($request)
            || ($request['status'] ?? null) !== 'draft'
            || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->requests[$requestId] = array_replace($request, $submission, [
            'status' => 'pending_approval',
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function attachWorkflowInstance(int $requestId, int $expectedLockVersion, int $workflowInstanceId): bool
    {
        $request = $this->requests[$requestId] ?? null;
        if (!is_array($request) || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->requests[$requestId] = array_replace($request, [
            'workflow_instance_id' => $workflowInstanceId,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function withdrawDraft(int $requestId, int $expectedLockVersion): bool
    {
        return false;
    }

    public function finalizeWorkflowOutcome(
        int $requestId,
        int $expectedLockVersion,
        string $outcome,
        DateTimeImmutable $decidedAt
    ): bool {
        return false;
    }

    public function conflictsForStaffForUpdate(
        int $staffUserId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        ?int $excludingRequestId = null
    ): array {
        return [];
    }
}

final class LeaveStaffingOverrideTestRequestGateway implements LeaveStaffingOverrideRequestGateway
{
    public function __construct(private LeaveStaffingOverrideTestRequests $requests)
    {
    }

    public function applyStaffingOverrideDecision(
        int $requestId,
        int $expectedLockVersion,
        bool $granted,
        ?string $reason
    ): bool {
        $request = $this->requests->requests[$requestId] ?? null;
        if (!is_array($request)
            || ($request['status'] ?? null) !== 'draft'
            || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->requests->requests[$requestId] = array_replace($request, [
            'staffing_override_granted' => $granted ? 1 : 0,
            'staffing_override_reason' => $granted ? $reason : null,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }
}

final class LeaveStaffingOverrideTestEvidence implements LeaveStaffingOverrideRepository, LeaveStaffingOverrideApprovalQuery
{
    /** @var array<int,array<string,mixed>> */
    public array $decisions = [];
    private int $nextId = 1;

    public function decisionByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->decisions as $decision) {
            if (($decision['decision_idempotency_key'] ?? null) === $idempotencyKey) {
                return $decision;
            }
        }

        return null;
    }

    public function approvedDecisionForRequestHashForUpdate(int $requestId, string $requestHash): ?array
    {
        foreach ($this->decisions as $decision) {
            if ((int) ($decision['leave_request_id'] ?? 0) === $requestId
                && ($decision['request_hash'] ?? null) === $requestHash
                && ($decision['decision_outcome'] ?? null) === 'approved') {
                return $decision;
            }
        }

        return null;
    }

    public function insertDecision(array $decision): int
    {
        $id = $this->nextId++;
        $this->decisions[$id] = $decision + ['id' => $id];

        return $id;
    }
}

final class LeaveStaffingOverrideTestRoles implements ApprovalRoleAssigneeQuery
{
    /** @var array<int,list<string>> */
    public array $rolesByUser = [900 => ['hr_staffing_override']];

    public function activeUsersForRoles(array $roleKeys, DateTimeImmutable $resolvedAt): array
    {
        $rows = [];
        foreach ($this->rolesByUser as $userId => $roles) {
            $matched = array_values(array_intersect($roles, $roleKeys));
            if ($matched !== []) {
                $rows[] = ['user_id' => $userId, 'role_keys' => $matched];
            }
        }

        return $rows;
    }
}

final class LeaveStaffingOverrideTestPolicyRepository implements LeavePolicyReadRepository
{
    public bool $requiresOverride = true;
    public ?string $overrideRole = 'hr_staffing_override';
    public ?int $minimumAvailable = 3;

    public function findType(int $leaveTypeId): ?array
    {
        return $leaveTypeId === 3 ? [
            'id' => 3,
            'code' => 'annual',
            'name' => 'Annual leave',
            'unit' => 'day',
            'allow_partial_unit' => 1,
            'requires_reason' => 0,
            'requires_attachment' => 0,
            'requires_medical_document' => 0,
            'payroll_effect_code' => null,
            'status' => 'active',
        ] : null;
    }

    public function candidateVersionsFor(
        int $leaveTypeId,
        int $staffId,
        array $assignment,
        DateTimeImmutable $effectiveAt
    ): array {
        return [[
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
            'min_notice_minutes' => 0,
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
            'minimum_available_staff' => $this->minimumAvailable,
            'max_absence_percentage' => null,
            'requires_staffing_override' => $this->requiresOverride ? 1 : 0,
            'override_role_key' => $this->overrideRole,
            'scope_type' => 'global',
            'scope_id' => 0,
            'scope_priority' => 0,
            'scope_valid_from' => '2025-01-01 00:00:00.000000',
            'scope_valid_to' => null,
            'scope_status' => 'active',
        ]];
    }
}

final class LeaveStaffingOverrideTestAssignment implements StaffAssignmentAtDateQuery
{
    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
    {
        return $staffId === 55 ? [
            'assignment_id' => 44,
            'org_unit_id' => 12,
            'job_title_id' => 8,
            'group_ids' => [5],
            'employment_status' => 'active',
        ] : null;
    }
}

final class LeaveStaffingOverrideTestEmployment implements StaffEmploymentQuery
{
    public function activeContractOf(int $staffId, ?string $atDate = null): ?array
    {
        return $staffId === 55 ? [
            'staff_id' => 55,
            'employee_code' => 'E-55',
            'job_title' => 'Teacher',
            'department' => 'Academics',
            'hire_date' => '2020-01-01',
            'current_work_status' => 'on_duty',
            'is_active' => true,
        ] : null;
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

final class LeaveStaffingOverrideTestCalendar implements LeaveWorkdayCalendarQuery
{
    public function daysIntersecting(
        int $staffId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        DateTimeZone $requestTimezone
    ): array {
        $start = $fromAt->setTimezone($requestTimezone)->setTime(8, 0);
        $end = $fromAt->setTimezone($requestTimezone)->setTime(16, 0);

        return [[
            'status' => 'working',
            'reason_code' => 'EFFECTIVE_SCHEDULE_RESOLVED',
            'staff_id' => $staffId,
            'work_date' => $start->format('Y-m-d'),
            'required_minutes' => 480,
            'working_intervals' => [[
                'start_at' => $start->format('Y-m-d H:i:s.u'),
                'end_at' => $end->format('Y-m-d H:i:s.u'),
                'minutes' => 480,
            ]],
            'schedule_policy_version_id' => 501,
            'calendar_exception_id' => null,
            'conflicts' => [],
        ]];
    }
}

final class LeaveStaffingOverrideTestStaffing implements LeaveStaffingReadRepository
{
    /** @var list<array<string,mixed>> */
    public array $blackouts = [];

    public function blackoutsFor(
        int $policyVersionId,
        array $assignment,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt
    ): array {
        return $this->blackouts;
    }

    public function availabilityForScopeForUpdate(
        string $scopeType,
        int $scopeId,
        DateTimeImmutable $workDate,
        ?int $excludingRequestId = null
    ): array {
        return [
            'staff_ids' => [55, 56, 57],
            'absent_staff_ids' => [],
            'conflicting_staff_ids' => [],
        ];
    }
}

final class LeaveStaffingOverrideTestAuthorization implements LeaveRequestAuthorization
{
    public function assertCanAct(
        int $actorId,
        int $staffUserId,
        string $action,
        DateTimeImmutable $atInstant
    ): void {
        if ($actorId !== $staffUserId) {
            throw new DomainException('LEAVE_REQUEST_ACTOR_MISMATCH');
        }
    }
}

final class LeaveStaffingOverrideTestWorkflow implements ApprovalWorkflowResolutionGateway, ApprovalWorkflowSubmissionGateway
{
    public function resolveForResource(
        string $resourceType,
        int $staffUserId,
        array $context,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $resolvedAt
    ): array {
        return [
            'workflow_version_id' => 99,
            'snapshot' => [
                'resource_type' => $resourceType,
                'stages' => [['sequence' => 1, 'assignees' => [900]]],
            ],
        ];
    }

    public function submit(array $command): array
    {
        return ['instance_id' => 701];
    }
}

final class LeaveStaffingOverrideTestLedger implements LeaveBalanceLedgerGateway
{
    /** @var list<array<string,mixed>> */
    public array $movements = [];

    public function record(array $command): array
    {
        $this->movements[] = $command;

        return ['movement_id' => count($this->movements), 'replayed' => false];
    }

    public function carry(array $command): array
    {
        throw new RuntimeException('not used');
    }

    public function reverse(array $command): array
    {
        throw new RuntimeException('not used');
    }
}

final class LeaveStaffingOverrideTestAudit implements AuditEventWriter
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

final class LeaveStaffingOverrideTestClock implements LeaveRequestClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-01 09:00:00', new DateTimeZone('Africa/Cairo'));
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        ++$failures;
    }
};
$assertThrows = static function (callable $work, string $expected, string $message) use (&$failures): void {
    try {
        $work();
        fwrite(STDERR, "FAIL: " . $message . " (no exception)" . PHP_EOL);
        ++$failures;
    } catch (DomainException $exception) {
        if ($exception->getMessage() !== $expected) {
            fwrite(STDERR, "FAIL: " . $message . " (got " . $exception->getMessage() . ")" . PHP_EOL);
            ++$failures;
        }
    }
};

$requests = new LeaveStaffingOverrideTestRequests();
$evidence = new LeaveStaffingOverrideTestEvidence();
$policies = new LeaveStaffingOverrideTestPolicyRepository();
$staffingRead = new LeaveStaffingOverrideTestStaffing();
$clock = new LeaveStaffingOverrideTestClock();
$audit = new LeaveStaffingOverrideTestAudit();
$ledger = new LeaveStaffingOverrideTestLedger();
$workflow = new LeaveStaffingOverrideTestWorkflow();
$policyService = new LeavePolicyService(
    $policies,
    new LeaveStaffingOverrideTestAssignment(),
    new LeaveStaffingOverrideTestEmployment(),
    new LeaveStaffingOverrideTestCalendar(),
    new EffectivePolicyResolver()
);
$staffingPolicy = new LeaveStaffingPolicy($staffingRead);
$requestService = new LeaveRequestService(
    $requests,
    $requests,
    $policyService,
    $ledger,
    new LeaveStaffingOverrideTestAuthorization(),
    $workflow,
    $audit,
    $clock,
    $workflow,
    $staffingPolicy,
    null,
    $evidence
);
$overrideService = new LeaveStaffingOverrideService(
    $requests,
    new LeaveStaffingOverrideTestRequestGateway($requests),
    $evidence,
    $policyService,
    $staffingPolicy,
    new LeaveStaffingOverrideAuthorization(new LeaveStaffingOverrideTestRoles()),
    $audit,
    $clock
);

$draft = $requestService->createDraft([
    'actor_id' => 55,
    'staff_user_id' => 55,
    'leave_type_id' => 3,
    'from_at' => '2026-09-10 08:00:00',
    'to_at' => '2026-09-10 16:00:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'annual leave',
    'reason_code' => 'annual',
    'create_idempotency_key' => 'staffing-override-draft-1',
]);
$submit = static fn (): array => $requestService->submit([
    'actor_id' => 55,
    'request_id' => $draft['request_id'],
    'expected_lock_version' => 1,
    'submission_idempotency_key' => 'staffing-override-submit-1',
]);
$assertThrows(
    $submit,
    'LEAVE_STAFFING_OVERRIDE_REQUIRED',
    'worker cannot submit a capacity-breaching request before a manager records an override'
);
$assert(count($ledger->movements) === 0, 'failed override requirement does not reserve an entitlement balance');

$decisionCommand = [
    'actor_id' => 900,
    'request_id' => $draft['request_id'],
    'expected_lock_version' => 1,
    'decision_outcome' => 'approved',
    'decision_idempotency_key' => 'staffing-override-decision-1',
    'reason' => 'Documented operational coverage exception',
];
$decision = $overrideService->decide($decisionCommand);
$assert($decision['granted'] === true, 'authorized manager can grant a required staffing override');
$assert($decision['lock_version'] === 2, 'override decision increments the draft lock version exactly once');
$assert(count($evidence->decisions) === 1, 'override decision is retained as immutable evidence');
$assert(
    ($requests->requests[$draft['request_id']]['staffing_override_granted'] ?? 0) === 1,
    'compatibility grant is written only alongside immutable decision evidence'
);
$assert(
    $overrideService->decide($decisionCommand)['replayed'] === true,
    'same idempotency key replays the immutable override decision without a second write'
);
$submitted = $requestService->submit([
    'actor_id' => 55,
    'request_id' => $draft['request_id'],
    'expected_lock_version' => 2,
    'submission_idempotency_key' => 'staffing-override-submit-1',
]);
$assert($submitted['status'] === 'pending_approval', 'matching immutable manager evidence permits normal leave workflow submission');
$assert(count($ledger->movements) === 1, 'only normal submission reserves the balance after a staffing override');

$rejectedDraft = $requestService->createDraft([
    'actor_id' => 55,
    'staff_user_id' => 55,
    'leave_type_id' => 3,
    'from_at' => '2026-09-11 08:00:00',
    'to_at' => '2026-09-11 16:00:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'annual leave',
    'reason_code' => 'annual',
    'create_idempotency_key' => 'staffing-override-draft-rejected',
]);
$rejected = $overrideService->decide([
    'actor_id' => 900,
    'request_id' => $rejectedDraft['request_id'],
    'expected_lock_version' => 1,
    'decision_outcome' => 'rejected',
    'decision_idempotency_key' => 'staffing-override-decision-rejected',
    'reason' => 'Coverage cannot be reduced',
]);
$assert($rejected['granted'] === false, 'rejection is recorded without a compatibility grant');
$assert(
    array_key_exists('staffing_override_reason', $requests->requests[$rejectedDraft['request_id']])
        && $requests->requests[$rejectedDraft['request_id']]['staffing_override_reason'] === null,
    'rejected compatibility state never stores the sensitive decision reason on the request row'
);
$assertThrows(
    static fn (): array => $requestService->submit([
        'actor_id' => 55,
        'request_id' => $rejectedDraft['request_id'],
        'expected_lock_version' => 2,
        'submission_idempotency_key' => 'staffing-override-submit-rejected',
    ]),
    'LEAVE_STAFFING_OVERRIDE_REQUIRED',
    'rejected staffing override cannot create a balance reservation or workflow'
);
$assert(count($ledger->movements) === 1, 'rejected override leaves the balance untouched');

$staleDraft = $requestService->createDraft([
    'actor_id' => 55,
    'staff_user_id' => 55,
    'leave_type_id' => 3,
    'from_at' => '2026-09-12 08:00:00',
    'to_at' => '2026-09-12 16:00:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'annual leave',
    'reason_code' => 'annual',
    'create_idempotency_key' => 'staffing-override-draft-stale',
]);
$overrideService->decide([
    'actor_id' => 900,
    'request_id' => $staleDraft['request_id'],
    'expected_lock_version' => 1,
    'decision_outcome' => 'approved',
    'decision_idempotency_key' => 'staffing-override-decision-stale',
    'reason' => 'Coverage exception for original interval',
]);
$updated = $requestService->updateDraft([
    'actor_id' => 55,
    'request_id' => $staleDraft['request_id'],
    'expected_lock_version' => 2,
    'from_at' => '2026-09-13 08:00:00',
    'to_at' => '2026-09-13 16:00:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'annual leave',
    'reason_code' => 'annual',
]);
$assert($updated['lock_version'] === 3, 'editing a draft after a decision changes its immutable request hash');
$assert(
    ($requests->requests[$staleDraft['request_id']]['staffing_override_granted'] ?? 1) === 0,
    'draft edit clears the compatibility grant so it cannot mislead legacy readers'
);
$assertThrows(
    static fn (): array => $requestService->submit([
        'actor_id' => 55,
        'request_id' => $staleDraft['request_id'],
        'expected_lock_version' => 3,
        'submission_idempotency_key' => 'staffing-override-submit-stale',
    ]),
    'LEAVE_STAFFING_OVERRIDE_REQUIRED',
    'an override tied to the original request hash cannot authorize a modified draft'
);
$assert(count($ledger->movements) === 1, 'stale override evidence cannot reserve an additional balance');

$missingReasonDraft = $requestService->createDraft([
    'actor_id' => 55,
    'staff_user_id' => 55,
    'leave_type_id' => 3,
    'from_at' => '2026-09-14 08:00:00',
    'to_at' => '2026-09-14 16:00:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'annual leave',
    'reason_code' => 'annual',
    'create_idempotency_key' => 'staffing-override-draft-no-reason',
]);
$decisionCountBeforeReasonFailure = count($evidence->decisions);
$assertThrows(
    static fn (): array => $overrideService->decide([
        'actor_id' => 900,
        'request_id' => $missingReasonDraft['request_id'],
        'expected_lock_version' => 1,
        'decision_outcome' => 'approved',
        'decision_idempotency_key' => 'staffing-override-decision-no-reason',
        'reason' => ' ',
    ]),
    'LEAVE_STAFFING_OVERRIDE_REASON_REQUIRED',
    'every staffing override decision requires a non-empty recorded reason'
);
$assert(count($evidence->decisions) === $decisionCountBeforeReasonFailure, 'missing reason creates no override evidence');

$unauthorizedDraft = $requestService->createDraft([
    'actor_id' => 55,
    'staff_user_id' => 55,
    'leave_type_id' => 3,
    'from_at' => '2026-09-15 08:00:00',
    'to_at' => '2026-09-15 16:00:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'annual leave',
    'reason_code' => 'annual',
    'create_idempotency_key' => 'staffing-override-draft-unauthorized',
]);
$assertThrows(
    static fn (): array => $overrideService->decide([
        'actor_id' => 901,
        'request_id' => $unauthorizedDraft['request_id'],
        'expected_lock_version' => 1,
        'decision_outcome' => 'approved',
        'decision_idempotency_key' => 'staffing-override-decision-unauthorized',
        'reason' => 'Attempt without designated role',
    ]),
    'LEAVE_STAFFING_OVERRIDE_ACTOR_UNAUTHORIZED',
    'an actor without the policy-designated role cannot grant an exception'
);

$policies->requiresOverride = false;
$policies->overrideRole = null;
$hardRuleDraft = $requestService->createDraft([
    'actor_id' => 55,
    'staff_user_id' => 55,
    'leave_type_id' => 3,
    'from_at' => '2026-09-16 08:00:00',
    'to_at' => '2026-09-16 16:00:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'annual leave',
    'reason_code' => 'annual',
    'create_idempotency_key' => 'staffing-override-draft-hard-rule',
]);
$assertThrows(
    static fn (): array => $overrideService->decide([
        'actor_id' => 900,
        'request_id' => $hardRuleDraft['request_id'],
        'expected_lock_version' => 1,
        'decision_outcome' => 'approved',
        'decision_idempotency_key' => 'staffing-override-decision-hard-rule',
        'reason' => 'Cannot override a hard policy limit',
    ]),
    'LEAVE_STAFFING_MINIMUM_BREACHED',
    'manager workflow cannot convert a hard staffing limit into an override'
);

if ($failures > 0) {
    fwrite(STDERR, $failures . " leave staffing override test failure(s)." . PHP_EOL);
    exit(1);
}

echo "Staff-HR leave staffing override tests passed." . PHP_EOL;
