<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned persistence boundary for proposed and issued discipline decisions.
 *
 * The adapter never writes Finance or a linked source module. It only exposes
 * locks and compare-and-swap transitions so the application service and the
 * shared approval owner can keep business data and audit evidence atomic.
 */
interface DisciplineDecisionRepository
{
    public function transactional(callable $work): mixed;

    public function lockUser(int $userId): bool;

    /** @return array<string,mixed>|null */
    public function caseForUpdate(int $caseId): ?array;

    /** @return array<string,mixed>|null */
    public function investigationForUpdate(int $investigationId): ?array;

    /** @return array<string,mixed>|null */
    public function decisionByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function decisionForUpdate(int $decisionId): ?array;

    public function nextDecisionSequenceForUpdate(int $caseId): int;

    /** @param array<string,mixed> $decision */
    public function insertDecision(array $decision): int;

    public function attachWorkflowInstance(
        int $decisionId,
        int $expectedLockVersion,
        int $workflowInstanceId
    ): bool;

    public function issueDecision(
        int $decisionId,
        int $expectedLockVersion,
        int $decidedByUserId,
        string $decidedAt,
        string $issuedAt
    ): bool;

    public function cancelProposedDecision(
        int $decisionId,
        int $expectedLockVersion
    ): bool;

    public function markNotification(
        int $decisionId,
        int $expectedLockVersion,
        string $status,
        ?string $reference,
        ?string $notifiedAt
    ): bool;

    public function recordReceipt(
        int $decisionId,
        int $expectedLockVersion,
        string $receiptAt
    ): bool;

    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus
    ): bool;
}
