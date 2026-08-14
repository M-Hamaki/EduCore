<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Read boundary for the current, actionable approval work assigned to one
 * account. It deliberately returns only the frozen evidence required by the
 * manager inbox; resource-specific details remain owned by their resource.
 */
interface AssignedApprovalInboxReadRepository
{
    public function countActiveForAssignee(int $assigneeUserId, ?string $resourceType): int;

    /** @return list<array<string,mixed>> */
    public function activeForAssignee(
        int $assigneeUserId,
        ?string $resourceType,
        int $limit,
        int $offset
    ): array;
}
