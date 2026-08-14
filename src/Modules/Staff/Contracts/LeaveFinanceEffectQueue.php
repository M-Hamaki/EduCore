<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Narrow Staff-owned outbox boundary used by a final leave decision.
 *
 * It persists a durable fact only. A separate dispatcher may later use the
 * Finance contract after the enclosing Staff transaction has committed.
 */
interface LeaveFinanceEffectQueue
{
    /**
     * @return array{status:string,request_id:int,effect_ids:list<int>,replayed_effect_ids:list<int>}
     */
    public function queueForApprovedRequest(
        int $requestId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array;
}
