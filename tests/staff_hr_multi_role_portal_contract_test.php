<?php

declare(strict_types=1);

/** Isolated proof that the worker portal follows Staff identity, not an active role. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/src/Modules/Staff/Contracts/StaffAssignmentAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffAccessEligibilityQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffPortalEligibilityQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffPortalEligibilityReadRepository.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Organization/PdoStaffAssignmentAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Portal/PdoStaffPortalEligibilityReadRepository.php';
require_once $root . '/src/Modules/Staff/Application/Portal/StaffPortalEligibilityService.php';

use EduCore\Modules\Staff\Application\Portal\StaffPortalEligibilityService;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\Portal\PdoStaffPortalEligibilityReadRepository;

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
    $db->exec('CREATE TABLE staff_profiles (user_id INTEGER PRIMARY KEY)');
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
    $db->exec('CREATE TABLE staff_roles (role_key TEXT PRIMARY KEY, role_name TEXT NOT NULL, portal_type TEXT NULL, base_role_key TEXT NULL, status TEXT NOT NULL)');
    $db->exec('CREATE TABLE user_role_assignments (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, role_key TEXT NOT NULL, is_primary INTEGER NOT NULL, status TEXT NOT NULL)');

    $db->exec(
        "INSERT INTO users (id, role, status) VALUES
            (201, 'teacher', 'active'),
            (202, 'specialist', 'active'),
            (203, 'employee', 'active'),
            (204, 'teacher', 'active'),
            (205, 'teacher', 'active'),
            (206, 'teacher', 'active'),
            (207, 'teacher', 'inactive'),
            (208, 'teacher', 'active'),
            (301, 'admin', 'active')"
    );
    $db->exec('INSERT INTO staff_profiles (user_id) VALUES (201), (202), (203), (204), (205), (206), (207), (301)');
    $db->exec(
        "INSERT INTO staff_roles (role_key, role_name, portal_type, base_role_key, status) VALUES
            ('teacher', 'Teacher', 'teacher', NULL, 'active'),
            ('specialist', 'Specialist', 'specialist', NULL, 'active'),
            ('employee', 'Employee', 'employee', NULL, 'active'),
            ('admin', 'Admin', 'admin_like', NULL, 'active')"
    );
    $db->exec(
        "INSERT INTO user_role_assignments (id, user_id, role_key, is_primary, status) VALUES
            (1, 201, 'teacher', 1, 'active'),
            (2, 201, 'specialist', 0, 'active'),
            (3, 202, 'specialist', 1, 'active'),
            (4, 203, 'employee', 1, 'active'),
            (5, 204, 'teacher', 1, 'active'),
            (6, 205, 'teacher', 1, 'active'),
            (7, 206, 'teacher', 1, 'active'),
            (8, 207, 'teacher', 1, 'active'),
            (9, 208, 'teacher', 1, 'active')"
    );
    $db->exec(
        "INSERT INTO staff_assignments
            (id, staff_user_id, org_unit_id, job_title_id, assignment_kind, employment_status, valid_from, valid_to, version)
         VALUES
            (1, 201, 10, 20, 'primary', 'active', '2026-01-01', NULL, 1),
            (2, 202, 10, 21, 'primary', 'active', '2026-01-01', NULL, 1),
            (3, 203, 11, 22, 'primary', 'active', '2026-01-01', NULL, 1),
            (4, 204, 12, 23, 'primary', 'suspended', '2026-01-01', NULL, 1),
            (5, 205, 13, 24, 'primary', 'active', '2026-01-01', '2026-08-08', 1),
            (6, 206, 14, 25, 'primary', 'active', '2026-01-01', NULL, 1),
            (7, 206, 15, 26, 'primary', 'active', '2026-01-01', NULL, 2),
            (8, 207, 16, 27, 'primary', 'active', '2026-01-01', NULL, 1),
            (9, 208, 17, 28, 'primary', 'active', '2026-01-01', NULL, 1)"
    );
    $db->exec(
        "INSERT INTO staff_manager_assignments
            (id, subject_type, subject_id, manager_user_id, manager_kind, priority, valid_from, valid_to, status)
         VALUES
            (801, 'staff', 202, 201, 'direct', 0, '2026-01-01', '2026-08-31', 'active'),
            (802, 'org_unit', 10, 201, 'direct', 0, '2026-09-01', '2026-09-30', 'active'),
            (803, 'staff', 202, 301, 'direct', 0, '2026-09-15', NULL, 'active')"
    );

    $service = new StaffPortalEligibilityService(
        new PdoStaffPortalEligibilityReadRepository($db),
        new PdoStaffAssignmentAtDateQuery($db)
    );

    $august = new DateTimeImmutable('2026-08-09 08:00:00');
    $multiRoleManager = $service->forUser(201, $august);
    $employeeRole = $service->forUser(203, $august);
    $suspended = $service->forUser(204, $august);
    $ended = $service->forUser(205, $august);
    $ambiguous = $service->forUser(206, $august);
    $inactiveAccount = $service->forUser(207, $august);
    $missingProfile = $service->forUser(208, $august);
    $organizationalManager = $service->forUser(201, new DateTimeImmutable('2026-09-01 08:00:00'));
    $staffSpecificOverride = $service->forUser(201, new DateTimeImmutable('2026-09-15 08:00:00'));

    $serviceSource = (string) file_get_contents($root . '/src/Modules/Staff/Application/Portal/StaffPortalEligibilityService.php');

    $assert(
        ($multiRoleManager['eligible'] ?? false) === true
        && ($multiRoleManager['staff_id'] ?? null) === 201
        && ($multiRoleManager['active_assignment_id'] ?? null) === 1
        && ($multiRoleManager['capabilities'] ?? []) === [
            'staff.portal.self_service',
            'staff.portal.manager_approval_inbox',
        ],
        'a teacher/specialist account receives worker self-service once and the effective direct-manager inbox'
    );
    $assert(
        ($employeeRole['eligible'] ?? false) === true
        && ($employeeRole['capabilities'] ?? []) === ['staff.portal.self_service'],
        'self-service eligibility is based on the active Staff identity, not on adding or switching to an employee role'
    );
    $assert(
        !str_contains($serviceSource, 'active_role')
        && !str_contains($serviceSource, 'StaffActiveRoleService'),
        'the eligibility adapter does not read the browser-selected active role'
    );
    $assert(
        ($suspended['eligible'] ?? true) === false
        && ($ended['eligible'] ?? true) === false
        && ($ambiguous['eligible'] ?? true) === false,
        'suspension, service end, and concurrent primary-assignment ambiguity fail closed'
    );
    $assert(
        ($inactiveAccount['eligible'] ?? true) === false
        && ($missingProfile['eligible'] ?? true) === false,
        'a disabled account or missing Staff profile cannot obtain worker portal eligibility'
    );
    $assert(
        ($organizationalManager['capabilities'] ?? []) === [
            'staff.portal.self_service',
            'staff.portal.manager_approval_inbox',
        ],
        'an organizational manager scope grants the inbox only after the Staff-specific scope expires'
    );
    $assert(
        ($staffSpecificOverride['capabilities'] ?? []) === ['staff.portal.self_service'],
        'a later Staff-specific manager assignment removes the organization-level inbox affordance for that target'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR multi-role portal eligibility: PASS\n";
