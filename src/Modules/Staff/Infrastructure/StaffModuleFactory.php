<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowService;
use EduCore\Modules\Staff\Application\Approval\ApprovalNotificationService;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowAdministrationService;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowAuthorization;
use EduCore\Modules\Staff\Application\Approval\ApprovedCoveragePublisher;
use EduCore\Modules\Staff\Application\Approval\AssignedApprovalInboxQuery;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqInboxQuery;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqSlaService;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqTicketService;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqConversationService;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqUrgentRoutingService;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqUrgentInboxQuery;
use EduCore\Modules\Staff\Application\Organization\StaffOrganizationService;
use EduCore\Modules\Staff\Application\Organization\StaffOrganizationAdministrationQuery;
use EduCore\Modules\Staff\Application\Organization\StaffOrganizationCorrectionService;
use EduCore\Modules\Staff\Application\Organization\StaffEmploymentLifecycleService;
use EduCore\Modules\Staff\Application\Portal\StaffPortalEligibilityService;
use EduCore\Modules\Staff\Application\Timeline\StaffDocumentExpiryService;
use EduCore\Modules\Staff\Application\Timeline\StaffHrTimelineQuery;
use EduCore\Modules\Staff\Application\Approval\StaffApprovalOutcomeRouter;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowResolver;
use EduCore\Modules\Staff\Application\Discipline\DisciplineDecisionApprovalOutcomeHandler;
use EduCore\Modules\Staff\Application\Discipline\DisciplineCaseAdminQuery;
use EduCore\Modules\Staff\Application\Discipline\DisciplineAppealService;
use EduCore\Modules\Staff\Application\Discipline\DisciplineCaseOperationsQuery;
use EduCore\Modules\Staff\Application\Discipline\DisciplineFinanceEffectService;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\StaffAccessEligibilityQuery;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityQuery;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\PayrollImpactGateway;
use EduCore\Modules\Staff\Application\Leave\LeaveApprovalOutcomeHandler;
use EduCore\Modules\Staff\Application\Permission\LegacyPermissionCompatibilityService;
use EduCore\Modules\Staff\Application\Permission\PermissionApprovalOutcomeHandler;
use EduCore\Modules\Staff\Application\Permission\PermissionQuotaLedger;
use EduCore\Modules\Staff\Application\Permission\PermissionPolicyResolver;
use EduCore\Modules\Staff\Application\Permission\PermissionRequestService;
use EduCore\Modules\Staff\Application\Permission\StaffLifecyclePermissionAuthorization;
use EduCore\Modules\Staff\Application\Portal\StaffSelfServiceAuthorization;
use EduCore\Modules\Staff\Application\Portal\StaffSelfServicePortalQuery;
use EduCore\Modules\Staff\Application\Leave\LeaveBalanceLedger;
use EduCore\Modules\Staff\Application\Leave\LeaveAttachmentService;
use EduCore\Modules\Staff\Application\Leave\LeaveFinanceEffectService;
use EduCore\Modules\Staff\Application\Leave\LeavePolicyService;
use EduCore\Modules\Staff\Application\Leave\LeaveRequestService;
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingPolicy;
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingOverrideAuthorization;
use EduCore\Modules\Staff\Application\Leave\LeaveStaffingOverrideService;
use EduCore\Modules\Staff\Application\Leave\LegacyLeaveCompatibilityService;
use EduCore\Modules\Staff\Application\Policy\EffectivePolicyResolver;
use EduCore\Modules\Staff\Application\Portal\StaffLeaveSelfServiceAuthorization;
use EduCore\Modules\Staff\Application\Portal\StaffErtaqSelfServicePolicy;
use EduCore\Modules\Attendance\Contracts\LeaveWorkdayCalendarQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalActorEligibilityQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalManagerRelationshipRevalidationQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalDelegationRevalidationQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalDelegationQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalRoleAssigneeQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowDefinitionQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowAdministrationRepository;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowRepository;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoAssignedApprovalInboxReadRepository;
use EduCore\Modules\Staff\Infrastructure\Notification\PdoStaffNotificationOutbox;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveBalanceLedgerRepository;
use EduCore\Modules\Staff\Infrastructure\PdoDisciplineFinanceEffectRepository;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveFinanceEffectRepository;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveStaffingOverrideRepository;
use EduCore\Modules\Staff\Infrastructure\PdoErtaqInboxReadRepository;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffOrganizationRepository;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffOrganizationAdministrationReadRepository;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffOrganizationCorrectionRepository;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoManagerHierarchyQuery;
use EduCore\Modules\Staff\Infrastructure\Organization\PdoStaffAssignmentAtDateQuery as PdoOrganizationStaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\Portal\PdoStaffPortalEligibilityReadRepository;
use EduCore\Modules\Staff\Infrastructure\Timeline\PdoStaffAssignmentTimelineEventSource;
use EduCore\Modules\Staff\Infrastructure\Timeline\PdoStaffCredentialRepository;
use EduCore\Modules\Staff\Infrastructure\Timeline\PdoStaffCredentialTimelineEventSource;
use EduCore\Modules\Staff\Infrastructure\Migration\StaffHrMigrationCoordinator;
use PDO;

/**
 * Staff composition root for stable legacy HTTP entrypoints.
 *
 * Concrete PDO and audit dependencies stay here instead of being assembled in
 * application services or page handlers.
 */
final class StaffModuleFactory
{
    public function __construct(private PDO $db, private AuditService $audit)
    {
    }

    public function legacyPermissionCompatibility(): LegacyPermissionCompatibilityService
    {
        return new LegacyPermissionCompatibilityService(
            new PdoLegacyPermissionRepository($this->db),
            new OperationsAuditLegacyPermissionWriter($this->audit)
        );
    }

    /** CLI/operations-only coordinator; role pages must not run migrations. */
    public function migrationCoordinator(): StaffHrMigrationCoordinator
    {
        return new StaffHrMigrationCoordinator($this->db, $this->audit);
    }

    /**
     * Keeps the established leave/balance routes stable while their records
     * remain on the audited compatibility store during incremental rollout.
     */
    public function legacyLeaveCompatibility(): LegacyLeaveCompatibilityService
    {
        return new LegacyLeaveCompatibilityService(
            new StaffLeaveLegacyGateway($this->db)
        );
    }

    /** Composes the Staff-owned immutable leave ledger with the shared audit transaction. */
    public function leaveBalanceLedger(): LeaveBalanceLedger
    {
        return new LeaveBalanceLedger(
            new PdoLeaveBalanceLedgerRepository($this->db),
            $this->audit
        );
    }

    /**
     * Composes the Staff outbox owner with a Finance-owned fact gateway.
     * The gateway receives units and policy facts only; it owns payroll
     * amounts, period controls, posting, and reversal accounting.
     */
    public function leaveFinanceEffects(PayrollImpactGateway $finance): LeaveFinanceEffectService
    {
        return new LeaveFinanceEffectService(
            new PdoLeaveFinanceEffectRepository($this->db),
            $finance,
            $this->audit
        );
    }

    /**
     * Final approval may persist its Staff outbox fact before any operational
     * Finance dispatcher is configured. Calling dispatchEffect() on this
     * queue-only composition fails safely into retry until a dispatcher owns a
     * real Finance gateway.
     */
    public function leaveFinanceEffectQueue(): LeaveFinanceEffectQueue
    {
        return new LeaveFinanceEffectService(
            new PdoLeaveFinanceEffectRepository($this->db),
            null,
            $this->audit
        );
    }

    /**
     * Composes the Staff discipline outbox with the Finance-owned boundary.
     * It submits facts and units only; Finance owns money, posting, and periods.
     */
    public function disciplineFinanceEffects(PayrollImpactGateway $finance): DisciplineFinanceEffectService
    {
        return new DisciplineFinanceEffectService(
            new PdoDisciplineFinanceEffectRepository($this->db),
            $finance,
            $this->audit
        );
    }

    /**
     * Final discipline approval writes only the local outbox intent. A later
     * operational worker must provide the Finance-owned gateway to dispatch it.
     */
    public function disciplineFinanceEffectQueue(): DisciplineFinanceEffectQueue
    {
        return new DisciplineFinanceEffectService(
            new PdoDisciplineFinanceEffectRepository($this->db),
            null,
            $this->audit
        );
    }

    /**
     * Presentation-safe summary query for the compatible discipline route.
     * It cannot fetch reasons, evidence, decision text, or file references.
     */
    public function disciplineCaseAdminQuery(): DisciplineCaseAdminQuery
    {
        return new DisciplineCaseAdminQuery(
            new PdoDisciplineCaseAdminReadRepository($this->db)
        );
    }

    public function disciplineCaseOperationsQuery(): DisciplineCaseOperationsQuery
    {
        return new DisciplineCaseOperationsQuery(new PdoDisciplineCaseOperationsReadRepository($this->db));
    }

    public function disciplineAppeals(): DisciplineAppealService
    {
        return new DisciplineAppealService(
            new PdoDisciplineAppealRepository($this->db),
            new PdoDisciplineCaseAuthorization($this->db),
            $this->audit,
            null,
            $this->disciplineFinanceEffectQueue()
        );
    }

    /** Uses the same PDO/audit transaction context as a Staff approval transition. */
    public function approvalNotifications(): ApprovalNotificationService
    {
        return new ApprovalNotificationService(
            new PdoStaffNotificationOutbox($this->db, $this->audit)
        );
    }

    /** Composes live authorization checks without exposing PDO to the state machine. */
    public function approvalTransitionAuthorization(): ApprovalWorkflowAuthorization
    {
        return new ApprovalWorkflowAuthorization(
            new PdoApprovalActorEligibilityQuery($this->db),
            new PdoApprovalDelegationRevalidationQuery($this->db),
            new PdoApprovalManagerRelationshipRevalidationQuery($this->db)
        );
    }

    /** Composes audited workflow/delegation administration for the protected admin surface. */
    public function approvalAdministration(): ApprovalWorkflowAdministrationService
    {
        return new ApprovalWorkflowAdministrationService(
            new PdoApprovalWorkflowAdministrationRepository($this->db),
            $this->audit
        );
    }

    /** Composes audited, effective-dated Staff organization administration. */
    public function organizationAdministration(): StaffOrganizationService
    {
        return new StaffOrganizationService(
            new PdoStaffOrganizationRepository($this->db),
            $this->audit
        );
    }

    /** Presentation-safe lists for the organization administration entrypoint. */
    public function organizationAdministrationRead(): StaffOrganizationAdministrationQuery
    {
        return new StaffOrganizationAdministrationQuery(
            new PdoStaffOrganizationRepository($this->db),
            new PdoStaffOrganizationAdministrationReadRepository($this->db)
        );
    }

    /** Immutable preview/decision/reversal workflow for retroactive organization corrections. */
    public function organizationCorrections(): StaffOrganizationCorrectionService
    {
        $repository = new PdoStaffOrganizationCorrectionRepository($this->db);

        return new StaffOrganizationCorrectionService($repository, $repository, $this->audit);
    }

    /** Returns the Staff-owned dated assignment contract for cross-module readers. */
    public function datedStaffAssignments(): StaffAssignmentAtDateQuery
    {
        return new PdoOrganizationStaffAssignmentAtDateQuery($this->db);
    }

    /** Rechecks current employment, manager relationship, and HR scope per request. */
    public function currentStaffAccess(): StaffAccessEligibilityQuery
    {
        return new PdoOrganizationStaffAssignmentAtDateQuery($this->db);
    }

    public function approvalWorkflowResolver(): ApprovalWorkflowResolver
    {
        return new ApprovalWorkflowResolver(
            new PdoApprovalWorkflowDefinitionQuery($this->db),
            new PdoManagerHierarchyQuery($this->db),
            new PdoApprovalRoleAssigneeQuery($this->db),
            new PdoApprovalDelegationQuery($this->db)
        );
    }

    /**
     * Resolves worker self-service capabilities without depending on the role
     * selected for the current browser session.
     */
    public function portalEligibility(): StaffPortalEligibilityQuery
    {
        return new StaffPortalEligibilityService(
            new PdoStaffPortalEligibilityReadRepository($this->db),
            $this->datedStaffAssignments()
        );
    }

    /** Worker-scoped, presentation-safe permission types, balances, and requests. */
    public function permissionPortal(): StaffSelfServicePortalQuery
    {
        return new StaffSelfServicePortalQuery(
            new PdoStaffSelfServicePortalReadRepository($this->db)
        );
    }

    /**
     * Audited permission lifecycle for the shared worker portal.
     * Attendance supplies only its narrow approved-coverage change contract.
     */
    public function permissionRequests(
        AttendanceCoverageChangeGateway $coverageChanges
    ): PermissionRequestService {
        $requestRepository = new PdoPermissionRequestRepository($this->db);

        return new PermissionRequestService(
            $requestRepository,
            $requestRepository,
            new PermissionPolicyResolver(
                new PdoPermissionPolicyReadRepository($this->db),
                $this->datedStaffAssignments()
            ),
            new PermissionQuotaLedger(
                new PdoPermissionQuotaLedgerRepository($this->db),
                $this->audit
            ),
            new StaffSelfServiceAuthorization($this->portalEligibility()),
            new ApprovalWorkflowResolver(
                new PdoApprovalWorkflowDefinitionQuery($this->db),
                new PdoManagerHierarchyQuery($this->db),
                new PdoApprovalRoleAssigneeQuery($this->db),
                new PdoApprovalDelegationQuery($this->db)
            ),
            $this->audit,
            null,
            $this->approvalWorkflowService($coverageChanges)
        );
    }

    /** Atomic transfer/service-end owner with permission quota release. */
    public function employmentLifecycle(AttendanceCoverageChangeGateway $coverageChanges): StaffEmploymentLifecycleService
    {
        $organization = new PdoStaffOrganizationRepository($this->db);
        $requestRepository = new PdoPermissionRequestRepository($this->db);
        $permissions = new PermissionRequestService(
            $requestRepository,
            $requestRepository,
            new PermissionPolicyResolver(new PdoPermissionPolicyReadRepository($this->db), $this->datedStaffAssignments()),
            new PermissionQuotaLedger(new PdoPermissionQuotaLedgerRepository($this->db), $this->audit),
            new StaffLifecyclePermissionAuthorization($organization),
            new ApprovalWorkflowResolver(
                new PdoApprovalWorkflowDefinitionQuery($this->db),
                new PdoManagerHierarchyQuery($this->db),
                new PdoApprovalRoleAssigneeQuery($this->db),
                new PdoApprovalDelegationQuery($this->db)
            ),
            $this->audit,
            null,
            $this->approvalWorkflowService($coverageChanges)
        );
        return new StaffEmploymentLifecycleService($organization, $permissions, $this->audit);
    }

    /**
     * Audited leave lifecycle for the shared worker portal. Attendance owns
     * workday resolution and approved-coverage publication behind contracts.
     */
    public function leaveRequests(
        LeaveWorkdayCalendarQuery $calendar,
        AttendanceCoverageChangeGateway $coverageChanges
    ): LeaveRequestService {
        $requestRepository = new PdoLeaveRequestRepository($this->db);

        return new LeaveRequestService(
            $requestRepository,
            $requestRepository,
            new LeavePolicyService(
                new PdoLeavePolicyReadRepository($this->db),
                $this->datedStaffAssignments(),
                new PdoStaffEmploymentQuery($this->db),
                $calendar,
                new EffectivePolicyResolver()
            ),
            $this->leaveBalanceLedger(),
            new StaffLeaveSelfServiceAuthorization($this->portalEligibility()),
            new ApprovalWorkflowResolver(
                new PdoApprovalWorkflowDefinitionQuery($this->db),
                new PdoManagerHierarchyQuery($this->db),
                new PdoApprovalRoleAssigneeQuery($this->db),
                new PdoApprovalDelegationQuery($this->db)
            ),
            $this->audit,
            null,
            $this->approvalWorkflowService($coverageChanges),
            new LeaveStaffingPolicy(new PdoLeaveStaffingReadRepository($this->db)),
            new PdoLeaveAttachmentRepository($this->db),
            new PdoLeaveStaffingOverrideRepository($this->db)
        );
    }

    public function leaveStaffingOverrides(LeaveWorkdayCalendarQuery $calendar): LeaveStaffingOverrideService
    {
        $requests = new PdoLeaveRequestRepository($this->db);
        return new LeaveStaffingOverrideService(
            $requests,
            $requests,
            new PdoLeaveStaffingOverrideRepository($this->db),
            new LeavePolicyService(
                new PdoLeavePolicyReadRepository($this->db),
                $this->datedStaffAssignments(),
                new PdoStaffEmploymentQuery($this->db),
                $calendar,
                new EffectivePolicyResolver()
            ),
            new LeaveStaffingPolicy(new PdoLeaveStaffingReadRepository($this->db)),
            new LeaveStaffingOverrideAuthorization(new PdoApprovalRoleAssigneeQuery($this->db)),
            $this->audit
        );
    }

    /**
     * Authenticated draft-only medical evidence upload for the worker portal.
     * The storage adapter keeps the physical path private while the repository
     * and shared audit writer participate in the same metadata transaction.
     */
    public function leaveAttachments(): LeaveAttachmentService
    {
        return new LeaveAttachmentService(
            new PdoLeaveAttachmentRepository($this->db),
            new LocalLeaveAttachmentStorage(dirname(__DIR__, 4)),
            new StaffLeaveSelfServiceAuthorization($this->portalEligibility()),
            $this->audit
        );
    }

    /**
     * Aggregates summary-only sources from the resource owners. Each source is
     * independently failure-contained by StaffHrTimelineQuery.
     */
    public function staffTimeline(): StaffHrTimelineQuery
    {
        return new StaffHrTimelineQuery([
            new PdoStaffAssignmentTimelineEventSource($this->db),
            new PdoStaffCredentialTimelineEventSource($this->db),
        ]);
    }

    /**
     * Owns immutable qualification/training/document evidence and its safe
     * expiry notifications. The notification outbox remains the audited
     * delivery owner.
     */
    public function documentExpiryService(): StaffDocumentExpiryService
    {
        return new StaffDocumentExpiryService(
            new PdoStaffCredentialRepository($this->db),
            new PdoStaffNotificationOutbox($this->db, $this->audit),
            $this->audit
        );
    }

    /** Read-only, data-scoped inbox for the currently authenticated approver. */
    public function assignedApprovalInbox(): AssignedApprovalInboxQuery
    {
        return new AssignedApprovalInboxQuery(
            new PdoAssignedApprovalInboxReadRepository($this->db)
        );
    }

    /**
     * Read-only, directly assigned Ertaq inbox. The query boundary keeps an
     * administrative page from broad-scanning confidential ticket tables.
     */
    public function ertaqInboxQuery(): ErtaqInboxQuery
    {
        return new ErtaqInboxQuery(
            new PdoErtaqInboxReadRepository($this->db)
        );
    }

    /** Worker-only ticket creation with server-owned confidential defaults. */
    public function ertaqWorkerTickets(): ErtaqTicketService
    {
        $policy = new StaffErtaqSelfServicePolicy($this->portalEligibility());

        return new ErtaqTicketService(
            new PdoErtaqTicketRepository($this->db),
            $policy,
            $policy,
            $this->audit,
            new ErtaqSlaService(
                new PdoErtaqSlaRepository($this->db),
                $policy,
                $this->audit
            )
        );
    }

    /** Worker replies are forced to requester visibility and owner scope. */
    public function ertaqWorkerConversation(): ErtaqConversationService
    {
        return new ErtaqConversationService(
            new PdoErtaqConversationRepository($this->db),
            new StaffErtaqSelfServicePolicy($this->portalEligibility()),
            $this->audit
        );
    }

    public function ertaqCaseManagementConversation(): ErtaqConversationService
    {
        return new ErtaqConversationService(
            new PdoErtaqConversationRepository($this->db),
            new PdoErtaqConversationAuthorization($this->db),
            $this->audit
        );
    }

    public function ertaqUrgentRouting(): ErtaqUrgentRoutingService
    {
        return new ErtaqUrgentRoutingService(
            new PdoErtaqUrgentRoutingRepository($this->db),
            new PdoErtaqUrgentRoutingAuthorization($this->db),
            $this->audit
        );
    }

    public function ertaqUrgentInbox(): ErtaqUrgentInboxQuery
    {
        return new ErtaqUrgentInboxQuery(new PdoErtaqUrgentInboxReadRepository($this->db));
    }

    /**
     * Composes the resource-aware Staff outcome bridge. The shared PDO keeps
     * decision, quota/balance, resource state, audit, attendance change, and
     * Staff outbox facts in one transaction.
     */
    public function approvalWorkflowService(
        AttendanceCoverageChangeGateway $coverageChanges,
        ?ApprovalWorkflowOutcomeHandler $scheduleChangeOutcomes = null
    ): ApprovalWorkflowService
    {
        $leaveLedgerRepository = new PdoLeaveBalanceLedgerRepository($this->db);

        return new ApprovalWorkflowService(
            new PdoApprovalWorkflowRepository($this->db),
            new StaffApprovalOutcomeRouter(
                new PermissionApprovalOutcomeHandler(
                    new PdoPermissionRequestRepository($this->db),
                    new PermissionQuotaLedger(
                        new PdoPermissionQuotaLedgerRepository($this->db),
                        $this->audit
                    ),
                    $this->audit,
                    new ApprovedCoveragePublisher($coverageChanges, $this->audit)
                ),
                new LeaveApprovalOutcomeHandler(
                    new PdoLeaveRequestRepository($this->db),
                    new LeaveBalanceLedger($leaveLedgerRepository, $this->audit),
                    $leaveLedgerRepository,
                    $coverageChanges,
                    $this->leaveFinanceEffectQueue(),
                    $this->audit
                ),
                new DisciplineDecisionApprovalOutcomeHandler(
                    new PdoDisciplineDecisionRepository($this->db),
                    new PdoStaffNotificationOutbox($this->db, $this->audit),
                    $this->audit,
                    null,
                    'admin/disciplinary.php',
                    $this->disciplineFinanceEffectQueue()
                ),
                $scheduleChangeOutcomes
            ),
            $this->audit,
            null,
            $this->approvalNotifications(),
            $this->approvalTransitionAuthorization()
        );
    }
}
