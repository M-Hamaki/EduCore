<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$portal = (string) file_get_contents($root . '/staff_hr_portal.php');
$factory = (string) file_get_contents($root . '/src/Modules/Staff/Infrastructure/StaffModuleFactory.php');
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$assert(str_contains($portal, "approval_intent"), 'shared portal handles approval decisions');
$assert(str_contains($portal, "'actor_id' => \$actorId"), 'decision actor comes from the authenticated session');
$assert(!str_contains($portal, "\$_POST['actor_id']"), 'manager cannot post another decision actor');
$assert(str_contains($portal, "'decided_at' => new DateTimeImmutable('now')"), 'decision time is server-owned');
$assert(str_contains($portal, 'approvalWorkflowService(') && str_contains($portal, ')->decide(['), 'decision uses the audited approval application service');
$assert(str_contains($portal, "!\$canUseManagerInbox"), 'decision route checks current manager capability');
$assert(str_contains($portal, "'approve' => \"approval:approve:"), 'inbox exposes an idempotent approve action');
$assert(str_contains($portal, "'reject' => \"approval:reject:"), 'inbox exposes an idempotent reject action');
$assert(str_contains($factory, 'new PdoApprovalActorEligibilityQuery('), 'approval service rechecks live actor eligibility');
$assert(str_contains($factory, 'new PdoApprovalDelegationRevalidationQuery('), 'approval service revalidates delegation at decision time');

if ($failures > 0) {
    exit(1);
}
echo "Staff HR manager decision runtime wiring: PASS\n";
