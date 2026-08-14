<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Contracts\LegacyPermissionAuditWriter;

/**
 * Infrastructure adapter that preserves the undo-aware audit behavior of the
 * existing staff_permissions table while the route remains a compatibility UI.
 */
final class OperationsAuditLegacyPermissionWriter implements LegacyPermissionAuditWriter
{
    public function __construct(private AuditService $audit)
    {
    }

    public function permissionCreated(int $permissionId, array $after): void
    {
        $this->audit->recordInsert(
            'staff_permission',
            'staff_permissions',
            $permissionId,
            $this->permissionName($permissionId),
            $after,
            'إضافة إذن موظف'
        );
    }

    public function permissionUpdated(int $permissionId, array $before, array $after): void
    {
        $this->audit->recordUpdate(
            'staff_permission',
            'staff_permissions',
            $permissionId,
            $this->permissionName($permissionId),
            $before,
            $after,
            'تعديل إذن موظف'
        );
    }

    public function permissionDeleted(int $permissionId, array $before): void
    {
        $this->audit->recordDelete(
            'staff_permission',
            'staff_permissions',
            $permissionId,
            $this->permissionName($permissionId),
            $before,
            'حذف إذن موظف'
        );
    }

    private function permissionName(int $permissionId): string
    {
        return 'إذن موظف #' . $permissionId;
    }
}
