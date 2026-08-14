<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\CashboxRepository;
use PDO;

/**
 * PDO implementation for cashboxes, bank accounts, and daily settlements.
 */
final class PdoCashboxRepository implements CashboxRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findActiveCashboxes(): array
    {
        return $this->db->query(
            'SELECT id, code, name, type, is_active, accountability_role, receipt_prefix
             FROM finance_cashboxes WHERE is_active = 1 ORDER BY code'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_cashboxes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Cashbox locks require an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_cashboxes WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findSettlement(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_cashbox_settlements WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createSettlement(int $cashboxId, ?int $periodId, string $date, string $openingFloat, string $expectedTotal, string $countedTotal): int
    {
        $this->db->prepare(
            'INSERT INTO finance_cashbox_settlements
                (cashbox_id, period_id, settlement_date, opening_float, expected_total, counted_total, difference, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $cashboxId, $periodId, $date, $openingFloat, $expectedTotal, $countedTotal,
            '0.00',
            'open',
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function settleSettlement(int $id, string $countedTotal, string $difference, int $settledBy): void
    {
        $this->db->prepare(
            'UPDATE finance_cashbox_settlements SET counted_total = ?, difference = ?, status = ?, settled_by = ?, settled_at = NOW() WHERE id = ? AND status = ?'
        )->execute([$countedTotal, $difference, 'settled', $settledBy, $id, 'open']);
    }

    public function expectedReceiptTotal(int $cashboxId, string $date): string
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(movement), 0) FROM (
                SELECT CASE WHEN reversal_of IS NULL THEN gross_amount ELSE -gross_amount END AS movement
                FROM finance_receipts WHERE cashbox_id = ? AND DATE(posted_at) = ? AND status = 'posted'
                UNION ALL
                SELECT rf.signed_amount AS movement
                FROM finance_refunds rf JOIN finance_receipts r ON r.id = rf.receipt_id
                WHERE r.cashbox_id = ? AND DATE(rf.posted_at) = ? AND rf.status = 'posted'
                UNION ALL
                SELECT CASE WHEN pp.reversal_of IS NULL THEN -pp.amount ELSE pp.amount END AS movement
                FROM payroll_payments pp WHERE pp.cashbox_id = ? AND DATE(pp.posted_at) = ? AND pp.status = 'posted'
                UNION ALL
                SELECT CASE WHEN sam.reversal_of IS NULL THEN sam.amount ELSE -sam.amount END AS movement
                FROM staff_advance_movements sam WHERE sam.cashbox_id = ? AND DATE(sam.created_at) = ? AND sam.status = 'posted' AND sam.movement_type = 'cash_repayment'
                UNION ALL
                SELECT (CASE
                    WHEN v.voucher_type = 'expense' AND v.cashbox_id = ? THEN -v.amount
                    WHEN v.voucher_type = 'other_income' AND v.cashbox_id = ? THEN v.amount
                    WHEN v.voucher_type = 'cash_transfer' AND v.source_cashbox_id = ? THEN -v.amount
                    WHEN v.voucher_type = 'cash_transfer' AND v.destination_cashbox_id = ? THEN v.amount
                    ELSE 0 END) * (CASE WHEN v.reversal_of IS NULL THEN 1 ELSE -1 END) AS movement
                FROM finance_vouchers v WHERE v.entry_date = ? AND v.status = 'posted'
            ) cash_movements"
        );
        $stmt->execute([$cashboxId, $date, $cashboxId, $date, $cashboxId, $date, $cashboxId, $date, $cashboxId, $cashboxId, $cashboxId, $cashboxId, $date]);
        return (string) $stmt->fetchColumn();
    }
}
