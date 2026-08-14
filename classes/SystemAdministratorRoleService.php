<?php

declare(strict_types=1);

/**
 * Protects transitions into and out of the two system-administrator roles.
 *
 * The caller owns the surrounding transaction and the audited write. This
 * service locks the actor and active super-admin rows before authorizing it.
 */
final class SystemAdministratorRoleService
{
    private const SYSTEM_ROLES = ['admin', 'super_admin'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function isSystemRole(?string $role): bool
    {
        return in_array((string) $role, self::SYSTEM_ROLES, true);
    }

    public function assertActorCanManage(int $actorId, string $actorActiveRole): void
    {
        if ($actorActiveRole !== 'super_admin') {
            throw new RuntimeException('هذه العملية تتطلب تفعيل دور مدير النظام الأعلى.');
        }
        $stmt = $this->db->prepare("SELECT u.status,
                EXISTS(
                    SELECT 1 FROM user_role_assignments ura
                    WHERE ura.user_id = u.id AND ura.role_key = 'super_admin' AND ura.status = 'active'
                ) AS has_super_admin_role
            FROM users u WHERE u.id = ? LIMIT 1");
        $stmt->execute([$actorId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$actor || (int)$actor['has_super_admin_role'] !== 1 || $actor['status'] !== 'active') {
            throw new RuntimeException('هذه العملية متاحة لمدير النظام الأعلى فقط.');
        }
    }

    public function assertRoleChangeAllowed(
        int $actorId,
        int $targetId,
        ?string $currentRole,
        string $newRole,
        string $actorActiveRole
    ): void {
        if (!self::isSystemRole($currentRole) && !self::isSystemRole($newRole)) {
            return;
        }

        $this->assertActiveSuperAdminActor($actorId, $actorActiveRole);
        $this->assertDifferentAccount($actorId, $targetId);

        if ($currentRole === 'super_admin' && $newRole !== 'super_admin') {
            $this->assertAnotherActiveSuperAdminExists($targetId);
        }
    }

    /** @param array<int,string> $currentRoles @param array<int,string> $newRoles */
    public function assertRoleSetChangeAllowed(
        int $actorId,
        int $targetId,
        array $currentRoles,
        array $newRoles,
        string $actorActiveRole
    ): void {
        if (array_intersect(self::SYSTEM_ROLES, array_merge($currentRoles, $newRoles)) === []) {
            return;
        }

        $this->assertActiveSuperAdminActor($actorId, $actorActiveRole);
        if ($actorId === $targetId) {
            if (!in_array('super_admin', $currentRoles, true)
                || !in_array('super_admin', $newRoles, true)) {
                throw new RuntimeException('لا يمكنك إزالة دور مدير النظام الأعلى من حسابك الحالي.');
            }
            return;
        }

        $this->assertDifferentAccount($actorId, $targetId);
        if (in_array('super_admin', $currentRoles, true) && !in_array('super_admin', $newRoles, true)) {
            $this->assertAnotherActiveSuperAdminExists($targetId);
        }
    }

    public function assertStatusChangeAllowed(
        int $actorId,
        int $targetId,
        ?string $currentRole,
        string $currentStatus,
        string $newStatus,
        string $actorActiveRole
    ): void {
        $roleStmt = $this->db->prepare(
            "SELECT role_key FROM user_role_assignments
             WHERE user_id = ? AND status = 'active' AND role_key IN ('admin', 'super_admin')"
        );
        $roleStmt->execute([$targetId]);
        $systemRoles = array_map('strval', $roleStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($systemRoles === []) {
            return;
        }

        $this->assertActiveSuperAdminActor($actorId, $actorActiveRole);
        $this->assertDifferentAccount($actorId, $targetId);

        if (in_array('super_admin', $systemRoles, true) && $currentStatus === 'active' && $newStatus !== 'active') {
            $this->assertAnotherActiveSuperAdminExists($targetId);
        }
    }

    private function assertActiveSuperAdminActor(int $actorId, string $actorActiveRole): void
    {
        if ($actorActiveRole !== 'super_admin') {
            throw new RuntimeException('هذه العملية تتطلب تفعيل دور مدير النظام الأعلى.');
        }
        $stmt = $this->db->prepare("SELECT u.status,
                EXISTS(
                    SELECT 1 FROM user_role_assignments ura
                    WHERE ura.user_id = u.id AND ura.role_key = 'super_admin' AND ura.status = 'active'
                ) AS has_super_admin_role
            FROM users u WHERE u.id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$actorId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$actor || (int)$actor['has_super_admin_role'] !== 1 || $actor['status'] !== 'active') {
            throw new RuntimeException('هذه العملية متاحة لمدير النظام الأعلى فقط.');
        }
    }

    private function assertDifferentAccount(int $actorId, int $targetId): void
    {
        if ($actorId === $targetId) {
            throw new RuntimeException('لا يمكنك تغيير دور أو حالة حسابك الإداري الحالي.');
        }
    }

    private function assertAnotherActiveSuperAdminExists(int $targetId): void
    {
        $stmt = $this->db->query(
            "SELECT u.id FROM users u
             JOIN user_role_assignments ura ON ura.user_id = u.id
                AND ura.role_key = 'super_admin' AND ura.status = 'active'
             WHERE u.status = 'active' FOR UPDATE"
        );
        $activeIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $remaining = array_values(array_filter($activeIds, static fn(int $id): bool => $id !== $targetId));
        if ($remaining === []) {
            throw new RuntimeException('لا يمكن تخفيض أو تعطيل آخر مدير نظام أعلى نشط.');
        }
    }
}
