<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Confirms that the exact delegated authority frozen at submission still lives. */
interface ApprovalDelegationRevalidationQuery
{
    public function isStillActive(
        int $delegationId,
        int $delegatorUserId,
        int $delegateUserId,
        DateTimeImmutable $atInstant
    ): bool;
}
