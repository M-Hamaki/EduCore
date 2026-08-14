<?php

declare(strict_types=1);

/**
 * Guarded MariaDB proof for assignment-backfill compatibility records.
 *
 * The test creates and removes one fresh, explicitly named *_test database.
 * It proves that the compatibility ledger can be applied before its later
 * foundations, records a reviewed dated map without touching legacy source
 * rows, and keeps ambiguous source data quarantined until a new dated
 * resolution is appended.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['database:']);
$databaseName = trim((string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: ''));
$marker = trim((string) (getenv('STAFF_HR_TEST_MARKER') ?: ''));
if ($marker !== 'integrated-staff-hr') {
    fwrite(STDERR, "FAIL: set STAFF_HR_TEST_MARKER=integrated-staff-hr explicitly.\n");
    exit(2);
}
if ($databaseName === ''
    || !preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName)
    || strtolower($databaseName) === 'educore') {
    fwrite(STDERR, "FAIL: EDUCORE_TEST_DB_NAME/--database must identify a new isolated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('DB_NAME=' . $databaseName);
putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = $databaseName;
$_ENV['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_ENV['STAFF_HR_TEST_MARKER'] = $marker;
$_SERVER['APP_ENV'] = 'test';
$_SERVER['DB_NAME'] = $databaseName;
$_SERVER['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_SERVER['STAFF_HR_TEST_MARKER'] = $marker;

require_once __DIR__ . '/bootstrap_staff_hr.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/bootstrap.php';

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$recordFailure = static function (string $message) use (&$failures): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    ++$failures;
};
$expectRejected = static function (
    callable $operation,
    string $message,
    array $expectedSqlStates = ['23000', '45000']
) use ($assert): void {
    $rejected = false;
    $sqlState = '';
    try {
        $operation();
    } catch (Throwable $exception) {
        $rejected = true;
        $sqlState = $exception instanceof PDOException && isset($exception->errorInfo[0])
            ? (string) $exception->errorInfo[0]
            : (string) $exception->getCode();
    }

    $assert($rejected, $message);
    if ($rejected && $expectedSqlStates !== []) {
        $assert(
            in_array($sqlState, $expectedSqlStates, true),
            $message . ' (expected SQLSTATE ' . implode(' or ', $expectedSqlStates)
            . '; actual ' . $sqlState . ')'
        );
    }
};
$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }

    return chr(96) . $identifier . chr(96);
};

$backfillTables = ['staff_assignment_legacy_links'];
$backfillTriggers = [
    'trg_staff_assignment_legacy_link_no_update',
    'trg_staff_assignment_legacy_link_no_delete',
];
$admin = null;
$db = null;
$databaseCreated = false;
$databaseDropped = false;

try {
    $admin = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $exists = $admin->prepare(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $exists->execute([$databaseName]);
    if ((int) $exists->fetchColumn() !== 0) {
        fwrite(STDERR, "FAIL: {$databaseName} already exists; supply a new dedicated *_test database name.\n");
        exit(2);
    }

    $admin->exec(
        'CREATE DATABASE ' . $quoteIdentifier($databaseName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $databaseCreated = true;
    $db = staffHrTestDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $schemaObjects = static function (string $kind) use ($db): array {
        $sql = $kind === 'table'
            ? 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
            : 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME';

        return array_map('strval', $db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    };
    $tableExists = static function (string $table) use ($db): bool {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() === 1;
    };
    $triggerExists = static function (string $trigger) use ($db): bool {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?'
        );
        $statement->execute([$trigger]);

        return (int) $statement->fetchColumn() === 1;
    };

    $assert($schemaObjects('table') === [], 'new assignment-backfill test database starts without tables');
    $assert($schemaObjects('trigger') === [], 'new assignment-backfill test database starts without triggers');

    // These are deliberately minimal legacy fixtures. The migration must not
    // read, normalize, update, or delete them; a later coordinator owns that.
    $db->exec(<<<'SQL'
CREATE TABLE `staff_profiles` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `department` VARCHAR(255) NULL,
    `job_title` VARCHAR(255) NULL,
    `hire_date` DATE NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE `staff_job_movements` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `new_department` VARCHAR(255) NULL,
    `new_job_title` VARCHAR(255) NULL,
    `effective_date` DATE NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
INSERT INTO `staff_profiles` (`user_id`, `department`, `job_title`, `hire_date`) VALUES
    (970001, 'المرحلة الابتدائية', 'معلم', '2026-01-01'),
    (970002, 'المرحلة الابتدائية، المرحلة الإعدادية', 'معلم', '2026-01-01')
SQL);
    $db->exec(<<<'SQL'
INSERT INTO `staff_job_movements` (`user_id`, `new_department`, `new_job_title`, `effective_date`)
VALUES (970001, 'المرحلة الإعدادية', 'معلم', '2026-07-01')
SQL);

    $backfillMigration = require dirname(__DIR__)
        . '/database/migrations/20260730_staff_hr_assignment_backfill.php';
    $organizationMigration = require dirname(__DIR__)
        . '/database/migrations/20260730_staff_hr_organization_policy_foundation.php';
    $operationsMigration = require dirname(__DIR__)
        . '/database/migrations/20260730_staff_hr_workflow_operations_foundation.php';
    $assert(is_callable($backfillMigration), 'assignment-backfill migration returns a callable');
    $assert(is_callable($organizationMigration), 'organization prerequisite migration returns a callable');
    $assert(is_callable($operationsMigration), 'workflow operations prerequisite migration returns a callable');

    // The compatibility migration is intentionally safe in filename order,
    // before the organization/workflow tables that its scalar refs target.
    $backfillMigration($db);
    $assert($tableExists('staff_assignment_legacy_links'), 'backfill ledger applies before later foundations');
    foreach ($backfillTriggers as $trigger) {
        $assert($triggerExists($trigger), "backfill ledger creates {$trigger}");
    }

    $organizationMigration($db);
    $operationsMigration($db);
    $backfillMigration($db);
    $assert(
        count(array_filter($backfillTables, $tableExists)) === count($backfillTables),
        'reapplying the compatibility migration leaves its one table intact'
    );
    $assert(
        count(array_filter($backfillTriggers, $triggerExists)) === count($backfillTriggers),
        'reapplying the compatibility migration leaves its append-only triggers intact'
    );

    $legacyProfile = $db->query(
        'SELECT department, job_title, hire_date FROM staff_profiles WHERE user_id = 970001'
    )->fetch(PDO::FETCH_ASSOC);
    $legacyMovement = $db->query(
        'SELECT new_department, new_job_title, effective_date FROM staff_job_movements WHERE id = 1'
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        $legacyProfile === [
            'department' => 'المرحلة الابتدائية',
            'job_title' => 'معلم',
            'hire_date' => '2026-01-01',
        ]
        && $legacyMovement === [
            'new_department' => 'المرحلة الإعدادية',
            'new_job_title' => 'معلم',
            'effective_date' => '2026-07-01',
        ],
        'schema migration leaves legacy profile and movement source rows untouched'
    );

    $orgInsert = $db->prepare(
        'INSERT INTO staff_org_units (code, name, unit_type, valid_from) VALUES (?, ?, ?, ?)'
    );
    $orgInsert->execute(['PRIMARY', 'المرحلة الابتدائية', 'stage', '2026-01-01']);
    $primaryUnitId = (int) $db->lastInsertId();
    $orgInsert->execute(['PREPARATORY', 'المرحلة الإعدادية', 'stage', '2026-01-01']);
    $preparatoryUnitId = (int) $db->lastInsertId();
    $titleInsert = $db->prepare(
        'INSERT INTO staff_job_titles (code, name, active_from) VALUES (?, ?, ?)'
    );
    $titleInsert->execute(['TEACHER', 'معلم', '2026-01-01']);
    $teacherTitleId = (int) $db->lastInsertId();

    $batchInsert = $db->prepare(
        "INSERT INTO staff_hr_migration_batches
            (migration_key, started_at, status, idempotency_key, checksum)
         VALUES ('staff_assignment_compatibility_v1', '2026-08-09 09:00:00.000000', 'running', ?, ?)"
    );
    $batchInsert->execute([
        'assignment-backfill-integration-batch',
        hash('sha256', 'assignment-backfill-integration-batch'),
    ]);
    $batchId = (int) $db->lastInsertId();

    $assignmentInsert = $db->prepare(
        "INSERT INTO staff_assignments
            (staff_user_id, org_unit_id, job_title_id, assignment_kind, employment_status,
             valid_from, valid_to, source, source_ref)
         VALUES (?, ?, ?, 'primary', 'active', ?, ?, 'legacy_backfill', ?)"
    );
    $assignmentInsert->execute([
        970001, $primaryUnitId, $teacherTitleId, '2026-01-01', '2026-06-30', 'staff_profiles:970001',
    ]);
    $initialAssignmentId = (int) $db->lastInsertId();
    $assignmentInsert->execute([
        970001, $preparatoryUnitId, $teacherTitleId, '2026-07-01', null, 'staff_job_movements:1',
    ]);
    $transferAssignmentId = (int) $db->lastInsertId();

    $linkInsert = $db->prepare(
        "INSERT INTO staff_assignment_legacy_links
            (migration_batch_id, migration_exception_id, assignment_id, staff_user_id,
             legacy_source_type, legacy_source_key, source_payload_hash,
             assignment_valid_from, assignment_valid_to, resolution_status,
             resolution_reason_code, supersedes_id, decision_idempotency_key, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $profileHash = hash('sha256', 'staff_profiles|970001|المرحلة الابتدائية|معلم|2026-01-01');
    $linkInsert->execute([
        $batchId, null, $initialAssignmentId, 970001,
        'staff_profiles', 'staff_profiles:970001', $profileHash,
        '2026-01-01', '2026-06-30', 'mapped', 'EXACT_CATALOG_MATCH', null,
        'assignment-backfill-profile-970001', 990001,
    ]);
    $initialLinkId = (int) $db->lastInsertId();
    $movementHash = hash('sha256', 'staff_job_movements|1|970001|المرحلة الإعدادية|معلم|2026-07-01');
    $linkInsert->execute([
        $batchId, null, $transferAssignmentId, 970001,
        'staff_job_movements', 'staff_job_movements:1', $movementHash,
        '2026-07-01', null, 'mapped', 'EXACT_CATALOG_MATCH', $initialLinkId,
        'assignment-backfill-movement-1', 990001,
    ]);
    $transferLinkId = (int) $db->lastInsertId();

    $assignmentQuery = new \EduCore\Modules\Staff\Infrastructure\PdoStaffAssignmentAtDateQuery($db);
    $beforeTransfer = $assignmentQuery->forStaff(970001, new DateTimeImmutable('2026-06-30'));
    $onTransfer = $assignmentQuery->forStaff(970001, new DateTimeImmutable('2026-07-01'));
    $assert(
        ($beforeTransfer['assignment_id'] ?? null) === $initialAssignmentId
        && ($beforeTransfer['org_unit_id'] ?? null) === $primaryUnitId
        && ($onTransfer['assignment_id'] ?? null) === $transferAssignmentId
        && ($onTransfer['org_unit_id'] ?? null) === $preparatoryUnitId,
        'dated assignment query preserves pre-transfer history and switches exactly on the effective date'
    );

    $ambiguousHash = hash('sha256', 'staff_profiles|970002|ambiguous-unit-and-title');
    $exceptionInsert = $db->prepare(
        "INSERT INTO staff_hr_migration_exceptions
            (batch_id, source_type, source_key, reason_code, payload_hash)
         VALUES (?, 'staff_profiles', 'staff_profiles:970002', 'AMBIGUOUS_ORG_UNIT', ?)"
    );
    $exceptionInsert->execute([$batchId, $ambiguousHash]);
    $exceptionId = (int) $db->lastInsertId();
    $linkInsert->execute([
        $batchId, $exceptionId, null, 970002,
        'staff_profiles', 'staff_profiles:970002', $ambiguousHash,
        '2026-01-01', null, 'quarantined', 'AMBIGUOUS_ORG_UNIT', null,
        'assignment-backfill-profile-970002-quarantine', 990001,
    ]);
    $quarantineLinkId = (int) $db->lastInsertId();
    $quarantineRow = $db->prepare(
        'SELECT assignment_id, migration_exception_id, source_payload_hash, resolution_status
         FROM staff_assignment_legacy_links WHERE id = ?'
    );
    $quarantineRow->execute([$quarantineLinkId]);
    $quarantine = $quarantineRow->fetch(PDO::FETCH_ASSOC);
    $assert(
        $assignmentQuery->forStaff(970002, new DateTimeImmutable('2026-07-31')) === null
        && is_array($quarantine)
        && array_key_exists('assignment_id', $quarantine)
        && $quarantine['assignment_id'] === null
        && (int) ($quarantine['migration_exception_id'] ?? 0) === $exceptionId
        && ($quarantine['source_payload_hash'] ?? null) === $ambiguousHash
        && ($quarantine['resolution_status'] ?? null) === 'quarantined',
        'ambiguous legacy data is quarantined without inventing an assignment'
    );
    $linkColumns = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_assignment_legacy_links'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        !in_array('department', $linkColumns, true)
        && !in_array('job_title', $linkColumns, true)
        && !in_array('source_payload', $linkColumns, true),
        'quarantine ledger stores a source hash rather than copied legacy free text'
    );

    $expectRejected(static function () use ($linkInsert, $batchId, $profileHash): void {
        $linkInsert->execute([
            $batchId, null, null, 970003,
            'staff_profiles', 'staff_profiles:970003', $profileHash,
            '2026-01-01', null, 'mapped', 'EXACT_CATALOG_MATCH', null,
            'assignment-backfill-invalid-mapped', 990001,
        ]);
    }, 'a mapped legacy source requires a concrete assignment reference');
    $expectRejected(static function () use ($linkInsert, $batchId, $profileHash): void {
        $linkInsert->execute([
            $batchId, null, null, 970003,
            'staff_profiles', 'staff_profiles:970004', $profileHash,
            '2026-01-01', null, 'quarantined', 'AMBIGUOUS_ORG_UNIT', null,
            'assignment-backfill-invalid-quarantine', 990001,
        ]);
    }, 'a quarantined legacy source requires a migration-exception reference');
    $expectRejected(static function () use ($linkInsert, $batchId, $initialAssignmentId, $profileHash): void {
        $linkInsert->execute([
            $batchId, null, $initialAssignmentId, 970003,
            'staff_profiles', 'staff_profiles:970005', $profileHash,
            '2026-07-02', '2026-07-01', 'mapped', 'EXACT_CATALOG_MATCH', null,
            'assignment-backfill-invalid-date', 990001,
        ]);
    }, 'compatibility records reject an inverted effective range');
    $expectRejected(static function () use ($linkInsert, $batchId, $initialAssignmentId, $profileHash): void {
        $linkInsert->execute([
            $batchId, null, $initialAssignmentId, 970001,
            'staff_profiles', 'staff_profiles:970001', $profileHash,
            '2026-01-01', '2026-06-30', 'mapped', 'EXACT_CATALOG_MATCH', null,
            'assignment-backfill-duplicate-source', 990001,
        ]);
    }, 'the same source hash cannot be mapped twice in one batch');
    $expectRejected(static function () use ($db, $initialLinkId): void {
        $statement = $db->prepare(
            "UPDATE staff_assignment_legacy_links SET resolution_reason_code = 'CHANGED' WHERE id = ?"
        );
        $statement->execute([$initialLinkId]);
    }, 'legacy compatibility decisions are append-only', ['45000']);
    $expectRejected(static function () use ($db, $transferLinkId): void {
        $statement = $db->prepare('DELETE FROM staff_assignment_legacy_links WHERE id = ?');
        $statement->execute([$transferLinkId]);
    }, 'legacy compatibility decisions cannot be deleted', ['45000']);

    // A human-reviewed resolution is a new dated record; the original
    // quarantine row remains immutable and no historical period is rewritten.
    $assignmentInsert->execute([
        970002, $primaryUnitId, $teacherTitleId, '2026-08-01', null, 'manual_resolution:970002',
    ]);
    $reviewedAssignmentId = (int) $db->lastInsertId();
    $linkInsert->execute([
        $batchId, null, $reviewedAssignmentId, 970002,
        'staff_profiles', 'staff_profiles:970002', $ambiguousHash,
        '2026-08-01', null, 'mapped', 'MANUAL_REVIEW_RESOLUTION', $quarantineLinkId,
        'assignment-backfill-profile-970002-reviewed', 990002,
    ]);
    $assert(
        $assignmentQuery->forStaff(970002, new DateTimeImmutable('2026-07-31')) === null
        && ($assignmentQuery->forStaff(970002, new DateTimeImmutable('2026-08-01'))['assignment_id'] ?? null)
            === $reviewedAssignmentId,
        'manual resolution appends a future-dated assignment without changing the quarantined past'
    );
} catch (Throwable $exception) {
    $recordFailure('assignment-backfill migration/invariant exercise failed: ' . $exception->getMessage());
} finally {
    if ($databaseCreated && $admin instanceof PDO) {
        try {
            $db = null;
            $admin->exec('DROP DATABASE ' . $quoteIdentifier($databaseName));
            $databaseDropped = true;
            $exists = $admin->prepare(
                'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
            );
            $exists->execute([$databaseName]);
            $assert((int) $exists->fetchColumn() === 0, 'temporary assignment-backfill database is deleted');
        } catch (Throwable $exception) {
            $recordFailure("temporary assignment-backfill database cleanup failed: {$exception->getMessage()}");
        }
    }
}

if ($databaseCreated && !$databaseDropped) {
    fwrite(STDERR, "FAIL: temporary database {$databaseName} still exists and requires manual cleanup.\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR assignment-backfill integration failure(s).\n");
    exit(1);
}

echo "Staff-HR assignment-backfill migration/quarantine/effective-date proof passed on {$databaseName}; temporary database removed.\n";
