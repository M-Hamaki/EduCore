<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\ApprovalRoleAssigneeQuery;
use InvalidArgumentException;

/**
 * Verifies the currently active, policy-designated authority for a staffing
 * exception and captures only the matched role keys as decision evidence.
 */
final class LeaveStaffingOverrideAuthorization
{
    public function __construct(private ApprovalRoleAssigneeQuery $roles)
    {
    }

    /**
     * @param list<string> $requiredRoleKeys
     * @return list<string>
     */
    public function assertCanDecide(
        int $actorId,
        array $requiredRoleKeys,
        DateTimeImmutable $resolvedAt
    ): array {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('LEAVE_STAFFING_OVERRIDE_ACTOR_INVALID');
        }
        $required = $this->roleKeys($requiredRoleKeys);
        if ($required === []) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_ROLE_REQUIRED');
        }

        $actorRoles = [];
        foreach ($this->roles->activeUsersForRoles($required, $resolvedAt) as $candidate) {
            if (!is_array($candidate) || (int) ($candidate['user_id'] ?? 0) <= 0) {
                throw new DomainException('LEAVE_STAFFING_OVERRIDE_ROLE_PAYLOAD_INVALID');
            }
            if ((int) $candidate['user_id'] !== $actorId) {
                continue;
            }
            $actorRoles = $this->roleKeys((array) ($candidate['role_keys'] ?? []));
            break;
        }

        $matched = array_values(array_intersect($required, $actorRoles));
        sort($matched, SORT_STRING);
        if (count($matched) !== count($required)) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_ACTOR_UNAUTHORIZED');
        }

        return $matched;
    }

    /** @param array<int,mixed> $values @return list<string> */
    private function roleKeys(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $roleKey = trim((string) $value);
            if ($roleKey === '') {
                throw new DomainException('LEAVE_STAFFING_OVERRIDE_ROLE_PAYLOAD_INVALID');
            }
            if (mb_strlen($roleKey, 'UTF-8') > 80) {
                throw new DomainException('LEAVE_STAFFING_OVERRIDE_ROLE_PAYLOAD_INVALID');
            }
            $normalized[$roleKey] = true;
        }
        $keys = array_keys($normalized);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
