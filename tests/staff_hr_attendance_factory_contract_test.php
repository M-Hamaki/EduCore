<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\EffectiveScheduleQueryService;
use EduCore\Modules\Attendance\Application\AlternativeAttendanceRecorder;
use EduCore\Modules\Attendance\Application\AttendanceAdjustmentService;
use EduCore\Modules\Attendance\Application\AttendanceExceptionQueryService;
use EduCore\Modules\Attendance\Application\AttendanceShadowRunService;
use EduCore\Modules\Attendance\Application\AttendanceReportQueryService;
use EduCore\Modules\Attendance\Application\AttendanceReportProjector;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceAuthorization;
use EduCore\Modules\Attendance\Contracts\AttendanceAdjustmentAuthorization;
use EduCore\Modules\Attendance\Application\LegacyStaffShiftCompatibilityService;
use EduCore\Modules\Attendance\Application\SchedulePolicyAdminQueryService;
use EduCore\Modules\Attendance\Application\SchedulePolicyCommandService;
use EduCore\Modules\Attendance\Application\SchedulePolicyImpactQuery;
use EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory;
use EduCore\Modules\Attendance\Infrastructure\OperationsAuditLegacyStaffShiftWriter;
use EduCore\Modules\Attendance\Infrastructure\PdoSchedulePolicyRepository;
use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Infrastructure\PdoStaffPopulationAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffAccessEligibilityQuery;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$readPrivate = static function (object $object, string $property): mixed {
    $reflection = new ReflectionProperty($object, $property);
    $reflection->setAccessible(true);
    return $reflection->getValue($object);
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$audit = new AuditService($pdo);
$factory = new AttendanceModuleFactory($pdo, $audit);

$command = $factory->schedulePolicyCommand();
$adminQuery = $factory->schedulePolicyAdminQuery();
$effective = $factory->effectiveSchedule();
$impact = $factory->schedulePolicyImpact();
$legacy = $factory->legacyStaffShiftCompatibility();
$access = new class implements StaffAccessEligibilityQuery {
    public function assertCurrentAccess(
        int $userId,
        string $capability,
        string $resourceRef,
        DateTimeImmutable $atInstant
    ): array {
        return ['allowed' => true, 'staff_status' => 'active', 'relationship_version' => null, 'reason' => 'test'];
    }
};
$alternativeAuthorization = $factory->alternativeAttendanceAuthorization($access);
$alternative = $factory->alternativeAttendanceRecorder($alternativeAuthorization);
$adjustmentAuthorization = $factory->attendanceAdjustmentAuthorization($access);
$adjustments = $factory->attendanceAdjustmentService($adjustmentAuthorization);
$exceptions = $factory->attendanceExceptionQuery();
$shadow = $factory->attendanceShadowRunService();
$reportQuery = $factory->attendanceReportQuery();
$reportProjector = $factory->attendanceReportProjector();

$assert($command instanceof SchedulePolicyCommandService, 'factory composes the audited schedule command owner');
$assert($adminQuery instanceof SchedulePolicyAdminQueryService, 'factory composes the administration read model');
$assert($effective instanceof EffectiveScheduleQueryService, 'factory composes effective schedule resolution');
$assert($impact instanceof SchedulePolicyImpactQuery, 'factory composes the cross-module impact preview');
$assert($legacy instanceof LegacyStaffShiftCompatibilityService, 'factory composes the legacy shift compatibility service');
$assert($alternativeAuthorization instanceof AlternativeAttendanceAuthorization, 'factory composes a Staff-owned access adapter for alternative evidence');
$assert($alternative instanceof AlternativeAttendanceRecorder, 'factory composes the alternative attendance recorder');
$assert($adjustmentAuthorization instanceof AttendanceAdjustmentAuthorization, 'factory composes the Staff-owned correction authorization adapter');
$assert($adjustments instanceof AttendanceAdjustmentService, 'factory composes the versioned attendance correction workflow');
$assert($exceptions instanceof AttendanceExceptionQueryService, 'factory composes the read-only attendance exception review model');
$assert($shadow instanceof AttendanceShadowRunService, 'factory composes the non-official shadow comparison workflow');
$assert($reportQuery instanceof AttendanceReportQueryService, 'factory composes the official attendance report query boundary');
$assert($reportProjector instanceof AttendanceReportProjector, 'factory composes the audited report projection owner');

$scheduleRepository = $readPrivate($factory, 'schedules');
$assert($scheduleRepository instanceof PdoSchedulePolicyRepository, 'one PDO schedule repository is owned by the composition root');
$assert($readPrivate($command, 'repository') === $scheduleRepository, 'command and factory share the schedule repository');
$assert($readPrivate($adminQuery, 'repository') === $scheduleRepository, 'admin query and command share the same repository boundary');
$assert($readPrivate($impact, 'schedules') === $scheduleRepository, 'impact preview uses the same read repository boundary');
$assert($readPrivate($impact, 'staffPopulation') instanceof PdoStaffPopulationAtDateQuery, 'impact enumeration uses the Staff-owned PDO adapter');
$assert($readPrivate($command, 'audit') === $audit, 'schedule writes consume the shared mandatory audit writer');
$assert($readPrivate($legacy, 'audit') instanceof OperationsAuditLegacyStaffShiftWriter, 'legacy writes retain the undo-aware audit adapter');

$factorySource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Infrastructure/AttendanceModuleFactory.php'
);
$impactSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/SchedulePolicyImpactQuery.php'
);
$alternativeSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/AlternativeAttendanceRecorder.php'
);
$adjustmentSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/AttendanceAdjustmentService.php'
);
$shadowSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/AttendanceShadowRunService.php'
);
$exceptionQuerySource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/AttendanceExceptionQueryService.php'
);
$reportQuerySource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/AttendanceReportQueryService.php'
);
$assert(str_contains($factorySource, 'private AuditService $audit'), 'factory documents its concrete audit dependency at the infrastructure composition root');
$assert(str_contains($factorySource, 'undo-aware insert/update/delete'), 'factory explains why legacy audit needs AuditService');
$assert(!str_contains($impactSource, 'use PDO;'), 'impact application service has no PDO dependency');
$assert(!str_contains($impactSource, '\\Infrastructure\\'), 'impact application service has no infrastructure dependency');
$assert(!str_contains($factorySource, 'function attendanceSchedulePolicyImpactQuery'), 'factory does not introduce a global composition helper');
$assert(!str_contains($alternativeSource, 'use PDO;'), 'alternative attendance application service has no PDO dependency');
$assert(!str_contains($alternativeSource, 'users '), 'alternative attendance application service does not query Staff tables directly');
$assert(!str_contains($adjustmentSource, 'use PDO;'), 'correction application service has no PDO dependency');
$assert(str_contains($adjustmentSource, 'AttendanceAdjustmentRepository'), 'correction application service writes through its owned repository contract');
$assert(!str_contains($shadowSource, 'use PDO;'), 'shadow application service has no PDO dependency');
$assert(str_contains($shadowSource, 'AttendanceShadowRunRepository'), 'shadow application service writes through its owned repository contract');
$assert(!str_contains($exceptionQuerySource, 'use PDO;'), 'exception review application service has no PDO dependency');
$assert(str_contains($exceptionQuerySource, 'AttendanceExceptionQuery'), 'exception review application service reads through its owned contract');
$assert(str_contains($factorySource, 'function attendanceExceptionQuery'), 'factory owns composition of the exception-review read model');
$assert(str_contains($factorySource, 'function attendanceReportQuery'), 'factory owns composition of the official report read model');
$assert(str_contains($factorySource, 'function attendanceReportProjector'), 'factory owns composition of the report projection owner');
$assert(!str_contains($reportQuerySource, 'use PDO;'), 'official report application service has no PDO dependency');
$assert(!str_contains($reportQuerySource, 'staff_assignments'), 'official report application service does not read Staff tables directly');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Attendance module factory contract failure(s).\n");
    exit(1);
}

echo "Attendance module factory contracts passed.\n";
