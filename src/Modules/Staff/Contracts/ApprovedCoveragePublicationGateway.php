<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Staff-owned publication boundary for approved/reversed permission coverage.
 * Cancellation workflow owners use the reversal method only after their own
 * final decision is durable; a self-service cancellation must not uncover
 * attendance directly.
 */
interface ApprovedCoveragePublicationGateway
{
    /** @param array<string,mixed> $request @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function publishApproved(
        array $request,
        array $snapshot,
        int $workflowInstanceId,
        int $actorId,
        DateTimeImmutable $occurredAt
    ): array;

    /** @param array<string,mixed> $request @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function publishReversed(
        array $request,
        array $snapshot,
        int $workflowInstanceId,
        int $actorId,
        DateTimeImmutable $occurredAt
    ): array;
}
