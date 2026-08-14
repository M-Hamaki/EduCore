<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';
require_once $root . '/src/Modules/Attendance/bootstrap.php';

use EduCore\Modules\Attendance\Application\EffectiveScheduleQueryService;
use EduCore\Modules\Attendance\Application\LeaveWorkdayCalendarQueryService;
use EduCore\Modules\Attendance\Application\AlternativeAttendanceRecorder;
use EduCore\Modules\Attendance\Application\AttendanceAdjustmentService;
use EduCore\Modules\Attendance\Application\AttendanceExceptionQueryService;
use EduCore\Modules\Attendance\Application\AttendanceShadowRunService;
use EduCore\Modules\Attendance\Application\AttendanceRecalculationService;
use EduCore\Modules\Attendance\Application\AttendancePeriodService;
use EduCore\Modules\Attendance\Application\AttendanceReportQueryService;
use EduCore\Modules\Attendance\Application\AttendanceReportScope;
use EduCore\Modules\Attendance\Application\AttendanceReportProjector;
use EduCore\Modules\Attendance\Application\LegacyStaffShiftCompatibilityService;
use EduCore\Modules\Attendance\Application\SchedulePolicyAdminQueryService;
use EduCore\Modules\Attendance\Application\SchedulePolicyCommandService;
use EduCore\Modules\Attendance\Application\SchedulePolicyImpactQuery;
use EduCore\Modules\Attendance\Application\ScheduleChangeRequestService;
use EduCore\Modules\Attendance\Contracts\ScheduleChangeAuthorization;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceAuthorization;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceEventRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceAdjustmentAuthorization;
use EduCore\Modules\Attendance\Contracts\AttendanceAdjustmentRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceExceptionQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceEventWindowQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceShadowRunRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceRecalculationRepository;
use EduCore\Modules\Attendance\Contracts\AttendancePeriodRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceReportReadRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceReportProjectionRepository;
use EduCore\Modules\Attendance\Contracts\ApprovedCoverageQuery;
use EduCore\Modules\Attendance\Contracts\LeaveWorkdayCalendarQuery;
use EduCore\Modules\Attendance\Contracts\LegacyStaffAttendanceDayQuery;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyReadRepository;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyRepository;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory;
use EduCore\Modules\Attendance\Infrastructure\PdoSchedulePolicyRepository;
use EduCore\Modules\Attendance\Infrastructure\PdoScheduleChangeAuthorization;
use EduCore\Modules\Attendance\Infrastructure\PdoAlternativeAttendanceEventRepository;
use EduCore\Modules\Attendance\Infrastructure\StaffAlternativeAttendanceAuthorization;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceAdjustmentRepository;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceExceptionQuery;
use EduCore\Modules\Attendance\Infrastructure\StaffAttendanceAdjustmentAuthorization;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceShadowRunRepository;
use EduCore\Modules\Attendance\Infrastructure\PdoLegacyStaffAttendanceDayQuery;
use EduCore\Modules\Attendance\Infrastructure\PdoApprovedCoverageQuery;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendancePeriodRepository;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceReportReadRepository;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceReportProjectionRepository;
use EduCore\Modules\Attendance\Infrastructure\StaffApprovedCoverageChangeGateway;
use EduCore\Modules\Staff\Infrastructure\Migration\StaffHrMigrationCoordinator;
use EduCore\Modules\Staff\Application\Policy\EffectivePolicyResolver;
use EduCore\Modules\Staff\Application\Organization\StaffOrganizationAdministrationQuery;
use EduCore\Modules\Staff\Application\Organization\StaffOrganizationService;
use EduCore\Modules\Staff\Application\Portal\StaffPortalEligibilityService;
use EduCore\Modules\Staff\Application\Timeline\StaffDocumentExpiryService;
use EduCore\Modules\Staff\Application\Timeline\StaffHrTimelineQuery;
use EduCore\Modules\Staff\Application\Discipline\DisciplineCaseService;
use EduCore\Modules\Staff\Application\Discipline\DisciplineCaseAdminQuery;
use EduCore\Modules\Staff\Application\Discipline\DisciplineAppealService;
use EduCore\Modules\Staff\Application\Discipline\DisciplineDecisionApprovalOutcomeHandler;
use EduCore\Modules\Staff\Application\Discipline\DisciplineDecisionService;
use EduCore\Modules\Staff\Application\Discipline\DisciplineFinanceEffectService;
use EduCore\Modules\Staff\Application\Discipline\DisciplineInvestigationService;
use EduCore\Modules\Staff\Application\Permission\PermissionPolicyResolver;
use EduCore\Modules\Staff\Application\Leave\LeavePolicyService;
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingPolicy;
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingOverrideAuthorization;
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingOverrideService;
use EduCore\Modules\Staff\Application\Leave\LeaveAttachmentService;
use EduCore\Modules\Staff\Application\Leave\LeaveApprovalOutcomeHandler;
use EduCore\Modules\Staff\Application\Leave\LeaveBalanceLedger;
use EduCore\Modules\Staff\Application\Leave\LeaveFinanceEffectService;
use EduCore\Modules\Staff\Application\Leave\LeaveRequestService;
use EduCore\Modules\Staff\Application\Leave\LegacyLeaveCompatibilityService;
use EduCore\Modules\Staff\Application\Leave\SystemLeaveRequestClock;
use EduCore\Modules\Staff\Application\Permission\PermissionApprovalOutcomeHandler;
use EduCore\Modules\Staff\Application\Permission\PermissionQuotaLedger;
use EduCore\Modules\Staff\Application\Permission\PermissionQuotaLimits;
use EduCore\Modules\Staff\Application\Permission\PermissionRequestService;
use EduCore\Modules\Staff\Application\Permission\LegacyPermissionCompatibilityService;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowResolver;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowService;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowAuthorization;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowAdministrationService;
use EduCore\Modules\Staff\Application\Approval\ApprovalNotificationService;
use EduCore\Modules\Staff\Application\Approval\AssignedApprovalInboxQuery;
use EduCore\Modules\Staff\Contracts\ApprovalRoleAssigneeQuery;
use EduCore\Modules\Staff\Contracts\ApprovalDelegationQuery;
use EduCore\Modules\Staff\Contracts\ApprovalDelegationRevalidationQuery;
use EduCore\Modules\Staff\Contracts\ApprovalActorEligibilityQuery;
use EduCore\Modules\Staff\Contracts\ApprovalTransitionAuthorization;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowAdministrationRepository;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowDefinitionQuery;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowRepository;
use EduCore\Modules\Staff\Contracts\AssignedApprovalInboxReadRepository;
use EduCore\Modules\Staff\Contracts\LegacyPermissionAuditWriter;
use EduCore\Modules\Staff\Contracts\LegacyPermissionRepository;
use EduCore\Modules\Staff\Contracts\LegacyLeaveGateway;
use EduCore\Modules\Staff\Contracts\ManagerHierarchyAtDateQuery;
use EduCore\Modules\Staff\Contracts\PayrollImpactGateway;
use EduCore\Modules\Staff\Contracts\PermissionPolicyReadRepository;
use EduCore\Modules\Staff\Contracts\LeavePolicyReadRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingReadRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideApprovalQuery;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideRequestGateway;
use EduCore\Modules\Staff\Contracts\LeaveAttachmentVerificationQuery;
use EduCore\Modules\Staff\Contracts\LeaveAttachmentStorage;
use EduCore\Modules\Staff\Contracts\LeaveAttachmentRepository;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerGateway;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerRepository;
use EduCore\Modules\Staff\Contracts\LeaveBalanceMovementLookup;
use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectRepository;
use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\LeaveRequestAuthorization;
use EduCore\Modules\Staff\Contracts\LeaveRequestClock;
use EduCore\Modules\Staff\Contracts\LeaveRequestOverlapQuery;
use EduCore\Modules\Staff\Contracts\LeaveRequestRepository;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowResolutionGateway;
use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerGateway;
use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerRepository;
use EduCore\Modules\Staff\Contracts\PermissionRequestAuthorization;
use EduCore\Modules\Staff\Contracts\PermissionRequestClock;
use EduCore\Modules\Staff\Contracts\PermissionRequestOverlapQuery;
use EduCore\Modules\Staff\Contracts\PermissionRequestRepository;
use EduCore\Modules\Staff\Contracts\PermissionSubmissionWorkflowResolver;
use EduCore\Modules\Staff\Contracts\StaffAccessEligibilityQuery;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffOrganizationAdministrationReadRepository;
use EduCore\Modules\Staff\Contracts\StaffOrganizationRepository;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;
use EduCore\Modules\Staff\Contracts\StaffPopulationAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffAttendanceReportDimensionQuery;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityQuery;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityReadRepository;
use EduCore\Modules\Staff\Contracts\StaffTimelineEventSource;
use EduCore\Modules\Staff\Contracts\StaffCredentialRepository;
use EduCore\Modules\Staff\Contracts\StaffApprovedCoverageReadRepository;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;
use EduCore\Modules\Staff\Contracts\ApprovedCoveragePublicationGateway;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAdminReadRepository;
use EduCore\Modules\Staff\Contracts\DisciplineAppealRepository;
use EduCore\Modules\Staff\Contracts\DisciplineCaseRepository;
use EduCore\Modules\Staff\Contracts\DisciplineDecisionRepository;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectRepository;
use EduCore\Modules\Staff\Contracts\DisciplineEvidenceStorage;
use EduCore\Modules\Staff\Contracts\DisciplineInvestigationRepository;
use EduCore\Modules\Staff\Domain\Policy\EffectiveDatedPolicy;
use EduCore\Modules\Staff\Domain\Policy\PolicyScope;
use EduCore\Modules\Staff\Infrastructure\Notification\PdoStaffNotificationOutbox;
use EduCore\Modules\Staff\Infrastructure\PdoPermissionPolicyReadRepository;
use EduCore\Modules\Staff\Infrastructure\PdoPermissionQuotaLedgerRepository;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveBalanceLedgerRepository;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveFinanceEffectRepository;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveRequestRepository;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveStaffingReadRepository;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveStaffingOverrideRepository;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveAttachmentRepository;
use EduCore\Modules\Staff\Infrastructure\LocalLeaveAttachmentStorage;
use EduCore\Modules\Staff\Infrastructure\PdoPermissionRequestRepository;
use EduCore\Modules\Staff\Infrastructure\PdoDisciplineCaseRepository;
use EduCore\Modules\Staff\Infrastructure\PdoDisciplineCaseAdminReadRepository;
use EduCore\Modules\Staff\Infrastructure\PdoDisciplineAppealRepository;
use EduCore\Modules\Staff\Infrastructure\PdoDisciplineDecisionRepository;
use EduCore\Modules\Staff\Infrastructure\PdoDisciplineFinanceEffectRepository;
use EduCore\Modules\Staff\Infrastructure\PdoDisciplineInvestigationRepository;
use EduCore\Modules\Staff\Infrastructure\LocalDisciplineEvidenceStorage;
use EduCore\Modules\Staff\Infrastructure\PdoLegacyPermissionRepository;
use EduCore\Modules\Staff\Infrastructure\StaffLeaveLegacyGateway;
use EduCore\Modules\Staff\Infrastructure\PdoStaffApprovedCoverageReadRepository;
use EduCore\Modules\Staff\Application\Approval\ApprovedCoveragePublisher;
use EduCore\Modules\Staff\Application\Approval\StaffApprovalOutcomeRouter;
use EduCore\Modules\Staff\Infrastructure\OperationsAuditLegacyPermissionWriter;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;
use EduCore\Modules\Staff\Infrastructure\PdoStaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffPopulationAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffAttendanceReportDimensionQuery;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoManagerHierarchyQuery;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffAssignmentAtDateQuery as PdoLifecycleStaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffOrganizationAdministrationReadRepository;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffOrganizationRepository;
use EduCore\Modules\Staff\Infrastructure\Portal\PdoStaffPortalEligibilityReadRepository;
use EduCore\Modules\Staff\Infrastructure\Timeline\PdoStaffAssignmentTimelineEventSource;
use EduCore\Modules\Staff\Infrastructure\Timeline\PdoStaffCredentialRepository;
use EduCore\Modules\Staff\Infrastructure\Timeline\PdoStaffCredentialTimelineEventSource;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalRoleAssigneeQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalDelegationQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalDelegationRevalidationQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalActorEligibilityQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowAdministrationRepository;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowDefinitionQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowRepository;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoAssignedApprovalInboxReadRepository;
use EduCore\Modules\Staff\Infrastructure\StaffHrFeatureFlags;
use EduCore\Modules\Staff\Presentation\StaffSelfServiceRequests;
use EduCore\Modules\Staff\Presentation\ManagerApprovalInbox;

$checks = [
    'assignment_contract_is_bootstrapped' => interface_exists(StaffAssignmentAtDateQuery::class, false),
    'manager_contract_is_bootstrapped' => interface_exists(ManagerHierarchyAtDateQuery::class, false),
    'portal_contract_is_bootstrapped' => interface_exists(StaffPortalEligibilityQuery::class, false),
    'role_independent_portal_eligibility_boundary_is_bootstrapped' => interface_exists(StaffPortalEligibilityReadRepository::class, false)
        && class_exists(PdoStaffPortalEligibilityReadRepository::class, false)
        && class_exists(StaffPortalEligibilityService::class, false)
        && is_subclass_of(StaffPortalEligibilityService::class, StaffPortalEligibilityQuery::class)
        && method_exists(StaffModuleFactory::class, 'portalEligibility'),
    'staff_hr_timeline_boundary_is_bootstrapped' => interface_exists(StaffTimelineEventSource::class, false)
        && class_exists(PdoStaffAssignmentTimelineEventSource::class, false)
        && is_subclass_of(PdoStaffAssignmentTimelineEventSource::class, StaffTimelineEventSource::class)
        && class_exists(StaffHrTimelineQuery::class, false)
        && method_exists(StaffModuleFactory::class, 'staffTimeline'),
    'staff_credential_expiry_boundary_is_bootstrapped' => interface_exists(StaffCredentialRepository::class, false)
        && class_exists(PdoStaffCredentialRepository::class, false)
        && is_subclass_of(PdoStaffCredentialRepository::class, StaffCredentialRepository::class)
        && class_exists(PdoStaffCredentialTimelineEventSource::class, false)
        && is_subclass_of(PdoStaffCredentialTimelineEventSource::class, StaffTimelineEventSource::class)
        && class_exists(StaffDocumentExpiryService::class, false)
        && method_exists(StaffModuleFactory::class, 'documentExpiryService'),
    'access_contract_is_bootstrapped' => interface_exists(StaffAccessEligibilityQuery::class, false),
    'notification_contract_is_bootstrapped' => interface_exists(StaffNotificationPort::class, false),
    'payroll_contract_is_bootstrapped' => interface_exists(PayrollImpactGateway::class, false),
    'population_contract_and_adapters_are_bootstrapped' => interface_exists(StaffPopulationAtDateQuery::class, false)
        && class_exists(PdoStaffPopulationAtDateQuery::class, false)
        && class_exists(PdoStaffAssignmentAtDateQuery::class, false),
    'lifecycle_assignment_and_access_adapter_is_bootstrapped' => class_exists(PdoLifecycleStaffAssignmentAtDateQuery::class, false)
        && is_subclass_of(PdoLifecycleStaffAssignmentAtDateQuery::class, StaffAssignmentAtDateQuery::class)
        && is_subclass_of(PdoLifecycleStaffAssignmentAtDateQuery::class, StaffAccessEligibilityQuery::class)
        && method_exists(StaffModuleFactory::class, 'datedStaffAssignments')
        && method_exists(StaffModuleFactory::class, 'currentStaffAccess'),
    'manager_hierarchy_adapter_is_bootstrapped' => class_exists(PdoManagerHierarchyQuery::class, false),
    'organization_command_boundary_is_bootstrapped' => interface_exists(StaffOrganizationRepository::class, false)
        && class_exists(PdoStaffOrganizationRepository::class, false)
        && class_exists(StaffOrganizationService::class, false)
        && method_exists(StaffModuleFactory::class, 'organizationAdministration'),
    'organization_administration_read_boundary_is_bootstrapped' => interface_exists(StaffOrganizationAdministrationReadRepository::class, false)
        && class_exists(PdoStaffOrganizationAdministrationReadRepository::class, false)
        && is_subclass_of(PdoStaffOrganizationAdministrationReadRepository::class, StaffOrganizationAdministrationReadRepository::class)
        && class_exists(StaffOrganizationAdministrationQuery::class, false)
        && method_exists(StaffModuleFactory::class, 'organizationAdministrationRead'),
    'approval_resolver_boundaries_are_bootstrapped' => interface_exists(ApprovalWorkflowDefinitionQuery::class, false)
        && interface_exists(ApprovalRoleAssigneeQuery::class, false)
        && interface_exists(ApprovalDelegationQuery::class, false)
        && class_exists(PdoApprovalWorkflowDefinitionQuery::class, false)
        && class_exists(PdoApprovalRoleAssigneeQuery::class, false)
        && class_exists(PdoApprovalDelegationQuery::class, false)
        && class_exists(ApprovalWorkflowResolver::class, false),
    'approval_state_machine_boundary_is_bootstrapped' => interface_exists(ApprovalWorkflowRepository::class, false)
        && interface_exists(ApprovalWorkflowOutcomeHandler::class, false)
        && interface_exists(ApprovalWorkflowSubmissionGateway::class, false)
        && class_exists(PdoApprovalWorkflowRepository::class, false)
        && class_exists(ApprovalWorkflowService::class, false)
        && class_exists(ApprovalNotificationService::class, false),
    'approval_live_authorization_boundary_is_bootstrapped' => interface_exists(ApprovalActorEligibilityQuery::class, false)
        && interface_exists(ApprovalDelegationRevalidationQuery::class, false)
        && interface_exists(ApprovalTransitionAuthorization::class, false)
        && class_exists(PdoApprovalActorEligibilityQuery::class, false)
        && class_exists(PdoApprovalDelegationRevalidationQuery::class, false)
        && class_exists(ApprovalWorkflowAuthorization::class, false),
    'approval_administration_boundary_is_bootstrapped' => interface_exists(ApprovalWorkflowAdministrationRepository::class, false)
        && class_exists(PdoApprovalWorkflowAdministrationRepository::class, false)
        && class_exists(ApprovalWorkflowAdministrationService::class, false),
    'assigned_approval_inbox_boundary_is_bootstrapped' => interface_exists(AssignedApprovalInboxReadRepository::class, false)
        && class_exists(PdoAssignedApprovalInboxReadRepository::class, false)
        && class_exists(AssignedApprovalInboxQuery::class, false),
    'policy_primitives_are_bootstrapped' => class_exists(PolicyScope::class, false)
        && class_exists(EffectiveDatedPolicy::class, false)
        && class_exists(EffectivePolicyResolver::class, false),
    'leave_policy_calendar_boundary_is_bootstrapped' => interface_exists(LeavePolicyReadRepository::class, false)
        && class_exists(LeavePolicyService::class, false)
        && interface_exists(LeaveWorkdayCalendarQuery::class, false)
        && class_exists(LeaveWorkdayCalendarQueryService::class, false),
    'leave_balance_ledger_boundary_is_bootstrapped' => interface_exists(LeaveBalanceLedgerGateway::class, false)
        && interface_exists(LeaveBalanceLedgerRepository::class, false)
        && class_exists(LeaveBalanceLedger::class, false)
        && class_exists(PdoLeaveBalanceLedgerRepository::class, false)
        && method_exists(StaffModuleFactory::class, 'leaveBalanceLedger'),
    'leave_finance_effect_boundary_is_bootstrapped' => interface_exists(LeaveFinanceEffectRepository::class, false)
        && interface_exists(LeaveFinanceEffectQueue::class, false)
        && class_exists(LeaveFinanceEffectService::class, false)
        && class_exists(PdoLeaveFinanceEffectRepository::class, false)
        && method_exists(StaffModuleFactory::class, 'leaveFinanceEffects')
        && method_exists(StaffModuleFactory::class, 'leaveFinanceEffectQueue'),
    'leave_approval_outcome_boundary_is_bootstrapped' => interface_exists(LeaveBalanceMovementLookup::class, false)
        && class_exists(LeaveApprovalOutcomeHandler::class, false)
        && class_exists(StaffApprovalOutcomeRouter::class, false)
        && class_exists(PdoLeaveBalanceLedgerRepository::class, false),
    'leave_request_lifecycle_boundary_is_bootstrapped' => interface_exists(LeaveRequestRepository::class, false)
        && interface_exists(LeaveRequestAuthorization::class, false)
        && interface_exists(LeaveRequestClock::class, false)
        && interface_exists(LeaveRequestOverlapQuery::class, false)
        && interface_exists(ApprovalWorkflowResolutionGateway::class, false)
        && class_exists(LeaveRequestService::class, false)
        && class_exists(SystemLeaveRequestClock::class, false)
        && class_exists(PdoLeaveRequestRepository::class, false),
    'leave_staffing_policy_boundary_is_bootstrapped' => interface_exists(LeaveStaffingReadRepository::class, false)
        && class_exists(LeaveStaffingPolicy::class, false)
        && class_exists(PdoLeaveStaffingReadRepository::class, false),
    'leave_staffing_override_boundary_is_bootstrapped' => interface_exists(LeaveStaffingOverrideApprovalQuery::class, false)
        && interface_exists(LeaveStaffingOverrideRepository::class, false)
        && interface_exists(LeaveStaffingOverrideRequestGateway::class, false)
        && class_exists(LeaveStaffingOverrideAuthorization::class, false)
        && class_exists(LeaveStaffingOverrideService::class, false)
        && class_exists(PdoLeaveStaffingOverrideRepository::class, false),
    'leave_private_attachment_boundary_is_bootstrapped' => interface_exists(LeaveAttachmentVerificationQuery::class, false)
        && interface_exists(LeaveAttachmentStorage::class, false)
        && interface_exists(LeaveAttachmentRepository::class, false)
        && class_exists(LeaveAttachmentService::class, false)
        && class_exists(PdoLeaveAttachmentRepository::class, false)
        && class_exists(LocalLeaveAttachmentStorage::class, false),
    'discipline_case_boundary_is_bootstrapped' => interface_exists(DisciplineCaseAuthorization::class, false)
        && interface_exists(DisciplineCaseRepository::class, false)
        && class_exists(PdoDisciplineCaseRepository::class, false)
        && class_exists(DisciplineCaseService::class, false),
    'discipline_case_safe_index_boundary_is_bootstrapped' => interface_exists(DisciplineCaseAdminReadRepository::class, false)
        && class_exists(PdoDisciplineCaseAdminReadRepository::class, false)
        && class_exists(DisciplineCaseAdminQuery::class, false),
    'discipline_appeal_boundary_is_bootstrapped' => interface_exists(DisciplineAppealRepository::class, false)
        && class_exists(PdoDisciplineAppealRepository::class, false)
        && class_exists(DisciplineAppealService::class, false),
    'discipline_investigation_boundary_is_bootstrapped' => interface_exists(DisciplineEvidenceStorage::class, false)
        && interface_exists(DisciplineInvestigationRepository::class, false)
        && class_exists(PdoDisciplineInvestigationRepository::class, false)
        && class_exists(LocalDisciplineEvidenceStorage::class, false)
        && class_exists(DisciplineInvestigationService::class, false),
    'discipline_decision_boundary_is_bootstrapped' => interface_exists(DisciplineDecisionRepository::class, false)
        && class_exists(PdoDisciplineDecisionRepository::class, false)
        && class_exists(DisciplineDecisionService::class, false)
        && class_exists(DisciplineDecisionApprovalOutcomeHandler::class, false),
    'discipline_finance_effect_boundary_is_bootstrapped' => interface_exists(DisciplineFinanceEffectRepository::class, false)
        && interface_exists(DisciplineFinanceEffectQueue::class, false)
        && class_exists(PdoDisciplineFinanceEffectRepository::class, false)
        && class_exists(DisciplineFinanceEffectService::class, false),
    'permission_request_boundaries_are_bootstrapped' => interface_exists(PermissionPolicyReadRepository::class, false)
        && interface_exists(PermissionQuotaLedgerGateway::class, false)
        && interface_exists(PermissionQuotaLedgerRepository::class, false)
        && interface_exists(PermissionRequestRepository::class, false)
        && interface_exists(PermissionRequestAuthorization::class, false)
        && interface_exists(PermissionRequestClock::class, false)
        && interface_exists(PermissionRequestOverlapQuery::class, false)
        && interface_exists(PermissionSubmissionWorkflowResolver::class, false)
        && class_exists(PermissionPolicyResolver::class, false)
        && class_exists(PermissionQuotaLimits::class, false)
        && class_exists(PermissionQuotaLedger::class, false)
        && class_exists(PermissionRequestService::class, false)
        && class_exists(PermissionApprovalOutcomeHandler::class, false)
        && class_exists(PdoPermissionPolicyReadRepository::class, false)
        && class_exists(PdoPermissionQuotaLedgerRepository::class, false)
        && class_exists(PdoPermissionRequestRepository::class, false),
    'permission_portal_presentation_is_bootstrapped' => class_exists(StaffSelfServiceRequests::class, false),
    'manager_approval_inbox_presentation_is_bootstrapped' => class_exists(ManagerApprovalInbox::class, false),
    'legacy_permission_compatibility_is_bootstrapped' => interface_exists(LegacyPermissionRepository::class, false)
        && interface_exists(LegacyPermissionAuditWriter::class, false)
        && class_exists(LegacyPermissionCompatibilityService::class, false)
        && class_exists(PdoLegacyPermissionRepository::class, false)
        && class_exists(OperationsAuditLegacyPermissionWriter::class, false)
        && class_exists(StaffModuleFactory::class, false)
        && method_exists(StaffModuleFactory::class, 'approvalNotifications')
        && method_exists(StaffModuleFactory::class, 'approvalTransitionAuthorization')
        && method_exists(StaffModuleFactory::class, 'approvalAdministration')
        && method_exists(StaffModuleFactory::class, 'assignedApprovalInbox')
        && method_exists(StaffModuleFactory::class, 'approvalWorkflowService'),
    'legacy_leave_compatibility_is_bootstrapped' => interface_exists(LegacyLeaveGateway::class, false)
        && class_exists(LegacyLeaveCompatibilityService::class, false)
        && class_exists(StaffLeaveLegacyGateway::class, false)
        && method_exists(StaffModuleFactory::class, 'legacyLeaveCompatibility'),
    'notification_adapter_is_bootstrapped' => class_exists(PdoStaffNotificationOutbox::class, false),
    'rollout_flag_is_bootstrapped_and_safe_by_default' => class_exists(StaffHrFeatureFlags::class, false)
        && (new StaffHrFeatureFlags())->mode() === StaffHrFeatureFlags::MODE_OFF,
    'legacy_attendance_service_remains_available' => class_exists(
        EduCore\Modules\Attendance\SpecialistAttendanceReadService::class,
        false
    ),
    'schedule_contracts_are_bootstrapped' => interface_exists(SchedulePolicyReadRepository::class, false)
        && interface_exists(SchedulePolicyRepository::class, false)
        && interface_exists(ScheduleChangeAuthorization::class, false),
    'alternative_attendance_boundary_is_bootstrapped' => interface_exists(AlternativeAttendanceAuthorization::class, false)
        && interface_exists(AlternativeAttendanceEventRepository::class, false)
        && class_exists(AlternativeAttendanceRecorder::class, false)
        && class_exists(PdoAlternativeAttendanceEventRepository::class, false)
        && class_exists(StaffAlternativeAttendanceAuthorization::class, false),
    'attendance_adjustment_boundary_is_bootstrapped' => interface_exists(AttendanceAdjustmentAuthorization::class, false)
        && interface_exists(AttendanceAdjustmentRepository::class, false)
        && class_exists(AttendanceAdjustmentService::class, false)
        && class_exists(PdoAttendanceAdjustmentRepository::class, false)
        && class_exists(StaffAttendanceAdjustmentAuthorization::class, false),
    'attendance_exception_review_boundary_is_bootstrapped' => interface_exists(AttendanceExceptionQuery::class, false)
        && class_exists(AttendanceExceptionQueryService::class, false)
        && class_exists(PdoAttendanceExceptionQuery::class, false),
    'attendance_shadow_boundary_is_bootstrapped' => interface_exists(AttendanceEventWindowQuery::class, false)
        && interface_exists(AttendanceShadowRunRepository::class, false)
        && interface_exists(LegacyStaffAttendanceDayQuery::class, false)
        && class_exists(AttendanceShadowRunService::class, false)
        && class_exists(PdoAttendanceShadowRunRepository::class, false)
        && class_exists(PdoLegacyStaffAttendanceDayQuery::class, false),
    'attendance_recalculation_boundary_is_bootstrapped' => interface_exists(AttendanceRecalculationRepository::class, false)
        && class_exists(AttendanceRecalculationService::class, false)
        && method_exists(AttendanceModuleFactory::class, 'attendanceRecalculationService'),
    'attendance_period_boundary_is_bootstrapped' => interface_exists(AttendancePeriodRepository::class, false)
        && class_exists(AttendancePeriodService::class, false)
        && class_exists(PdoAttendancePeriodRepository::class, false)
        && method_exists(AttendanceModuleFactory::class, 'attendancePeriodService'),
    'attendance_reporting_boundary_is_bootstrapped' => interface_exists(AttendanceReportReadRepository::class, false)
        && class_exists(PdoAttendanceReportReadRepository::class, false)
        && class_exists(AttendanceReportScope::class, false)
        && class_exists(AttendanceReportQueryService::class, false)
        && interface_exists(AttendanceReportProjectionRepository::class, false)
        && class_exists(PdoAttendanceReportProjectionRepository::class, false)
        && class_exists(AttendanceReportProjector::class, false)
        && interface_exists(StaffAttendanceReportDimensionQuery::class, false)
        && class_exists(PdoStaffAttendanceReportDimensionQuery::class, false)
        && method_exists(AttendanceModuleFactory::class, 'attendanceReportQuery')
        && method_exists(AttendanceModuleFactory::class, 'attendanceReportProjector'),
    'approved_coverage_publication_boundary_is_bootstrapped' => interface_exists(AttendanceCoverageChangeGateway::class, false)
        && interface_exists(ApprovedCoveragePublicationGateway::class, false)
        && class_exists(ApprovedCoveragePublisher::class, false)
        && class_exists(StaffApprovedCoverageChangeGateway::class, false)
        && method_exists(AttendanceModuleFactory::class, 'approvedCoverageChangeGateway'),
    'approved_coverage_boundary_is_bootstrapped' => interface_exists(ApprovedCoverageQuery::class, false)
        && interface_exists(StaffApprovedCoverageReadRepository::class, false)
        && class_exists(PdoApprovedCoverageQuery::class, false)
        && class_exists(PdoStaffApprovedCoverageReadRepository::class, false)
        && method_exists(AttendanceModuleFactory::class, 'approvedCoverageQuery'),
    'schedule_domain_and_application_are_bootstrapped' => class_exists(WorkSchedule::class, false)
        && class_exists(EffectiveScheduleQueryService::class, false)
        && class_exists(SchedulePolicyAdminQueryService::class, false)
        && class_exists(SchedulePolicyCommandService::class, false)
        && class_exists(ScheduleChangeRequestService::class, false)
        && class_exists(SchedulePolicyImpactQuery::class, false),
    'schedule_infrastructure_is_bootstrapped' => class_exists(PdoSchedulePolicyRepository::class, false)
        && class_exists(PdoScheduleChangeAuthorization::class, false)
        && class_exists(AttendanceModuleFactory::class, false),
    'legacy_shift_compatibility_is_bootstrapped' => class_exists(LegacyStaffShiftCompatibilityService::class, false),
    'migration_cutover_coordinator_is_bootstrapped' => class_exists(StaffHrMigrationCoordinator::class, false)
        && method_exists(StaffModuleFactory::class, 'migrationCoordinator'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
