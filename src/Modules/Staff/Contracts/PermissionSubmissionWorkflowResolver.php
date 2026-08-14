<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Cross-subsystem snapshot boundary for the initial approval workflow.
 *
 * Approval owns workflow definitions and instances. Permission requests only
 * persist the resolved immutable version identifier and redacted snapshot.
 */
interface PermissionSubmissionWorkflowResolver
{
    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $policy
     * @param array<string,mixed> $assignment
     * @return array{workflow_version_id:int,snapshot:array<string,mixed>}
     */
    public function resolveForSubmission(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        array $request,
        array $policy,
        array $assignment,
        DateTimeImmutable $submittedAt
    ): array;
}
