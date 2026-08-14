<?php

declare(strict_types=1);

/** Isolated command/audit proof for effective-dated Staff organization administration. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Organization\StaffOrganizationService;
use EduCore\Modules\Staff\Contracts\StaffOrganizationRepository;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffOrganizationRepository;

final class StaffOrganizationTestAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public bool $failNext = false;

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('AUDIT_WRITE_FAILED');
        }

        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

final class StaffOrganizationMemoryRepository implements StaffOrganizationRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $orgUnits = [
        1 => ['id' => 1, 'code' => 'SCHOOL', 'valid_from' => '2020-01-01', 'valid_to' => null, 'status' => 'active'],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $jobTitles = [
        1 => ['id' => 1, 'code' => 'TEACHER', 'active_from' => '2020-01-01', 'active_to' => null, 'status' => 'active'],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $policyGroups = [];
    /** @var array<int,array<string,mixed>> */
    public array $memberships = [];
    /** @var array<int,array<string,mixed>> */
    public array $managerAssignments = [];
    /** @var array<int,array<string,mixed>> */
    public array $assignments = [];

    private int $nextId = 100;

    public function transactional(callable $work): mixed
    {
        $snapshot = [
            'orgUnits' => $this->orgUnits,
            'jobTitles' => $this->jobTitles,
            'policyGroups' => $this->policyGroups,
            'memberships' => $this->memberships,
            'managerAssignments' => $this->managerAssignments,
            'assignments' => $this->assignments,
            'nextId' => $this->nextId,
        ];

        try {
            return $work();
        } catch (Throwable $exception) {
            $this->orgUnits = $snapshot['orgUnits'];
            $this->jobTitles = $snapshot['jobTitles'];
            $this->policyGroups = $snapshot['policyGroups'];
            $this->memberships = $snapshot['memberships'];
            $this->managerAssignments = $snapshot['managerAssignments'];
            $this->assignments = $snapshot['assignments'];
            $this->nextId = $snapshot['nextId'];
            throw $exception;
        }
    }

    public function actorCanManageOrganization(int $actorId): bool
    {
        return $actorId === 9001;
    }

    public function isStaffUser(int $staffUserId): bool
    {
        return in_array($staffUserId, [101, 102, 103], true);
    }

    public function isActiveUser(int $userId): bool
    {
        return in_array($userId, [101, 102, 103, 9001], true);
    }

    public function orgUnitAvailableForRange(int $orgUnitId, string $validFrom, ?string $validTo): bool
    {
        return $this->referenceAvailable($this->orgUnits[$orgUnitId] ?? null, 'valid_from', 'valid_to', $validFrom, $validTo);
    }

    public function jobTitleAvailableForRange(int $jobTitleId, string $validFrom, ?string $validTo): bool
    {
        return $this->referenceAvailable($this->jobTitles[$jobTitleId] ?? null, 'active_from', 'active_to', $validFrom, $validTo);
    }

    public function policyGroupAvailableForRange(int $groupId, string $validFrom, ?string $validTo): bool
    {
        return $this->referenceAvailable($this->policyGroups[$groupId] ?? null, 'valid_from', 'valid_to', $validFrom, $validTo);
    }

    public function hasOrgUnitCodeOverlap(string $code, string $validFrom, ?string $validTo): bool
    {
        return $this->hasCodeOverlap($this->orgUnits, $code, 'valid_from', 'valid_to', $validFrom, $validTo);
    }

    public function hasJobTitleCodeOverlap(string $code, string $validFrom, ?string $validTo): bool
    {
        return $this->hasCodeOverlap($this->jobTitles, $code, 'active_from', 'active_to', $validFrom, $validTo);
    }

    public function hasPolicyGroupCodeOverlap(string $code, string $validFrom, ?string $validTo): bool
    {
        return $this->hasCodeOverlap($this->policyGroups, $code, 'valid_from', 'valid_to', $validFrom, $validTo);
    }

    public function hasActiveGroupMembershipOverlap(array $membership): bool
    {
        foreach ($this->memberships as $existing) {
            if ((int) $existing['group_id'] === (int) $membership['group_id']
                && (int) $existing['staff_user_id'] === (int) $membership['staff_user_id']
                && $existing['status'] === 'active'
                && $this->rangesOverlap($existing['valid_from'], $existing['valid_to'], $membership['valid_from'], $membership['valid_to'])) {
                return true;
            }
        }

        return false;
    }

    public function activeStaffManagerEdgesInRange(string $managerKind, string $validFrom, ?string $validTo): array
    {
        $edges = [];
        foreach ($this->managerAssignments as $existing) {
            if ($existing['subject_type'] === 'staff'
                && $existing['manager_kind'] === $managerKind
                && $existing['status'] === 'active'
                && $this->rangesOverlap($existing['valid_from'], $existing['valid_to'], $validFrom, $validTo)) {
                $edges[] = [
                    'subject_id' => (int) $existing['subject_id'],
                    'manager_user_id' => (int) $existing['manager_user_id'],
                    'valid_from' => (string) $existing['valid_from'],
                    'valid_to' => $existing['valid_to'] === null ? null : (string) $existing['valid_to'],
                ];
            }
        }

        return $edges;
    }

    public function hasActiveManagerScopeOverlap(array $manager): bool
    {
        foreach ($this->managerAssignments as $existing) {
            if ($existing['subject_type'] === $manager['subject_type']
                && (int) $existing['subject_id'] === (int) $manager['subject_id']
                && $existing['manager_kind'] === $manager['manager_kind']
                && (int) $existing['priority'] === (int) $manager['priority']
                && $existing['status'] === 'active'
                && $this->rangesOverlap($existing['valid_from'], $existing['valid_to'], $manager['valid_from'], $manager['valid_to'])) {
                return true;
            }
        }

        return false;
    }

    public function hasPrimaryAssignmentOverlap(array $assignment): bool
    {
        foreach ($this->assignments as $existing) {
            if ((int) $existing['staff_user_id'] === (int) $assignment['staff_user_id']
                && $existing['assignment_kind'] === 'primary'
                && $this->rangesOverlap($existing['valid_from'], $existing['valid_to'], $assignment['valid_from'], $assignment['valid_to'])) {
                return true;
            }
        }

        return false;
    }

    public function insertOrgUnit(array $orgUnit): int
    {
        return $this->insert($this->orgUnits, $orgUnit);
    }

    public function insertJobTitle(array $jobTitle): int
    {
        return $this->insert($this->jobTitles, $jobTitle);
    }

    public function insertPolicyGroup(array $group): int
    {
        return $this->insert($this->policyGroups, $group);
    }

    public function insertPolicyGroupMembership(array $membership): int
    {
        return $this->insert($this->memberships, $membership);
    }

    public function insertManagerAssignment(array $manager): int
    {
        return $this->insert($this->managerAssignments, $manager);
    }

    public function insertAssignment(array $assignment): int
    {
        return $this->insert($this->assignments, $assignment);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function hasCodeOverlap(array $rows, string $code, string $fromColumn, string $toColumn, string $validFrom, ?string $validTo): bool
    {
        foreach ($rows as $row) {
            if ($row['code'] === $code && $this->rangesOverlap($row[$fromColumn], $row[$toColumn], $validFrom, $validTo)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed>|null $reference */
    private function referenceAvailable(?array $reference, string $fromColumn, string $toColumn, string $validFrom, ?string $validTo): bool
    {
        return $reference !== null
            && $reference['status'] === 'active'
            && $reference[$fromColumn] <= $validFrom
            && ($validTo !== null
                ? ($reference[$toColumn] === null || $reference[$toColumn] >= $validTo)
                : $reference[$toColumn] === null);
    }

    private function rangesOverlap(string $fromA, ?string $toA, string $fromB, ?string $toB): bool
    {
        return ($toB === null || $fromA <= $toB) && ($toA === null || $toA >= $fromB);
    }

    /** @param array<int,array<string,mixed>> $target @param array<string,mixed> $row */
    private function insert(array &$target, array $row): int
    {
        $id = ++$this->nextId;
        $row['id'] = $id;
        $target[$id] = $row;

        return $id;
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertError = static function (callable $callback, string $expectedCode, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message . ' (no error)');
    } catch (Throwable $exception) {
        $assert($exception->getMessage() === $expectedCode, $message . ' (' . $exception->getMessage() . ')');
    }
};

try {
    $repository = new StaffOrganizationMemoryRepository();
    $audit = new StaffOrganizationTestAudit();
    $service = new StaffOrganizationService($repository, $audit, new DateTimeZone('UTC'));
    $unit = static fn (string $code, string $from = '2026-01-01', string $to = ''): array => [
        'code' => $code,
        'name' => 'وحدة ' . $code,
        'unit_type' => 'department',
        'parent_id' => 1,
        'valid_from' => $from,
        'valid_to' => $to,
        'status' => 'active',
    ];
    $group = static fn (string $code): array => [
        'code' => $code,
        'name' => 'مجموعة ' . $code,
        'purpose' => 'اختبار نطاق السياسة',
        'valid_from' => '2026-01-01',
        'valid_to' => '',
        'status' => 'active',
    ];

    $assert(class_exists(PdoStaffOrganizationRepository::class, false), 'organization PDO adapter is loaded through the Staff bootstrap');
    $assertError(
        static fn (): int => $service->createOrganizationUnit($unit('FORBIDDEN'), 9002),
        'STAFF_ORG_ACTOR_FORBIDDEN',
        'non-HR actor cannot create an organization unit'
    );

    $unitId = $service->createOrganizationUnit($unit('hr-services'), 9001);
    $assert($unitId > 0 && ($repository->orgUnits[$unitId]['code'] ?? null) === 'HR-SERVICES', 'organization code is normalized and persisted');
    $assertError(
        static fn (): int => $service->createOrganizationUnit($unit('HR-SERVICES', '2026-06-01'), 9001),
        'STAFF_ORG_UNIT_RANGE_CONFLICT',
        'overlapping effective organization code is rejected'
    );

    $jobTitleId = $service->createJobTitle([
        'code' => 'hr-officer',
        'name' => 'مسؤول موارد بشرية',
        'active_from' => '2026-01-01',
        'active_to' => '',
        'status' => 'active',
    ], 9001);
    $assert($jobTitleId > 0 && ($repository->jobTitles[$jobTitleId]['code'] ?? null) === 'HR-OFFICER', 'job title is effective-dated and audited');

    $groupId = $service->createPolicyGroup($group('HR-TEAM'), 9001);
    $membershipId = $service->addPolicyGroupMembership([
        'group_id' => $groupId,
        'staff_user_id' => 101,
        'valid_from' => '2026-01-01',
        'valid_to' => '',
        'status' => 'active',
    ], 9001);
    $assert($membershipId > 0, 'staff member can be added to an active policy group');
    $assertError(
        static fn (): int => $service->addPolicyGroupMembership([
            'group_id' => $groupId,
            'staff_user_id' => 101,
            'valid_from' => '2026-06-01',
            'valid_to' => '',
            'status' => 'active',
        ], 9001),
        'STAFF_ORG_GROUP_MEMBERSHIP_CONFLICT',
        'overlapping active group membership is rejected'
    );

    $assertError(
        static fn (): int => $service->assignManager([
            'subject_type' => 'staff',
            'subject_id' => 101,
            'manager_user_id' => 101,
            'manager_kind' => 'direct',
            'priority' => 0,
            'valid_from' => '2026-01-01',
            'valid_to' => '',
            'status' => 'active',
        ], 9001),
        'STAFF_ORG_MANAGER_SELF_REFERENCE',
        'self-manager relationship is rejected'
    );
    $managerId = $service->assignManager([
        'subject_type' => 'staff',
        'subject_id' => 101,
        'manager_user_id' => 102,
        'manager_kind' => 'direct',
        'priority' => 0,
        'valid_from' => '2026-01-01',
        'valid_to' => '',
        'status' => 'active',
    ], 9001);
    $assert($managerId > 0, 'dated direct-manager relationship is persisted');
    $assertError(
        static fn (): int => $service->assignManager([
            'subject_type' => 'staff',
            'subject_id' => 101,
            'manager_user_id' => 103,
            'manager_kind' => 'direct',
            'priority' => 0,
            'valid_from' => '2026-06-01',
            'valid_to' => '',
            'status' => 'active',
        ], 9001),
        'STAFF_ORG_MANAGER_SCOPE_CONFLICT',
        'same-priority overlapping manager scope is rejected'
    );
    $assertError(
        static fn (): int => $service->assignManager([
            'subject_type' => 'staff',
            'subject_id' => 102,
            'manager_user_id' => 101,
            'manager_kind' => 'direct',
            'priority' => 0,
            'valid_from' => '2026-01-01',
            'valid_to' => '',
            'status' => 'active',
        ], 9001),
        'STAFF_ORG_MANAGER_CYCLE',
        'dated manager cycle is rejected before persistence'
    );

    $primaryId = $service->createAssignment([
        'staff_user_id' => 101,
        'org_unit_id' => 1,
        'job_title_id' => 1,
        'assignment_kind' => 'primary',
        'employment_status' => 'active',
        'work_fraction' => '1',
        'valid_from' => '2026-01-01',
        'valid_to' => '',
    ], 9001);
    $assert($primaryId > 0 && ($repository->assignments[$primaryId]['work_fraction'] ?? null) === '1.0000', 'primary assignment is normalized and persisted');
    $assertError(
        static fn (): int => $service->createAssignment([
            'staff_user_id' => 101,
            'org_unit_id' => 1,
            'job_title_id' => 1,
            'assignment_kind' => 'primary',
            'employment_status' => 'active',
            'work_fraction' => '1',
            'valid_from' => '2026-06-01',
            'valid_to' => '',
        ], 9001),
        'STAFF_ORG_PRIMARY_ASSIGNMENT_CONFLICT',
        'overlapping primary assignment is rejected'
    );
    $secondaryId = $service->createAssignment([
        'staff_user_id' => 101,
        'org_unit_id' => 1,
        'job_title_id' => 1,
        'assignment_kind' => 'secondary',
        'employment_status' => 'active',
        'work_fraction' => '0.2500',
        'valid_from' => '2026-06-01',
        'valid_to' => '',
    ], 9001);
    $assert($secondaryId > 0, 'concurrent secondary assignment remains allowed');
    $assertError(
        static fn (): int => $service->createAssignment([
            'staff_user_id' => 102,
            'org_unit_id' => 1,
            'job_title_id' => 1,
            'assignment_kind' => 'temporary',
            'employment_status' => 'active',
            'work_fraction' => '1',
            'valid_from' => '2026-01-01',
            'valid_to' => '',
        ], 9001),
        'STAFF_ORG_ASSIGNMENT_END_DATE_REQUIRED',
        'temporary assignment cannot be open-ended'
    );

    $groupsBeforeAuditFailure = count($repository->policyGroups);
    $audit->failNext = true;
    $assertError(
        static fn (): int => $service->createPolicyGroup($group('ROLLBACK-GROUP'), 9001),
        'AUDIT_WRITE_FAILED',
        'mandatory audit failure reaches the caller'
    );
    $assert(count($repository->policyGroups) === $groupsBeforeAuditFailure, 'audit failure rolls back the organization business write');
    $assert(count($audit->events) >= 6, 'each successful organization command records shared audit evidence');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR organization service: PASS\n";
