<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\SubledgerAccountRepository;
use PDO;
use RuntimeException;

final class PdoSubledgerAccountRepository implements SubledgerAccountRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findOrCreate(string $partyType, int $partyId, string $scopeKey): array
    {
        if (!in_array($partyType, ['student', 'staff'], true) || $partyId <= 0 || trim($scopeKey) === '') {
            throw new RuntimeException('Invalid sub-ledger account identity.');
        }
        if ($partyType === 'staff' && $scopeKey !== 'STAFF_GLOBAL') {
            throw new RuntimeException('Staff sub-ledger accounts must use the stable STAFF_GLOBAL scope.');
        }
        if ($partyType === 'student' && $scopeKey === 'STAFF_GLOBAL') {
            throw new RuntimeException('Student sub-ledger accounts require an academic-year scope.');
        }
        // Try find first.
        $stmt = $this->db->prepare(
            'SELECT id, party_type, party_id, scope_key, currency, status
             FROM finance_subledger_accounts
             WHERE party_type = ? AND party_id = ? AND scope_key = ?
             LIMIT 1'
        );
        $stmt->execute([$partyType, $partyId, $scopeKey]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account) {
            return $account;
        }

        // Create with FOR UPDATE protection inside a transaction (caller owns the txn).
        try {
            $this->db->prepare(
                'INSERT INTO finance_subledger_accounts (party_type, party_id, scope_key, currency, status)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$partyType, $partyId, $scopeKey, 'EGP', 'active']);
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $stmt->execute([$partyType, $partyId, $scopeKey]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($account) {
                return $account;
            }
            throw $exception;
        }

        return [
            'id' => (int) $this->db->lastInsertId(),
            'party_type' => $partyType,
            'party_id' => $partyId,
            'scope_key' => $scopeKey,
            'currency' => 'EGP',
            'status' => 'active',
        ];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, party_type, party_id, scope_key, currency, status
             FROM finance_subledger_accounts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
