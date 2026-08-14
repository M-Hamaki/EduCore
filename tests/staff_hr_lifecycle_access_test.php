<?php

declare(strict_types=1);

/** Isolated lifecycle/effective-date/access-revocation proof; no school DB is touched. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/src/Modules/Staff/Contracts/StaffAssignmentAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffAccessEligibilityQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffPopulationAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/PdoStaffPopulationAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Organization/PdoStaffAssignmentAtDateQuery.php';

use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffPopulationAtDateQuery;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

try {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, status TEXT NOT NULL)');
    $db->exec('CREATE TABLE user_role_assignments (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, role_key TEXT NOT NULL, status TEXT NOT NULL)');
    $db->exec(
        'CREATE TABLE staff_assignments (
            id INTEGER PRIMARY KEY,
            staff_user_id INTEGER NOT NULL,
            org_unit_id INTEGER NOT NULL,
            job_title_id INTEGER NOT NULL,
            assignment_kind TEXT NOT NULL,
            employment_status TEXT NOT NULL,
            valid_from TEXT NOT NULL,
            valid_to TEXT NULL,
            version INTEGER NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE staff_policy_group_memberships (
            id INTEGER PRIMARY KEY,
            group_id INTEGER NOT NULL,
            staff_user_id INTEGER NOT NULL,
            valid_from TEXT NOT NULL,
            valid_to TEXT NULL,
            status TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE staff_manager_assignments (
            id INTEGER PRIMARY KEY,
            subject_type TEXT NOT NULL,
            subject_id INTEGER NOT NULL,
            manager_user_id INTEGER NOT NULL,
            manager_kind TEXT NOT NULL,
            priority INTEGER NOT NULL,
            valid_from TEXT NOT NULL,
            valid_to TEXT NULL,
            status TEXT NOT NULL
        )'
    );
    $db->exec(
        "INSERT INTO users (id, role, status) VALUES
            (101, 'employee', 'active'),
            (102, 'employee', 'active'),
            (103, 'employee', 'active'),
            (104, 'employee', 'active'),
            (105, 'employee', 'active'),
            (106, 'employee', 'active'),
            (107, 'employee', 'inactive'),
            (108, 'employee', 'active')"
    );
    $db->exec("INSERT INTO user_role_assignments (id, user_id, role_key, status) VALUES (501, 103, 'admin', 'active'), (502, 104, 'admin', 'active')");
    $db->exec(
        "INSERT INTO staff_assignments
            (id, staff_user_id, org_unit_id, job_title_id, assignment_kind, employment_status, valid_from, valid_to, version)
         VALUES
            (1, 101, 10, 20, 'primary', 'active', '2026-01-01', '2026-06-30', 1),
            (2, 101, 11, 21, 'primary', 'active', '2026-07-01', NULL, 2),
            (3, 101, 99, 99, 'secondary', 'active', '2026-01-01', NULL, 1),
            (4, 102, 10, 20, 'primary', 'active', '2026-01-01', NULL, 1),
            (5, 104, 10, 20, 'primary', 'active', '2026-01-01', '2026-06-30', 1),
            (6, 105, 10, 20, 'primary', 'suspended', '2026-01-01', NULL, 1),
            (7, 106, 10, 20, 'primary', 'active', '2026-01-01', '2026-05-31', 1),
            (8, 106, 11, 21, 'primary', 'rehired', '2026-06-01', NULL, 2),
            (9, 107, 10, 20, 'primary', 'active', '2026-01-01', NULL, 1),
            (10, 108, 10, 20, 'primary', 'active', '2026-01-01', NULL, 1),
            (11, 108, 11, 21, 'primary', 'active', '2026-06-01', NULL, 2)"
    );
    $db->exec(
        "INSERT INTO staff_policy_group_memberships (id, group_id, staff_user_id, valid_from, valid_to, status) VALUES
            (1, 30, 101, '2026-01-01', '2026-06-30', 'active'),
            (2, 31, 101, '2026-07-01', NULL, 'active')"
    );
    $db->exec(
        "INSERT INTO staff_manager_assignments
            (id, subject_type, subject_id, manager_user_id, manager_kind, priority, valid_from, valid_to, status)
         VALUES (701, 'staff', 101, 102, 'direct', 0, '2026-01-01', '2026-06-30', 'active')"
    );

    $query = new PdoStaffAssignmentAtDateQuery($db);
    $population = new PdoStaffPopulationAtDateQuery($db);
    $beforeTransfer = $query->forStaff(101, new DateTimeImmutable('2026-06-30'));
    $afterTransfer = $query->forStaff(101, new DateTimeImmutable('2026-07-01'));
    $suspended = $query->forStaff(105, new DateTimeImmutable('2026-07-01'));
    $rehired = $query->forStaff(106, new DateTimeImmutable('2026-06-01'));

    $ambiguousRejected = false;
    try {
        $query->forStaff(108, new DateTimeImmutable('2026-07-01'));
    } catch (DomainException $exception) {
        $ambiguousRejected = str_starts_with($exception->getMessage(), 'AMBIGUOUS_STAFF_ASSIGNMENT:');
    }

    $selfActive = $query->assertCurrentAccess(
        101,
        'attendance.adjustment.request.self',
        'attendance:adjustment:staff:101',
        new DateTimeImmutable('2026-07-01 08:00:00')
    );
    $managerBeforeTransfer = $query->assertCurrentAccess(
        102,
        'attendance.adjustment.decide.manager',
        'attendance:adjustment:staff:101',
        new DateTimeImmutable('2026-06-30 08:00:00')
    );
    $managerAfterRelationshipEnd = $query->assertCurrentAccess(
        102,
        'attendance.adjustment.decide.manager',
        'attendance:adjustment:staff:101',
        new DateTimeImmutable('2026-07-01 08:00:00')
    );
    $ended = $query->assertCurrentAccess(
        104,
        'attendance.adjustment.request.self',
        'attendance:adjustment:staff:104',
        new DateTimeImmutable('2026-07-01 08:00:00')
    );
    $suspendedAccess = $query->assertCurrentAccess(
        105,
        'attendance.adjustment.request.self',
        'attendance:adjustment:staff:105',
        new DateTimeImmutable('2026-07-01 08:00:00')
    );
    $rehiredAccess = $query->assertCurrentAccess(
        106,
        'attendance.adjustment.request.self',
        'attendance:adjustment:staff:106',
        new DateTimeImmutable('2026-06-01 08:00:00')
    );
    $multiRoleHr = $query->assertCurrentAccess(
        103,
        'attendance.alternative.review.hr',
        'attendance:alternative:staff:101',
        new DateTimeImmutable('2026-07-01 08:00:00')
    );
    $endedHr = $query->assertCurrentAccess(
        104,
        'attendance.alternative.review.hr',
        'attendance:alternative:staff:101',
        new DateTimeImmutable('2026-07-01 08:00:00')
    );
    $inactiveAccount = $query->assertCurrentAccess(
        107,
        'attendance.adjustment.request.self',
        'attendance:adjustment:staff:107',
        new DateTimeImmutable('2026-07-01 08:00:00')
    );
    $unsupportedCapability = $query->assertCurrentAccess(
        101,
        'attendance.adjustment.request.untrusted_scope',
        'attendance:adjustment:staff:101',
        new DateTimeImmutable('2026-07-01 08:00:00')
    );
    $activePopulation = $population->forScope('global', null, new DateTimeImmutable('2026-07-01'));
    $activeStaffIds = array_column($activePopulation['staff'], 'staff_id');

    $assert(
        ($beforeTransfer['assignment_id'] ?? null) === 1
        && ($beforeTransfer['org_unit_id'] ?? null) === 10
        && ($beforeTransfer['group_ids'] ?? null) === [30],
        'dated assignment uses the original unit and group on the final pre-transfer day'
    );
    $assert(
        ($afterTransfer['assignment_id'] ?? null) === 2
        && ($afterTransfer['org_unit_id'] ?? null) === 11
        && ($afterTransfer['job_title_id'] ?? null) === 21
        && ($afterTransfer['group_ids'] ?? null) === [31],
        'dated assignment switches exactly at the transfer boundary and ignores concurrent secondary work'
    );
    $assert(($suspended['employment_status'] ?? null) === 'suspended', 'dated read retains the suspended assignment as history evidence');
    $assert(($rehired['employment_status'] ?? null) === 'rehired' && ($rehired['assignment_id'] ?? null) === 8, 'rehire is effective from its explicit successor date');
    $assert($ambiguousRejected, 'ambiguous concurrent primary assignments fail closed instead of choosing one');
    $assert(($selfActive['allowed'] ?? false) === true && ($selfActive['relationship_version'] ?? null) === 2, 'active worker self access is revalidated against current assignment');
    $assert(($managerBeforeTransfer['allowed'] ?? false) === true && ($managerBeforeTransfer['relationship_version'] ?? null) === 701, 'active direct manager receives current relationship evidence');
    $assert(($managerAfterRelationshipEnd['allowed'] ?? true) === false && ($managerAfterRelationshipEnd['reason'] ?? null) === 'manager_relationship_inactive', 'manager access is revoked on the first request after relationship end');
    $assert(($ended['allowed'] ?? true) === false && ($ended['staff_status'] ?? null) === 'ended', 'service end revokes self access without removing historical assignment rows');
    $assert(($suspendedAccess['allowed'] ?? true) === false && ($suspendedAccess['reason'] ?? null) === 'resource_service_suspended', 'suspension revokes self access immediately');
    $assert(($rehiredAccess['allowed'] ?? false) === true && ($rehiredAccess['staff_status'] ?? null) === 'rehired', 'rehire restores access at its effective date');
    $assert(($multiRoleHr['allowed'] ?? false) === true && ($multiRoleHr['relationship_version'] ?? null) === 501, 'active multi-role admin evidence grants HR scope without a parallel role store');
    $assert(($endedHr['allowed'] ?? true) === false && ($endedHr['reason'] ?? null) === 'actor_service_ended', 'ended worker cannot retain HR scope solely through a still-active role assignment');
    $assert(($inactiveAccount['allowed'] ?? true) === false && ($inactiveAccount['reason'] ?? null) === 'account_inactive', 'inactive account is denied even with an active assignment');
    $assert(($unsupportedCapability['allowed'] ?? true) === false && ($unsupportedCapability['reason'] ?? null) === 'unsupported_capability', 'unrecognized capability cannot become access through a caller-controlled string');
    $assert(
        in_array(101, $activeStaffIds, true)
        && in_array(106, $activeStaffIds, true)
        && !in_array(104, $activeStaffIds, true)
        && !in_array(105, $activeStaffIds, true),
        'active population excludes ended and suspended workers while retaining rehires'
    );
    $assert(($activePopulation['conflicts'][0]['staff_id'] ?? null) === 108, 'population reports rather than masks an ambiguous primary assignment');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR lifecycle/access: PASS\n";
