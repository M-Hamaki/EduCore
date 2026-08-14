<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Rechecks current employment and relationship scope on every protected request. */
interface StaffAccessEligibilityQuery
{
    /**
     * @return array{
     *     allowed:bool,
     *     staff_status:string,
     *     relationship_version:?int,
     *     reason:string
     * }
     */
    public function assertCurrentAccess(
        int $userId,
        string $capability,
        string $resourceRef,
        DateTimeImmutable $atInstant
    ): array;
}
