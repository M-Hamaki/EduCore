<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

interface ErtaqUrgentInboxReadRepository
{
    /** @return list<array<string,mixed>> */
    public function forActor(int $actorId): array;
}
