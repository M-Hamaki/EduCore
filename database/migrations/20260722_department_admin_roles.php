<?php

declare(strict_types=1);

/** Register the three reviewed department-level admin-like staff roles. */
return static function (PDO $db): void {
    foreach (['staff_roles', 'staff_role_pages'] as $table) {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Staff role schema is not ready.');
        }
    }

    $roles = [
        'student_affairs_manager' => [
            'name' => 'مسؤول شؤون الطلاب',
            'pages' => [
                'students.php',
                'pending_operations.php',
                'new_students.php',
                'transferred_students.php',
                'graduate_students.php',
                'student_archive.php',
                'student_data_completeness.php',
                'class_lists.php',
                'siblings.php',
                'attendance.php',
                'statements.php',
                'student_file.php',
                'school_budget.php',
                'student_id_cards.php',
                'export_students.php',
                'student_statistics.php',
                'calculation_tools.php',
            ],
        ],
        'transport_manager' => [
            'name' => 'مسؤول الحركة والتنقلات',
            'pages' => [
                'locations.php',
                'bus_staff.php',
                'buses.php',
                'student_buses.php',
                'bus_lists.php',
                'bus_report.php',
                'transport_statistics.php',
            ],
        ],
        'roles_permissions_manager' => [
            'name' => 'مسؤول الأدوار والصلاحيات',
            'pages' => [
                'school_settings.php',
                'student_accounts.php',
                'staff_accounts.php',
            ],
        ],
    ];

    $roleStmt = $db->prepare(
        "INSERT INTO staff_roles (role_key, role_name, portal_type, status)
         VALUES (?, ?, 'admin_like', 'active')
         ON DUPLICATE KEY UPDATE
            role_name = VALUES(role_name),
            portal_type = 'admin_like',
            status = 'active'"
    );
    $clearPagesStmt = $db->prepare('DELETE FROM staff_role_pages WHERE role_key = ?');
    $pageStmt = $db->prepare('INSERT INTO staff_role_pages (role_key, page_name) VALUES (?, ?)');

    foreach ($roles as $roleKey => $definition) {
        $roleStmt->execute([$roleKey, $definition['name']]);
        $clearPagesStmt->execute([$roleKey]);
        foreach ($definition['pages'] as $pageName) {
            $pageStmt->execute([$roleKey, $pageName]);
        }
    }
};
