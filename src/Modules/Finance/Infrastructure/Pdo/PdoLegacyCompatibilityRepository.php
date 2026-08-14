<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\LegacyCompatibilityRepository;
use PDO;
use RuntimeException;

final class PdoLegacyCompatibilityRepository implements LegacyCompatibilityRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findActive(string $sourceType, string $sourceKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM finance_legacy_compatibility_mappings
             WHERE source_type = ? AND source_key = ? AND status = ?
             ORDER BY version_number DESC LIMIT 1'
        );
        $stmt->execute([$sourceType, $sourceKey, 'active']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findActiveTarget(string $targetType, int $targetId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM finance_legacy_compatibility_mappings
             WHERE target_type = ? AND target_id = ? AND status = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$targetType, $targetId, 'active']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function storeVersion(
        string $sourceType,
        string $sourceKey,
        string $targetType,
        int $targetId,
        ?int $academicYearId,
        array $payload,
        int $createdBy
    ): int {
        $lock = $this->db->prepare(
            'SELECT id, version_number FROM finance_legacy_compatibility_mappings
             WHERE source_type = ? AND source_key = ?
             ORDER BY version_number DESC FOR UPDATE'
        );
        $lock->execute([$sourceType, $sourceKey]);
        $versions = $lock->fetchAll(PDO::FETCH_ASSOC);
        $nextVersion = $versions === [] ? 1 : ((int) $versions[0]['version_number'] + 1);

        $this->db->prepare(
            'UPDATE finance_legacy_compatibility_mappings
             SET status = ?, superseded_at = NOW()
             WHERE source_type = ? AND source_key = ? AND status = ?'
        )->execute(['superseded', $sourceType, $sourceKey, 'active']);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db->prepare(
            'INSERT INTO finance_legacy_compatibility_mappings
                (source_type, source_key, version_number, target_type, target_id,
                 academic_year_id, payload_json, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $sourceType,
            $sourceKey,
            $nextVersion,
            $targetType,
            $targetId,
            $academicYearId,
            $encoded,
            'active',
            $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function archive(string $sourceType, string $sourceKey): void
    {
        $stmt = $this->db->prepare(
            'UPDATE finance_legacy_compatibility_mappings
             SET status = ?, superseded_at = NOW()
             WHERE source_type = ? AND source_key = ? AND status = ?'
        );
        $stmt->execute(['archived', $sourceType, $sourceKey, 'active']);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Active legacy compatibility mapping was not found.');
        }
    }
}
