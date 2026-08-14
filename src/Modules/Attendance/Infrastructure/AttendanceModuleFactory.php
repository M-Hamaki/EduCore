<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use EduCore\Modules\Attendance\Application\EffectiveScheduleQueryService;
use EduCore\Modules\Attendance\Application\LeaveWorkdayCalendarQueryService;
use EduCore\Modules\Attendance\Application\AttendanceEventIngestor;
use EduCore\Modules\Attendance\Application\AlternativeAttendanceRecorder;
use EduCore\Modules\Attendance\Application\AttendanceAdjustmentService;
use EduCore\Modules\Attendance\Application\AttendanceExceptionQueryService;
use EduCore\Modules\Attendance\Application\AttendanceShadowRunService;
use EduCore\Modules\Attendance\Application\AttendanceRecalculationService;
use EduCore\Modules\Attendance\Application\AttendancePeriodService;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Attendance\Application\AttendanceReportQueryService;
use EduCore\Modules\Attendance\Application\AttendanceReportProjector;
use EduCore\Modules\Attendance\Application\BiometricIdentityMappingService;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceAuthorization;
use EduCore\Modules\Attendance\Contracts\AttendanceAdjustmentAuthorization;
use EduCore\Modules\Attendance\Application\LegacyStaffShiftCompatibilityService;
use EduCore\Modules\Attendance\Application\SchedulePolicyAdminQueryService;
use EduCore\Modules\Attendance\Application\SchedulePolicyCommandService;
use EduCore\Modules\Attendance\Application\SchedulePolicyImpactQuery;
use EduCore\Modules\Attendance\Application\ScheduleChangeRequestService;
use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Infrastructure\PdoStaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffGroupOverlapQuery;
use EduCore\Modules\Staff\Infrastructure\PdoApprovalDecisionEvidenceQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffPopulationAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffAttendanceReportDimensionQuery;
use EduCore\Modules\Staff\Infrastructure\PdoLegacyStaffDirectoryQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffApprovedCoverageReadRepository;
use EduCore\Modules\Staff\Contracts\StaffAccessEligibilityQuery;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;
use EduCore\Modules\Staff\Contracts\StaffScheduleScopeOptionQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffScheduleScopeOptionQuery;
use EduCore\Modules\Attendance\Domain\Calculation\AttendanceDayCalculator;
use EduCore\Modules\Attendance\Domain\Calculation\PunchWindowMatcher;
use EduCore\Modules\Attendance\Contracts\ApprovedCoverageQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceEntryMethodQuery;
use EduCore\Modules\Attendance\Contracts\LeaveWorkdayCalendarQuery;
use PDO;

/**
 * Attendance composition root shared by the legacy HTTP adapters.
 *
 * AuditService is intentionally accepted here instead of the narrower event
 * contract: schedule and biometric commands consume it through AuditEventWriter,
 * while the legacy shift writer retains the existing undo-aware insert/update/delete
 * audit contract. The concrete dependency remains confined to Infrastructure.
 */
final class AttendanceModuleFactory
{
    private PdoAttendanceTransactionManager $transactions;
    private PdoSchedulePolicyRepository $schedules;
    private PdoStaffPopulationAtDateQuery $staffPopulation;
    private PdoStaffAssignmentAtDateQuery $staffAssignments;
    private PdoBiometricIdentityMappingRepository $biometricMappings;

    public function __construct(private PDO $db, private AuditService $audit)
    {
        $this->transactions = new PdoAttendanceTransactionManager($db);
        $this->schedules = new PdoSchedulePolicyRepository($db, new PdoStaffGroupOverlapQuery($db));
        $this->staffPopulation = new PdoStaffPopulationAtDateQuery($db);
        $this->staffAssignments = new PdoStaffAssignmentAtDateQuery($db, $this->staffPopulation);
        $this->biometricMappings = new PdoBiometricIdentityMappingRepository($db);
    }

    public function schedulePolicyCommand(): SchedulePolicyCommandService
    {
        return new SchedulePolicyCommandService(
            $this->transactions,
            $this->schedules,
            $this->audit
        );
    }

    public function schedulePolicyAdminQuery(): SchedulePolicyAdminQueryService
    {
        return new SchedulePolicyAdminQueryService($this->schedules);
    }

    public function effectiveSchedule(): EffectiveScheduleQueryService
    {
        return new EffectiveScheduleQueryService($this->schedules, $this->staffAssignments);
    }

    /** Staff consumes only the minimal resolved-workday calendar contract. */
    public function leaveWorkdayCalendar(): LeaveWorkdayCalendarQuery
    {
        return new LeaveWorkdayCalendarQueryService($this->effectiveSchedule());
    }

    public function schedulePolicyImpact(): SchedulePolicyImpactQuery
    {
        return new SchedulePolicyImpactQuery(
            $this->schedules,
            $this->effectiveSchedule(),
            $this->staffPopulation
        );
    }

    public function scheduleChangeRequests(): ScheduleChangeRequestService
    {
        return new ScheduleChangeRequestService(
            $this->transactions,
            $this->schedules,
            $this->audit,
            new PdoScheduleChangeAuthorization(new PdoApprovalDecisionEvidenceQuery($this->db))
        );
    }

    public function scheduleChangeApprovalOutcomes(): ApprovalWorkflowOutcomeHandler
    {
        return new ScheduleChangeApprovalOutcomeHandler(
            $this->schedules,
            $this->scheduleChangeRequests(),
            $this->audit
        );
    }

    public function scheduleScopeOptions(): StaffScheduleScopeOptionQuery
    {
        return new PdoStaffScheduleScopeOptionQuery($this->db);
    }

    public function legacyStaffShiftCompatibility(): LegacyStaffShiftCompatibilityService
    {
        return new LegacyStaffShiftCompatibilityService(
            new PdoLegacyStaffShiftRepository($this->db, new PdoLegacyStaffDirectoryQuery($this->db)),
            $this->transactions,
            new OperationsAuditLegacyStaffShiftWriter($this->audit)
        );
    }

    public function biometricIdentityMappings(): BiometricIdentityMappingService
    {
        return new BiometricIdentityMappingService(
            $this->transactions,
            $this->biometricMappings,
            $this->staffAssignments,
            $this->audit
        );
    }

    public function attendanceEventIngestor(): AttendanceEventIngestor
    {
        return new AttendanceEventIngestor(
            $this->transactions,
            new PdoAttendanceEventRepository($this->db),
            $this->biometricMappings,
            $this->audit
        );
    }

    public function attendanceEntryMethods(): AttendanceEntryMethodQuery
    {
        return new PdoAttendanceEventRepository($this->db);
    }

    /**
     * The caller supplies the Staff-owned current-access adapter. This keeps
     * attendance evidence independent from legacy role/session internals.
     */
    public function alternativeAttendanceRecorder(
        AlternativeAttendanceAuthorization $authorization
    ): AlternativeAttendanceRecorder {
        return new AlternativeAttendanceRecorder(
            $this->transactions,
            new PdoAlternativeAttendanceEventRepository($this->db),
            $this->staffAssignments,
            $authorization,
            $this->audit
        );
    }

    public function alternativeAttendanceAuthorization(
        StaffAccessEligibilityQuery $access
    ): AlternativeAttendanceAuthorization {
        return new StaffAlternativeAttendanceAuthorization($access);
    }

    public function attendanceAdjustmentService(
        AttendanceAdjustmentAuthorization $authorization
    ): AttendanceAdjustmentService {
        return new AttendanceAdjustmentService(
            $this->transactions,
            new PdoAttendanceAdjustmentRepository($this->db),
            $authorization,
            $this->audit
        );
    }

    public function attendanceAdjustmentAuthorization(
        StaffAccessEligibilityQuery $access
    ): AttendanceAdjustmentAuthorization {
        return new StaffAttendanceAdjustmentAuthorization($access);
    }

    /**
     * Read-only review model. It deliberately remains inside the Attendance
     * module and never joins legacy Staff profile tables for display labels.
     */
    public function attendanceExceptionQuery(): AttendanceExceptionQueryService
    {
        return new AttendanceExceptionQueryService(
            new PdoAttendanceExceptionQuery($this->db)
        );
    }

    /**
     * The composition root alone bridges Attendance to Staff's narrow
     * coverage projection; calculation code never queries Staff tables.
     */
    public function approvedCoverageQuery(): ApprovedCoverageQuery
    {
        return new PdoApprovedCoverageQuery(
            new PdoStaffApprovedCoverageReadRepository($this->db)
        );
    }

    /**
     * Shadow calculation is intentionally read/compare-only. The HTTP owner
     * supplies its own capability check before selecting the bounded staff set.
     */
    public function attendanceShadowRunService(): AttendanceShadowRunService
    {
        $repository = new PdoAttendanceShadowRunRepository($this->db);

        return new AttendanceShadowRunService(
            $this->transactions,
            $repository,
            $this->effectiveSchedule(),
            $repository,
            new PdoLegacyStaffAttendanceDayQuery($this->db),
            new AttendanceDayCalculator(new PunchWindowMatcher()),
            $this->audit,
            $this->approvedCoverageQuery()
        );
    }

    /**
     * Official successor generation remains behind an explicit application
     * service; callers select an already-authorized affected day only.
     */
    public function attendanceRecalculationService(): AttendanceRecalculationService
    {
        $repository = new PdoAttendanceShadowRunRepository($this->db);

        return new AttendanceRecalculationService(
            $this->transactions,
            $repository,
            $this->effectiveSchedule(),
            $repository,
            $this->approvedCoverageQuery(),
            new AttendanceDayCalculator(new PunchWindowMatcher()),
            $this->audit
        );
    }

    /**
     * Period close/reopen is deliberately composed separately from day
     * recalculation. A caller must make the explicit follow-up calculation
     * after a change becomes ready, rather than changing a closed month here.
     */
    public function attendancePeriodService(): AttendancePeriodService
    {
        return new AttendancePeriodService(
            $this->transactions,
            new PdoAttendancePeriodRepository($this->db),
            $this->audit
        );
    }

    /**
     * Staff publishes only approved/reversed coverage facts through this
     * contract. Attendance decides whether the month is ready or requires
     * an explicit reopen; no Staff table is exposed to the adapter.
     */
    public function approvedCoverageChangeGateway(): AttendanceCoverageChangeGateway
    {
        return new StaffApprovedCoverageChangeGateway($this->attendancePeriodService());
    }

    /**
     * The caller supplies a pre-authorized AttendanceReportScope. This
     * composition root keeps the historical Staff dimension read behind its
     * Staff-owned adapter and the official-day read inside Attendance.
     */
    public function attendanceReportQuery(): AttendanceReportQueryService
    {
        return new AttendanceReportQueryService(
            new PdoAttendanceReportReadRepository($this->db),
            new PdoStaffAttendanceReportDimensionQuery($this->db)
        );
    }

    /** Rebuildable reporting projections use the same official-day reader. */
    public function attendanceReportProjector(): AttendanceReportProjector
    {
        return new AttendanceReportProjector(
            $this->transactions,
            new PdoAttendanceReportReadRepository($this->db),
            new PdoStaffAttendanceReportDimensionQuery($this->db),
            new PdoAttendanceReportProjectionRepository($this->db),
            $this->audit
        );
    }
}
