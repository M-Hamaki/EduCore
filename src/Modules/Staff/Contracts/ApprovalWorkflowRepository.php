<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Transactional persistence boundary for approval instances. The application
 * state machine owns transition rules; this adapter only locks and persists
 * durable instance, step, assignee, decision, and escalation evidence.
 */
interface ApprovalWorkflowRepository
{
    public function transactional(callable $work): mixed;

    /** @return array<string,mixed>|null */
    public function instanceByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $instance */
    public function insertInstance(array $instance): int;

    /** @param array<string,mixed> $step */
    public function insertStep(array $step): int;

    /** @param array<string,mixed> $assignee */
    public function insertAssignee(array $assignee): int;

    /** @return array<string,mixed>|null */
    public function stepWithInstanceForUpdate(int $stepId): ?array;

    /** @return list<array<string,mixed>> */
    public function stepsForInstanceForUpdate(int $instanceId): array;

    /** @return list<array<string,mixed>> */
    public function assigneesForStepForUpdate(int $stepId): array;

    /** @return list<array<string,mixed>> */
    public function decisionsForStepForUpdate(int $stepId): array;

    /** @return array<string,mixed>|null */
    public function decisionByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function decisionForActorForUpdate(int $stepId, int $actorUserId): ?array;

    /** @param array<string,mixed> $decision */
    public function insertDecision(array $decision): int;

    /** @param array<string,mixed> $changes */
    public function updateStep(int $stepId, int $expectedLockVersion, array $changes): bool;

    /** @param array<string,mixed> $changes */
    public function updateInstance(int $instanceId, int $expectedLockVersion, array $changes): bool;

    public function updateAssigneeStatus(int $assigneeId, string $status): bool;

    /** @param array<string,mixed> $event */
    public function insertEscalationEvent(array $event): int;
}
