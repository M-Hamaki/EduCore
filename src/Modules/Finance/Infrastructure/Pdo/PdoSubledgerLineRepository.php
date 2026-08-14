<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\SubledgerLineRepository;
use PDO;

final class PdoSubledgerLineRepository implements SubledgerLineRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, transaction_id, line_number, bucket_code, amount_delta, description, installment_id, cost_center_id
             FROM finance_subledger_lines WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findByTransaction(int $transactionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, transaction_id, line_number, bucket_code, amount_delta, description, installment_id, cost_center_id
             FROM finance_subledger_lines WHERE transaction_id = ? ORDER BY line_number'
        );
        $stmt->execute([$transactionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sumForBucket(int $subledgerAccountId, string $bucketCode): string
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(sl.amount_delta), 0)
             FROM finance_subledger_lines sl
             INNER JOIN finance_subledger_transactions st ON st.id = sl.transaction_id
             WHERE st.subledger_account_id = ? AND sl.bucket_code = ? AND st.status = ?'
        );
        $stmt->execute([$subledgerAccountId, $bucketCode, 'posted']);

        return (string) $stmt->fetchColumn();
    }

    public function sumForPartyTypeBucket(string $partyType, string $bucketCode): string
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(sl.amount_delta), 0)
             FROM finance_subledger_lines sl
             INNER JOIN finance_subledger_transactions st ON st.id = sl.transaction_id
             INNER JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id
             WHERE sa.party_type = ? AND sl.bucket_code = ? AND st.status = ?'
        );
        $stmt->execute([$partyType, $bucketCode, 'posted']);
        return (string) $stmt->fetchColumn();
    }

    public function sumForTransaction(int $transactionId): string
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(amount_delta), 0) FROM finance_subledger_lines WHERE transaction_id = ?'
        );
        $stmt->execute([$transactionId]);

        return (string) $stmt->fetchColumn();
    }
}
