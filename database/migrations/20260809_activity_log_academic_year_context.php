<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $columnStmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND COLUMN_NAME = 'academic_year_id'"
    );
    $columnStmt->execute();
    if ((int) $columnStmt->fetchColumn() === 0) {
        $db->exec(
            "ALTER TABLE activity_logs
             ADD academic_year_id INT NULL AFTER ip_address"
        );
    }

    $db->exec(
        "UPDATE activity_logs
         SET academic_year_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(details, '$.academic_year_id')) AS UNSIGNED)
         WHERE academic_year_id IS NULL
           AND JSON_VALID(details)
           AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.academic_year_id')) REGEXP '^[1-9][0-9]*$'"
    );
    $db->exec(
        "UPDATE activity_logs
         SET academic_year_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(details, '$.changes.academic_year_id.to')) AS UNSIGNED)
         WHERE academic_year_id IS NULL
           AND JSON_VALID(details)
           AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.changes.academic_year_id.to')) REGEXP '^[1-9][0-9]*$'"
    );
    $db->exec(
        "UPDATE activity_logs
         SET academic_year_id = target_id
         WHERE academic_year_id IS NULL
           AND target_type = 'academic_year_student_sync'
           AND target_id IS NOT NULL"
    );

    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    };

    if ($tableExists('student_enrollments')) {
        $db->exec(
            "UPDATE activity_logs al
             INNER JOIN student_enrollments se ON al.target_type = 'student_enrollment' AND se.id = al.target_id
             SET al.academic_year_id = se.academic_year_id
             WHERE al.academic_year_id IS NULL"
        );
    }
    if ($tableExists('student_change_requests')) {
        $db->exec(
            "UPDATE activity_logs al
             INNER JOIN student_change_requests scr ON al.target_type = 'student_change_request' AND scr.id = al.target_id
             SET al.academic_year_id = scr.academic_year_id
             WHERE al.academic_year_id IS NULL"
        );
    }
    if ($tableExists('attendance')) {
        $db->exec(
            "UPDATE activity_logs al
             INNER JOIN attendance a ON al.target_type = 'attendance' AND a.id = al.target_id
             SET al.academic_year_id = a.academic_year_id
             WHERE al.academic_year_id IS NULL AND a.academic_year_id IS NOT NULL"
        );
    }

    if ($tableExists('academic_years')) {
        $db->exec(
            "UPDATE activity_logs al
             SET al.academic_year_id = (
                 SELECT ay.id
                 FROM academic_years ay
                 WHERE ay.start_date IS NOT NULL
                   AND ay.end_date IS NOT NULL
                   AND al.created_at >= ay.start_date
                   AND al.created_at < DATE_ADD(ay.end_date, INTERVAL 1 DAY)
                 ORDER BY ay.is_active DESC, ay.id DESC
                 LIMIT 1
             )
             WHERE al.academic_year_id IS NULL"
        );
    }

    $indexStmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND INDEX_NAME = 'idx_activity_academic_year'"
    );
    $indexStmt->execute();
    if ((int) $indexStmt->fetchColumn() === 0) {
        $db->exec(
            'ALTER TABLE activity_logs ADD INDEX idx_activity_academic_year (academic_year_id, created_at)'
        );
    }
};
