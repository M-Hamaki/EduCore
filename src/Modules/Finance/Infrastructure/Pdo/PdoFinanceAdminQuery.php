<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Queries\FinanceAdminQuery;
use InvalidArgumentException;
use PDO;

final class PdoFinanceAdminQuery implements FinanceAdminQuery
{
    /** @var array<string,list<string>> */
    private const PAGE_COLUMNS = [
        'receipts' => ['id', 'receipt_number', 'student_account_id', 'student_id', 'academic_year_id', 'cashbox_code', 'payment_method', 'gross_amount', 'status', 'reversal_of', 'posted_at'],
        'student_ledger' => ['transaction_id', 'source_type', 'source_ref_id', 'status', 'reversal_of', 'posted_at', 'line_number', 'bucket_code', 'amount_delta', 'description', 'installment_id'],
        'staff_ledger' => ['transaction_id', 'staff_id', 'source_type', 'source_ref_id', 'status', 'reversal_of', 'posted_at', 'line_number', 'bucket_code', 'amount_delta', 'description'],
        'payroll_runs' => ['id', 'payroll_period_id', 'start_date', 'end_date', 'version_number', 'is_settlement', 'status', 'reversal_of', 'created_at'],
        'payroll_items' => ['id', 'payroll_run_id', 'staff_id', 'gross', 'total_deductions', 'net', 'status', 'reversal_of', 'payslip_ref_number', 'payment_status', 'subledger_transaction_id'],
        'journal' => ['id', 'entry_number', 'entry_date', 'source_type', 'source_ref_id', 'source_idempotency_key', 'status', 'reversal_of', 'subledger_transaction_id', 'total_debit', 'total_credit'],
        'audit_log' => ['id', 'action', 'target_type', 'target_id', 'target_name', 'user_id', 'user_name', 'result', 'created_at'],
        'vouchers' => ['id', 'voucher_number', 'voucher_type', 'amount', 'entry_date', 'status', 'reversal_of', 'cashbox_code', 'posted_by', 'approved_by'],
    ];

    public function __construct(private PDO $db)
    {
    }

    public function rows(string $view, array $filters = [], int $limit = 100): array
    {
        if ($view === 'audit_log' && !$this->tableExists('activity_logs')) {
            return [];
        }
        $limit = max(1, min($limit, 500));
        [$sql, $params] = $this->statement($view, $filters);
        $stmt = $this->db->prepare($sql . ' LIMIT ' . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function page(
        string $view,
        array $filters,
        string $search,
        string $orderBy,
        string $orderDirection,
        int $offset,
        int $limit
    ): array {
        if (!isset(self::PAGE_COLUMNS[$view])) {
            throw new InvalidArgumentException('Finance view does not support server-side paging.');
        }
        if ($view === 'audit_log' && !$this->tableExists('activity_logs')) {
            return ['total' => 0, 'filtered' => 0, 'rows' => []];
        }

        [$sourceSql, $sourceParams] = $this->statement($view, $filters);
        $baseSql = 'SELECT * FROM (' . $sourceSql . ') finance_page';
        $totalStatement = $this->db->prepare('SELECT COUNT(*) FROM (' . $sourceSql . ') finance_total');
        $totalStatement->execute($sourceParams);
        $total = (int) $totalStatement->fetchColumn();

        $searchSql = '';
        $searchParams = [];
        if ($search !== '') {
            $searchTerms = [];
            foreach (self::PAGE_COLUMNS[$view] as $column) {
                $searchTerms[] = 'CAST(`' . $column . '` AS CHAR) LIKE ?';
                $searchParams[] = '%' . $search . '%';
            }
            $searchSql = ' WHERE ' . implode(' OR ', $searchTerms);
        }

        $filteredStatement = $this->db->prepare('SELECT COUNT(*) FROM (' . $sourceSql . ') finance_filtered' . $searchSql);
        $filteredStatement->execute(array_merge($sourceParams, $searchParams));
        $filtered = (int) $filteredStatement->fetchColumn();

        $columns = self::PAGE_COLUMNS[$view];
        $safeOrderBy = in_array($orderBy, $columns, true) ? $orderBy : $columns[0];
        $safeDirection = strtolower($orderDirection) === 'asc' ? 'ASC' : 'DESC';
        $limitSql = $limit === -1
            ? ''
            : ' LIMIT ' . max(1, min($limit, 500)) . ' OFFSET ' . max(0, $offset);
        $pageStatement = $this->db->prepare(
            $baseSql . $searchSql . ' ORDER BY `' . $safeOrderBy . '` ' . $safeDirection
            . $limitSql
        );
        $pageStatement->execute(array_merge($sourceParams, $searchParams));

        return [
            'total' => $total,
            'filtered' => $filtered,
            'rows' => $pageStatement->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function statement(string $view, array $filters): array
    {
        $studentId = max(0, (int) ($filters['student_id'] ?? 0));
        $staffId = max(0, (int) ($filters['staff_id'] ?? 0));
        $yearId = max(0, (int) ($filters['academic_year_id'] ?? 0));

        return match ($view) {
            'fee_plans' => [
                'SELECT fp.id, fp.name, fp.charge_type_id, fp.academic_year_id, fp.grade_id, fp.status,
                        fpv.id AS latest_version_id, fpv.version_number AS latest_version,
                        fpv.status AS latest_version_status, COALESCE(SUM(fpi.gross_amount), 0) AS latest_total
                 FROM finance_fee_plans fp
                 LEFT JOIN finance_fee_plan_versions fpv ON fpv.id = (
                    SELECT MAX(v2.id) FROM finance_fee_plan_versions v2 WHERE v2.fee_plan_id = fp.id
                 )
                 LEFT JOIN finance_fee_plan_installments fpi ON fpi.fee_plan_version_id = fpv.id
                 GROUP BY fp.id ORDER BY fp.created_at DESC',
                [],
            ],
            'discounts' => [
                'SELECT id, code, name_ar, academic_year_id, scope_charge_type_key, version_number,
                        priority, combinable, cap_amount, effective_from, effective_to, status
                 FROM finance_discount_rules ORDER BY created_at DESC',
                [],
            ],
            'receipts' => [
                'SELECT r.id, r.receipt_number, r.student_account_id, sa.student_id, r.academic_year_id,
                        c.code AS cashbox_code, r.payment_method, r.gross_amount, r.status,
                        r.reversal_of, r.posted_at
                 FROM finance_receipts r
                 JOIN finance_student_accounts sa ON sa.id = r.student_account_id
                 LEFT JOIN finance_cashboxes c ON c.id = r.cashbox_id
                 ORDER BY r.created_at DESC',
                [],
            ],
            'debts' => [
                'SELECT b.student_id, b.academic_year_id, b.outstanding_due, b.unapplied_credit,
                        b.net_account_position, b.subledger_account_id, fsa.id AS student_account_id
                 FROM v_student_subledger_balances b
                 JOIN finance_student_accounts fsa ON fsa.student_id = b.student_id AND fsa.academic_year_id = b.academic_year_id
                 WHERE b.outstanding_due > 0 ORDER BY b.outstanding_due DESC',
                [],
            ],
            'student_accounts' => [
                'SELECT b.student_id, b.academic_year_id, b.outstanding_due, b.unapplied_credit,
                        b.net_account_position, b.subledger_account_id, fsa.id AS student_account_id
                 FROM v_student_subledger_balances b
                 JOIN finance_student_accounts fsa ON fsa.student_id = b.student_id AND fsa.academic_year_id = b.academic_year_id
                 ORDER BY b.student_id',
                [],
            ],
            'student_ledger' => [
                'SELECT st.id AS transaction_id, st.source_type, st.source_ref_id, st.status,
                        st.reversal_of, st.posted_at, sl.line_number, sl.bucket_code,
                        sl.amount_delta, sl.description, sl.installment_id
                 FROM finance_subledger_accounts sa
                 JOIN finance_subledger_transactions st ON st.subledger_account_id = sa.id
                 JOIN finance_subledger_lines sl ON sl.transaction_id = st.id
                 WHERE sa.party_type = ? AND (? = 0 OR sa.party_id = ?) AND (? = 0 OR sa.scope_key = ?)
                 ORDER BY st.id DESC, sl.line_number',
                ['student', $studentId, $studentId, $yearId, (string) $yearId],
            ],
            'staff_contracts' => [
                'SELECT id, staff_id, effective_from, effective_to, status,
                        provenance, history_confidence, approved_by, approved_at
                 FROM staff_compensation_contracts ORDER BY created_at DESC',
                [],
            ],
            'payroll_runs' => [
                'SELECT pr.id, pr.payroll_period_id, pp.start_date, pp.end_date, pr.version_number,
                        pr.is_settlement, pr.status, pr.reversal_of, pr.created_at
                 FROM payroll_runs pr LEFT JOIN payroll_periods pp ON pp.id = pr.payroll_period_id
                 ORDER BY pr.created_at DESC',
                [],
            ],
            'payroll_items' => [
                'SELECT id, payroll_run_id, staff_id, gross, total_deductions, net, status,
                        reversal_of, payslip_ref_number, payment_status, subledger_transaction_id
                 FROM payroll_run_items
                 WHERE (? = 0 OR staff_id = ?) ORDER BY payroll_run_id DESC, id DESC',
                [$staffId, $staffId],
            ],
            'staff_advances' => [
                'SELECT id, staff_id, amount, issue_date, reason, status, created_at
                 FROM staff_advances ORDER BY created_at DESC',
                [],
            ],
            'staff_ledger' => [
                'SELECT st.id AS transaction_id, sa.party_id AS staff_id, st.source_type, st.source_ref_id,
                        st.status, st.reversal_of, st.posted_at, sl.line_number, sl.bucket_code,
                        sl.amount_delta, sl.description
                 FROM finance_subledger_accounts sa
                 JOIN finance_subledger_transactions st ON st.subledger_account_id = sa.id
                 JOIN finance_subledger_lines sl ON sl.transaction_id = st.id
                 WHERE sa.party_type = ? AND sa.scope_key = ? AND (? = 0 OR sa.party_id = ?)
                 ORDER BY st.id DESC, sl.line_number',
                ['staff', 'STAFF_GLOBAL', $staffId, $staffId],
            ],
            'cashboxes' => [
                'SELECT c.id, c.code, c.name, c.type, c.is_active, c.accountability_role,
                        c.receipt_prefix, s.id AS settlement_id, s.settlement_date, s.expected_total, s.counted_total,
                        s.difference, s.status AS settlement_status
                 FROM finance_cashboxes c
                 LEFT JOIN finance_cashbox_settlements s ON s.id = (
                    SELECT MAX(s2.id) FROM finance_cashbox_settlements s2 WHERE s2.cashbox_id = c.id
                 ) ORDER BY c.code',
                [],
            ],
            'budgets' => [
                'SELECT b.id, b.name, b.academic_year_id, b.status,
                        bv.id AS budget_version_id, bv.version_number, bv.status AS version_status,
                        COALESCE(SUM(bl.planned_amount), 0) AS planned_total
                 FROM finance_budgets b
                 LEFT JOIN finance_budget_versions bv ON bv.budget_id = b.id
                 LEFT JOIN finance_budget_lines bl ON bl.budget_version_id = bv.id
                 GROUP BY b.id, bv.id ORDER BY b.created_at DESC, bv.version_number DESC',
                [],
            ],
            'archive' => [
                "SELECT 'finance_fee_plans' AS entity_type, id AS entity_id, name AS entity_name, status FROM finance_fee_plans WHERE status = 'archived'
                 UNION ALL SELECT 'finance_discount_rules', id, name_ar, status FROM finance_discount_rules WHERE status = 'archived'
                 UNION ALL SELECT 'finance_cashboxes', id, name, 'archived' FROM finance_cashboxes WHERE is_active = 0
                 UNION ALL SELECT 'accounting_accounts', id, name_ar, 'archived' FROM accounting_accounts WHERE is_active = 0
                 ORDER BY entity_type, entity_id DESC",
                [],
            ],
            'imports' => [
                'SELECT id, batch_id, operation_type, schema_version, source_file_ref, row_count,
                        error_count, status, reversal_of, created_by, created_at
                 FROM finance_import_batches ORDER BY created_at DESC',
                [],
            ],
            'buses' => [
                "SELECT sc.id AS charge_id, sa.student_id, sc.academic_year_id, ct.code AS charge_code,
                        ct.name_ar AS charge_name, sc.net_due, sc.status, sc.posted_at
                 FROM finance_student_charges sc
                 JOIN finance_student_accounts sa ON sa.id = sc.student_account_id
                 JOIN finance_charge_types ct ON ct.id = sc.charge_type_id
                 WHERE ct.code LIKE '%BUS%' OR ct.name_ar LIKE '%حافل%'
                 ORDER BY sc.created_at DESC",
                [],
            ],
            'journal' => [
                'SELECT je.id, je.entry_number, je.entry_date, je.source_type, je.source_ref_id, je.source_idempotency_key,
                        je.status, je.reversal_of, je.subledger_transaction_id,
                        COALESCE(SUM(jl.debit), 0) AS total_debit,
                        COALESCE(SUM(jl.credit), 0) AS total_credit
                 FROM accounting_journal_entries je
                 LEFT JOIN accounting_journal_lines jl ON jl.journal_entry_id = je.id
                 GROUP BY je.id ORDER BY je.entry_date DESC, je.id DESC',
                [],
            ],
            'accounts' => [
                'SELECT id, code, name_ar, type, is_control_account, is_active
                 FROM accounting_accounts ORDER BY code',
                [],
            ],
            'audit_log' => [
                "SELECT id, action, target_type, target_id, target_name, user_id, user_name, result, created_at
                 FROM activity_logs WHERE target_type LIKE 'finance_%' ORDER BY created_at DESC",
                [],
            ],
            'vouchers' => [
                'SELECT v.id, v.voucher_number, v.voucher_type, v.amount, v.entry_date, v.status,
                        v.reversal_of, c.code AS cashbox_code, v.posted_by, v.approved_by
                 FROM finance_vouchers v LEFT JOIN finance_cashboxes c ON c.id = v.cashbox_id
                 ORDER BY v.created_at DESC',
                [],
            ],
            'approvals' => [
                'SELECT id, operation_type, status, requested_by, requested_at, decided_by, decided_at,
                        decision_reason, result_ref_type, result_ref_id
                 FROM finance_approval_requests ORDER BY requested_at DESC, id DESC',
                [],
            ],
            'discount_awards' => [
                'SELECT a.id, a.student_account_id, sa.student_id, a.discount_rule_id, r.name_ar AS rule_name,
                        a.awarded_amount, a.reason, a.requested_by, a.approved_by, a.approved_at, a.status, a.created_at
                 FROM finance_discount_awards a
                 JOIN finance_student_accounts sa ON sa.id = a.student_account_id
                 JOIN finance_discount_rules r ON r.id = a.discount_rule_id
                 ORDER BY a.created_at DESC, a.id DESC',
                [],
            ],
            'periods' => [
                'SELECT fp.id, fp.academic_year_id, ay.name AS academic_year_name, fp.name,
                        fp.start_date, fp.end_date, fp.status, fp.closed_at, fp.closed_by,
                        fp.closed_approved_by, fp.reopen_reason, fp.reopened_at
                 FROM finance_periods fp
                 LEFT JOIN academic_years ay ON ay.id = fp.academic_year_id
                 ORDER BY fp.academic_year_id DESC, fp.start_date DESC, fp.id DESC',
                [],
            ],
            'refunds' => [
                'SELECT rf.id, rf.receipt_id, r.receipt_number, rf.refund_type, rf.payment_allocation_id,
                        rf.unapplied_credit_id, rf.signed_amount, rf.payment_method, rf.reason, rf.status,
                        rf.reversal_of, rf.posted_at, r.academic_year_id, sa.student_id
                 FROM finance_refunds rf
                 JOIN finance_receipts r ON r.id = rf.receipt_id
                 JOIN finance_student_accounts sa ON sa.id = r.student_account_id
                 ORDER BY rf.created_at DESC, rf.id DESC',
                [],
            ],
            'payroll_payments' => [
                'SELECT pp.id, pp.payroll_run_item_id, pri.payroll_run_id, pri.staff_id, pp.cashbox_id,
                        c.code AS cashbox_code, pp.amount, pp.payment_method, pp.status, pp.reversal_of,
                        pp.posted_at, pp.posted_by, pp.approved_by
                 FROM payroll_payments pp
                 JOIN payroll_run_items pri ON pri.id = pp.payroll_run_item_id
                 LEFT JOIN finance_cashboxes c ON c.id = pp.cashbox_id
                 ORDER BY pp.posted_at DESC, pp.id DESC',
                [],
            ],
            default => throw new InvalidArgumentException('Unsupported finance admin query.'),
        };
    }
}
