<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Queries\FinanceReconciliationQuery;
use PDO;

final class PdoFinanceReconciliationQuery implements FinanceReconciliationQuery
{
    public function __construct(private PDO $db) {}

    public function partyJournalLinkAnomalies(): array
    {
        $sql = "SELECT st.id AS subledger_transaction_id, st.source_type, st.source_ref_id,
                       COUNT(je.id) AS linked_journals,
                       MAX(CASE WHEN je.status = 'posted' AND je.source_idempotency_key = st.source_idempotency_key THEN 1 ELSE 0 END) AS valid_link
                FROM finance_subledger_transactions st
                LEFT JOIN accounting_journal_entries je ON je.subledger_transaction_id = st.id
                WHERE st.status = 'posted'
                GROUP BY st.id, st.source_type, st.source_ref_id
                HAVING linked_journals <> 1 OR valid_link <> 1
                UNION ALL
                SELECT je.subledger_transaction_id, je.source_type, je.source_ref_id, 1, 0
                FROM accounting_journal_entries je
                LEFT JOIN finance_subledger_transactions st ON st.id = je.subledger_transaction_id
                WHERE je.subledger_transaction_id IS NOT NULL
                  AND (st.id IS NULL OR st.status <> 'posted' OR st.source_idempotency_key <> je.source_idempotency_key)";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pureGlLinkAnomalies(): array
    {
        $stmt = $this->db->query("SELECT id AS journal_entry_id, source_type, source_ref_id, subledger_transaction_id
                                  FROM accounting_journal_entries
                                  WHERE source_type IN ('voucher','voucher_reversal','manual','manual_reversal')
                                    AND subledger_transaction_id IS NOT NULL");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function accountScopeAnomalies(): array
    {
        $stmt = $this->db->query("SELECT id AS subledger_account_id, party_type, party_id, scope_key
                                  FROM finance_subledger_accounts
                                  WHERE (party_type = 'staff' AND scope_key <> 'STAFF_GLOBAL')
                                     OR (party_type = 'student' AND scope_key = 'STAFF_GLOBAL')");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function domainBucketMismatches(): array
    {
        $sql = "SELECT 'student_charge' AS source_type, sc.id AS source_ref_id,
                       CAST(sc.net_due AS DECIMAL(14,2)) AS expected_amount,
                       CAST(COALESCE(SUM(CASE WHEN sl.bucket_code = 'STUDENT_OUTSTANDING_DUE' THEN sl.amount_delta ELSE 0 END), 0) AS DECIMAL(14,2)) AS bucket_amount
                FROM finance_student_charges sc
                JOIN finance_subledger_transactions st ON st.id = sc.subledger_transaction_id AND st.status = 'posted'
                LEFT JOIN finance_subledger_lines sl ON sl.transaction_id = st.id
                WHERE sc.status = 'posted' AND sc.reversal_of IS NULL
                GROUP BY sc.id, sc.net_due
                HAVING CAST(sc.net_due AS DECIMAL(14,2)) <> CAST(COALESCE(SUM(CASE WHEN sl.bucket_code = 'STUDENT_OUTSTANDING_DUE' THEN sl.amount_delta ELSE 0 END), 0) AS DECIMAL(14,2))
                UNION ALL
                SELECT 'payroll_item', pri.id, CAST(pri.net AS DECIMAL(14,2)),
                       CAST(COALESCE(SUM(CASE WHEN sl.bucket_code = 'STAFF_PAYROLL_PAYABLE' THEN sl.amount_delta ELSE 0 END), 0) AS DECIMAL(14,2))
                FROM payroll_run_items pri
                JOIN finance_subledger_transactions st ON st.id = pri.subledger_transaction_id AND st.status = 'posted'
                LEFT JOIN finance_subledger_lines sl ON sl.transaction_id = st.id
                WHERE pri.status = 'locked' AND pri.reversal_of IS NULL
                GROUP BY pri.id, pri.net
                HAVING CAST(pri.net AS DECIMAL(14,2)) <> CAST(COALESCE(SUM(CASE WHEN sl.bucket_code = 'STAFF_PAYROLL_PAYABLE' THEN sl.amount_delta ELSE 0 END), 0) AS DECIMAL(14,2))
                UNION ALL
                SELECT 'staff_advance', sa.id, CAST(sa.amount AS DECIMAL(14,2)),
                       CAST(COALESCE(SUM(CASE WHEN sl.bucket_code = 'STAFF_ADVANCE_RECEIVABLE' THEN sl.amount_delta ELSE 0 END), 0) AS DECIMAL(14,2))
                FROM staff_advances sa
                JOIN finance_subledger_transactions st ON st.id = sa.subledger_transaction_id AND st.status = 'posted'
                LEFT JOIN finance_subledger_lines sl ON sl.transaction_id = st.id
                WHERE sa.status IN ('active','repaid','written_off')
                GROUP BY sa.id, sa.amount
                HAVING CAST(sa.amount AS DECIMAL(14,2)) <> CAST(COALESCE(SUM(CASE WHEN sl.bucket_code = 'STAFF_ADVANCE_RECEIVABLE' THEN sl.amount_delta ELSE 0 END), 0) AS DECIMAL(14,2))";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
