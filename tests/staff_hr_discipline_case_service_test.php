<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Discipline\DisciplineCaseService;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use EduCore\Modules\Staff\Contracts\DisciplineCaseRepository;

final class DisciplineCaseServiceMemoryRepository implements DisciplineCaseRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $incidents = [];
    /** @var array<int,array<string,mixed>> */
    public array $cases = [];
    /** @var array<int,array<string,mixed>> */
    public array $parties = [];
    /** @var array<int,bool> */
    public array $staff = [7 => true, 8 => true, 9 => true];
    private int $incidentSequence = 0;
    private int $caseSequence = 0;
    private int $partySequence = 0;

    public function transactional(callable $work): mixed
    {
        $incidents = $this->incidents;
        $cases = $this->cases;
        $parties = $this->parties;
        $incidentSequence = $this->incidentSequence;
        $caseSequence = $this->caseSequence;
        $partySequence = $this->partySequence;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->incidents = $incidents;
            $this->cases = $cases;
            $this->parties = $parties;
            $this->incidentSequence = $incidentSequence;
            $this->caseSequence = $caseSequence;
            $this->partySequence = $partySequence;
            throw $exception;
        }
    }

    public function lockStaff(int $staffUserId): bool
    {
        return isset($this->staff[$staffUserId]);
    }

    public function incidentByCreateIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->incidents as $incident) {
            if (($incident['create_idempotency_key'] ?? null) === $idempotencyKey) {
                return $incident;
            }
        }

        return null;
    }

    public function incidentForUpdate(int $incidentId): ?array
    {
        return $this->incidents[$incidentId] ?? null;
    }

    public function insertIncident(array $incident): int
    {
        $id = ++$this->incidentSequence;
        $this->incidents[$id] = $incident + [
            'id' => $id,
            'status' => 'reported',
            'lock_version' => 1,
        ];

        return $id;
    }

    public function markIncidentTriaged(int $incidentId, int $expectedLockVersion): bool
    {
        $incident = $this->incidents[$incidentId] ?? null;
        if ($incident === null
            || ($incident['status'] ?? null) !== 'reported'
            || (int) ($incident['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $incident['status'] = 'triage';
        $incident['lock_version'] = $expectedLockVersion + 1;
        $this->incidents[$incidentId] = $incident;

        return true;
    }

    public function cancelIncident(
        int $incidentId,
        int $expectedLockVersion,
        int $actorId,
        string $reason,
        string $cancelledAt
    ): bool {
        $incident = $this->incidents[$incidentId] ?? null;
        if ($incident === null
            || !in_array((string) ($incident['status'] ?? ''), ['draft', 'reported', 'triage'], true)
            || (int) ($incident['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $incident += [
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_by_user_id' => $actorId,
            'cancelled_at' => $cancelledAt,
            'lock_version' => $expectedLockVersion + 1,
        ];
        $incident['status'] = 'cancelled';
        $incident['cancellation_reason'] = $reason;
        $incident['cancelled_by_user_id'] = $actorId;
        $incident['cancelled_at'] = $cancelledAt;
        $incident['lock_version'] = $expectedLockVersion + 1;
        $this->incidents[$incidentId] = $incident;

        return true;
    }

    public function caseByCreateIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->cases as $case) {
            if (($case['create_idempotency_key'] ?? null) === $idempotencyKey) {
                return $case;
            }
        }

        return null;
    }

    public function caseByIncidentForUpdate(int $incidentId): ?array
    {
        foreach ($this->cases as $case) {
            if ((int) ($case['incident_id'] ?? 0) === $incidentId) {
                return $case;
            }
        }

        return null;
    }

    public function caseForUpdate(int $caseId): ?array
    {
        return $this->cases[$caseId] ?? null;
    }

    public function insertCase(array $case): int
    {
        $id = ++$this->caseSequence;
        $this->cases[$id] = $case + [
            'id' => $id,
            'status' => 'reported',
            'lock_version' => 1,
        ];

        return $id;
    }

    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus,
        array $changes
    ): bool {
        $case = $this->cases[$caseId] ?? null;
        if ($case === null
            || ($case['status'] ?? null) !== $fromStatus
            || (int) ($case['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $case = array_replace($case, $changes, [
            'status' => $toStatus,
            'lock_version' => $expectedLockVersion + 1,
        ]);
        $this->cases[$caseId] = $case;

        return true;
    }

    public function cancelCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        int $actorId,
        string $reason,
        string $cancelledAt
    ): bool {
        return $this->transitionCase($caseId, $expectedLockVersion, $fromStatus, 'cancelled', [
            'cancellation_reason' => $reason,
            'cancelled_by_user_id' => $actorId,
            'cancelled_at' => $cancelledAt,
        ]);
    }

    public function partyByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->parties as $party) {
            if (($party['idempotency_key'] ?? null) === $idempotencyKey) {
                return $party;
            }
        }

        return null;
    }

    public function partyForUpdate(int $partyId): ?array
    {
        return $this->parties[$partyId] ?? null;
    }

    public function insertParty(array $party): int
    {
        $id = ++$this->partySequence;
        $this->parties[$id] = $party + [
            'id' => $id,
            'status' => 'active',
            'lock_version' => 1,
        ];

        return $id;
    }

    public function declarePartyConflict(
        int $partyId,
        int $expectedLockVersion,
        string $declaration,
        string $declaredAt
    ): bool {
        $party = $this->parties[$partyId] ?? null;
        if ($party === null
            || ($party['status'] ?? null) !== 'active'
            || (int) ($party['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $party['conflict_declaration'] = $declaration;
        $party['conflict_declared_at'] = $declaredAt;
        $party['lock_version'] = $expectedLockVersion + 1;
        $this->parties[$partyId] = $party;

        return true;
    }

    public function withdrawParty(
        int $partyId,
        int $expectedLockVersion,
        int $actorId,
        string $reason,
        string $withdrawnAt
    ): bool {
        $party = $this->parties[$partyId] ?? null;
        if ($party === null
            || ($party['status'] ?? null) !== 'active'
            || (int) ($party['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $party['status'] = 'withdrawn';
        $party['withdrawn_by_user_id'] = $actorId;
        $party['withdrawn_at'] = $withdrawnAt;
        $party['withdrawal_reason'] = $reason;
        $party['lock_version'] = $expectedLockVersion + 1;
        $this->parties[$partyId] = $party;

        return true;
    }
}

final class DisciplineCaseServiceTestAuthorization implements DisciplineCaseAuthorization
{
    /** @var list<string> */
    public array $actions = [];

    public function __construct(private bool $allow = true)
    {
    }

    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $case,
        DateTimeImmutable $atInstant
    ): void {
        $this->actions[] = $action;
        if (!$this->allow) {
            throw new DomainException('DISCIPLINE_ACCESS_DENIED');
        }
    }
}

final class DisciplineCaseServiceTestAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function __construct(private bool $fail = false)
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
            throw new RuntimeException('DISCIPLINE_AUDIT_WRITE_FAILED');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $work, string $expectedMessage, string $message) use (&$failures): void {
    try {
        $work();
        fwrite(STDERR, "FAIL: {$message} (no exception)\n");
        ++$failures;
    } catch (Throwable $exception) {
        if ($exception->getMessage() !== $expectedMessage) {
            fwrite(STDERR, "FAIL: {$message} (got {$exception->getMessage()})\n");
            ++$failures;
        }
    }
};

$repository = new DisciplineCaseServiceMemoryRepository();
$authorization = new DisciplineCaseServiceTestAuthorization();
$audit = new DisciplineCaseServiceTestAudit();
$service = new DisciplineCaseService($repository, $authorization, $audit);

$incidentCommand = [
    'actor_id' => 1,
    'subject_staff_user_id' => 7,
    'description' => 'واقعة اختبار حساسة',
    'classification' => 'attendance',
    'confidentiality_level' => 'restricted',
    'source_resource_type' => 'attendance_day_result',
    'source_resource_id' => 41,
    'source_reference_snapshot' => ['run_id' => 8, 'status' => 'late'],
    'create_idempotency_key' => 'discipline-incident-1',
];
$incident = $service->recordIncident($incidentCommand);
$assert($incident['incident_id'] === 1 && $incident['status'] === 'reported', 'recording an incident creates a reported immutable source record');
$assert($repository->incidents[1]['source_resource_id'] === 41, 'incident stores a source reference without invoking the source owner');
$assert(count($audit->events) === 1, 'incident recording is audited in the same repository transaction path');

$incidentReplay = $service->recordIncident($incidentCommand);
$assert($incidentReplay['replayed'] === true && $incidentReplay['incident_id'] === 1, 'incident create idempotency returns the original receipt');
$assert(count($audit->events) === 1, 'idempotent incident replay does not duplicate audit evidence');
$assertThrows(
    fn (): array => $service->recordIncident(array_replace($incidentCommand, ['description' => 'وصف مختلف'])),
    'DISCIPLINE_INCIDENT_IDEMPOTENCY_CONFLICT',
    'one incident idempotency key cannot be reused for different sensitive content'
);
$assertThrows(
    fn (): array => $service->recordIncident(array_replace($incidentCommand, [
        'create_idempotency_key' => 'discipline-incident-incomplete-source',
        'source_resource_id' => null,
    ])),
    'DISCIPLINE_SOURCE_REFERENCE_INCOMPLETE',
    'a linked source requires both its type and immutable scalar ID'
);

$caseCommand = [
    'actor_id' => 2,
    'incident_id' => 1,
    'create_idempotency_key' => 'discipline-case-1',
];
$case = $service->openCase($caseCommand);
$assert($case['case_id'] === 1 && $case['status'] === 'reported', 'a case opens from exactly one eligible incident');
$assert($repository->incidents[1]['status'] === 'triage', 'opening a case triages the incident rather than modifying its source link');
$caseReplay = $service->openCase($caseCommand);
$assert($caseReplay['replayed'] === true && $caseReplay['case_id'] === 1, 'case create replay is stable even though the opening timestamp is new');
$assertThrows(
    fn (): array => $service->openCase(array_replace($caseCommand, ['create_idempotency_key' => 'discipline-case-duplicate'])),
    'DISCIPLINE_INCIDENT_ALREADY_CASED',
    'one incident cannot silently create a second discipline case'
);
$assertThrows(
    fn (): array => $service->openCase(array_replace($caseCommand, [
        'create_idempotency_key' => 'discipline-case-subject-tamper',
        'subject_staff_user_id' => 8,
    ])),
    'DISCIPLINE_CASE_SUBJECT_IMMUTABLE',
    'the case subject can only come from the original incident'
);

$triaged = $service->triageCase(['actor_id' => 2, 'case_id' => 1, 'expected_lock_version' => 1]);
$assert($triaged['status'] === 'triage' && $triaged['lock_version'] === 2, 'case triage is versioned and auditable');

$partyCommand = [
    'actor_id' => 2,
    'case_id' => 1,
    'party_user_id' => 7,
    'party_role' => 'subject',
    'visibility_scope' => 'case_team',
    'idempotency_key' => 'discipline-party-subject-1',
];
$party = $service->addParty($partyCommand);
$assert($party['party_id'] === 1 && $party['status'] === 'active', 'a case party is stored as its own auditable record');
$assert($service->addParty($partyCommand)['replayed'] === true, 'party creation is idempotent');
$assertThrows(
    fn (): array => $service->addParty(array_replace($partyCommand, [
        'idempotency_key' => 'discipline-party-wrong-subject',
        'party_user_id' => 8,
    ])),
    'DISCIPLINE_PARTY_SUBJECT_MISMATCH',
    'a subject party cannot be substituted for a different worker'
);

$conflict = $service->declarePartyConflict([
    'actor_id' => 2,
    'party_id' => 1,
    'expected_lock_version' => 1,
    'conflict_declaration' => 'صلة مباشرة بطرف الواقعة',
]);
$assert($conflict['conflict_declared'] === true && $conflict['lock_version'] === 2, 'party conflict evidence is versioned instead of being a UI-only flag');
$withdrawn = $service->withdrawParty([
    'actor_id' => 2,
    'party_id' => 1,
    'expected_lock_version' => 2,
    'withdrawal_reason' => 'تغيير تمثيل الطرف',
]);
$assert($withdrawn['status'] === 'withdrawn' && $withdrawn['lock_version'] === 3, 'parties are withdrawn with a reason rather than deleted');

$cancelled = $service->cancelCase([
    'actor_id' => 2,
    'case_id' => 1,
    'expected_lock_version' => 2,
    'cancellation_reason' => 'ثبت عدم الاختصاص بعد المراجعة',
]);
$assert($cancelled['status'] === 'cancelled', 'a pre-decision case is cancelled with a reason rather than hard-deleted');
$assert(in_array('record_incident', $authorization->actions, true)
    && in_array('open_case', $authorization->actions, true)
    && in_array('add_case_party', $authorization->actions, true), 'every discipline write crossed the live authorization boundary');

$deniedRepository = new DisciplineCaseServiceMemoryRepository();
$denied = new DisciplineCaseService(
    $deniedRepository,
    new DisciplineCaseServiceTestAuthorization(false),
    new DisciplineCaseServiceTestAudit()
);
$assertThrows(
    fn (): array => $denied->recordIncident($incidentCommand),
    'DISCIPLINE_ACCESS_DENIED',
    'unauthorized actors cannot create an incident before persistence'
);
$assert($deniedRepository->incidents === [], 'authorization failure leaves no discipline write behind');

$auditFailureRepository = new DisciplineCaseServiceMemoryRepository();
$auditFailure = new DisciplineCaseService(
    $auditFailureRepository,
    new DisciplineCaseServiceTestAuthorization(),
    new DisciplineCaseServiceTestAudit(true)
);
$assertThrows(
    fn (): array => $auditFailure->recordIncident($incidentCommand),
    'DISCIPLINE_AUDIT_WRITE_FAILED',
    'mandatory audit failure aborts the incident transaction'
);
$assert(
    $auditFailureRepository->incidents === [],
    'the incident write rolls back when its mandatory shared audit event fails'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} discipline case service test failure(s).\n");
    exit(1);
}

echo "Staff-HR discipline case service tests passed.\n";
