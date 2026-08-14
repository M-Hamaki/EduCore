<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\ChargeInstallmentRepository;
use PDO;

final class PdoChargeInstallmentRepository implements ChargeInstallmentRepository
{
    public function __construct(private PDO $db) {}
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_charge_installments WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findByCharge(int $chargeId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_charge_installments WHERE student_charge_id = ? ORDER BY COALESCE(due_date, ?), display_order, id');
        $stmt->execute([$chargeId, '9999-12-31']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function remainingDue(int $installmentId): string
    {
        $stmt = $this->db->prepare("SELECT i.net_amount - COALESCE(SUM(pa.signed_amount), 0) - COALESCE((SELECT SUM(uca.applied_amount) FROM finance_unapplied_credit_applications uca WHERE uca.student_charge_installment_id = i.id AND uca.status = 'applied'), 0) + COALESCE((SELECT SUM(-r.signed_amount) FROM finance_refunds r JOIN finance_payment_allocations pra ON pra.id = r.payment_allocation_id WHERE pra.student_charge_installment_id = i.id AND r.status = 'posted'), 0) FROM finance_charge_installments i LEFT JOIN finance_payment_allocations pa ON pa.student_charge_installment_id = i.id WHERE i.id = ? GROUP BY i.id, i.net_amount");
        $stmt->execute([$installmentId]);
        return (string) ($stmt->fetchColumn() ?: '0.00');
    }
    public function updateStatus(int $id, string $status): void
    {
        $this->db->prepare('UPDATE finance_charge_installments SET status = ? WHERE id = ?')->execute([$status, $id]);
    }
}
