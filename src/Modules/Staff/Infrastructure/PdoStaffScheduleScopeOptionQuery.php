<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\StaffScheduleScopeOptionQuery;
use InvalidArgumentException;
use PDO;

/** Read-only named choices for Attendance policy scopes. */
final class PdoStaffScheduleScopeOptionQuery implements StaffScheduleScopeOptionQuery
{
    private const TYPES = ['global', 'org_unit', 'job_title', 'group', 'staff'];

    public function __construct(private PDO $db)
    {
    }

    public function options(): array
    {
        return [
            'org_unit' => $this->namedRows(
                "SELECT id, name, code FROM staff_org_units WHERE status = 'active' ORDER BY name, code"
            ),
            'job_title' => $this->namedRows(
                "SELECT id, name, code FROM staff_job_titles WHERE status = 'active' ORDER BY name, code"
            ),
            'group' => $this->namedRows(
                "SELECT id, name, code FROM staff_policy_groups WHERE status = 'active' ORDER BY name, code"
            ),
            'staff' => $this->namedRows(
                "SELECT DISTINCT u.id, u.name,
                        COALESCE(NULLIF(sp.employee_code, ''), CONCAT('#', u.id)) AS code
                 FROM users u
                 JOIN staff_assignments a ON a.staff_user_id = u.id AND a.assignment_kind = 'primary'
                 LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                 WHERE u.status = 'active'
                 ORDER BY u.name, u.id"
            ),
        ];
    }

    public function isSelectable(string $scopeType, ?int $scopeId, DateTimeImmutable $atDate): bool
    {
        $scopeType = strtolower(trim($scopeType));
        if (!in_array($scopeType, self::TYPES, true)) {
            return false;
        }
        if ($scopeType === 'global') {
            return $scopeId === null || $scopeId === 0;
        }
        if ($scopeId === null || $scopeId <= 0) {
            return false;
        }

        $date = $atDate->format('Y-m-d');
        [$sql, $params] = match ($scopeType) {
            'org_unit' => [
                "SELECT 1 FROM staff_org_units WHERE id = ? AND status = 'active'
                 AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)",
                [$scopeId, $date, $date],
            ],
            'job_title' => [
                "SELECT 1 FROM staff_job_titles WHERE id = ? AND status = 'active'
                 AND active_from <= ? AND (active_to IS NULL OR active_to >= ?)",
                [$scopeId, $date, $date],
            ],
            'group' => [
                "SELECT 1 FROM staff_policy_groups WHERE id = ? AND status = 'active'
                 AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)",
                [$scopeId, $date, $date],
            ],
            'staff' => [
                "SELECT 1 FROM users u
                 JOIN staff_assignments a ON a.staff_user_id = u.id AND a.assignment_kind = 'primary'
                 WHERE u.id = ? AND u.status = 'active'
                   AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?)
                 LIMIT 1",
                [$scopeId, $date, $date],
            ],
            default => throw new InvalidArgumentException('Unsupported schedule scope type.'),
        };
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return (bool) $statement->fetchColumn();
    }

    /** @return list<array{id:int,label:string}> */
    private function namedRows(string $sql): array
    {
        $rows = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $code = trim((string) ($row['code'] ?? ''));
            $rows[] = [
                'id' => $id,
                'label' => ($name !== '' ? $name : 'عنصر #' . $id) . ($code !== '' ? ' — ' . $code : ''),
            ];
        }
        return $rows;
    }
}
