<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Modules/Staff/Contracts/ManagerHierarchyAtDateQuery.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Organization/PdoManagerHierarchyQuery.php';

use EduCore\Modules\Staff\Infrastructure\Organization\PdoManagerHierarchyQuery;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE staff_assignments (
        id INTEGER PRIMARY KEY,
        staff_user_id INTEGER NOT NULL,
        org_unit_id INTEGER NOT NULL,
        assignment_kind TEXT NOT NULL,
        valid_from TEXT NOT NULL,
        valid_to TEXT NULL
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
    'CREATE TABLE staff_delegations (
        id INTEGER PRIMARY KEY,
        delegator_user_id INTEGER NOT NULL,
        delegate_user_id INTEGER NOT NULL,
        scope_type TEXT NOT NULL,
        scope_id INTEGER NOT NULL,
        request_types TEXT NULL,
        valid_from TEXT NOT NULL,
        valid_to TEXT NOT NULL,
        status TEXT NOT NULL
    )'
);

$db->exec(
    "INSERT INTO staff_assignments VALUES
        (1, 100, 10, 'primary', '2026-01-01', NULL),
        (2, 101, 11, 'primary', '2026-01-01', NULL),
        (3, 102, 12, 'primary', '2026-01-01', NULL),
        (4, 103, 13, 'primary', '2026-01-01', NULL),
        (5, 103, 13, 'primary', '2026-06-01', NULL),
        (6, 104, 14, 'primary', '2026-01-01', NULL),
        (7, 105, 15, 'primary', '2026-01-01', NULL),
        (8, 106, 16, 'primary', '2026-01-01', NULL),
        (9, 107, 17, 'primary', '2026-01-01', NULL),
        (10, 108, 18, 'primary', '2026-01-01', NULL),
        (11, 109, 19, 'primary', '2026-01-01', NULL),
        (12, 110, 20, 'primary', '2026-01-01', NULL),
        (13, 111, 21, 'primary', '2026-01-01', NULL)"
);
$db->exec(
    "INSERT INTO staff_manager_assignments VALUES
        (1, 'staff', 100, 200, 'direct', 10, '2026-01-01', NULL, 'active'),
        (2, 'org_unit', 10, 300, 'direct', 0, '2026-01-01', NULL, 'active'),
        (3, 'org_unit', 10, 400, 'administrative', 0, '2026-01-01', NULL, 'active'),
        (4, 'org_unit', 11, 301, 'direct', 0, '2026-01-01', NULL, 'active'),
        (5, 'org_unit', 14, 500, 'direct', 0, '2026-01-01', NULL, 'active'),
        (6, 'org_unit', 14, 501, 'direct', 0, '2026-01-01', NULL, 'active'),
        (7, 'org_unit', 15, 105, 'direct', 0, '2026-01-01', NULL, 'active'),
        (8, 'org_unit', 16, 600, 'direct', 0, '2026-01-01', NULL, 'active'),
        (9, 'staff', 107, 250, 'direct', 0, '2026-01-01', NULL, 'active'),
        (10, 'org_unit', 17, 350, 'direct', 99, '2026-01-01', NULL, 'active'),
        (11, 'staff', 108, 211, 'direct', 0, '2026-01-01', '2026-06-30', 'active'),
        (12, 'staff', 108, 212, 'direct', 0, '2026-07-01', NULL, 'active'),
        (13, 'org_unit', 19, 610, 'direct', 0, '2026-01-01', NULL, 'active'),
        (14, 'org_unit', 20, 620, 'direct', 0, '2026-01-01', NULL, 'active'),
        (15, 'org_unit', 21, 630, 'direct', 0, '2026-01-01', NULL, 'active')"
);
$db->exec(
    "INSERT INTO staff_policy_group_memberships VALUES
        (1, 90, 111, '2026-01-01', NULL, 'active')"
);
$db->exec(
    "INSERT INTO staff_delegations VALUES
        (1, 600, 700, 'global', 0, NULL, '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active'),
        (2, 600, 701, 'staff', 106, NULL, '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active'),
        (3, 600, 702, 'staff', 106, NULL, '2027-01-01 00:00:00.000000', '2027-12-31 23:59:59.999999', 'active'),
        (4, 610, 720, 'request_type', 1, NULL, '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active'),
        (5, 620, 721, 'staff', 110, NULL, '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active'),
        (6, 620, 722, 'staff', 110, NULL, '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active'),
        (7, 630, 731, 'global', 0, NULL, '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active'),
        (8, 630, 732, 'group', 90, NULL, '2026-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999', 'active')"
);

$query = new PdoManagerHierarchyQuery($db);
$at = new DateTimeImmutable('2026-07-15 09:00:00');
$direct = $query->resolve(100, 'direct', $at);
$administrative = $query->resolve(100, 'administrative', $at);
$fallback = $query->resolve(101, 'direct', $at);
$missing = $query->resolve(102, 'direct', $at);
$assignmentConflict = $query->resolve(103, 'direct', $at);
$managerConflict = $query->resolve(104, 'direct', $at);
$selfManager = $query->resolve(105, 'direct', $at);
$delegated = $query->resolve(106, 'direct', $at);
$staffOverride = $query->resolve(107, 'direct', $at);
$historic = $query->resolve(108, 'direct', new DateTimeImmutable('2026-06-30 23:59:59'));
$successor = $query->resolve(108, 'direct', new DateTimeImmutable('2026-07-01 00:00:00'));
$restrictedDelegation = $query->resolve(109, 'direct', $at);
$delegationConflict = $query->resolve(110, 'direct', $at);
$groupDelegated = $query->resolve(111, 'direct', $at);

$invalidRejected = false;
try {
    $query->resolve(100, 'unknown', $at);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}

$checks = [
    'staff_manager_assignment_wins_over_unit_fallback' => $direct['manager_id'] === 200
        && $direct['assignment_id'] === 1
        && $direct['conflicts'] === [],
    'administrative_kind_resolves_its_own_effective_unit_manager' => $administrative['manager_id'] === 400,
    'unit_fallback_resolves_when_no_staff_specific_manager_exists' => $fallback['manager_id'] === 301
        && $fallback['assignment_id'] === 2,
    'missing_manager_is_an_explicit_non_authoritative_result' => $missing['manager_id'] === null
        && $missing['assignment_id'] === 3
        && $missing['conflicts'] === [],
    'overlapping_primary_assignments_fail_closed' => $assignmentConflict['manager_id'] === null
        && ($assignmentConflict['conflicts'][0]['reason'] ?? null) === 'overlapping_primary_assignments'
        && ($assignmentConflict['conflicts'][0]['assignment_ids'] ?? null) === [4, 5],
    'equal_priority_manager_rows_fail_closed' => $managerConflict['manager_id'] === null
        && ($managerConflict['conflicts'][0]['reason'] ?? null) === 'ambiguous_manager_assignment'
        && ($managerConflict['conflicts'][0]['manager_user_ids'] ?? null) === [500, 501],
    'unit_fallback_cannot_make_the_worker_own_manager' => $selfManager['manager_id'] === null
        && ($selfManager['conflicts'][0]['reason'] ?? null) === 'self_manager_assignment',
    'manager_hierarchy_defers_delegation_until_resource_type_is_known' => $delegated['manager_id'] === 600
        && $delegated['delegation'] === null
        && $delegated['conflicts'] === [],
    'staff_specific_manager_precedes_even_higher_priority_org_manager' => $staffOverride['manager_id'] === 250,
    'dated_manager_boundaries_are_inclusive_then_switch_on_successor_start' => $historic['manager_id'] === 211
        && $successor['manager_id'] === 212,
    'request_type_delegation_does_not_change_the_original_hierarchy' => $restrictedDelegation['manager_id'] === 610
        && $restrictedDelegation['delegation'] === null,
    'delegation_conflicts_do_not_mask_a_valid_manager_hierarchy' => $delegationConflict['manager_id'] === 620
        && $delegationConflict['conflicts'] === [],
    'group_delegation_does_not_change_the_original_hierarchy' => $groupDelegated['manager_id'] === 630
        && $groupDelegated['delegation'] === null,
    'invalid_manager_kind_is_rejected_before_querying_data' => $invalidRejected,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
