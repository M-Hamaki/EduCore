<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqConversationService;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqTicketService;
use EduCore\Modules\Staff\Application\Portal\StaffErtaqSelfServicePolicy;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityQuery;
use EduCore\Modules\Staff\Contracts\ErtaqSlaAuthorization;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$eligibility = new class implements StaffPortalEligibilityQuery {
    public function forUser(int $userId, DateTimeImmutable $atInstant): array
    {
        return ['eligible' => true, 'staff_id' => $userId, 'capabilities' => ['staff.portal.self_service']];
    }
};
$policy = new StaffErtaqSelfServicePolicy($eligibility);
$assert($policy instanceof ErtaqSlaAuthorization, 'worker policy supplies fail-closed SLA queue authorization');
$now = new DateTimeImmutable('2026-08-11 08:00:00');
$resolved = $policy->resolveForCreate(41, [
    'type' => 'complaint',
    'requested_confidentiality_level' => 'restricted',
    'requested_priority' => 'normal',
    'requested_risk_level' => 'none',
], $now);
$assert($resolved['confidentiality_level'] === 'restricted', 'ordinary restricted selection is preserved by policy');
$assert($resolved['priority'] === 'normal' && $resolved['risk_level'] === 'none', 'ordinary worker ticket remains ordinary');
$urgent = $policy->resolveForCreate(41, [
    'type' => 'complaint',
    'requested_confidentiality_level' => 'normal',
    'requested_priority' => 'normal',
    'requested_risk_level' => 'immediate',
], $now);
$assert(
    $urgent['confidentiality_level'] === 'highly_restricted'
        && $urgent['priority'] === 'urgent'
        && $urgent['risk_level'] === 'immediate',
    'immediate-risk signal is upgraded by server policy before protected routing'
);
$ticket = ['requester_user_id' => 41, 'status' => 'new'];
$assert(
    $policy->resolveMessageVisibility(41, $ticket, 'requester_message', 'restricted', $now) === 'requester',
    'worker reply visibility is forced server-side'
);
try {
    $policy->assertCanAct(42, 'post_message', $ticket, $now);
    $assert(false, 'another worker cannot reply to the ticket');
} catch (DomainException $exception) {
    $assert($exception->getMessage() === 'ERTAQ_ACCESS_DENIED', 'Ertaq IDOR fails closed');
}

$portalSource = (string) file_get_contents($root . '/staff_hr_portal.php');
$factorySource = (string) file_get_contents($root . '/src/Modules/Staff/Infrastructure/StaffModuleFactory.php');
$assert(str_contains($portalSource, "'requester_user_id' => \$actorId"), 'requester identity comes from the authenticated session');
$assert(!str_contains($portalSource, "\$_POST['requester_user_id']"), 'worker cannot post another requester id');
$assert(str_contains($portalSource, "'message_type' => 'requester_message'"), 'worker cannot post an internal note');
$assert(str_contains($portalSource, "\$_POST['immediate_risk']"), 'worker can signal an immediate risk after the protected route is configured');
$assert(str_contains($portalSource, "'risk_type' => 'immediate_protection'"), 'server fixes the protected risk classification');
$assert(str_contains($portalSource, 'ertaqUrgentRouting()->routeUrgentTicket('), 'immediate ticket and protected routing are wired atomically');
$assert(!str_contains($portalSource, "\$_POST['routed_team_id']"), 'browser cannot choose the protection team');
$assert(str_contains($portalSource, 'ertaqInboxQuery()->forRequester('), 'worker reads only the requester-scoped Ertaq projection');
$assert(str_contains($factorySource, 'function ertaqWorkerTickets('), 'factory composes worker ticket creation');
$assert(str_contains($factorySource, 'function ertaqWorkerConversation('), 'factory composes worker replies');

try {
    $db = new PDO('sqlite::memory:');
    $factory = new StaffModuleFactory($db, new AuditService($db));
    $assert($factory->ertaqWorkerTickets() instanceof ErtaqTicketService, 'Ertaq ticket composition resolves');
    $assert($factory->ertaqWorkerConversation() instanceof ErtaqConversationService, 'Ertaq conversation composition resolves');
} catch (Throwable $exception) {
    $assert(false, 'Ertaq worker composition failed: ' . $exception->getMessage());
}

if ($failures > 0) {
    exit(1);
}
echo "Staff HR Ertaq portal runtime wiring: PASS\n";
