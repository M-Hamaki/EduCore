<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\StaffPopulationAtDateQuery;
use InvalidArgumentException;
use PDO;

/** Read-only Staff adapter; Attendance never reaches into Staff tables directly. */
final class PdoStaffPopulationAtDateQuery implements StaffPopulationAtDateQuery
{
    private const SCOPE_TYPES = ['global', 'org_unit', 'job_title', 'group', 'staff'];

    public function __construct(private PDO $db)
    {
    }

    public function forScope(
        string $scopeType,
        ?int $scopeId,
        DateTimeImmutable $atDate
    ): array {
        $scopeType = strtolower(trim($scopeType));
        if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported staff population scope.');
        }
        if ($scopeType === 'global') {
            if ($scopeId !== null && $scopeId !== 0) {
                throw new InvalidArgumentException('Global staff population scope cannot have an id.');
            }
            $scopeId = null;
        } elseif ($scopeId === null || $scopeId <= 0) {
            throw new InvalidArgumentException('A scoped staff population query requires a positive id.');
        }

        $date = $atDate->format('Y-m-d');
        $sql = "SELECT a.id, a.staff_user_id, a.org_unit_id, a.job_title_id,
                       a.employment_status
                 FROM staff_assignments a
                 WHERE a.assignment_kind = 'primary'
                   AND a.employment_status IN ('active', 'rehired')
                   AND a.valid_from <= :effective_date
                  AND (a.valid_to IS NULL OR a.valid_to >= :effective_date_again)";
        $params = [
            ':effective_date' => $date,
            ':effective_date_again' => $date,
        ];

        if ($scopeType === 'org_unit') {
            $sql .= ' AND a.org_unit_id = :scope_id';
            $params[':scope_id'] = $scopeId;
        } elseif ($scopeType === 'job_title') {
            $sql .= ' AND a.job_title_id = :scope_id';
            $params[':scope_id'] = $scopeId;
        } elseif ($scopeType === 'staff') {
            $sql .= ' AND a.staff_user_id = :scope_id';
            $params[':scope_id'] = $scopeId;
        } elseif ($scopeType === 'group') {
            $sql .= " AND EXISTS (
                SELECT 1
                FROM staff_policy_group_memberships gm
                WHERE gm.staff_user_id = a.staff_user_id
                  AND gm.group_id = :scope_id
                  AND gm.status = 'active'
                  AND gm.valid_from <= :membership_date
                  AND (gm.valid_to IS NULL OR gm.valid_to >= :membership_date_again)
            )";
            $params[':scope_id'] = $scopeId;
            $params[':membership_date'] = $date;
            $params[':membership_date_again'] = $date;
        }

        $sql .= ' ORDER BY a.staff_user_id, a.valid_from DESC, a.id DESC';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<int,list<array<string,mixed>>> $rowsByStaff */
        $rowsByStaff = [];
        foreach ($rows as $row) {
            $rowsByStaff[(int)$row['staff_user_id']][] = $row;
        }

        $staffIds = array_keys($rowsByStaff);
        $groupsByStaff = $this->effectiveGroups($staffIds, $date);
        $staff = [];
        $conflicts = [];
        foreach ($rowsByStaff as $staffId => $staffRows) {
            if (count($staffRows) !== 1) {
                $assignmentIds = array_map(
                    static fn(array $row): int => (int)$row['id'],
                    $staffRows
                );
                sort($assignmentIds, SORT_NUMERIC);
                $conflicts[] = [
                    'staff_id' => $staffId,
                    'assignment_ids' => $assignmentIds,
                    'reason' => 'overlapping_primary_assignments',
                ];
                continue;
            }

            $row = $staffRows[0];
            $staff[] = [
                'staff_id' => $staffId,
                'assignment_id' => (int)$row['id'],
                'org_unit_id' => (int)$row['org_unit_id'],
                'job_title_id' => (int)$row['job_title_id'],
                'group_ids' => $groupsByStaff[$staffId] ?? [],
                'employment_status' => (string)$row['employment_status'],
            ];
        }

        return ['staff' => $staff, 'conflicts' => $conflicts];
    }

    /** @param list<int> $staffIds @return array<int,list<int>> */
    private function effectiveGroups(array $staffIds, string $date): array
    {
        if ($staffIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
        $statement = $this->db->prepare(
            "SELECT staff_user_id, group_id
             FROM staff_policy_group_memberships
             WHERE staff_user_id IN ({$placeholders})
               AND status = 'active'
               AND valid_from <= ?
               AND (valid_to IS NULL OR valid_to >= ?)
             ORDER BY staff_user_id, group_id"
        );
        $statement->execute([...$staffIds, $date, $date]);

        $groups = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $staffId = (int)$row['staff_user_id'];
            $groups[$staffId][] = (int)$row['group_id'];
        }

        return $groups;
    }
}
