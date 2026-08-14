<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Compatibility persistence boundary for the legacy staff_permissions route.
 *
 * The legacy table remains a supported adapter during the gradual migration;
 * this contract keeps its SQL and transaction details outside the admin page.
 */
interface LegacyPermissionRepository
{
    public function transactional(callable $operation): mixed;

    /** @return list<array<string,mixed>> */
    public function activeStaffList(): array;

    /** @return array<string,mixed>|null */
    public function permissionById(int $permissionId): ?array;

    /** @return list<array<string,mixed>> */
    public function permissions(array $filters = []): array;

    /** @return array{type_stats:array<string,int>,status_stats:array<string,int>,total:int} */
    public function permissionStats(): array;

    /** @return array<string,mixed>|null */
    public function lockPermission(int $permissionId): ?array;

    /** @param array<string,mixed> $permission */
    public function insertPermission(array $permission): int;

    /** @param array<string,mixed> $permission */
    public function updatePermission(int $permissionId, array $permission): bool;

    public function deletePermission(int $permissionId): bool;
}
