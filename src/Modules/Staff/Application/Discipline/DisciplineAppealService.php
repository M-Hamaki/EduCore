<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Discipline;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\DisciplineAppealRepository;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectQueue;
use InvalidArgumentException;
use JsonException;

/**
 * Owns non-destructive appeal, temporary-measure, and reopening commands.
 *
 * These records are legal/audit facts. The service never changes an issued
 * decision in place, executes an access effect, or writes Finance. A later
 * owner consumes only durable, authorized outcomes through an explicit port.
 */
final class DisciplineAppealService
{
    /** @var list<string> */
    private const REOPEN_CASE_STATES = ['decided', 'appeal_pending', 'upheld', 'amended', 'revoked', 'closed'];
    /** @var list<string> */
    private const INTERIM_CASE_STATES = [
        'triage',
        'under_investigation',
        'pending_decision',
        'decided',
        'appeal_pending',
        'reopened',
    ];

    private DateTimeZone $clockZone;

    public function __construct(
        private DisciplineAppealRepository $repository,
        private DisciplineCaseAuthorization $authorization,
        private AuditEventWriter $audit,
        ?DateTimeZone $clockZone = null,
        private ?DisciplineFinanceEffectQueue $financeEffects = null
    ) {
        $this->clockZone = $clockZone ?? new DateTimeZone('UTC');
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function submitAppeal(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $decisionId = $this->positiveId($command['decision_id'] ?? null, 'DISCIPLINE_DECISION_ID_INVALID');
        $expectedCaseLock = $this->positiveId(
            $command['expected_case_lock_version'] ?? null,
            'DISCIPLINE_CASE_LOCK_INVALID'
        );
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'DISCIPLINE_APPEAL_IDEMPOTENCY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $command,
            $actorId,
            $decisionId,
            $expectedCaseLock,
            $idempotencyKey,
            $now
        ): array {
            $decision = $this->requiredDecision($decisionId);
            $case = $this->requiredCase(
                $this->positiveId($decision['case_id'] ?? null, 'DISCIPLINE_DECISION_CASE_INVALID')
            );
            $this->authorization->assertCanAct($actorId, 'submit_appeal', $case, $now);
            if ((int) ($case['subject_staff_user_id'] ?? 0) !== $actorId) {
                throw new DomainException('DISCIPLINE_APPEAL_SUBJECT_ONLY');
            }
            if ((int) ($decision['decided_by_user_id'] ?? 0) === $actorId) {
                throw new DomainException('DISCIPLINE_APPEAL_DECIDER_CONFLICT');
            }
            if (!$this->repository->lockUser($actorId)) {
                throw new DomainException('DISCIPLINE_ACTOR_NOT_FOUND');
            }

            $policy = $this->appealPolicy($decision);
            $input = $this->appealInput($command, $actorId, $case, $decision, $policy, $idempotencyKey, $now);
            $existing = $this->repository->appealByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['appeal_hash'] ?? ''), $input['appeal_hash'])) {
                    return $this->appealReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_APPEAL_IDEMPOTENCY_CONFLICT');
            }
            if ((string) ($decision['status'] ?? '') !== 'issued'
                || (string) ($case['status'] ?? '') !== 'decided'
                || (int) ($case['lock_version'] ?? 0) !== $expectedCaseLock) {
                throw new DomainException('DISCIPLINE_APPEAL_SUBMISSION_FORBIDDEN');
            }
            if ($this->repository->activeAppealForDecisionAndAppellantForUpdate($decisionId, $actorId) !== null) {
                throw new DomainException('DISCIPLINE_APPEAL_ALREADY_ACTIVE');
            }

            $appealId = $this->repository->insertAppeal($input);
            if ($appealId <= 0) {
                throw new DomainException('DISCIPLINE_APPEAL_PERSIST_FAILED');
            }
            if (!$this->repository->transitionCase(
                (int) $case['id'],
                $expectedCaseLock,
                'decided',
                'appeal_pending'
            )) {
                throw new DomainException('DISCIPLINE_CASE_STALE');
            }
            $stored = array_replace($input, [
                'id' => $appealId,
                'status' => 'submitted',
                'lock_version' => 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_appeal_submitted',
                'staff_discipline_appeals',
                $appealId,
                null,
                [
                    'case_id' => (int) $case['id'],
                    'decision_id' => $decisionId,
                    'appellant_user_id' => $actorId,
                    'due_at' => $input['due_at'],
                    'suspends_execution' => (bool) $input['suspends_execution'],
                    'reason_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_discipline_case_appeal_pending',
                'staff_discipline_cases',
                (int) $case['id'],
                (string) ($case['case_no'] ?? ''),
                ['previous_status' => 'decided', 'status' => 'appeal_pending', 'appeal_id' => $appealId],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->appealReceipt($stored, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function assignAppealReviewer(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $appealId = $this->positiveId($command['appeal_id'] ?? null, 'DISCIPLINE_APPEAL_ID_INVALID');
        $expectedLock = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_APPEAL_LOCK_INVALID'
        );
        $reviewerId = $this->positiveId(
            $command['reviewer_user_id'] ?? null,
            'DISCIPLINE_APPEAL_REVIEWER_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($actorId, $appealId, $expectedLock, $reviewerId, $now): array {
            $appeal = $this->requiredAppeal($appealId);
            $case = $this->requiredCase(
                $this->positiveId($appeal['case_id'] ?? null, 'DISCIPLINE_APPEAL_CASE_INVALID')
            );
            $decision = $this->requiredDecision(
                $this->positiveId($appeal['decision_id'] ?? null, 'DISCIPLINE_APPEAL_DECISION_INVALID')
            );
            $this->authorization->assertCanAct($actorId, 'assign_appeal_reviewer', $case, $now);
            if ((string) ($appeal['status'] ?? '') !== 'submitted'
                || (int) ($appeal['lock_version'] ?? 0) !== $expectedLock) {
                throw new DomainException('DISCIPLINE_APPEAL_REVIEW_ASSIGNMENT_STALE');
            }
            $investigation = $this->decisionInvestigation($decision);
            $this->assertReviewerSeparated($reviewerId, $case, $decision, $appeal, $investigation);
            if (!$this->repository->lockUser($reviewerId)) {
                throw new DomainException('DISCIPLINE_APPEAL_REVIEWER_NOT_FOUND');
            }
            if (!$this->repository->assignAppealReviewer($appealId, $expectedLock, $reviewerId)) {
                throw new DomainException('DISCIPLINE_APPEAL_REVIEW_ASSIGNMENT_STALE');
            }
            $after = array_replace($appeal, [
                'reviewer_user_id' => $reviewerId,
                'status' => 'under_review',
                'lock_version' => $expectedLock + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_appeal_reviewer_assigned',
                'staff_discipline_appeals',
                $appealId,
                null,
                [
                    'case_id' => (int) $case['id'],
                    'decision_id' => (int) $decision['id'],
                    'reviewer_user_id' => $reviewerId,
                    'status' => 'under_review',
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->appealReceipt($after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function resolveAppeal(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $appealId = $this->positiveId($command['appeal_id'] ?? null, 'DISCIPLINE_APPEAL_ID_INVALID');
        $expectedLock = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_APPEAL_LOCK_INVALID'
        );
        $outcome = $this->enum(
            $command['outcome'] ?? null,
            ['upheld', 'amended', 'revoked'],
            'DISCIPLINE_APPEAL_OUTCOME_INVALID'
        );
        $outcomeReason = $this->requiredText(
            $command['outcome_reason'] ?? null,
            20000,
            'DISCIPLINE_APPEAL_OUTCOME_REASON_REQUIRED'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $appealId,
            $expectedLock,
            $outcome,
            $outcomeReason,
            $now
        ): array {
            $appeal = $this->requiredAppeal($appealId);
            $case = $this->requiredCase(
                $this->positiveId($appeal['case_id'] ?? null, 'DISCIPLINE_APPEAL_CASE_INVALID')
            );
            $decision = $this->requiredDecision(
                $this->positiveId($appeal['decision_id'] ?? null, 'DISCIPLINE_APPEAL_DECISION_INVALID')
            );
            $this->authorization->assertCanAct($actorId, 'resolve_appeal', $case, $now);
            if ((string) ($case['status'] ?? '') !== 'appeal_pending'
                || (string) ($appeal['status'] ?? '') !== 'under_review'
                || (int) ($appeal['reviewer_user_id'] ?? 0) !== $actorId
                || (int) ($appeal['lock_version'] ?? 0) !== $expectedLock) {
                throw new DomainException('DISCIPLINE_APPEAL_RESOLUTION_FORBIDDEN');
            }
            $this->assertReviewerSeparated($actorId, $case, $decision, $appeal, $this->decisionInvestigation($decision));
            if (!$this->repository->resolveAppeal(
                $appealId,
                $expectedLock,
                $outcome,
                $outcomeReason,
                $this->instant($now)
            )) {
                throw new DomainException('DISCIPLINE_APPEAL_RESOLUTION_STALE');
            }
            $caseLock = $this->positiveId($case['lock_version'] ?? null, 'DISCIPLINE_CASE_LOCK_INVALID');
            if (!$this->repository->transitionCase((int) $case['id'], $caseLock, 'appeal_pending', $outcome)) {
                throw new DomainException('DISCIPLINE_CASE_STALE');
            }
            $after = array_replace($appeal, [
                'status' => $outcome,
                'outcome_reason' => $outcomeReason,
                'reviewed_at' => $this->instant($now),
                'lock_version' => $expectedLock + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_appeal_resolved',
                'staff_discipline_appeals',
                $appealId,
                null,
                [
                    'case_id' => (int) $case['id'],
                    'decision_id' => (int) $decision['id'],
                    'status' => $outcome,
                    'outcome_reason_provided' => true,
                    'financial_write_performed' => false,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_discipline_case_appeal_resolved',
                'staff_discipline_cases',
                (int) $case['id'],
                (string) ($case['case_no'] ?? ''),
                ['previous_status' => 'appeal_pending', 'status' => $outcome, 'appeal_id' => $appealId],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            if ($this->financeEffects !== null
                && in_array($outcome, ['amended', 'revoked'], true)) {
                $this->financeEffects->queueReversalForResolvedAppeal($appealId, $actorId, $now);
            }

            return $this->appealReceipt($after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function withdrawAppeal(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $appealId = $this->positiveId($command['appeal_id'] ?? null, 'DISCIPLINE_APPEAL_ID_INVALID');
        $expectedLock = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_APPEAL_LOCK_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($actorId, $appealId, $expectedLock, $now): array {
            $appeal = $this->requiredAppeal($appealId);
            $case = $this->requiredCase(
                $this->positiveId($appeal['case_id'] ?? null, 'DISCIPLINE_APPEAL_CASE_INVALID')
            );
            $this->authorization->assertCanAct($actorId, 'withdraw_appeal', $case, $now);
            if ((int) ($appeal['appellant_user_id'] ?? 0) !== $actorId
                || !in_array((string) ($appeal['status'] ?? ''), ['submitted', 'under_review'], true)
                || (int) ($appeal['lock_version'] ?? 0) !== $expectedLock
                || (string) ($case['status'] ?? '') !== 'appeal_pending') {
                throw new DomainException('DISCIPLINE_APPEAL_WITHDRAWAL_FORBIDDEN');
            }
            if (!$this->repository->withdrawAppeal($appealId, $expectedLock)) {
                throw new DomainException('DISCIPLINE_APPEAL_WITHDRAWAL_STALE');
            }
            $caseLock = $this->positiveId($case['lock_version'] ?? null, 'DISCIPLINE_CASE_LOCK_INVALID');
            if (!$this->repository->transitionCase((int) $case['id'], $caseLock, 'appeal_pending', 'decided')) {
                throw new DomainException('DISCIPLINE_CASE_STALE');
            }
            $after = array_replace($appeal, ['status' => 'withdrawn', 'lock_version' => $expectedLock + 1]);
            $this->audit->recordEvent(
                'staff_discipline_appeal_withdrawn',
                'staff_discipline_appeals',
                $appealId,
                null,
                ['case_id' => (int) $case['id'], 'status' => 'withdrawn'],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_discipline_case_appeal_withdrawn',
                'staff_discipline_cases',
                (int) $case['id'],
                (string) ($case['case_no'] ?? ''),
                ['previous_status' => 'appeal_pending', 'status' => 'decided', 'appeal_id' => $appealId],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->appealReceipt($after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function expireAppeal(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $appealId = $this->positiveId($command['appeal_id'] ?? null, 'DISCIPLINE_APPEAL_ID_INVALID');
        $expectedLock = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_APPEAL_LOCK_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($actorId, $appealId, $expectedLock, $now): array {
            $appeal = $this->requiredAppeal($appealId);
            $case = $this->requiredCase(
                $this->positiveId($appeal['case_id'] ?? null, 'DISCIPLINE_APPEAL_CASE_INVALID')
            );
            $this->authorization->assertCanAct($actorId, 'expire_appeal', $case, $now);
            $dueAt = $this->requiredInstant($appeal['due_at'] ?? null, 'DISCIPLINE_APPEAL_DUE_INVALID');
            if (!in_array((string) ($appeal['status'] ?? ''), ['submitted', 'under_review'], true)
                || (int) ($appeal['lock_version'] ?? 0) !== $expectedLock
                || (string) ($case['status'] ?? '') !== 'appeal_pending'
                || $now < $dueAt) {
                throw new DomainException('DISCIPLINE_APPEAL_EXPIRY_FORBIDDEN');
            }
            if (!$this->repository->expireAppeal($appealId, $expectedLock, $this->instant($now))) {
                throw new DomainException('DISCIPLINE_APPEAL_EXPIRY_STALE');
            }
            $caseLock = $this->positiveId($case['lock_version'] ?? null, 'DISCIPLINE_CASE_LOCK_INVALID');
            if (!$this->repository->transitionCase((int) $case['id'], $caseLock, 'appeal_pending', 'decided')) {
                throw new DomainException('DISCIPLINE_CASE_STALE');
            }
            $after = array_replace($appeal, [
                'status' => 'expired',
                'reviewed_at' => $this->instant($now),
                'lock_version' => $expectedLock + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_appeal_expired',
                'staff_discipline_appeals',
                $appealId,
                null,
                ['case_id' => (int) $case['id'], 'status' => 'expired'],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_discipline_case_appeal_expired',
                'staff_discipline_cases',
                (int) $case['id'],
                (string) ($case['case_no'] ?? ''),
                ['previous_status' => 'appeal_pending', 'status' => 'decided', 'appeal_id' => $appealId],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->appealReceipt($after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function requestInterimMeasure(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $caseId = $this->positiveId($command['case_id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $expectedCaseLock = $this->positiveId(
            $command['expected_case_lock_version'] ?? null,
            'DISCIPLINE_CASE_LOCK_INVALID'
        );
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'DISCIPLINE_INTERIM_IDEMPOTENCY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $command,
            $actorId,
            $caseId,
            $expectedCaseLock,
            $idempotencyKey,
            $now
        ): array {
            $case = $this->requiredCase($caseId);
            $this->authorization->assertCanAct($actorId, 'request_interim_measure', $case, $now);
            if (!in_array((string) ($case['status'] ?? ''), self::INTERIM_CASE_STATES, true)
                || (int) ($case['lock_version'] ?? 0) !== $expectedCaseLock) {
                throw new DomainException('DISCIPLINE_INTERIM_REQUEST_FORBIDDEN');
            }
            $input = $this->interimInput($command, $actorId, $case, $idempotencyKey);
            $existing = $this->repository->interimByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['measure_hash'] ?? ''), $input['measure_hash'])) {
                    return $this->interimReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_INTERIM_IDEMPOTENCY_CONFLICT');
            }
            if ($input['basis_evidence_id'] !== null) {
                $evidence = $this->requiredEvidence($input['basis_evidence_id']);
                if ((int) ($evidence['case_id'] ?? 0) !== $caseId) {
                    throw new DomainException('DISCIPLINE_INTERIM_EVIDENCE_MISMATCH');
                }
            }
            $measureId = $this->repository->insertInterim($input);
            if ($measureId <= 0) {
                throw new DomainException('DISCIPLINE_INTERIM_PERSIST_FAILED');
            }
            $stored = array_replace($input, ['id' => $measureId, 'status' => 'draft', 'lock_version' => 1]);
            $this->audit->recordEvent(
                'staff_discipline_interim_requested',
                'staff_discipline_interim_measures',
                $measureId,
                (string) $input['measure_type'],
                [
                    'case_id' => $caseId,
                    'basis_evidence_id' => $input['basis_evidence_id'],
                    'starts_at' => $input['starts_at'],
                    'ends_at' => $input['ends_at'],
                    'access_effect_provided' => $input['access_effect'] !== null,
                    'reason_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->interimReceipt($stored, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function activateInterimMeasure(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $measureId = $this->positiveId($command['measure_id'] ?? null, 'DISCIPLINE_INTERIM_ID_INVALID');
        $expectedLock = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_INTERIM_LOCK_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($actorId, $measureId, $expectedLock, $now): array {
            $measure = $this->requiredInterim($measureId);
            $case = $this->requiredCase(
                $this->positiveId($measure['case_id'] ?? null, 'DISCIPLINE_INTERIM_CASE_INVALID')
            );
            $this->authorization->assertCanAct($actorId, 'authorize_interim_measure', $case, $now);
            if ((string) ($measure['status'] ?? '') !== 'draft'
                || (int) ($measure['lock_version'] ?? 0) !== $expectedLock
                || (int) ($measure['requested_by_user_id'] ?? 0) === $actorId) {
                throw new DomainException('DISCIPLINE_INTERIM_AUTHORIZATION_FORBIDDEN');
            }
            $startsAt = $this->requiredInstant($measure['starts_at'] ?? null, 'DISCIPLINE_INTERIM_WINDOW_INVALID');
            $endsAt = $this->requiredInstant($measure['ends_at'] ?? null, 'DISCIPLINE_INTERIM_WINDOW_INVALID');
            if ($now < $startsAt || $now >= $endsAt) {
                throw new DomainException('DISCIPLINE_INTERIM_AUTHORIZATION_WINDOW_INVALID');
            }
            if (!$this->repository->lockUser($actorId)
                || !$this->repository->activateInterim($measureId, $expectedLock, $actorId, $this->instant($now))) {
                throw new DomainException('DISCIPLINE_INTERIM_AUTHORIZATION_STALE');
            }
            $after = array_replace($measure, [
                'status' => 'active',
                'authorized_by_user_id' => $actorId,
                'authorized_at' => $this->instant($now),
                'lock_version' => $expectedLock + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_interim_authorized',
                'staff_discipline_interim_measures',
                $measureId,
                (string) ($measure['measure_type'] ?? ''),
                ['case_id' => (int) $case['id'], 'status' => 'active'],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->interimReceipt($after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function resolveInterimMeasure(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $measureId = $this->positiveId($command['measure_id'] ?? null, 'DISCIPLINE_INTERIM_ID_INVALID');
        $expectedLock = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_INTERIM_LOCK_INVALID'
        );
        $outcome = $this->enum(
            $command['outcome'] ?? null,
            ['expired', 'revoked', 'completed'],
            'DISCIPLINE_INTERIM_OUTCOME_INVALID'
        );
        $resolutionReason = $this->nullableText(
            $command['resolution_reason'] ?? null,
            10000,
            'DISCIPLINE_INTERIM_RESOLUTION_REASON_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $measureId,
            $expectedLock,
            $outcome,
            $resolutionReason,
            $now
        ): array {
            $measure = $this->requiredInterim($measureId);
            $case = $this->requiredCase(
                $this->positiveId($measure['case_id'] ?? null, 'DISCIPLINE_INTERIM_CASE_INVALID')
            );
            $action = $outcome === 'expired' ? 'expire_interim_measure' : 'review_interim_measure';
            $this->authorization->assertCanAct($actorId, $action, $case, $now);
            if ((string) ($measure['status'] ?? '') !== 'active'
                || (int) ($measure['lock_version'] ?? 0) !== $expectedLock) {
                throw new DomainException('DISCIPLINE_INTERIM_RESOLUTION_FORBIDDEN');
            }
            $endsAt = $this->requiredInstant($measure['ends_at'] ?? null, 'DISCIPLINE_INTERIM_WINDOW_INVALID');
            if ($outcome === 'expired') {
                if ($now < $endsAt) {
                    throw new DomainException('DISCIPLINE_INTERIM_NOT_EXPIRED');
                }
            } else {
                if ($now >= $endsAt || $resolutionReason === null) {
                    throw new DomainException('DISCIPLINE_INTERIM_RESOLUTION_FORBIDDEN');
                }
                if (in_array($actorId, [
                    (int) ($measure['requested_by_user_id'] ?? 0),
                    (int) ($measure['authorized_by_user_id'] ?? 0),
                ], true)) {
                    throw new DomainException('DISCIPLINE_INTERIM_REVIEWER_CONFLICT');
                }
            }
            if (!$this->repository->resolveInterim(
                $measureId,
                $expectedLock,
                $outcome,
                $actorId,
                $this->instant($now),
                $resolutionReason
            )) {
                throw new DomainException('DISCIPLINE_INTERIM_RESOLUTION_STALE');
            }
            $after = array_replace($measure, [
                'status' => $outcome,
                'reviewed_by_user_id' => $actorId,
                'reviewed_at' => $this->instant($now),
                'resolution_reason' => $resolutionReason,
                'lock_version' => $expectedLock + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_interim_resolved',
                'staff_discipline_interim_measures',
                $measureId,
                (string) ($measure['measure_type'] ?? ''),
                [
                    'case_id' => (int) $case['id'],
                    'status' => $outcome,
                    'resolution_reason_provided' => $resolutionReason !== null,
                    'access_effect_executed' => false,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->interimReceipt($after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function requestReopen(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $caseId = $this->positiveId($command['case_id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $priorDecisionId = $this->positiveId(
            $command['prior_decision_id'] ?? null,
            'DISCIPLINE_REOPEN_DECISION_INVALID'
        );
        $newEvidenceId = $this->positiveId(
            $command['new_evidence_id'] ?? null,
            'DISCIPLINE_REOPEN_EVIDENCE_INVALID'
        );
        $expectedCaseLock = $this->positiveId(
            $command['expected_case_lock_version'] ?? null,
            'DISCIPLINE_CASE_LOCK_INVALID'
        );
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'DISCIPLINE_REOPEN_IDEMPOTENCY_INVALID'
        );
        $reason = $this->requiredText(
            $command['reopen_reason'] ?? null,
            20000,
            'DISCIPLINE_REOPEN_REASON_REQUIRED'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $caseId,
            $priorDecisionId,
            $newEvidenceId,
            $expectedCaseLock,
            $idempotencyKey,
            $reason,
            $now
        ): array {
            $case = $this->requiredCase($caseId);
            $this->authorization->assertCanAct($actorId, 'request_reopen', $case, $now);
            $caseStatus = (string) ($case['status'] ?? '');
            if (!in_array($caseStatus, self::REOPEN_CASE_STATES, true)
                || (int) ($case['lock_version'] ?? 0) !== $expectedCaseLock) {
                throw new DomainException('DISCIPLINE_REOPEN_REQUEST_FORBIDDEN');
            }
            $decision = $this->requiredDecision($priorDecisionId);
            $evidence = $this->requiredEvidence($newEvidenceId);
            if ((int) ($decision['case_id'] ?? 0) !== $caseId
                || !in_array((string) ($decision['status'] ?? ''), ['issued', 'amended', 'revoked', 'superseded'], true)
                || (int) ($evidence['case_id'] ?? 0) !== $caseId) {
                throw new DomainException('DISCIPLINE_REOPEN_LINK_INVALID');
            }
            $input = $this->reopenInput(
                null,
                $case,
                $priorDecisionId,
                $newEvidenceId,
                'requested',
                $actorId,
                null,
                $reason,
                $idempotencyKey,
                $now
            );
            $existing = $this->repository->reopenEventByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['reopen_hash'] ?? ''), $input['reopen_hash'])) {
                    return $this->reopenReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_REOPEN_IDEMPOTENCY_CONFLICT');
            }
            $eventId = $this->repository->insertReopenEvent($input);
            if ($eventId <= 0) {
                throw new DomainException('DISCIPLINE_REOPEN_PERSIST_FAILED');
            }
            $stored = array_replace($input, ['id' => $eventId]);
            $this->audit->recordEvent(
                'staff_discipline_reopen_requested',
                'staff_discipline_reopen_events',
                $eventId,
                null,
                [
                    'case_id' => $caseId,
                    'prior_decision_id' => $priorDecisionId,
                    'new_evidence_id' => $newEvidenceId,
                    'prior_case_status' => $caseStatus,
                    'reason_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->reopenReceipt($stored, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function decideReopen(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $requestEventId = $this->positiveId(
            $command['request_event_id'] ?? null,
            'DISCIPLINE_REOPEN_REQUEST_INVALID'
        );
        $expectedCaseLock = $this->positiveId(
            $command['expected_case_lock_version'] ?? null,
            'DISCIPLINE_CASE_LOCK_INVALID'
        );
        $outcome = $this->enum(
            $command['outcome'] ?? null,
            ['authorized', 'rejected'],
            'DISCIPLINE_REOPEN_OUTCOME_INVALID'
        );
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'DISCIPLINE_REOPEN_IDEMPOTENCY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $requestEventId,
            $expectedCaseLock,
            $outcome,
            $idempotencyKey,
            $now
        ): array {
            $request = $this->requiredReopenEvent($requestEventId);
            if ((string) ($request['status'] ?? '') !== 'requested') {
                throw new DomainException('DISCIPLINE_REOPEN_REQUEST_INVALID');
            }
            $case = $this->requiredCase(
                $this->positiveId($request['case_id'] ?? null, 'DISCIPLINE_REOPEN_CASE_INVALID')
            );
            $this->authorization->assertCanAct($actorId, 'decide_reopen', $case, $now);
            if ((int) ($request['requested_by_user_id'] ?? 0) === $actorId) {
                throw new DomainException('DISCIPLINE_REOPEN_DECISION_FORBIDDEN');
            }
            $priorCase = array_replace($case, ['status' => (string) ($request['prior_case_status'] ?? '')]);
            $input = $this->reopenInput(
                $requestEventId,
                $priorCase,
                $this->positiveId($request['prior_decision_id'] ?? null, 'DISCIPLINE_REOPEN_DECISION_INVALID'),
                $this->positiveId($request['new_evidence_id'] ?? null, 'DISCIPLINE_REOPEN_EVIDENCE_INVALID'),
                $outcome,
                $this->positiveId($request['requested_by_user_id'] ?? null, 'DISCIPLINE_REOPEN_REQUEST_INVALID'),
                $outcome === 'authorized' ? $actorId : null,
                $this->requiredText($request['reopen_reason'] ?? null, 20000, 'DISCIPLINE_REOPEN_REASON_REQUIRED'),
                $idempotencyKey,
                $now
            );
            $existing = $this->repository->reopenEventByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['reopen_hash'] ?? ''), $input['reopen_hash'])) {
                    return $this->reopenReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_REOPEN_IDEMPOTENCY_CONFLICT');
            }
            if ((int) ($case['lock_version'] ?? 0) !== $expectedCaseLock
                || (string) ($case['status'] ?? '') !== (string) ($request['prior_case_status'] ?? '')
                || $this->repository->reopenResolutionForRequestForUpdate($requestEventId) !== null) {
                throw new DomainException('DISCIPLINE_REOPEN_ALREADY_DECIDED');
            }
            $eventId = $this->repository->insertReopenEvent($input);
            if ($eventId <= 0) {
                throw new DomainException('DISCIPLINE_REOPEN_PERSIST_FAILED');
            }
            if ($outcome === 'authorized'
                && !$this->repository->transitionCase(
                    (int) $case['id'],
                    $expectedCaseLock,
                    (string) $case['status'],
                    'reopened'
                )) {
                throw new DomainException('DISCIPLINE_CASE_STALE');
            }
            $stored = array_replace($input, ['id' => $eventId]);
            $this->audit->recordEvent(
                'staff_discipline_reopen_decided',
                'staff_discipline_reopen_events',
                $eventId,
                null,
                [
                    'request_event_id' => $requestEventId,
                    'case_id' => (int) $case['id'],
                    'status' => $outcome,
                    'new_evidence_id' => (int) $request['new_evidence_id'],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            if ($outcome === 'authorized') {
                $this->audit->recordEvent(
                    'staff_discipline_case_reopened',
                    'staff_discipline_cases',
                    (int) $case['id'],
                    (string) ($case['case_no'] ?? ''),
                    [
                        'previous_status' => (string) $case['status'],
                        'status' => 'reopened',
                        'reopen_event_id' => $eventId,
                    ],
                    ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
                );
            }

            return $this->reopenReceipt($stored, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @param array<string,mixed> $case
     * @param array<string,mixed> $decision
     * @param array{appeal_window_minutes:int,review_sla_minutes:int,suspends_execution:bool,suspension_reason:?string} $policy
     * @return array<string,mixed>
     */
    private function appealInput(
        array $command,
        int $actorId,
        array $case,
        array $decision,
        array $policy,
        string $idempotencyKey,
        DateTimeImmutable $now
    ): array {
        $issuedAt = $this->requiredInstant($decision['issued_at'] ?? null, 'DISCIPLINE_APPEAL_ISSUED_AT_INVALID');
        $deadline = $this->addMinutes($issuedAt, $policy['appeal_window_minutes']);
        if ($now > $deadline) {
            throw new DomainException('DISCIPLINE_APPEAL_WINDOW_EXPIRED');
        }
        $reason = $this->requiredText($command['appeal_reason'] ?? null, 20000, 'DISCIPLINE_APPEAL_REASON_REQUIRED');
        $dueAt = $this->addMinutes($now, $policy['review_sla_minutes']);
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_CASE_INVALID');
        $decisionId = $this->positiveId($decision['id'] ?? null, 'DISCIPLINE_DECISION_INVALID');
        $hash = $this->hash([
            'case_id' => $caseId,
            'decision_id' => $decisionId,
            'appellant_user_id' => $actorId,
            'appeal_reason' => $reason,
            'review_sla_minutes' => $policy['review_sla_minutes'],
            'suspends_execution' => $policy['suspends_execution'],
            'suspension_reason' => $policy['suspension_reason'],
            'decision_hash' => (string) ($decision['decision_hash'] ?? ''),
        ]);

        return [
            'case_id' => $caseId,
            'decision_id' => $decisionId,
            'appellant_user_id' => $actorId,
            'submitted_at' => $this->instant($now),
            'due_at' => $this->instant($dueAt),
            'appeal_reason' => $reason,
            'suspends_execution' => $policy['suspends_execution'] ? 1 : 0,
            'suspension_reason' => $policy['suspension_reason'],
            'idempotency_key' => $idempotencyKey,
            'appeal_hash' => $hash,
        ];
    }

    /** @param array<string,mixed> $command @param array<string,mixed> $case @return array<string,mixed> */
    private function interimInput(
        array $command,
        int $actorId,
        array $case,
        string $idempotencyKey
    ): array {
        $measureType = $this->requiredText(
            $command['measure_type'] ?? null,
            100,
            'DISCIPLINE_INTERIM_TYPE_REQUIRED'
        );
        $reason = $this->requiredText($command['reason'] ?? null, 20000, 'DISCIPLINE_INTERIM_REASON_REQUIRED');
        $basisEvidenceId = $this->nullablePositiveId($command['basis_evidence_id'] ?? null);
        $startsAt = $this->requiredInstant($command['starts_at'] ?? null, 'DISCIPLINE_INTERIM_WINDOW_INVALID');
        $endsAt = $this->requiredInstant($command['ends_at'] ?? null, 'DISCIPLINE_INTERIM_WINDOW_INVALID');
        $reviewDueAt = $this->nullableInstant($command['review_due_at'] ?? null, 'DISCIPLINE_INTERIM_REVIEW_DUE_INVALID');
        if ($endsAt <= $startsAt || ($reviewDueAt !== null && ($reviewDueAt < $startsAt || $reviewDueAt > $endsAt))) {
            throw new InvalidArgumentException('DISCIPLINE_INTERIM_WINDOW_INVALID');
        }
        $accessEffect = $command['access_effect'] ?? null;
        if ($accessEffect !== null && !is_array($accessEffect)) {
            throw new InvalidArgumentException('DISCIPLINE_INTERIM_ACCESS_EFFECT_INVALID');
        }
        $accessEffectJson = $accessEffect === null
            ? null
            : $this->json($accessEffect, 'DISCIPLINE_INTERIM_ACCESS_EFFECT_INVALID');
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_CASE_INVALID');
        $hash = $this->hash([
            'case_id' => $caseId,
            'basis_evidence_id' => $basisEvidenceId,
            'measure_type' => $measureType,
            'reason' => $reason,
            'access_effect' => $accessEffect,
            'starts_at' => $this->instant($startsAt),
            'ends_at' => $this->instant($endsAt),
            'review_due_at' => $reviewDueAt === null ? null : $this->instant($reviewDueAt),
            'requested_by_user_id' => $actorId,
        ]);

        return [
            'case_id' => $caseId,
            'basis_evidence_id' => $basisEvidenceId,
            'measure_type' => $measureType,
            'reason' => $reason,
            'access_effect' => $accessEffectJson,
            'requested_by_user_id' => $actorId,
            'starts_at' => $this->instant($startsAt),
            'ends_at' => $this->instant($endsAt),
            'review_due_at' => $reviewDueAt === null ? null : $this->instant($reviewDueAt),
            'idempotency_key' => $idempotencyKey,
            'measure_hash' => $hash,
        ];
    }

    /** @param array<string,mixed> $case @return array<string,mixed> */
    private function reopenInput(
        ?int $requestEventId,
        array $case,
        int $priorDecisionId,
        int $newEvidenceId,
        string $status,
        int $requestedByUserId,
        ?int $authorizedByUserId,
        string $reason,
        string $idempotencyKey,
        DateTimeImmutable $now
    ): array {
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_CASE_INVALID');
        $priorStatus = $this->enum(
            $case['status'] ?? null,
            self::REOPEN_CASE_STATES,
            'DISCIPLINE_REOPEN_CASE_STATUS_INVALID'
        );
        $hash = $this->hash([
            'request_event_id' => $requestEventId,
            'case_id' => $caseId,
            'prior_decision_id' => $priorDecisionId,
            'new_evidence_id' => $newEvidenceId,
            'prior_case_status' => $priorStatus,
            'status' => $status,
            'requested_by_user_id' => $requestedByUserId,
            'authorized_by_user_id' => $authorizedByUserId,
            'reopen_reason' => $reason,
        ]);

        return [
            'request_event_id' => $requestEventId,
            'case_id' => $caseId,
            'prior_decision_id' => $priorDecisionId,
            'new_evidence_id' => $newEvidenceId,
            'prior_case_status' => $priorStatus,
            'status' => $status,
            'requested_by_user_id' => $requestedByUserId,
            'requested_at' => $this->instant($now),
            'authorized_by_user_id' => $authorizedByUserId,
            'authorized_at' => $authorizedByUserId === null ? null : $this->instant($now),
            'reopen_reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'reopen_hash' => $hash,
        ];
    }

    /** @param array<string,mixed> $decision @return array{appeal_window_minutes:int,review_sla_minutes:int,suspends_execution:bool,suspension_reason:?string} */
    private function appealPolicy(array $decision): array
    {
        $snapshot = $decision['policy_snapshot'] ?? null;
        if (is_string($snapshot)) {
            try {
                $snapshot = json_decode($snapshot, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new DomainException('DISCIPLINE_APPEAL_POLICY_INVALID');
            }
        }
        if (!is_array($snapshot)) {
            throw new DomainException('DISCIPLINE_APPEAL_POLICY_INVALID');
        }
        $policy = $snapshot['appeal'] ?? $snapshot['appeal_policy'] ?? null;
        if (!is_array($policy)) {
            throw new DomainException('DISCIPLINE_APPEAL_POLICY_MISSING');
        }
        $window = $this->nonNegativeInt(
            $policy['appeal_window_minutes'] ?? null,
            'DISCIPLINE_APPEAL_POLICY_MISSING'
        );
        $reviewSla = $this->positiveId(
            $policy['review_sla_minutes'] ?? null,
            'DISCIPLINE_APPEAL_POLICY_MISSING'
        );
        $suspends = $this->boolean(
            $policy['suspend_execution_on_submission'] ?? null,
            'DISCIPLINE_APPEAL_POLICY_MISSING'
        );
        $suspensionReason = $this->nullableText(
            $policy['suspension_reason'] ?? null,
            10000,
            'DISCIPLINE_APPEAL_POLICY_INVALID'
        );
        if ($suspends && $suspensionReason === null) {
            throw new DomainException('DISCIPLINE_APPEAL_POLICY_MISSING');
        }
        if (!$suspends) {
            $suspensionReason = null;
        }

        return [
            'appeal_window_minutes' => $window,
            'review_sla_minutes' => $reviewSla,
            'suspends_execution' => $suspends,
            'suspension_reason' => $suspensionReason,
        ];
    }

    /** @param array<string,mixed> $decision @return array<string,mixed>|null */
    private function decisionInvestigation(array $decision): ?array
    {
        $investigationId = $this->nullablePositiveId($decision['investigation_id'] ?? null);

        return $investigationId === null ? null : $this->requiredInvestigation($investigationId);
    }

    /** @param array<string,mixed> $case @param array<string,mixed> $decision @param array<string,mixed> $appeal @param array<string,mixed>|null $investigation */
    private function assertReviewerSeparated(
        int $reviewerId,
        array $case,
        array $decision,
        array $appeal,
        ?array $investigation
    ): void {
        $conflicts = [
            $this->nullablePositiveId($case['subject_staff_user_id'] ?? null),
            $this->nullablePositiveId($case['incident_reported_by_user_id'] ?? null),
            $this->nullablePositiveId($case['opened_by_user_id'] ?? null),
            $this->nullablePositiveId($decision['decided_by_user_id'] ?? null),
            $this->nullablePositiveId($appeal['appellant_user_id'] ?? null),
            $investigation === null ? null : $this->nullablePositiveId($investigation['investigator_user_id'] ?? null),
        ];
        if (in_array($reviewerId, array_filter($conflicts), true)) {
            throw new DomainException('DISCIPLINE_APPEAL_REVIEWER_CONFLICT');
        }
    }

    /** @return array<string,mixed> */
    private function requiredCase(int $caseId): array
    {
        $case = $this->repository->caseForUpdate($caseId);
        if ($case === null) {
            throw new DomainException('DISCIPLINE_CASE_NOT_FOUND');
        }

        return $case;
    }

    /** @return array<string,mixed> */
    private function requiredDecision(int $decisionId): array
    {
        $decision = $this->repository->decisionForUpdate($decisionId);
        if ($decision === null) {
            throw new DomainException('DISCIPLINE_DECISION_NOT_FOUND');
        }

        return $decision;
    }

    /** @return array<string,mixed> */
    private function requiredInvestigation(int $investigationId): array
    {
        $investigation = $this->repository->investigationForUpdate($investigationId);
        if ($investigation === null) {
            throw new DomainException('DISCIPLINE_INVESTIGATION_NOT_FOUND');
        }

        return $investigation;
    }

    /** @return array<string,mixed> */
    private function requiredEvidence(int $evidenceId): array
    {
        $evidence = $this->repository->evidenceForUpdate($evidenceId);
        if ($evidence === null) {
            throw new DomainException('DISCIPLINE_EVIDENCE_NOT_FOUND');
        }

        return $evidence;
    }

    /** @return array<string,mixed> */
    private function requiredAppeal(int $appealId): array
    {
        $appeal = $this->repository->appealForUpdate($appealId);
        if ($appeal === null) {
            throw new DomainException('DISCIPLINE_APPEAL_NOT_FOUND');
        }

        return $appeal;
    }

    /** @return array<string,mixed> */
    private function requiredInterim(int $measureId): array
    {
        $measure = $this->repository->interimForUpdate($measureId);
        if ($measure === null) {
            throw new DomainException('DISCIPLINE_INTERIM_NOT_FOUND');
        }

        return $measure;
    }

    /** @return array<string,mixed> */
    private function requiredReopenEvent(int $eventId): array
    {
        $event = $this->repository->reopenEventForUpdate($eventId);
        if ($event === null) {
            throw new DomainException('DISCIPLINE_REOPEN_EVENT_NOT_FOUND');
        }

        return $event;
    }

    /** @param array<string,mixed> $appeal @return array<string,mixed> */
    private function appealReceipt(array $appeal, bool $replayed): array
    {
        return [
            'appeal_id' => $this->positiveId($appeal['id'] ?? null, 'DISCIPLINE_APPEAL_RECEIPT_INVALID'),
            'case_id' => $this->positiveId($appeal['case_id'] ?? null, 'DISCIPLINE_APPEAL_RECEIPT_INVALID'),
            'decision_id' => $this->positiveId($appeal['decision_id'] ?? null, 'DISCIPLINE_APPEAL_RECEIPT_INVALID'),
            'status' => (string) ($appeal['status'] ?? ''),
            'reviewer_user_id' => $this->nullablePositiveId($appeal['reviewer_user_id'] ?? null),
            'due_at' => $appeal['due_at'] ?? null,
            'suspends_execution' => (bool) ($appeal['suspends_execution'] ?? false),
            'lock_version' => $this->positiveId($appeal['lock_version'] ?? null, 'DISCIPLINE_APPEAL_RECEIPT_INVALID'),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $measure @return array<string,mixed> */
    private function interimReceipt(array $measure, bool $replayed): array
    {
        return [
            'measure_id' => $this->positiveId($measure['id'] ?? null, 'DISCIPLINE_INTERIM_RECEIPT_INVALID'),
            'case_id' => $this->positiveId($measure['case_id'] ?? null, 'DISCIPLINE_INTERIM_RECEIPT_INVALID'),
            'status' => (string) ($measure['status'] ?? ''),
            'starts_at' => $measure['starts_at'] ?? null,
            'ends_at' => $measure['ends_at'] ?? null,
            'lock_version' => $this->positiveId($measure['lock_version'] ?? null, 'DISCIPLINE_INTERIM_RECEIPT_INVALID'),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function reopenReceipt(array $event, bool $replayed): array
    {
        return [
            'reopen_event_id' => $this->positiveId($event['id'] ?? null, 'DISCIPLINE_REOPEN_RECEIPT_INVALID'),
            'request_event_id' => $this->nullablePositiveId($event['request_event_id'] ?? null),
            'case_id' => $this->positiveId($event['case_id'] ?? null, 'DISCIPLINE_REOPEN_RECEIPT_INVALID'),
            'status' => (string) ($event['status'] ?? ''),
            'prior_case_status' => (string) ($event['prior_case_status'] ?? ''),
            'replayed' => $replayed,
        ];
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function nonNegativeInt(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'DISCIPLINE_IDENTIFIER_INVALID');
    }

    private function requiredText(mixed $value, int $maximum, string $error): string
    {
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text) > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function nullableText(mixed $value, int $maximum, string $error): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->requiredText($value, $maximum, $error);
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $error): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function boolean(mixed $value, string $error): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new InvalidArgumentException($error);
    }

    private function nullableInstant(mixed $value, string $error): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requiredInstant($value, $error);
    }

    private function requiredInstant(mixed $value, string $error): DateTimeImmutable
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException($error);
        }
        try {
            return $value instanceof DateTimeImmutable
                ? $value->setTimezone($this->clockZone)
                : (new DateTimeImmutable((string) $value, $this->clockZone))->setTimezone($this->clockZone);
        } catch (\Throwable) {
            throw new InvalidArgumentException($error);
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->clockZone);
    }

    private function addMinutes(DateTimeImmutable $from, int $minutes): DateTimeImmutable
    {
        try {
            return $from->add(new DateInterval('PT' . $minutes . 'M'));
        } catch (\Throwable) {
            throw new InvalidArgumentException('DISCIPLINE_POLICY_DURATION_INVALID');
        }
    }

    private function instant(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone($this->clockZone)
            ->format('Y-m-d H:i:s.u');
    }

    private function json(mixed $value, string $error): string
    {
        try {
            return json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            throw new InvalidArgumentException($error);
        }
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', $this->json($value, 'DISCIPLINE_COMMAND_SERIALIZATION_INVALID'));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
