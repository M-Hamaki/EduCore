<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffPortalEligibilityQuery.php';
require_once $root . '/src/Modules/Staff/Contracts/PermissionRequestAuthorization.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffSelfServicePortalReadRepository.php';
require_once $root . '/src/Modules/Staff/Application/Portal/StaffSelfServiceAuthorization.php';
require_once $root . '/src/Modules/Staff/Application/Portal/StaffSelfServicePortalQuery.php';
require_once $root . '/src/Modules/Staff/Presentation/self_service_requests.php';

use EduCore\Modules\Staff\Application\Portal\StaffSelfServiceAuthorization;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityQuery;
use EduCore\Modules\Staff\Contracts\StaffSelfServicePortalReadRepository;
use EduCore\Modules\Staff\Application\Portal\StaffSelfServicePortalQuery;
use EduCore\Modules\Staff\Presentation\StaffSelfServiceRequests;
use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;
use EduCore\Modules\Staff\Application\Permission\PermissionRequestService;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$eligibility = new class implements StaffPortalEligibilityQuery {
    public bool $enabled = true;

    public function forUser(int $userId, DateTimeImmutable $atInstant): array
    {
        return [
            'eligible' => $this->enabled,
            'staff_id' => $userId,
            'capabilities' => $this->enabled ? ['staff.portal.self_service'] : [],
        ];
    }
};

$authorization = new StaffSelfServiceAuthorization($eligibility);
$now = new DateTimeImmutable('2026-08-11 08:00:00');

try {
    $authorization->assertCanAct(41, 41, 'create_draft', $now);
    $assert(true, 'eligible worker can act on their own permission request');
} catch (Throwable) {
    $assert(false, 'eligible worker can act on their own permission request');
}

try {
    $authorization->assertCanAct(41, 42, 'create_draft', $now);
    $assert(false, 'cross-worker permission access is rejected');
} catch (DomainException $exception) {
    $assert($exception->getMessage() === 'PERMISSION_REQUEST_OWNER_ONLY', 'cross-worker access uses the stable owner-only code');
}

$eligibility->enabled = false;
try {
    $authorization->assertCanAct(41, 41, 'create_draft', $now);
    $assert(false, 'ineligible employment state fails closed');
} catch (DomainException $exception) {
    $assert($exception->getMessage() === 'PERMISSION_REQUEST_FORBIDDEN', 'ineligible employment state uses a safe denial code');
}

$assert($authorization->canOverrideQuota(41, 41, 1, $now) === false, 'worker portal never grants quota override');

$readRepository = new class implements StaffSelfServicePortalReadRepository {
    public function activeLeaveTypes(): array { return []; }
    public function leaveRequestsForStaff(int $staffUserId, int $limit): array { return []; }
    public function leaveBalanceAccountsForStaff(int $staffUserId): array { return []; }

    public function activePermissionTypes(): array
    {
        return [[
            'id' => 7,
            'name' => 'حضور متأخر',
            'requires_reason' => 1,
            'requires_custom_label' => 0,
            'requires_attachment' => 0,
        ]];
    }

    public function permissionRequestsForStaff(int $staffUserId, int $limit): array
    {
        return [[
            'id' => 91,
            'lock_version' => 2,
            'type_name' => 'حضور متأخر',
            'custom_label' => null,
            'from_at' => '2026-08-12 07:30:00',
            'to_at' => '2026-08-12 09:30:00',
            'requested_minutes' => 120,
            'status' => 'pending_approval',
            'workflow_instance_id' => 33,
        ]];
    }

    public function permissionQuotaAccountsForStaff(int $staffUserId, string $periodKey): array
    {
        return [[
            'type_name' => 'حضور متأخر',
            'reserved_count' => 1,
            'consumed_count' => 0,
            'reserved_minutes' => 120,
            'consumed_minutes' => 0,
            'max_requests_per_month' => 3,
            'max_minutes_per_month' => 360,
        ]];
    }
};
$portalView = (new StaffSelfServicePortalQuery($readRepository))->forStaff(41, '2026-08');
$html = StaffSelfServiceRequests::renderPortal($portalView + [
    'csrf_token' => 'csrf-test',
    'draft_scope' => '41',
    'create_idempotency_key' => 'create-test',
    'submission_idempotency_key' => 'submit-test',
    'action_url' => 'staff_hr_portal.php',
    'timezone' => 'Africa/Cairo',
]);
$assert(
    str_contains($html, 'حضور متأخر')
    && str_contains($html, 'مسار الاعتماد #33')
    && str_contains($html, 'المتاح:'),
    'read model renders the worker permission type, request, and quota'
);
$assert(!str_contains($html, 'name="staff_user_id"'), 'rendered permission forms do not expose a mutable worker id');

$factorySource = (string) file_get_contents($root . '/src/Modules/Staff/Infrastructure/StaffModuleFactory.php');
$portalSource = (string) file_get_contents($root . '/staff_hr_portal.php');
$querySource = (string) file_get_contents($root . '/src/Modules/Staff/Application/Portal/StaffSelfServicePortalQuery.php');

$assert(str_contains($factorySource, 'function permissionRequests('), 'Staff factory composes the modern permission request owner');
$assert(str_contains($factorySource, 'new PermissionRequestService('), 'factory returns the audited PermissionRequestService');
$assert(str_contains($portalSource, "permission_request_intent"), 'shared portal routes permission form intents');
$assert(str_contains($portalSource, '->createDraft(') && str_contains($portalSource, '->submit('), 'new request can be drafted and submitted through the application service');
$assert(str_contains($portalSource, 'permissionPortal()->forStaff($actorId'), 'portal reads only the authenticated worker view');
$assert(!str_contains($portalSource, "\$_POST['staff_user_id']"), 'runtime wiring never trusts a posted worker id');
$assert(!str_contains($querySource, 'PDO'), 'application query remains independent from PDO');

try {
    $compositionDb = new PDO('sqlite::memory:');
    $coverage = new class implements AttendanceCoverageChangeGateway {
        public function publish(array $event): array
        {
            return ['status' => 'ready'];
        }
    };
    $composedService = (new StaffModuleFactory($compositionDb, new AuditService($compositionDb)))
        ->permissionRequests($coverage);
    $assert($composedService instanceof PermissionRequestService, 'factory composition resolves every permission runtime dependency');
} catch (Throwable $exception) {
    $assert(false, 'factory permission composition failed: ' . $exception->getMessage());
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR permission portal runtime wiring: PASS\n";
