<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Revalidates a live account and, where required, live staff service. */
interface ApprovalActorEligibilityQuery
{
    /**
     * @return array{allowed:bool,reason:string,can_manage_approvals:bool}
     */
    public function currentEligibility(
        int $userId,
        string $relationshipKind,
        DateTimeImmutable $atInstant
    ): array;
}
