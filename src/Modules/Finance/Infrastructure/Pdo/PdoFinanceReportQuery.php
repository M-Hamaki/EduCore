<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Queries\FinanceReportQuery;
use PDO;

final class PdoFinanceReportQuery implements FinanceReportQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function trialBalance(?int $financePeriodId): array
    {
        $sql = 'SELECT a.id AS account_id, a.code, a.name_ar, a.type, COALESCE(SUM(CASE WHEN je.status = ? THEN jl.debit ELSE 0 END), 0) AS total_debit, COALESCE(SUM(CASE WHEN je.status = ? THEN jl.credit ELSE 0 END), 0) AS total_credit, COALESCE(SUM(CASE WHEN je.status = ? THEN jl.debit - jl.credit ELSE 0 END), 0) AS balance FROM accounting_accounts a LEFT JOIN accounting_journal_lines jl ON jl.account_id = a.id LEFT JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id';
        $params = ['posted', 'posted', 'posted'];
        if ($financePeriodId !== null) {
            $sql .= ' AND je.finance_period_id = ?';
            $params[] = $financePeriodId;
        }
        $sql .= ' WHERE a.is_active = 1 GROUP BY a.id, a.code, a.name_ar, a.type ORDER BY a.code';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function profitAndLoss(?int $financePeriodId, ?int $costCenterId): array
    {
        $sql = "SELECT a.id AS account_id, a.code, a.name_ar, a.type, COALESCE(SUM(CASE WHEN a.type = 'revenue' THEN jl.credit - jl.debit ELSE jl.debit - jl.credit END), 0) AS amount FROM accounting_accounts a JOIN accounting_journal_lines jl ON jl.account_id = a.id JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted' WHERE a.type IN ('revenue','expense')";
        $params = [];
        if ($financePeriodId !== null) { $sql .= ' AND je.finance_period_id = ?'; $params[] = $financePeriodId; }
        if ($costCenterId !== null) { $sql .= ' AND jl.cost_center_id = ?'; $params[] = $costCenterId; }
        $sql .= ' GROUP BY a.id, a.code, a.name_ar, a.type ORDER BY a.type, a.code';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cashFlow(?int $financePeriodId, ?int $costCenterId): array
    {
        $sql = "SELECT je.entry_date, a.id AS account_id, a.code, a.name_ar, COALESCE(SUM(jl.debit - jl.credit), 0) AS net_cash_change FROM accounting_journal_entries je JOIN accounting_journal_lines jl ON jl.journal_entry_id = je.id JOIN accounting_accounts a ON a.id = jl.account_id WHERE je.status = 'posted' AND a.type = 'asset'";
        $params = [];
        if ($financePeriodId !== null) { $sql .= ' AND je.finance_period_id = ?'; $params[] = $financePeriodId; }
        if ($costCenterId !== null) { $sql .= ' AND jl.cost_center_id = ?'; $params[] = $costCenterId; }
        $sql .= ' GROUP BY je.entry_date, a.id, a.code, a.name_ar ORDER BY je.entry_date, a.code';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function collectionSummary(int $academicYearId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                (SELECT COALESCE(SUM(sc.net_due), 0) FROM finance_student_charges sc JOIN finance_student_accounts sa ON sa.id = sc.student_account_id WHERE sa.academic_year_id = ? AND sc.status = ?) AS total_charges,
                (SELECT COALESCE(SUM(CASE WHEN r.reversal_of IS NULL THEN r.gross_amount ELSE -r.gross_amount END), 0) FROM finance_receipts r JOIN finance_student_accounts sa ON sa.id = r.student_account_id WHERE sa.academic_year_id = ? AND r.status = ?) AS total_collected,
                (SELECT COALESCE(SUM(v.net_account_position), 0) FROM v_student_subledger_balances v WHERE v.academic_year_id = ?) AS total_outstanding'
        );
        $stmt->execute([$academicYearId, 'posted', $academicYearId, 'posted', (string) $academicYearId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_charges' => '0.00', 'total_collected' => '0.00', 'total_outstanding' => '0.00'];
    }

    public function debtAging(int $academicYearId): array
    {
        $stmt = $this->db->prepare(
            "SELECT CASE WHEN DATEDIFF(CURRENT_DATE, sci.due_date) <= 0 THEN 'current' WHEN DATEDIFF(CURRENT_DATE, sci.due_date) <= 30 THEN 'd1_30' WHEN DATEDIFF(CURRENT_DATE, sci.due_date) <= 60 THEN 'd31_60' WHEN DATEDIFF(CURRENT_DATE, sci.due_date) <= 90 THEN 'd61_90' ELSE 'd90_plus' END AS bucket, COALESCE(SUM(GREATEST(sci.net_amount - COALESCE(pa.allocated, 0), 0)), 0) AS total FROM finance_charge_installments sci JOIN finance_student_charges sc ON sc.id = sci.student_charge_id JOIN finance_student_accounts sa ON sa.id = sc.student_account_id LEFT JOIN (SELECT student_charge_installment_id, SUM(signed_amount) AS allocated FROM finance_payment_allocations GROUP BY student_charge_installment_id) pa ON pa.student_charge_installment_id = sci.id WHERE sa.academic_year_id = ? AND sc.status = 'posted' GROUP BY bucket"
        );
        $stmt->execute([$academicYearId]);
        $result = ['current' => '0.00', 'd1_30' => '0.00', 'd31_60' => '0.00', 'd61_90' => '0.00', 'd90_plus' => '0.00'];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['bucket']] = (string) $row['total'];
        }
        return $result;
    }

    public function payrollSummary(int $payrollRunId): array
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(gross), 0) AS total_gross, COALESCE(SUM(total_deductions), 0) AS total_deductions, COALESCE(SUM(net), 0) AS total_net, COUNT(*) AS staff_count FROM payroll_run_items WHERE payroll_run_id = ? AND status <> ?');
        $stmt->execute([$payrollRunId, 'reversed']);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_gross' => '0.00', 'total_deductions' => '0.00', 'total_net' => '0.00', 'staff_count' => 0];
    }

    public function budgetVsActual(int $budgetVersionId): array
    {
        $stmt = $this->db->prepare('SELECT bl.account_id, a.code, a.name_ar, bl.planned_amount, COALESCE(SUM(CASE WHEN je.status = ? AND (bl.cost_center_id IS NULL OR jl.cost_center_id = bl.cost_center_id) AND (bl.period_id IS NULL OR je.finance_period_id = bl.period_id) THEN jl.debit - jl.credit ELSE 0 END), 0) AS actual_amount, bl.planned_amount - COALESCE(SUM(CASE WHEN je.status = ? AND (bl.cost_center_id IS NULL OR jl.cost_center_id = bl.cost_center_id) AND (bl.period_id IS NULL OR je.finance_period_id = bl.period_id) THEN jl.debit - jl.credit ELSE 0 END), 0) AS variance FROM finance_budget_lines bl JOIN accounting_accounts a ON a.id = bl.account_id LEFT JOIN accounting_journal_lines jl ON jl.account_id = bl.account_id LEFT JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id WHERE bl.budget_version_id = ? GROUP BY bl.id, bl.account_id, a.code, a.name_ar, bl.planned_amount');
        $stmt->execute(['posted', 'posted', $budgetVersionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
