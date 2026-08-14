<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\JournalEntryRepository;
use EduCore\Modules\Finance\Contracts\Repositories\JournalLineRepository;
use PDO;

final class PdoJournalEntryRepository implements JournalEntryRepository, JournalLineRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, entry_number, finance_period_id, entry_date, source_type, source_ref_id,
                    source_idempotency_key, subledger_transaction_id, status, batch_id
             FROM accounting_journal_entries
             WHERE source_idempotency_key = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findBySubledgerTransactionId(int $subledgerTransactionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, entry_number, source_idempotency_key, subledger_transaction_id, status
             FROM accounting_journal_entries
             WHERE subledger_transaction_id = ? LIMIT 1'
        );
        $stmt->execute([$subledgerTransactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(
        string $entryNumber,
        ?int $financePeriodId,
        string $entryDate,
        string $sourceType,
        ?int $sourceRefId,
        string $sourceIdempotencyKey,
        ?string $batchId,
        int $postedBy,
        ?int $subledgerTransactionId = null,
        ?int $reversalOf = null
    ): int {
        // Idempotency: if exists, return it.
        $existing = $this->findByIdempotencyKey($sourceIdempotencyKey);
        if ($existing) {
            return (int) $existing['id'];
        }

        $this->db->prepare(
            'INSERT INTO accounting_journal_entries
                (entry_number, finance_period_id, entry_date, source_type, source_ref_id,
                 source_idempotency_key, subledger_transaction_id, status, batch_id, posted_by, reversal_of)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $entryNumber, $financePeriodId, $entryDate, $sourceType, $sourceRefId,
            $sourceIdempotencyKey, $subledgerTransactionId, 'draft', $batchId, $postedBy, $reversalOf,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function addLine(
        int $entryId,
        int $accountId,
        ?int $costCenterId,
        string $debit,
        string $credit,
        ?string $description,
        ?string $subLedgerRefType,
        ?int $subLedgerRefId
    ): void {
        $this->db->prepare(
            'INSERT INTO accounting_journal_lines
                (journal_entry_id, account_id, cost_center_id, debit, credit, description,
                 sub_ledger_ref_type, sub_ledger_ref_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $entryId, $accountId, $costCenterId, $debit, $credit,
            $description, $subLedgerRefType, $subLedgerRefId,
        ]);
    }

    public function post(int $entryId, int $postedBy): void
    {
        if (!$this->isBalanced($entryId)) {
            throw new \RuntimeException('القيد غير متوازن: مجموع المدين لا يساوي مجموع الدائن.');
        }
        $this->db->prepare(
            'UPDATE accounting_journal_entries SET status = ?, posted_at = NOW(), posted_by = ? WHERE id = ?'
        )->execute(['posted', $postedBy, $entryId]);
    }

    public function isBalanced(int $entryId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit
             FROM accounting_journal_lines WHERE journal_entry_id = ?'
        );
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        // Compare as strings to avoid float: convert to minor units.
        $debitMinor = $this->decimalToMinor((string) $row['total_debit']);
        $creditMinor = $this->decimalToMinor((string) $row['total_credit']);
        return $debitMinor === $creditMinor;
    }

    public function findByEntry(int $entryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM accounting_journal_lines WHERE journal_entry_id = ? ORDER BY id'
        );
        $stmt->execute([$entryId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function linesForEntry(int $entryId): array
    {
        return $this->findByEntry($entryId);
    }

    public function sumDebit(int $entryId): string
    {
        return $this->sumColumn($entryId, 'debit');
    }

    public function sumCredit(int $entryId): string
    {
        return $this->sumColumn($entryId, 'credit');
    }

    private function sumColumn(int $entryId, string $column): string
    {
        if (!in_array($column, ['debit', 'credit'], true)) {
            throw new \InvalidArgumentException('Unsupported journal total column.');
        }

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM({$column}), 0.00) FROM accounting_journal_lines WHERE journal_entry_id = ?"
        );
        $stmt->execute([$entryId]);

        return (string) $stmt->fetchColumn();
    }

    private function decimalToMinor(string $decimal): int
    {
        $decimal = trim($decimal);
        if ($decimal === '') {
            return 0;
        }
        $parts = explode('.', $decimal);
        $major = (int) ($parts[0] ?? '0');
        $fractional = str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        return ($major * 100) + (int) $fractional;
    }
}
