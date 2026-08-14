<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Resolves the effective Ertaq classification, confidentiality and frozen SLA
 * evidence at submission time. A request never treats browser-supplied
 * priority, confidentiality, route, or deadline as authority.
 */
interface ErtaqTicketPolicyResolver
{
    /**
     * @param array<string,mixed> $requested
     * @return array<string,mixed>
     */
    public function resolveForCreate(
        int $requesterUserId,
        array $requested,
        DateTimeImmutable $atInstant
    ): array;

    /**
     * @param array<string,mixed> $ticket
     * @param array<string,mixed> $requested
     * @return array<string,mixed>
     */
    public function resolveForClassification(
        int $actorId,
        array $ticket,
        array $requested,
        DateTimeImmutable $atInstant
    ): array;
}
