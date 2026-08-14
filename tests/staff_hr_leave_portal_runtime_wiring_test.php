<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';
require_once $root . '/src/Modules/Attendance/bootstrap.php';

use EduCore\Modules\Attendance\Contracts\LeaveWorkdayCalendarQuery;
use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Application\Leave\LeaveRequestService;
use EduCore\Modules\Staff\Application\Leave\LeaveAttachmentService;
use EduCore\Modules\Staff\Application\Portal\StaffLeaveSelfServiceAuthorization;
use EduCore\Modules\Staff\Application\Portal\StaffSelfServicePortalQuery;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityQuery;
use EduCore\Modules\Staff\Contracts\StaffSelfServicePortalReadRepository;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;
use EduCore\Modules\Staff\Presentation\StaffSelfServiceRequests;

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
        return ['eligible' => $this->enabled, 'staff_id' => $userId,
            'capabilities' => $this->enabled ? ['staff.portal.self_service'] : []];
    }
};
$authorization = new StaffLeaveSelfServiceAuthorization($eligibility);
$now = new DateTimeImmutable('2026-08-11 08:00:00');
try {
    $authorization->assertCanAct(41, 41, 'create_draft', $now);
    $assert(true, 'eligible worker can manage their own leave');
} catch (Throwable) {
    $assert(false, 'eligible worker can manage their own leave');
}
try {
    $authorization->assertCanAct(41, 42, 'create_draft', $now);
    $assert(false, 'cross-worker leave access is rejected');
} catch (DomainException $exception) {
    $assert($exception->getMessage() === 'LEAVE_REQUEST_OWNER_ONLY', 'leave IDOR uses the owner-only code');
}

$readRepository = new class implements StaffSelfServicePortalReadRepository {
    public function activeLeaveTypes(): array
    {
        return [['id' => 3, 'name' => 'إجازة اعتيادية', 'unit' => 'day',
            'requires_reason' => 0, 'requires_attachment' => 0, 'requires_medical_document' => 0]];
    }
    public function leaveRequestsForStaff(int $staffUserId, int $limit): array
    {
        return [['id' => 71, 'lock_version' => 1, 'type_name' => 'إجازة مرضية',
            'from_at' => '2026-09-10 08:00:00', 'to_at' => '2026-09-10 16:00:00',
            'requested_units' => '1.000', 'requested_minutes' => 480, 'status' => 'draft',
            'workflow_instance_id' => null, 'supporting_document_ref' => null,
            'requires_attachment' => 1, 'requires_medical_document' => 1]];
    }
    public function leaveBalanceAccountsForStaff(int $staffUserId): array
    {
        return [['type_name' => 'إجازة اعتيادية', 'entitlement_period_key' => 'CY-2026',
            'available_units' => '18.000', 'reserved_units' => '1.000', 'consumed_units' => '2.000']];
    }
    public function activePermissionTypes(): array { return []; }
    public function permissionRequestsForStaff(int $staffUserId, int $limit): array { return []; }
    public function permissionQuotaAccountsForStaff(int $staffUserId, string $periodKey): array { return []; }
};
$view = (new StaffSelfServicePortalQuery($readRepository))->leaveForStaff(41);
$html = StaffSelfServiceRequests::renderLeavePortal($view + [
    'csrf_token' => 'csrf-test', 'draft_scope' => '41',
    'create_idempotency_key' => 'leave-create-test',
    'submission_idempotency_key' => 'leave-submit-test',
    'action_url' => 'staff_hr_portal.php', 'timezone' => 'Africa/Cairo',
]);
$assert(str_contains($html, 'إجازة اعتيادية'), 'leave type and own request render');
$assert(str_contains($html, 'leave_request_intent'), 'leave actions retain the dedicated server intent');
$assert(str_contains($html, 'staffLeaveAttachmentModal-71') && str_contains($html, 'إرفاق المستند'), 'medical draft exposes its private upload action and required state');
$assert(!str_contains($html, 'name="staff_user_id"'), 'leave forms cannot select another worker');

$portalSource = (string) file_get_contents($root . '/staff_hr_portal.php');
$factorySource = (string) file_get_contents($root . '/src/Modules/Staff/Infrastructure/StaffModuleFactory.php');
$assert(str_contains($portalSource, "leave_request_intent"), 'shared route handles leave intents');
$assert(str_contains($portalSource, "'supporting_document_ref' => null"), 'browser cannot forge private evidence references');
$assert(str_contains($portalSource, "'upload_medical_attachment'"), 'shared route handles the private medical upload intent');
$assert(str_contains($portalSource, "'file' => \$_FILES['file'] ?? null"), 'shared route passes only the authenticated upload payload to the attachment service');
$assert(str_contains($portalSource, 'leaveWorkdayCalendar()'), 'leave allocation consumes Attendance through its calendar contract');
$assert(str_contains($factorySource, 'function leaveRequests('), 'Staff composition root exposes the leave lifecycle');
$assert(str_contains($factorySource, 'function leaveAttachments()'), 'Staff composition root exposes the private attachment lifecycle');
$assert(str_contains($factorySource, 'new PdoLeavePolicyReadRepository('), 'effective leave policies are composed behind a Staff contract');

try {
    $db = new PDO('sqlite::memory:');
    $calendar = new class implements LeaveWorkdayCalendarQuery {
        public function daysIntersecting(int $staffId, DateTimeImmutable $fromAt, DateTimeImmutable $toAt, DateTimeZone $requestTimezone): array
        {
            return [];
        }
    };
    $coverage = new class implements AttendanceCoverageChangeGateway {
        public function publish(array $event): array { return ['status' => 'ready']; }
    };
    $service = (new StaffModuleFactory($db, new AuditService($db)))->leaveRequests($calendar, $coverage);
    $assert($service instanceof LeaveRequestService, 'factory resolves all leave runtime dependencies');
    $attachmentService = (new StaffModuleFactory($db, new AuditService($db)))->leaveAttachments();
    $assert($attachmentService instanceof LeaveAttachmentService, 'factory resolves private attachment runtime dependencies');
} catch (Throwable $exception) {
    $assert(false, 'factory leave composition failed: ' . $exception->getMessage());
}

if ($failures > 0) {
    exit(1);
}
echo "Staff HR leave portal runtime wiring: PASS\n";
