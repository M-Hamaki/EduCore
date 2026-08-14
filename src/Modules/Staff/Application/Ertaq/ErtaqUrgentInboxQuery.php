<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Ertaq;

use EduCore\Modules\Staff\Contracts\ErtaqUrgentInboxReadRepository;

final class ErtaqUrgentInboxQuery
{
    public function __construct(private ErtaqUrgentInboxReadRepository $repository)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forActor(int $actorId): array
    {
        return $actorId > 0 ? $this->repository->forActor($actorId) : [];
    }
}
