<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Approval;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\ApprovalRoleAssigneeQuery;
use InvalidArgumentException;
use PDO;

/**
 * Role membership is resolved once at submission and copied into the workflow
 * snapshot. Later decisions revalidate their assigned evidence rather than
 * turning a current role membership into retroactive access.
 */
final class PdoApprovalRoleAssigneeQuery implements ApprovalRoleAssigneeQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function activeUsersForRoles(array $roleKeys, DateTimeImmutable $resolvedAt): array
    {
        $normalized = [];
        foreach ($roleKeys as $roleKey) {
            $roleKey = trim((string) $roleKey);
            if ($roleKey === '') {
                throw new InvalidArgumentException('Approval role keys must be non-empty strings.');
            }
            $normalized[$roleKey] = true;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('At least one approval role key is required.');
        }

        $keys = array_keys($normalized);
        sort($keys, SORT_STRING);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $statement = $this->db->prepare(
            "SELECT user_id, role_key
             FROM user_role_assignments
             WHERE status = 'active'
               AND role_key IN ({$placeholders})
             ORDER BY user_id ASC, role_key ASC"
        );
        $statement->execute($keys);

        /** @var array<int,list<string>> $rolesByUser */
        $rolesByUser = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rolesByUser[(int) $row['user_id']][] = (string) $row['role_key'];
        }

        $users = [];
        foreach ($rolesByUser as $userId => $matchedRoles) {
            $users[] = [
                'user_id' => $userId,
                'role_keys' => array_values(array_unique($matchedRoles)),
            ];
        }

        return $users;
    }
}
