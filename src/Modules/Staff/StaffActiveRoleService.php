<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use InvalidArgumentException;
use PDO;

require_once __DIR__ . '/StaffRoleAssignmentService.php';

/** Builds and validates the single active role used by an authenticated session. */
final class StaffActiveRoleService
{
    private StaffRoleAssignmentService $assignments;

    public function __construct(private PDO $db)
    {
        $this->assignments = new StaffRoleAssignmentService($db);
    }

    /**
     * Starts a fresh authenticated staff session.
     *
     * @return array{requires_selection:bool,active_role:?string,roles:array<int,array<string,mixed>>}
     */
    public function startSession(array &$session, int $userId): array
    {
        $this->assertAccountActive($userId);
        $roles = $this->assignments->rolesForUser($userId, true);
        $roleKeys = array_values(array_map(
            static fn(array $row): string => (string)$row['role_key'],
            $roles
        ));
        if ($roleKeys === []) {
            throw new InvalidArgumentException('لا توجد أدوار نشطة مخصصة لهذا الحساب.');
        }

        $primaryRole = $this->primaryRole($roles) ?? $roleKeys[0];
        $session['primary_role'] = $primaryRole;
        $session['available_roles'] = $roleKeys;
        unset($session['active_mode']);

        if (count($roleKeys) === 1) {
            $activeRole = $this->activateRole($session, $userId, $roleKeys[0]);
            return ['requires_selection' => false, 'active_role' => $activeRole, 'roles' => $roles];
        }

        // Keep the legacy scalar session key deterministic, but deny protected
        // pages until an explicit role is chosen.
        $session['role'] = $primaryRole;
        unset($session['active_role']);
        $session['role_selection_required'] = true;
        return ['requires_selection' => true, 'active_role' => null, 'roles' => $roles];
    }

    public function activateRole(array &$session, int $userId, string $roleKey): string
    {
        $this->assertAccountActive($userId);
        $roleKey = trim($roleKey);
        if (!$this->assignments->userHasRole($userId, $roleKey)) {
            throw new InvalidArgumentException('الدور المحدد غير مخصص لهذا الحساب أو لم يعد نشطاً.');
        }
        $roles = $this->assignments->rolesForUser($userId, true);
        $session['available_roles'] = array_values(array_map(
            static fn(array $row): string => (string)$row['role_key'],
            $roles
        ));
        $session['primary_role'] = $this->primaryRole($roles) ?? $roleKey;
        $session['active_role'] = $roleKey;
        $session['role'] = $roleKey; // compatibility mirror for legacy callers
        unset($session['role_selection_required'], $session['active_mode']);
        return $roleKey;
    }

    public function refreshActiveRole(array &$session, int $userId): ?string
    {
        $activeRole = trim((string)($session['active_role'] ?? $session['role'] ?? ''));
        if ($activeRole === '' || !$this->assignments->userHasRole($userId, $activeRole)) {
            $result = $this->startSession($session, $userId);
            return $result['active_role'];
        }

        return $this->activateRole($session, $userId, $activeRole);
    }

    /** @param array<int,array<string,mixed>> $roles */
    private function primaryRole(array $roles): ?string
    {
        foreach ($roles as $role) {
            if ((int)($role['is_primary'] ?? 0) === 1) {
                return (string)$role['role_key'];
            }
        }
        return null;
    }

    private function assertAccountActive(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        if ((string)($stmt->fetchColumn() ?: '') !== 'active') {
            throw new InvalidArgumentException('الحساب غير نشط ولا يمكنه استخدام بوابات النظام.');
        }
    }
}
