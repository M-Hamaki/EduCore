<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use AdminRolePageCatalog;
use EduCore\Modules\Operations\Audit\AuditService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use UndoManager;

require_once dirname(__DIR__, 3) . '/classes/AdminRolePageCatalog.php';
require_once dirname(__DIR__, 3) . '/classes/UndoManager.php';
require_once __DIR__ . '/../Operations/Audit/AuditService.php';

/**
 * Owns bulk page permission operations across customizable admin-like staff roles.
 */
final class StaffRolePageBulkCommandService
{
    private const PROHIBITED_ROLES = [
        'admin', 'super_admin', 'teacher', 'supervisor', 'student', 'employee', 'external_teacher'
    ];

    public function __construct(private PDO $db)
    {
    }

    /**
     * Preview or execute bulk role pages mutation.
     *
     * @param string $operation 'copy_from'|'add'|'remove'|'replace'
     * @param array<int,string> $targetRoleKeys Role keys to modify
     * @param string $sourceRoleKey Source role key if operation is 'copy_from'
     * @param array<int,string> $pages Pages array if operation is 'add', 'remove', or 'replace'
     * @param string $activeRole Current active session role (must be 'super_admin')
     * @param bool $dryRun If true, returns preview changes without saving to DB
     * @return array{preview:array<string,array{role_name:string,added:list<string>,removed:list<string>,final:list<string>}>,updated:int,message:string}
     */
    public function execute(
        string $operation,
        array $targetRoleKeys,
        string $sourceRoleKey,
        array $pages,
        string $activeRole,
        bool $dryRun = false
    ): array {
        if ($activeRole !== 'super_admin') {
            throw new InvalidArgumentException('التحكم الجماعي في صلاحيات الأدوار متاح فقط لمدير النظام الأعلى.');
        }
        if (!$this->hasTable('staff_role_pages')) {
            throw new RuntimeException('مخطط صفحات الأدوار غير مهيأ؛ شغّل ترحيلات قاعدة البيانات أولًا.');
        }

        $validOps = ['copy_from', 'add', 'remove', 'replace'];
        if (!in_array($operation, $validOps, true)) {
            throw new InvalidArgumentException('عملية الصلاحيات الجماعية غير معروفة.');
        }

        $targetRoleKeys = array_values(array_unique(array_filter(array_map('strval', $targetRoleKeys))));
        if ($targetRoleKeys === []) {
            throw new InvalidArgumentException('يجب تحديد دور هدف واحد على الأقل.');
        }

        foreach ($targetRoleKeys as $roleKey) {
            if (in_array($roleKey, self::PROHIBITED_ROLES, true)) {
                throw new InvalidArgumentException("الدور «{$roleKey}» من الأدوار غير القابلة للتخصيص الجماعي.");
            }
        }

        // Fetch existing roles metadata from staff_roles
        $placeholders = implode(',', array_fill(0, count($targetRoleKeys), '?'));
        $stmt = $this->db->prepare("SELECT id, role_key, role_name, base_role_key FROM staff_roles WHERE status = 'active' AND role_key IN ({$placeholders})");
        $stmt->execute($targetRoleKeys);
        $roleRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($roleRows) !== count($targetRoleKeys)) {
            throw new InvalidArgumentException('تتضمن القائمة أعداء أدوار غير موجودة أو غير نشطة.');
        }

        // Resolve source pages if operation is copy_from
        $sourcePages = [];
        if ($operation === 'copy_from') {
            if (trim($sourceRoleKey) === '' || in_array($sourceRoleKey, self::PROHIBITED_ROLES, true)) {
                throw new InvalidArgumentException('دور المصدر المحدد غير صالح لاستنساخ الصلاحيات.');
            }
            $sourceRoleStmt = $this->db->prepare(
                "SELECT role_key, base_role_key
                 FROM staff_roles
                 WHERE role_key = ? AND status = 'active' LIMIT 1"
            );
            $sourceRoleStmt->execute([$sourceRoleKey]);
            $sourceRole = $sourceRoleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sourceRole) {
                throw new InvalidArgumentException('دور المصدر غير موجود أو غير نشط.');
            }
            $sourceFamily = trim((string)($sourceRole['base_role_key'] ?? ''));
            if ($sourceFamily === '') {
                $sourceFamily = $sourceRoleKey;
            }
            if (!AdminRolePageCatalog::isCustomizableRole($sourceFamily)) {
                throw new InvalidArgumentException('دور المصدر غير موجود أو لا يدعم تخصيص الصفحات.');
            }
            $sourceStmt = $this->db->prepare("SELECT page_name FROM staff_role_pages WHERE role_key = ?");
            $sourceStmt->execute([$sourceRoleKey]);
            $sourcePages = array_map('strval', $sourceStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        $preview = [];
        $updates = [];

        foreach ($roleRows as $roleRow) {
            $roleKey = (string)$roleRow['role_key'];
            $roleName = (string)$roleRow['role_name'];
            $baseRoleKey = (string)($roleRow['base_role_key'] ?? $roleKey);
            $roleFamily = AdminRolePageCatalog::isCustomizableRole($baseRoleKey) ? $baseRoleKey : $roleKey;

            $allowedPages = AdminRolePageCatalog::customizablePages($roleFamily);
            $mandatoryPages = AdminRolePageCatalog::mandatoryPages($roleFamily);

            // Fetch current role pages
            $currentPages = [];
            $currentStmt = $this->db->prepare("SELECT page_name FROM staff_role_pages WHERE role_key = ?");
            $currentStmt->execute([$roleKey]);
            $currentPages = array_values(array_unique(array_map('strval', $currentStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));

            $targetPages = [];
            if ($operation === 'copy_from') {
                $targetPages = array_intersect($sourcePages, $allowedPages);
            } elseif ($operation === 'add') {
                $pagesToAdd = array_intersect($pages, $allowedPages);
                $targetPages = array_merge($currentPages, $pagesToAdd);
            } elseif ($operation === 'remove') {
                $targetPages = array_diff($currentPages, $pages);
            } elseif ($operation === 'replace') {
                $targetPages = array_intersect($pages, $allowedPages);
            }

            // Always ensure mandatory pages are present
            $targetPages = array_values(array_unique(array_merge($targetPages, $mandatoryPages)));
            // Expand with derived dependencies
            $finalPages = AdminRolePageCatalog::expandWithDependencies($targetPages);

            sort($currentPages);
            sort($finalPages);

            $added = array_values(array_diff($finalPages, $currentPages));
            $removed = array_values(array_diff($currentPages, $finalPages));

            $preview[$roleKey] = [
                'role_name' => $roleName,
                'added' => $added,
                'removed' => $removed,
                'final' => $finalPages
            ];

            $updates[$roleKey] = [
                'role_id' => (int)($roleRow['id'] ?? 0),
                'role_name' => $roleName,
                'current' => $currentPages,
                'final' => $finalPages
            ];
        }

        if ($dryRun) {
            return [
                'preview' => $preview,
                'updated' => 0,
                'message' => 'معاينة التغييرات جاهزة.'
            ];
        }

        $batchId = UndoManager::newBatchId();
        $updatedCount = 0;

        $this->db->beginTransaction();
        try {
            $auditService = new AuditService($this->db);

            foreach ($updates as $roleKey => $item) {
                if ($item['current'] === $item['final']) {
                    continue;
                }

                $this->db->prepare("DELETE FROM staff_role_pages WHERE role_key = ?")->execute([$roleKey]);
                $ins = $this->db->prepare("INSERT INTO staff_role_pages (role_key, page_name) VALUES (?, ?)");
                foreach ($item['final'] as $pageName) {
                    $ins->execute([$roleKey, $pageName]);
                }

                $roleId = (int)($item['role_id'] ?? 0);
                $auditService->recordUpdate(
                    'staff_roles',
                    'staff_roles',
                    $roleId,
                    $item['role_name'],
                    ['pages' => implode(',', $item['current'])],
                    ['pages' => implode(',', $item['final'])],
                    "تعديل جماعي لصفحات الدور «{$item['role_name']}»",
                    $batchId
                );

                $updatedCount++;
            }

            $this->db->commit();

            return [
                'preview' => $preview,
                'updated' => $updatedCount,
                'message' => "تم تحديث صفحات {$updatedCount} دور بنجاح."
            ];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function hasTable(string $tableName): bool
    {
        $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($isSqlite) {
            $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
            $stmt->execute([$tableName]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $this->db->prepare(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    }
}
