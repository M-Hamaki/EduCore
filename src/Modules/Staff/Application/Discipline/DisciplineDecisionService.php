<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Discipline;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowResolutionGateway;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use EduCore\Modules\Staff\Contracts\DisciplineDecisionRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Proposes an immutable discipline decision and records a worker receipt.
 *
 * Issuance itself belongs to DisciplineDecisionApprovalOutcomeHandler, which
 * is called only by the shared approval workflow once all configured stages
 * have completed. This service deliberately does not write Finance effects.
 */
final class DisciplineDecisionService
{
    private DateTimeZone $clockZone;

    public function __construct(
        private DisciplineDecisionRepository $repository,
        private DisciplineCaseAuthorization $authorization,
        private ApprovalWorkflowResolutionGateway $workflowResolver,
        private ApprovalWorkflowSubmissionGateway $approvalWorkflow,
        private AuditEventWriter $audit,
        ?DateTimeZone $clockZone = null
    ) {
        $this->clockZone = $clockZone ?? new DateTimeZone('UTC');
    }

    /**
     * Creates a proposed decision, freezes its approval workflow, and submits
     * it to the shared approval state machine in one transaction.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function proposeDecision(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $caseId = $this->positiveId($command['case_id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $investigationId = $this->positiveId(
            $command['investigation_id'] ?? null,
            'DISCIPLINE_INVESTIGATION_ID_INVALID'
        );
        $expectedCaseLock = $this->positiveId(
            $command['expected_case_lock_version'] ?? null,
            'DISCIPLINE_CASE_LOCK_INVALID'
        );
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'DISCIPLINE_DECISION_IDEMPOTENCY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $command,
            $actorId,
            $caseId,
            $investigationId,
            $expectedCaseLock,
            $idempotencyKey,
            $now
        ): array {
            $case = $this->requiredCase($caseId);
            $this->authorization->assertCanAct($actorId, 'propose_decision', $case, $now);
            $input = $this->decisionInput($command, $actorId, $case, $investigationId, $idempotencyKey, $now);
            $existing = $this->repository->decisionByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['decision_hash'] ?? ''), $input['decision_hash'])) {
                    return $this->decisionReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_DECISION_IDEMPOTENCY_CONFLICT');
            }

            $caseStatus = (string) ($case['status'] ?? '');
            if (!in_array($caseStatus, ['under_investigation', 'pending_decision'], true)
                || (int) ($case['lock_version'] ?? 0) !== $expectedCaseLock) {
                throw new DomainException('DISCIPLINE_CASE_DECISION_PROPOSAL_FORBIDDEN');
            }
            $subjectId = $this->positiveId(
                $case['subject_staff_user_id'] ?? null,
                'DISCIPLINE_DECISION_SUBJECT_REQUIRED'
            );
            if (!$this->repository->lockUser($subjectId)) {
                throw new DomainException('DISCIPLINE_DECISION_SUBJECT_NOT_FOUND');
            }
            if (!$this->repository->lockUser($actorId)) {
                throw new DomainException('DISCIPLINE_ACTOR_NOT_FOUND');
            }

            $investigation = $this->requiredInvestigation($investigationId);
            if ((int) ($investigation['case_id'] ?? 0) !== $caseId
                || (string) ($investigation['status'] ?? '') !== 'completed') {
                throw new DomainException('DISCIPLINE_DECISION_INVESTIGATION_NOT_READY');
            }
            $this->assertPreparerSeparated($actorId, $case, $investigation);

            $workflow = $this->normalizedWorkflow(
                $this->workflowResolver->resolveForResource(
                    'discipline_case',
                    $subjectId,
                    [
                        'case_id' => $caseId,
                        'subject_staff_user_id' => $subjectId,
                        'discipline_decision' => true,
                    ],
                    $input['effective_at'],
                    $now
                )
            );

            $sequence = $this->repository->nextDecisionSequenceForUpdate($caseId);
            if ($sequence <= 0) {
                throw new DomainException('DISCIPLINE_DECISION_SEQUENCE_INVALID');
            }
            $toStore = array_replace($input, [
                'decision_sequence' => $sequence,
                'status' => 'proposed',
                'prepared_by_user_id' => $actorId,
                'notification_status' => 'pending',
            ]);
            $decisionId = $this->repository->insertDecision($toStore);
            if ($decisionId <= 0) {
                throw new DomainException('DISCIPLINE_DECISION_PERSIST_FAILED');
            }

            $caseTransitioned = false;
            if ($caseStatus === 'under_investigation') {
                if (!$this->repository->transitionCase(
                    $caseId,
                    $expectedCaseLock,
                    'under_investigation',
                    'pending_decision'
                )) {
                    throw new DomainException('DISCIPLINE_CASE_STALE');
                }
                $caseTransitioned = true;
            }

            $workflow['snapshot']['context']['discipline_decision_id'] = $decisionId;
            $workflow['snapshot']['context']['discipline_case_id'] = $caseId;
            $approval = $this->approvalWorkflow->submit([
                'actor_id' => $actorId,
                'resource_type' => 'discipline_case',
                'resource_id' => $caseId,
                'workflow_version_id' => $workflow['workflow_version_id'],
                'snapshot' => $workflow['snapshot'],
                'idempotency_key' => $this->approvalSubmissionIdempotencyKey($idempotencyKey),
                'submitted_at' => $now,
            ]);
            $workflowInstanceId = $this->positiveId(
                $approval['instance_id'] ?? null,
                'DISCIPLINE_DECISION_APPROVAL_INSTANCE_INVALID'
            );
            if (!$this->repository->attachWorkflowInstance($decisionId, 1, $workflowInstanceId)) {
                throw new DomainException('DISCIPLINE_DECISION_STALE');
            }

            $stored = array_replace($toStore, [
                'id' => $decisionId,
                'workflow_instance_id' => $workflowInstanceId,
                'lock_version' => 2,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_decision_proposed',
                'staff_discipline_decisions',
                $decisionId,
                (string) $stored['decision_no'],
                [
                    'case_id' => $caseId,
                    'investigation_id' => $investigationId,
                    'workflow_instance_id' => $workflowInstanceId,
                    'workflow_version_id' => $workflow['workflow_version_id'],
                    'decision_hash' => $stored['decision_hash'],
                    'sanction_code' => $stored['sanction_code'],
                    'financial_effect_requested' => $stored['financial_effect_requested'],
                    'reason_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            if ($caseTransitioned) {
                $this->audit->recordEvent(
                    'staff_discipline_case_pending_decision',
                    'staff_discipline_cases',
                    $caseId,
                    (string) ($case['case_no'] ?? ''),
                    [
                        'previous_status' => 'under_investigation',
                        'status' => 'pending_decision',
                        'decision_id' => $decisionId,
                    ],
                    ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
                );
            }

            return $this->decisionReceipt($stored, false);
        });
    }

    /**
     * The subject acknowledges an already delivered final decision. Replaying
     * an existing receipt is safe and does not create a second legal record.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function acknowledgeReceipt(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $decisionId = $this->positiveId($command['decision_id'] ?? null, 'DISCIPLINE_DECISION_ID_INVALID');
        $expectedLock = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_DECISION_LOCK_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($actorId, $decisionId, $expectedLock, $now): array {
            $decision = $this->requiredDecision($decisionId);
            $case = $this->requiredCase(
                $this->positiveId($decision['case_id'] ?? null, 'DISCIPLINE_DECISION_CASE_INVALID')
            );
            $this->authorization->assertCanAct($actorId, 'acknowledge_decision_receipt', $case, $now);
            if ((int) ($case['subject_staff_user_id'] ?? 0) !== $actorId) {
                throw new DomainException('DISCIPLINE_DECISION_RECEIPT_SUBJECT_ONLY');
            }
            if ((string) ($decision['status'] ?? '') !== 'issued') {
                throw new DomainException('DISCIPLINE_DECISION_RECEIPT_NOT_AVAILABLE');
            }
            if ((string) ($decision['notification_status'] ?? '') === 'received') {
                return $this->decisionReceipt($decision, true);
            }
            if ((string) ($decision['notification_status'] ?? '') !== 'sent'
                || (int) ($decision['lock_version'] ?? 0) !== $expectedLock) {
                throw new DomainException('DISCIPLINE_DECISION_RECEIPT_STALE');
            }
            if (!$this->repository->recordReceipt($decisionId, $expectedLock, $this->instant($now))) {
                throw new DomainException('DISCIPLINE_DECISION_RECEIPT_STALE');
            }
            $after = array_replace($decision, [
                'notification_status' => 'received',
                'receipt_at' => $this->instant($now),
                'lock_version' => $expectedLock + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_decision_receipt_recorded',
                'staff_discipline_decisions',
                $decisionId,
                (string) ($decision['decision_no'] ?? ''),
                [
                    'case_id' => (int) $decision['case_id'],
                    'notification_status' => 'received',
                    'notification_reference_hash' => $this->nullableHash($decision['notification_reference'] ?? null),
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->decisionReceipt($after, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private function decisionInput(
        array $command,
        int $actorId,
        array $case,
        int $investigationId,
        string $idempotencyKey,
        DateTimeImmutable $now
    ): array {
        $sanctionCode = $this->requiredText(
            $command['sanction_code'] ?? null,
            100,
            'DISCIPLINE_DECISION_SANCTION_REQUIRED'
        );
        $decisionReason = $this->requiredText(
            $command['decision_reason'] ?? null,
            20000,
            'DISCIPLINE_DECISION_REASON_REQUIRED'
        );
        $policySnapshot = $command['policy_snapshot'] ?? null;
        if (!is_array($policySnapshot) || $policySnapshot === []) {
            throw new InvalidArgumentException('DISCIPLINE_DECISION_POLICY_SNAPSHOT_INVALID');
        }
        $effectiveFrom = $this->nullableInstant(
            $command['effective_from'] ?? null,
            'DISCIPLINE_DECISION_EFFECTIVE_FROM_INVALID'
        );
        $effectiveTo = $this->nullableInstant(
            $command['effective_to'] ?? null,
            'DISCIPLINE_DECISION_EFFECTIVE_TO_INVALID'
        );
        if ($effectiveFrom !== null && $effectiveTo !== null && $effectiveTo <= $effectiveFrom) {
            throw new InvalidArgumentException('DISCIPLINE_DECISION_EFFECTIVE_WINDOW_INVALID');
        }
        $decisionNo = $this->nullableText(
            $command['decision_no'] ?? null,
            80,
            'DISCIPLINE_DECISION_NO_INVALID'
        ) ?? $this->number('DISC-DEC', $idempotencyKey);
        $financialEffectRequested = $this->boolean(
            $command['financial_effect_requested'] ?? false,
            'DISCIPLINE_DECISION_FINANCE_FLAG_INVALID'
        );
        $policyJson = $this->json($policySnapshot, 'DISCIPLINE_DECISION_POLICY_SNAPSHOT_INVALID');
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_CASE_INVALID');
        $decisionHash = $this->hash([
            'case_id' => $caseId,
            'investigation_id' => $investigationId,
            'decision_no' => $decisionNo,
            'sanction_code' => $sanctionCode,
            'effective_from' => $effectiveFrom === null ? null : $this->instant($effectiveFrom),
            'effective_to' => $effectiveTo === null ? null : $this->instant($effectiveTo),
            'decision_reason' => $decisionReason,
            'policy_snapshot' => $policySnapshot,
            'financial_effect_requested' => $financialEffectRequested,
            'prepared_by_user_id' => $actorId,
        ]);

        return [
            'case_id' => $caseId,
            'investigation_id' => $investigationId,
            'decision_no' => $decisionNo,
            'sanction_code' => $sanctionCode,
            'effective_from' => $effectiveFrom === null ? null : $this->instant($effectiveFrom),
            'effective_to' => $effectiveTo === null ? null : $this->instant($effectiveTo),
            'decision_reason' => $decisionReason,
            'policy_snapshot' => $policyJson,
            'financial_effect_requested' => $financialEffectRequested ? 1 : 0,
            'idempotency_key' => $idempotencyKey,
            'decision_hash' => $decisionHash,
            'effective_at' => $effectiveFrom ?? $now,
        ];
    }

    /** @param array<string,mixed> $workflow @return array{workflow_version_id:int,snapshot:array<string,mixed>} */
    private function normalizedWorkflow(array $workflow): array
    {
        $versionId = $this->positiveId(
            $workflow['workflow_version_id'] ?? null,
            'DISCIPLINE_DECISION_WORKFLOW_VERSION_INVALID'
        );
        $snapshot = $workflow['snapshot'] ?? null;
        if (!is_array($snapshot)) {
            throw new DomainException('DISCIPLINE_DECISION_WORKFLOW_SNAPSHOT_INVALID');
        }
        $context = $snapshot['context'] ?? null;
        if (!is_array($context)) {
            throw new DomainException('DISCIPLINE_DECISION_WORKFLOW_SNAPSHOT_INVALID');
        }

        return ['workflow_version_id' => $versionId, 'snapshot' => $snapshot];
    }

    /** @param array<string,mixed> $case @param array<string,mixed> $investigation */
    private function assertPreparerSeparated(int $actorId, array $case, array $investigation): void
    {
        $conflicts = [
            $this->nullablePositiveId($case['incident_reported_by_user_id'] ?? null),
            $this->nullablePositiveId($case['opened_by_user_id'] ?? null),
            $this->nullablePositiveId($investigation['investigator_user_id'] ?? null),
        ];
        if (in_array($actorId, array_filter($conflicts), true)) {
            throw new DomainException('DISCIPLINE_DECISION_PREPARER_CONFLICT');
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
    private function requiredInvestigation(int $investigationId): array
    {
        $investigation = $this->repository->investigationForUpdate($investigationId);
        if ($investigation === null) {
            throw new DomainException('DISCIPLINE_INVESTIGATION_NOT_FOUND');
        }

        return $investigation;
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

    /** @param array<string,mixed> $decision @return array<string,mixed> */
    private function decisionReceipt(array $decision, bool $replayed): array
    {
        return [
            'decision_id' => $this->positiveId($decision['id'] ?? null, 'DISCIPLINE_DECISION_RECEIPT_INVALID'),
            'case_id' => $this->positiveId($decision['case_id'] ?? null, 'DISCIPLINE_DECISION_RECEIPT_INVALID'),
            'decision_no' => (string) ($decision['decision_no'] ?? ''),
            'status' => (string) ($decision['status'] ?? ''),
            'workflow_instance_id' => $this->nullablePositiveId($decision['workflow_instance_id'] ?? null),
            'notification_status' => (string) ($decision['notification_status'] ?? ''),
            'receipt_at' => $decision['receipt_at'] ?? null,
            'financial_effect_requested' => (bool) ($decision['financial_effect_requested'] ?? false),
            'lock_version' => $this->positiveId(
                $decision['lock_version'] ?? null,
                'DISCIPLINE_DECISION_RECEIPT_INVALID'
            ),
            'replayed' => $replayed,
        ];
    }

    private function approvalSubmissionIdempotencyKey(string $idempotencyKey): string
    {
        return 'discipline-approval-submit:' . hash('sha256', $idempotencyKey);
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
        try {
            return $value instanceof DateTimeImmutable
                ? $value
                : new DateTimeImmutable((string) $value, $this->clockZone);
        } catch (\Throwable) {
            throw new InvalidArgumentException($error);
        }
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

    private function number(string $prefix, string $idempotencyKey): string
    {
        return $prefix . '-' . strtoupper(substr(hash('sha256', $idempotencyKey), 0, 16));
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', $this->json($this->canonicalize($value), 'DISCIPLINE_DECISION_SERIALIZATION_INVALID'));
    }

    private function nullableHash(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return hash('sha256', $value);
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
