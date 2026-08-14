<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Minimal application boundary for opening a durable approval instance.
 *
 * Resource owners resolve and freeze their own evidence, then use this port
 * inside their transaction. The approval state machine remains the only
 * owner of instances, steps, assignees, decisions, and notifications.
 */
interface ApprovalWorkflowSubmissionGateway
{
    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function submit(array $command): array;
}
