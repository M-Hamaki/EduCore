<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Resolves currently active role memberships that are captured at submission.
 * Role membership itself is not an approval grant outside the resulting
 * workflow snapshot and assigned-inbox authorization.
 */
interface ApprovalRoleAssigneeQuery
{
    /**
     * @param list<string> $roleKeys
     * @return list<array{user_id:int,role_keys:list<string>}>
     */
    public function activeUsersForRoles(array $roleKeys, DateTimeImmutable $resolvedAt): array;
}
