<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Approval-owned delegation resolution includes request type and scope. It is
 * deliberately separate from manager-hierarchy lookup so a resource-specific
 * delegation cannot be discarded before the workflow knows its resource.
 */
interface ApprovalDelegationQuery
{
    /**
     * @param list<int> $groupIds
     * @return array{
     *     delegation:?array{
     *         delegation_id:int,
     *         acting_for_user_id:int,
     *         delegate_user_id:int,
     *         valid_from:string,
     *         valid_to:?string
     *     },
     *     conflicts:list<array<string,mixed>>
     * }
     */
    public function resolve(
        int $delegatorUserId,
        int $staffUserId,
        ?int $orgUnitId,
        array $groupIds,
        string $resourceType,
        ?int $requestTypeId,
        DateTimeImmutable $atDate
    ): array;
}
