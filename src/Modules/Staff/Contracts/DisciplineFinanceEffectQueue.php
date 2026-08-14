<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Queue-only boundary for Staff-owned discipline facts sent to Finance.
 *
 * Implementations persist immutable intent records only. They never calculate
 * a salary amount, post a payroll entry, or write a Finance-owned table.
 */
interface DisciplineFinanceEffectQueue
{
    /**
     * @return array{status:string,decision_id:int,effect_id:?int,replayed:bool}
     */
    public function queueForIssuedDecision(
        int $decisionId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array;

    /**
     * @return array{status:string,appeal_id:int,effect_id:?int,reversed_effect_id:?int,replayed:bool}
     */
    public function queueReversalForResolvedAppeal(
        int $appealId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array;
}
