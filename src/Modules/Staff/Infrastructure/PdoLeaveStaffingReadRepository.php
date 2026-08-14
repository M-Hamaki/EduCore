<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\LeaveStaffingReadRepository;
use InvalidArgumentException;
use PDO;

/**
 * Locked Staff-only read adapter for leave operating-capacity checks.
 *
 * It locks the scoped active assignment rows before reading leave-day
 * allocations. The outer LeaveRequestRepository transaction retries a
 * transient deadlock, so simultaneous submissions cannot both reserve the
 * final available employee in one scope.
 */
final class PdoLeaveStaffingReadRepository implements LeaveStaffingReadRepository
{
    /** @var list<string> */
    private const SCOPE_TYPES = ['global', 'org_unit', 'job_title', 'group', 'staff'];

    public function __construct(private PDO $db)
    {
    }

    public function blackoutsFor(
        int $policyVersionId,
        array $assignment,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt
    ): array {
        if ($policyVersionId <= 0 || $toAt <= $fromAt) {
            throw new InvalidArgumentException('LEAVE_STAFFING_BLACKOUT_QUERY_INVALID');
        }
        $assignment = $this->assignment($assignment);
        $params = [
            ':policy_version_id' => $policyVersionId,
            ':from_at' => $this->instant($fromAt),
            ':to_at' => $this->instant($toAt),
        ];
        $scopeSql = ["(b.scope_type = 'global' AND b.scope_id = 0)"];
        $scopeSql[] = "(b.scope_type = 'org_unit' AND b.scope_id = :org_unit_id)";
        $params[':org_unit_id'] = $assignment['org_unit_id'];
        $scopeSql[] = "(b.scope_type = 'job_title' AND b.scope_id = :job_title_id)";
        $params[':job_title_id'] = $assignment['job_title_id'];
        $scopeSql[] = "(b.scope_type = 'staff' AND b.scope_id = :staff_user_id)";
        $params[':staff_user_id'] = $assignment['staff_user_id'];
        if ($assignment['group_ids'] !== []) {
            $groupPlaceholders = [];
            foreach ($assignment['group_ids'] as $index => $groupId) {
                $placeholder = ':group_' . $index;
                $groupPlaceholders[] = $placeholder;
                $params[$placeholder] = $groupId;
            }
            $scopeSql[] = "(b.scope_type = 'group' AND b.scope_id IN (" . implode(', ', $groupPlaceholders) . '))';
        }
        $statement = $this->db->prepare(
            "SELECT b.id, b.scope_type, b.scope_id, b.from_at, b.to_at, b.label,
                    b.requires_override, b.override_role_key
             FROM staff_leave_policy_blackouts b
             WHERE b.policy_version_id = :policy_version_id
               AND b.status = 'active'
               AND b.from_at < :to_at
               AND b.to_at > :from_at
               AND (" . implode(' OR ', $scopeSql) . ')
             ORDER BY b.from_at ASC, b.id ASC'
        );
        $statement->execute($params);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'scope_type' => (string) $row['scope_type'],
                'scope_id' => (int) $row['scope_id'],
                'from_at' => (string) $row['from_at'],
                'to_at' => (string) $row['to_at'],
                'label' => (string) $row['label'],
                'requires_override' => (bool) $row['requires_override'],
                'override_role_key' => $row['override_role_key'] === null
                    ? null
                    : (string) $row['override_role_key'],
            ];
        }

        return $result;
    }

    public function availabilityForScopeForUpdate(
        string $scopeType,
        int $scopeId,
        DateTimeImmutable $workDate,
        ?int $excludingRequestId = null
    ): array {
        [$scopeType, $scopeId] = $this->scope($scopeType, $scopeId);
        $date = $workDate->format('Y-m-d');
        $params = [
            ':effective_date' => $date,
            ':effective_date_again' => $date,
        ];
        $sql = "SELECT a.id, a.staff_user_id
                FROM staff_assignments a
                WHERE a.assignment_kind = 'primary'
                  AND a.employment_status = 'active'
                  AND a.valid_from <= :effective_date
                  AND (a.valid_to IS NULL OR a.valid_to >= :effective_date_again)";
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
        $statement = $this->db->prepare($sql . ' ORDER BY a.staff_user_id ASC, a.id ASC FOR UPDATE');
        $statement->execute($params);

        /** @var array<int,int> $assignmentCounts */
        $assignmentCounts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $staffId = (int) $row['staff_user_id'];
            $assignmentCounts[$staffId] = ($assignmentCounts[$staffId] ?? 0) + 1;
        }
        $staffIds = array_keys($assignmentCounts);
        sort($staffIds, SORT_NUMERIC);
        $conflictingStaffIds = [];
        foreach ($assignmentCounts as $staffId => $count) {
            if ($count !== 1) {
                $conflictingStaffIds[] = $staffId;
            }
        }
        sort($conflictingStaffIds, SORT_NUMERIC);
        if ($staffIds === []) {
            return [
                'staff_ids' => [],
                'absent_staff_ids' => [],
                'conflicting_staff_ids' => [],
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($staffIds), '?'));
        $absenceSql = "SELECT DISTINCT r.id, r.staff_user_id
                       FROM staff_leave_request_days d
                       INNER JOIN staff_leave_requests r ON r.id = d.request_id
                       WHERE d.work_date = ?
                         AND d.requested_units > 0
                         AND r.status IN ('pending_approval', 'approved', 'cancellation_requested')
                         AND r.staff_user_id IN (" . $placeholders . ')';
        $absenceParams = [$date, ...$staffIds];
        if ($excludingRequestId !== null) {
            $absenceSql .= ' AND r.id <> ?';
            $absenceParams[] = $excludingRequestId;
        }
        $absenceStatement = $this->db->prepare($absenceSql . ' ORDER BY r.staff_user_id ASC, r.id ASC FOR UPDATE');
        $absenceStatement->execute($absenceParams);
        $absent = [];
        foreach ($absenceStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $absent[(int) $row['staff_user_id']] = true;
        }
        $absentStaffIds = array_keys($absent);
        sort($absentStaffIds, SORT_NUMERIC);

        return [
            'staff_ids' => $staffIds,
            'absent_staff_ids' => $absentStaffIds,
            'conflicting_staff_ids' => $conflictingStaffIds,
        ];
    }

    /** @param array<string,mixed> $assignment @return array{staff_user_id:int,org_unit_id:?int,job_title_id:?int,group_ids:list<int>} */
    private function assignment(array $assignment): array
    {
        $groupIds = [];
        foreach ((array) ($assignment['group_ids'] ?? []) as $groupId) {
            if (filter_var($groupId, FILTER_VALIDATE_INT) === false || (int) $groupId <= 0) {
                throw new InvalidArgumentException('LEAVE_STAFFING_ASSIGNMENT_INVALID');
            }
            $groupIds[(int) $groupId] = (int) $groupId;
        }
        $groupIds = array_values($groupIds);
        sort($groupIds, SORT_NUMERIC);

        return [
            'staff_user_id' => $this->positiveId($assignment['staff_user_id'] ?? null),
            // A global or staff-scoped policy remains valid where the dated
            // assignment deliberately has no organization/job-title value.
            'org_unit_id' => $this->nullablePositiveId($assignment['org_unit_id'] ?? null),
            'job_title_id' => $this->nullablePositiveId($assignment['job_title_id'] ?? null),
            'group_ids' => $groupIds,
        ];
    }

    /** @return array{0:string,1:int} */
    private function scope(string $scopeType, int $scopeId): array
    {
        $scopeType = strtolower(trim($scopeType));
        if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new InvalidArgumentException('LEAVE_STAFFING_SCOPE_INVALID');
        }
        if (($scopeType === 'global' && $scopeId !== 0)
            || ($scopeType !== 'global' && $scopeId <= 0)) {
            throw new InvalidArgumentException('LEAVE_STAFFING_SCOPE_INVALID');
        }

        return [$scopeType, $scopeId];
    }

    private function positiveId(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException('LEAVE_STAFFING_ASSIGNMENT_INVALID');
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value);
    }

    private function instant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
