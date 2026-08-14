<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Staff-owned cross-module query for effective policy-group membership overlap. */
interface StaffGroupOverlapQuery
{
    public function groupsShareActiveMember(
        int $leftGroupId,
        int $rightGroupId,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): bool;
}
