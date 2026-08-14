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
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingPolicy;
use EduCore\Modules\Staff\Application\Policy\EffectivePolicyResolver;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowResolutionGateway;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerGateway;
use EduCore\Modules\Staff\Contracts\LeavePolicyReadRepository;
use EduCore\Modules\Staff\Contracts\LeaveRequestAuthorization;
use EduCore\Modules\Staff\Contracts\LeaveRequestClock;
use EduCore\Modules\Staff\Contracts\LeaveRequestOverlapQuery;
use EduCore\Modules\Staff\Contracts\LeaveRequestRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingReadRepository;
use EduCore\Modules\Staff\Contracts\LeaveAttachmentVerificationQuery;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffEmploymentQuery;

final class LeaveRequestTestRepository implements LeaveRequestRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $requests = [];
    /** @var array<int,list<array<string,mixed>>> */
    public array $days = [];
    private int $nextRequestId = 1;
    private int $nextDayId = 1;

    public function transactional(callable $work): mixed
    {
        $requests = $this->requests;
        $days = $this->days;
        $nextRequestId = $this->nextRequestId;
        $nextDayId = $this->nextDayId;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->requests = $requests;
            $this->days = $days;
            $this->nextRequestId = $nextRequestId;
            $this->nextDayId = $nextDayId;
            throw $exception;
        }
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
        unset($request['actor_id'], $request['request_days']);
        $this->requests[$id] = $request + [
            'id' => $id,
            'status' => 'draft',
            'lock_version' => 1,
            'policy_version_id' => null,
            'workflow_version_id' => null,
            'workflow_instance_id' => null,
            'assignment_id' => null,
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
        foreach ([
            'from_at', 'to_at', 'timezone', 'requested_units', 'requested_minutes',
            'reason', 'reason_code', 'supporting_document_ref', 'request_hash',
        ] as $field) {
            $request[$field] = $changes[$field];
        }
        $request['lock_version'] = $expectedLockVersion + 1;
        $this->requests[$requestId] = $request;

        return true;
    }

    public function replaceDraftDays(int $requestId, array $days): array
    {
        $stored = [];
        foreach ($days as $day) {
            $storedDay = $day + ['id' => $this->nextDayId++, 'request_id' => $requestId];
            $stored[] = $storedDay;
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
        foreach ($submission as $field => $value) {
            $request[$field] = $value;
        }
        $request['status'] = 'pending_approval';
        $request['lock_version'] = $expectedLockVersion + 1;
        $this->requests[$requestId] = $request;

        return true;
    }

    public function attachWorkflowInstance(int $requestId, int $expectedLockVersion, int $workflowInstanceId): bool
    {
        $request = $this->requests[$requestId] ?? null;
        if (!is_array($request)
            || ($request['status'] ?? null) !== 'pending_approval'
            || ($request['workflow_instance_id'] ?? null) !== null
            || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $request['workflow_instance_id'] = $workflowInstanceId;
        $request['lock_version'] = $expectedLockVersion + 1;
        $this->requests[$requestId] = $request;

        return true;
    }

    public function withdrawDraft(int $requestId, int $expectedLockVersion): bool
    {
        $request = $this->requests[$requestId] ?? null;
        if (!is_array($request)
            || ($request['status'] ?? null) !== 'draft'
            || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $request['status'] = 'withdrawn';
        $request['lock_version'] = $expectedLockVersion + 1;
        $this->requests[$requestId] = $request;

        return true;
    }

    public function finalizeWorkflowOutcome(
        int $requestId,
        int $expectedLockVersion,
        string $outcome,
        DateTimeImmutable $decidedAt
    ): bool {
        $request = $this->requests[$requestId] ?? null;
        if (!is_array($request)
            || ($request['status'] ?? null) !== 'pending_approval'
            || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $request['status'] = $outcome;
        $request['decided_at'] = $decidedAt->format('Y-m-d H:i:s.u');
        $request['lock_version'] = $expectedLockVersion + 1;
        $this->requests[$requestId] = $request;

        return true;
    }

    /** @param array<string,mixed> $request */
    public function seedApproved(int $id, array $request): void
    {
        $this->requests[$id] = $request + [
            'id' => $id,
            'status' => 'approved',
            'lock_version' => 3,
            'policy_version_id' => 77,
            'workflow_version_id' => 99,
            'workflow_instance_id' => 700 + $id,
            'assignment_id' => 44,
        ];
        $this->nextRequestId = max($this->nextRequestId, $id + 1);
    }
}

final class LeaveRequestTestOverlaps implements LeaveRequestOverlapQuery
{
    /** @var list<array<string,mixed>> */
    public array $conflicts = [];

    public function conflictsForStaffForUpdate(
        int $staffUserId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        ?int $excludingRequestId = null
    ): array {
        return $this->conflicts;
    }
}

final class LeaveRequestTestStaffingRepository implements LeaveStaffingReadRepository
{
    /** @var list<array<string,mixed>> */
    public array $blackouts = [];
    /** @var array{staff_ids:list<int>,absent_staff_ids:list<int>,conflicting_staff_ids:list<int>} */
    public array $availability = [
        'staff_ids' => [55, 56, 57],
        'absent_staff_ids' => [],
        'conflicting_staff_ids' => [],
    ];
    /** @var list<array<string,mixed>> */
    public array $blackoutAssignments = [];
    public int $availabilityQueries = 0;

    public function blackoutsFor(
        int $policyVersionId,
        array $assignment,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt
    ): array {
        $this->blackoutAssignments[] = $assignment;

        return $this->blackouts;
    }

    public function availabilityForScopeForUpdate(
        string $scopeType,
        int $scopeId,
        DateTimeImmutable $workDate,
        ?int $excludingRequestId = null
    ): array {
        ++$this->availabilityQueries;

        return $this->availability;
    }
}

final class LeaveRequestTestAttachments implements LeaveAttachmentVerificationQuery
{
    /** @var array<int,array<string,mixed>> */
    public array $current = [];

    public function currentAttachmentForRequestForUpdate(int $requestId): ?array
    {
        return $this->current[$requestId] ?? null;
    }
}

final class LeaveRequestTestAuthorization implements LeaveRequestAuthorization
{
    /** @var list<string> */
    public array $actions = [];

    public function assertCanAct(int $actorId, int $staffUserId, string $action, DateTimeImmutable $atInstant): void
    {
        if ($actorId !== $staffUserId || $staffUserId !== 55) {
            throw new DomainException('LEAVE_REQUEST_ACCESS_DENIED');
        }
        $this->actions[] = $action;
    }
}

final class LeaveRequestTestWorkflowResolver implements ApprovalWorkflowResolutionGateway
{
    /** @var list<array<string,mixed>> */
    public array $contexts = [];

    public function resolveForResource(
        string $resourceType,
        int $staffUserId,
        array $context,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $resolvedAt
    ): array {
        if ($resourceType !== 'leave_request' || $staffUserId !== 55) {
            throw new DomainException('LEAVE_REQUEST_WORKFLOW_CONTEXT_INVALID');
        }
        $this->contexts[] = $context;

        return [
            'workflow_version_id' => 99,
            'snapshot' => [
                'workflow_id' => 14,
                'stages' => [[
                    'stage_id' => 5,
                    'sequence_no' => 1,
                    'decision_mode' => 'sequential',
                    'assignees' => [[
                        'user_id' => 88,
                        'relationship_kind' => 'direct_manager',
                        'assignment_snapshot' => ['manager_at_submission' => true],
                    ]],
                ]],
            ],
        ];
    }
}

final class LeaveRequestTestApprovalGateway implements ApprovalWorkflowSubmissionGateway
{
    /** @var list<array<string,mixed>> */
    public array $submissions = [];

    public function submit(array $command): array
    {
        $this->submissions[] = $command;

        return ['instance_id' => 700 + count($this->submissions)];
    }
}

final class LeaveRequestTestLedger implements LeaveBalanceLedgerGateway
{
    /** @var list<array<string,mixed>> */
    public array $movements = [];

    public function record(array $command): array
    {
        $this->movements[] = $command;

        return ['replayed' => false, 'movement_id' => count($this->movements)];
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

final class LeaveRequestTestAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public bool $fail = false;

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->fail) {
            throw new DomainException('LEAVE_REQUEST_AUDIT_FAILURE');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

final class LeaveRequestTestClock implements LeaveRequestClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-01 09:00:00', new DateTimeZone('Africa/Cairo'));
    }
}

final class LeaveRequestTestPolicyRepository implements LeavePolicyReadRepository
{
    public ?int $minimumAvailableStaff = null;
    public ?string $maximumAbsencePercentage = null;
    public bool $requiresStaffingOverride = false;
    public ?string $staffingOverrideRole = null;
    public bool $requiresAttachment = false;
    public bool $requiresMedicalDocument = false;

    public function findType(int $leaveTypeId): ?array
    {
        return $leaveTypeId === 3 ? [
            'id' => 3,
            'code' => 'annual',
            'name' => 'Annual leave',
            'unit' => 'day',
            'allow_partial_unit' => 1,
            'requires_reason' => 0,
            'requires_attachment' => $this->requiresAttachment ? 1 : 0,
            'requires_medical_document' => $this->requiresMedicalDocument ? 1 : 0,
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
            'requires_attachment' => $this->requiresAttachment ? 1 : 0,
            'requires_medical_document' => $this->requiresMedicalDocument ? 1 : 0,
            'payroll_effect_code' => null,
            'minimum_available_staff' => $this->minimumAvailableStaff,
            'max_absence_percentage' => $this->maximumAbsencePercentage,
            'requires_staffing_override' => $this->requiresStaffingOverride ? 1 : 0,
            'override_role_key' => $this->staffingOverrideRole,
            'scope_type' => 'global',
            'scope_id' => 0,
            'scope_priority' => 0,
            'scope_valid_from' => '2025-01-01 00:00:00.000000',
            'scope_valid_to' => null,
            'scope_status' => 'active',
        ]];
    }
}

final class LeaveRequestTestAssignment implements StaffAssignmentAtDateQuery
{
    public string $employmentStatus = 'active';

    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
    {
        return $staffId === 55 ? [
            'assignment_id' => 44,
            'org_unit_id' => 12,
            'job_title_id' => 8,
            'group_ids' => [5],
            'employment_status' => $this->employmentStatus,
        ] : null;
    }
}

final class LeaveRequestTestEmployment implements StaffEmploymentQuery
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

final class LeaveRequestTestCalendar implements LeaveWorkdayCalendarQuery
{
    public function daysIntersecting(
        int $staffId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        DateTimeZone $requestTimezone
    ): array {
        $days = [];
        $cursor = $fromAt->setTimezone($requestTimezone)->setTime(0, 0, 0, 0);
        $last = $toAt->setTimezone($requestTimezone)->modify('-1 microsecond')->setTime(0, 0, 0, 0);
        while ($cursor <= $last) {
            $start = $cursor->setTime(8, 0);
            $end = $cursor->setTime(16, 0);
            $days[] = [
                'status' => 'working',
                'reason_code' => 'EFFECTIVE_SCHEDULE_RESOLVED',
                'staff_id' => $staffId,
                'work_date' => $cursor->format('Y-m-d'),
                'required_minutes' => 480,
                'working_intervals' => [[
                    'start_at' => $start->format('Y-m-d H:i:s.u'),
                    'end_at' => $end->format('Y-m-d H:i:s.u'),
                    'minutes' => 480,
                ]],
                'schedule_policy_version_id' => 501,
                'calendar_exception_id' => null,
                'conflicts' => [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }
}

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
    } catch (DomainException|InvalidArgumentException $exception) {
        if ($exception->getMessage() === $expectedCode) {
            return;
        }
        throw new RuntimeException($message . ': expected ' . $expectedCode . ', got ' . $exception->getMessage());
    }
    throw new RuntimeException($message . ': no exception');
};

$repository = new LeaveRequestTestRepository();
$overlaps = new LeaveRequestTestOverlaps();
$authorization = new LeaveRequestTestAuthorization();
$workflow = new LeaveRequestTestWorkflowResolver();
$approval = new LeaveRequestTestApprovalGateway();
$ledger = new LeaveRequestTestLedger();
$audit = new LeaveRequestTestAudit();
$policyRepository = new LeaveRequestTestPolicyRepository();
$assignment = new LeaveRequestTestAssignment();
$staffingRepository = new LeaveRequestTestStaffingRepository();
$attachments = new LeaveRequestTestAttachments();
$policyService = new LeavePolicyService(
    $policyRepository,
    $assignment,
    new LeaveRequestTestEmployment(),
    new LeaveRequestTestCalendar(),
    new EffectivePolicyResolver()
);
$service = new LeaveRequestService(
    $repository,
    $overlaps,
    $policyService,
    $ledger,
    $authorization,
    $workflow,
    $audit,
    new LeaveRequestTestClock(),
    $approval,
    new LeaveStaffingPolicy($staffingRepository),
    $attachments
);
$draftCommand = [
    'actor_id' => 55,
    'staff_user_id' => 55,
    'leave_type_id' => 3,
    'from_at' => '2026-09-10 08:00',
    'to_at' => '2026-09-10 16:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'Annual rest',
    'reason_code' => 'annual_rest',
    'create_idempotency_key' => 'leave-create-1',
];

$draft = $service->createDraft($draftCommand);
$assert($draft['status'] === 'draft', 'ordinary leave is initially a draft');
$assert($draft['requested_units'] === '1.000', 'draft allocation is calculated server-side');
$assert(count($repository->days[$draft['request_id']]) === 1, 'draft stores one calculated allocation row');
$assert(
    $service->createDraft($draftCommand)['replayed'] === true,
    'same create idempotency key replays the original draft'
);
$assertThrows(
    static fn (): array => $service->createDraft(array_replace($draftCommand, [
        'supporting_document_ref' => 'private:leave_attachments/forged.pdf',
        'create_idempotency_key' => 'leave-create-forged-document',
    ])),
    'LEAVE_REQUEST_DOCUMENT_MANAGED_SEPARATELY',
    'a browser cannot forge a private medical attachment reference into a leave draft'
);

$updated = $service->updateDraft([
    'actor_id' => 55,
    'request_id' => $draft['request_id'],
    'expected_lock_version' => 1,
    'from_at' => '2026-09-11 08:00',
    'to_at' => '2026-09-11 16:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'Updated rest',
    'reason_code' => 'annual_rest',
    'supporting_document_ref' => null,
]);
$assert($updated['lock_version'] === 2, 'draft update uses optimistic locking');
$assert(
    $repository->requests[$draft['request_id']]['from_at'] === '2026-09-11 08:00:00.000000',
    'draft update replaces the calculated request window before submission'
);

$submitted = $service->submit([
    'actor_id' => 55,
    'request_id' => $draft['request_id'],
    'expected_lock_version' => 2,
    'submission_idempotency_key' => 'leave-submit-1',
]);
$assert($submitted['status'] === 'pending_approval', 'submission enters the shared approval workflow');
$assert($submitted['workflow_instance_id'] === 701, 'submission attaches the durable workflow instance');
$assert(count($ledger->movements) === 1, 'submission reserves the exact entitlement day once');
$assert($ledger->movements[0]['account']['entitlement_period_key'] === 'CY-2026', 'reservation targets the quoted entitlement period');
$snapshot = json_decode((string) $repository->requests[$draft['request_id']]['policy_snapshot'], true, 512, JSON_THROW_ON_ERROR);
$assert($snapshot['policy']['version_id'] === 77, 'submission persists the effective policy snapshot');
$assert($snapshot['workflow']['workflow_version_id'] === 99, 'submission persists the resolved workflow snapshot');
$assert($snapshot['staffing']['status'] === 'clear', 'submission persists the checked staffing snapshot');
$assert(
    ($staffingRepository->blackoutAssignments[0]['staff_user_id'] ?? null) === 55,
    'staff-scoped blackout checks receive the request owner without exposing it in the policy quote'
);
$assert(
    $service->submit([
        'actor_id' => 55,
        'request_id' => $draft['request_id'],
        'expected_lock_version' => $submitted['lock_version'],
        'submission_idempotency_key' => 'leave-submit-1',
    ])['replayed'] === true,
    'same submission idempotency key replays after the status transition'
);

$policyRepository->requiresMedicalDocument = true;
$medicalDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-09-25 08:00',
    'to_at' => '2026-09-25 16:00',
    'create_idempotency_key' => 'leave-create-medical-required',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 55,
        'request_id' => $medicalDraft['request_id'],
        'expected_lock_version' => 1,
        'submission_idempotency_key' => 'leave-submit-medical-missing',
    ]),
    'LEAVE_REQUEST_ATTACHMENT_REQUIRED',
    'a policy-required medical document must exist before leave submission'
);
$medicalReference = 'private:leave_attachments/test_medical_evidence.pdf';
$repository->requests[$medicalDraft['request_id']]['supporting_document_ref'] = $medicalReference;
$attachments->current[$medicalDraft['request_id']] = [
    'attachment_id' => 7001,
    'request_id' => $medicalDraft['request_id'],
    'attachment_kind' => 'medical',
    'storage_ref' => $medicalReference,
    'status' => 'active',
];
$medicalSubmitted = $service->submit([
    'actor_id' => 55,
    'request_id' => $medicalDraft['request_id'],
    'expected_lock_version' => 1,
    'submission_idempotency_key' => 'leave-submit-medical-present',
]);
$assert(
    $medicalSubmitted['status'] === 'pending_approval',
    'only an active private medical attachment matching the draft reference satisfies the policy'
);
$policyRepository->requiresMedicalDocument = false;

$secondDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-10 08:00',
    'to_at' => '2026-10-10 16:00',
    'create_idempotency_key' => 'leave-create-overlap',
]));
$overlaps->conflicts = [[
    'resource_type' => 'permission_request',
    'resource_id' => 77,
    'from_at' => '2026-10-10 08:00:00.000000',
    'to_at' => '2026-10-10 16:00:00.000000',
    'status' => 'approved',
]];
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 55,
        'request_id' => $secondDraft['request_id'],
        'expected_lock_version' => 1,
        'submission_idempotency_key' => 'leave-submit-overlap',
    ]),
    'LEAVE_REQUEST_OVERLAP',
    'a locked leave/permission overlap stops submission before reservation'
);
$overlaps->conflicts = [];

$policyRepository->minimumAvailableStaff = 2;
$staffingRepository->availability = [
    'staff_ids' => [55, 56, 57],
    'absent_staff_ids' => [56],
    'conflicting_staff_ids' => [],
];
$staffingMinimumDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-11 08:00',
    'to_at' => '2026-10-11 16:00',
    'create_idempotency_key' => 'leave-create-staffing-minimum',
]));
$ledgerCountBeforeStaffingFailure = count($ledger->movements);
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 55,
        'request_id' => $staffingMinimumDraft['request_id'],
        'expected_lock_version' => 1,
        'submission_idempotency_key' => 'leave-submit-staffing-minimum',
    ]),
    'LEAVE_STAFFING_MINIMUM_BREACHED',
    'a leave that would breach the minimum operational staffing cannot reserve leave'
);
$assert(
    count($ledger->movements) === $ledgerCountBeforeStaffingFailure,
    'a failed staffing check leaves the balance ledger untouched'
);
$assert($staffingRepository->availabilityQueries > 0, 'staffing rules use a locked scope availability query');

$policyRepository->requiresStaffingOverride = true;
$policyRepository->staffingOverrideRole = 'hr_staffing_override';
$staffingOverrideDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-12 08:00',
    'to_at' => '2026-10-12 16:00',
    'create_idempotency_key' => 'leave-create-staffing-override',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 55,
        'request_id' => $staffingOverrideDraft['request_id'],
        'expected_lock_version' => 1,
        'submission_idempotency_key' => 'leave-submit-staffing-override',
    ]),
    'LEAVE_STAFFING_OVERRIDE_REQUIRED',
    'a breached capacity rule requiring an override fails closed for the worker'
);
$policyRepository->minimumAvailableStaff = null;
$policyRepository->requiresStaffingOverride = false;
$policyRepository->staffingOverrideRole = null;
$staffingRepository->availability = [
    'staff_ids' => [55, 56, 57],
    'absent_staff_ids' => [],
    'conflicting_staff_ids' => [],
];

$staffingRepository->blackouts = [['id' => 901, 'requires_override' => false]];
$blackoutDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-13 08:00',
    'to_at' => '2026-10-13 16:00',
    'create_idempotency_key' => 'leave-create-blackout',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 55,
        'request_id' => $blackoutDraft['request_id'],
        'expected_lock_version' => 1,
        'submission_idempotency_key' => 'leave-submit-blackout',
    ]),
    'LEAVE_REQUEST_BLACKOUT',
    'an ordinary blackout blocks leave submission before a balance reservation'
);
$staffingRepository->blackouts = [['id' => 902, 'requires_override' => true]];
$blackoutOverrideDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-14 08:00',
    'to_at' => '2026-10-14 16:00',
    'create_idempotency_key' => 'leave-create-blackout-override',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 55,
        'request_id' => $blackoutOverrideDraft['request_id'],
        'expected_lock_version' => 1,
        'submission_idempotency_key' => 'leave-submit-blackout-override',
    ]),
    'LEAVE_BLACKOUT_OVERRIDE_REQUIRED',
    'an override-only blackout remains blocked until an authorized exception flow exists'
);
$staffingRepository->blackouts = [];

$policyRepository->maximumAbsencePercentage = '33.33';
$fractionalLimitDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-15 08:00',
    'to_at' => '2026-10-15 16:00',
    'create_idempotency_key' => 'leave-create-fractional-capacity',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 55,
        'request_id' => $fractionalLimitDraft['request_id'],
        'expected_lock_version' => 1,
        'submission_idempotency_key' => 'leave-submit-fractional-capacity',
    ]),
    'LEAVE_STAFFING_ABSENCE_LIMIT_BREACHED',
    'fractional absence percentages round up so capacity cannot pass by integer truncation'
);
$policyRepository->maximumAbsencePercentage = null;

$serviceEndDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-16 08:00',
    'to_at' => '2026-10-16 16:00',
    'create_idempotency_key' => 'leave-create-service-ended',
]));
$assignment->employmentStatus = 'ended';
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 55,
        'request_id' => $serviceEndDraft['request_id'],
        'expected_lock_version' => 1,
        'submission_idempotency_key' => 'leave-submit-service-ended',
    ]),
    'LEAVE_STAFF_NOT_ACTIVE',
    'a service-status change between draft and submission prevents a stale leave from being reserved'
);
$assignment->employmentStatus = 'active';

$repository->seedApproved(900, [
    'staff_user_id' => 55,
    'leave_type_id' => 3,
    'request_kind' => 'leave',
    'parent_request_id' => null,
    'supersedes_id' => null,
    'from_at' => '2026-09-20 08:00:00.000000',
    'to_at' => '2026-09-21 16:00:00.000000',
    'timezone' => 'Africa/Cairo',
    'requested_units' => '2.000',
    'requested_minutes' => 960,
    'reason' => null,
    'reason_code' => null,
    'supporting_document_ref' => null,
    'create_idempotency_key' => 'seed-parent-extension',
    'request_hash' => str_repeat('a', 64),
]);
$extension = $service->createExtensionDraft([
    'actor_id' => 55,
    'parent_request_id' => 900,
    'from_at' => '2026-09-21 16:00',
    'to_at' => '2026-09-22 16:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'Extension',
    'create_idempotency_key' => 'leave-extension-1',
]);
$assert($extension['request_kind'] === 'extension' && $extension['parent_request_id'] === 900, 'extension is a child record, not a mutation of the approved leave');
$assertThrows(
    static fn (): array => $service->createExtensionDraft([
        'actor_id' => 55,
        'parent_request_id' => 900,
        'from_at' => '2026-09-21 15:59',
        'to_at' => '2026-09-22 16:00',
        'timezone' => 'Africa/Cairo',
        'create_idempotency_key' => 'leave-extension-invalid',
    ]),
    'LEAVE_REQUEST_EXTENSION_MUST_BE_CONTIGUOUS',
    'extension cannot silently overlap or rewrite the approved parent'
);

$repository->seedApproved(901, [
    'staff_user_id' => 55,
    'leave_type_id' => 3,
    'request_kind' => 'leave',
    'parent_request_id' => null,
    'supersedes_id' => null,
    'from_at' => '2026-11-01 08:00:00.000000',
    'to_at' => '2026-11-03 16:00:00.000000',
    'timezone' => 'Africa/Cairo',
    'requested_units' => '3.000',
    'requested_minutes' => 1440,
    'reason' => null,
    'reason_code' => null,
    'supporting_document_ref' => null,
    'create_idempotency_key' => 'seed-parent-return',
    'request_hash' => str_repeat('b', 64),
]);
$earlyReturn = $service->createEarlyReturnDraft([
    'actor_id' => 55,
    'parent_request_id' => 901,
    'from_at' => '2026-11-02 08:00',
    'to_at' => '2026-11-03 16:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'Returning early',
    'create_idempotency_key' => 'leave-return-1',
]);
$assert($earlyReturn['request_kind'] === 'early_return', 'early return is recorded as an explicit successor');
$cancellation = $service->requestCancellation([
    'actor_id' => 55,
    'parent_request_id' => 901,
    'from_at' => '2026-11-01 08:00',
    'to_at' => '2026-11-03 16:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'Cancellation requested',
    'create_idempotency_key' => 'leave-cancel-1',
]);
$assert($cancellation['request_kind'] === 'cancellation', 'approved cancellation becomes a separately auditable child request');
$balanceMovementsBeforeCancellationSubmission = count($ledger->movements);
$submittedCancellation = $service->submit([
    'actor_id' => 55,
    'request_id' => $cancellation['request_id'],
    'expected_lock_version' => 1,
    'submission_idempotency_key' => 'leave-cancel-submit-1',
]);
$assert($submittedCancellation['status'] === 'pending_approval', 'cancellation successor still enters the shared final approval workflow');
$assert(
    count($ledger->movements) === $balanceMovementsBeforeCancellationSubmission,
    'early-return and cancellation successors do not create a second balance reservation before their final reversal decision'
);

$withdrawn = $service->withdrawDraft(55, $earlyReturn['request_id'], 1);
$assert($withdrawn['status'] === 'withdrawn', 'only a draft successor can be withdrawn directly');
$assertThrows(
    static fn (): array => $service->createDraft(array_replace($draftCommand, [
        'actor_id' => 56,
        'create_idempotency_key' => 'leave-id-or',
    ])),
    'LEAVE_REQUEST_OWNER_ONLY',
    'browser-supplied worker identity cannot create another worker leave'
);

$beforeFailure = count($repository->requests);
$audit->fail = true;
$assertThrows(
    static fn (): array => $service->createDraft(array_replace($draftCommand, [
        'from_at' => '2026-12-10 08:00',
        'to_at' => '2026-12-10 16:00',
        'create_idempotency_key' => 'leave-audit-rollback',
    ])),
    'LEAVE_REQUEST_AUDIT_FAILURE',
    'audit failure rolls back the business draft'
);
$audit->fail = false;
$assert(count($repository->requests) === $beforeFailure, 'failed audit leaves no partial leave draft');

echo 'staff_hr_leave_request_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
