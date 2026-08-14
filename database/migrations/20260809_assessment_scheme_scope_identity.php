<?php

/**
 * Compatibility migration for early grouped-scheme deployments.
 *
 * MySQL considers NULL values distinct in a UNIQUE key. A direct unique key on
 * `class_id` therefore permits accidental duplicate whole-grade scopes. This
 * migration introduces a non-null identity (0 for a whole grade, class id for a
 * class scope) and makes that the true uniqueness boundary.
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
    $indexColumns = static function (string $table, string $index) use ($db): array {
        $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
        $stmt->execute([$table, $index]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    };

    if (!$tableExists('assessment_scheme_scopes')) {
        return;
    }

    if (!$columnExists('assessment_scheme_scopes', 'scope_identity')) {
        $db->exec('ALTER TABLE assessment_scheme_scopes ADD COLUMN scope_identity INT NULL AFTER class_id');
    }
    $db->exec('UPDATE assessment_scheme_scopes SET scope_identity = COALESCE(class_id, 0) WHERE scope_identity IS NULL');
    $db->exec('ALTER TABLE assessment_scheme_scopes MODIFY COLUMN scope_identity INT NOT NULL');

    $duplicateStmt = $db->query('SELECT scheme_id, grade_id, scope_identity, COUNT(*) AS duplicate_count
        FROM assessment_scheme_scopes
        GROUP BY scheme_id, grade_id, scope_identity
        HAVING COUNT(*) > 1
        LIMIT 1');
    $duplicate = $duplicateStmt ? $duplicateStmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($duplicate) {
        throw new RuntimeException(
            'تعذر ترقية نطاقات خطط الدرجات لأن الخطة #' . (int) $duplicate['scheme_id']
            . ' تحتوي نطاقًا مكررًا. راجع النطاقات يدويًا قبل إعادة تشغيل الترقية.'
        );
    }

    $expectedScopeIndex = ['scheme_id', 'grade_id', 'scope_identity'];
    $scopeIndexIsCurrent = $indexExists('assessment_scheme_scopes', 'uq_scheme_scope')
        && $indexColumns('assessment_scheme_scopes', 'uq_scheme_scope') === $expectedScopeIndex;

    if (!$scopeIndexIsCurrent && $indexExists('assessment_scheme_scopes', 'uq_scheme_scope')) {
        // MySQL may use the unique index as the supporting index for the
        // scheme_id foreign key. Keep an explicit supporting index before the
        // unique key is rebuilt so the migration remains valid on early installs.
        if (!$indexExists('assessment_scheme_scopes', 'idx_scheme_scope_scheme')) {
            $db->exec('ALTER TABLE assessment_scheme_scopes ADD KEY idx_scheme_scope_scheme (scheme_id)');
        }
        $db->exec('ALTER TABLE assessment_scheme_scopes DROP INDEX uq_scheme_scope');
    }
    if (!$scopeIndexIsCurrent && !$indexExists('assessment_scheme_scopes', 'uq_scheme_scope')) {
        $db->exec('ALTER TABLE assessment_scheme_scopes ADD UNIQUE KEY uq_scheme_scope (scheme_id, grade_id, scope_identity)');
    }

    if ($tableExists('assessment_schemes') && $columnExists('assessment_schemes', 'readiness_status')) {
        // Do not let newly created legacy rows inherit a speculative "ready"
        // state. Every supported write path refreshes this derived state after
        // its scopes/components are known.
        $db->exec("ALTER TABLE assessment_schemes MODIFY COLUMN readiness_status VARCHAR(40) NOT NULL DEFAULT 'legacy'");

        // An installation may have executed the first draft of the migration,
        // whose default was "ready". Keep those rows operational, but flag
        // ungrouped plans for an explicit review instead of rewriting business
        // state without evidence.
        if ($tableExists('assessment_scheme_migration_reviews')
            && $columnExists('assessment_schemes', 'family_id')) {
            $db->exec("INSERT IGNORE INTO assessment_scheme_migration_reviews (scheme_id, review_type, details)
                SELECT id, 'legacy_readiness_review',
                       'Legacy ungrouped plan inherited readiness=ready before readiness was recalculated.'
                FROM assessment_schemes
                WHERE family_id IS NULL AND readiness_status = 'ready'");
        }
    }
};
