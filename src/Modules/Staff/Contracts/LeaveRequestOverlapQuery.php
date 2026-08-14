<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Locked, privacy-minimal absence overlap query for leave submission.
 *
 * It may inspect Staff-owned leave, permission, and mission records, but
 * never returns another worker's reason, document reference, or snapshot.
 */
interface LeaveRequestOverlapQuery
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
