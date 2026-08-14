<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\ChargeRepository;
use PDO;

final class PdoChargeRepository implements ChargeRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function createCharge(array $fields): int
    {
        $this->db->prepare(
            'INSERT INTO finance_student_charges
                (student_account_id, student_contract_id, charge_type_id, direction,
                 gross_amount, discount_amount, adjustment_amount, net_due,
                 due_date, source, academic_year_id, status, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)'
        )->execute([
            $fields['student_account_id'],
            $fields['student_contract_id'] ?? null,
            $fields['charge_type_id'],
            $fields['direction'] ?? 'debit',
            $fields['gross_amount'],
            $fields['discount_amount'],
            $fields['adjustment_amount'],
            $fields['net_due'],
            $fields['source'] ?? 'plan',
            $fields['academic_year_id'],
            'pending',
            $fields['request_id'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM finance_student_charges WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Charge locks require an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_student_charges WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findInstallmentForCharge(int $installmentId, int $chargeId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_charge_installments WHERE id = ? AND student_charge_id = ? LIMIT 1');
        $stmt->execute([$installmentId, $chargeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByRequestId(int $studentAccountId, string $requestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM finance_student_charges WHERE student_account_id = ? AND request_id = ? LIMIT 1'
        );
        $stmt->execute([$studentAccountId, $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function addInstallment(int $chargeId, string $name, string $netAmount, ?string $dueDate, int $displayOrder): int
    {
        $this->db->prepare(
            'INSERT INTO finance_charge_installments (student_charge_id, installment_name, net_amount, due_date, display_order, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$chargeId, $name, $netAmount, $dueDate, $displayOrder, 'pending']);
        return (int) $this->db->lastInsertId();
    }

    public function installmentsForCharge(int $chargeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, installment_name, net_amount, due_date, display_order, status
             FROM finance_charge_installments
             WHERE student_charge_id = ?
             ORDER BY display_order'
        );
        $stmt->execute([$chargeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function installmentsForAccount(int $studentAccountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ci.*
             FROM finance_charge_installments ci
             JOIN finance_student_charges sc ON sc.id = ci.student_charge_id
             WHERE sc.student_account_id = ? AND sc.status = 'posted'
               AND ci.status IN ('pending','partially_paid','paid')
             ORDER BY ci.due_date IS NULL, ci.due_date ASC, sc.id ASC, ci.display_order ASC, ci.id ASC"
        );
        $stmt->execute([$studentAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function installmentRemainingDue(int $installmentId): string
    {
        // remaining = installment.net_amount - SUM(applied allocations) - SUM(applied unapplied_credit_applications)
        $stmt = $this->db->prepare(
            'SELECT i.net_amount - COALESCE(
                (SELECT SUM(a.signed_amount) FROM finance_payment_allocations a
                 WHERE a.student_charge_installment_id = i.id), 0
             ) + COALESCE(
                (SELECT SUM(-r.signed_amount) FROM finance_refunds r
                 WHERE r.payment_allocation_id IN (
                    SELECT pa.id FROM finance_payment_allocations pa WHERE pa.student_charge_installment_id = i.id
                 ) AND r.status = ?), 0
             ) - COALESCE(
                (SELECT SUM(uca.applied_amount) FROM finance_unapplied_credit_applications uca
                 WHERE uca.student_charge_installment_id = i.id AND uca.status = ?), 0
             ) - COALESCE(
                (SELECT SUM(da.ledger_effect_amount) FROM finance_discount_applications da
                 WHERE da.student_charge_installment_id = i.id), 0
             ) AS remaining
             FROM finance_charge_installments i WHERE i.id = ?'
        );
        $stmt->execute(['posted', 'applied', $installmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (string) ($row['remaining'] ?? '0.00');
    }

    public function lockInstallmentRemainingDue(int $installmentId): string
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Installment locking requires an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT id FROM finance_charge_installments WHERE id = ? FOR UPDATE');
        $stmt->execute([$installmentId]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Charge installment was not found.');
        }

        return $this->installmentRemainingDue($installmentId);
    }

    public function post(int $chargeId, int $subledgerTransactionId, int $postedBy): void
    {
        $this->db->prepare('UPDATE finance_student_charges SET subledger_transaction_id = ?, status = ?, posted_at = NOW(), posted_by = ? WHERE id = ? AND status = ?')
            ->execute([$subledgerTransactionId, 'posted', $postedBy, $chargeId, 'pending']);
    }
}
