<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

interface ScheduleChangeAuthorization
{
    /** @param array<string,mixed> $payload */
    public function canSubmit(int $actorId, int $staffId, array $payload): bool;

    /** @param array<string,mixed> $request */
    public function canLinkWorkflow(int $actorId, array $request, int $workflowInstanceId): bool;

    /** @param array<string,mixed> $request */
    public function canApprove(int $actorId, array $request): bool;
}
