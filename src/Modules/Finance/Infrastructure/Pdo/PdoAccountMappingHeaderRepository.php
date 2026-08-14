<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\AccountMappingHeaderRepository;
use PDO;

final class PdoAccountMappingHeaderRepository implements AccountMappingHeaderRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findActiveHeader(): ?array
    {
        $stmt = $this->db->query(
            "SELECT * FROM accounting_account_mapping_headers WHERE status = 'active' ORDER BY version_number DESC LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function create(int $versionNumber, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO accounting_account_mapping_headers (version_number, status, created_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$versionNumber, 'draft', $createdBy]);

        return (int) $this->db->lastInsertId();
    }
}
