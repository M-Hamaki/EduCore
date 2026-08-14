<?php

declare(strict_types=1);

/**
 * Finance sub-ledger balance views + budget actuals view.
 *
 * Views created:
 *   - v_student_subledger_balances  — per student account: outstanding_due, unapplied_credit, net_account_position
 *   - v_staff_subledger_balances    — per staff account: payroll_payable, advance_receivable, settlement
 *   - v_budget_actuals              — per budget line: planned_amount vs actual from GL
 *
 * Preconditions: finance_subledger_* + accounting_journal_* + finance_budget_* tables must exist.
 * Rollback: DROP VIEW in reverse order.
 */
return static function (PDO $db): void {
    $viewExists = static function (string $view) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$view]);
        return (int) $stmt->fetchColumn() > 0;
    };

    $createView = static function (string $view, string $ddl) use ($db, $viewExists): void {
        if (!$viewExists($view)) {
            $db->exec($ddl);
        }
    };

    // Student sub-ledger balances: computed EXCLUSIVELY from finance_subledger_lines.
    $createView('v_student_subledger_balances', <<<'SQL'
CREATE VIEW v_student_subledger_balances AS
SELECT
    sa.id AS subledger_account_id,
    sa.party_id AS student_id,
    sa.scope_key AS academic_year_id,
    COALESCE(SUM(CASE WHEN sl.bucket_code = 'STUDENT_OUTSTANDING_DUE' THEN sl.amount_delta ELSE 0 END), 0) AS outstanding_due,
    COALESCE(SUM(CASE WHEN sl.bucket_code = 'STUDENT_UNAPPLIED_CREDIT' THEN sl.amount_delta ELSE 0 END), 0) AS unapplied_credit,
    COALESCE(SUM(CASE WHEN sl.bucket_code = 'STUDENT_OUTSTANDING_DUE' THEN sl.amount_delta ELSE 0 END), 0)
    - COALESCE(SUM(CASE WHEN sl.bucket_code = 'STUDENT_UNAPPLIED_CREDIT' THEN sl.amount_delta ELSE 0 END), 0) AS net_account_position
FROM finance_subledger_accounts sa
LEFT JOIN finance_subledger_transactions st ON st.subledger_account_id = sa.id AND st.status = 'posted'
LEFT JOIN finance_subledger_lines sl ON sl.transaction_id = st.id
WHERE sa.party_type = 'student'
GROUP BY sa.id, sa.party_id, sa.scope_key
SQL);

    // Staff sub-ledger balances.
    $createView('v_staff_subledger_balances', <<<'SQL'
CREATE VIEW v_staff_subledger_balances AS
SELECT
    sa.id AS subledger_account_id,
    sa.party_id AS staff_id,
    COALESCE(SUM(CASE WHEN sl.bucket_code = 'STAFF_PAYROLL_PAYABLE' THEN sl.amount_delta ELSE 0 END), 0) AS payroll_payable,
    COALESCE(SUM(CASE WHEN sl.bucket_code = 'STAFF_ADVANCE_RECEIVABLE' THEN sl.amount_delta ELSE 0 END), 0) AS advance_receivable,
    COALESCE(SUM(CASE WHEN sl.bucket_code = 'STAFF_SETTLEMENT' THEN sl.amount_delta ELSE 0 END), 0) AS settlement
FROM finance_subledger_accounts sa
LEFT JOIN finance_subledger_transactions st ON st.subledger_account_id = sa.id AND st.status = 'posted'
LEFT JOIN finance_subledger_lines sl ON sl.transaction_id = st.id
WHERE sa.party_type = 'staff' AND sa.scope_key = 'STAFF_GLOBAL'
GROUP BY sa.id, sa.party_id
SQL);

    // Budget actuals: computed EXCLUSIVELY from posted GL journal entries.
    $createView('v_budget_actuals', <<<'SQL'
CREATE VIEW v_budget_actuals AS
SELECT
    bl.id AS budget_line_id,
    bl.budget_version_id,
    bl.account_id,
    bl.cost_center_id,
    bl.period_id,
    bl.planned_amount,
    COALESCE(
        (SELECT SUM(jl.debit - jl.credit)
         FROM accounting_journal_lines jl
         JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = bl.account_id AND je.status = 'posted'
         AND (bl.cost_center_id IS NULL OR jl.cost_center_id = bl.cost_center_id)
         AND (bl.period_id IS NULL OR je.finance_period_id = bl.period_id)
        ), 0
    ) AS actual_amount,
    bl.planned_amount - COALESCE(
        (SELECT SUM(jl.debit - jl.credit)
         FROM accounting_journal_lines jl
         JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = bl.account_id AND je.status = 'posted'
         AND (bl.cost_center_id IS NULL OR jl.cost_center_id = bl.cost_center_id)
         AND (bl.period_id IS NULL OR je.finance_period_id = bl.period_id)
        ), 0
    ) AS variance
FROM finance_budget_lines bl
SQL);
};
