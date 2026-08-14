<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\VoucherRepository;
use EduCore\Modules\Finance\Contracts\Repositories\VoucherLineRepository;
use PDO;

/**
 * PDO implementation for vouchers and voucher lines.
 */
final class PdoVoucherRepository implements VoucherRepository, VoucherLineRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $fields): int
    {
        $this->db->prepare(
            'INSERT INTO finance_vouchers
                (voucher_number, voucher_type, cashbox_id, source_cashbox_id, destination_cashbox_id, bank_account_id, amount, finance_period_id,
                 entry_date, cost_center_id, status, posted_at, posted_by, approved_by, reversal_of, batch_id, request_id, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fields['voucher_number'], $fields['voucher_type'], $fields['cashbox_id'],
            $fields['source_cashbox_id'] ?? null, $fields['destination_cashbox_id'] ?? null,
            $fields['bank_account_id'] ?? null, $fields['amount'], $fields['finance_period_id'] ?? null,
            $fields['entry_date'], $fields['cost_center_id'] ?? null,
            $fields['status'] ?? 'posted', $fields['posted_by'] ?? 0, $fields['approved_by'] ?? 0,
            $fields['reversal_of'] ?? null, $fields['batch_id'] ?? null, $fields['request_id'] ?? null,
            $fields['notes'] ?? null, $fields['created_by'] ?? $fields['posted_by'] ?? 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_vouchers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_vouchers WHERE request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Voucher locking requires an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_vouchers WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByReversalOf(int $voucherId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_vouchers WHERE reversal_of = ? LIMIT 1');
        $stmt->execute([$voucherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function addLine(int $voucherId, int $accountId, ?int $costCenterId, string $debit, string $credit, ?string $description): void
    {
        $this->db->prepare(
            'INSERT INTO finance_voucher_lines (voucher_id, account_id, cost_center_id, debit, credit, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$voucherId, $accountId, $costCenterId, $debit, $credit, $description]);
    }

    public function isBalanced(int $voucherId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c
             FROM finance_voucher_lines WHERE voucher_id = ?'
        );
        $stmt->execute([$voucherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        // Compare as minor units.
        $dMinor = $this->toMinor((string) $row['d']);
        $cMinor = $this->toMinor((string) $row['c']);
        return $dMinor === $cMinor;
    }

    public function findByVoucher(int $voucherId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_voucher_lines WHERE voucher_id = ? ORDER BY id');
        $stmt->execute([$voucherId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sumDebit(int $voucherId): string
    {
        return $this->sumColumn($voucherId, 'debit');
    }

    public function sumCredit(int $voucherId): string
    {
        return $this->sumColumn($voucherId, 'credit');
    }

    private function sumColumn(int $voucherId, string $column): string
    {
        if (!in_array($column, ['debit', 'credit'], true)) {
            throw new \InvalidArgumentException('Unsupported voucher total column.');
        }

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM({$column}), 0.00) FROM finance_voucher_lines WHERE voucher_id = ?"
        );
        $stmt->execute([$voucherId]);

        return (string) $stmt->fetchColumn();
    }

    private function toMinor(string $decimal): int
    {
        $parts = explode('.', trim($decimal) ?: '0');
        return ((int) ($parts[0] ?? '0')) * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    }
}
