<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/**
 * Authorization boundary for correction requests and decisions.
 *
 * The Staff-owned adapter validates the current relationship/capability on
 * every action. Attendance receives only a stable request kind and optional
 * workflow evidence reference, never a role supplied by the browser.
 */
interface AttendanceAdjustmentAuthorization
{
    public function assertCanAct(
        int $actorId,
        int $staffUserId,
        string $requesterKind,
        string $action,
        ?int $workflowInstanceId,
        DateTimeImmutable $atInstant
    ): void;
}
