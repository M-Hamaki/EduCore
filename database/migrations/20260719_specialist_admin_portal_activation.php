<?php

declare(strict_types=1);

/**
 * Activate the specialist on the shared, scope-hardened admin entrypoints.
 * The specialist role keeps the exact admin UI while authorization remains
 * enforced by the annual staff scope context in each page and endpoint.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };

    if (!$tableExists('staff_roles') || !$tableExists('staff_role_pages')) {
        throw new RuntimeException('Staff role schema is not ready.');
    }

    $roleStmt = $db->prepare("INSERT INTO staff_roles (role_key, role_name, portal_type, status)
        VALUES ('specialist', 'أخصائي', 'admin_like', 'active')
        ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), portal_type = 'admin_like', status = 'active'");
    $roleStmt->execute();

    // Replace any legacy specialist page grants with the reviewed shared-page set.
    $clearPagesStmt = $db->prepare('DELETE FROM staff_role_pages WHERE role_key = ?');
    $clearPagesStmt->execute(['specialist']);

    $pages = [
        'specialist_dashboard.php',
        'students.php',
        'ajax_students_datatable.php',
        'class_lists.php',
        'attendance.php',
        'student_file.php',
        'student_id_cards.php',
        'export_students.php',
        'student_statistics.php',
        'calculation_tools.php',
        'student_evaluations.php',
        'teacher_evaluations.php',
        'evaluation_analytics.php',
        'evaluation_reports.php',
        'student_clinic.php',
        'ajax_clinic_datatable.php',
    ];

    $pageStmt = $db->prepare('INSERT IGNORE INTO staff_role_pages (role_key, page_name) VALUES (?, ?)');
    foreach ($pages as $page) {
        $pageStmt->execute(['specialist', $page]);
    }
};
