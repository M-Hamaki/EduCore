<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\LeavePolicyReadRepository;
use PDO;

/** Effective-dated leave type/policy rows; precedence remains in the application service. */
final class PdoLeavePolicyReadRepository implements LeavePolicyReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findType(int $leaveTypeId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_leave_types WHERE id = ? LIMIT 1');
        $statement->execute([$leaveTypeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function candidateVersionsFor(
        int $leaveTypeId,
        int $staffId,
        array $assignment,
        DateTimeImmutable $effectiveAt
    ): array {
        [$scopePredicate, $scopeParams] = $this->scopePredicate('scope_row', $staffId, $assignment);
        $instant = $effectiveAt->format('Y-m-d H:i:s.u');
        $statement = $this->db->prepare(
            "SELECT policy_row.*, policy_row.id AS policy_version_id,
                    scope_row.scope_type, scope_row.scope_id, scope_row.priority AS scope_priority,
                    scope_row.valid_from AS scope_valid_from, scope_row.valid_to AS scope_valid_to,
                    scope_row.status AS scope_status, scope_row.minimum_available_staff,
                    scope_row.max_absence_percentage, scope_row.requires_staffing_override,
                    scope_row.override_role_key
             FROM staff_leave_policy_versions policy_row
             JOIN staff_leave_policy_scopes scope_row ON scope_row.policy_version_id = policy_row.id
             WHERE policy_row.leave_type_id = :leave_type_id
               AND policy_row.state = 'published'
               AND policy_row.valid_from <= :effective_at
               AND (policy_row.valid_to IS NULL OR policy_row.valid_to > :effective_at_again)
               AND scope_row.status = 'active'
               AND scope_row.valid_from <= :scope_effective_at
               AND (scope_row.valid_to IS NULL OR scope_row.valid_to > :scope_effective_at_again)
               AND ({$scopePredicate})
             ORDER BY scope_row.priority DESC, policy_row.version_no DESC, scope_row.id DESC"
        );
        $statement->execute(array_merge([
            'leave_type_id' => $leaveTypeId,
            'effective_at' => $instant,
            'effective_at_again' => $instant,
            'scope_effective_at' => $instant,
            'scope_effective_at_again' => $instant,
        ], $scopeParams));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $assignment @return array{0:string,1:array<string,int>} */
    private function scopePredicate(string $alias, int $staffId, array $assignment): array
    {
        $clauses = ["({$alias}.scope_type = 'global' AND {$alias}.scope_id = 0)"];
        $params = [];
        $scopes = [
            'staff' => $staffId,
            'org_unit' => (int) ($assignment['org_unit_id'] ?? 0),
            'job_title' => (int) ($assignment['job_title_id'] ?? 0),
        ];
        foreach ($scopes as $scopeType => $scopeId) {
            if ($scopeId <= 0) {
                continue;
            }
            $key = 'leave_scope_' . $scopeType;
            $clauses[] = "({$alias}.scope_type = '{$scopeType}' AND {$alias}.scope_id = :{$key})";
            $params[$key] = $scopeId;
        }
        $groupIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($assignment['group_ids'] ?? [])),
            static fn (int $groupId): bool => $groupId > 0
        )));
        sort($groupIds, SORT_NUMERIC);
        if ($groupIds !== []) {
            $placeholders = [];
            foreach ($groupIds as $index => $groupId) {
                $key = 'leave_scope_group_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $groupId;
            }
            $clauses[] = "({$alias}.scope_type = 'group' AND {$alias}.scope_id IN ("
                . implode(', ', $placeholders) . '))';
        }

        return [implode(' OR ', $clauses), $params];
    }
}
