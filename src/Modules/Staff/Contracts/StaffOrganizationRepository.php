<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned persistence boundary for effective-dated organization commands.
 *
 * The application service owns validation, ambiguity/cycle policy, and the
 * mandatory audit record. This adapter only locks/queries Staff resources and
 * persists already validated commands inside that service transaction.
 */
interface StaffOrganizationRepository
{
    public function transactional(callable $work): mixed;

    public function actorCanManageOrganization(int $actorId): bool;

    public function isStaffUser(int $staffUserId): bool;

    public function isActiveUser(int $userId): bool;

    public function orgUnitAvailableForRange(int $orgUnitId, string $validFrom, ?string $validTo): bool;

    public function jobTitleAvailableForRange(int $jobTitleId, string $validFrom, ?string $validTo): bool;

    public function policyGroupAvailableForRange(int $groupId, string $validFrom, ?string $validTo): bool;

    public function hasOrgUnitCodeOverlap(string $code, string $validFrom, ?string $validTo): bool;

    public function hasJobTitleCodeOverlap(string $code, string $validFrom, ?string $validTo): bool;

    public function hasPolicyGroupCodeOverlap(string $code, string $validFrom, ?string $validTo): bool;

    /** @param array<string,mixed> $membership */
    public function hasActiveGroupMembershipOverlap(array $membership): bool;

    /**
     * @return list<array{subject_id:int,manager_user_id:int,valid_from:string,valid_to:?string}>
     */
    public function activeStaffManagerEdgesInRange(string $managerKind, string $validFrom, ?string $validTo): array;

    /** @param array<string,mixed> $manager */
    public function hasActiveManagerScopeOverlap(array $manager): bool;

    /** @param array<string,mixed> $assignment */
    public function hasPrimaryAssignmentOverlap(array $assignment): bool;

    /** @param array<string,mixed> $orgUnit */
    public function insertOrgUnit(array $orgUnit): int;

    /** @param array<string,mixed> $jobTitle */
    public function insertJobTitle(array $jobTitle): int;

    /** @param array<string,mixed> $group */
    public function insertPolicyGroup(array $group): int;

    /** @param array<string,mixed> $membership */
    public function insertPolicyGroupMembership(array $membership): int;

    /** @param array<string,mixed> $manager */
    public function insertManagerAssignment(array $manager): int;

    /** @param array<string,mixed> $assignment */
    public function insertAssignment(array $assignment): int;
}
