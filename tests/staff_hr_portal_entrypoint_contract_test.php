<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$portal = (string) file_get_contents($root . '/staff_hr_portal.php');
$teacher = (string) file_get_contents($root . '/teacher/portal.php');
$specialist = (string) file_get_contents($root . '/admin/specialist_dashboard.php');
$supervisor = (string) file_get_contents($root . '/supervisor/index.php');
$admin = (string) file_get_contents($root . '/admin/index.php');

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$authPosition = strpos($portal, 'Utilities::validateSession();');
$postPosition = strpos($portal, "\$_SERVER['REQUEST_METHOD'] === 'POST'");
$assert($authPosition !== false && $postPosition !== false && $authPosition < $postPosition, 'shared portal authenticates before POST handling');
$assert(str_contains($portal, "\$actorId = (int) (\$_SESSION['user_id'] ?? 0)"), 'worker identity is derived from the authenticated session');
$assert(!str_contains($portal, "\$_POST['staff_user_id']"), 'the entrypoint never accepts a mutable worker identifier');
$assert(str_contains($portal, 'hash_equals($csrfToken, $postedToken)'), 'every POST is protected by timing-safe CSRF verification');
$assert(str_contains($portal, 'portalEligibility()->forUser($actorId'), 'Staff eligibility is rechecked independently of the active portal role');
$assert(str_contains($portal, '$canUseManagerInbox = $portalError === null;')
    && str_contains($portal, 'assignedApprovalInbox()->forAssignee'), 'assigned inbox is available to named assignees and remains query-scoped');
$assert(str_contains($portal, 'assignedApprovalInbox()->forAssignee($actorId'), 'manager data is queried only for the authenticated assignee');
$assert(str_contains($portal, 'StaffSelfServiceRequests::renderPortal'), 'shared permission component is wired');
$assert(str_contains($portal, 'StaffSelfServiceRequests::renderLeavePortal'), 'shared leave component is wired');
$assert(str_contains($portal, 'ErtaqPortal::renderWorkerConversation'), 'shared worker Ertaq component is wired');
$assert(substr_count($portal, "'draft_scope' => (string) \$actorId") >= 3, 'permission, leave, and Ertaq drafts are scoped to the authenticated worker');
$assert(str_contains($portal, 'ManagerApprovalInbox::renderInbox'), 'shared manager inbox component is wired');
$assert(str_contains($portal, 'lang="ar" dir="rtl"'), 'shared portal is explicitly Arabic RTL');
$assert(str_contains($portal, "http_response_code(403)"), 'ineligible and invalid-CSRF access fails closed');

foreach ([$teacher, $specialist, $supervisor, $admin] as $source) {
    $assert(str_contains($source, 'staff_hr_portal.php'), 'each existing staff-facing portal exposes the shared Staff HR route');
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR portal entrypoint contract: PASS\n";
