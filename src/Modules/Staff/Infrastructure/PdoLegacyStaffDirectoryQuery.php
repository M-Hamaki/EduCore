<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\LegacyStaffDirectoryQuery;
use PDO;

/** Staff-owned compatibility directory used by the legacy Attendance adapter. */
final class PdoLegacyStaffDirectoryQuery implements LegacyStaffDirectoryQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function listActiveStaff(): array
    {
        $statement = $this->db->query(
            "SELECT DISTINCT u.id, u.name
             FROM users u
             LEFT JOIN staff_profiles sp ON sp.user_id = u.id
             WHERE u.status = 'active'
               AND (u.role IN ('teacher','specialist','admin') OR sp.user_id IS NOT NULL)
             ORDER BY u.name, u.id"
        );
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function isEligibleActiveStaff(int $staffId): bool
    {
        if ($staffId <= 0) {
            return false;
        }
        $statement = $this->db->prepare(
            "SELECT 1
             FROM users u
             LEFT JOIN staff_profiles sp ON sp.user_id = u.id
             WHERE u.id = ? AND u.status = 'active'
               AND (u.role IN ('teacher','specialist','admin') OR sp.user_id IS NOT NULL)"
        );
        $statement->execute([$staffId]);
        return (bool) $statement->fetchColumn();
    }

    public function namesByIds(array $staffIds): array
    {
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds), static fn (int $id): bool => $id > 0)));
        if ($staffIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
        $statement = $this->db->prepare("SELECT id, name FROM users WHERE id IN ({$placeholders})");
        $statement->execute($staffIds);
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }
        return $names;
    }
}
