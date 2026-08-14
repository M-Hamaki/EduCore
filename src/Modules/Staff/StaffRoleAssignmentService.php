<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use EduCore\Modules\Operations\Audit\AuditService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/Operations/Audit/AuditService.php';

/** Owns persistent multi-role membership for internal user accounts. */
final class StaffRoleAssignmentService
{
    public const EMPLOYEE_ROLE = 'employee';

    private ?bool $schemaReady = null;

    public function __construct(private PDO $db)
    {
    }

    public function isSchemaReady(): bool
    {
        if ($this->schemaReady !== null) {
            return $this->schemaReady;
        }

        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
            $stmt->execute(['user_role_assignments']);
            return $this->schemaReady = (bool)$stmt->fetchColumn();
        }

        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute(['user_role_assignments']);
        return $this->schemaReady = (bool)$stmt->fetchColumn();
    }

    public function assertSchemaReady(): void
    {
        if (!$this->isSchemaReady()) {
            throw new RuntimeException('Multi-role staff schema is not ready; run database migrations.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function rolesForUser(int $userId, bool $activeOnly = true): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (!$this->isSchemaReady()) {
            $stmt = $this->db->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $legacyRole = trim((string)($stmt->fetchColumn() ?: ''));
            return $legacyRole === '' ? [] : [[
                'role_key' => $legacyRole,
                'role_name' => $legacyRole,
                'portal_type' => null,
                'base_role_key' => null,
                'is_primary' => 1,
                'status' => 'active',
            ]];
        }

        $sql = "SELECT ura.role_key, sr.role_name, sr.portal_type, sr.base_role_key,
                       ura.is_primary, ura.status, ura.created_at, ura.updated_at
                FROM user_role_assignments ura
                JOIN staff_roles sr ON sr.role_key = ura.role_key
                WHERE ura.user_id = ?";
        $params = [$userId];
        if ($activeOnly) {
            $sql .= " AND ura.status = 'active' AND sr.status = 'active'";
        }
        $sql .= ' ORDER BY ura.is_primary DESC, sr.role_name, ura.role_key';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,string> */
    public function roleKeysForUser(int $userId, bool $activeOnly = true): array
    {
        return array_values(array_map(
            static fn(array $row): string => (string)$row['role_key'],
            $this->rolesForUser($userId, $activeOnly)
        ));
    }

    public function primaryRoleForUser(int $userId): ?string
    {
        foreach ($this->rolesForUser($userId, true) as $role) {
            if ((int)($role['is_primary'] ?? 0) === 1) {
                return (string)$role['role_key'];
            }
        }

        $roles = $this->roleKeysForUser($userId, true);
        return $roles[0] ?? null;
    }

    public function userHasRole(int $userId, string $roleKey): bool
    {
        $roleKey = trim($roleKey);
        return $roleKey !== '' && in_array($roleKey, $this->roleKeysForUser($userId, true), true);
    }

    /**
     * @param array<int,mixed> $roleKeys
     * @return array<int,array<string,mixed>>
     */
    public function replaceRoles(
        int $userId,
        array $roleKeys,
        ?string $primaryRoleKey,
        int $actorId,
        ?string $batchId = null
    ): array {
        $this->assertSchemaReady();
        $roleKeys = $this->normalizeRoleKeys($roleKeys);
        if ($userId <= 0 || $roleKeys === []) {
            throw new InvalidArgumentException('يجب تحديد دور واحد على الأقل للحساب.');
        }
        if (in_array(self::EMPLOYEE_ROLE, $roleKeys, true) && count($roleKeys) > 1) {
            throw new InvalidArgumentException('دور الموظف دون بوابة ولا يمكن جمعه مع دور آخر.');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $lockClause = $isSqlite ? '' : ' FOR UPDATE';
            $userStmt = $this->db->prepare('SELECT id, name, role FROM users WHERE id = ? LIMIT 1' . $lockClause);
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                throw new InvalidArgumentException('حساب العامل غير موجود.');
            }

            $validRoles = $this->validActiveRoles($roleKeys);
            if (count($validRoles) !== count($roleKeys)) {
                throw new InvalidArgumentException('تتضمن الأدوار المحددة دوراً غير موجود أو غير نشط.');
            }

            $beforeRows = $this->assignmentRows($userId, true);
            $beforeKeys = array_map(static fn(array $row): string => (string)$row['role_key'], $beforeRows);
            $existingPrimary = null;
            foreach ($beforeRows as $row) {
                if ((int)$row['is_primary'] === 1) {
                    $existingPrimary = (string)$row['role_key'];
                    break;
                }
            }

            $primaryRoleKey = trim((string)$primaryRoleKey);
            if ($primaryRoleKey === '' || !in_array($primaryRoleKey, $roleKeys, true)) {
                $primaryRoleKey = $existingPrimary !== null && in_array($existingPrimary, $roleKeys, true)
                    ? $existingPrimary
                    : $roleKeys[0];
            }

            sort($beforeKeys);
            $comparisonKeys = $roleKeys;
            sort($comparisonKeys);
            if ($beforeKeys === $comparisonKeys
                && $existingPrimary === $primaryRoleKey
                && (string)$user['role'] === $primaryRoleKey) {
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return $this->rolesForUser($userId, true);
            }

            $this->db->prepare('DELETE FROM user_role_assignments WHERE user_id = ?')->execute([$userId]);
            $insert = $this->db->prepare(
                "INSERT INTO user_role_assignments
                    (user_id, role_key, is_primary, status, assigned_by)
                 VALUES (?, ?, ?, 'active', ?)"
            );
            foreach ($roleKeys as $roleKey) {
                $insert->execute([
                    $userId,
                    $roleKey,
                    $roleKey === $primaryRoleKey ? 1 : 0,
                    $actorId > 0 ? $actorId : null,
                ]);
            }
            $this->db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$primaryRoleKey, $userId]);
            $afterRows = $this->assignmentRows($userId, false);

            $batchId = trim((string)$batchId);
            if ($batchId === '') {
                $batchId = \UndoManager::newBatchId();
            }
            (new AuditService($this->db))->recordReplacement(
                'staff_role_assignments',
                $userId,
                (string)$user['name'],
                $this->auditItems($beforeRows, true),
                $this->auditItems($afterRows, false),
                [
                    'actor_id' => $actorId,
                    'before_roles' => $beforeKeys,
                    'after_roles' => $comparisonKeys,
                    'primary_role' => $primaryRoleKey,
                ],
                $batchId
            );
            if ((string)$user['role'] !== $primaryRoleKey) {
                (new AuditService($this->db))->recordUpdate(
                    'user',
                    'users',
                    $userId,
                    (string)$user['name'],
                    ['role' => (string)$user['role']],
                    ['role' => $primaryRoleKey],
                    'مزامنة الدور الأساسي المتوافق مع الحساب متعدد الأدوار',
                    $batchId
                );
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $this->rolesForUser($userId, true);
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<int,mixed> $roleKeys @return array<int,string> */
    private function normalizeRoleKeys(array $roleKeys): array
    {
        $normalized = [];
        foreach ($roleKeys as $roleKey) {
            $roleKey = trim((string)$roleKey);
            if ($roleKey !== '' && preg_match('/^[a-z][a-z0-9_]{1,49}$/', $roleKey)) {
                $normalized[$roleKey] = $roleKey;
            }
        }
        return array_values($normalized);
    }

    /** @param array<int,string> $roleKeys @return array<int,string> */
    private function validActiveRoles(array $roleKeys): array
    {
        if ($roleKeys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($roleKeys), '?'));
        $stmt = $this->db->prepare(
            "SELECT role_key FROM staff_roles
             WHERE status = 'active' AND role_key IN ({$placeholders})"
        );
        $stmt->execute($roleKeys);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array<int,array<string,mixed>> */
    private function assignmentRows(int $userId, bool $lock): array
    {
        $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $lockClause = ($lock && !$isSqlite) ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare(
            'SELECT * FROM user_role_assignments WHERE user_id = ? ORDER BY id' . $lockClause
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function auditItems(array $rows, bool $deleting): array
    {
        return array_map(static function (array $row) use ($deleting): array {
            return [
                'table' => 'user_role_assignments',
                'record_id' => (int)$row['id'],
                'snapshot' => $row,
                'description' => $deleting ? 'استبدال أدوار حساب العامل' : 'إضافة دور إلى حساب العامل',
            ];
        }, $rows);
    }
}
