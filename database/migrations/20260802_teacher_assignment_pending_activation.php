<?php
declare(strict_types=1);

/**
 * Separates the administrator's requested activation from the effective
 * operational activation of a teacher subject/class assignment.
 *
 * Existing inactive records are intentionally treated as manually disabled;
 * they must never become active merely because a subject link is edited.
 */
$applyMigration = static function (PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM teacher_subject_assignments')->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('requested_active', $columns, true)) {
        $pdo->exec(
            "ALTER TABLE teacher_subject_assignments
             ADD COLUMN requested_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
             ADD COLUMN pending_reason VARCHAR(64) NULL DEFAULT NULL AFTER requested_active"
        );
        // Preserve historical meaning: an already inactive row was disabled by an administrator.
        $pdo->exec('UPDATE teacher_subject_assignments SET requested_active = is_active');
    }

    $indexes = $pdo->query("SHOW INDEX FROM teacher_subject_assignments WHERE Key_name = 'idx_tsa_activation_sync'")
        ->fetchAll(PDO::FETCH_ASSOC);
    if ($indexes === []) {
        $pdo->exec(
            'ALTER TABLE teacher_subject_assignments
             ADD INDEX idx_tsa_activation_sync
             (academic_year_id, subject_id, grade_id, class_id, requested_active, is_active)'
        );
    }
};

// Compatible with both migration runners used by older EduCore deployments.
if (isset($pdo) && $pdo instanceof PDO) {
    $applyMigration($pdo);
}

return $applyMigration;
