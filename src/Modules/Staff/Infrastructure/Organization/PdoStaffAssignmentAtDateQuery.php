<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Organization;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\StaffAccessEligibilityQuery;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use InvalidArgumentException;
use PDO;

/**
 * Staff-owned dated read boundary for lifecycle and current protected access.
 *
 * Assignment selection never falls back to a current profile. A current-access
 * decision is deliberately narrow: only fixed Attendance capability shapes are
 * recognized here, and every decision re-reads the account, employment state,
 * and relevant relationship at the supplied instant.
 */
final class PdoStaffAssignmentAtDateQuery implements StaffAssignmentAtDateQuery, StaffAccessEligibilityQuery
{
    /** @var list<string> */
    private const ACCESSIBLE_EMPLOYMENT_STATUSES = ['active', 'rehired'];
    public function __construct(private PDO $db)
    {
    }

    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
    {
        if ($staffId <= 0) {
            throw new InvalidArgumentException('A dated staff assignment query requires a positive staff id.');
        }

        $date = $atDate->format('Y-m-d');
        $assignments = $this->effectivePrimaryAssignments($staffId, $date);
        if ($assignments === []) {
            return null;
        }
        if (count($assignments) !== 1) {
            $ids = array_map(static fn (array $assignment): int => (int) $assignment['id'], $assignments);
            sort($ids, SORT_NUMERIC);
            throw new DomainException('AMBIGUOUS_STAFF_ASSIGNMENT: ' . implode(', ', $ids));
        }

        $assignment = $assignments[0];

        return [
            'assignment_id' => (int) $assignment['id'],
            'org_unit_id' => (int) $assignment['org_unit_id'],
            'job_title_id' => (int) $assignment['job_title_id'],
            'group_ids' => $this->effectiveGroups($staffId, $date),
            'employment_status' => (string) $assignment['employment_status'],
        ];
    }

    public function assertCurrentAccess(
        int $userId,
        string $capability,
        string $resourceRef,
        DateTimeImmutable $atInstant
    ): array {
        if ($userId <= 0) {
            return $this->denied('not_staff', 'invalid_actor');
        }

        $relationship = $this->capabilityRelationship($capability);
        if ($relationship === null) {
            return $this->denied('not_staff', 'unsupported_capability');
        }
        $resourceStaffId = $this->resourceStaffId($resourceRef);
        if ($resourceStaffId === null) {
            return $this->denied('not_staff', 'invalid_resource');
        }

        if (!$this->isActiveAccount($userId)) {
            return $this->denied('not_staff', 'account_inactive');
        }

        $date = $atInstant->format('Y-m-d');
        $actorState = $this->employmentState($userId, $date);
        $targetState = $resourceStaffId === $userId
            ? $actorState
            : $this->employmentState($resourceStaffId, $date);

        if (!$this->isAccessibleEmployment($targetState['status'])) {
            return $this->denied($actorState['status'], $this->employmentDenialReason($targetState['status'], 'resource'));
        }

        if ($relationship === 'hr') {
            // A purely administrative account may have no staff record, but an
            // account with a known ended/suspended/ambiguous employment record
            // cannot retain HR scope after that lifecycle state takes effect.
            if ($actorState['status'] !== 'not_staff' && !$this->isAccessibleEmployment($actorState['status'])) {
                return $this->denied($actorState['status'], $this->employmentDenialReason($actorState['status'], 'actor'));
            }
            $roleVersion = $this->activeHrRoleVersion($userId);
            if ($roleVersion === null) {
                return $this->denied($actorState['status'], 'hr_role_required');
            }

            return $this->allowed($actorState['status'], $roleVersion, 'allowed');
        }

        if (!$this->isAccessibleEmployment($actorState['status'])) {
            return $this->denied($actorState['status'], $this->employmentDenialReason($actorState['status'], 'actor'));
        }
        if ($actorState['assignment_id'] === null) {
            return $this->denied($actorState['status'], 'actor_assignment_unresolved');
        }

        if ($relationship === 'self') {
            if ($userId !== $resourceStaffId) {
                return $this->denied($actorState['status'], 'self_scope_required');
            }

            return $this->allowed($actorState['status'], $actorState['assignment_id'], 'allowed');
        }

        if ($userId === $resourceStaffId) {
            return $this->denied($actorState['status'], 'manager_self_scope_forbidden');
        }
        $relationshipVersion = $this->activeManagerRelationshipVersion($resourceStaffId, $userId, $date);
        if ($relationshipVersion === null) {
            return $this->denied($actorState['status'], 'manager_relationship_inactive');
        }

        return $this->allowed($actorState['status'], $relationshipVersion, 'allowed');
    }

    /** @return list<array<string,mixed>> */
    private function effectivePrimaryAssignments(int $staffId, string $date): array
    {
        $statement = $this->db->prepare(
            "SELECT id, org_unit_id, job_title_id, employment_status, version
             FROM staff_assignments
             WHERE staff_user_id = :staff_user_id
               AND assignment_kind = 'primary'
               AND valid_from <= :effective_date
               AND (valid_to IS NULL OR valid_to >= :effective_date_again)
             ORDER BY valid_from DESC, id DESC"
        );
        $statement->execute([
            ':staff_user_id' => $staffId,
            ':effective_date' => $date,
            ':effective_date_again' => $date,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<int> */
    private function effectiveGroups(int $staffId, string $date): array
    {
        $statement = $this->db->prepare(
            "SELECT group_id
             FROM staff_policy_group_memberships
             WHERE staff_user_id = :staff_user_id
               AND status = 'active'
               AND valid_from <= :effective_date
               AND (valid_to IS NULL OR valid_to >= :effective_date_again)
             ORDER BY group_id"
        );
        $statement->execute([
            ':staff_user_id' => $staffId,
            ':effective_date' => $date,
            ':effective_date_again' => $date,
        ]);

        return array_map(
            static fn (array $row): int => (int) $row['group_id'],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** @return array{status:string,assignment_id:?int} */
    private function employmentState(int $userId, string $date): array
    {
        $assignments = $this->effectivePrimaryAssignments($userId, $date);
        if (count($assignments) > 1) {
            return ['status' => 'ambiguous', 'assignment_id' => null];
        }
        if ($assignments !== []) {
            return [
                'status' => strtolower((string) $assignments[0]['employment_status']),
                'assignment_id' => (int) $assignments[0]['id'],
            ];
        }

        $statement = $this->db->prepare(
            "SELECT employment_status
             FROM staff_assignments
             WHERE staff_user_id = :staff_user_id
               AND assignment_kind = 'primary'
               AND valid_from <= :effective_date
             ORDER BY valid_from DESC, id DESC
             LIMIT 1"
        );
        $statement->execute([
            ':staff_user_id' => $userId,
            ':effective_date' => $date,
        ]);
        $latestStatus = $statement->fetchColumn();
        if ($latestStatus === false) {
            return ['status' => 'not_staff', 'assignment_id' => null];
        }

        return ['status' => 'ended', 'assignment_id' => null];
    }

    private function isActiveAccount(int $userId): bool
    {
        $statement = $this->db->prepare(
            "SELECT id
             FROM users
             WHERE id = :user_id
               AND status = 'active'
             LIMIT 1"
        );
        $statement->execute([':user_id' => $userId]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function activeHrRoleVersion(int $userId): ?int
    {
        $legacyRole = $this->db->prepare(
            "SELECT id
             FROM users
             WHERE id = :user_id
               AND status = 'active'
               AND role IN ('admin', 'super_admin')
             LIMIT 1"
        );
        $legacyRole->execute([':user_id' => $userId]);
        $legacyId = $legacyRole->fetchColumn();
        if ($legacyId !== false) {
            return (int) $legacyId;
        }

        $statement = $this->db->prepare(
            "SELECT id
             FROM user_role_assignments
             WHERE user_id = :user_id
               AND status = 'active'
               AND role_key IN ('admin', 'super_admin')
             ORDER BY id
             LIMIT 1"
        );
        $statement->execute([':user_id' => $userId]);
        $roleId = $statement->fetchColumn();

        return $roleId === false ? null : (int) $roleId;
    }

    private function activeManagerRelationshipVersion(int $staffUserId, int $managerUserId, string $date): ?int
    {
        $statement = $this->db->prepare(
            "SELECT manager_row.id
             FROM staff_manager_assignments manager_row
             INNER JOIN staff_assignments assignment_row
               ON assignment_row.staff_user_id = :staff_user_id
              AND assignment_row.assignment_kind = 'primary'
              AND assignment_row.valid_from <= :assignment_date
              AND (assignment_row.valid_to IS NULL OR assignment_row.valid_to >= :assignment_date_again)
             WHERE manager_row.manager_user_id = :manager_user_id
               AND manager_row.manager_kind IN ('direct', 'administrative')
               AND manager_row.status = 'active'
               AND manager_row.valid_from <= :effective_date
               AND (manager_row.valid_to IS NULL OR manager_row.valid_to >= :effective_date_again)
               AND ((manager_row.subject_type = 'staff' AND manager_row.subject_id = :staff_subject_id)
                 OR (manager_row.subject_type = 'org_unit' AND manager_row.subject_id = assignment_row.org_unit_id))
             ORDER BY CASE manager_row.subject_type WHEN 'staff' THEN 0 ELSE 1 END,
                      CASE manager_row.manager_kind WHEN 'direct' THEN 0 ELSE 1 END,
                      manager_row.priority DESC,
                      manager_row.valid_from DESC,
                      manager_row.id DESC
             LIMIT 1"
        );
        $statement->execute([
            ':staff_user_id' => $staffUserId,
            ':assignment_date' => $date,
            ':assignment_date_again' => $date,
            ':manager_user_id' => $managerUserId,
            ':effective_date' => $date,
            ':effective_date_again' => $date,
            ':staff_subject_id' => $staffUserId,
        ]);
        $relationshipId = $statement->fetchColumn();

        return $relationshipId === false ? null : (int) $relationshipId;
    }

    private function capabilityRelationship(string $capability): ?string
    {
        $capability = strtolower(trim($capability));
        if (preg_match(
            '/^attendance\\.(?:alternative\\.(?:record|review)|adjustment\\.(?:request|submit|cancel|decide))\\.(self|manager|hr)$/D',
            $capability,
            $matches
        ) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function resourceStaffId(string $resourceRef): ?int
    {
        if (preg_match('/^attendance:(?:alternative|adjustment):staff:([1-9][0-9]*)$/D', trim($resourceRef), $matches) !== 1) {
            return null;
        }

        $staffId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $staffId === false ? null : (int) $staffId;
    }

    /** @param array{status:string,assignment_id:?int} $state */
    private function isAccessibleEmployment(string $status): bool
    {
        return in_array($status, self::ACCESSIBLE_EMPLOYMENT_STATUSES, true);
    }

    private function employmentDenialReason(string $status, string $subject): string
    {
        return match ($status) {
            'suspended' => $subject . '_service_suspended',
            'ambiguous' => $subject . '_assignment_ambiguous',
            'not_staff' => $subject . '_assignment_missing',
            default => $subject . '_service_ended',
        };
    }

    /** @return array{allowed:false,staff_status:string,relationship_version:null,reason:string} */
    private function denied(string $staffStatus, string $reason): array
    {
        return [
            'allowed' => false,
            'staff_status' => $staffStatus,
            'relationship_version' => null,
            'reason' => $reason,
        ];
    }

    /** @return array{allowed:true,staff_status:string,relationship_version:int,reason:string} */
    private function allowed(string $staffStatus, int $relationshipVersion, string $reason): array
    {
        return [
            'allowed' => true,
            'staff_status' => $staffStatus,
            'relationship_version' => $relationshipVersion,
            'reason' => $reason,
        ];
    }
}
