<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Revalidates the current worker self-service entitlement for a leave action.
 *
 * The application service separately enforces self ownership, so a caller
 * cannot manufacture a target worker from a browser field.
 */
interface LeaveRequestAuthorization
{
    public function assertCanAct(
        int $actorId,
        int $staffUserId,
        string $action,
        DateTimeImmutable $atInstant
    ): void;
}
