<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Staff\Contracts\StaffGroupOverlapQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffGroupOverlapQuery;
use EduCore\Modules\Staff\Infrastructure\PdoApprovalDecisionEvidenceQuery;
use EduCore\Modules\Attendance\Infrastructure\PdoScheduleChangeAuthorization;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};
$attendanceSource = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Attendance/Infrastructure/PdoSchedulePolicyRepository.php');
$authorizationSource = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Attendance/Infrastructure/PdoScheduleChangeAuthorization.php');
$assert(!str_contains($attendanceSource, 'staff_policy_group_memberships'), 'Attendance adapter does not read a Staff-owned table directly');
$assert(str_contains($attendanceSource, 'StaffGroupOverlapQuery'), 'Attendance depends on the Staff-owned overlap contract');
$assert(!str_contains($authorizationSource, 'staff_approval_'), 'Attendance authorization does not query Staff-owned workflow tables directly');
$assert(str_contains($authorizationSource, 'ApprovalDecisionEvidenceQuery'), 'Attendance authorization consumes Staff-owned approval evidence contract');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE staff_policy_group_memberships (
    id INTEGER PRIMARY KEY, group_id INTEGER, staff_user_id INTEGER,
    valid_from TEXT, valid_to TEXT, status TEXT
)');
$db->exec("INSERT INTO staff_policy_group_memberships VALUES
    (1, 10, 501, '2026-01-01', NULL, 'active'),
    (2, 20, 501, '2026-07-01', NULL, 'active'),
    (3, 30, 502, '2026-01-01', '2026-07-31', 'active'),
    (4, 40, 502, '2026-01-01', NULL, 'active')");
$query = new PdoStaffGroupOverlapQuery($db);
$assert($query instanceof StaffGroupOverlapQuery, 'PDO adapter implements Staff group overlap contract');
$assert($query->groupsShareActiveMember(10, 20, new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-09-01')), 'overlapping active membership is detected');
$assert($query->groupsShareActiveMember(10, 20, new DateTimeImmutable('2026-08-01 08:00:00'), new DateTimeImmutable('2026-08-01 12:00:00')), 'inclusive DATE membership intersects a same-day half-open schedule window');
$assert(!$query->groupsShareActiveMember(30, 40, new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-09-01')), 'membership ending before half-open window is excluded');
try {
    $query->groupsShareActiveMember(10, 20, new DateTimeImmutable('2026-08-01 12:00:00'), new DateTimeImmutable('2026-08-01 08:00:00'));
    $assert(false, 'reversed overlap window fails closed');
} catch (InvalidArgumentException $exception) {
    $assert($exception->getMessage() === 'STAFF_GROUP_OVERLAP_RANGE_INVALID', 'reversed overlap window has a stable domain error');
}

$db->exec('CREATE TABLE staff_approval_instances (id INTEGER PRIMARY KEY, resource_type TEXT, resource_id INTEGER, status TEXT)');
$db->exec('CREATE TABLE staff_approval_steps (id INTEGER PRIMARY KEY, instance_id INTEGER)');
$db->exec('CREATE TABLE staff_approval_decisions (id INTEGER PRIMARY KEY, step_id INTEGER, actor_user_id INTEGER, decision TEXT, is_effective INTEGER)');
$db->exec("INSERT INTO staff_approval_instances VALUES (700, 'staff_schedule_change_request', 200, 'approved')");
$db->exec('INSERT INTO staff_approval_steps VALUES (701, 700)');
$db->exec("INSERT INTO staff_approval_decisions VALUES (702, 701, 900, 'approve', 1)");
$authorization = new PdoScheduleChangeAuthorization(new PdoApprovalDecisionEvidenceQuery($db));
$request = ['id' => 200, 'staff_user_id' => 501, 'workflow_instance_id' => 700];
$assert($authorization->canSubmit(501, 501, []), 'self submission is authorized');
$assert(!$authorization->canSubmit(600, 501, []), 'on-behalf submission fails closed');
$assert($authorization->canLinkWorkflow(501, $request, 700), 'matching workflow resource can be linked by the requester');
$assert(!$authorization->canLinkWorkflow(501, $request, 799), 'unrelated workflow evidence cannot be linked');
$assert($authorization->canApprove(900, $request), 'effective workflow approver decision authorizes approval');
$assert(!$authorization->canApprove(901, $request), 'actor without effective workflow decision cannot approve');

exit($failures === 0 ? 0 : 1);
