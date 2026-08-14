<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\BankAccountRepository;
use PDO;

final class PdoBankAccountRepository implements BankAccountRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByCashbox(int $cashboxId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM finance_bank_accounts WHERE cashbox_id = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$cashboxId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_bank_accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
