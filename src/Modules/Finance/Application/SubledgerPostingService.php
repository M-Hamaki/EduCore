<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\SubledgerAccountRepository;
use EduCore\Modules\Finance\Contracts\Repositories\SubledgerLineRepository;
use EduCore\Modules\Finance\Contracts\Repositories\SubledgerTransactionRepository;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use RuntimeException;

/**
 * Owns append-only posting into the unified student/staff sub-ledger.
 *
 * The transaction manager joins an existing domain/GL transaction or creates
 * one when this service is called directly. GL linkage is supplied by the
 * higher-level accounting orchestration and must share this boundary.
 */
final class SubledgerPostingService
{
    public function __construct(
        private FinanceTransactionManager $transactions,
        private SubledgerAccountRepository $accounts,
        private SubledgerTransactionRepository $subledgerTransactions,
        private SubledgerLineRepository $lines,
        private JournalEntryService $journals,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * @param list<array{
     *   bucket:string,
     *   delta:SignedMoneyDelta,
     *   description?:?string,
     *   installment_id?:?int,
     *   cost_center_id?:?int
     * }> $lines
     */
    public function postPartyOperation(
        string $partyType,
        int $partyId,
        string $scopeKey,
        string $sourceType,
        ?int $sourceRefId,
        string $sourceIdempotencyKey,
        array $subledgerLines,
        string $journalSourceType,
        string $entryDate,
        array $journalLines,
        int $postedBy,
        ?string $batchId = null,
        ?string $requestId = null
    ): int {
        if (!in_array($partyType, ['student', 'staff'], true)) {
            throw new RuntimeException('Unsupported finance party type.');
        }
        if ($partyId <= 0 || $postedBy <= 0 || trim($scopeKey) === '' || trim($sourceType) === '') {
            throw new RuntimeException('Invalid sub-ledger posting context.');
        }
        if (!preg_match('/^[a-f0-9]{32}$/i', $sourceIdempotencyKey)) {
            throw new RuntimeException('The source idempotency key must be a 32-character hexadecimal value.');
        }
        if ($subledgerLines === [] || $journalLines === []) {
            throw new RuntimeException('A party operation must contain at least one sub-ledger line.');
        }
        if (trim($journalSourceType) === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) !== 1) {
            throw new RuntimeException('Invalid journal context for party operation.');
        }

        return $this->transactions->transactional(function () use (
            $partyType,
            $partyId,
            $scopeKey,
            $sourceType,
            $sourceRefId,
            $sourceIdempotencyKey,
            $subledgerLines,
            $journalSourceType,
            $entryDate,
            $journalLines,
            $postedBy,
            $batchId,
            $requestId
        ): int {
            $existing = $this->subledgerTransactions->findByIdempotencyKey($sourceIdempotencyKey);
            if ($existing !== null) {
                $existingId = (int) $existing['id'];
                $this->journals->postJournalForPartyOperation(
                    $journalSourceType,
                    $sourceRefId,
                    $sourceIdempotencyKey,
                    $existingId,
                    $entryDate,
                    $journalLines,
                    $postedBy
                );
                return $existingId;
            }

            $account = $this->accounts->findOrCreate($partyType, $partyId, $scopeKey);
            $transactionId = $this->subledgerTransactions->createTransaction(
                (int) $account['id'],
                $sourceType,
                $sourceRefId,
                $sourceIdempotencyKey,
                $batchId,
                $requestId,
                $postedBy
            );

            foreach (array_values($subledgerLines) as $index => $line) {
                if (!isset($line['delta']) || !$line['delta'] instanceof SignedMoneyDelta) {
                    throw new RuntimeException('Every sub-ledger line requires a signed money delta.');
                }
                $bucket = trim((string) ($line['bucket'] ?? ''));
                if ($bucket === '') {
                    throw new RuntimeException('Every sub-ledger line requires a bucket code.');
                }

                $this->subledgerTransactions->addLine(
                    $transactionId,
                    $index + 1,
                    $bucket,
                    $line['delta'],
                    $line['description'] ?? null,
                    $line['installment_id'] ?? null,
                    $line['cost_center_id'] ?? null
                );
            }

            $this->subledgerTransactions->post($transactionId, $postedBy);

            $journalId = $this->journals->postJournalForPartyOperation(
                $journalSourceType,
                $sourceRefId,
                $sourceIdempotencyKey,
                $transactionId,
                $entryDate,
                $journalLines,
                $postedBy
            );

            $this->audit->recordEvent(
                'finance_post',
                'finance_' . $sourceType,
                $sourceRefId ?? $transactionId,
                $sourceType,
                [
                    'subledger_transaction_id' => $transactionId,
                    'journal_entry_id' => $journalId,
                    'source_idempotency_key' => $sourceIdempotencyKey,
                ],
                ['batch_id' => $batchId, 'request_id' => $requestId]
            );

            return $transactionId;
        });
    }

    public function postReversal(
        int $originalTransactionId,
        string $idempotencyKey,
        string $entryDate,
        array $journalLines,
        int $reversedBy,
        ?string $batchId = null,
        ?string $requestId = null
    ): int
    {
        if ($originalTransactionId <= 0 || $reversedBy <= 0 || $journalLines === []) {
            throw new RuntimeException('Invalid reversal context.');
        }
        if (!preg_match('/^[a-f0-9]{32}$/i', $idempotencyKey) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) !== 1) {
            throw new RuntimeException('Invalid reversal idempotency key or entry date.');
        }

        return $this->transactions->transactional(function () use (
            $originalTransactionId,
            $idempotencyKey,
            $entryDate,
            $journalLines,
            $reversedBy,
            $batchId,
            $requestId
        ): int {
            $existing = $this->subledgerTransactions->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                $existingId = (int) $existing['id'];
                $this->journals->postReversalForPartyOperation(
                    $originalTransactionId,
                    $existingId,
                    $idempotencyKey,
                    $entryDate,
                    $journalLines,
                    $reversedBy
                );
                return $existingId;
            }

            $originalLines = $this->lines->findByTransaction($originalTransactionId);
            if ($originalLines === []) {
                throw new RuntimeException('The original transaction has no lines to reverse.');
            }

            $reversalId = $this->subledgerTransactions->createReversal(
                $originalTransactionId,
                $idempotencyKey,
                $reversedBy
            );

            foreach ($originalLines as $index => $line) {
                $this->subledgerTransactions->addLine(
                    $reversalId,
                    $index + 1,
                    (string) $line['bucket_code'],
                    SignedMoneyDelta::fromDecimalString((string) $line['amount_delta'])->negate(),
                    isset($line['description']) ? (string) $line['description'] : null,
                    isset($line['installment_id']) ? (int) $line['installment_id'] : null,
                    isset($line['cost_center_id']) ? (int) $line['cost_center_id'] : null
                );
            }

            $this->subledgerTransactions->post($reversalId, $reversedBy);

            $journalId = $this->journals->postReversalForPartyOperation(
                $originalTransactionId,
                $reversalId,
                $idempotencyKey,
                $entryDate,
                $journalLines,
                $reversedBy
            );

            $this->audit->recordEvent(
                'finance_reverse',
                'finance_subledger_transaction',
                $reversalId,
                'reversal',
                [
                    'original_subledger_transaction_id' => $originalTransactionId,
                    'subledger_transaction_id' => $reversalId,
                    'journal_entry_id' => $journalId,
                    'source_idempotency_key' => $idempotencyKey,
                ],
                ['batch_id' => $batchId, 'request_id' => $requestId]
            );

            return $reversalId;
        });
    }

    public function bucketBalance(int $subledgerAccountId, string $bucketCode): string
    {
        return $this->lines->sumForBucket($subledgerAccountId, $bucketCode);
    }
}
