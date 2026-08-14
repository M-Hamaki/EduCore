<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\StaffAttendanceReportDimensionQuery;
use InvalidArgumentException;
use PDO;

/**
 * Read-only historical Staff projection used by Attendance reporting.
 *
 * The adapter owns the only Staff-table reads required for report dimensions.
 * It returns IDs only: presentation labels remain a separate, authorized
 * concern and no current profile value is used as historical evidence.
 */
final class PdoStaffAttendanceReportDimensionQuery implements StaffAttendanceReportDimensionQuery
{
    private const MAX_DAY_REFERENCES = 10000;

    public function __construct(private PDO $db)
    {
    }

    public function forAttendanceDays(array $dayReferences): array
    {
        $references = $this->normalizeReferences($dayReferences);
        if ($references === []) {
            return ['dimensions' => [], 'conflicts' => []];
        }

        $assignmentIds = array_values(array_unique(array_filter(
            array_map(static fn (array $reference): int => (int) ($reference['assignment_id'] ?? 0), $references),
            static fn (int $assignmentId): bool => $assignmentId > 0
        )));
        $assignments = $this->assignmentsById($assignmentIds);
        $staffIds = array_values(array_unique(array_map(
            static fn (array $reference): int => (int) $reference['staff_user_id'],
            $references
        )));
        $dates = array_column($references, 'work_date');
        $memberships = $this->membershipsByStaff(
            $staffIds,
            min($dates),
            max($dates)
        );

        $dimensions = [];
        $conflicts = [];
        foreach ($references as $reference) {
            $dayVersionId = (int) $reference['day_version_id'];
            $assignmentId = (int) ($reference['assignment_id'] ?? 0);
            if ($assignmentId <= 0 || !isset($assignments[$assignmentId])) {
                $conflicts[] = [
                    'day_version_id' => $dayVersionId,
                    'reason_code' => 'ATTENDANCE_REPORT_ASSIGNMENT_MISSING',
                ];
                continue;
            }

            $assignment = $assignments[$assignmentId];
            if ((int) $assignment['staff_user_id'] !== (int) $reference['staff_user_id']) {
                $conflicts[] = [
                    'day_version_id' => $dayVersionId,
                    'reason_code' => 'ATTENDANCE_REPORT_ASSIGNMENT_STAFF_MISMATCH',
                ];
                continue;
            }
            if (!$this->dateInInclusiveRange(
                (string) $reference['work_date'],
                (string) $assignment['valid_from'],
                $assignment['valid_to'] === null ? null : (string) $assignment['valid_to']
            )) {
                $conflicts[] = [
                    'day_version_id' => $dayVersionId,
                    'reason_code' => 'ATTENDANCE_REPORT_ASSIGNMENT_NOT_EFFECTIVE',
                ];
                continue;
            }

            $dimensions[$dayVersionId] = [
                'day_version_id' => $dayVersionId,
                'staff_user_id' => (int) $reference['staff_user_id'],
                'assignment_id' => $assignmentId,
                'org_unit_id' => $assignment['org_unit_id'] === null ? null : (int) $assignment['org_unit_id'],
                'job_title_id' => $assignment['job_title_id'] === null ? null : (int) $assignment['job_title_id'],
                'group_ids' => $this->groupsForDate(
                    $memberships[(int) $reference['staff_user_id']] ?? [],
                    (string) $reference['work_date']
                ),
            ];
        }

        usort($conflicts, static fn (array $left, array $right): int => $left['day_version_id'] <=> $right['day_version_id']);

        return ['dimensions' => $dimensions, 'conflicts' => $conflicts];
    }

    /**
     * @param list<array<string,mixed>> $references
     * @return list<array{day_version_id:int,staff_user_id:int,work_date:string,assignment_id:?int}>
     */
    private function normalizeReferences(array $references): array
    {
        if (count($references) > self::MAX_DAY_REFERENCES) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_DIMENSION_BATCH_TOO_LARGE');
        }

        $normalized = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                throw new InvalidArgumentException('ATTENDANCE_REPORT_DIMENSION_REFERENCE_INVALID');
            }
            $dayVersionId = (int) ($reference['day_version_id'] ?? 0);
            $staffUserId = (int) ($reference['staff_user_id'] ?? 0);
            $workDate = (string) ($reference['work_date'] ?? '');
            if ($dayVersionId <= 0 || $staffUserId <= 0 || !$this->validDate($workDate)) {
                throw new InvalidArgumentException('ATTENDANCE_REPORT_DIMENSION_REFERENCE_INVALID');
            }
            if (isset($normalized[$dayVersionId])) {
                throw new InvalidArgumentException('ATTENDANCE_REPORT_DIMENSION_REFERENCE_DUPLICATE');
            }
            $assignmentId = isset($reference['assignment_id']) && (int) $reference['assignment_id'] > 0
                ? (int) $reference['assignment_id']
                : null;
            $normalized[$dayVersionId] = [
                'day_version_id' => $dayVersionId,
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate,
                'assignment_id' => $assignmentId,
            ];
        }

        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }

    /** @param list<int> $assignmentIds @return array<int,array<string,mixed>> */
    private function assignmentsById(array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
        $statement = $this->db->prepare(
            'SELECT id, staff_user_id, org_unit_id, job_title_id, valid_from, valid_to
             FROM staff_assignments
             WHERE id IN (' . $placeholders . ')'
        );
        $statement->execute($assignmentIds);
        $assignments = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $assignments[(int) $assignment['id']] = $assignment;
        }

        return $assignments;
    }

    /**
     * @param list<int> $staffIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function membershipsByStaff(array $staffIds, string $fromDate, string $toDate): array
    {
        if ($staffIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
        $statement = $this->db->prepare(
            'SELECT staff_user_id, group_id, valid_from, valid_to
             FROM staff_policy_group_memberships
             WHERE status = \'active\'
               AND staff_user_id IN (' . $placeholders . ')
               AND valid_from <= ?
               AND (valid_to IS NULL OR valid_to >= ?)
             ORDER BY staff_user_id, group_id, valid_from'
        );
        $statement->execute([...$staffIds, $toDate, $fromDate]);
        $memberships = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $membership) {
            $memberships[(int) $membership['staff_user_id']][] = $membership;
        }

        return $memberships;
    }

    /** @param list<array<string,mixed>> $memberships @return list<int> */
    private function groupsForDate(array $memberships, string $workDate): array
    {
        $groups = [];
        foreach ($memberships as $membership) {
            if ($this->dateInInclusiveRange(
                $workDate,
                (string) ($membership['valid_from'] ?? ''),
                isset($membership['valid_to']) && $membership['valid_to'] !== null
                    ? (string) $membership['valid_to']
                    : null
            )) {
                $groups[] = (int) $membership['group_id'];
            }
        }
        $groups = array_values(array_unique(array_filter($groups, static fn (int $groupId): bool => $groupId > 0)));
        sort($groups, SORT_NUMERIC);

        return $groups;
    }

    private function dateInInclusiveRange(string $date, string $from, ?string $to): bool
    {
        $fromDate = substr($from, 0, 10);
        $toDate = $to === null ? null : substr($to, 0, 10);

        return $fromDate !== '' && $date >= $fromDate && ($toDate === null || $date <= $toDate);
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }
}
