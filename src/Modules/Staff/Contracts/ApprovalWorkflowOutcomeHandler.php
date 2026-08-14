<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Resource owner applies a final approval outcome inside the same transaction.
 * It must not dispatch external effects directly; later outbox owners do that
 * after durable business and audit records exist.
 */
interface ApprovalWorkflowOutcomeHandler
{
    /** @param array<string,mixed> $instance */
    public function apply(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void;
}
