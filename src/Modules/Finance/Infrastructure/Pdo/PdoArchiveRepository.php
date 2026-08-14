<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\ArchiveRepository;
use PDO;
use RuntimeException;

final class PdoArchiveRepository implements ArchiveRepository
{
    private const TARGETS = [
        'finance_fee_plans' => ['column' => 'status', 'archived' => 'archived', 'restored' => 'draft'],
        'finance_discount_rules' => ['column' => 'status', 'archived' => 'archived', 'restored' => 'draft'],
        'finance_cashboxes' => ['column' => 'is_active', 'archived' => 0, 'restored' => 1],
        'accounting_accounts' => ['column' => 'is_active', 'archived' => 0, 'restored' => 1],
    ];

    public function __construct(private PDO $db)
    {
    }

    public function archive(string $entityType, int $entityId): void
    {
        $target = $this->target($entityType);
        $this->update($entityType, $target['column'], $target['archived'], $entityId);
    }

    public function canRestore(string $entityType, int $entityId): bool
    {
        $target = $this->target($entityType);
        $stmt = $this->db->prepare(sprintf('SELECT `%s` FROM `%s` WHERE id = ? LIMIT 1', $target['column'], $entityType));
        $stmt->execute([$entityId]);
        $value = $stmt->fetchColumn();
        return $value !== false && (string) $value === (string) $target['archived'];
    }

    public function restore(string $entityType, int $entityId): void
    {
        if (!$this->canRestore($entityType, $entityId)) {
            throw new RuntimeException('Archived finance entity was not found or cannot be restored.');
        }
        $target = $this->target($entityType);
        $this->update($entityType, $target['column'], $target['restored'], $entityId);
    }

    private function update(string $table, string $column, mixed $value, int $entityId): void
    {
        $stmt = $this->db->prepare(sprintf('UPDATE `%s` SET `%s` = ? WHERE id = ?', $table, $column));
        $stmt->execute([$value, $entityId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Finance archive target was not found or its state did not change.');
        }
    }

    /** @return array{column:string,archived:mixed,restored:mixed} */
    private function target(string $entityType): array
    {
        if (!isset(self::TARGETS[$entityType])) {
            throw new RuntimeException('Unsupported finance archive entity type.');
        }
        return self::TARGETS[$entityType];
    }
}
