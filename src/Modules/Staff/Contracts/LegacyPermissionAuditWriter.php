<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Keeps the undo-aware audit dependency of the legacy adapter explicit.
 */
interface LegacyPermissionAuditWriter
{
    /** @param array<string,mixed> $after */
    public function permissionCreated(int $permissionId, array $after): void;

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function permissionUpdated(int $permissionId, array $before, array $after): void;

    /** @param array<string,mixed> $before */
    public function permissionDeleted(int $permissionId, array $before): void;
}
