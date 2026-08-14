<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Locked cross-absence query for permission submission.
 *
 * It may return Staff-owned permission, leave, or mission conflicts, but it
 * must not reveal private reasons or attachments to the caller.
 */
interface PermissionRequestOverlapQuery
{
    /**
     * @return list<array{resource_type:string,resource_id:int,from_at:string,to_at:string,status:string}>
     */
    public function conflictsForStaffForUpdate(
        int $staffUserId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        ?int $excludingRequestId = null
    ): array;
}
