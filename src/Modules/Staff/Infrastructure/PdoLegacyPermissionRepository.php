<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\LegacyPermissionRepository;
use PDO;

/**
 * PDO implementation for the supported legacy staff_permissions adapter.
 *
 * New self-service requests live in staff_permission_requests. This class is
 * deliberately limited to the old route until its callers can migrate through
 * a tested rollout rather than a silent table replacement.
 */
final class PdoLegacyPermissionRepository implements LegacyPermissionRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function activeStaffList(): array
    {
        $statement = $this->db->query(
            "SELECT u.id, u.name
             FROM users u
             LEFT JOIN staff_profiles sp ON sp.user_id = u.id
             WHERE u.status = 'active'
               AND (u.role IN ('teacher', 'specialist', 'admin') OR sp.user_id IS NOT NULL)
             ORDER BY u.name"
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function permissionById(int $permissionId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_permissions WHERE id = ? LIMIT 1');
        $statement->execute([$permissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function permissions(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'p.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'p.permission_type = ?';
            $params[] = (string) $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'p.permission_date >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'p.permission_date <= ?';
            $params[] = (string) $filters['date_to'];
        }

        $statement = $this->db->prepare(
            "SELECT p.*, u.name AS staff_name
             FROM staff_permissions p
             JOIN users u ON p.user_id = u.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY p.permission_date DESC, p.created_at DESC"
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function permissionStats(): array
    {
        $typeStats = $this->normalizeStats(
            $this->db->query('SELECT permission_type, COUNT(*) AS cnt FROM staff_permissions GROUP BY permission_type')
                ->fetchAll(PDO::FETCH_KEY_PAIR)
        );
        $statusStats = $this->normalizeStats(
            $this->db->query('SELECT status, COUNT(*) AS cnt FROM staff_permissions GROUP BY status')
                ->fetchAll(PDO::FETCH_KEY_PAIR)
        );

        return [
            'type_stats' => $typeStats,
            'status_stats' => $statusStats,
            'total' => array_sum($typeStats),
        ];
    }

    public function lockPermission(int $permissionId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_permissions WHERE id = ? FOR UPDATE');
        $statement->execute([$permissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertPermission(array $permission): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_permissions
                (user_id, permission_type, permission_date, time_from, time_to, reason, status, approved_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            (int) $permission['user_id'],
            (string) $permission['permission_type'],
            (string) $permission['permission_date'],
            $permission['time_from'],
            $permission['time_to'],
            (string) $permission['reason'],
            (string) $permission['status'],
            $permission['approved_by'],
            (string) $permission['notes'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updatePermission(int $permissionId, array $permission): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_permissions
             SET user_id = ?, permission_type = ?, permission_date = ?, time_from = ?, time_to = ?,
                 reason = ?, status = ?, approved_by = ?, notes = ?
             WHERE id = ?'
        );
        $statement->execute([
            (int) $permission['user_id'],
            (string) $permission['permission_type'],
            (string) $permission['permission_date'],
            $permission['time_from'],
            $permission['time_to'],
            (string) $permission['reason'],
            (string) $permission['status'],
            $permission['approved_by'],
            (string) $permission['notes'],
            $permissionId,
        ]);

        return $statement->rowCount() === 1 || $this->lockPermission($permissionId) !== null;
    }

    public function deletePermission(int $permissionId): bool
    {
        $statement = $this->db->prepare('DELETE FROM staff_permissions WHERE id = ?');
        $statement->execute([$permissionId]);

        return $statement->rowCount() === 1;
    }

    /** @param array<mixed,mixed> $stats @return array<string,int> */
    private function normalizeStats(array $stats): array
    {
        $normalized = [];
        foreach ($stats as $key => $value) {
            $normalized[(string) $key] = (int) $value;
        }

        return $normalized;
    }
}
