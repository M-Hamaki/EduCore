<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use PDO;

require_once dirname(__DIR__, 3) . '/classes/AdminRolePageCatalog.php';

/** Resolves the behavioural family inherited by cloned administrative roles. */
final class StaffRoleCapabilityResolver
{
    private const ACADEMIC_SCOPE_FAMILIES = [
        \AdminRolePageCatalog::SPECIALIST,
        \AdminRolePageCatalog::DOCTOR,
        \AdminRolePageCatalog::LIBRARIAN,
    ];

    public function __construct(private PDO $db)
    {
    }

    public function family(string $role): string
    {
        $role = trim($role);
        if ($role === '' || \AdminRolePageCatalog::isCustomizableRole($role)) {
            return $role;
        }

        $stmt = $this->db->prepare(
            "SELECT base_role_key
             FROM staff_roles
             WHERE role_key = ? AND portal_type = 'admin_like' AND status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$role]);
        $baseRole = trim((string)($stmt->fetchColumn() ?: ''));

        return \AdminRolePageCatalog::isCustomizableRole($baseRole) ? $baseRole : $role;
    }

    public function requiresAcademicScope(string $role): bool
    {
        return in_array($this->family($role), self::ACADEMIC_SCOPE_FAMILIES, true);
    }

    public function isSpecialistFamily(string $role): bool
    {
        return $this->family($role) === \AdminRolePageCatalog::SPECIALIST;
    }
}
