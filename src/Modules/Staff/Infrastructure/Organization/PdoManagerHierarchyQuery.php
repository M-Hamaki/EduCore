<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Organization;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\ManagerHierarchyAtDateQuery;
use InvalidArgumentException;
use PDO;

/**
 * Resolves the effective reporting relationship without guessing between
 * overlapping assignments. Resource-aware delegation is intentionally owned
 * by ApprovalDelegationQuery, after the workflow knows its request type.
 */
final class PdoManagerHierarchyQuery implements ManagerHierarchyAtDateQuery
{
    private const MANAGER_KINDS = ['direct', 'administrative', 'hr'];

    public function __construct(private PDO $db)
    {
    }

    public function resolve(int $staffId, string $managerKind, DateTimeImmutable $atDate): array
    {
        if ($staffId <= 0) {
            throw new InvalidArgumentException('A manager hierarchy query requires a positive staff id.');
        }

        $managerKind = strtolower(trim($managerKind));
        if (!in_array($managerKind, self::MANAGER_KINDS, true)) {
            throw new InvalidArgumentException('Unsupported manager hierarchy kind.');
        }

        $date = $atDate->format('Y-m-d');
        $assignment = $this->effectivePrimaryAssignment($staffId, $date);
        if ($assignment['conflicts'] !== []) {
            return $this->unresolved($assignment['assignment_id'], $assignment['conflicts']);
        }

        $resolvedManager = $this->resolveManagerCandidates(
            $this->managerCandidates('staff', $staffId, $managerKind, $date),
            $staffId,
            'staff',
            $staffId,
            $managerKind
        );
        if (!$resolvedManager['has_candidates']) {
            if ($assignment['assignment_id'] === null || $assignment['org_unit_id'] === null) {
                return $this->unresolved($assignment['assignment_id'], []);
            }

            $resolvedManager = $this->resolveManagerCandidates(
                $this->managerCandidates('org_unit', $assignment['org_unit_id'], $managerKind, $date),
                $staffId,
                'org_unit',
                $assignment['org_unit_id'],
                $managerKind
            );
        }

        if ($resolvedManager['conflicts'] !== []) {
            return $this->unresolved($assignment['assignment_id'], $resolvedManager['conflicts']);
        }
        if ($resolvedManager['manager_id'] === null) {
            return $this->unresolved($assignment['assignment_id'], []);
        }

        return [
            'manager_id' => $resolvedManager['manager_id'],
            'assignment_id' => $assignment['assignment_id'],
            'delegation' => null,
            'conflicts' => [],
        ];
    }

    /**
     * @return array{assignment_id:?int,org_unit_id:?int,conflicts:list<array<string,mixed>>}
     */
    private function effectivePrimaryAssignment(int $staffId, string $date): array
    {
        $statement = $this->db->prepare(
            "SELECT id, org_unit_id
             FROM staff_assignments
             WHERE staff_user_id = :staff_id
               AND assignment_kind = 'primary'
               AND valid_from <= :effective_date
               AND (valid_to IS NULL OR valid_to >= :effective_date_again)
             ORDER BY valid_from DESC, id DESC"
        );
        $statement->execute([
            ':staff_id' => $staffId,
            ':effective_date' => $date,
            ':effective_date_again' => $date,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return ['assignment_id' => null, 'org_unit_id' => null, 'conflicts' => []];
        }
        if (count($rows) !== 1) {
            $assignmentIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            sort($assignmentIds, SORT_NUMERIC);

            return [
                'assignment_id' => null,
                'org_unit_id' => null,
                'conflicts' => [[
                    'reason' => 'overlapping_primary_assignments',
                    'staff_id' => $staffId,
                    'assignment_ids' => $assignmentIds,
                ]],
            ];
        }

        return [
            'assignment_id' => (int) $rows[0]['id'],
            'org_unit_id' => (int) $rows[0]['org_unit_id'],
            'conflicts' => [],
        ];
    }

    /** @return list<array{id:int,manager_user_id:int,priority:int,valid_from:string}> */
    private function managerCandidates(string $subjectType, int $subjectId, string $managerKind, string $date): array
    {
        $statement = $this->db->prepare(
            "SELECT id, manager_user_id, priority, valid_from
             FROM staff_manager_assignments
             WHERE subject_type = :subject_type
               AND subject_id = :subject_id
               AND manager_kind = :manager_kind
               AND status = 'active'
               AND valid_from <= :effective_date
               AND (valid_to IS NULL OR valid_to >= :effective_date_again)
             ORDER BY priority DESC, valid_from DESC, id DESC"
        );
        $statement->execute([
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':manager_kind' => $managerKind,
            ':effective_date' => $date,
            ':effective_date_again' => $date,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array{id:int,manager_user_id:int,priority:int,valid_from:string}> $candidates
     * @return array{has_candidates:bool,manager_id:?int,conflicts:list<array<string,mixed>>}
     */
    private function resolveManagerCandidates(
        array $candidates,
        int $staffId,
        string $subjectType,
        int $subjectId,
        string $managerKind
    ): array {
        if ($candidates === []) {
            return ['has_candidates' => false, 'manager_id' => null, 'conflicts' => []];
        }

        $highestPriority = (int) $candidates[0]['priority'];
        $winningRows = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => (int) $candidate['priority'] === $highestPriority
        ));
        $managerIds = array_values(array_unique(array_map(
            static fn(array $candidate): int => (int) $candidate['manager_user_id'],
            $winningRows
        )));
        sort($managerIds, SORT_NUMERIC);

        if (in_array($staffId, $managerIds, true)) {
            return [
                'has_candidates' => true,
                'manager_id' => null,
                'conflicts' => [[
                    'reason' => 'self_manager_assignment',
                    'staff_id' => $staffId,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'manager_kind' => $managerKind,
                    'manager_assignment_ids' => array_map(static fn(array $row): int => (int) $row['id'], $winningRows),
                ]],
            ];
        }

        if (count($managerIds) !== 1) {
            return [
                'has_candidates' => true,
                'manager_id' => null,
                'conflicts' => [[
                    'reason' => 'ambiguous_manager_assignment',
                    'staff_id' => $staffId,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'manager_kind' => $managerKind,
                    'priority' => $highestPriority,
                    'manager_user_ids' => $managerIds,
                    'manager_assignment_ids' => array_map(static fn(array $row): int => (int) $row['id'], $winningRows),
                ]],
            ];
        }

        return ['has_candidates' => true, 'manager_id' => $managerIds[0], 'conflicts' => []];
    }

    /** @param list<array<string,mixed>> $conflicts */
    private function unresolved(?int $assignmentId, array $conflicts): array
    {
        return [
            'manager_id' => null,
            'assignment_id' => $assignmentId,
            'delegation' => null,
            'conflicts' => $conflicts,
        ];
    }
}
