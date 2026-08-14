<?php

declare(strict_types=1);

/** Isolated read/authorization and static web-boundary proof for HR organization administration. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Staff\Application\Organization\StaffOrganizationAdministrationQuery;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffOrganizationAdministrationReadRepository;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffOrganizationRepository;

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
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, role TEXT, status TEXT NOT NULL)');
    $db->exec('CREATE TABLE user_role_assignments (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, role_key TEXT NOT NULL, status TEXT NOT NULL)');
    $db->exec('CREATE TABLE staff_profiles (user_id INTEGER PRIMARY KEY)');
    $db->exec('CREATE TABLE staff_org_units (id INTEGER PRIMARY KEY, code TEXT, name TEXT, unit_type TEXT, parent_id INTEGER, valid_from TEXT, valid_to TEXT, status TEXT)');
    $db->exec('CREATE TABLE staff_job_titles (id INTEGER PRIMARY KEY, code TEXT, name TEXT, active_from TEXT, active_to TEXT, status TEXT)');
    $db->exec('CREATE TABLE staff_policy_groups (id INTEGER PRIMARY KEY, code TEXT, name TEXT, purpose TEXT, valid_from TEXT, valid_to TEXT, status TEXT)');
    $db->exec('CREATE TABLE staff_policy_group_memberships (id INTEGER PRIMARY KEY, group_id INTEGER, staff_user_id INTEGER, valid_from TEXT, valid_to TEXT, status TEXT)');
    $db->exec('CREATE TABLE staff_manager_assignments (id INTEGER PRIMARY KEY, subject_type TEXT, subject_id INTEGER, manager_user_id INTEGER, manager_kind TEXT, priority INTEGER, valid_from TEXT, valid_to TEXT, status TEXT)');
    $db->exec('CREATE TABLE staff_assignments (id INTEGER PRIMARY KEY, staff_user_id INTEGER, org_unit_id INTEGER, job_title_id INTEGER, assignment_kind TEXT, employment_status TEXT, work_fraction TEXT, valid_from TEXT, valid_to TEXT, version INTEGER)');
    $db->exec("INSERT INTO users (id, name, role, status) VALUES
        (1, 'مسؤول الموارد', 'admin', 'active'),
        (2, 'معلم أول', 'teacher', 'active'),
        (3, 'مدير المدرسة', 'teacher', 'active'),
        (4, 'حساب غير مخول', 'teacher', 'active')");
    $db->exec("INSERT INTO user_role_assignments (id, user_id, role_key, status) VALUES (1, 3, 'super_admin', 'active')");
    $db->exec('INSERT INTO staff_profiles (user_id) VALUES (2), (3), (4)');
    $db->exec("INSERT INTO staff_org_units (id, code, name, unit_type, parent_id, valid_from, valid_to, status) VALUES (10, 'ACADEMIC', 'القوة الأكاديمية', 'workforce', NULL, '2026-01-01', NULL, 'active')");
    $db->exec("INSERT INTO staff_job_titles (id, code, name, active_from, active_to, status) VALUES (20, 'TEACHER', 'معلم', '2026-01-01', NULL, 'active')");
    $db->exec("INSERT INTO staff_policy_groups (id, code, name, purpose, valid_from, valid_to, status) VALUES (30, 'MORNING', 'مجموعة الصباح', 'اختبار', '2026-01-01', NULL, 'active')");
    $db->exec("INSERT INTO staff_policy_group_memberships (id, group_id, staff_user_id, valid_from, valid_to, status) VALUES (40, 30, 2, '2026-01-01', NULL, 'active')");
    $db->exec("INSERT INTO staff_manager_assignments (id, subject_type, subject_id, manager_user_id, manager_kind, priority, valid_from, valid_to, status) VALUES (50, 'staff', 2, 3, 'direct', 0, '2026-01-01', NULL, 'active')");
    $db->exec("INSERT INTO staff_assignments (id, staff_user_id, org_unit_id, job_title_id, assignment_kind, employment_status, work_fraction, valid_from, valid_to, version) VALUES (60, 2, 10, 20, 'primary', 'active', '1.0000', '2026-01-01', NULL, 1)");

    $query = new StaffOrganizationAdministrationQuery(
        new PdoStaffOrganizationRepository($db),
        new PdoStaffOrganizationAdministrationReadRepository($db)
    );
    $dashboard = $query->forAdministrator(1, 10);
    $assert(
        ($dashboard['org_units'][0]['name'] ?? null) === 'القوة الأكاديمية'
        && ($dashboard['job_titles'][0]['name'] ?? null) === 'معلم'
        && ($dashboard['policy_groups'][0]['name'] ?? null) === 'مجموعة الصباح'
        && ($dashboard['group_memberships'][0]['staff_name'] ?? null) === 'معلم أول'
        && ($dashboard['manager_assignments'][0]['manager_name'] ?? null) === 'مدير المدرسة'
        && ($dashboard['assignments'][0]['job_title_name'] ?? null) === 'معلم',
        'the Staff-owned administration query composes the form references and history lists'
    );
    $assert(
        !array_key_exists('title', $dashboard['assignments'][0] ?? [])
        && !array_key_exists('payload_hash', $dashboard['assignments'][0] ?? []),
        'the organization dashboard read model omits unrelated sensitive resource fields'
    );
    $superAdminDashboard = $query->forAdministrator(3, 1);
    $assert(count($superAdminDashboard['staff']) === 3, 'current assigned super-admin evidence may read the same management surface');

    $denied = false;
    try {
        $query->forAdministrator(4);
    } catch (DomainException $exception) {
        $denied = $exception->getMessage() === 'STAFF_ORG_ACTOR_FORBIDDEN';
    }
    $assert($denied, 'an unrelated active account cannot read organization administration data');

    $invalidLimit = false;
    try {
        $query->forAdministrator(1, 201);
    } catch (InvalidArgumentException $exception) {
        $invalidLimit = $exception->getMessage() === 'STAFF_ORG_READ_LIMIT_INVALID';
    }
    $assert($invalidLimit, 'read limits are bounded before querying all organization resources');

    $surface = (string) file_get_contents($root . '/admin/hr_organization.php');
    $authAt = strpos($surface, "Utilities::validateSession('admin')");
    $dbAt = strpos($surface, '$database = new Database()');
    $assert(
        $authAt !== false && $dbAt !== false && $authAt < $dbAt,
        'organization page validates the admin session before database initialization'
    );
    $assert(
        str_contains($surface, "hash_equals((string) (\$_SESSION['csrf_token'] ?? ''), \$csrfToken)")
        && str_contains($surface, "header('Location: hr_organization.php')")
        && str_contains($surface, '$factory->organizationAdministration()')
        && str_contains($surface, '$factory->organizationAdministrationRead()'),
        'organization page uses CSRF, PRG, and only the Staff factory command/read boundaries'
    );
    $assert(
        !str_contains($surface, 'CREATE TABLE')
        && !str_contains($surface, '->prepare(')
        && !str_contains($surface, 'confirm(')
        && !str_contains($surface, 'Swal.'),
        'organization page has no runtime DDL/direct SQL or legacy browser confirmations'
    );
    foreach (['create_unit', 'create_job_title', 'create_group', 'add_group_member', 'assign_manager', 'create_assignment'] as $action) {
        $assert(str_contains($surface, 'name="action" value="' . $action . '"'), 'page exposes the reviewed action form: ' . $action);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR organization administration surface: PASS\n";
