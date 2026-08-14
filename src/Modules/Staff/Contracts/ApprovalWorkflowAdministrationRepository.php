<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Persistence boundary for workflow/delegation administration. The application
 * service owns validation, publication policy, and mandatory audit evidence.
 */
interface ApprovalWorkflowAdministrationRepository
{
    public function transactional(callable $work): mixed;

    /** @return list<array<string,mixed>> */
    public function workflowVersions(): array;

    /** @return list<array<string,mixed>> */
    public function delegations(): array;

    /** @return list<array<string,mixed>> */
    public function activeUsers(): array;

    public function isActiveUser(int $userId): bool;

    /** @return list<string> */
    public function activeRoleKeys(): array;

    /** @return array<string,mixed>|null */
    public function workflowForUpdate(int $workflowId): ?array;

    /** @return array<string,mixed>|null */
    public function versionForUpdate(int $versionId): ?array;

    /** @return list<array<string,mixed>> */
    public function publishedVersionsForUpdate(int $workflowId): array;

    public function stageCountForVersion(int $versionId): int;

    public function nextVersionNumber(int $workflowId): int;

    /** @param array<string,mixed> $workflow */
    public function insertWorkflow(array $workflow): int;

    /** @param array<string,mixed> $version */
    public function insertVersion(array $version): int;

    /** @param array<string,mixed> $stage */
    public function insertStage(array $stage): int;

    public function setVersionValidTo(int $versionId, string $validTo): bool;

    public function publishVersion(int $versionId, int $actorId, string $publishedAt): bool;

    public function setWorkflowStatus(int $workflowId, string $status): bool;

    /** @return array<string,mixed>|null */
    public function delegationForUpdate(int $delegationId): ?array;

    /** @param array<string,mixed> $delegation */
    public function hasActiveDelegationScopeOverlap(array $delegation): bool;

    /** @param array<string,mixed> $delegation */
    public function insertDelegation(array $delegation): int;

    public function setDelegationStatus(int $delegationId, string $status): bool;
}
