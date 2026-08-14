<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Current permission boundary for sensitive discipline case actions.
 *
 * The caller never supplies a role, case-visibility grant, or delegator as
 * authority. Infrastructure resolves those facts at the moment of the write.
 */
interface DisciplineCaseAuthorization
{
    /** @param array<string,mixed>|null $case */
    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $case,
        DateTimeImmutable $atInstant
    ): void;
}
