<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for payroll runs (versioned, posts to sub-ledger).
 */
interface PayrollRunRepository
{
    /**
     * Create a payroll run for a period (draft).
     *
     * @param int $payrollPeriodId
     * @param int $versionNumber
     * @param int $postedBy
     * @return int the run id
     */
    public function create(int $payrollPeriodId, int $versionNumber, int $createdBy, bool $isSettlement, string $batchId): int;
    public function nextVersion(int $payrollPeriodId): int;
    public function setStatus(int $runId, string $fromStatus, string $toStatus, int $actorId): void;

    /**
     * Create a run item for a staff member.
     *
     * @param int $runId
     * @param int $staffId
     * @param string $gross            decimal
     * @param string $totalDeductions  decimal
     * @param string $net              decimal
     * @param int|null $subledgerTxId
     * @return int the item id
     */
    public function createItem(int $runId, int $staffId, string $contractSnapshotJson, string $gross, string $totalDeductions, string $net, ?int $subledgerTxId): int;

    /**
     * Add a component breakdown line to a run item.
     *
     * @param int $itemId
     * @param int $componentId
     * @param string $amount    decimal
     * @param string $direction 'earning'|'deduction'
     */
    public function addItemComponent(int $itemId, int $componentId, string $amount, string $direction): void;

    /**
     * Post a payroll run (finalises and posts to sub-ledger).
     *
     * @param int $runId
     * @param int $postedBy
     */
    public function post(int $runId, int $postedBy): void;

    /**
     * Find a run by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;
    public function lockRun(int $id): ?array;
    public function findRunByReversalOf(int $originalRunId): ?array;
    public function createReversalRun(array $originalRun, int $versionNumber, int $createdBy, string $batchId): int;
    public function itemsForRun(int $runId): array;
    public function componentsForItem(int $itemId): array;
    public function createReversalItem(int $runId, array $originalItem, string $snapshotJson): int;
    public function hasPostedPaymentsForRun(int $runId): bool;
    public function markReversed(int $runId, int $reversedBy): void;
    public function markItemReversed(int $itemId): void;

    public function findItem(int $itemId): ?array;
    public function lockItem(int $itemId): ?array;
    public function findItemByRunAndStaff(int $runId, int $staffId): ?array;
    public function linkItemPosting(int $itemId, int $subledgerTransactionId): void;
    public function createPayment(int $itemId, int $cashboxId, string $amount, string $paymentMethod, int $postedBy, int $approvedBy, string $requestId): int;
    public function findPaymentByRequestId(string $requestId): ?array;
    public function lockPayment(int $paymentId): ?array;
    public function findPaymentByReversalOf(int $paymentId): ?array;
    public function createPaymentReversal(array $originalPayment, int $postedBy, int $approvedBy, string $requestId): int;
    public function linkPaymentPosting(int $paymentId, int $subledgerTransactionId): void;
    public function paidAmountForItem(int $itemId): string;
    public function markItemPaid(int $itemId): void;
    public function refreshItemPaymentStatus(int $itemId): void;
    public function payslip(int $itemId): ?array;
}
