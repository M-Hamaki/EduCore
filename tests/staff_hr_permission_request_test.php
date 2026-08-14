<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Permission\PermissionPolicyResolver;
use EduCore\Modules\Staff\Application\Permission\PermissionRequestService;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\PermissionPolicyReadRepository;
use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerGateway;
use EduCore\Modules\Staff\Contracts\PermissionRequestAuthorization;
use EduCore\Modules\Staff\Contracts\PermissionRequestClock;
use EduCore\Modules\Staff\Contracts\PermissionRequestOverlapQuery;
use EduCore\Modules\Staff\Contracts\PermissionRequestRepository;
use EduCore\Modules\Staff\Contracts\PermissionSubmissionWorkflowResolver;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;

final class PermissionRequestFixtureState
{
    /** @var array<int,array<string,mixed>> */
    public array $requests = [];

    /** @var array<int,list<array<string,mixed>>> */
    public array $periods = [];

    /** @var list<array<string,mixed>> */
    public array $movements = [];

    /** @var list<array<string,mixed>> */
    public array $audits = [];

    /** @var list<array<string,mixed>> */
    public array $approvalSubmissions = [];

    /** @var array<int,bool> */
    public array $staff = [10 => true];

    public int $nextRequestId = 1;
    public int $nextPeriodId = 100;
    public int $nextApprovalInstanceId = 7000;
}

final class PermissionRequestFixtureRepository implements PermissionRequestRepository
{
    public function __construct(private PermissionRequestFixtureState $state)
    {
    }

    public function transactional(callable $work): mixed
    {
        $backup = [
            'requests' => $this->state->requests,
            'periods' => $this->state->periods,
            'movements' => $this->state->movements,
            'audits' => $this->state->audits,
            'approvalSubmissions' => $this->state->approvalSubmissions,
            'nextRequestId' => $this->state->nextRequestId,
            'nextPeriodId' => $this->state->nextPeriodId,
            'nextApprovalInstanceId' => $this->state->nextApprovalInstanceId,
        ];
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->state->requests = $backup['requests'];
            $this->state->periods = $backup['periods'];
            $this->state->movements = $backup['movements'];
            $this->state->audits = $backup['audits'];
            $this->state->approvalSubmissions = $backup['approvalSubmissions'];
            $this->state->nextRequestId = $backup['nextRequestId'];
            $this->state->nextPeriodId = $backup['nextPeriodId'];
            $this->state->nextApprovalInstanceId = $backup['nextApprovalInstanceId'];
            throw $exception;
        }
    }

    public function lockStaffForRequest(int $staffUserId): bool
    {
        return $this->state->staff[$staffUserId] ?? false;
    }

    public function requestByCreateIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->state->requests as $request) {
            if (($request['create_idempotency_key'] ?? null) === $idempotencyKey) {
                return $request;
            }
        }

        return null;
    }

    public function requestBySubmissionIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->state->requests as $request) {
            if (($request['submission_idempotency_key'] ?? null) === $idempotencyKey) {
                return $request;
            }
        }

        return null;
    }

    public function requestForUpdate(int $requestId): ?array
    {
        return $this->state->requests[$requestId] ?? null;
    }

    public function insertDraft(array $request): int
    {
        $id = $this->state->nextRequestId++;
        $this->state->requests[$id] = $request + [
            'id' => $id,
            'status' => 'draft',
            'policy_version_id' => null,
            'policy_snapshot' => null,
            'workflow_version_id' => null,
            'workflow_instance_id' => null,
            'assignment_id' => null,
            'quota_exception' => false,
            'quota_exception_reason' => null,
            'submitted_by' => null,
            'submitted_at' => null,
            'submission_idempotency_key' => null,
            'lock_version' => 1,
        ];

        return $id;
    }

    public function updateDraft(int $requestId, int $expectedLockVersion, array $changes): bool
    {
        $request = $this->state->requests[$requestId] ?? null;
        if ($request === null || $request['status'] !== 'draft' || $request['lock_version'] !== $expectedLockVersion) {
            return false;
        }
        $this->state->requests[$requestId] = array_replace($request, $changes, [
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function replaceDraftPeriods(int $requestId, array $periods): array
    {
        $request = $this->state->requests[$requestId] ?? null;
        if ($request === null || $request['status'] !== 'draft') {
            throw new RuntimeException('fixture periods require a draft');
        }
        $stored = [];
        foreach ($periods as $period) {
            $stored[] = $period + [
                'id' => $this->state->nextPeriodId++,
                'request_id' => $requestId,
            ];
        }
        $this->state->periods[$requestId] = $stored;

        return $stored;
    }

    public function periodsForRequestForUpdate(int $requestId): array
    {
        return $this->state->periods[$requestId] ?? [];
    }

    public function submitDraft(int $requestId, int $expectedLockVersion, array $submission): bool
    {
        $request = $this->state->requests[$requestId] ?? null;
        if ($request === null || $request['status'] !== 'draft' || $request['lock_version'] !== $expectedLockVersion) {
            return false;
        }
        $this->state->requests[$requestId] = array_replace($request, $submission, [
            'status' => 'pending_approval',
            'quota_exception' => false,
            'quota_exception_reason' => null,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function attachWorkflowInstance(int $requestId, int $expectedLockVersion, int $workflowInstanceId): bool
    {
        $request = $this->state->requests[$requestId] ?? null;
        if ($request === null
            || $request['status'] !== 'pending_approval'
            || $request['lock_version'] !== $expectedLockVersion
            || $request['workflow_instance_id'] !== null) {
            return false;
        }
        $this->state->requests[$requestId] = array_replace($request, [
            'workflow_instance_id' => $workflowInstanceId,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function finalizeWorkflowOutcome(
        int $requestId,
        int $expectedLockVersion,
        string $outcome,
        DateTimeImmutable $decidedAt
    ): bool {
        $request = $this->state->requests[$requestId] ?? null;
        if ($request === null
            || !in_array($outcome, ['approved', 'rejected'], true)
            || $request['status'] !== 'pending_approval'
            || $request['lock_version'] !== $expectedLockVersion
            || $request['workflow_instance_id'] === null) {
            return false;
        }
        $this->state->requests[$requestId] = array_replace($request, [
            'status' => $outcome,
            'decided_at' => $decidedAt->format('Y-m-d H:i:s.u'),
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function markQuotaException(int $requestId, int $expectedLockVersion, string $reason): bool
    {
        $request = $this->state->requests[$requestId] ?? null;
        if ($request === null
            || $request['status'] !== 'pending_approval'
            || $request['lock_version'] !== $expectedLockVersion
            || $request['quota_exception']) {
            return false;
        }
        $this->state->requests[$requestId] = array_replace($request, [
            'quota_exception' => true,
            'quota_exception_reason' => $reason,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function withdrawDraft(int $requestId, int $expectedLockVersion): bool
    {
        $request = $this->state->requests[$requestId] ?? null;
        if ($request === null || $request['status'] !== 'draft' || $request['lock_version'] !== $expectedLockVersion) {
            return false;
        }
        $this->state->requests[$requestId] = array_replace($request, [
            'status' => 'withdrawn',
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function cancelPendingRequest(int $requestId, int $expectedLockVersion): bool
    {
        $request = $this->state->requests[$requestId] ?? null;
        if ($request === null || $request['status'] !== 'pending_approval' || $request['lock_version'] !== $expectedLockVersion) {
            return false;
        }
        $this->state->requests[$requestId] = array_replace($request, [
            'status' => 'cancelled',
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }
}

final class PermissionRequestFixtureOverlapQuery implements PermissionRequestOverlapQuery
{
    /** @var list<array{resource_type:string,resource_id:int,from_at:string,to_at:string,status:string}> */
    public array $conflicts = [];

    public int $calls = 0;

    public function conflictsForStaffForUpdate(
        int $staffUserId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        ?int $excludingRequestId = null
    ): array {
        ++$this->calls;

        return $this->conflicts;
    }
}

final class PermissionRequestFixturePolicyRepository implements PermissionPolicyReadRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $types = [];

    /** @var list<array<string,mixed>> */
    public array $candidates = [];

    public function findType(int $permissionTypeId): ?array
    {
        return $this->types[$permissionTypeId] ?? null;
    }

    public function candidateVersionsFor(
        int $permissionTypeId,
        int $staffId,
        array $assignment,
        DateTimeImmutable $effectiveAt
    ): array {
        return $this->candidates;
    }
}

final class PermissionRequestFixtureAssignments implements StaffAssignmentAtDateQuery
{
    /** @var array<string,mixed>|null */
    public ?array $assignment;

    public function __construct(?array $assignment)
    {
        $this->assignment = $assignment;
    }

    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
    {
        return $this->assignment;
    }
}

final class PermissionRequestFixtureAuthorization implements PermissionRequestAuthorization
{
    public bool $allowRetroactive = true;
    public bool $allowOverride = false;
    public int $retroactiveCalls = 0;

    public function assertCanAct(int $actorId, int $staffUserId, string $action, DateTimeImmutable $atInstant): void
    {
        if ($actorId !== $staffUserId) {
            throw new DomainException('fixture owner mismatch');
        }
    }

    public function assertCanSubmitRetroactive(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $atInstant
    ): void {
        ++$this->retroactiveCalls;
        if (!$this->allowRetroactive) {
            throw new DomainException('PERMISSION_REQUEST_RETROACTIVE_AUTHORIZATION_REQUIRED');
        }
    }

    public function canOverrideQuota(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        DateTimeImmutable $atInstant
    ): bool {
        return $this->allowOverride;
    }
}

final class PermissionRequestFixtureWorkflowResolver implements PermissionSubmissionWorkflowResolver
{
    public int $versionId = 701;

    public function resolveForSubmission(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        array $request,
        array $policy,
        array $assignment,
        DateTimeImmutable $submittedAt
    ): array {
        return [
            'workflow_version_id' => $this->versionId,
            'snapshot' => [
                'resource_type' => 'staff_permission_request',
                'version_no' => 1,
                'resolved_for_staff_user_id' => $staffUserId,
            ],
        ];
    }
}

final class PermissionRequestFixtureApprovalWorkflow implements ApprovalWorkflowSubmissionGateway
{
    public bool $fail = false;

    public function __construct(private PermissionRequestFixtureState $state)
    {
    }

    public function submit(array $command): array
    {
        if ($this->fail) {
            throw new DomainException('PERMISSION_APPROVAL_SIMULATED_FAILURE');
        }
        $instanceId = $this->state->nextApprovalInstanceId++;
        $this->state->approvalSubmissions[] = $command + ['instance_id' => $instanceId];

        return ['instance_id' => $instanceId, 'replayed' => false];
    }
}

final class PermissionRequestFixtureQuotaLedger implements PermissionQuotaLedgerGateway
{
    public bool $forceException = false;
    public bool $fail = false;
    private int $nextMovementId = 1;

    public function __construct(private PermissionRequestFixtureState $state)
    {
    }

    public function record(array $command): array
    {
        if ($this->fail) {
            throw new DomainException('PERMISSION_QUOTA_SIMULATED_FAILURE');
        }
        $isException = $this->forceException && ($command['movement_type'] ?? null) === 'reserve';
        if ($isException
            && (!(bool) ($command['limits']['override_authorized'] ?? false)
                || ($command['reason_code'] ?? null) !== 'quota_override')) {
            throw new DomainException('PERMISSION_QUOTA_EXCEEDED');
        }
        $movementId = $this->nextMovementId++;
        $this->state->movements[] = $command + ['id' => $movementId, 'quota_exception' => $isException];

        return [
            'movement_id' => $movementId,
            'quota_exception' => $isException,
            'replayed' => false,
        ];
    }
}

final class PermissionRequestFixtureAudit implements AuditEventWriter
{
    public bool $fail = false;

    public function __construct(private PermissionRequestFixtureState $state)
    {
    }

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->fail) {
            throw new RuntimeException('PERMISSION_REQUEST_AUDIT_FAILED');
        }
        $this->state->audits[] = [
            'action' => $action,
            'entity_type' => $entityType,
            'record_id' => $recordId,
            'details' => $details,
            'context' => $context,
        ];
    }
}

final class PermissionRequestFixtureClock implements PermissionRequestClock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
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

$candidate = static function (array $overrides = []): array {
    return array_replace([
        'version_id' => 501,
        'permission_type_id' => 1,
        'version_no' => 1,
        'state' => 'published',
        'valid_from' => '2026-01-01 00:00:00.000000',
        'valid_to' => null,
        'timezone' => 'Africa/Cairo',
        'max_requests_per_month' => 5,
        'max_minutes_per_request' => 180,
        'max_minutes_per_month' => 300,
        'min_notice_minutes' => 30,
        'retroactive_limit_days' => 2,
        'reserve_on_submit' => 1,
        'allow_overlap' => 0,
        'allow_quota_override' => 1,
        'quota_override_max_minutes' => 60,
        'scope_id_record' => 501,
        'scope_type' => 'global',
        'scope_id' => 0,
        'scope_priority' => 0,
        'scope_valid_from' => '2026-01-01 00:00:00.000000',
        'scope_valid_to' => null,
    ], $overrides);
};

$state = new PermissionRequestFixtureState();
$repository = new PermissionRequestFixtureRepository($state);
$overlaps = new PermissionRequestFixtureOverlapQuery();
$policyRepository = new PermissionRequestFixturePolicyRepository();
$policyRepository->types[1] = [
    'id' => 1,
    'code' => 'LATE_ARRIVAL',
    'name' => 'Late arrival',
    'coverage_behavior' => 'late_arrival',
    'requires_reason' => true,
    'requires_custom_label' => false,
    'requires_attachment' => false,
    'allow_retroactive' => true,
    'status' => 'active',
];
$policyRepository->candidates = [$candidate()];
$assignments = new PermissionRequestFixtureAssignments([
    'assignment_id' => 81,
    'org_unit_id' => 11,
    'job_title_id' => 12,
    'group_ids' => [13],
    'employment_status' => 'active',
]);
$authorization = new PermissionRequestFixtureAuthorization();
$workflow = new PermissionRequestFixtureWorkflowResolver();
$approvalWorkflow = new PermissionRequestFixtureApprovalWorkflow($state);
$quota = new PermissionRequestFixtureQuotaLedger($state);
$audit = new PermissionRequestFixtureAudit($state);
$service = new PermissionRequestService(
    $repository,
    $overlaps,
    new PermissionPolicyResolver($policyRepository, $assignments),
    $quota,
    $authorization,
    $workflow,
    $audit,
    new PermissionRequestFixtureClock(new DateTimeImmutable('2026-10-01 08:00:00', new DateTimeZone('Africa/Cairo'))),
    $approvalWorkflow
);

$draftCommand = [
    'actor_id' => 10,
    'staff_user_id' => 10,
    'permission_type_id' => 1,
    'from_at' => '2026-10-10T10:00',
    'to_at' => '2026-10-10T11:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'Medical appointment',
    'create_idempotency_key' => 'permission-create-one',
];
$draft = $service->createDraft($draftCommand);
$assert($draft['status'] === 'draft' && !$draft['replayed'], 'worker can create a self-owned permission draft');
$assert($draft['requested_minutes'] === 60, 'draft duration is derived from the requested local window');
$draftReplay = $service->createDraft($draftCommand);
$assert($draftReplay['replayed'] && $draftReplay['request_id'] === $draft['request_id'], 'same create key and payload replay safely');
$assertThrows(
    static fn (): array => $service->createDraft(array_replace($draftCommand, ['reason' => 'different', 'create_idempotency_key' => 'permission-create-one'])),
    'PERMISSION_REQUEST_CREATE_IDEMPOTENCY_CONFLICT',
    'create idempotency key cannot be reused for a different draft'
);
$updated = $service->updateDraft(array_replace($draftCommand, [
    'request_id' => $draft['request_id'],
    'expected_lock_version' => $draft['lock_version'],
    'to_at' => '2026-10-10T11:30',
]));
$assert($updated['lock_version'] === 2 && $updated['requested_minutes'] === 90, 'draft update uses optimistic locking and recalculates minutes');
$assertThrows(
    static fn (): array => $service->updateDraft(array_replace($draftCommand, [
        'request_id' => $draft['request_id'],
        'expected_lock_version' => 1,
        'to_at' => '2026-10-10T11:45',
    ])),
    'PERMISSION_REQUEST_STALE',
    'stale draft edits cannot overwrite a newer draft'
);
$assertThrows(
    static fn (): array => $service->createDraft(array_replace($draftCommand, [
        'actor_id' => 99,
        'create_idempotency_key' => 'permission-idor',
    ])),
    'PERMISSION_REQUEST_OWNER_ONLY',
    'employee permission service rejects an IDOR-style foreign staff request'
);

$crossMonthDraft = $service->createDraft([
    'actor_id' => 10,
    'staff_user_id' => 10,
    'permission_type_id' => 1,
    'from_at' => '2026-10-31T23:00',
    'to_at' => '2026-11-01T01:00',
    'timezone' => 'Africa/Cairo',
    'reason' => 'Documented mission',
    'create_idempotency_key' => 'permission-cross-month',
]);
$submitted = $service->submit([
    'actor_id' => 10,
    'request_id' => $crossMonthDraft['request_id'],
    'expected_lock_version' => $crossMonthDraft['lock_version'],
    'submission_idempotency_key' => 'permission-submit-cross-month',
]);
$assert(
    $submitted['status'] === 'pending_approval'
    && $submitted['lock_version'] === 3
    && ($submitted['workflow_instance_id'] ?? null) !== null,
    'valid draft is submitted with a frozen snapshot and a durable approval instance'
);
$assert(count($state->approvalSubmissions) === 1, 'permission submission opens one workflow instance inside its transaction');
$assert(count($state->periods[$crossMonthDraft['request_id']]) === 2, 'a cross-month permission is split into exact monthly quota periods');
$assert(
    $state->periods[$crossMonthDraft['request_id']][0]['requested_minutes'] === 60
    && $state->periods[$crossMonthDraft['request_id']][1]['requested_minutes'] === 60,
    'monthly slices preserve the original two-hour duration exactly'
);
$assert(count($state->movements) === 2, 'submission reserves one immutable quota movement for each monthly slice');
$assert(
    $state->requests[$crossMonthDraft['request_id']]['policy_version_id'] === 501
    && $state->requests[$crossMonthDraft['request_id']]['workflow_version_id'] === 701
    && $state->requests[$crossMonthDraft['request_id']]['assignment_id'] === 81,
    'submission freezes policy, workflow-version, and dated-assignment identifiers'
);
$submitReplay = $service->submit([
    'actor_id' => 10,
    'request_id' => $crossMonthDraft['request_id'],
    'expected_lock_version' => 1,
    'submission_idempotency_key' => 'permission-submit-cross-month',
]);
$assert($submitReplay['replayed'], 'same completed submission key returns a safe replay');

$overlaps->conflicts = [[
    'resource_type' => 'staff_leave_request',
    'resource_id' => 333,
    'from_at' => '2026-10-15 10:00:00.000000',
    'to_at' => '2026-10-15 11:00:00.000000',
    'status' => 'approved',
]];
$overlapDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-15T10:00',
    'to_at' => '2026-10-15T11:00',
    'create_idempotency_key' => 'permission-overlap',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 10,
        'request_id' => $overlapDraft['request_id'],
        'expected_lock_version' => $overlapDraft['lock_version'],
        'submission_idempotency_key' => 'permission-submit-overlap',
    ]),
    'PERMISSION_REQUEST_OVERLAP',
    'a policy that forbids overlap rejects permission, leave, or mission conflicts through the narrow query contract'
);
$overlaps->conflicts = [];

$noticeDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-01T08:15',
    'to_at' => '2026-10-01T09:15',
    'create_idempotency_key' => 'permission-notice',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 10,
        'request_id' => $noticeDraft['request_id'],
        'expected_lock_version' => $noticeDraft['lock_version'],
        'submission_idempotency_key' => 'permission-submit-notice',
    ]),
    'PERMISSION_REQUEST_MIN_NOTICE_NOT_MET',
    'minimum notice is enforced from the trusted clock rather than client input'
);

$retroDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-09-20T10:00',
    'to_at' => '2026-09-20T11:00',
    'create_idempotency_key' => 'permission-retro-too-old',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 10,
        'request_id' => $retroDraft['request_id'],
        'expected_lock_version' => $retroDraft['lock_version'],
        'submission_idempotency_key' => 'permission-submit-retro-too-old',
    ]),
    'PERMISSION_REQUEST_RETROACTIVE_LIMIT_EXCEEDED',
    'retroactive submission outside the dated policy window fails closed'
);
$sameDayRetroDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-01T07:00',
    'to_at' => '2026-10-01T08:00',
    'create_idempotency_key' => 'permission-retro-same-day',
]));
$authorization->allowRetroactive = false;
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 10,
        'request_id' => $sameDayRetroDraft['request_id'],
        'expected_lock_version' => $sameDayRetroDraft['lock_version'],
        'submission_idempotency_key' => 'permission-submit-retro-same-day',
    ]),
    'PERMISSION_REQUEST_RETROACTIVE_AUTHORIZATION_REQUIRED',
    'a type flag alone never authorizes retroactive submission'
);
$authorization->allowRetroactive = true;

$policyRepository->types[1]['requires_custom_label'] = true;
$otherDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-20T10:00',
    'to_at' => '2026-10-20T11:00',
    'create_idempotency_key' => 'permission-custom-label',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 10,
        'request_id' => $otherDraft['request_id'],
        'expected_lock_version' => $otherDraft['lock_version'],
        'submission_idempotency_key' => 'permission-submit-custom-label',
    ]),
    'PERMISSION_REQUEST_CUSTOM_LABEL_REQUIRED',
    'custom-other permission types require a stored explanatory label'
);
$policyRepository->types[1]['requires_custom_label'] = false;

$quota->forceException = true;
$authorization->allowOverride = true;
$overrideDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-21T10:00',
    'to_at' => '2026-10-21T11:00',
    'create_idempotency_key' => 'permission-override',
]));
$overrideSubmitted = $service->submit([
    'actor_id' => 10,
    'request_id' => $overrideDraft['request_id'],
    'expected_lock_version' => $overrideDraft['lock_version'],
    'submission_idempotency_key' => 'permission-submit-override',
    'quota_exception_reason' => 'approved emergency exception',
]);
$assert($overrideSubmitted['quota_exception'] && $overrideSubmitted['lock_version'] === 4, 'actual authorized quota overflow is marked once before its workflow instance is attached');
$assert(
    $state->requests[$overrideDraft['request_id']]['quota_exception_reason'] === 'approved emergency exception',
    'the request retains the exception reason while audit policy can redact it'
);
$quota->forceException = false;
$authorization->allowOverride = false;

$movementsBeforeCancellation = count($state->movements);
$cancelled = $service->cancel([
    'actor_id' => 10,
    'request_id' => $crossMonthDraft['request_id'],
    'expected_lock_version' => $submitted['lock_version'],
    'reason' => 'No longer needed',
]);
$assert($cancelled['status'] === 'cancelled', 'an employee can cancel a pending request');
$assert(
    count($state->movements) === $movementsBeforeCancellation + 2,
    'pending cancellation releases every previously reserved monthly slice'
);
$cancelReplay = $service->cancel([
    'actor_id' => 10,
    'request_id' => $crossMonthDraft['request_id'],
    'expected_lock_version' => 999,
    'reason' => 'retry',
]);
$assert($cancelReplay['replayed'], 'completed cancellation retry does not release quota twice');
$state->requests[$overrideDraft['request_id']]['status'] = 'approved';
$assertThrows(
    static fn (): array => $service->cancel([
        'actor_id' => 10,
        'request_id' => $overrideDraft['request_id'],
        'expected_lock_version' => $overrideSubmitted['lock_version'],
        'reason' => 'needs approval workflow',
    ]),
    'PERMISSION_REQUEST_CANCELLATION_WORKFLOW_REQUIRED',
    'approved permission cannot be silently cancelled before its workflow path exists'
);

$withdrawnDraft = $service->createDraft(array_replace($draftCommand, ['create_idempotency_key' => 'permission-withdraw']));
$withdrawn = $service->withdrawDraft(10, $withdrawnDraft['request_id'], $withdrawnDraft['lock_version']);
$assert($withdrawn['status'] === 'withdrawn', 'unsubmitted draft withdrawal retains the record without a quota movement');

$assignments->assignment = null;
$futureAssignmentDraft = $service->createDraft(array_replace($draftCommand, [
    'from_at' => '2026-10-22T10:00',
    'to_at' => '2026-10-22T11:00',
    'create_idempotency_key' => 'permission-future-assignment',
]));
$assertThrows(
    static fn (): array => $service->submit([
        'actor_id' => 10,
        'request_id' => $futureAssignmentDraft['request_id'],
        'expected_lock_version' => $futureAssignmentDraft['lock_version'],
        'submission_idempotency_key' => 'permission-submit-future-assignment',
    ]),
    'STAFF_NOT_ACTIVE',
    'submission fails closed when no dated active assignment exists even if a draft was allowed'
);
$assignments->assignment = [
    'assignment_id' => 81,
    'org_unit_id' => 11,
    'job_title_id' => 12,
    'group_ids' => [13],
    'employment_status' => 'active',
];

$audit->fail = true;
$beforeFailureRequestCount = count($state->requests);
$assertThrows(
    static fn (): array => $service->createDraft(array_replace($draftCommand, [
        'create_idempotency_key' => 'permission-audit-rollback',
    ])),
    'PERMISSION_REQUEST_AUDIT_FAILED',
    'mandatory request audit failure is surfaced'
);
$assert(count($state->requests) === $beforeFailureRequestCount, 'audit failure rolls back the draft write in the shared transaction');

$adapterSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/PdoPermissionRequestRepository.php'
);
$assert(
    str_contains($adapterSource, 'SELECT id FROM users WHERE id = ? FOR UPDATE')
    && str_contains($adapterSource, 'staff_permission_request_periods')
    && str_contains($adapterSource, 'FOR UPDATE'),
    'PDO adapter serializes a worker and locks request/period state before writes'
);
$assert(
    !str_contains($adapterSource, 'staff_leave_requests')
    && !str_contains($adapterSource, 'attachments'),
    'permission adapter does not reach into future leave or private attachment internals'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} permission request service failure(s).\n");
    exit(1);
}

echo "Staff-HR permission request service tests passed.\n";
