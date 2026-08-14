<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Modules/Staff/Contracts/StaffPopulationAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffAssignmentAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/PdoStaffPopulationAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/PdoStaffAssignmentAtDateQuery.php';

use EduCore\Modules\Staff\Infrastructure\PdoStaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffPopulationAtDateQuery;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE staff_assignments (
        id INTEGER PRIMARY KEY,
        staff_user_id INTEGER NOT NULL,
        org_unit_id INTEGER NOT NULL,
        job_title_id INTEGER NOT NULL,
        assignment_kind TEXT NOT NULL,
        employment_status TEXT NOT NULL,
        valid_from TEXT NOT NULL,
        valid_to TEXT NULL
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
    "INSERT INTO staff_assignments VALUES
        (1, 101, 10, 20, 'primary', 'active', '2026-01-01', NULL),
        (2, 102, 11, 20, 'primary', 'active', '2026-01-01', NULL),
        (3, 103, 10, 21, 'primary', 'active', '2026-01-01', NULL),
        (4, 103, 10, 21, 'primary', 'active', '2026-06-01', NULL),
        (5, 104, 10, 20, 'secondary', 'active', '2026-01-01', NULL)"
);
$db->exec(
    "INSERT INTO staff_policy_group_memberships VALUES
        (1, 30, 101, '2026-01-01', NULL, 'active'),
        (2, 30, 102, '2026-01-01', '2026-03-31', 'active'),
        (3, 31, 102, '2026-04-01', NULL, 'active')"
);

$query = new PdoStaffPopulationAtDateQuery($db);
$at = new DateTimeImmutable('2026-07-01');
$global = $query->forScope('global', null, $at);
$org = $query->forScope('org_unit', 10, $at);
$group = $query->forScope('group', 30, $at);
$staff = $query->forScope('staff', 102, $at);
$assignmentQuery = new PdoStaffAssignmentAtDateQuery($db, $query);
$assignment = $assignmentQuery->forStaff(102, $at);
$assignmentConflictRejected = false;
try {
    $assignmentQuery->forStaff(103, $at);
} catch (DomainException $exception) {
    $assignmentConflictRejected = str_starts_with(
        $exception->getMessage(),
        'AMBIGUOUS_STAFF_ASSIGNMENT:'
    );
}

$invalidRejected = false;
try {
    $query->forScope('group', null, $at);
} catch (InvalidArgumentException $exception) {
    $invalidRejected = true;
}

$checks = [
    'global_returns_unambiguous_primary_assignments' => array_column($global['staff'], 'staff_id') === [101, 102],
    'overlapping_primary_assignments_fail_closed' => ($global['conflicts'][0]['staff_id'] ?? null) === 103
        && ($global['conflicts'][0]['assignment_ids'] ?? null) === [3, 4],
    'organization_scope_filters_inside_staff_owner' => array_column($org['staff'], 'staff_id') === [101],
    'dated_group_membership_is_honored' => array_column($group['staff'], 'staff_id') === [101],
    'assignment_snapshot_contains_effective_groups' => ($staff['staff'][0]['group_ids'] ?? null) === [31],
    'single_assignment_adapter_uses_population_contract' => ($assignment['assignment_id'] ?? null) === 2
        && ($assignment['group_ids'] ?? null) === [31],
    'single_assignment_adapter_rejects_ambiguity' => $assignmentConflictRejected,
    'secondary_assignments_do_not_duplicate_population' => !in_array(104, array_column($global['staff'], 'staff_id'), true),
    'invalid_scope_fails_closed' => $invalidRejected,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
