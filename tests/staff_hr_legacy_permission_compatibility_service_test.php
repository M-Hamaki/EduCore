<?php

declare(strict_types=1);

use EduCore\Modules\Staff\Application\Permission\LegacyPermissionCompatibilityService;
use EduCore\Modules\Staff\Contracts\LegacyPermissionAuditWriter;
use EduCore\Modules\Staff\Contracts\LegacyPermissionRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class MemoryLegacyPermissionRepository implements LegacyPermissionRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public int $writes = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public bool $inTransaction = false;
    private int $nextId = 1;

    public function transactional(callable $operation): mixed
    {
        $snapshot = [$this->rows, $this->writes, $this->nextId];
        $this->inTransaction = true;
        try {
            $result = $operation();
            $this->commits++;
            return $result;
        } catch (Throwable $exception) {
            [$this->rows, $this->writes, $this->nextId] = $snapshot;
            $this->rollbacks++;
            throw $exception;
        } finally {
            $this->inTransaction = false;
        }
    }

    public function activeStaffList(): array
    {
        return [['id' => 7, 'name' => 'عامل تجريبي']];
    }

    public function permissionById(int $permissionId): ?array
    {
        return $this->rows[$permissionId] ?? null;
    }

    public function permissions(array $filters = []): array
    {
        return array_values($this->rows);
    }

    public function permissionStats(): array
    {
        $typeStats = [];
        $statusStats = [];
        foreach ($this->rows as $row) {
            $type = (string) $row['permission_type'];
            $status = (string) $row['status'];
            $typeStats[$type] = ($typeStats[$type] ?? 0) + 1;
            $statusStats[$status] = ($statusStats[$status] ?? 0) + 1;
        }
        return ['type_stats' => $typeStats, 'status_stats' => $statusStats, 'total' => count($this->rows)];
    }

    public function lockPermission(int $permissionId): ?array
    {
        return $this->rows[$permissionId] ?? null;
    }

    public function insertPermission(array $permission): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = ['id' => $id] + $permission;
        $this->writes++;
        return $id;
    }

    public function updatePermission(int $permissionId, array $permission): bool
    {
        if (!isset($this->rows[$permissionId])) {
            return false;
        }
        $this->rows[$permissionId] = ['id' => $permissionId] + $permission;
        $this->writes++;
        return true;
    }

    public function deletePermission(int $permissionId): bool
    {
        if (!isset($this->rows[$permissionId])) {
            return false;
        }
        unset($this->rows[$permissionId]);
        $this->writes++;
        return true;
    }
}

final class MemoryLegacyPermissionAuditWriter implements LegacyPermissionAuditWriter
{
    /** @var list<array{type:string,id:int}> */
    public array $events = [];
    public ?string $failOn = null;

    public function __construct(private MemoryLegacyPermissionRepository $repository)
    {
    }

    public function permissionCreated(int $permissionId, array $after): void
    {
        $this->record('created', $permissionId);
    }

    public function permissionUpdated(int $permissionId, array $before, array $after): void
    {
        $this->record('updated', $permissionId);
    }

    public function permissionDeleted(int $permissionId, array $before): void
    {
        $this->record('deleted', $permissionId);
    }

    private function record(string $type, int $permissionId): void
    {
        if (!$this->repository->inTransaction) {
            throw new RuntimeException('AUDIT_OUTSIDE_TRANSACTION');
        }
        if ($this->failOn === $type) {
            throw new RuntimeException('AUDIT_FAILED');
        }
        $this->events[] = ['type' => $type, 'id' => $permissionId];
    }
}

$repository = new MemoryLegacyPermissionRepository();
$audit = new MemoryLegacyPermissionAuditWriter($repository);
$service = new LegacyPermissionCompatibilityService($repository, $audit);
$failures = [];

$check = static function (string $name, bool $passed) use (&$failures): void {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
};

$throws = static function (callable $operation): bool {
    try {
        $operation();
    } catch (Throwable) {
        return true;
    }

    return false;
};

$base = [
    'user_id' => 7,
    'permission_type' => 'late_arrival',
    'permission_date' => '2026-08-07',
    'time_from' => '08:00',
    'time_to' => '09:00',
    'status' => 'approved',
    'reason' => 'اختبار',
    'notes' => 'بيانات تجريبية',
];

$check('invalid_type_rejected_before_write', $throws(fn (): int => $service->savePermission(
    array_replace($base, ['permission_type' => 'other']),
    9
)) && $repository->writes === 0);
$check('invalid_time_range_rejected_before_write', $throws(fn (): int => $service->savePermission(
    array_replace($base, ['time_from' => '10:00', 'time_to' => '09:00']),
    9
)) && $repository->writes === 0);

$permissionId = $service->savePermission($base, 9);
$check('create_preserves_legacy_payload_and_audits_atomically', $permissionId === 1
    && ($repository->rows[$permissionId]['approved_by'] ?? null) === 9
    && ($audit->events[0]['type'] ?? null) === 'created'
    && $repository->commits === 1);

$service->savePermission(array_replace($base, ['status' => 'pending', 'reason' => 'تعديل تجريبي']), 9, $permissionId);
$check('edit_updates_same_legacy_record_and_audits', ($repository->rows[$permissionId]['status'] ?? null) === 'pending'
    && array_key_exists('approved_by', $repository->rows[$permissionId])
    && $repository->rows[$permissionId]['approved_by'] === null
    && ($audit->events[1]['type'] ?? null) === 'updated');

$beforeAuditFailure = $repository->rows[$permissionId];
$audit->failOn = 'updated';
$check('audit_failure_rolls_back_legacy_update', $throws(fn (): int => $service->savePermission(
    array_replace($base, ['reason' => 'لا يجب حفظه']),
    9,
    $permissionId
)) && $repository->rows[$permissionId] === $beforeAuditFailure && $repository->rollbacks === 1);
$audit->failOn = null;

$check('delete_is_audited_and_missing_delete_is_idempotent', $service->deletePermission($permissionId)
    && !isset($repository->rows[$permissionId])
    && ($audit->events[2]['type'] ?? null) === 'deleted'
    && !$service->deletePermission($permissionId));

$page = (string) file_get_contents(dirname(__DIR__) . '/admin/permissions.php');
$auditAdapter = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/OperationsAuditLegacyPermissionWriter.php'
);
$check('legacy_page_uses_staff_owned_compatibility_adapter', str_contains($page, 'StaffModuleFactory')
    && str_contains($page, 'legacyPermissionCompatibility()')
    && !str_contains($page, 'new StaffPermissionService('));
$check('legacy_page_keeps_public_permission_fields_and_actions', str_contains($page, 'id="permissionForm"')
    && str_contains($page, 'name="permission_form_mode"')
    && str_contains($page, 'name="delete_permission"'));
$check('legacy_permission_audit_writer_keeps_shared_undo_audit_contract', str_contains($auditAdapter, "'staff_permissions'")
    && str_contains($auditAdapter, 'recordInsert(')
    && str_contains($auditAdapter, 'recordUpdate(')
    && str_contains($auditAdapter, 'recordDelete('));

exit($failures === [] ? 0 : 1);
