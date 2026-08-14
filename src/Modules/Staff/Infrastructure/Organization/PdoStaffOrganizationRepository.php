<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Organization;

use EduCore\Modules\Staff\Contracts\StaffEmploymentLifecycleRepository;
use PDO;
use Throwable;

/** PDO persistence adapter for the Staff-owned organization command service. */
final class PdoStaffOrganizationRepository implements StaffEmploymentLifecycleRepository
{
    private int $savepointSequence = 0;

    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $work): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        } else {
            $savepoint = 'staff_organization_' . (++$this->savepointSequence);
            $this->db->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $work();
            if ($ownsTransaction) {
                if (!$this->db->inTransaction()) {
                    throw new \RuntimeException('Staff organization transaction boundary was lost.');
                }
                $this->db->commit();
            } elseif ($savepoint !== null) {
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            } elseif ($savepoint !== null && $this->db->inTransaction()) {
                $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    public function actorCanManageOrganization(int $actorId): bool
    {
        $statement = $this->db->prepare(
            "SELECT id
             FROM users
             WHERE id = ?
               AND status = 'active'
               AND (
                    role IN ('admin', 'super_admin')
                    OR EXISTS (
                        SELECT 1
                        FROM user_role_assignments role_assignment
                        WHERE role_assignment.user_id = users.id
                          AND role_assignment.status = 'active'
                          AND role_assignment.role_key IN ('admin', 'super_admin')
                    )
               )
             LIMIT 1" . $this->forUpdate()
        );
        $statement->execute([$actorId]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function isStaffUser(int $staffUserId): bool
    {
        $statement = $this->db->prepare(
            'SELECT user_id FROM staff_profiles WHERE user_id = ? LIMIT 1' . $this->forUpdate()
        );
        $statement->execute([$staffUserId]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function isActiveUser(int $userId): bool
    {
        $statement = $this->db->prepare(
            "SELECT id FROM users WHERE id = ? AND status = 'active' LIMIT 1" . $this->forUpdate()
        );
        $statement->execute([$userId]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function orgUnitAvailableForRange(int $orgUnitId, string $validFrom, ?string $validTo): bool
    {
        return $this->referenceAvailableForRange(
            'staff_org_units',
            'valid_from',
            'valid_to',
            $orgUnitId,
            $validFrom,
            $validTo
        );
    }

    public function jobTitleAvailableForRange(int $jobTitleId, string $validFrom, ?string $validTo): bool
    {
        return $this->referenceAvailableForRange(
            'staff_job_titles',
            'active_from',
            'active_to',
            $jobTitleId,
            $validFrom,
            $validTo
        );
    }

    public function policyGroupAvailableForRange(int $groupId, string $validFrom, ?string $validTo): bool
    {
        return $this->referenceAvailableForRange(
            'staff_policy_groups',
            'valid_from',
            'valid_to',
            $groupId,
            $validFrom,
            $validTo
        );
    }

    public function hasOrgUnitCodeOverlap(string $code, string $validFrom, ?string $validTo): bool
    {
        return $this->hasCodeOverlap('staff_org_units', 'valid_from', 'valid_to', $code, $validFrom, $validTo);
    }

    public function hasJobTitleCodeOverlap(string $code, string $validFrom, ?string $validTo): bool
    {
        return $this->hasCodeOverlap('staff_job_titles', 'active_from', 'active_to', $code, $validFrom, $validTo);
    }

    public function hasPolicyGroupCodeOverlap(string $code, string $validFrom, ?string $validTo): bool
    {
        return $this->hasCodeOverlap('staff_policy_groups', 'valid_from', 'valid_to', $code, $validFrom, $validTo);
    }

    public function hasActiveGroupMembershipOverlap(array $membership): bool
    {
        $sql = "SELECT id
                FROM staff_policy_group_memberships
                WHERE group_id = ?
                  AND staff_user_id = ?
                  AND status = 'active'";
        $params = [(int) $membership['group_id'], (int) $membership['staff_user_id']];
        [$sql, $params] = $this->withRangeOverlap(
            $sql,
            $params,
            'valid_from',
            'valid_to',
            (string) $membership['valid_from'],
            $membership['valid_to'] === null ? null : (string) $membership['valid_to']
        );
        $statement = $this->db->prepare($sql . ' LIMIT 1' . $this->forUpdate());
        $statement->execute($params);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function activeStaffManagerEdgesInRange(string $managerKind, string $validFrom, ?string $validTo): array
    {
        $sql = "SELECT subject_id, manager_user_id, valid_from, valid_to
                FROM staff_manager_assignments
                WHERE subject_type = 'staff'
                  AND manager_kind = ?
                  AND status = 'active'";
        [$sql, $params] = $this->withRangeOverlap($sql, [$managerKind], 'valid_from', 'valid_to', $validFrom, $validTo);
        $statement = $this->db->prepare($sql . ' ORDER BY subject_id, valid_from, id' . $this->forUpdate());
        $statement->execute($params);

        return array_map(
            static fn(array $row): array => [
                'subject_id' => (int) $row['subject_id'],
                'manager_user_id' => (int) $row['manager_user_id'],
                'valid_from' => (string) $row['valid_from'],
                'valid_to' => $row['valid_to'] === null ? null : (string) $row['valid_to'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function hasActiveManagerScopeOverlap(array $manager): bool
    {
        $sql = "SELECT id
                FROM staff_manager_assignments
                WHERE subject_type = ?
                  AND subject_id = ?
                  AND manager_kind = ?
                  AND priority = ?
                  AND status = 'active'";
        $params = [
            (string) $manager['subject_type'],
            (int) $manager['subject_id'],
            (string) $manager['manager_kind'],
            (int) $manager['priority'],
        ];
        [$sql, $params] = $this->withRangeOverlap(
            $sql,
            $params,
            'valid_from',
            'valid_to',
            (string) $manager['valid_from'],
            $manager['valid_to'] === null ? null : (string) $manager['valid_to']
        );
        $statement = $this->db->prepare($sql . ' LIMIT 1' . $this->forUpdate());
        $statement->execute($params);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function hasPrimaryAssignmentOverlap(array $assignment): bool
    {
        $sql = "SELECT id
                FROM staff_assignments
                WHERE staff_user_id = ?
                  AND assignment_kind = 'primary'";
        [$sql, $params] = $this->withRangeOverlap(
            $sql,
            [(int) $assignment['staff_user_id']],
            'valid_from',
            'valid_to',
            (string) $assignment['valid_from'],
            $assignment['valid_to'] === null ? null : (string) $assignment['valid_to']
        );
        $statement = $this->db->prepare($sql . ' LIMIT 1' . $this->forUpdate());
        $statement->execute($params);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function currentPrimaryAssignmentForUpdate(int $staffUserId, string $effectiveDate): ?array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM staff_assignments
             WHERE staff_user_id = :staff_user_id
               AND assignment_kind = 'primary'
               AND valid_from <= :effective_date
               AND (valid_to IS NULL OR valid_to >= :effective_date_again)
             ORDER BY valid_from DESC, id DESC
             LIMIT 2
             FOR UPDATE"
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'effective_date' => $effectiveDate,
            'effective_date_again' => $effectiveDate,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \DomainException('STAFF_ORG_PRIMARY_ASSIGNMENT_AMBIGUOUS');
        }
        return $rows[0] ?? null;
    }

    public function closeAssignment(int $assignmentId, string $validTo): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_assignments
             SET valid_to = :valid_to, version = version + 1
             WHERE id = :id AND valid_from <= :valid_to_again
               AND (valid_to IS NULL OR valid_to > :valid_to_current)'
        );
        $statement->execute([
            'valid_to' => $validTo,
            'id' => $assignmentId,
            'valid_to_again' => $validTo,
            'valid_to_current' => $validTo,
        ]);
        return $statement->rowCount() === 1;
    }

    public function insertOrgUnit(array $orgUnit): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_org_units
                (code, name, unit_type, parent_id, valid_from, valid_to, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $orgUnit['code'],
            $orgUnit['name'],
            $orgUnit['unit_type'],
            $orgUnit['parent_id'],
            $orgUnit['valid_from'],
            $orgUnit['valid_to'],
            $orgUnit['status'],
            $orgUnit['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function insertJobTitle(array $jobTitle): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_job_titles
                (code, name, active_from, active_to, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $jobTitle['code'],
            $jobTitle['name'],
            $jobTitle['active_from'],
            $jobTitle['active_to'],
            $jobTitle['status'],
            $jobTitle['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function insertPolicyGroup(array $group): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_policy_groups
                (code, name, purpose, valid_from, valid_to, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $group['code'],
            $group['name'],
            $group['purpose'],
            $group['valid_from'],
            $group['valid_to'],
            $group['status'],
            $group['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function insertPolicyGroupMembership(array $membership): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_policy_group_memberships
                (group_id, staff_user_id, valid_from, valid_to, status, source, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $membership['group_id'],
            $membership['staff_user_id'],
            $membership['valid_from'],
            $membership['valid_to'],
            $membership['status'],
            $membership['source'],
            $membership['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function insertManagerAssignment(array $manager): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_manager_assignments
                (subject_type, subject_id, manager_user_id, manager_kind, priority,
                 valid_from, valid_to, status, source, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $manager['subject_type'],
            $manager['subject_id'],
            $manager['manager_user_id'],
            $manager['manager_kind'],
            $manager['priority'],
            $manager['valid_from'],
            $manager['valid_to'],
            $manager['status'],
            $manager['source'],
            $manager['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function insertAssignment(array $assignment): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_assignments
                (staff_user_id, org_unit_id, job_title_id, assignment_kind, employment_status,
                 work_fraction, valid_from, valid_to, source, source_ref, version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $assignment['staff_user_id'],
            $assignment['org_unit_id'],
            $assignment['job_title_id'],
            $assignment['assignment_kind'],
            $assignment['employment_status'],
            $assignment['work_fraction'],
            $assignment['valid_from'],
            $assignment['valid_to'],
            $assignment['source'],
            $assignment['source_ref'],
            $assignment['version'],
            $assignment['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function referenceAvailableForRange(
        string $table,
        string $fromColumn,
        string $toColumn,
        int $id,
        string $validFrom,
        ?string $validTo
    ): bool {
        if ($validTo === null) {
            $statement = $this->db->prepare(
                "SELECT id FROM {$table}
                 WHERE id = ?
                   AND status = 'active'
                   AND {$fromColumn} <= ?
                   AND {$toColumn} IS NULL
                 LIMIT 1" . $this->forUpdate()
            );
            $statement->execute([$id, $validFrom]);
        } else {
            $statement = $this->db->prepare(
                "SELECT id FROM {$table}
                 WHERE id = ?
                   AND status = 'active'
                   AND {$fromColumn} <= ?
                   AND ({$toColumn} IS NULL OR {$toColumn} >= ?)
                 LIMIT 1" . $this->forUpdate()
            );
            $statement->execute([$id, $validFrom, $validTo]);
        }

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function hasCodeOverlap(
        string $table,
        string $fromColumn,
        string $toColumn,
        string $code,
        string $validFrom,
        ?string $validTo
    ): bool {
        if ($validTo === null) {
            $statement = $this->db->prepare(
                "SELECT id FROM {$table}
                 WHERE code = ?
                   AND ({$toColumn} IS NULL OR {$toColumn} >= ?)
                 LIMIT 1" . $this->forUpdate()
            );
            $statement->execute([$code, $validFrom]);
        } else {
            $statement = $this->db->prepare(
                "SELECT id FROM {$table}
                 WHERE code = ?
                   AND {$fromColumn} <= ?
                   AND ({$toColumn} IS NULL OR {$toColumn} >= ?)
                 LIMIT 1" . $this->forUpdate()
            );
            $statement->execute([$code, $validTo, $validFrom]);
        }

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /** @param list<mixed> $params @return array{0:string,1:list<mixed>} */
    private function withRangeOverlap(
        string $sql,
        array $params,
        string $fromColumn,
        string $toColumn,
        string $validFrom,
        ?string $validTo
    ): array {
        if ($validTo === null) {
            $sql .= " AND ({$toColumn} IS NULL OR {$toColumn} >= ?)";
            $params[] = $validFrom;
        } else {
            $sql .= " AND {$fromColumn} <= ? AND ({$toColumn} IS NULL OR {$toColumn} >= ?)";
            $params[] = $validTo;
            $params[] = $validFrom;
        }

        return [$sql, $params];
    }

    private function forUpdate(): string
    {
        return $this->db->inTransaction() ? ' FOR UPDATE' : '';
    }
}
