<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Attendance\Contracts\EffectiveScheduleQuery;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyReadRepository;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use RuntimeException;

/** Resolves one explainable schedule for a staff member and work date. */
final class EffectiveScheduleQueryService implements EffectiveScheduleQuery
{
    /** @var array<string,int> */
    private const SCOPE_PRECEDENCE = [
        'global' => 100,
        'job_title' => 200,
        'org_unit' => 300,
        'group' => 400,
        'staff' => 500,
    ];

    private ?SchedulePolicyReadRepository $repository;
    private ?StaffAssignmentAtDateQuery $assignmentQuery;

    public function __construct(
        ?SchedulePolicyReadRepository $repository = null,
        ?StaffAssignmentAtDateQuery $assignmentQuery = null
    ) {
        $this->repository = $repository;
        $this->assignmentQuery = $assignmentQuery;
    }

    public function forStaffDate(int $staffId, DateTimeImmutable $workDate): array
    {
        if ($staffId <= 0) {
            throw new DomainException('STAFF_ID_INVALID');
        }
        if ($this->repository === null || $this->assignmentQuery === null) {
            throw new RuntimeException('Effective schedule query dependencies are not configured.');
        }

        $assignment = $this->assignmentQuery->forStaff($staffId, $workDate);
        if ($assignment === null || ($assignment['employment_status'] ?? '') !== 'active') {
            return [
                'status' => 'non_working',
                'reason_code' => 'STAFF_NOT_ACTIVE',
                'staff_id' => $staffId,
                'work_date' => $workDate->format('Y-m-d'),
                'assignment' => $assignment,
                'current' => null,
                'proposed' => null,
                'selected' => null,
                'changed' => false,
                'conflicts' => [],
                'calendar_exception' => null,
                'approved_changes' => [],
                'explanation' => ['reason_code' => 'STAFF_NOT_ACTIVE'],
            ];
        }

        $candidates = $this->repository->candidateVersionsFor($staffId, $assignment, $workDate);
        $exceptions = $this->repository->calendarExceptionsFor($staffId, $assignment, $workDate);
        $base = $this->resolveFromCandidates($staffId, $workDate, $assignment, $candidates, $exceptions);

        $calendarStart = $workDate->setTime(0, 0, 0, 0);
        $calendarEnd = $calendarStart->modify('+1 day');
        $windowStart = $calendarStart;
        $windowEnd = $calendarEnd;
        if (($base['selected']['schedule'] ?? null) instanceof WorkSchedule) {
            $window = $base['selected']['schedule']->workWindow($workDate);
            if ($window !== null) {
                $windowStart = $window['start'] < $calendarStart ? $window['start'] : $calendarStart;
                $windowEnd = $window['end'] > $calendarEnd ? $window['end'] : $calendarEnd;
            }
        }

        $changes = $this->repository->approvedChangesFor($staffId, $windowStart, $windowEnd);
        return $this->resolveFromCandidates(
            $staffId,
            $workDate,
            $assignment,
            $candidates,
            $exceptions,
            $changes
        );
    }

    /**
     * Pure resolution entrypoint used by impact previews and unit tests.
     *
     * @param array<string,mixed> $assignmentSnapshot
     * @param list<array<string,mixed>> $publishedCandidates
     * @param list<array<string,mixed>> $calendarExceptions
     * @param list<array<string,mixed>> $approvedChanges
     * @param array<string,mixed>|null $proposedCandidate unpublished candidate to preview
     * @return array<string,mixed>
     */
    public function resolveFromCandidates(
        int $staffId,
        DateTimeImmutable $workDate,
        array $assignmentSnapshot,
        array $publishedCandidates,
        array $calendarExceptions = [],
        array $approvedChanges = [],
        ?array $proposedCandidate = null
    ): array {
        $currentResolution = $this->selectCandidate(
            $staffId,
            $workDate,
            $assignmentSnapshot,
            $publishedCandidates,
            false
        );
        $current = $currentResolution['selected'];
        if ($currentResolution['invalid'] !== []) {
            return $this->unresolvedResult(
                $staffId,
                $workDate,
                $assignmentSnapshot,
                'SCHEDULE_PAYLOAD_INVALID',
                $currentResolution['invalid'],
                null,
                null
            );
        }
        if ($currentResolution['conflicts'] !== []) {
            return $this->unresolvedResult(
                $staffId,
                $workDate,
                $assignmentSnapshot,
                'SCHEDULE_CONFLICT',
                $currentResolution['conflicts'],
                null,
                null
            );
        }

        $proposedResolution = $currentResolution;
        if ($proposedCandidate !== null) {
            $proposedCandidate['_impact_proposed'] = true;
            $proposedResolution = $this->selectCandidate(
                $staffId,
                $workDate,
                $assignmentSnapshot,
                array_merge($publishedCandidates, [$proposedCandidate]),
                true
            );
            if ($proposedResolution['invalid'] !== []) {
                return $this->unresolvedResult(
                    $staffId,
                    $workDate,
                    $assignmentSnapshot,
                    'SCHEDULE_PAYLOAD_INVALID',
                    $proposedResolution['invalid'],
                    $current,
                    null
                );
            }
        }

        $selected = $proposedCandidate === null ? $current : $proposedResolution['selected'];
        $conflicts = $proposedResolution['conflicts'];
        if ($conflicts !== []) {
            return $this->unresolvedResult(
                $staffId,
                $workDate,
                $assignmentSnapshot,
                'SCHEDULE_CONFLICT',
                $conflicts,
                $current,
                $proposedResolution['selected']
            );
        }
        if ($selected === null) {
            return $this->unresolvedResult(
                $staffId,
                $workDate,
                $assignmentSnapshot,
                'SCHEDULE_NOT_FOUND',
                [],
                $current,
                null
            );
        }

        $calendarResolution = $this->selectCalendarException(
            $staffId,
            $workDate,
            $assignmentSnapshot,
            $calendarExceptions
        );
        if ($calendarResolution['conflicts'] !== []) {
            return $this->unresolvedResult(
                $staffId,
                $workDate,
                $assignmentSnapshot,
                'CALENDAR_EXCEPTION_CONFLICT',
                $calendarResolution['conflicts'],
                $current,
                $proposedResolution['selected']
            );
        }

        $calendarException = $calendarResolution['selected'];
        $schedule = $selected['schedule'];
        if (!$schedule instanceof WorkSchedule) {
            return $this->unresolvedResult(
                $staffId,
                $workDate,
                $assignmentSnapshot,
                'SCHEDULE_PAYLOAD_INVALID',
                [(int) ($selected['version_id'] ?? 0)],
                $current,
                $proposedResolution['selected']
            );
        }

        if ($calendarException !== null) {
            $exceptionType = (string) $calendarException['exception_type'];
            if (in_array($exceptionType, ['holiday', 'closure'], true)) {
                return $this->resolvedResult(
                    'non_working',
                    $staffId,
                    $workDate,
                    $assignmentSnapshot,
                    $current,
                    $proposedResolution['selected'],
                    $selected,
                    $calendarException,
                    $approvedChanges,
                    'CALENDAR_' . strtoupper($exceptionType)
                );
            }
            try {
                $schedule = $this->applyCalendarOverride($schedule, $workDate, $calendarException);
                $selected['schedule'] = $schedule;
                $selected['schedule_payload'] = $schedule->toArray();
            } catch (\Throwable $exception) {
                return $this->unresolvedResult(
                    $staffId,
                    $workDate,
                    $assignmentSnapshot,
                    'CALENDAR_OVERRIDE_INVALID',
                    [(int) ($calendarException['id'] ?? 0)],
                    $current,
                    $proposedResolution['selected']
                );
            }
        }

        $changeResolution = $this->applyApprovedChanges($staffId, $selected, $approvedChanges);
        if ($changeResolution['conflicts'] !== []) {
            return $this->unresolvedResult(
                $staffId,
                $workDate,
                $assignmentSnapshot,
                $changeResolution['reason_code'],
                $changeResolution['conflicts'],
                $current,
                $proposedResolution['selected']
            );
        }
        $selected = $changeResolution['selected'];
        $schedule = $selected['schedule'];

        $status = $schedule instanceof WorkSchedule && $schedule->isWorkingDay($workDate)
            ? 'working'
            : 'non_working';

        return $this->resolvedResult(
            $status,
            $staffId,
            $workDate,
            $assignmentSnapshot,
            $current,
            $proposedResolution['selected'],
            $selected,
            $calendarException,
            $approvedChanges,
            $status === 'working' ? 'EFFECTIVE_SCHEDULE_RESOLVED' : 'SCHEDULE_NON_WORKING_DAY'
        );
    }

    /** @return array{selected:?array,conflicts:list<int>,invalid:list<int>} */
    private function selectCandidate(
        int $staffId,
        DateTimeImmutable $workDate,
        array $assignment,
        array $candidates,
        bool $allowProposed
    ): array {
        $eligible = [];
        $invalid = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $isProposed = ($candidate['_impact_proposed'] ?? false) === true;
            if (!$isProposed && ($candidate['state'] ?? '') !== 'published') {
                continue;
            }
            if ($isProposed && !$allowProposed) {
                continue;
            }
            if (!$this->candidateMatchesStaff($staffId, $assignment, $candidate)) {
                continue;
            }
            try {
                if (!$this->dateInRange($workDate, $candidate['valid_from'] ?? null, $candidate['valid_to'] ?? null)) {
                    continue;
                }
                if (!$this->dateInRange(
                    $workDate,
                    $candidate['scope_valid_from'] ?? $candidate['valid_from'] ?? null,
                    $candidate['scope_valid_to'] ?? $candidate['valid_to'] ?? null
                )) {
                    continue;
                }
                $schedule = ($candidate['schedule'] ?? null) instanceof WorkSchedule
                    ? $candidate['schedule']
                    : WorkSchedule::fromArray((array) ($candidate['schedule'] ?? []));
            } catch (\Throwable $exception) {
                $invalid[] = (int) ($candidate['version_id'] ?? 0);
                continue;
            }
            if (!$schedule->isSeasonallyActive($workDate)) {
                continue;
            }

            $candidate['schedule'] = $schedule;
            $candidate['schedule_payload'] = $schedule->toArray();
            $candidate['_scope_rank'] = self::SCOPE_PRECEDENCE[(string) $candidate['scope_type']];
            $candidate['_priority'] = (int) ($candidate['scope_priority'] ?? $candidate['priority'] ?? 0);
            $candidate['_effective_start'] = $this->effectiveStart($candidate);
            $eligible[] = $candidate;
        }

        if ($eligible === []) {
            sort($invalid, SORT_NUMERIC);
            return ['selected' => null, 'conflicts' => [], 'invalid' => array_values(array_unique($invalid))];
        }
        if ($invalid !== []) {
            sort($invalid, SORT_NUMERIC);
            return ['selected' => null, 'conflicts' => [], 'invalid' => array_values(array_unique($invalid))];
        }
        usort($eligible, [$this, 'compareRank']);
        $winner = $eligible[0];
        $ties = array_values(array_filter($eligible, fn (array $candidate): bool => $this->sameRank($winner, $candidate)));
        if (count($ties) > 1) {
            $ids = array_values(array_unique(array_map(
                static fn (array $candidate): int => (int) ($candidate['version_id'] ?? 0),
                $ties
            )));
            sort($ids, SORT_NUMERIC);
            return ['selected' => null, 'conflicts' => $ids, 'invalid' => []];
        }

        unset($winner['_scope_rank'], $winner['_priority'], $winner['_effective_start'], $winner['_impact_proposed']);
        return ['selected' => $winner, 'conflicts' => [], 'invalid' => []];
    }

    /** @return array{selected:?array,conflicts:list<int>} */
    private function selectCalendarException(
        int $staffId,
        DateTimeImmutable $workDate,
        array $assignment,
        array $exceptions
    ): array {
        $superseded = [];
        foreach ($exceptions as $exception) {
            if (is_array($exception) && (int) ($exception['supersedes_id'] ?? 0) > 0) {
                $superseded[(int) $exception['supersedes_id']] = true;
            }
        }

        $eligible = [];
        foreach ($exceptions as $exception) {
            if (!is_array($exception) || ($exception['status'] ?? '') !== 'active') {
                continue;
            }
            if (isset($superseded[(int) ($exception['id'] ?? 0)])) {
                continue;
            }
            if (($exception['calendar_date'] ?? '') !== $workDate->format('Y-m-d')) {
                continue;
            }
            if (!$this->candidateMatchesStaff($staffId, $assignment, $exception)) {
                continue;
            }
            $exception['_scope_rank'] = self::SCOPE_PRECEDENCE[(string) $exception['scope_type']];
            $exception['_priority'] = (int) ($exception['priority'] ?? 0);
            $exception['_effective_start'] = (string) ($exception['created_at'] ?? $exception['calendar_date']);
            $eligible[] = $exception;
        }
        if ($eligible === []) {
            return ['selected' => null, 'conflicts' => []];
        }

        usort($eligible, [$this, 'compareRank']);
        $winner = $eligible[0];
        $ties = array_values(array_filter($eligible, fn (array $candidate): bool => $this->sameRank($winner, $candidate)));
        if (count($ties) > 1) {
            $ids = array_values(array_unique(array_map(
                static fn (array $candidate): int => (int) ($candidate['id'] ?? 0),
                $ties
            )));
            sort($ids, SORT_NUMERIC);
            return ['selected' => null, 'conflicts' => $ids];
        }

        unset($winner['_scope_rank'], $winner['_priority'], $winner['_effective_start']);
        return ['selected' => $winner, 'conflicts' => []];
    }

    /** @return array{selected:array<string,mixed>,conflicts:list<int>,reason_code:string} */
    private function applyApprovedChanges(int $staffId, array $selected, array $changes): array
    {
        $scheduleChanges = [];
        $approvedOvertime = [];
        $alternativeAttendance = [];
        foreach ($changes as $change) {
            if (!is_array($change) || ($change['status'] ?? '') !== 'approved') {
                continue;
            }
            $type = (string) ($change['change_type'] ?? '');
            if (in_array($type, ['temporary_shift', 'shift_swap'], true)) {
                $scheduleChanges[] = $change;
            } elseif ($type === 'overtime') {
                $approvedOvertime[] = $change;
            } elseif ($type === 'alternative_attendance') {
                $alternativeAttendance[] = $change;
            }
        }

        if (count($scheduleChanges) > 1) {
            $ids = array_map(static fn (array $change): int => (int) ($change['id'] ?? 0), $scheduleChanges);
            sort($ids, SORT_NUMERIC);
            return ['selected' => $selected, 'conflicts' => $ids, 'reason_code' => 'SCHEDULE_CHANGE_CONFLICT'];
        }
        if ($scheduleChanges !== []) {
            $change = $scheduleChanges[0];
            $snapshot = $change['approved_schedule_snapshot'] ?? null;
            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);
            }
            $schedulePayload = is_array($snapshot) && isset($snapshot['schedule'])
                ? $snapshot['schedule']
                : $snapshot;
            if (!is_array($schedulePayload)) {
                return [
                    'selected' => $selected,
                    'conflicts' => [(int) ($change['id'] ?? 0)],
                    'reason_code' => 'SCHEDULE_CHANGE_PAYLOAD_INVALID',
                ];
            }
            try {
                $selected['schedule'] = WorkSchedule::fromArray($schedulePayload);
            } catch (\Throwable $exception) {
                return [
                    'selected' => $selected,
                    'conflicts' => [(int) ($change['id'] ?? 0)],
                    'reason_code' => 'SCHEDULE_CHANGE_PAYLOAD_INVALID',
                ];
            }
            $selected['schedule_payload'] = $selected['schedule']->toArray();
            $selected['schedule_change_request_id'] = (int) ($change['id'] ?? 0);
            $selected['schedule_change_type'] = (string) $change['change_type'];
        }
        $selected['approved_overtime'] = $approvedOvertime;
        $selected['alternative_attendance'] = $alternativeAttendance;

        return ['selected' => $selected, 'conflicts' => [], 'reason_code' => 'EFFECTIVE_SCHEDULE_RESOLVED'];
    }

    private function applyCalendarOverride(
        WorkSchedule $base,
        DateTimeImmutable $workDate,
        array $exception
    ): WorkSchedule {
        $override = $exception['override_json'] ?? null;
        if (is_string($override)) {
            $override = json_decode($override, true);
        }
        if (isset($exception['schedule']) && is_array($exception['schedule'])) {
            return WorkSchedule::fromArray($exception['schedule']);
        }
        if (!is_array($override)) {
            throw new DomainException('CALENDAR_OVERRIDE_SCHEDULE_MISSING');
        }
        if (isset($override['days'])) {
            return WorkSchedule::fromArray($override);
        }

        return $base->withDayOverride($workDate, $override);
    }

    private function candidateMatchesStaff(int $staffId, array $assignment, array $candidate): bool
    {
        $scopeType = (string) ($candidate['scope_type'] ?? '');
        $scopeId = (int) ($candidate['scope_id'] ?? 0);
        if (!isset(self::SCOPE_PRECEDENCE[$scopeType])) {
            return false;
        }

        return match ($scopeType) {
            'global' => $scopeId === 0,
            'staff' => $scopeId === $staffId,
            'org_unit' => $scopeId === (int) ($assignment['org_unit_id'] ?? 0),
            'job_title' => $scopeId === (int) ($assignment['job_title_id'] ?? 0),
            'group' => in_array($scopeId, array_map('intval', (array) ($assignment['group_ids'] ?? [])), true),
            default => false,
        };
    }

    private function dateInRange(DateTimeImmutable $date, mixed $from, mixed $to): bool
    {
        if ($from === null || trim((string) $from) === '') {
            return false;
        }
        $start = new DateTimeImmutable((string) $from);
        $end = $to === null || trim((string) $to) === '' ? null : new DateTimeImmutable((string) $to);

        return $date >= $start && ($end === null || $date < $end);
    }

    private function effectiveStart(array $candidate): string
    {
        $versionStart = new DateTimeImmutable((string) $candidate['valid_from']);
        $scopeStart = new DateTimeImmutable((string) ($candidate['scope_valid_from'] ?? $candidate['valid_from']));
        return ($scopeStart > $versionStart ? $scopeStart : $versionStart)->format(DateTimeImmutable::ATOM);
    }

    private function compareRank(array $left, array $right): int
    {
        $scope = $right['_scope_rank'] <=> $left['_scope_rank'];
        if ($scope !== 0) {
            return $scope;
        }
        $priority = $right['_priority'] <=> $left['_priority'];
        if ($priority !== 0) {
            return $priority;
        }

        return strcmp((string) $right['_effective_start'], (string) $left['_effective_start']);
    }

    private function sameRank(array $left, array $right): bool
    {
        return $left['_scope_rank'] === $right['_scope_rank']
            && $left['_priority'] === $right['_priority']
            && $left['_effective_start'] === $right['_effective_start'];
    }

    private function resolvedResult(
        string $status,
        int $staffId,
        DateTimeImmutable $workDate,
        array $assignment,
        ?array $current,
        ?array $proposed,
        array $selected,
        ?array $calendarException,
        array $approvedChanges,
        string $reasonCode
    ): array {
        $changed = $proposed !== null
            && (int) ($current['version_id'] ?? 0) !== (int) ($proposed['version_id'] ?? 0);

        return [
            'status' => $status,
            'reason_code' => $reasonCode,
            'staff_id' => $staffId,
            'work_date' => $workDate->format('Y-m-d'),
            'assignment' => $assignment,
            'current' => $current,
            'proposed' => $proposed,
            'selected' => $selected,
            'changed' => $changed,
            'conflicts' => [],
            'calendar_exception' => $calendarException,
            'approved_changes' => $approvedChanges,
            'explanation' => [
                'reason_code' => $reasonCode,
                'policy_id' => (int) ($selected['policy_id'] ?? 0),
                'version_id' => (int) ($selected['version_id'] ?? 0),
                'scope_type' => (string) ($selected['scope_type'] ?? ''),
                'scope_id' => (int) ($selected['scope_id'] ?? 0),
                'scope_priority' => (int) ($selected['scope_priority'] ?? $selected['priority'] ?? 0),
                'assignment_id' => (int) ($assignment['assignment_id'] ?? 0),
                'calendar_exception_id' => $calendarException === null ? null : (int) ($calendarException['id'] ?? 0),
                'schedule_change_request_id' => $selected['schedule_change_request_id'] ?? null,
            ],
        ];
    }

    private function unresolvedResult(
        int $staffId,
        DateTimeImmutable $workDate,
        array $assignment,
        string $reasonCode,
        array $conflicts,
        ?array $current,
        ?array $proposed
    ): array {
        sort($conflicts, SORT_NUMERIC);
        return [
            'status' => 'unresolved',
            'reason_code' => $reasonCode,
            'staff_id' => $staffId,
            'work_date' => $workDate->format('Y-m-d'),
            'assignment' => $assignment,
            'current' => $current,
            'proposed' => $proposed,
            'selected' => null,
            'changed' => false,
            'conflicts' => array_values($conflicts),
            'calendar_exception' => null,
            'approved_changes' => [],
            'explanation' => ['reason_code' => $reasonCode, 'conflicts' => array_values($conflicts)],
        ];
    }
}
