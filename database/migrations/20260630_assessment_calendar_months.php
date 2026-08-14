<?php

/**
 * Migration: إضافة شهور التقويم الدراسي لمحرك الرصد.
 *
 * يحافظ على academic_weeks.month_label للتوافق، ويضيف academic_months ككيان حقيقي
 * حتى تصبح العلاقة: عام دراسي -> ترم -> شهر -> أسبوع.
 */

return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };

    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };

    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
        $stmt->execute([$table, $index]);
        return (bool) $stmt->fetchColumn();
    };

    $foreignKeyExists = static function (string $table, string $constraint) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1');
        $stmt->execute([$table, $constraint, 'FOREIGN KEY']);
        return (bool) $stmt->fetchColumn();
    };

    if (!$tableExists('academic_months')) {
        $db->exec("CREATE TABLE academic_months (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academic_year_id INT NOT NULL,
            term_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            month_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            start_date DATE NULL,
            end_date DATE NULL,
            month_type ENUM('study','holiday','exam','custom') NOT NULL DEFAULT 'study',
            status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
            notes VARCHAR(500) NULL,
            copied_from_month_id INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_month_term_order (term_id, month_order),
            KEY idx_month_year_term (academic_year_id, term_id),
            KEY idx_month_status (status),
            KEY idx_month_copy (copied_from_month_id),
            CONSTRAINT fk_month_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_month_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
            CONSTRAINT fk_month_copy FOREIGN KEY (copied_from_month_id) REFERENCES academic_months(id) ON DELETE SET NULL,
            CONSTRAINT fk_month_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('academic_weeks') && !$columnExists('academic_weeks', 'month_id')) {
        $db->exec('ALTER TABLE academic_weeks ADD COLUMN month_id INT NULL AFTER term_id');
    }

    if ($tableExists('academic_weeks') && !$indexExists('academic_weeks', 'idx_week_month')) {
        $db->exec('ALTER TABLE academic_weeks ADD KEY idx_week_month (month_id)');
    }

    if ($tableExists('academic_weeks') && !$foreignKeyExists('academic_weeks', 'fk_weeks_month')) {
        $db->exec('ALTER TABLE academic_weeks ADD CONSTRAINT fk_weeks_month FOREIGN KEY (month_id) REFERENCES academic_months(id) ON DELETE SET NULL');
    }

    if ($tableExists('academic_months') && $tableExists('academic_weeks')) {
        $db->exec("INSERT INTO academic_months
            (academic_year_id, term_id, name, month_order, start_date, end_date, month_type, status, notes)
            SELECT w.academic_year_id,
                   w.term_id,
                   TRIM(w.month_label) AS name,
                   MIN(w.week_order) AS month_order,
                   MIN(w.start_date) AS start_date,
                   MAX(w.end_date) AS end_date,
                   CASE
                       WHEN SUM(w.week_type = 'exam') > 0 AND SUM(w.week_type = 'study') = 0 THEN 'exam'
                       WHEN SUM(w.week_type = 'holiday') > 0 AND SUM(w.week_type = 'study') = 0 THEN 'holiday'
                       ELSE 'study'
                   END AS month_type,
                   'active' AS status,
                   'تم إنشاؤه تلقائيا من حقل الشهر القديم في الأسابيع' AS notes
            FROM academic_weeks w
            LEFT JOIN academic_months existing
              ON existing.term_id = w.term_id
             AND existing.name = TRIM(w.month_label)
            WHERE w.month_label IS NOT NULL
              AND TRIM(w.month_label) <> ''
              AND existing.id IS NULL
            GROUP BY w.academic_year_id, w.term_id, TRIM(w.month_label)");

        $db->exec("UPDATE academic_weeks w
            JOIN academic_months m
              ON m.academic_year_id = w.academic_year_id
             AND m.term_id = w.term_id
             AND m.name = TRIM(w.month_label)
            SET w.month_id = m.id
            WHERE w.month_id IS NULL
              AND w.month_label IS NOT NULL
              AND TRIM(w.month_label) <> ''");
    }

    echo "Assessment calendar months are ready.\n";
};
