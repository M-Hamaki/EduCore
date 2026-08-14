<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Staff-owned read boundary for published workflow definitions. The result is
 * intentionally raw-but-structured so application code owns validation and
 * snapshot construction instead of coupling to approval tables.
 */
interface ApprovalWorkflowDefinitionQuery
{
    /**
     * @return list<array{
     *     workflow_id:int,
     *     workflow_code:string,
     *     workflow_name:string,
     *     resource_type:string,
     *     workflow_version_id:int,
     *     version_no:int,
     *     valid_from:string,
     *     valid_to:?string,
     *     cancellation_rule:string,
     *     escalation_rule:mixed,
     *     stages:list<array<string,mixed>>
     * }>
     */
    public function findPublishedForResource(string $resourceType, DateTimeImmutable $effectiveAt): array;
}
