<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\PermissionPolicyReadRepository;
use PDO;

/**
 * PDO adapter for the Staff-owned, effective-dated permission-policy tables.
 *
 * Historical organization/group facts are supplied by the Staff assignment
 * contract, so this adapter does not fall back to profile or current
 * membership tables while resolving a request.
 */
final class PdoPermissionPolicyReadRepository implements PermissionPolicyReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findType(int $permissionTypeId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, name, coverage_behavior, requires_reason,
                    requires_custom_label, requires_attachment, allow_retroactive, status
             FROM staff_permission_types
             WHERE id = ?'
        );
        $statement->execute([$permissionTypeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function candidateVersionsFor(
        int $permissionTypeId,
        int $staffId,
        array $assignment,
        DateTimeImmutable $effectiveAt
    ): array {
        [$scopePredicate, $scopeParams] = $this->scopePredicate('scope_row', $staffId, $assignment);
        $instant = $effectiveAt->format('Y-m-d H:i:s.u');
        $statement = $this->db->prepare(
            'SELECT policy_version.id AS version_id,
                    policy_version.permission_type_id,
                    policy_version.version_no,
                    policy_version.state,
                    policy_version.valid_from,
                    policy_version.valid_to,
                    policy_version.timezone,
                    policy_version.max_requests_per_month,
                    policy_version.max_minutes_per_request,
                    policy_version.max_minutes_per_month,
                    policy_version.min_notice_minutes,
                    policy_version.retroactive_limit_days,
                    policy_version.reserve_on_submit,
                    policy_version.allow_overlap,
                    policy_version.allow_quota_override,
                    policy_version.quota_override_max_minutes,
                    scope_row.id AS scope_id_record,
                    scope_row.scope_type,
                    scope_row.scope_id,
                    scope_row.priority AS scope_priority,
                    scope_row.valid_from AS scope_valid_from,
                    scope_row.valid_to AS scope_valid_to
             FROM staff_permission_policy_versions policy_version
             JOIN staff_permission_policy_scopes scope_row
               ON scope_row.policy_version_id = policy_version.id
              AND scope_row.status = \'active\'
             WHERE policy_version.permission_type_id = ?
               AND policy_version.state = \'published\'
               AND policy_version.valid_from <= ?
               AND (policy_version.valid_to IS NULL OR policy_version.valid_to > ?)
               AND scope_row.valid_from <= ?
               AND (scope_row.valid_to IS NULL OR scope_row.valid_to > ?)
               AND (' . $scopePredicate . ')
             ORDER BY policy_version.id, scope_row.id'
        );
        $statement->execute(array_merge(
            [$permissionTypeId, $instant, $instant, $instant, $instant],
            $scopeParams
        ));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $assignment
     * @return array{0:string,1:list<int>}
     */
    private function scopePredicate(string $alias, int $staffId, array $assignment): array
    {
        $clauses = ["({$alias}.scope_type = 'global' AND {$alias}.scope_id = 0)"];
        $params = [];
        if ($staffId > 0) {
            $clauses[] = "({$alias}.scope_type = 'staff' AND {$alias}.scope_id = ?)";
            $params[] = $staffId;
        }
        foreach (['org_unit' => 'org_unit_id', 'job_title' => 'job_title_id'] as $scopeType => $field) {
            $scopeId = (int) ($assignment[$field] ?? 0);
            if ($scopeId > 0) {
                $clauses[] = "({$alias}.scope_type = '{$scopeType}' AND {$alias}.scope_id = ?)";
                $params[] = $scopeId;
            }
        }
        $groupIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($assignment['group_ids'] ?? [])),
            static fn (int $groupId): bool => $groupId > 0
        )));
        if ($groupIds !== []) {
            sort($groupIds, SORT_NUMERIC);
            $clauses[] = "({$alias}.scope_type = 'group' AND {$alias}.scope_id IN ("
                . implode(',', array_fill(0, count($groupIds), '?')) . '))';
            array_push($params, ...$groupIds);
        }

        return [implode(' OR ', $clauses), $params];
    }
}
