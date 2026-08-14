<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\Repositories\AccountMappingLineRepository;
use EduCore\Modules\Finance\Contracts\Repositories\JournalEntryRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;

final class JournalEntryService
{
    public function __construct(
        private JournalEntryRepository $journals,
        private AccountMappingLineRepository $mappingLines,
        private AccountMappingPolicy $mappingPolicy,
        private ControlAccountService $controlAccounts
    ) {
    }

    /**
     * @param list<array{
     *   account_id:int,
     *   debit:Money,
     *   credit:Money,
     *   description?:?string,
     *   cost_center_id?:?int,
     *   sub_ledger_ref_type?:?string,
     *   sub_ledger_ref_id?:?int
     * }> $lines
     */
    public function postJournalForPartyOperation(
        string $sourceType,
        ?int $sourceRefId,
        string $sourceIdempotencyKey,
        ?int $subledgerTransactionId,
        string $entryDate,
        array $lines,
        int $postedBy,
        ?int $reversalOf = null
    ): int {
        $existing = $this->journals->findByIdempotencyKey($sourceIdempotencyKey);
        if ($existing !== null) {
            $existingLink = $existing['subledger_transaction_id'] === null
                ? null
                : (int) $existing['subledger_transaction_id'];
            if ($existingLink !== $subledgerTransactionId) {
                throw new \RuntimeException('Journal idempotency key is linked to another source operation.');
            }
            return (int) $existing['id'];
        }

        $entryId = $this->journals->create(
            'JE-' . $sourceType . '-' . substr($sourceIdempotencyKey, 0, 8),
            null,
            $entryDate,
            $sourceType,
            $sourceRefId,
            $sourceIdempotencyKey,
            null,
            $postedBy,
            $subledgerTransactionId,
            $reversalOf
        );

        foreach ($lines as $line) {
            $this->journals->addLine(
                $entryId,
                $line['account_id'],
                $line['cost_center_id'] ?? null,
                $line['debit']->toDatabaseString(),
                $line['credit']->toDatabaseString(),
                $line['description'] ?? null,
                $line['sub_ledger_ref_type'] ?? null,
                $line['sub_ledger_ref_id'] ?? null
            );
        }

        $this->journals->post($entryId, $postedBy);

        return $entryId;
    }

    /** @param list<array<string,mixed>> $lines */
    public function postReversalForPartyOperation(
        int $originalSubledgerTransactionId,
        int $reversalSubledgerTransactionId,
        string $sourceIdempotencyKey,
        string $entryDate,
        array $lines,
        int $postedBy
    ): int {
        $original = $this->journals->findBySubledgerTransactionId($originalSubledgerTransactionId);
        if ($original === null || (string) $original['status'] !== 'posted') {
            throw new \RuntimeException('The original party journal is missing or is not posted.');
        }

        return $this->postJournalForPartyOperation(
            'reversal',
            $originalSubledgerTransactionId,
            $sourceIdempotencyKey,
            $reversalSubledgerTransactionId,
            $entryDate,
            $lines,
            $postedBy,
            (int) $original['id']
        );
    }

    /** Return the exact opposite of the original party journal; callers never rebuild it from current mappings. */
    public function reversalLinesForPartyOperation(int $originalSubledgerTransactionId): array
    {
        $original = $this->journals->findBySubledgerTransactionId($originalSubledgerTransactionId);
        if ($original === null || (string) $original['status'] !== 'posted') {
            throw new \RuntimeException('The original party journal is missing or is not posted.');
        }
        $lines = $this->journals->linesForEntry((int) $original['id']);
        if ($lines === []) {
            throw new \RuntimeException('The original party journal has no lines.');
        }
        return array_map(static fn (array $line): array => [
            'account_id' => (int) $line['account_id'],
            'cost_center_id' => $line['cost_center_id'] === null ? null : (int) $line['cost_center_id'],
            'debit' => Money::fromDecimalString((string) $line['credit']),
            'credit' => Money::fromDecimalString((string) $line['debit']),
            'description' => 'Reversal: ' . trim((string) ($line['description'] ?? '')),
            'sub_ledger_ref_type' => $line['sub_ledger_ref_type'] ?? null,
            'sub_ledger_ref_id' => $line['sub_ledger_ref_id'] === null ? null : (int) $line['sub_ledger_ref_id'],
        ], $lines);
    }

    /** @param list<array<string,mixed>> $lines */
    public function postPureGlOperation(
        string $sourceType,
        ?int $sourceRefId,
        string $sourceIdempotencyKey,
        ?int $financePeriodId,
        string $entryDate,
        array $lines,
        int $postedBy,
        ?string $batchId = null
    ): int {
        $this->controlAccounts->assertPureGlLinesAllowed($lines);
        $existing = $this->journals->findByIdempotencyKey($sourceIdempotencyKey);
        if ($existing !== null) {
            if ($existing['subledger_transaction_id'] !== null) {
                throw new \RuntimeException('Pure GL idempotency key is linked to a party transaction.');
            }
            return (int) $existing['id'];
        }

        $entryId = $this->journals->create(
            'JE-' . $sourceType . '-' . substr($sourceIdempotencyKey, 0, 8),
            $financePeriodId,
            $entryDate,
            $sourceType,
            $sourceRefId,
            $sourceIdempotencyKey,
            $batchId,
            $postedBy,
            null
        );
        foreach ($lines as $line) {
            $this->journals->addLine($entryId, (int) $line['account_id'], $line['cost_center_id'] ?? null, $line['debit']->toDatabaseString(), $line['credit']->toDatabaseString(), $line['description'] ?? null, null, null);
        }
        $this->journals->post($entryId, $postedBy);
        return $entryId;
    }

    public function postPureGlReversal(
        string $originalSourceIdempotencyKey,
        ?int $reversalSourceRefId,
        string $reversalIdempotencyKey,
        string $entryDate,
        int $postedBy,
        ?string $batchId = null
    ): int {
        $existing = $this->journals->findByIdempotencyKey($reversalIdempotencyKey);
        if ($existing !== null) {
            if ($existing['subledger_transaction_id'] !== null) {
                throw new \RuntimeException('Pure GL reversal idempotency key is linked to a party transaction.');
            }
            return (int) $existing['id'];
        }
        $original = $this->journals->findByIdempotencyKey($originalSourceIdempotencyKey);
        if ($original === null || (string) $original['status'] !== 'posted' || $original['subledger_transaction_id'] !== null) {
            throw new \RuntimeException('Original pure GL journal is missing, not posted, or party-linked.');
        }
        $originalLines = $this->journals->linesForEntry((int) $original['id']);
        if ($originalLines === []) {
            throw new \RuntimeException('Original pure GL journal has no lines.');
        }
        $lines = array_map(static fn (array $line): array => [
            'account_id' => (int) $line['account_id'],
            'cost_center_id' => $line['cost_center_id'] === null ? null : (int) $line['cost_center_id'],
            'debit' => Money::fromDecimalString((string) $line['credit']),
            'credit' => Money::fromDecimalString((string) $line['debit']),
            'description' => 'Reversal: ' . trim((string) ($line['description'] ?? '')),
        ], $originalLines);
        $this->controlAccounts->assertPureGlLinesAllowed($lines);
        $reversalSourceType = (string) $original['source_type'] . '_reversal';
        $entryId = $this->journals->create(
            'JE-' . $reversalSourceType . '-' . substr($reversalIdempotencyKey, 0, 8),
            $original['finance_period_id'] === null ? null : (int) $original['finance_period_id'],
            $entryDate,
            $reversalSourceType,
            $reversalSourceRefId,
            $reversalIdempotencyKey,
            $batchId,
            $postedBy,
            null,
            (int) $original['id']
        );
        foreach ($lines as $line) {
            $this->journals->addLine($entryId, $line['account_id'], $line['cost_center_id'], $line['debit']->toDatabaseString(), $line['credit']->toDatabaseString(), $line['description'], null, null);
        }
        $this->journals->post($entryId, $postedBy);
        return $entryId;
    }

    /** @return array{debit_account_id:int, credit_account_id:int} */
    public function resolveAccounts(string $operationType, array $selectors = []): array
    {
        $resolved = $this->mappingPolicy->resolve(
            $this->mappingLines->findActiveLines($operationType, $selectors)
        );

        return [
            'debit_account_id' => (int) $resolved['debit_account_id'],
            'credit_account_id' => (int) $resolved['credit_account_id'],
        ];
    }
}
