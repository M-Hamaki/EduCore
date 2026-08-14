<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Discipline\DisciplineAppealService;
use EduCore\Modules\Staff\Contracts\DisciplineAppealRepository;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectQueue;

final class DisciplineAppealMemoryRepository implements DisciplineAppealRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $cases;
    /** @var array<int,array<string,mixed>> */
    public array $decisions;
    /** @var array<int,array<string,mixed>> */
    public array $investigations;
    /** @var array<int,array<string,mixed>> */
    public array $evidence;
    /** @var array<int,array<string,mixed>> */
    public array $appeals = [];
    /** @var array<int,array<string,mixed>> */
    public array $interims = [];
    /** @var array<int,array<string,mixed>> */
    public array $reopenEvents = [];
    /** @var array<int,bool> */
    public array $users = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 7 => true, 8 => true, 9 => true];
    private int $nextAppealId = 1;
    private int $nextInterimId = 1;
    private int $nextReopenId = 1;

    public function __construct()
    {
        $issuedAt = (new DateTimeImmutable('-5 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->cases = [
            1 => [
                'id' => 1,
                'case_no' => 'DISC-CASE-APPEAL-001',
                'status' => 'decided',
                'lock_version' => 6,
                'subject_staff_user_id' => 7,
                'opened_by_user_id' => 2,
                'incident_reported_by_user_id' => 1,
            ],
        ];
        $this->decisions = [
            1 => [
                'id' => 1,
                'case_id' => 1,
                'investigation_id' => 1,
                'status' => 'issued',
                'prepared_by_user_id' => 4,
                'decided_by_user_id' => 5,
                'issued_at' => $issuedAt,
                'decision_hash' => str_repeat('d', 64),
                'policy_snapshot' => json_encode([
                    'appeal' => [
                        'appeal_window_minutes' => 10080,
                        'review_sla_minutes' => 120,
                        'suspend_execution_on_submission' => true,
                        'suspension_reason' => 'إيقاف التنفيذ مؤقتًا وفق السياسة المثبتة.',
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
        $this->investigations = [
            1 => ['id' => 1, 'case_id' => 1, 'investigator_user_id' => 3, 'status' => 'completed'],
        ];
        $this->evidence = [
            41 => ['id' => 41, 'case_id' => 1, 'chain_hash' => str_repeat('e', 64)],
        ];
    }

    public function transactional(callable $work): mixed
    {
        $snapshot = [
            $this->cases,
            $this->decisions,
            $this->investigations,
            $this->evidence,
            $this->appeals,
            $this->interims,
            $this->reopenEvents,
            $this->nextAppealId,
            $this->nextInterimId,
            $this->nextReopenId,
        ];
        try {
            return $work();
        } catch (Throwable $exception) {
            [
                $this->cases,
                $this->decisions,
                $this->investigations,
                $this->evidence,
                $this->appeals,
                $this->interims,
                $this->reopenEvents,
                $this->nextAppealId,
                $this->nextInterimId,
                $this->nextReopenId,
            ] = $snapshot;
            throw $exception;
        }
    }

    public function lockUser(int $userId): bool
    {
        return isset($this->users[$userId]);
    }

    public function caseForUpdate(int $caseId): ?array
    {
        return $this->cases[$caseId] ?? null;
    }

    public function decisionForUpdate(int $decisionId): ?array
    {
        return $this->decisions[$decisionId] ?? null;
    }

    public function investigationForUpdate(int $investigationId): ?array
    {
        return $this->investigations[$investigationId] ?? null;
    }

    public function evidenceForUpdate(int $evidenceId): ?array
    {
        return $this->evidence[$evidenceId] ?? null;
    }

    public function appealByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->appeals as $appeal) {
            if (($appeal['idempotency_key'] ?? null) === $idempotencyKey) {
                return $appeal;
            }
        }

        return null;
    }

    public function appealForUpdate(int $appealId): ?array
    {
        return $this->appeals[$appealId] ?? null;
    }

    public function activeAppealForDecisionAndAppellantForUpdate(int $decisionId, int $appellantUserId): ?array
    {
        foreach ($this->appeals as $appeal) {
            if ((int) ($appeal['decision_id'] ?? 0) === $decisionId
                && (int) ($appeal['appellant_user_id'] ?? 0) === $appellantUserId
                && in_array((string) ($appeal['status'] ?? ''), ['submitted', 'under_review'], true)) {
                return $appeal;
            }
        }

        return null;
    }

    public function insertAppeal(array $appeal): int
    {
        $id = $this->nextAppealId++;
        $this->appeals[$id] = array_replace($appeal, [
            'id' => $id,
            'status' => 'submitted',
            'reviewer_user_id' => null,
            'lock_version' => 1,
        ]);

        return $id;
    }

    public function assignAppealReviewer(int $appealId, int $expectedLockVersion, int $reviewerUserId): bool
    {
        $appeal = $this->appeals[$appealId] ?? null;
        if ($appeal === null || ($appeal['status'] ?? null) !== 'submitted'
            || (int) ($appeal['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $appeal['reviewer_user_id'] = $reviewerUserId;
        $appeal['status'] = 'under_review';
        $appeal['lock_version'] = $expectedLockVersion + 1;
        $this->appeals[$appealId] = $appeal;

        return true;
    }

    public function resolveAppeal(
        int $appealId,
        int $expectedLockVersion,
        string $outcome,
        string $outcomeReason,
        string $reviewedAt
    ): bool {
        $appeal = $this->appeals[$appealId] ?? null;
        if ($appeal === null || ($appeal['status'] ?? null) !== 'under_review'
            || (int) ($appeal['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $appeal['status'] = $outcome;
        $appeal['outcome_reason'] = $outcomeReason;
        $appeal['reviewed_at'] = $reviewedAt;
        $appeal['lock_version'] = $expectedLockVersion + 1;
        $this->appeals[$appealId] = $appeal;

        return true;
    }

    public function withdrawAppeal(int $appealId, int $expectedLockVersion): bool
    {
        $appeal = $this->appeals[$appealId] ?? null;
        if ($appeal === null || !in_array((string) ($appeal['status'] ?? ''), ['submitted', 'under_review'], true)
            || (int) ($appeal['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $appeal['status'] = 'withdrawn';
        $appeal['lock_version'] = $expectedLockVersion + 1;
        $this->appeals[$appealId] = $appeal;

        return true;
    }

    public function expireAppeal(int $appealId, int $expectedLockVersion, string $reviewedAt): bool
    {
        $appeal = $this->appeals[$appealId] ?? null;
        if ($appeal === null || !in_array((string) ($appeal['status'] ?? ''), ['submitted', 'under_review'], true)
            || (int) ($appeal['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $appeal['status'] = 'expired';
        $appeal['reviewed_at'] = $reviewedAt;
        $appeal['lock_version'] = $expectedLockVersion + 1;
        $this->appeals[$appealId] = $appeal;

        return true;
    }

    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus
    ): bool {
        $case = $this->cases[$caseId] ?? null;
        if ($case === null || ($case['status'] ?? null) !== $fromStatus
            || (int) ($case['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $case['status'] = $toStatus;
        $case['lock_version'] = $expectedLockVersion + 1;
        $this->cases[$caseId] = $case;

        return true;
    }

    public function interimByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->interims as $measure) {
            if (($measure['idempotency_key'] ?? null) === $idempotencyKey) {
                return $measure;
            }
        }

        return null;
    }

    public function interimForUpdate(int $measureId): ?array
    {
        return $this->interims[$measureId] ?? null;
    }

    public function insertInterim(array $measure): int
    {
        $id = $this->nextInterimId++;
        $this->interims[$id] = array_replace($measure, [
            'id' => $id,
            'status' => 'draft',
            'lock_version' => 1,
        ]);

        return $id;
    }

    public function activateInterim(
        int $measureId,
        int $expectedLockVersion,
        int $authorizedByUserId,
        string $authorizedAt
    ): bool {
        $measure = $this->interims[$measureId] ?? null;
        if ($measure === null || ($measure['status'] ?? null) !== 'draft'
            || (int) ($measure['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $measure['status'] = 'active';
        $measure['authorized_by_user_id'] = $authorizedByUserId;
        $measure['authorized_at'] = $authorizedAt;
        $measure['lock_version'] = $expectedLockVersion + 1;
        $this->interims[$measureId] = $measure;

        return true;
    }

    public function resolveInterim(
        int $measureId,
        int $expectedLockVersion,
        string $outcome,
        ?int $reviewedByUserId,
        string $reviewedAt,
        ?string $resolutionReason
    ): bool {
        $measure = $this->interims[$measureId] ?? null;
        if ($measure === null || ($measure['status'] ?? null) !== 'active'
            || (int) ($measure['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $measure['status'] = $outcome;
        $measure['reviewed_by_user_id'] = $reviewedByUserId;
        $measure['reviewed_at'] = $reviewedAt;
        $measure['resolution_reason'] = $resolutionReason;
        $measure['lock_version'] = $expectedLockVersion + 1;
        $this->interims[$measureId] = $measure;

        return true;
    }

    public function reopenEventByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->reopenEvents as $event) {
            if (($event['idempotency_key'] ?? null) === $idempotencyKey) {
                return $event;
            }
        }

        return null;
    }

    public function reopenEventForUpdate(int $reopenEventId): ?array
    {
        return $this->reopenEvents[$reopenEventId] ?? null;
    }

    public function reopenResolutionForRequestForUpdate(int $requestEventId): ?array
    {
        foreach ($this->reopenEvents as $event) {
            if ((int) ($event['request_event_id'] ?? 0) === $requestEventId) {
                return $event;
            }
        }

        return null;
    }

    public function insertReopenEvent(array $event): int
    {
        $id = $this->nextReopenId++;
        $this->reopenEvents[$id] = array_replace($event, ['id' => $id]);

        return $id;
    }
}

final class DisciplineAppealTestAuthorization implements DisciplineCaseAuthorization
{
    /** @var list<string> */
    public array $actions = [];
    public bool $fail = false;

    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $case,
        DateTimeImmutable $atInstant
    ): void {
        $this->actions[] = $action;
        if ($this->fail) {
            throw new DomainException('DISCIPLINE_APPEAL_ACCESS_DENIED');
        }
    }
}

final class DisciplineAppealTestAudit implements AuditEventWriter
{
    public bool $fail = false;
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
        if ($this->fail) {
            throw new DomainException('DISCIPLINE_APPEAL_AUDIT_FAILURE');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

final class DisciplineAppealTestFinanceQueue implements DisciplineFinanceEffectQueue
{
    /** @var list<array{appeal_id:int,actor_id:int}> */
    public array $reversalCalls = [];

    public function queueForIssuedDecision(
        int $decisionId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        return [
            'status' => 'not_applicable',
            'decision_id' => $decisionId,
            'effect_id' => null,
            'replayed' => false,
        ];
    }

    public function queueReversalForResolvedAppeal(
        int $appealId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        $this->reversalCalls[] = ['appeal_id' => $appealId, 'actor_id' => $actorId];

        return [
            'status' => 'queued',
            'appeal_id' => $appealId,
            'effect_id' => 99,
            'reversed_effect_id' => 88,
            'replayed' => false,
        ];
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertThrows = static function (callable $work, string $expectedMessage, string $message) use (&$assertions): void {
    ++$assertions;
    try {
        $work();
    } catch (Throwable $exception) {
        if ($exception->getMessage() === $expectedMessage) {
            return;
        }
        throw new RuntimeException($message . ': expected ' . $expectedMessage . ', got ' . $exception->getMessage());
    }
    throw new RuntimeException($message . ': no exception');
};

/** @return array{0:DisciplineAppealMemoryRepository,1:DisciplineAppealTestAuthorization,2:DisciplineAppealTestAudit,3:DisciplineAppealService} */
$newFixture = static function (?DisciplineFinanceEffectQueue $financeEffects = null): array {
    $repository = new DisciplineAppealMemoryRepository();
    $authorization = new DisciplineAppealTestAuthorization();
    $audit = new DisciplineAppealTestAudit();

    return [
        $repository,
        $authorization,
        $audit,
        new DisciplineAppealService($repository, $authorization, $audit, null, $financeEffects),
    ];
};
$appealCommand = static function (string $idempotencyKey = 'discipline-appeal-1'): array {
    return [
        'actor_id' => 7,
        'decision_id' => 1,
        'expected_case_lock_version' => 6,
        'appeal_reason' => 'أطلب مراجعة القرار للأسباب المرفقة في السجل.',
        'idempotency_key' => $idempotencyKey,
    ];
};

[$repository, $authorization, $audit, $service] = $newFixture();
$assertThrows(
    static fn (): array => $service->submitAppeal(array_replace($appealCommand(), ['actor_id' => 4])),
    'DISCIPLINE_APPEAL_SUBJECT_ONLY',
    'only the affected worker can submit an appeal'
);
$assert($repository->appeals === [] && $repository->cases[1]['status'] === 'decided', 'unauthorized subject change leaves no appeal');
$appeal = $service->submitAppeal($appealCommand());
$assert(
    $appeal['appeal_id'] === 1
        && $appeal['status'] === 'submitted'
        && $appeal['suspends_execution'] === true
        && $repository->cases[1]['status'] === 'appeal_pending'
        && $repository->cases[1]['lock_version'] === 7,
    'appeal freezes its configured window/SLA and records the policy suspension flag'
);
$assert(
    $service->submitAppeal($appealCommand())['replayed'] === true
        && count($repository->appeals) === 1,
    'appeal submission replay survives the case transition without a duplicate record'
);
$assertThrows(
    static fn (): array => $service->assignAppealReviewer([
        'actor_id' => 9,
        'appeal_id' => 1,
        'expected_lock_version' => 1,
        'reviewer_user_id' => 5,
    ]),
    'DISCIPLINE_APPEAL_REVIEWER_CONFLICT',
    'the final decision maker cannot review the appeal'
);
$assigned = $service->assignAppealReviewer([
    'actor_id' => 9,
    'appeal_id' => 1,
    'expected_lock_version' => 1,
    'reviewer_user_id' => 8,
]);
$assert(
    $assigned['status'] === 'under_review'
        && $assigned['reviewer_user_id'] === 8
        && $assigned['lock_version'] === 2,
    'an independent reviewer is assigned under optimistic locking'
);
$resolved = $service->resolveAppeal([
    'actor_id' => 8,
    'appeal_id' => 1,
    'expected_lock_version' => 2,
    'outcome' => 'upheld',
    'outcome_reason' => 'تمت المراجعة وفق السياسة المثبتة.',
]);
$assert(
    $resolved['status'] === 'upheld'
        && $repository->cases[1]['status'] === 'upheld'
        && $repository->decisions[1]['status'] === 'issued',
    'appeal outcome changes the case without mutating the issued decision itself'
);

$financeQueue = new DisciplineAppealTestFinanceQueue();
[$financeRepository, , , $financeService] = $newFixture($financeQueue);
$financeAppeal = $financeService->submitAppeal($appealCommand('discipline-appeal-finance-reversal'));
$financeService->assignAppealReviewer([
    'actor_id' => 9,
    'appeal_id' => $financeAppeal['appeal_id'],
    'expected_lock_version' => 1,
    'reviewer_user_id' => 8,
]);
$financeResolved = $financeService->resolveAppeal([
    'actor_id' => 8,
    'appeal_id' => $financeAppeal['appeal_id'],
    'expected_lock_version' => 2,
    'outcome' => 'revoked',
    'outcome_reason' => 'تم إلغاء القرار بعد مراجعة التظلم.',
]);
$assert(
    $financeResolved['status'] === 'revoked'
        && $financeRepository->cases[1]['status'] === 'revoked'
        && $financeQueue->reversalCalls === [['appeal_id' => 1, 'actor_id' => 8]],
    'final revoked appeal atomically hands only a local reversal intent to the Finance queue'
);

[$withdrawRepository, , , $withdrawService] = $newFixture();
$withdrawAppeal = $withdrawService->submitAppeal($appealCommand('discipline-appeal-withdraw'));
$withdrawn = $withdrawService->withdrawAppeal([
    'actor_id' => 7,
    'appeal_id' => $withdrawAppeal['appeal_id'],
    'expected_lock_version' => 1,
]);
$assert(
    $withdrawn['status'] === 'withdrawn'
        && $withdrawRepository->cases[1]['status'] === 'decided',
    'withdrawn appeal restores the case to the still-issued decision state'
);

[$expiredRepository, , , $expiredService] = $newFixture();
$expiredAppeal = $expiredService->submitAppeal($appealCommand('discipline-appeal-expire'));
$expiredRepository->appeals[1]['due_at'] = '2000-01-01 00:00:00.000000';
$expired = $expiredService->expireAppeal([
    'actor_id' => 9,
    'appeal_id' => $expiredAppeal['appeal_id'],
    'expected_lock_version' => 1,
]);
$assert(
    $expired['status'] === 'expired'
        && $expiredRepository->cases[1]['status'] === 'decided',
    'expired appeal remains historical and returns the case to its issued-decision state'
);

[$interimRepository, , , $interimService] = $newFixture();
$clock = new DateTimeZone('UTC');
$startsAt = (new DateTimeImmutable('-1 minute', $clock))->format('Y-m-d H:i:s.u');
$endsAt = (new DateTimeImmutable('+1 hour', $clock))->format('Y-m-d H:i:s.u');
$measure = $interimService->requestInterimMeasure([
    'actor_id' => 4,
    'case_id' => 1,
    'expected_case_lock_version' => 6,
    'basis_evidence_id' => 41,
    'measure_type' => 'temporary_access_restriction',
    'reason' => 'إجراء احترازي مؤقت لحماية سير التحقيق.',
    'access_effect' => ['intent' => 'restrict_nonessential_access'],
    'starts_at' => $startsAt,
    'ends_at' => $endsAt,
    'review_due_at' => (new DateTimeImmutable('+30 minutes', $clock))->format('Y-m-d H:i:s.u'),
    'idempotency_key' => 'discipline-interim-1',
]);
$assert(
    $measure['status'] === 'draft'
        && $measure['measure_id'] === 1
        && $interimRepository->interims[1]['access_effect'] !== null,
    'temporary measure records only an intended access effect before separate authorization'
);
$activeMeasure = $interimService->activateInterimMeasure([
    'actor_id' => 5,
    'measure_id' => 1,
    'expected_lock_version' => 1,
]);
$assert($activeMeasure['status'] === 'active' && $activeMeasure['lock_version'] === 2, 'different authorizer activates a live temporary measure');
$assertThrows(
    static fn (): array => $interimService->resolveInterimMeasure([
        'actor_id' => 5,
        'measure_id' => 1,
        'expected_lock_version' => 2,
        'outcome' => 'completed',
        'resolution_reason' => 'سبب غير مسموح من صاحب التفويض.',
    ]),
    'DISCIPLINE_INTERIM_REVIEWER_CONFLICT',
    'the authorizer cannot also close the temporary measure'
);
$completedMeasure = $interimService->resolveInterimMeasure([
    'actor_id' => 8,
    'measure_id' => 1,
    'expected_lock_version' => 2,
    'outcome' => 'completed',
    'resolution_reason' => 'انتهى الاحتياج للإجراء الاحترازي.',
]);
$assert(
    $completedMeasure['status'] === 'completed'
        && $interimRepository->interims[1]['resolution_reason'] !== null,
    'independent review closes the temporary measure without executing an external access write'
);

[$reopenRepository, , , $reopenService] = $newFixture();
$request = $reopenService->requestReopen([
    'actor_id' => 4,
    'case_id' => 1,
    'prior_decision_id' => 1,
    'new_evidence_id' => 41,
    'expected_case_lock_version' => 6,
    'reopen_reason' => 'ظهر دليل جديد يحتاج إلى تحقيق مستقل.',
    'idempotency_key' => 'discipline-reopen-request-1',
]);
$assert(
    $request['status'] === 'requested'
        && $request['request_event_id'] === null
        && $reopenRepository->cases[1]['status'] === 'decided',
    'reopen request preserves the existing final case until a separate authority decides'
);
$assertThrows(
    static fn (): array => $reopenService->decideReopen([
        'actor_id' => 4,
        'request_event_id' => $request['reopen_event_id'],
        'expected_case_lock_version' => 6,
        'outcome' => 'authorized',
        'idempotency_key' => 'discipline-reopen-self-authorize',
    ]),
    'DISCIPLINE_REOPEN_DECISION_FORBIDDEN',
    'requester cannot authorize the same evidence-based reopen'
);
$authorized = $reopenService->decideReopen([
    'actor_id' => 9,
    'request_event_id' => $request['reopen_event_id'],
    'expected_case_lock_version' => 6,
    'outcome' => 'authorized',
    'idempotency_key' => 'discipline-reopen-authorize-1',
]);
$assert(
    $authorized['status'] === 'authorized'
        && $authorized['request_event_id'] === $request['reopen_event_id']
        && $reopenRepository->cases[1]['status'] === 'reopened',
    'authorized reopen writes a linked append-only decision and transitions the case only then'
);
$assert(
    $reopenService->decideReopen([
        'actor_id' => 9,
        'request_event_id' => $request['reopen_event_id'],
        'expected_case_lock_version' => 6,
        'outcome' => 'authorized',
        'idempotency_key' => 'discipline-reopen-authorize-1',
    ])['replayed'] === true,
    'reopen authorization idempotency survives the case transition'
);

[$rollbackRepository, , $rollbackAudit, $rollbackService] = $newFixture();
$rollbackAudit->fail = true;
$assertThrows(
    static fn (): array => $rollbackService->submitAppeal($appealCommand('discipline-appeal-audit-rollback')),
    'DISCIPLINE_APPEAL_AUDIT_FAILURE',
    'mandatory audit failure aborts the appeal submission transaction'
);
$assert(
    $rollbackRepository->appeals === []
        && $rollbackRepository->cases[1]['status'] === 'decided',
    'audit failure leaves neither an appeal row nor a case-state transition'
);
$assert(
    in_array('submit_appeal', $authorization->actions, true)
        && in_array('assign_appeal_reviewer', $authorization->actions, true)
        && in_array('resolve_appeal', $authorization->actions, true),
    'appeal lifecycle writes cross the authorization boundary'
);

echo 'staff_hr_discipline_appeal_service_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
