<?php

declare(strict_types=1);

/**
 * Separates the student's registration status from the academic status while
 * keeping one authoritative enrollment row per student and academic year.
 *
 * The migration is additive and retains legacy enum values during the
 * compatibility window so older readers continue to work while they migrate
 * to academic_status.
 */
return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };
    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $index]);
        return (bool) $stmt->fetchColumn();
    };

    $db->exec(
        "ALTER TABLE student_enrollments
         MODIFY enrollment_status
         ENUM('enrolled','transferred','discontinued','graduated','withdrawn')
         NOT NULL DEFAULT 'enrolled'"
    );
    if (!$columnExists('student_enrollments', 'academic_status')) {
        $db->exec(
            "ALTER TABLE student_enrollments
             ADD COLUMN academic_status
             ENUM('new','promoted','retained','graduated')
             NOT NULL DEFAULT 'new'
             AFTER enrollment_status"
        );
    }
    if (!$indexExists('student_enrollments', 'idx_enroll_year_statuses')) {
        $db->exec(
            'ALTER TABLE student_enrollments
             ADD KEY idx_enroll_year_statuses (academic_year_id, enrollment_status, academic_status)'
        );
    }

    $db->exec(
        "ALTER TABLE student_profiles
         MODIFY enrollment_status
         ENUM('enrolled','graduated','transferred','discontinued')
         NOT NULL DEFAULT 'enrolled'"
    );
    if ($columnExists('assessment_student_locks', 'lock_reason')) {
        $db->exec(
            "ALTER TABLE assessment_student_locks
             MODIFY lock_reason
             ENUM('graduated','transferred','discontinued','manual')
             NOT NULL"
        );
    }

    if (!$columnExists('student_promotion_decisions', 'enrollment_status')) {
        $db->exec(
            "ALTER TABLE student_promotion_decisions
             ADD COLUMN enrollment_status
             ENUM('enrolled','transferred','discontinued')
             NULL AFTER decision"
        );
    }
    if (!$columnExists('student_promotion_decisions', 'academic_status')) {
        $db->exec(
            "ALTER TABLE student_promotion_decisions
             ADD COLUMN academic_status
             ENUM('new','promoted','retained','graduated')
             NULL AFTER enrollment_status"
        );
    }
    if (!$indexExists('student_promotion_decisions', 'idx_student_promotion_status_pair')) {
        $db->exec(
            'ALTER TABLE student_promotion_decisions
             ADD KEY idx_student_promotion_status_pair
             (source_year_id, target_year_id, enrollment_status, academic_status, status)'
        );
    }

    $db->exec(
        "UPDATE student_promotion_decisions
         SET enrollment_status = CASE decision
             WHEN 'transferred_out' THEN 'transferred'
             WHEN 'withdrawn' THEN 'discontinued'
             ELSE 'enrolled'
         END
         WHERE enrollment_status IS NULL"
    );
    $db->exec(
        "UPDATE student_promotion_decisions
         SET academic_status = CASE decision
             WHEN 'promoted' THEN 'promoted'
             WHEN 'retained' THEN 'retained'
             WHEN 'graduated' THEN 'graduated'
             ELSE academic_status
         END
         WHERE academic_status IS NULL"
    );

    $db->exec(
        "UPDATE student_enrollments se
         JOIN student_promotion_decisions d ON d.id = se.promotion_decision_id
         SET se.academic_status = CASE d.decision
             WHEN 'promoted' THEN 'promoted'
             WHEN 'retained' THEN 'retained'
             WHEN 'graduated' THEN 'graduated'
             ELSE se.academic_status
         END"
    );
    $db->exec(
        "UPDATE student_enrollments
         SET academic_status = 'graduated'
         WHERE enrollment_status = 'graduated'"
    );
    $db->exec(
        "UPDATE student_enrollments
         SET enrollment_status = 'discontinued'
         WHERE enrollment_status = 'withdrawn'"
    );
};
