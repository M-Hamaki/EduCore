<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Organization;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffOrganizationRepository;
use InvalidArgumentException;

/**
 * Owns effective-dated organization, group, manager, and initial-assignment
 * commands. It never infers a legacy department or manager from a browser
 * payload: callers submit explicit identifiers, and ambiguity is rejected
 * before a Staff-owned row or audit record can be committed.
 */
final class StaffOrganizationService
{
    /** @var list<string> */
    private const REFERENCE_STATUSES = ['active', 'inactive', 'retired'];
    /** @var list<string> */
    private const MEMBERSHIP_STATUSES = ['active', 'suspended', 'retired'];
    /** @var list<string> */
    private const MANAGER_KINDS = ['direct', 'administrative', 'hr'];
    /** @var list<string> */
    private const MANAGER_SUBJECT_TYPES = ['staff', 'org_unit'];
    /** @var list<string> */
    private const ASSIGNMENT_KINDS = ['primary', 'secondary', 'temporary'];
    /** @var list<string> */
    private const EMPLOYMENT_STATUSES = ['active', 'suspended', 'ended', 'rehired'];

    private DateTimeZone $timezone;

    public function __construct(
        private StaffOrganizationRepository $repository,
        private AuditEventWriter $audit,
        ?DateTimeZone $timezone = null
    ) {
        $this->timezone = $timezone ?? new DateTimeZone('Africa/Cairo');
    }

    public function createOrganizationUnit(array $input, int $actorId): int
    {
        $command = $this->normalizeOrganizationUnit($input, $actorId);

        return $this->repository->transactional(function () use ($command): int {
            $this->assertOrganizationAdministrator($command['created_by']);
            if ($command['parent_id'] !== null
                && !$this->repository->orgUnitAvailableForRange(
                    $command['parent_id'],
                    $command['valid_from'],
                    $command['valid_to']
                )) {
                throw new DomainException('STAFF_ORG_PARENT_UNAVAILABLE');
            }
            if ($this->repository->hasOrgUnitCodeOverlap(
                $command['code'],
                $command['valid_from'],
                $command['valid_to']
            )) {
                throw new DomainException('STAFF_ORG_UNIT_RANGE_CONFLICT');
            }

            $id = $this->repository->insertOrgUnit($command);
            if ($id <= 0) {
                throw new DomainException('STAFF_ORG_UNIT_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_organization_unit_created',
                'staff_org_units',
                $id,
                $command['name'],
                [
                    'code' => $command['code'],
                    'unit_type' => $command['unit_type'],
                    'parent_id' => $command['parent_id'],
                    'valid_from' => $command['valid_from'],
                    'valid_to' => $command['valid_to'],
                    'status' => $command['status'],
                ],
                ['user_id' => $command['created_by']]
            );

            return $id;
        });
    }

    public function createJobTitle(array $input, int $actorId): int
    {
        $command = $this->normalizeJobTitle($input, $actorId);

        return $this->repository->transactional(function () use ($command): int {
            $this->assertOrganizationAdministrator($command['created_by']);
            if ($this->repository->hasJobTitleCodeOverlap(
                $command['code'],
                $command['active_from'],
                $command['active_to']
            )) {
                throw new DomainException('STAFF_ORG_JOB_TITLE_RANGE_CONFLICT');
            }

            $id = $this->repository->insertJobTitle($command);
            if ($id <= 0) {
                throw new DomainException('STAFF_ORG_JOB_TITLE_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_organization_job_title_created',
                'staff_job_titles',
                $id,
                $command['name'],
                [
                    'code' => $command['code'],
                    'active_from' => $command['active_from'],
                    'active_to' => $command['active_to'],
                    'status' => $command['status'],
                ],
                ['user_id' => $command['created_by']]
            );

            return $id;
        });
    }

    public function createPolicyGroup(array $input, int $actorId): int
    {
        $command = $this->normalizePolicyGroup($input, $actorId);

        return $this->repository->transactional(function () use ($command): int {
            $this->assertOrganizationAdministrator($command['created_by']);
            if ($this->repository->hasPolicyGroupCodeOverlap(
                $command['code'],
                $command['valid_from'],
                $command['valid_to']
            )) {
                throw new DomainException('STAFF_ORG_GROUP_RANGE_CONFLICT');
            }

            $id = $this->repository->insertPolicyGroup($command);
            if ($id <= 0) {
                throw new DomainException('STAFF_ORG_GROUP_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_organization_group_created',
                'staff_policy_groups',
                $id,
                $command['name'],
                [
                    'code' => $command['code'],
                    'valid_from' => $command['valid_from'],
                    'valid_to' => $command['valid_to'],
                    'status' => $command['status'],
                ],
                ['user_id' => $command['created_by']]
            );

            return $id;
        });
    }

    public function addPolicyGroupMembership(array $input, int $actorId): int
    {
        $command = $this->normalizeGroupMembership($input, $actorId);

        return $this->repository->transactional(function () use ($command): int {
            $this->assertOrganizationAdministrator($command['created_by']);
            if (!$this->repository->isStaffUser($command['staff_user_id'])) {
                throw new DomainException('STAFF_ORG_MEMBER_NOT_STAFF');
            }
            if (!$this->repository->policyGroupAvailableForRange(
                $command['group_id'],
                $command['valid_from'],
                $command['valid_to']
            )) {
                throw new DomainException('STAFF_ORG_GROUP_UNAVAILABLE');
            }
            if ($command['status'] === 'active'
                && $this->repository->hasActiveGroupMembershipOverlap($command)) {
                throw new DomainException('STAFF_ORG_GROUP_MEMBERSHIP_CONFLICT');
            }

            $id = $this->repository->insertPolicyGroupMembership($command);
            if ($id <= 0) {
                throw new DomainException('STAFF_ORG_GROUP_MEMBERSHIP_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_organization_group_membership_created',
                'staff_policy_group_memberships',
                $id,
                null,
                [
                    'group_id' => $command['group_id'],
                    'staff_user_id' => $command['staff_user_id'],
                    'valid_from' => $command['valid_from'],
                    'valid_to' => $command['valid_to'],
                    'status' => $command['status'],
                    'source' => $command['source'],
                ],
                ['user_id' => $command['created_by']]
            );

            return $id;
        });
    }

    public function assignManager(array $input, int $actorId): int
    {
        $command = $this->normalizeManagerAssignment($input, $actorId);

        return $this->repository->transactional(function () use ($command): int {
            $this->assertOrganizationAdministrator($command['created_by']);
            if (!$this->repository->isActiveUser($command['manager_user_id'])) {
                throw new DomainException('STAFF_ORG_MANAGER_ACCOUNT_INACTIVE');
            }
            if ($command['subject_type'] === 'staff') {
                if (!$this->repository->isStaffUser($command['subject_id'])) {
                    throw new DomainException('STAFF_ORG_MANAGER_SUBJECT_NOT_STAFF');
                }
                if ($command['subject_id'] === $command['manager_user_id']) {
                    throw new DomainException('STAFF_ORG_MANAGER_SELF_REFERENCE');
                }
            } elseif (!$this->repository->orgUnitAvailableForRange(
                $command['subject_id'],
                $command['valid_from'],
                $command['valid_to']
            )) {
                throw new DomainException('STAFF_ORG_MANAGER_UNIT_UNAVAILABLE');
            }
            if ($command['status'] === 'active'
                && $this->repository->hasActiveManagerScopeOverlap($command)) {
                throw new DomainException('STAFF_ORG_MANAGER_SCOPE_CONFLICT');
            }
            if ($command['status'] === 'active'
                && $command['subject_type'] === 'staff'
                && $this->wouldCreateManagerCycle($command)) {
                throw new DomainException('STAFF_ORG_MANAGER_CYCLE');
            }

            $id = $this->repository->insertManagerAssignment($command);
            if ($id <= 0) {
                throw new DomainException('STAFF_ORG_MANAGER_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_organization_manager_assigned',
                'staff_manager_assignments',
                $id,
                null,
                [
                    'subject_type' => $command['subject_type'],
                    'subject_id' => $command['subject_id'],
                    'manager_user_id' => $command['manager_user_id'],
                    'manager_kind' => $command['manager_kind'],
                    'priority' => $command['priority'],
                    'valid_from' => $command['valid_from'],
                    'valid_to' => $command['valid_to'],
                    'status' => $command['status'],
                ],
                ['user_id' => $command['created_by']]
            );

            return $id;
        });
    }

    /**
     * Creates an explicit assignment interval. A transfer/rehire correction
     * adds a non-overlapping successor rather than reusing this creation path
     * to overwrite an existing primary interval.
     */
    public function createAssignment(array $input, int $actorId): int
    {
        $command = $this->normalizeAssignment($input, $actorId);

        return $this->repository->transactional(function () use ($command): int {
            $this->assertOrganizationAdministrator($command['created_by']);
            if (!$this->repository->isStaffUser($command['staff_user_id'])) {
                throw new DomainException('STAFF_ORG_ASSIGNMENT_SUBJECT_NOT_STAFF');
            }
            if (!$this->repository->orgUnitAvailableForRange(
                $command['org_unit_id'],
                $command['valid_from'],
                $command['valid_to']
            ) || !$this->repository->jobTitleAvailableForRange(
                $command['job_title_id'],
                $command['valid_from'],
                $command['valid_to']
            )) {
                throw new DomainException('STAFF_ORG_ASSIGNMENT_REFERENCE_UNAVAILABLE');
            }
            if ($command['assignment_kind'] === 'primary'
                && $this->repository->hasPrimaryAssignmentOverlap($command)) {
                throw new DomainException('STAFF_ORG_PRIMARY_ASSIGNMENT_CONFLICT');
            }

            $id = $this->repository->insertAssignment($command);
            if ($id <= 0) {
                throw new DomainException('STAFF_ORG_ASSIGNMENT_PERSIST_FAILED');
            }
            $this->audit->recordEvent(
                'staff_organization_assignment_created',
                'staff_assignments',
                $id,
                null,
                [
                    'staff_user_id' => $command['staff_user_id'],
                    'org_unit_id' => $command['org_unit_id'],
                    'job_title_id' => $command['job_title_id'],
                    'assignment_kind' => $command['assignment_kind'],
                    'employment_status' => $command['employment_status'],
                    'work_fraction' => $command['work_fraction'],
                    'valid_from' => $command['valid_from'],
                    'valid_to' => $command['valid_to'],
                    'source' => $command['source'],
                ],
                ['user_id' => $command['created_by']]
            );

            return $id;
        });
    }

    /** @return array<string,mixed> */
    private function normalizeOrganizationUnit(array $input, int $actorId): array
    {
        [$validFrom, $validTo] = $this->dateRange(
            $input['valid_from'] ?? null,
            $input['valid_to'] ?? null,
            'STAFF_ORG_UNIT_VALIDITY_INVALID'
        );

        return [
            'code' => $this->code($input['code'] ?? null, 'STAFF_ORG_UNIT_CODE_INVALID'),
            'name' => $this->requiredText($input['name'] ?? null, 'STAFF_ORG_UNIT_NAME_INVALID', 200),
            'unit_type' => $this->unitType($input['unit_type'] ?? null),
            'parent_id' => $this->nullablePositiveId($input['parent_id'] ?? null, 'STAFF_ORG_PARENT_INVALID'),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'status' => $this->choice($input['status'] ?? 'active', self::REFERENCE_STATUSES, 'STAFF_ORG_UNIT_STATUS_INVALID'),
            'created_by' => $this->actorId($actorId),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeJobTitle(array $input, int $actorId): array
    {
        [$activeFrom, $activeTo] = $this->dateRange(
            $input['active_from'] ?? null,
            $input['active_to'] ?? null,
            'STAFF_ORG_JOB_TITLE_VALIDITY_INVALID'
        );

        return [
            'code' => $this->code($input['code'] ?? null, 'STAFF_ORG_JOB_TITLE_CODE_INVALID'),
            'name' => $this->requiredText($input['name'] ?? null, 'STAFF_ORG_JOB_TITLE_NAME_INVALID', 200),
            'active_from' => $activeFrom,
            'active_to' => $activeTo,
            'status' => $this->choice($input['status'] ?? 'active', self::REFERENCE_STATUSES, 'STAFF_ORG_JOB_TITLE_STATUS_INVALID'),
            'created_by' => $this->actorId($actorId),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizePolicyGroup(array $input, int $actorId): array
    {
        [$validFrom, $validTo] = $this->dateRange(
            $input['valid_from'] ?? null,
            $input['valid_to'] ?? null,
            'STAFF_ORG_GROUP_VALIDITY_INVALID'
        );

        return [
            'code' => $this->code($input['code'] ?? null, 'STAFF_ORG_GROUP_CODE_INVALID'),
            'name' => $this->requiredText($input['name'] ?? null, 'STAFF_ORG_GROUP_NAME_INVALID', 200),
            'purpose' => $this->nullableText($input['purpose'] ?? null, 'STAFF_ORG_GROUP_PURPOSE_INVALID', 500),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'status' => $this->choice($input['status'] ?? 'active', self::REFERENCE_STATUSES, 'STAFF_ORG_GROUP_STATUS_INVALID'),
            'created_by' => $this->actorId($actorId),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeGroupMembership(array $input, int $actorId): array
    {
        [$validFrom, $validTo] = $this->dateRange(
            $input['valid_from'] ?? null,
            $input['valid_to'] ?? null,
            'STAFF_ORG_GROUP_MEMBERSHIP_VALIDITY_INVALID'
        );

        return [
            'group_id' => $this->positiveId($input['group_id'] ?? null, 'STAFF_ORG_GROUP_INVALID'),
            'staff_user_id' => $this->positiveId($input['staff_user_id'] ?? null, 'STAFF_ORG_MEMBER_INVALID'),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'status' => $this->choice($input['status'] ?? 'active', self::MEMBERSHIP_STATUSES, 'STAFF_ORG_GROUP_MEMBERSHIP_STATUS_INVALID'),
            'source' => 'manual',
            'created_by' => $this->actorId($actorId),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeManagerAssignment(array $input, int $actorId): array
    {
        [$validFrom, $validTo] = $this->dateRange(
            $input['valid_from'] ?? null,
            $input['valid_to'] ?? null,
            'STAFF_ORG_MANAGER_VALIDITY_INVALID'
        );

        return [
            'subject_type' => $this->choice($input['subject_type'] ?? null, self::MANAGER_SUBJECT_TYPES, 'STAFF_ORG_MANAGER_SUBJECT_INVALID'),
            'subject_id' => $this->positiveId($input['subject_id'] ?? null, 'STAFF_ORG_MANAGER_SUBJECT_INVALID'),
            'manager_user_id' => $this->positiveId($input['manager_user_id'] ?? null, 'STAFF_ORG_MANAGER_USER_INVALID'),
            'manager_kind' => $this->choice($input['manager_kind'] ?? null, self::MANAGER_KINDS, 'STAFF_ORG_MANAGER_KIND_INVALID'),
            'priority' => $this->priority($input['priority'] ?? 0),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'status' => $this->choice($input['status'] ?? 'active', self::MEMBERSHIP_STATUSES, 'STAFF_ORG_MANAGER_STATUS_INVALID'),
            'source' => 'manual',
            'created_by' => $this->actorId($actorId),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeAssignment(array $input, int $actorId): array
    {
        [$validFrom, $validTo] = $this->dateRange(
            $input['valid_from'] ?? null,
            $input['valid_to'] ?? null,
            'STAFF_ORG_ASSIGNMENT_VALIDITY_INVALID'
        );
        $kind = $this->choice($input['assignment_kind'] ?? 'primary', self::ASSIGNMENT_KINDS, 'STAFF_ORG_ASSIGNMENT_KIND_INVALID');
        $status = $this->choice($input['employment_status'] ?? 'active', self::EMPLOYMENT_STATUSES, 'STAFF_ORG_EMPLOYMENT_STATUS_INVALID');
        if (($kind === 'temporary' || $status === 'ended') && $validTo === null) {
            throw new InvalidArgumentException('STAFF_ORG_ASSIGNMENT_END_DATE_REQUIRED');
        }

        return [
            'staff_user_id' => $this->positiveId($input['staff_user_id'] ?? null, 'STAFF_ORG_ASSIGNMENT_STAFF_INVALID'),
            'org_unit_id' => $this->positiveId($input['org_unit_id'] ?? null, 'STAFF_ORG_ASSIGNMENT_UNIT_INVALID'),
            'job_title_id' => $this->positiveId($input['job_title_id'] ?? null, 'STAFF_ORG_ASSIGNMENT_TITLE_INVALID'),
            'assignment_kind' => $kind,
            'employment_status' => $status,
            'work_fraction' => $this->workFraction($input['work_fraction'] ?? 1),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'source' => 'manual',
            'source_ref' => null,
            'version' => 1,
            'created_by' => $this->actorId($actorId),
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function wouldCreateManagerCycle(array $candidate): bool
    {
        $edges = $this->repository->activeStaffManagerEdgesInRange(
            $candidate['manager_kind'],
            $candidate['valid_from'],
            $candidate['valid_to']
        );
        $boundaries = [$candidate['valid_from'] => true];
        foreach ($edges as $edge) {
            $from = (string) ($edge['valid_from'] ?? '');
            if ($from >= $candidate['valid_from']
                && ($candidate['valid_to'] === null || $from <= $candidate['valid_to'])) {
                $boundaries[$from] = true;
            }
            $to = $edge['valid_to'] ?? null;
            if ($to !== null) {
                $next = $this->dayAfter((string) $to);
                if ($next >= $candidate['valid_from']
                    && ($candidate['valid_to'] === null || $next <= $candidate['valid_to'])) {
                    $boundaries[$next] = true;
                }
            }
        }
        ksort($boundaries, SORT_STRING);

        foreach (array_keys($boundaries) as $atDate) {
            /** @var array<int,list<int>> $graph */
            $graph = [];
            foreach ($edges as $edge) {
                if (!$this->dateFallsInRange(
                    (string) ($edge['valid_from'] ?? ''),
                    $edge['valid_to'] === null ? null : (string) $edge['valid_to'],
                    $atDate
                )) {
                    continue;
                }
                $graph[(int) $edge['subject_id']][] = (int) $edge['manager_user_id'];
            }
            $graph[(int) $candidate['subject_id']][] = (int) $candidate['manager_user_id'];
            if ($this->isReachable(
                (int) $candidate['manager_user_id'],
                (int) $candidate['subject_id'],
                $graph
            )) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,list<int>> $graph */
    private function isReachable(int $from, int $target, array $graph): bool
    {
        $pending = [$from];
        $visited = [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if ($current === $target) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($graph[$current] ?? [] as $next) {
                if (!isset($visited[$next])) {
                    $pending[] = $next;
                }
            }
        }

        return false;
    }

    private function assertOrganizationAdministrator(int $actorId): void
    {
        if (!$this->repository->actorCanManageOrganization($actorId)) {
            throw new DomainException('STAFF_ORG_ACTOR_FORBIDDEN');
        }
    }

    /** @return array{0:string,1:?string} */
    private function dateRange(mixed $from, mixed $to, string $error): array
    {
        $validFrom = $this->date($from, $error);
        $validTo = $this->nullableDate($to, $error);
        if ($validTo !== null && $validTo < $validFrom) {
            throw new InvalidArgumentException($error);
        }

        return [$validFrom, $validTo];
    }

    private function date(mixed $value, string $error): string
    {
        $text = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            throw new InvalidArgumentException($error);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function nullableDate(mixed $value, string $error): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->date($value, $error);
    }

    private function dayAfter(string $date): string
    {
        return (new DateTimeImmutable($date . ' 00:00:00', $this->timezone))
            ->modify('+1 day')
            ->format('Y-m-d');
    }

    private function dateFallsInRange(string $from, ?string $to, string $date): bool
    {
        return $from <= $date && ($to === null || $to >= $date);
    }

    private function actorId(int $actorId): int
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('STAFF_ORG_ACTOR_INVALID');
        }

        return $actorId;
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, $error);
    }

    private function priority(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 32767]]) === false) {
            throw new InvalidArgumentException('STAFF_ORG_MANAGER_PRIORITY_INVALID');
        }

        return (int) $value;
    }

    private function code(mixed $value, string $error): string
    {
        $code = strtoupper(trim((string) $value));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,79}$/', $code)) {
            throw new InvalidArgumentException($error);
        }

        return $code;
    }

    private function unitType(mixed $value): string
    {
        $type = strtolower(trim((string) $value));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $type)) {
            throw new InvalidArgumentException('STAFF_ORG_UNIT_TYPE_INVALID');
        }

        return $type;
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $error): string
    {
        $choice = strtolower(trim((string) $value));
        if (!in_array($choice, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }

        return $choice;
    }

    private function requiredText(mixed $value, string $error, int $maxLength): string
    {
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function nullableText(mixed $value, string $error, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function workFraction(mixed $value): string
    {
        $text = trim((string) $value);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $text)) {
            throw new InvalidArgumentException('STAFF_ORG_ASSIGNMENT_FRACTION_INVALID');
        }
        $fraction = (float) $text;
        if ($fraction <= 0 || $fraction > 1) {
            throw new InvalidArgumentException('STAFF_ORG_ASSIGNMENT_FRACTION_INVALID');
        }

        return number_format($fraction, 4, '.', '');
    }
}
