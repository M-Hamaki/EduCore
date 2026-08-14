<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Current-session and relationship revalidation before an approval write. */
interface ApprovalTransitionAuthorization
{
    /** @param array<string,mixed> $instance @param array<string,mixed> $step @param array<string,mixed>|null $assignee */
    public function assertCanAct(
        int $actorId,
        string $operation,
        array $instance,
        array $step,
        ?array $assignee,
        DateTimeImmutable $atInstant
    ): void;

    /** @param array<string,mixed> $instance @param array<string,mixed> $step */
    public function assertCanReceiveAssignment(
        int $userId,
        array $instance,
        array $step,
        DateTimeImmutable $atInstant
    ): void;
}
