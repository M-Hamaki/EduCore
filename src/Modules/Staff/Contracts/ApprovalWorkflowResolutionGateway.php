<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Resolves a published Staff approval workflow into immutable submission
 * evidence without creating an approval instance.
 *
 * Resource owners deliberately pass only their minimum non-sensitive context;
 * the Approval owner remains responsible for manager/delegation resolution.
 */
interface ApprovalWorkflowResolutionGateway
{
    /**
     * @param array<string,mixed> $context
     * @return array{workflow_version_id:int,snapshot:array<string,mixed>}
     */
    public function resolveForResource(
        string $resourceType,
        int $staffUserId,
        array $context,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $resolvedAt
    ): array;
}
