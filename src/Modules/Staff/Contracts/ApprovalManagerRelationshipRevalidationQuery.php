<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

interface ApprovalManagerRelationshipRevalidationQuery
{
    /** @param array<string,mixed> $assignmentSnapshot */
    public function isStillResponsible(
        int $actorId,
        string $relationshipKind,
        array $assignmentSnapshot,
        DateTimeImmutable $atInstant
    ): bool;
}
