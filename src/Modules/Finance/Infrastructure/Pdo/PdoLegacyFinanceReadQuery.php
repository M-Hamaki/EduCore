<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Queries\LegacyFinanceReadQuery;
use PDO;

final class PdoLegacyFinanceReadQuery implements LegacyFinanceReadQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function chargeTypeId(string $code): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM finance_charge_types WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function feePlan(int $chargeTypeId, int $academicYearId, ?int $gradeId): ?array
    {
        $sql = 'SELECT * FROM finance_fee_plans
                WHERE charge_type_id = ? AND academic_year_id = ? AND ';
        $params = [$chargeTypeId, $academicYearId];
        if ($gradeId === null) {
            $sql .= 'grade_id IS NULL';
        } else {
            $sql .= 'grade_id = ?';
            $params[] = $gradeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function feePlanById(int $planId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_fee_plans WHERE id = ? LIMIT 1');
        $stmt->execute([$planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function activeFeePlanVersion(int $planId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM finance_fee_plan_versions
             WHERE fee_plan_id = ? AND status = 'active'
             ORDER BY version_number DESC LIMIT 1"
        );
        $stmt->execute([$planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function feePlanInstallments(int $versionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, installment_name, gross_amount, due_date, display_order
             FROM finance_fee_plan_installments
             WHERE fee_plan_version_id = ?
             ORDER BY display_order'
        );
        $stmt->execute([$versionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function legacyFeeStructureCoordinates(int $legacyId): ?array
    {
        $stmt = $this->db->prepare('SELECT grade_id, academic_year FROM fee_structure WHERE id = ? LIMIT 1');
        $stmt->execute([$legacyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return ['grade_id' => (int) $row['grade_id'], 'academic_year' => (string) $row['academic_year']];
    }

    public function legacyOtherDiscount(int $legacyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, discount_type, discount_value, academic_year, status
             FROM other_discounts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$legacyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function studentAccount(int $studentId, int $academicYearId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT fsa.*, COALESCE(v.outstanding_due, 0.00) AS outstanding_due,
                    COALESCE(v.unapplied_credit, 0.00) AS unapplied_credit,
                    COALESCE(v.net_account_position, 0.00) AS net_account_position
             FROM finance_student_accounts fsa
             LEFT JOIN v_student_subledger_balances v
               ON v.student_id = fsa.student_id AND v.academic_year_id = fsa.academic_year_id
             WHERE fsa.student_id = ? AND fsa.academic_year_id = ?
             LIMIT 1'
        );
        $stmt->execute([$studentId, $academicYearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function activeStudentCharge(int $studentId, int $academicYearId, int $chargeTypeId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT sc.*
             FROM finance_student_charges sc
             JOIN finance_student_accounts fsa ON fsa.id = sc.student_account_id
             WHERE fsa.student_id = ? AND sc.academic_year_id = ?
               AND sc.charge_type_id = ? AND sc.status = 'posted'
             ORDER BY sc.id DESC LIMIT 1"
        );
        $stmt->execute([$studentId, $academicYearId, $chargeTypeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function chargeInstallments(int $chargeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ci.id, ci.student_charge_id, ci.installment_name, ci.net_amount,
                    ci.due_date, ci.display_order, ci.status,
                    GREATEST(ci.net_amount - COALESCE((
                        SELECT SUM(ABS(pa.signed_amount))
                        FROM finance_payment_allocations pa
                        WHERE pa.student_charge_installment_id = ci.id
                          AND pa.status = 'applied'
                    ), 0) - COALESCE((
                        SELECT SUM(uca.applied_amount)
                        FROM finance_unapplied_credit_applications uca
                        WHERE uca.student_charge_installment_id = ci.id
                          AND uca.status = 'applied'
                    ), 0) - COALESCE((
                        SELECT SUM(da.ledger_effect_amount)
                        FROM finance_discount_applications da
                        WHERE da.student_charge_installment_id = ci.id
                    ), 0), 0) AS remaining_due
             FROM finance_charge_installments ci
             WHERE ci.student_charge_id = ?
             ORDER BY ci.display_order"
        );
        $stmt->execute([$chargeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function studentReceipts(int $studentAccountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.id,
                    CASE WHEN r.reversal_of IS NULL THEN r.gross_amount ELSE -r.gross_amount END AS amount,
                    DATE(COALESCE(r.posted_at, r.created_at)) AS payment_date,
                    r.payment_method, r.receipt_number, r.notes, r.posted_by AS received_by,
                    NULL AS received_by_name, r.status, r.created_at
             FROM finance_receipts r
             WHERE r.student_account_id = ? AND r.status IN ('posted','reversed')
             ORDER BY COALESCE(r.posted_at, r.created_at) DESC, r.id DESC"
        );
        $stmt->execute([$studentAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function studentTotals(int $studentAccountId): array
    {
        $chargeStmt = $this->db->prepare(
            "SELECT
                COALESCE((
                    SELECT SUM(net_due)
                    FROM finance_student_charges
                    WHERE student_account_id = ? AND status = 'posted'
                ), 0)
                + COALESCE((
                    SELECT SUM(signed_amount)
                    FROM finance_adjustments
                    WHERE student_account_id = ? AND status = 'posted'
                ), 0)"
        );
        $chargeStmt->execute([$studentAccountId, $studentAccountId]);
        $due = (string) $chargeStmt->fetchColumn();
        $receiptStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(
                    CASE WHEN reversal_of IS NULL THEN gross_amount ELSE -gross_amount END
                ), 0)
             FROM finance_receipts
             WHERE student_account_id = ? AND status = 'posted'"
        );
        $receiptStmt->execute([$studentAccountId]);
        $paid = (string) $receiptStmt->fetchColumn();
        return ['final_amount' => $due, 'total_paid' => $paid];
    }

    public function studentDiscounts(int $studentAccountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT da.id, da.awarded_amount AS discount_amount, da.status,
                    dr.name_ar AS discount_name, dr.calculation_type AS discount_type,
                    dr.calculation_value AS discount_value, dr.code,
                    COALESCE(SUM(app.applied_amount), 0.00) AS applied_amount,
                    COALESCE(SUM(app.ledger_effect_amount), 0.00) AS ledger_effect_amount
             FROM finance_discount_awards da
             JOIN finance_discount_rules dr ON dr.id = da.discount_rule_id
             LEFT JOIN finance_discount_applications app ON app.discount_award_id = da.id
             WHERE da.student_account_id = ?
             GROUP BY da.id, da.awarded_amount, da.status, dr.name_ar,
                      dr.calculation_type, dr.calculation_value, dr.code
             ORDER BY da.created_at DESC, da.id DESC"
        );
        $stmt->execute([$studentAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function priorYearBalances(int $studentId, int $academicYearId): array
    {
        $stmt = $this->db->prepare(
            'SELECT fsa.academic_year_id, NULL AS year_name,
                    COALESCE(v.outstanding_due, 0.00) AS total_due,
                    0.00 AS total_paid,
                    COALESCE(v.net_account_position, 0.00) AS balance,
                    1 AS carried_forward
             FROM finance_student_accounts fsa
             JOIN v_student_subledger_balances v
               ON v.student_id = fsa.student_id AND v.academic_year_id = fsa.academic_year_id
             WHERE fsa.student_id = ? AND fsa.academic_year_id <> ?
               AND v.net_account_position > 0
             ORDER BY fsa.academic_year_id DESC'
        );
        $stmt->execute([$studentId, $academicYearId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function receiptByLegacyPaymentId(int $legacyPaymentId): ?array
    {
        $key = md5('legacy-fee-payment:' . $legacyPaymentId);
        $stmt = $this->db->prepare('SELECT * FROM finance_receipts WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function receipt(int $receiptId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, fsa.student_id
             FROM finance_receipts r
             JOIN finance_student_accounts fsa ON fsa.id = r.student_account_id
             WHERE r.id = ? LIMIT 1'
        );
        $stmt->execute([$receiptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function soleActiveCashboxId(): ?int
    {
        $rows = $this->db->query(
            'SELECT id FROM finance_cashboxes WHERE is_active = 1 ORDER BY id LIMIT 2'
        )->fetchAll(PDO::FETCH_COLUMN);
        return count($rows) === 1 ? (int) $rows[0] : null;
    }

    public function activeStaffContract(int $staffId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM staff_compensation_contracts
             WHERE staff_id = ? AND status = 'active'
             ORDER BY effective_from DESC, version_number DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$staffId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function staffContract(int $contractId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM staff_compensation_contracts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$contractId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function staffContractComponents(int $contractId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, pc.code, pc.name_ar
             FROM staff_compensation_contract_components c
             JOIN payroll_components pc ON pc.id = c.payroll_component_id
             WHERE c.contract_id = ? AND c.status = 'active'
             ORDER BY c.id"
        );
        $stmt->execute([$contractId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function payrollComponentId(string $code): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM payroll_components WHERE code = ? LIMIT 1'
        );
        $stmt->execute([trim($code)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
