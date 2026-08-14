<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\AlternativeAttendanceRecorder;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceAuthorization;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceEventRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Infrastructure\StaffAlternativeAttendanceAuthorization;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffAccessEligibilityQuery;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;

final class AlternativeAttendanceTestTransaction implements AttendanceTransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}

final class AlternativeAttendanceTestRepository implements AlternativeAttendanceEventRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $methods;

    /** @var array<int,array<string,mixed>> */
    public array $events = [];

    private int $nextId = 1;

    /** @param array<int,array<string,mixed>> $methods */
    public function __construct(array $methods)
    {
        $this->methods = $methods;
    }

    public function insertAlternativeEntryMethod(array $method): int
    {
        $id = $this->methods === [] ? 1 : max(array_keys($this->methods)) + 1;
        $this->methods[$id] = ['id' => $id, 'status' => 'active'] + $method;
        return $id;
    }

    public function activeAlternativeEntryMethods(): array { return array_values($this->methods); }
    public function pendingAlternativeEvents(int $limit = 100): array
    {
        return array_values(array_filter($this->events, static fn (array $event): bool => ($event['review_status'] ?? '') === 'pending'));
    }
    public function retireAlternativeEntryMethod(int $methodId): bool
    {
        if (!isset($this->methods[$methodId]) || ($this->methods[$methodId]['status'] ?? '') !== 'active') return false;
        $this->methods[$methodId]['status'] = 'retired';
        return true;
    }

    public function activeAlternativeEntryMethodForUpdate(int $entryMethodId): ?array
    {
        $method = $this->methods[$entryMethodId] ?? null;
        return $method !== null && ($method['status'] ?? null) === 'active' ? $method : null;
    }

    public function alternativeEventByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->events as $event) {
            if (($event['idempotency_key'] ?? null) === $idempotencyKey) {
                $method = $this->methods[(int) $event['entry_method_id']] ?? [];
                return $event + [
                    'allowed_scope' => $method['allowed_scope'] ?? null,
                    'method_type' => $method['method_type'] ?? null,
                ];
            }
        }
        return null;
    }

    public function insertAlternativeEvent(array $event): int
    {
        $id = $this->nextId++;
        $this->events[$id] = ['id' => $id] + $event;
        return $id;
    }

    public function pendingAlternativeEventForReview(int $eventId): ?array
    {
        $event = $this->events[$eventId] ?? null;
        if ($event === null || ($event['review_status'] ?? null) !== 'pending') {
            return null;
        }
        $method = $this->methods[(int) $event['entry_method_id']] ?? [];
        return $event + ['allowed_scope' => $method['allowed_scope'] ?? null];
    }

    public function finalizeAlternativeReview(
        int $eventId,
        string $decision,
        int $reviewerId,
        \DateTimeImmutable $reviewedAt
    ): bool {
        if (!isset($this->events[$eventId]) || $this->events[$eventId]['review_status'] !== 'pending') {
            return false;
        }
        $this->events[$eventId]['review_status'] = $decision;
        $this->events[$eventId]['reviewed_by'] = $reviewerId;
        $this->events[$eventId]['reviewed_at'] = $reviewedAt->format('Y-m-d H:i:s.u');
        return true;
    }
}

final class AlternativeAttendanceTestAssignments implements StaffAssignmentAtDateQuery
{
    public bool $eligible = true;

    public function forStaff(int $staffUserId, \DateTimeImmutable $atDate): ?array
    {
        if (!$this->eligible || $staffUserId !== 501) {
            return null;
        }
        return [
            'assignment_id' => 700,
            'org_unit_id' => 10,
            'job_title_id' => 20,
            'group_ids' => [],
            'employment_status' => 'active',
        ];
    }
}

final class AlternativeAttendanceTestAuthorization implements AlternativeAttendanceAuthorization
{
    public bool $allowRecord = true;
    public bool $allowReview = true;

    /** @var list<string> */
    public array $recordScopes = [];

    /** @var list<string> */
    public array $reviewScopes = [];

    public function assertCanRecord(
        int $actorId,
        int $staffUserId,
        string $allowedScope,
        \DateTimeImmutable $atInstant
    ): void {
        $this->recordScopes[] = $allowedScope;
        if (!$this->allowRecord) {
            throw new \DomainException('ALTERNATIVE_ATTENDANCE_RECORD_NOT_AUTHORIZED');
        }
    }

    public function assertCanReview(
        int $actorId,
        int $staffUserId,
        string $allowedScope,
        \DateTimeImmutable $atInstant
    ): void {
        $this->reviewScopes[] = $allowedScope;
        if (!$this->allowReview) {
            throw new \DomainException('ALTERNATIVE_ATTENDANCE_REVIEW_NOT_AUTHORIZED');
        }
    }
}

final class AlternativeAttendanceTestAudit implements AuditEventWriter
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
        $this->events[] = compact('action', 'entityType', 'recordId', 'details', 'context');
    }
}

final class AlternativeAttendanceTestStaffAccess implements StaffAccessEligibilityQuery
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
$throws = static function (callable $operation, string $expectedMessage, string $message) use (&$failures): void {
    try {
        $operation();
        fwrite(STDERR, "FAIL: {$message} (no exception)\n");
        ++$failures;
    } catch (\Throwable $exception) {
        if ($exception->getMessage() !== $expectedMessage) {
            fwrite(STDERR, "FAIL: {$message} (got {$exception->getMessage()})\n");
            ++$failures;
        }
    }
};

$methods = [
    9 => [
        'id' => 9,
        'method_type' => 'manual_verified',
        'requires_reason' => 1,
        'requires_attachment' => 1,
        'requires_review' => 1,
        'allowed_scope' => 'hr',
        'status' => 'active',
    ],
    10 => [
        'id' => 10,
        'method_type' => 'access_log',
        'requires_reason' => 0,
        'requires_attachment' => 0,
        'requires_review' => 0,
        'allowed_scope' => 'manager',
        'status' => 'active',
    ],
    11 => [
        'id' => 11,
        'method_type' => 'biometric',
        'requires_reason' => 0,
        'requires_attachment' => 0,
        'requires_review' => 0,
        'allowed_scope' => 'hr',
        'status' => 'active',
    ],
];
$repository = new AlternativeAttendanceTestRepository($methods);
$assignments = new AlternativeAttendanceTestAssignments();
$authorization = new AlternativeAttendanceTestAuthorization();
$audit = new AlternativeAttendanceTestAudit();
$service = new AlternativeAttendanceRecorder(
    new AlternativeAttendanceTestTransaction(),
    $repository,
    $assignments,
    $authorization,
    $audit
);
$occurredAt = new \DateTimeImmutable('2026-01-05 07:30:00', new \DateTimeZone('Africa/Cairo'));
$evidence = [
    'event_type' => 'in',
    'attachment_ref' => 'staff-attendance/alternative/proof-1.pdf',
    'evidence_ref' => 'staff-attendance/alternative/form-1',
];

$recorded = $service->record(100, 501, 9, $occurredAt, 'تعذر استخدام البصمة', $evidence, 'alt-attendance-1');
$assert(($recorded['event_id'] ?? null) === 1, 'recording creates one append-only alternative event');
$assert(($recorded['review_status'] ?? null) === 'pending', 'a review-required method stays pending');
$assert(($recorded['replayed'] ?? null) === false, 'first recording is not an idempotent replay');
$assert(($repository->events[1]['staff_user_id'] ?? null) === 501, 'alternative evidence remains linked to the specified worker');
$assert(
    array_key_exists('biometric_identity', $repository->events[1])
    && $repository->events[1]['biometric_identity'] === null,
    'alternative evidence never stores a biometric identity'
);
$assert(($repository->events[1]['link_status'] ?? null) === 'matched', 'alternative evidence is explicitly attributed rather than inferred');
$assert(preg_match('/^[a-f0-9]{64}$/', (string) ($repository->events[1]['raw_hash'] ?? '')) === 1, 'alternative request fingerprint is hashed');
$assert(end($authorization->recordScopes) === 'hr', 'configured entry-method scope is delegated to authorization');
$auditSerialized = json_encode($audit->events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(!str_contains((string) $auditSerialized, 'تعذر استخدام البصمة'), 'audit stores a reason hash rather than the sensitive reason text');
$assert(!str_contains((string) $auditSerialized, 'proof-1.pdf'), 'audit does not expose private evidence references');

$replayed = $service->record(100, 501, 9, $occurredAt, 'تعذر استخدام البصمة', $evidence, 'alt-attendance-1');
$assert(($replayed['event_id'] ?? null) === 1 && ($replayed['replayed'] ?? null) === true, 'same alternative request replays idempotently');
$assert(count($repository->events) === 1, 'idempotent replay creates no second raw event');
$throws(
    static fn () => $service->record(100, 501, 9, $occurredAt, 'سبب مختلف', $evidence, 'alt-attendance-1'),
    'ALTERNATIVE_ATTENDANCE_IDEMPOTENCY_CONFLICT',
    'same idempotency key with altered evidence fails closed'
);

$throws(
    static fn () => $service->review(100, 1, 'approved', 'لا يجوز اعتماد الذات'),
    'ALTERNATIVE_ATTENDANCE_SELF_REVIEW_FORBIDDEN',
    'the recorder cannot approve their own alternative attendance'
);
$assert(($repository->events[1]['review_status'] ?? null) === 'pending', 'self-review attempt leaves evidence pending');

$reviewed = $service->review(200, 1, 'approved', null);
$assert(($reviewed['review_status'] ?? null) === 'approved' && ($reviewed['reviewed_by'] ?? null) === 200, 'independent reviewer can approve a pending event');
$assert(end($authorization->reviewScopes) === 'hr', 'review authorization receives the configured scope');
$throws(
    static fn () => $service->review(201, 1, 'approved', null),
    'ALTERNATIVE_ATTENDANCE_REVIEW_NOT_PENDING',
    'finalized review cannot be rewritten'
);

$throws(
    static fn () => $service->record(100, 501, 9, $occurredAt, 'بدون دليل مرفق', ['event_type' => 'out'], 'alt-attendance-attachment'),
    'ALTERNATIVE_ATTENDANCE_ATTACHMENT_REQUIRED',
    'method attachment requirement blocks an evidence-free event'
);
$assert(count($repository->events) === 1, 'missing required attachment creates no event');

$authorization->allowRecord = false;
$throws(
    static fn () => $service->record(100, 501, 10, $occurredAt, 'سجل بوابة', ['event_type' => 'out'], 'alt-attendance-denied'),
    'ALTERNATIVE_ATTENDANCE_RECORD_NOT_AUTHORIZED',
    'unauthorized actor cannot create alternative evidence'
);
$authorization->allowRecord = true;
$assert(count($repository->events) === 1, 'denied record creates no event');

$assignments->eligible = false;
$throws(
    static fn () => $service->record(100, 501, 10, $occurredAt, 'سجل بوابة', ['event_type' => 'out'], 'alt-attendance-ineligible'),
    'STAFF_NOT_ELIGIBLE_AT_ALTERNATIVE_ATTENDANCE_DATE',
    'alternative attendance fails when the worker had no dated assignment'
);
$assignments->eligible = true;

$throws(
    static fn () => $service->record(100, 501, 10, $occurredAt, 'سجل بوابة', ['event_type' => 'out', 'attachment_ref' => 'C:\\private\\proof.pdf'], 'alt-attendance-unsafe-ref'),
    'ALTERNATIVE_ATTENDANCE_ATTACHMENT_REF_INVALID',
    'absolute or drive-relative attachment references are rejected'
);
$throws(
    static fn () => $service->record(100, 501, 11, $occurredAt, 'ليس بديلاً', ['event_type' => 'out'], 'alt-attendance-biometric'),
    'ALTERNATIVE_ATTENDANCE_METHOD_NOT_ACTIVE',
    'biometric methods cannot be recorded through the alternative path'
);

$repository->events[99] = [
    'id' => 99,
    'entry_method_id' => 11,
    'staff_user_id' => 501,
    'idempotency_key' => 'shared-biometric-key',
    'raw_hash' => hash('sha256', 'biometric'),
    'review_status' => 'not_required',
    'event_type' => 'in',
];
$throws(
    static fn () => $service->record(100, 501, 10, $occurredAt, 'سجل بوابة', ['event_type' => 'out'], 'shared-biometric-key'),
    'ALTERNATIVE_ATTENDANCE_IDEMPOTENCY_CONFLICT',
    'an idempotency collision with biometric evidence returns a safe domain error'
);

$noReview = $service->record(100, 501, 10, $occurredAt, 'سجل بوابة', ['event_type' => 'out'], 'alt-attendance-no-review');
$assert(($noReview['review_status'] ?? null) === 'not_required', 'non-review alternative method remains marked as such');
$throws(
    static fn () => $service->review(200, (int) $noReview['event_id'], 'approved', null),
    'ALTERNATIVE_ATTENDANCE_REVIEW_NOT_PENDING',
    'non-review alternative evidence cannot be silently promoted through review'
);

$staffAccess = new AlternativeAttendanceTestStaffAccess();
$staffAuthorization = new StaffAlternativeAttendanceAuthorization($staffAccess);
$staffAuthorization->assertCanRecord(100, 501, 'hr', $occurredAt);
$assert(
    ($staffAccess->calls[0]['capability'] ?? null) === 'attendance.alternative.record.hr'
    && ($staffAccess->calls[0]['resourceRef'] ?? null) === 'attendance:alternative:staff:501',
    'Staff access adapter derives a fixed record capability from the immutable method scope'
);
$staffAccess->allowed = false;
$throws(
    static fn () => $staffAuthorization->assertCanReview(200, 501, 'hr', $occurredAt),
    'ALTERNATIVE_ATTENDANCE_REVIEW_NOT_AUTHORIZED',
    'Staff access denial fails closed for independent review'
);
$throws(
    static fn () => $staffAuthorization->assertCanRecord(100, 501, 'hr admin', $occurredAt),
    'ALTERNATIVE_ATTENDANCE_METHOD_SCOPE_INVALID',
    'unconfigured entry-method scope cannot become an arbitrary capability'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} alternative attendance recorder failure(s).\n");
    exit(1);
}

echo "Staff-HR alternative attendance recorder tests passed.\n";
