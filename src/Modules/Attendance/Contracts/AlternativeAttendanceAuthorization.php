<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/**
 * Authorization boundary for non-biometric attendance evidence.
 *
 * `allowedScope` is the immutable scope configured on the entry method. Its
 * interpretation belongs to the Staff-owned access adapter; Attendance never
 * infers authority from a submitted role or a current page session.
 */
interface AlternativeAttendanceAuthorization
{
    public function assertCanRecord(
        int $actorId,
        int $staffUserId,
        string $allowedScope,
        DateTimeImmutable $atInstant
    ): void;

    public function assertCanReview(
        int $actorId,
        int $staffUserId,
        string $allowedScope,
        DateTimeImmutable $atInstant
    ): void;
}
