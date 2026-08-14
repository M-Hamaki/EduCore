<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyReadRepository;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Staff\Contracts\StaffPopulationAtDateQuery;
use InvalidArgumentException;
use Throwable;

/**
 * Read-only, cross-module preview of the staff affected by one schedule draft.
 *
 * Staff enumeration stays behind the Staff-owned query contract. Schedule
 * precedence and conflict decisions are delegated to the effective resolver;
 * this application service never reaches into PDO or another module's tables.
 */
final class SchedulePolicyImpactQuery
{
    private const SCOPE_TYPES = ['global', 'org_unit', 'job_title', 'group', 'staff'];
    private const MAX_PREVIEW_ROWS = 1000;

    public function __construct(
        private SchedulePolicyReadRepository $schedules,
        private EffectiveScheduleQueryService $effectiveSchedules,
        private StaffPopulationAtDateQuery $staffPopulation
    ) {
    }

    /**
     * @return array{
     *     version:array{policy_id:int,version_id:int,state:string,as_of:string},
     *     summary:array{population:int,affected:int,unchanged:int,conflict_count:int,truncated:bool},
     *     affected_staff:list<array{staff_id:int,current:array<string,mixed>,proposed:array<string,mixed>,explanation:array<string,mixed>}>,
     *     conflicts:list<array<string,mixed>>
     * }
     */
    public function previewDraft(
        int $versionId,
        DateTimeImmutable $asOf,
        int $limit = 100
    ): array {
        if ($versionId <= 0) {
            throw new InvalidArgumentException('SCHEDULE_PREVIEW_VERSION_INVALID');
        }
        if ($limit <= 0 || $limit > self::MAX_PREVIEW_ROWS) {
            throw new InvalidArgumentException('SCHEDULE_PREVIEW_LIMIT_INVALID');
        }

        $version = $this->schedules->findVersion($versionId);
        if ($version === null) {
            throw new DomainException('SCHEDULE_PREVIEW_VERSION_NOT_FOUND');
        }

        $resolvedVersionId = (int) ($version['version_id'] ?? $version['id'] ?? 0);
        if ($resolvedVersionId !== $versionId) {
            throw new DomainException('SCHEDULE_PREVIEW_VERSION_ID_MISMATCH');
        }
        if ((string) ($version['state'] ?? '') !== 'draft') {
            throw new DomainException('SCHEDULE_PREVIEW_VERSION_NOT_DRAFT');
        }

        $activeScopes = $this->activeScopes($version, $asOf);
        $proposedCandidates = $this->proposedCandidates($version, $activeScopes);

        /** @var array<int,array<string,mixed>> $staffById */
        $staffById = [];
        /** @var array<int,true> $populationIds */
        $populationIds = [];
        /** @var array<int,array<string,mixed>> $conflictsByStaff */
        $conflictsByStaff = [];

        $queriedScopes = [];
        foreach ($activeScopes as $scope) {
            $scopeType = (string) $scope['scope_type'];
            $scopeId = $scopeType === 'global' ? null : (int) $scope['scope_id'];
            $queryKey = $scopeType . ':' . ($scopeId ?? 'global');
            if (isset($queriedScopes[$queryKey])) {
                continue;
            }
            $queriedScopes[$queryKey] = true;

            $population = $this->staffPopulation->forScope($scopeType, $scopeId, $asOf);
            foreach ((array) ($population['conflicts'] ?? []) as $conflict) {
                if (!is_array($conflict)) {
                    continue;
                }
                $staffId = (int) ($conflict['staff_id'] ?? 0);
                if ($staffId <= 0) {
                    continue;
                }
                $populationIds[$staffId] = true;
                unset($staffById[$staffId]);
                $assignmentIds = array_values(array_unique(array_map(
                    'intval',
                    (array) ($conflict['assignment_ids'] ?? [])
                )));
                sort($assignmentIds, SORT_NUMERIC);
                $conflictsByStaff[$staffId] = [
                    'staff_id' => $staffId,
                    'phase' => 'population',
                    'reason_code' => 'AMBIGUOUS_STAFF_ASSIGNMENT',
                    'assignment_ids' => $assignmentIds,
                ];
            }

            foreach ((array) ($population['staff'] ?? []) as $staff) {
                if (!is_array($staff)) {
                    continue;
                }
                $staffId = (int) ($staff['staff_id'] ?? 0);
                if ($staffId <= 0) {
                    continue;
                }
                $populationIds[$staffId] = true;
                if (isset($conflictsByStaff[$staffId])) {
                    continue;
                }
                $assignment = $this->normaliseAssignment($staff);
                if (!$this->validAssignment($assignment)) {
                    unset($staffById[$staffId]);
                    $conflictsByStaff[$staffId] = [
                        'staff_id' => $staffId,
                        'phase' => 'population',
                        'reason_code' => 'STAFF_ASSIGNMENT_SNAPSHOT_INVALID',
                        'assignment_id' => (int) ($assignment['assignment_id'] ?? 0),
                    ];
                    continue;
                }
                if (!isset($staffById[$staffId])) {
                    $staffById[$staffId] = $assignment;
                    continue;
                }
                if (!$this->sameAssignment($staffById[$staffId], $assignment)) {
                    $existingAssignment = $staffById[$staffId];
                    unset($staffById[$staffId]);
                    $assignmentIds = array_values(array_unique([
                        (int) ($existingAssignment['assignment_id'] ?? 0),
                        (int) ($assignment['assignment_id'] ?? 0),
                    ]));
                    sort($assignmentIds, SORT_NUMERIC);
                    $conflictsByStaff[$staffId] = [
                        'staff_id' => $staffId,
                        'phase' => 'population',
                        'reason_code' => 'INCONSISTENT_ASSIGNMENT_SNAPSHOT',
                        'assignment_ids' => $assignmentIds,
                    ];
                }
            }
        }

        ksort($staffById, SORT_NUMERIC);
        $affected = [];
        $unchanged = 0;
        foreach ($staffById as $staffId => $assignment) {
            if (($assignment['employment_status'] ?? '') !== 'active') {
                ++$unchanged;
                continue;
            }

            try {
                $publishedCandidates = $this->schedules->candidateVersionsFor($staffId, $assignment, $asOf);
                $calendarExceptions = $this->schedules->calendarExceptionsFor($staffId, $assignment, $asOf);

                $currentBase = $this->effectiveSchedules->resolveFromCandidates(
                    $staffId,
                    $asOf,
                    $assignment,
                    $publishedCandidates,
                    $calendarExceptions
                );
                if ($this->isBlockingResolution($currentBase, true)) {
                    $conflictsByStaff[$staffId] = $this->resolutionConflict(
                        $staffId,
                        $assignment,
                        'current',
                        $currentBase
                    );
                    continue;
                }

                [$windowStart, $windowEnd] = $this->changeWindow($asOf, $currentBase);
                $approvedChanges = $this->schedules->approvedChangesFor($staffId, $windowStart, $windowEnd);
                $current = $this->effectiveSchedules->resolveFromCandidates(
                    $staffId,
                    $asOf,
                    $assignment,
                    $publishedCandidates,
                    $calendarExceptions,
                    $approvedChanges
                );
                if ($this->isBlockingResolution($current, true)) {
                    $conflictsByStaff[$staffId] = $this->resolutionConflict(
                        $staffId,
                        $assignment,
                        'current',
                        $current
                    );
                    continue;
                }

                // Draft scopes are evaluated together as synthetic published
                // candidates. This reuses the authoritative precedence/tie
                // resolver and correctly handles one worker matching many scopes.
                $proposed = $this->effectiveSchedules->resolveFromCandidates(
                    $staffId,
                    $asOf,
                    $assignment,
                    array_merge($publishedCandidates, $proposedCandidates),
                    $calendarExceptions,
                    $approvedChanges
                );
                if ($this->isBlockingResolution($proposed, false)) {
                    $conflictsByStaff[$staffId] = $this->resolutionConflict(
                        $staffId,
                        $assignment,
                        'proposed',
                        $proposed
                    );
                    continue;
                }

                $currentView = $this->resolutionView($current);
                $proposedView = $this->resolutionView($proposed);
                if ($this->sameResolution($currentView, $proposedView)) {
                    ++$unchanged;
                    continue;
                }

                $affected[] = [
                    'staff_id' => $staffId,
                    'current' => $currentView,
                    'proposed' => $proposedView,
                    'explanation' => array_merge(
                        (array) ($proposed['explanation'] ?? []),
                        [
                            'impact_reason_code' => 'EFFECTIVE_SCHEDULE_WOULD_CHANGE',
                            'assignment_id' => (int) ($assignment['assignment_id'] ?? 0),
                            'rounding_rule' => $proposedView['rounding_rule'],
                        ]
                    ),
                ];
            } catch (Throwable) {
                $conflictsByStaff[$staffId] = [
                    'staff_id' => $staffId,
                    'phase' => 'resolution',
                    'reason_code' => 'SCHEDULE_PREVIEW_RESOLUTION_FAILED',
                    'assignment_id' => (int) ($assignment['assignment_id'] ?? 0),
                ];
            }
        }

        usort($affected, static fn (array $left, array $right): int => $left['staff_id'] <=> $right['staff_id']);
        ksort($conflictsByStaff, SORT_NUMERIC);
        $conflicts = array_values($conflictsByStaff);
        $affectedCount = count($affected);
        $conflictCount = count($conflicts);

        return [
            'version' => [
                'policy_id' => (int) ($version['policy_id'] ?? 0),
                'version_id' => $versionId,
                'state' => 'draft',
                'as_of' => $asOf->format(DateTimeImmutable::ATOM),
            ],
            'summary' => [
                'population' => count($populationIds),
                'affected' => $affectedCount,
                'unchanged' => $unchanged,
                'conflict_count' => $conflictCount,
                'truncated' => $affectedCount > $limit || $conflictCount > $limit,
            ],
            'affected_staff' => array_slice($affected, 0, $limit),
            'conflicts' => array_slice($conflicts, 0, $limit),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function activeScopes(array $version, DateTimeImmutable $asOf): array
    {
        if (!$this->dateInRange($asOf, $version['valid_from'] ?? null, $version['valid_to'] ?? null)) {
            return [];
        }

        $scopes = $version['scopes'] ?? $version['scope_rows'] ?? null;
        if ($scopes === null && isset($version['scope_type'])) {
            $scopes = [$version];
        }
        if (!is_array($scopes) || $scopes === []) {
            throw new DomainException('SCHEDULE_PREVIEW_SCOPE_MISSING');
        }

        $active = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope)) {
                throw new DomainException('SCHEDULE_PREVIEW_SCOPE_INVALID');
            }
            $type = strtolower(trim((string) ($scope['scope_type'] ?? '')));
            if (!in_array($type, self::SCOPE_TYPES, true)) {
                throw new DomainException('SCHEDULE_PREVIEW_SCOPE_INVALID');
            }
            $id = (int) ($scope['scope_id'] ?? 0);
            if (($type === 'global' && $id !== 0) || ($type !== 'global' && $id <= 0)) {
                throw new DomainException('SCHEDULE_PREVIEW_SCOPE_INVALID');
            }
            $scopeFrom = $scope['valid_from'] ?? $scope['scope_valid_from'] ?? $version['valid_from'];
            $scopeTo = $scope['valid_to'] ?? $scope['scope_valid_to'] ?? $version['valid_to'] ?? null;
            if (!$this->dateInRange($asOf, $scopeFrom, $scopeTo)) {
                continue;
            }
            $scope['scope_type'] = $type;
            $scope['scope_id'] = $id;
            $scope['scope_valid_from'] = $scopeFrom;
            $scope['scope_valid_to'] = $scopeTo;
            $active[] = $scope;
        }

        return $active;
    }

    /** @param list<array<string,mixed>> $scopes @return list<array<string,mixed>> */
    private function proposedCandidates(array $version, array $scopes): array
    {
        $schedule = $version['schedule'] ?? null;
        if (!$schedule instanceof WorkSchedule && !is_array($schedule)) {
            $schedule = [
                'timezone' => (string) ($version['timezone'] ?? 'Africa/Cairo'),
                'rounding_rule' => (string) ($version['rounding_rule'] ?? 'none'),
                'season_start_mmdd' => $version['season_start_mmdd'] ?? null,
                'season_end_mmdd' => $version['season_end_mmdd'] ?? null,
                'days' => (array) ($version['days'] ?? []),
            ];
        }

        $candidates = [];
        foreach ($scopes as $scope) {
            $candidates[] = [
                'policy_id' => (int) ($version['policy_id'] ?? 0),
                'policy_code' => (string) ($version['policy_code'] ?? ''),
                'policy_name' => (string) ($version['policy_name'] ?? ''),
                'version_id' => (int) ($version['version_id'] ?? $version['id'] ?? 0),
                'version_no' => (int) ($version['version_no'] ?? 0),
                'rounding_rule' => (string) ($version['rounding_rule'] ?? 'none'),
                // The resolver only considers published candidates. The row is
                // not persisted or mutated; this state exists solely in memory.
                'state' => 'published',
                'valid_from' => $version['valid_from'] ?? null,
                'valid_to' => $version['valid_to'] ?? null,
                'scope_id_record' => (int) ($scope['id'] ?? $scope['scope_id_record'] ?? 0),
                'scope_type' => (string) $scope['scope_type'],
                'scope_id' => (int) $scope['scope_id'],
                'scope_priority' => (int) ($scope['priority'] ?? $scope['scope_priority'] ?? 0),
                'scope_valid_from' => $scope['scope_valid_from'],
                'scope_valid_to' => $scope['scope_valid_to'],
                'schedule' => $schedule,
            ];
        }

        return $candidates;
    }

    /** @return array<string,mixed> */
    private function normaliseAssignment(array $staff): array
    {
        $groupIds = array_values(array_filter(
            array_unique(array_map('intval', (array) ($staff['group_ids'] ?? []))),
            static fn (int $groupId): bool => $groupId > 0
        ));
        sort($groupIds, SORT_NUMERIC);

        return [
            'assignment_id' => (int) ($staff['assignment_id'] ?? 0),
            'org_unit_id' => (int) ($staff['org_unit_id'] ?? 0),
            'job_title_id' => (int) ($staff['job_title_id'] ?? 0),
            'group_ids' => $groupIds,
            'employment_status' => (string) ($staff['employment_status'] ?? ''),
        ];
    }

    private function validAssignment(array $assignment): bool
    {
        return (int) ($assignment['assignment_id'] ?? 0) > 0
            && (int) ($assignment['org_unit_id'] ?? 0) > 0
            && (int) ($assignment['job_title_id'] ?? 0) > 0
            && trim((string) ($assignment['employment_status'] ?? '')) !== '';
    }

    private function sameAssignment(array $left, array $right): bool
    {
        return $left === $right;
    }

    private function dateInRange(DateTimeImmutable $at, mixed $from, mixed $to): bool
    {
        if ($from === null || trim((string) $from) === '') {
            throw new DomainException('SCHEDULE_PREVIEW_EFFECTIVE_DATE_MISSING');
        }
        try {
            $start = new DateTimeImmutable((string) $from);
            $end = $to === null || trim((string) $to) === '' ? null : new DateTimeImmutable((string) $to);
        } catch (Throwable $exception) {
            throw new DomainException('SCHEDULE_PREVIEW_EFFECTIVE_DATE_INVALID', 0, $exception);
        }

        return $at >= $start && ($end === null || $at < $end);
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    private function changeWindow(DateTimeImmutable $asOf, array $resolution): array
    {
        $start = $asOf->setTime(0, 0, 0, 0);
        $end = $start->modify('+1 day');
        $schedule = $resolution['selected']['schedule'] ?? null;
        if ($schedule instanceof WorkSchedule) {
            $window = $schedule->workWindow($asOf);
            if ($window !== null) {
                return [$window['start'], $window['end']];
            }
        }

        return [$start, $end];
    }

    private function isBlockingResolution(array $resolution, bool $allowNotFound): bool
    {
        if (($resolution['status'] ?? '') !== 'unresolved') {
            return false;
        }

        return !$allowNotFound || ($resolution['reason_code'] ?? '') !== 'SCHEDULE_NOT_FOUND';
    }

    /** @return array<string,mixed> */
    private function resolutionConflict(
        int $staffId,
        array $assignment,
        string $phase,
        array $resolution
    ): array {
        $ids = array_values(array_unique(array_map('intval', (array) ($resolution['conflicts'] ?? []))));
        sort($ids, SORT_NUMERIC);

        return [
            'staff_id' => $staffId,
            'phase' => $phase,
            'reason_code' => (string) ($resolution['reason_code'] ?? 'SCHEDULE_PREVIEW_CONFLICT'),
            'assignment_id' => (int) ($assignment['assignment_id'] ?? 0),
            'conflict_ids' => $ids,
        ];
    }

    /** @return array<string,mixed> */
    private function resolutionView(array $resolution): array
    {
        $selected = is_array($resolution['selected'] ?? null) ? $resolution['selected'] : [];
        $schedule = $selected['schedule'] ?? null;
        if ($schedule instanceof WorkSchedule) {
            $schedule = $schedule->toArray();
        } elseif (isset($selected['schedule_payload']) && is_array($selected['schedule_payload'])) {
            $schedule = $selected['schedule_payload'];
        } elseif (!is_array($schedule)) {
            $schedule = null;
        }

        return [
            'status' => (string) ($resolution['status'] ?? 'unresolved'),
            'reason_code' => (string) ($resolution['reason_code'] ?? ''),
            'policy_id' => $selected === [] ? null : (int) ($selected['policy_id'] ?? 0),
            'policy_name' => $selected === [] ? null : (string) ($selected['policy_name'] ?? ''),
            'version_id' => $selected === [] ? null : (int) ($selected['version_id'] ?? 0),
            'scope_type' => $selected === [] ? null : (string) ($selected['scope_type'] ?? ''),
            'scope_id' => $selected === [] ? null : (int) ($selected['scope_id'] ?? 0),
            'scope_priority' => $selected === [] ? null : (int) ($selected['scope_priority'] ?? $selected['priority'] ?? 0),
            'rounding_rule' => $selected === [] ? null : (string) ($selected['rounding_rule'] ?? 'none'),
            'schedule' => $schedule,
            'calendar_exception_id' => $resolution['calendar_exception'] === null
                ? null
                : (int) (($resolution['calendar_exception']['id'] ?? 0)),
        ];
    }

    private function sameResolution(array $current, array $proposed): bool
    {
        return $current === $proposed;
    }
}
