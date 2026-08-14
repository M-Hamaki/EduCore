<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Discipline;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectRepository;
use EduCore\Modules\Staff\Contracts\PayrollImpactGateway;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Owns immutable discipline Finance fact intents and their safe dispatch.
 *
 * The policy snapshot of an issued decision supplies a code, signed units, and
 * one payroll period. Staff never derives a salary amount or accesses Finance
 * tables. A reversal is a new fact referencing a prior accepted Staff fact;
 * the original fact remains immutable for Finance reconciliation.
 */
final class DisciplineFinanceEffectService implements DisciplineFinanceEffectQueue
{
    private const TARGET_MODULE = 'finance';
    private const OUTBOX_SCHEMA_VERSION = 1;
    private const LEASE_SECONDS = 300;
    private const RETRY_DELAY_SECONDS = 60;
    private const MAX_DISPATCH_BATCH_SIZE = 200;

    /** @var list<string> */
    private const APPLICABLE_CASE_STATES = ['decided', 'upheld'];

    /** @var list<string> */
    private const REVERSING_APPEAL_OUTCOMES = ['amended', 'revoked'];

    public function __construct(
        private DisciplineFinanceEffectRepository $repository,
        private ?PayrollImpactGateway $finance,
        private AuditEventWriter $audit,
        ?DateTimeZone $clockZone = null
    ) {
        $this->clockZone = $clockZone ?? new DateTimeZone('UTC');
    }

    private DateTimeZone $clockZone;

    /**
     * Persists one policy-frozen Staff fact for an issued decision.
     *
     * The method is safe inside the final approval transaction: it does not
     * contact Finance. An independent dispatcher calls dispatchEffect() only
     * after the durable Staff transaction succeeds.
     *
     * @return array{status:string,decision_id:int,effect_id:?int,replayed:bool}
     */
    public function queueForIssuedDecision(
        int $decisionId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        if ($decisionId <= 0) {
            throw new InvalidArgumentException('DISCIPLINE_FINANCE_DECISION_ID_INVALID');
        }
        if ($actorId <= 0) {
            throw new InvalidArgumentException('DISCIPLINE_FINANCE_ACTOR_ID_INVALID');
        }
        $occurredAt = ($occurredAt ?? $this->now())->setTimezone($this->clockZone);

        return $this->repository->transactional(function () use ($decisionId, $actorId, $occurredAt): array {
            $decision = $this->requiredDecision($decisionId);
            $case = $this->requiredCase(
                $this->positiveId($decision['case_id'] ?? null, 'DISCIPLINE_FINANCE_CASE_ID_INVALID')
            );
            $effect = $this->applyEffectInput($decision, $case, $actorId);
            if ($effect === null) {
                return [
                    'status' => 'not_applicable',
                    'decision_id' => $decisionId,
                    'effect_id' => null,
                    'replayed' => false,
                ];
            }

            $existing = $this->repository->effectByIdentityForUpdate(
                (string) $effect['effect_key'],
                (string) $effect['idempotency_key']
            );
            if ($existing !== null) {
                $this->assertEffectMatches($existing, $effect);

                return [
                    'status' => 'idempotent_replay',
                    'decision_id' => $decisionId,
                    'effect_id' => $this->positiveId($existing['id'] ?? null, 'DISCIPLINE_FINANCE_EFFECT_ID_INVALID'),
                    'replayed' => true,
                ];
            }

            $effectId = $this->repository->insertEffect($effect);
            if ($effectId <= 0) {
                throw new DomainException('DISCIPLINE_FINANCE_EFFECT_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_discipline_finance_effect_queued',
                'staff_discipline_finance_effects',
                $effectId,
                null,
                $this->safeEffectAuditDetails($effect),
                $this->auditContext($actorId, $occurredAt)
            );

            return [
                'status' => 'queued',
                'decision_id' => $decisionId,
                'effect_id' => $effectId,
                'replayed' => false,
            ];
        });
    }

    /**
     * Cancels an unclaimed apply fact or queues a new reversal fact after a
     * final amended/revoked appeal. The original decision and Finance fact are
     * never edited or deleted.
     *
     * @return array{status:string,appeal_id:int,effect_id:?int,reversed_effect_id:?int,replayed:bool}
     */
    public function queueReversalForResolvedAppeal(
        int $appealId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        if ($appealId <= 0) {
            throw new InvalidArgumentException('DISCIPLINE_FINANCE_APPEAL_ID_INVALID');
        }
        if ($actorId <= 0) {
            throw new InvalidArgumentException('DISCIPLINE_FINANCE_ACTOR_ID_INVALID');
        }
        $occurredAt = ($occurredAt ?? $this->now())->setTimezone($this->clockZone);

        return $this->repository->transactional(function () use ($appealId, $actorId, $occurredAt): array {
            $appeal = $this->requiredAppeal($appealId);
            $outcome = (string) ($appeal['status'] ?? '');
            if (!in_array($outcome, self::REVERSING_APPEAL_OUTCOMES, true)) {
                throw new DomainException('DISCIPLINE_FINANCE_APPEAL_NOT_REVERSING');
            }
            $case = $this->requiredCase(
                $this->positiveId($appeal['case_id'] ?? null, 'DISCIPLINE_FINANCE_CASE_ID_INVALID')
            );
            $decision = $this->requiredDecision(
                $this->positiveId($appeal['decision_id'] ?? null, 'DISCIPLINE_FINANCE_DECISION_ID_INVALID')
            );
            if ((int) ($decision['case_id'] ?? 0) !== (int) ($case['id'] ?? 0)
                || (int) ($appeal['case_id'] ?? 0) !== (int) ($case['id'] ?? 0)
                || (string) ($case['status'] ?? '') !== $outcome
                || (string) ($decision['status'] ?? '') !== 'issued') {
                throw new DomainException('DISCIPLINE_FINANCE_APPEAL_LINK_INVALID');
            }

            $applyEffects = $this->repository->applyEffectsForDecisionForUpdate(
                $this->positiveId($decision['id'] ?? null, 'DISCIPLINE_FINANCE_DECISION_ID_INVALID')
            );
            if ($applyEffects === []) {
                return $this->appealEffectReceipt('not_applicable', $appealId, null, null, false);
            }
            if (count($applyEffects) !== 1) {
                throw new DomainException('DISCIPLINE_FINANCE_APPLY_EFFECT_AMBIGUOUS');
            }
            $applyEffect = $applyEffects[0];
            $applyEffectId = $this->positiveId(
                $applyEffect['id'] ?? null,
                'DISCIPLINE_FINANCE_EFFECT_ID_INVALID'
            );
            $this->assertApplyEffectLinks($applyEffect, $case, $decision);

            $existingReversal = $this->repository->reversalForEffectForUpdate($applyEffectId);
            if ($existingReversal !== null) {
                $this->assertExistingReversalLinks($existingReversal, $applyEffect, $appeal);

                return $this->appealEffectReceipt(
                    'idempotent_replay',
                    $appealId,
                    $this->positiveId($existingReversal['id'] ?? null, 'DISCIPLINE_FINANCE_EFFECT_ID_INVALID'),
                    $applyEffectId,
                    true
                );
            }

            $applyStatus = (string) ($applyEffect['status'] ?? '');
            if (in_array($applyStatus, ['pending', 'retry'], true)) {
                if (!$this->repository->cancelQueuedApplyEffect(
                    $applyEffectId,
                    'DISCIPLINE_FINANCE_CANCELLED_BY_APPEAL_' . strtoupper($outcome)
                )) {
                    throw new DomainException('DISCIPLINE_FINANCE_EFFECT_STALE');
                }
                $this->audit->recordEvent(
                    'staff_discipline_finance_effect_cancelled',
                    'staff_discipline_finance_effects',
                    $applyEffectId,
                    null,
                    [
                        'case_id' => (int) $case['id'],
                        'decision_id' => (int) $decision['id'],
                        'appeal_id' => $appealId,
                        'appeal_outcome' => $outcome,
                        'direction' => 'apply',
                        'reason_code' => 'APPEAL_' . strtoupper($outcome),
                    ],
                    $this->auditContext($actorId, $occurredAt)
                );

                return $this->appealEffectReceipt(
                    'cancelled_unaccepted',
                    $appealId,
                    $applyEffectId,
                    null,
                    false
                );
            }
            if ($applyStatus === 'processing') {
                throw new DomainException('DISCIPLINE_FINANCE_EFFECT_DISPATCH_UNCERTAIN');
            }
            if ($applyStatus !== 'accepted') {
                return $this->appealEffectReceipt('not_applicable', $appealId, null, $applyEffectId, false);
            }

            $reversal = $this->reversalEffectInput($applyEffect, $appeal, $case, $decision, $actorId);
            $effectId = $this->repository->insertEffect($reversal);
            if ($effectId <= 0) {
                throw new DomainException('DISCIPLINE_FINANCE_EFFECT_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_discipline_finance_effect_reversal_queued',
                'staff_discipline_finance_effects',
                $effectId,
                null,
                $this->safeEffectAuditDetails($reversal) + [
                    'appeal_id' => $appealId,
                    'appeal_outcome' => $outcome,
                    'reverses_effect_id' => $applyEffectId,
                ],
                $this->auditContext($actorId, $occurredAt)
            );

            return $this->appealEffectReceipt('queued', $appealId, $effectId, $applyEffectId, false);
        });
    }

    /**
     * @return array{effect_id:int,accepted:bool,status:string,finance_reference:?string}
     */
    public function dispatchEffect(
        int $effectId,
        ?int $actorId = null,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        if ($effectId <= 0) {
            throw new InvalidArgumentException('DISCIPLINE_FINANCE_EFFECT_ID_INVALID');
        }
        if ($actorId !== null && $actorId <= 0) {
            throw new InvalidArgumentException('DISCIPLINE_FINANCE_ACTOR_ID_INVALID');
        }
        $occurredAt = ($occurredAt ?? $this->now())->setTimezone($this->clockZone);
        $claim = $this->claimForDispatch($effectId, $occurredAt);
        if (($claim['claimed'] ?? false) !== true) {
            return $this->dispatchReceipt((array) ($claim['effect'] ?? []));
        }

        /** @var array<string,mixed> $effect */
        $effect = $claim['effect'];
        $leaseToken = (string) $claim['lease_token'];
        if ($this->finance === null) {
            return $this->markRetry(
                $effectId,
                $leaseToken,
                $actorId,
                $occurredAt,
                'FINANCE_GATEWAY_UNAVAILABLE'
            );
        }

        try {
            $payload = $this->gatewayPayload($effect);
            $result = $this->finance->submitFacts(
                (string) $effect['effect_key'],
                $payload['staff_id'],
                (string) $effect['fact_type'],
                (string) $effect['units'],
                $payload['effective_period'],
                $payload['source_ref'],
                $payload['metadata']
            );
        } catch (Throwable) {
            return $this->markRetry(
                $effectId,
                $leaseToken,
                $actorId,
                $occurredAt,
                'FINANCE_GATEWAY_UNAVAILABLE'
            );
        }

        if (!is_array($result) || !array_key_exists('accepted', $result) || !is_bool($result['accepted'])) {
            return $this->markRetry(
                $effectId,
                $leaseToken,
                $actorId,
                $occurredAt,
                'FINANCE_GATEWAY_RESPONSE_INVALID'
            );
        }
        if ($result['accepted'] !== true) {
            if ($this->isPostedPeriodRejection($result['status'] ?? null)) {
                return $this->markRejected(
                    $effectId,
                    $leaseToken,
                    $actorId,
                    $occurredAt,
                    'FINANCE_PERIOD_CLOSED'
                );
            }

            return $this->markRetry(
                $effectId,
                $leaseToken,
                $actorId,
                $occurredAt,
                'FINANCE_GATEWAY_REJECTED'
            );
        }

        return $this->markAccepted(
            $effectId,
            $leaseToken,
            $actorId,
            $occurredAt,
            $this->nullableReference($result['finance_reference'] ?? null)
        );
    }

    /**
     * @return array{
     *     selected_effect_ids:list<int>,
     *     accepted_count:int,
     *     retry_count:int,
     *     rejected_count:int,
     *     skipped_count:int,
     *     receipts:list<array{effect_id:int,accepted:bool,status:string,finance_reference:?string}>
     * }
     */
    public function dispatchDueEffects(
        int $limit = 50,
        ?int $actorId = null,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        if ($limit <= 0 || $limit > self::MAX_DISPATCH_BATCH_SIZE) {
            throw new InvalidArgumentException('DISCIPLINE_FINANCE_DISPATCH_LIMIT_INVALID');
        }
        if ($actorId !== null && $actorId <= 0) {
            throw new InvalidArgumentException('DISCIPLINE_FINANCE_ACTOR_ID_INVALID');
        }
        $occurredAt = ($occurredAt ?? $this->now())->setTimezone($this->clockZone);
        $effectIds = $this->repository->dueEffectIdsForDispatch($limit, $this->instant($occurredAt));
        $receipts = [];
        $acceptedCount = 0;
        $retryCount = 0;
        $rejectedCount = 0;
        $skippedCount = 0;

        foreach ($effectIds as $effectId) {
            $effectId = $this->positiveId($effectId, 'DISCIPLINE_FINANCE_EFFECT_ID_INVALID');
            $receipt = $this->dispatchEffect($effectId, $actorId, $occurredAt);
            $receipts[] = $receipt;
            if ($receipt['accepted']) {
                ++$acceptedCount;
            } elseif ($receipt['status'] === 'retry') {
                ++$retryCount;
            } elseif ($receipt['status'] === 'rejected') {
                ++$rejectedCount;
            } else {
                ++$skippedCount;
            }
        }

        return [
            'selected_effect_ids' => array_values(array_map('intval', $effectIds)),
            'accepted_count' => $acceptedCount,
            'retry_count' => $retryCount,
            'rejected_count' => $rejectedCount,
            'skipped_count' => $skippedCount,
            'receipts' => $receipts,
        ];
    }

    /** @return array{claimed:bool,effect:array<string,mixed>,lease_token:?string} */
    private function claimForDispatch(int $effectId, DateTimeImmutable $occurredAt): array
    {
        return $this->repository->transactional(function () use ($effectId, $occurredAt): array {
            $effect = $this->requiredEffect($effectId);
            $status = (string) ($effect['status'] ?? '');
            if (!in_array($status, ['pending', 'retry', 'processing'], true)) {
                return ['claimed' => false, 'effect' => $effect, 'lease_token' => null];
            }

            $leaseToken = hash(
                'sha256',
                'staff-discipline-finance-lease:v1|' . $effectId . '|' . $this->instant($occurredAt)
                    . '|' . bin2hex(random_bytes(16))
            );
            $leaseExpiresAt = $this->instant($occurredAt->modify('+' . self::LEASE_SECONDS . ' seconds'));
            if (!$this->repository->claimEffect(
                $effectId,
                $leaseToken,
                $this->instant($occurredAt),
                $leaseExpiresAt
            )) {
                $after = $this->requiredEffect($effectId);

                return ['claimed' => false, 'effect' => $after, 'lease_token' => null];
            }

            return ['claimed' => true, 'effect' => $effect, 'lease_token' => $leaseToken];
        });
    }

    /** @return array{effect_id:int,accepted:bool,status:string,finance_reference:?string} */
    private function markAccepted(
        int $effectId,
        string $leaseToken,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
        ?string $reference
    ): array {
        return $this->repository->transactional(function () use (
            $effectId,
            $leaseToken,
            $actorId,
            $occurredAt,
            $reference
        ): array {
            $effect = $this->requiredEffect($effectId);
            if ((string) ($effect['status'] ?? '') === 'accepted') {
                return $this->dispatchReceipt($effect);
            }
            if (!$this->repository->markEffectAccepted(
                $effectId,
                $leaseToken,
                $reference,
                $this->instant($occurredAt)
            )) {
                return $this->dispatchReceipt($this->requiredEffect($effectId));
            }

            $this->audit->recordEvent(
                'staff_discipline_finance_effect_accepted',
                'staff_discipline_finance_effects',
                $effectId,
                null,
                $this->safeEffectAuditDetails($effect) + [
                    'finance_reference_present' => $reference !== null,
                ],
                $this->auditContext($actorId, $occurredAt)
            );

            return [
                'effect_id' => $effectId,
                'accepted' => true,
                'status' => 'accepted',
                'finance_reference' => $reference,
            ];
        });
    }

    /** @return array{effect_id:int,accepted:bool,status:string,finance_reference:?string} */
    private function markRetry(
        int $effectId,
        string $leaseToken,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
        string $reasonCode
    ): array {
        return $this->repository->transactional(function () use (
            $effectId,
            $leaseToken,
            $actorId,
            $occurredAt,
            $reasonCode
        ): array {
            $effect = $this->requiredEffect($effectId);
            if (!$this->repository->markEffectForRetry(
                $effectId,
                $leaseToken,
                $reasonCode,
                $this->instant($occurredAt->modify('+' . self::RETRY_DELAY_SECONDS . ' seconds'))
            )) {
                return $this->dispatchReceipt($this->requiredEffect($effectId));
            }

            $this->audit->recordEvent(
                'staff_discipline_finance_effect_retry_queued',
                'staff_discipline_finance_effects',
                $effectId,
                null,
                $this->safeEffectAuditDetails($effect) + ['reason_code' => $reasonCode],
                $this->auditContext($actorId, $occurredAt)
            );

            return [
                'effect_id' => $effectId,
                'accepted' => false,
                'status' => 'retry',
                'finance_reference' => null,
            ];
        });
    }

    /** @return array{effect_id:int,accepted:bool,status:string,finance_reference:?string} */
    private function markRejected(
        int $effectId,
        string $leaseToken,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
        string $reasonCode
    ): array {
        return $this->repository->transactional(function () use (
            $effectId,
            $leaseToken,
            $actorId,
            $occurredAt,
            $reasonCode
        ): array {
            $effect = $this->requiredEffect($effectId);
            if (!$this->repository->markEffectRejected($effectId, $leaseToken, $reasonCode)) {
                return $this->dispatchReceipt($this->requiredEffect($effectId));
            }

            $this->audit->recordEvent(
                'staff_discipline_finance_effect_rejected',
                'staff_discipline_finance_effects',
                $effectId,
                null,
                $this->safeEffectAuditDetails($effect) + ['reason_code' => $reasonCode],
                $this->auditContext($actorId, $occurredAt)
            );

            return [
                'effect_id' => $effectId,
                'accepted' => false,
                'status' => 'rejected',
                'finance_reference' => null,
            ];
        });
    }

    /** @param array<string,mixed> $decision @param array<string,mixed> $case @return array<string,mixed>|null */
    private function applyEffectInput(array $decision, array $case, int $actorId): ?array
    {
        $decisionId = $this->positiveId($decision['id'] ?? null, 'DISCIPLINE_FINANCE_DECISION_ID_INVALID');
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_FINANCE_CASE_ID_INVALID');
        if ((int) ($decision['case_id'] ?? 0) !== $caseId
            || (string) ($decision['status'] ?? '') !== 'issued') {
            throw new DomainException('DISCIPLINE_FINANCE_DECISION_NOT_ISSUED');
        }
        if (!$this->databaseBoolean($decision['financial_effect_requested'] ?? null)) {
            return null;
        }
        if (!in_array((string) ($case['status'] ?? ''), self::APPLICABLE_CASE_STATES, true)) {
            throw new DomainException('DISCIPLINE_FINANCE_CASE_NOT_EXECUTABLE');
        }

        $decisionHash = $this->requiredHash(
            $decision['decision_hash'] ?? null,
            'DISCIPLINE_FINANCE_DECISION_HASH_INVALID'
        );
        $subjectId = $this->positiveId(
            $case['subject_staff_user_id'] ?? null,
            'DISCIPLINE_FINANCE_SUBJECT_ID_INVALID'
        );
        $sanctionCode = $this->requiredCode(
            $decision['sanction_code'] ?? null,
            'DISCIPLINE_FINANCE_SANCTION_CODE_INVALID'
        );
        $snapshot = $this->jsonObject(
            $decision['policy_snapshot'] ?? null,
            'DISCIPLINE_FINANCE_POLICY_SNAPSHOT_INVALID'
        );
        $financeEffect = $snapshot['finance_effect'] ?? null;
        if (!is_array($financeEffect)) {
            throw new DomainException('DISCIPLINE_FINANCE_POLICY_SNAPSHOT_INVALID');
        }
        $factType = $this->requiredCode(
            $financeEffect['fact_type'] ?? null,
            'DISCIPLINE_FINANCE_POLICY_SNAPSHOT_INVALID'
        );
        $effectCode = $this->requiredCode(
            $financeEffect['effect_code'] ?? null,
            'DISCIPLINE_FINANCE_POLICY_SNAPSHOT_INVALID'
        );
        $units = $this->nonZeroUnits(
            $financeEffect['units'] ?? null,
            'DISCIPLINE_FINANCE_POLICY_SNAPSHOT_INVALID'
        );
        $effectiveFrom = $this->date(
            $financeEffect['effective_from'] ?? null,
            'DISCIPLINE_FINANCE_POLICY_SNAPSHOT_INVALID'
        );
        $effectiveTo = $this->nullableDate(
            $financeEffect['effective_to'] ?? null,
            'DISCIPLINE_FINANCE_POLICY_SNAPSHOT_INVALID'
        );
        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw new DomainException('DISCIPLINE_FINANCE_POLICY_SNAPSHOT_INVALID');
        }
        $effectivePeriod = $this->period(
            $financeEffect['effective_period'] ?? null,
            'DISCIPLINE_FINANCE_POLICY_SNAPSHOT_INVALID'
        );
        if ($effectivePeriod !== substr($effectiveFrom, 0, 7)
            || ($effectiveTo !== null && $effectivePeriod !== substr($effectiveTo, 0, 7))) {
            throw new DomainException('DISCIPLINE_FINANCE_POLICY_PERIOD_INVALID');
        }

        $policyFingerprint = hash('sha256', $this->canonicalJson($financeEffect));
        $identity = [
            'schema_version' => self::OUTBOX_SCHEMA_VERSION,
            'case_id' => $caseId,
            'decision_id' => $decisionId,
            'decision_hash' => $decisionHash,
            'fact_type' => $factType,
            'effect_code' => $effectCode,
            'direction' => 'apply',
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'effective_period' => $effectivePeriod,
            'units' => $units,
            'policy_fingerprint' => $policyFingerprint,
        ];
        $effectKey = hash('sha256', 'staff-discipline-finance:v1|' . $this->canonicalJson($identity));
        $idempotencyKey = hash('sha256', 'staff-discipline-finance-idempotency:v1|' . $effectKey);
        $payload = [
            'schema_version' => self::OUTBOX_SCHEMA_VERSION,
            'staff_user_id' => $subjectId,
            'effective_period' => $effectivePeriod,
            'source_ref' => 'staff_discipline_decision:' . $decisionId,
            'metadata' => [
                'direction' => 'apply',
                'case_id' => $caseId,
                'decision_id' => $decisionId,
                'sanction_code' => $sanctionCode,
                'effect_code' => $effectCode,
                'policy_fingerprint' => $policyFingerprint,
            ],
        ];

        return [
            'case_id' => $caseId,
            'decision_id' => $decisionId,
            'execution_id' => null,
            'reverses_effect_id' => null,
            'target_module' => self::TARGET_MODULE,
            'fact_type' => $factType,
            'effect_code' => $effectCode,
            'effect_key' => $effectKey,
            'idempotency_key' => $idempotencyKey,
            'direction' => 'apply',
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'units' => $units,
            'payload_json' => $this->canonicalJson($payload),
            'status' => 'pending',
            'created_by_user_id' => $actorId,
        ];
    }

    /** @param array<string,mixed> $applyEffect @param array<string,mixed> $appeal @param array<string,mixed> $case @param array<string,mixed> $decision @return array<string,mixed> */
    private function reversalEffectInput(
        array $applyEffect,
        array $appeal,
        array $case,
        array $decision,
        int $actorId
    ): array {
        $applyEffectId = $this->positiveId($applyEffect['id'] ?? null, 'DISCIPLINE_FINANCE_EFFECT_ID_INVALID');
        $appealId = $this->positiveId($appeal['id'] ?? null, 'DISCIPLINE_FINANCE_APPEAL_ID_INVALID');
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_FINANCE_CASE_ID_INVALID');
        $decisionId = $this->positiveId($decision['id'] ?? null, 'DISCIPLINE_FINANCE_DECISION_ID_INVALID');
        $appealHash = $this->requiredHash(
            $appeal['appeal_hash'] ?? null,
            'DISCIPLINE_FINANCE_APPEAL_HASH_INVALID'
        );
        $payload = $this->jsonObject(
            $applyEffect['payload_json'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
        );
        $subjectId = $this->positiveId(
            $payload['staff_user_id'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
        );
        if ($subjectId !== $this->positiveId(
            $case['subject_staff_user_id'] ?? null,
            'DISCIPLINE_FINANCE_SUBJECT_ID_INVALID'
        )) {
            throw new DomainException('DISCIPLINE_FINANCE_EFFECT_SUBJECT_MISMATCH');
        }
        $effectivePeriod = $this->period(
            $payload['effective_period'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
        );
        $factType = $this->requiredCode(
            $applyEffect['fact_type'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_INVALID'
        );
        $effectCode = $this->requiredCode(
            $applyEffect['effect_code'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_INVALID'
        );
        $effectiveFrom = $this->date(
            $applyEffect['effective_from'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_INVALID'
        );
        $effectiveTo = $this->nullableDate(
            $applyEffect['effective_to'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_INVALID'
        );
        $units = $this->negateUnits(
            $this->nonZeroUnits($applyEffect['units'] ?? null, 'DISCIPLINE_FINANCE_EFFECT_INVALID')
        );
        $applyEffectKey = $this->requiredHash(
            $applyEffect['effect_key'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_INVALID'
        );
        $outcome = (string) ($appeal['status'] ?? '');
        $identity = [
            'schema_version' => self::OUTBOX_SCHEMA_VERSION,
            'reverses_effect_id' => $applyEffectId,
            'effect_key' => $applyEffectKey,
            'appeal_id' => $appealId,
            'appeal_hash' => $appealHash,
            'appeal_outcome' => $outcome,
            'direction' => 'reverse',
            'units' => $units,
        ];
        $effectKey = hash('sha256', 'staff-discipline-finance-reversal:v1|' . $this->canonicalJson($identity));
        $idempotencyKey = hash('sha256', 'staff-discipline-finance-reversal-idempotency:v1|' . $effectKey);
        $sourceMetadata = $payload['metadata'] ?? null;
        if (!is_array($sourceMetadata)) {
            throw new DomainException('DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID');
        }
        $metadata = [
            'direction' => 'reverse',
            'case_id' => $caseId,
            'decision_id' => $decisionId,
            'appeal_id' => $appealId,
            'appeal_outcome' => $outcome,
            'effect_code' => $effectCode,
            'reverses_effect_id' => $applyEffectId,
            'original_effect_key' => $applyEffectKey,
        ];
        if (isset($sourceMetadata['sanction_code']) && is_string($sourceMetadata['sanction_code'])) {
            $metadata['sanction_code'] = $this->requiredCode(
                $sourceMetadata['sanction_code'],
                'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
            );
        }
        if (isset($sourceMetadata['policy_fingerprint']) && is_string($sourceMetadata['policy_fingerprint'])) {
            $metadata['policy_fingerprint'] = $this->requiredHash(
                $sourceMetadata['policy_fingerprint'],
                'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
            );
        }

        return [
            'case_id' => $caseId,
            'decision_id' => $decisionId,
            'execution_id' => null,
            'reverses_effect_id' => $applyEffectId,
            'target_module' => self::TARGET_MODULE,
            'fact_type' => $factType,
            'effect_code' => $effectCode,
            'effect_key' => $effectKey,
            'idempotency_key' => $idempotencyKey,
            'direction' => 'reverse',
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'units' => $units,
            'payload_json' => $this->canonicalJson([
                'schema_version' => self::OUTBOX_SCHEMA_VERSION,
                'staff_user_id' => $subjectId,
                'effective_period' => $effectivePeriod,
                'source_ref' => 'staff_discipline_appeal:' . $appealId,
                'metadata' => $metadata,
            ]),
            'status' => 'pending',
            'created_by_user_id' => $actorId,
        ];
    }

    /** @param array<string,mixed> $effect @return array{staff_id:int,effective_period:string,source_ref:string,metadata:array<string,mixed>} */
    private function gatewayPayload(array $effect): array
    {
        $payload = $this->jsonObject(
            $effect['payload_json'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
        );
        $staffId = $this->positiveId(
            $payload['staff_user_id'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
        );
        $effectivePeriod = $this->period(
            $payload['effective_period'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
        );
        $sourceRef = $this->safeSourceReference(
            $payload['source_ref'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
        );
        $metadata = $payload['metadata'] ?? null;
        if (!is_array($metadata)) {
            throw new DomainException('DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID');
        }

        return [
            'staff_id' => $staffId,
            'effective_period' => $effectivePeriod,
            'source_ref' => $sourceRef,
            'metadata' => $metadata,
        ];
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $expected */
    private function assertEffectMatches(array $existing, array $expected): void
    {
        foreach ([
            'case_id',
            'decision_id',
            'target_module',
            'fact_type',
            'effect_code',
            'effect_key',
            'idempotency_key',
            'direction',
            'effective_from',
            'effective_to',
            'units',
        ] as $field) {
            if (!$this->sameNullableScalar($existing[$field] ?? null, $expected[$field] ?? null)) {
                throw new DomainException('DISCIPLINE_FINANCE_EFFECT_IDEMPOTENCY_CONFLICT');
            }
        }
        $existingPayload = $this->canonicalJson($this->jsonObject(
            $existing['payload_json'] ?? null,
            'DISCIPLINE_FINANCE_EFFECT_PAYLOAD_INVALID'
        ));
        if (!hash_equals($existingPayload, (string) $expected['payload_json'])) {
            throw new DomainException('DISCIPLINE_FINANCE_EFFECT_IDEMPOTENCY_CONFLICT');
        }
    }

    /** @param array<string,mixed> $applyEffect @param array<string,mixed> $case @param array<string,mixed> $decision */
    private function assertApplyEffectLinks(array $applyEffect, array $case, array $decision): void
    {
        if ((string) ($applyEffect['direction'] ?? '') !== 'apply'
            || (int) ($applyEffect['case_id'] ?? 0) !== (int) ($case['id'] ?? 0)
            || (int) ($applyEffect['decision_id'] ?? 0) !== (int) ($decision['id'] ?? 0)
            || ($applyEffect['reverses_effect_id'] ?? null) !== null) {
            throw new DomainException('DISCIPLINE_FINANCE_EFFECT_LINK_INVALID');
        }
    }

    /** @param array<string,mixed> $reversal @param array<string,mixed> $applyEffect @param array<string,mixed> $appeal */
    private function assertExistingReversalLinks(array $reversal, array $applyEffect, array $appeal): void
    {
        if ((string) ($reversal['direction'] ?? '') !== 'reverse'
            || (int) ($reversal['reverses_effect_id'] ?? 0) !== (int) ($applyEffect['id'] ?? 0)
            || (int) ($reversal['case_id'] ?? 0) !== (int) ($appeal['case_id'] ?? 0)
            || (int) ($reversal['decision_id'] ?? 0) !== (int) ($appeal['decision_id'] ?? 0)) {
            throw new DomainException('DISCIPLINE_FINANCE_REVERSAL_LINK_INVALID');
        }
    }

    /** @param array<string,mixed> $effect @return array{effect_id:int,accepted:bool,status:string,finance_reference:?string} */
    private function dispatchReceipt(array $effect): array
    {
        $effectId = $this->positiveId($effect['id'] ?? null, 'DISCIPLINE_FINANCE_EFFECT_ID_INVALID');
        $status = (string) ($effect['status'] ?? 'unavailable');

        return [
            'effect_id' => $effectId,
            'accepted' => $status === 'accepted',
            'status' => $status,
            'finance_reference' => $this->nullableReference($effect['accepted_reference'] ?? null),
        ];
    }

    /** @return array{status:string,appeal_id:int,effect_id:?int,reversed_effect_id:?int,replayed:bool} */
    private function appealEffectReceipt(
        string $status,
        int $appealId,
        ?int $effectId,
        ?int $reversedEffectId,
        bool $replayed
    ): array {
        return [
            'status' => $status,
            'appeal_id' => $appealId,
            'effect_id' => $effectId,
            'reversed_effect_id' => $reversedEffectId,
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $effect @return array<string,mixed> */
    private function safeEffectAuditDetails(array $effect): array
    {
        return [
            'case_id' => $this->positiveId($effect['case_id'] ?? null, 'DISCIPLINE_FINANCE_CASE_ID_INVALID'),
            'decision_id' => $this->positiveId($effect['decision_id'] ?? null, 'DISCIPLINE_FINANCE_DECISION_ID_INVALID'),
            'target_module' => self::TARGET_MODULE,
            'fact_type' => (string) ($effect['fact_type'] ?? ''),
            'effect_code' => (string) ($effect['effect_code'] ?? ''),
            'direction' => (string) ($effect['direction'] ?? ''),
            'units' => (string) ($effect['units'] ?? ''),
            'effective_from' => (string) ($effect['effective_from'] ?? ''),
            'effective_to_present' => ($effect['effective_to'] ?? null) !== null,
            'reverses_effect_id' => $this->nullablePositiveId($effect['reverses_effect_id'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function auditContext(?int $actorId, DateTimeImmutable $occurredAt): array
    {
        $context = ['occurred_at' => $this->instant($occurredAt)];
        if ($actorId === null) {
            $context['actor_scope'] = 'system';
        } else {
            $context['user_id'] = $actorId;
        }

        return $context;
    }

    private function isPostedPeriodRejection(mixed $status): bool
    {
        if (!is_string($status)) {
            return false;
        }

        return in_array(strtolower(trim($status)), ['period_closed', 'closed_period', 'posted_period'], true);
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
    private function requiredCase(int $caseId): array
    {
        $case = $this->repository->caseForUpdate($caseId);
        if ($case === null) {
            throw new DomainException('DISCIPLINE_CASE_NOT_FOUND');
        }

        return $case;
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
    private function requiredEffect(int $effectId): array
    {
        $effect = $this->repository->effectForUpdate($effectId);
        if ($effect === null) {
            throw new DomainException('DISCIPLINE_FINANCE_EFFECT_NOT_FOUND');
        }

        return $effect;
    }

    private function databaseBoolean(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }

        throw new DomainException('DISCIPLINE_FINANCE_DECISION_FLAG_INVALID');
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'DISCIPLINE_FINANCE_IDENTIFIER_INVALID');
    }

    private function requiredCode(mixed $value, string $error): string
    {
        if (!is_string($value)) {
            throw new DomainException($error);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new DomainException($error);
        }

        return $value;
    }

    private function requiredHash(mixed $value, string $error): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/i', $value) !== 1) {
            throw new DomainException($error);
        }

        return strtolower($value);
    }

    private function nonZeroUnits(mixed $value, string $error): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new DomainException($error);
        }
        $value = trim((string) $value);
        if (preg_match('/\A-?(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,3})?\z/', $value) !== 1) {
            throw new DomainException($error);
        }
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $normalized = ltrim($whole, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $normalized .= '.' . str_pad($fraction, 3, '0');
        if ($normalized === '0.000') {
            throw new DomainException($error);
        }

        return $negative ? '-' . $normalized : $normalized;
    }

    private function negateUnits(string $units): string
    {
        return str_starts_with($units, '-') ? substr($units, 1) : '-' . $units;
    }

    private function date(mixed $value, string $error): string
    {
        if (!is_string($value)) {
            throw new DomainException($error);
        }
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->clockZone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $value) {
            throw new DomainException($error);
        }

        return $value;
    }

    private function nullableDate(mixed $value, string $error): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->date($value, $error);
    }

    private function period(mixed $value, string $error): string
    {
        if (!is_string($value)) {
            throw new DomainException($error);
        }
        $value = trim($value);
        if (preg_match('/\A(19|20)[0-9]{2}-(0[1-9]|1[0-2])\z/', $value) !== 1) {
            throw new DomainException($error);
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value, string $error): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new DomainException($error, 0, $exception);
            }
        } else {
            throw new DomainException($error);
        }
        if (!is_array($decoded) || $this->isList($decoded)) {
            throw new DomainException($error);
        }

        return $decoded;
    }

    private function safeSourceReference(mixed $value, string $error): string
    {
        if (!is_string($value)) {
            throw new DomainException($error);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new DomainException($error);
        }

        return $value;
    }

    private function nullableReference(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->safeSourceReference($value, 'DISCIPLINE_FINANCE_REFERENCE_INVALID');
    }

    private function sameNullableScalar(mixed $left, mixed $right): bool
    {
        if ($left === null || $left === '') {
            return $right === null || $right === '';
        }
        if ($right === null || $right === '') {
            return false;
        }

        return (string) $left === (string) $right;
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new DomainException('DISCIPLINE_FINANCE_JSON_INVALID', 0, $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($this->isList($value)) {
                return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonicalize($item);
            }

            return $value;
        }
        if (is_float($value) && !is_finite($value)) {
            throw new DomainException('DISCIPLINE_FINANCE_JSON_INVALID');
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new DomainException('DISCIPLINE_FINANCE_JSON_INVALID');
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            ++$expected;
        }

        return true;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->clockZone);
    }

    private function instant(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone($this->clockZone)
            ->format('Y-m-d H:i:s.u');
    }
}
