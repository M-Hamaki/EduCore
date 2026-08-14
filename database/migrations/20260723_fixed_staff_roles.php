<?php

declare(strict_types=1);

/**
 * Register the real fixed roles as membership targets, then backfill every
 * existing non-null legacy role. This migration intentionally runs before the
 * multi-role schema on fresh installations and repairs installations where
 * that additive migration was already applied.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };

    if (!$tableExists('staff_roles')) {
        throw new RuntimeException('Staff role schema is not ready.');
    }

    $fixedRoles = [
        ['admin', 'مدير النظام', 'admin_like'],
        ['super_admin', 'مدير النظام الأعلى', 'admin_like'],
        ['teacher', 'معلم', 'teacher'],
        ['student', 'طالب', 'student'],
        ['external_teacher', 'معلم خارجي', 'external_teacher'],
        ['supervisor', 'مشرف مستقل', 'supervisor'],
        ['employee', 'موظف', 'none'],
        ['specialist', 'أخصائي', 'admin_like'],
        ['doctor', 'طبيب', 'admin_like'],
        ['librarian', 'مسؤول مكتبة', 'admin_like'],
    ];
    $roleStmt = $db->prepare("INSERT INTO staff_roles
            (role_key, role_name, base_role_key, portal_type, status)
        VALUES (?, ?, NULL, ?, 'active')
        ON DUPLICATE KEY UPDATE
            role_name = VALUES(role_name),
            base_role_key = NULL,
            portal_type = VALUES(portal_type),
            status = 'active'");
    foreach ($fixedRoles as $fixedRole) {
        $roleStmt->execute($fixedRole);
    }

    $unknownRoles = (int)$db->query("SELECT COUNT(DISTINCT u.role)
        FROM users u
        LEFT JOIN staff_roles sr ON sr.role_key = u.role
        WHERE u.role IS NOT NULL AND u.role <> '' AND sr.role_key IS NULL")->fetchColumn();
    if ($unknownRoles > 0) {
        throw new RuntimeException('Unknown legacy user roles must be reviewed before membership backfill.');
    }

    if (!$tableExists('user_role_assignments')) {
        return;
    }

    $db->exec("INSERT IGNORE INTO user_role_assignments (user_id, role_key, is_primary, status)
        SELECT u.id, u.role, 1, 'active'
        FROM users u
        JOIN staff_roles sr ON sr.role_key = u.role
        WHERE u.role IS NOT NULL AND u.role <> ''");
    $db->exec("UPDATE user_role_assignments ura
        JOIN users u ON u.id = ura.user_id
        SET ura.is_primary = CASE WHEN ura.role_key = u.role THEN 1 ELSE 0 END");
};
