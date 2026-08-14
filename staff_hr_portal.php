<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/utilities.php';
require_once __DIR__ . '/includes/session_config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Modules/Operations/Audit/AuditService.php';
require_once __DIR__ . '/src/Modules/Staff/bootstrap.php';
require_once __DIR__ . '/src/Modules/Attendance/bootstrap.php';
require_once __DIR__ . '/src/Modules/Staff/Presentation/self_service_requests.php';
require_once __DIR__ . '/src/Modules/Staff/Presentation/manager_approval_inbox.php';
require_once __DIR__ . '/src/Modules/Staff/Presentation/ertaq_portal.php';

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;
use EduCore\Modules\Staff\Presentation\ErtaqPortal;
use EduCore\Modules\Staff\Presentation\ManagerApprovalInbox;
use EduCore\Modules\Staff\Presentation\StaffSelfServiceRequests;

// This route is shared by the existing worker portals. Authentication is
// refreshed once here, while Staff eligibility remains independent of the
// currently selected teacher/specialist/supervisor/admin role.
Utilities::validateSession();

$actorId = (int) ($_SESSION['user_id'] ?? 0);
$csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
$activeRole = (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
$backUrl = match ($activeRole) {
    'teacher' => 'teacher/portal.php',
    'supervisor' => 'supervisor/index.php',
    'specialist' => 'specialist/index.php',
    'employee' => 'logout.php',
    default => 'admin/index.php',
};

$db = (new Database())->getConnection();
$audit = new AuditService($db);
$factory = new StaffModuleFactory($db, $audit);
$attendanceFactory = new AttendanceModuleFactory($db, $audit);
$adjustmentService = $attendanceFactory->attendanceAdjustmentService(
    $attendanceFactory->attendanceAdjustmentAuthorization($factory->currentStaffAccess())
);
$alternativeAttendance = $attendanceFactory->alternativeAttendanceRecorder(
    $attendanceFactory->alternativeAttendanceAuthorization($factory->currentStaffAccess())
);

try {
    $eligibility = $factory->portalEligibility()->forUser($actorId, new DateTimeImmutable('now'));
} catch (Throwable $exception) {
    error_log('Staff HR portal eligibility failed: ' . $exception->getMessage());
    $eligibility = ['eligible' => false, 'capabilities' => []];
}

if (($eligibility['eligible'] ?? false) !== true || (int) ($eligibility['staff_id'] ?? 0) !== $actorId) {
    http_response_code(403);
    $portalError = 'لا تتوفر لك خدمات العاملين وفق حالة خدمتك وتعيينك الحاليين.';
} else {
    $portalError = null;
}

$capabilities = array_values(array_filter(
    (array) ($eligibility['capabilities'] ?? []),
    static fn (mixed $value): bool => is_string($value)
));
$canUseSelfService = in_array('staff.portal.self_service', $capabilities, true);
// The inbox query and command service both authorize the concrete assigned
// actor. This also covers named workflow assignees (for example HR) who are
// not a direct/administrative manager in the organization hierarchy.
$canUseManagerInbox = $portalError === null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($csrfToken === '' || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        $csrfFeedbackKey = !empty($_POST['alternative_attendance_intent'])
            ? 'staff_hr_alternative_attendance_feedback'
            : (!empty($_POST['schedule_change_intent'])
            ? 'staff_hr_schedule_change_feedback'
            : (!empty($_POST['attendance_adjustment_intent'])
            ? 'staff_hr_adjustment_feedback'
            : (!empty($_POST['approval_intent'])
            ? 'staff_hr_approval_feedback'
            : (!empty($_POST['discipline_intent'])
            ? 'staff_hr_discipline_feedback'
            : (!empty($_POST['ertaq_intent'])
                ? 'staff_hr_ertaq_feedback'
                : (!empty($_POST['leave_request_intent']) ? 'staff_hr_leave_feedback' : 'staff_hr_permission_feedback'))))));
        $_SESSION[$csrfFeedbackKey] = ['kind' => 'danger', 'code' => 'CSRF_INVALID'];
    } else {
        try {
            $approvalIntent = trim((string) ($_POST['approval_intent'] ?? ''));
            $disciplineIntent = trim((string) ($_POST['discipline_intent'] ?? ''));
            $ertaqIntent = trim((string) ($_POST['ertaq_intent'] ?? ''));
            $leaveIntent = trim((string) ($_POST['leave_request_intent'] ?? ''));
            $permissionIntent = trim((string) ($_POST['permission_request_intent'] ?? ''));
            $adjustmentIntent = trim((string) ($_POST['attendance_adjustment_intent'] ?? ''));
            $scheduleChangeIntent = trim((string) ($_POST['schedule_change_intent'] ?? ''));
            $alternativeAttendanceIntent = trim((string) ($_POST['alternative_attendance_intent'] ?? ''));
            if (count(array_filter([$approvalIntent, $disciplineIntent, $ertaqIntent, $leaveIntent, $permissionIntent, $adjustmentIntent, $scheduleChangeIntent, $alternativeAttendanceIntent], static fn (string $intent): bool => $intent !== '')) !== 1) {
                throw new DomainException('PERMISSION_REQUEST_FORBIDDEN');
            }

            if ($disciplineIntent !== '') {
                if ($portalError !== null || !$canUseSelfService) {
                    throw new DomainException('DISCIPLINE_ACCESS_DENIED');
                }
                $discipline = $factory->disciplineAppeals();
                if ($disciplineIntent === 'request_interim') {
                    $receipt = $discipline->requestInterimMeasure([
                        'actor_id' => $actorId,
                        'case_id' => $_POST['case_id'] ?? null,
                        'expected_case_lock_version' => $_POST['expected_case_lock_version'] ?? null,
                        'basis_evidence_id' => $_POST['basis_evidence_id'] ?? null,
                        'measure_type' => $_POST['measure_type'] ?? null,
                        'reason' => $_POST['reason'] ?? null,
                        'starts_at' => $_POST['starts_at'] ?? null,
                        'ends_at' => $_POST['ends_at'] ?? null,
                        'review_due_at' => $_POST['review_due_at'] ?? null,
                        'access_effect' => ['mode' => 'none', 'source' => 'worker_request'],
                        'idempotency_key' => $_POST['idempotency_key'] ?? null,
                    ]);
                    $message = 'تم تسجيل طلب الإجراء المؤقت، ولن يصبح نافذاً قبل اعتماد مدير آخر.';
                } elseif ($disciplineIntent === 'request_reopen') {
                    $receipt = $discipline->requestReopen([
                        'actor_id' => $actorId,
                        'case_id' => $_POST['case_id'] ?? null,
                        'prior_decision_id' => $_POST['prior_decision_id'] ?? null,
                        'new_evidence_id' => $_POST['new_evidence_id'] ?? null,
                        'expected_case_lock_version' => $_POST['expected_case_lock_version'] ?? null,
                        'reopen_reason' => $_POST['reopen_reason'] ?? null,
                        'idempotency_key' => $_POST['idempotency_key'] ?? null,
                    ]);
                    $message = 'تم تسجيل طلب إعادة الفتح بالدليل الجديد دون تغيير القرار السابق.';
                } else {
                    throw new DomainException('DISCIPLINE_ACCESS_DENIED');
                }
                $_SESSION['staff_hr_discipline_feedback'] = [
                    'kind' => 'success',
                    'message' => $message,
                    'receipt' => $receipt,
                ];
            } elseif ($alternativeAttendanceIntent !== '') {
                if ($portalError !== null) {
                    throw new DomainException('ALTERNATIVE_ATTENDANCE_RECORD_NOT_AUTHORIZED');
                }
                if ($alternativeAttendanceIntent === 'create_method') {
                    $targetStaffId = (int) ($_POST['alternative_target_id'] ?? 0);
                    $managerAccess = $factory->currentStaffAccess()->assertCurrentAccess($actorId, 'attendance.alternative.review.manager', 'attendance:alternative:staff:' . $targetStaffId, new DateTimeImmutable('now'));
                    if (($managerAccess['allowed'] ?? false) !== true) throw new DomainException('ALTERNATIVE_ATTENDANCE_REVIEW_NOT_AUTHORIZED');
                    $receipt = $alternativeAttendance->createMethod(
                        $actorId,
                        (string) ($_POST['code'] ?? ''),
                        (string) ($_POST['name'] ?? ''),
                        'manual_verified',
                        'self_manager'
                    );
                } elseif ($alternativeAttendanceIntent === 'retire_method') {
                    $targetStaffId = (int) ($_POST['alternative_target_id'] ?? 0);
                    $managerAccess = $factory->currentStaffAccess()->assertCurrentAccess($actorId, 'attendance.alternative.review.manager', 'attendance:alternative:staff:' . $targetStaffId, new DateTimeImmutable('now'));
                    if (($managerAccess['allowed'] ?? false) !== true) throw new DomainException('ALTERNATIVE_ATTENDANCE_REVIEW_NOT_AUTHORIZED');
                    $receipt = $alternativeAttendance->retireMethod($actorId, (int) ($_POST['entry_method_id'] ?? 0));
                } elseif ($alternativeAttendanceIntent === 'record') {
                    if (!$canUseSelfService) throw new DomainException('ALTERNATIVE_ATTENDANCE_RECORD_NOT_AUTHORIZED');
                    $receipt = $alternativeAttendance->record(
                        $actorId,
                        $actorId,
                        (int) ($_POST['entry_method_id'] ?? 0),
                        new DateTimeImmutable((string) ($_POST['occurred_at'] ?? '')),
                        (string) ($_POST['reason'] ?? ''),
                        ['event_type' => $_POST['event_type'] ?? 'in', 'evidence_ref' => $_POST['evidence_ref'] ?? null],
                        (string) ($_POST['idempotency_key'] ?? '')
                    );
                } elseif ($alternativeAttendanceIntent === 'review') {
                    if (!$canUseManagerInbox) throw new DomainException('ALTERNATIVE_ATTENDANCE_REVIEW_NOT_AUTHORIZED');
                    $receipt = $alternativeAttendance->review(
                        $actorId,
                        (int) ($_POST['event_id'] ?? 0),
                        (string) ($_POST['decision'] ?? ''),
                        (string) ($_POST['comment'] ?? '')
                    );
                } else {
                    throw new DomainException('ALTERNATIVE_ATTENDANCE_RECORD_NOT_AUTHORIZED');
                }
                $_SESSION['staff_hr_alternative_attendance_feedback'] = [
                    'kind' => 'success', 'receipt' => $receipt, 'message' => 'تم حفظ إجراء وسيلة الحضور البديلة بنجاح.'
                ];
            } elseif ($scheduleChangeIntent !== '') {
                if ($portalError !== null || !$canUseSelfService) {
                    throw new DomainException('SCHEDULE_CHANGE_SUBMIT_FORBIDDEN');
                }
                $scheduleChanges = $attendanceFactory->scheduleChangeRequests();
                if ($scheduleChangeIntent === 'submit') {
                    $receipt = $scheduleChanges->submit($actorId, [
                        'staff_user_id' => $actorId,
                        'change_type' => $_POST['change_type'] ?? null,
                        'from_at' => $_POST['from_at'] ?? null,
                        'to_at' => $_POST['to_at'] ?? null,
                        'counterpart_staff_id' => $_POST['counterpart_staff_id'] ?? null,
                        'requested_schedule_version_id' => $_POST['requested_schedule_version_id'] ?? null,
                        'reason' => $_POST['reason'] ?? null,
                    ], (string) ($_POST['idempotency_key'] ?? ''));
                } elseif ($scheduleChangeIntent === 'accept_swap') {
                    $receipt = $scheduleChanges->acceptSwap(
                        (int) ($_POST['request_id'] ?? 0),
                        $actorId,
                        (int) ($_POST['expected_lock_version'] ?? 0),
                        new DateTimeImmutable('now'),
                        (string) ($_POST['idempotency_key'] ?? '')
                    );
                } elseif ($scheduleChangeIntent === 'link_workflow') {
                    $requestId = (int) ($_POST['request_id'] ?? 0);
                    $lockVersion = (int) ($_POST['expected_lock_version'] ?? 0);
                    $effectiveAt = new DateTimeImmutable((string) ($_POST['effective_at'] ?? 'now'));
                    $assignment = $factory->datedStaffAssignments()->forStaff($actorId, $effectiveAt);
                    if ($assignment === null) {
                        throw new DomainException('APPROVAL_ASSIGNMENT_SNAPSHOT_INVALID');
                    }
                    $approvedSnapshot = json_decode((string) ($_POST['approved_schedule_snapshot'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($approvedSnapshot)) {
                        throw new DomainException('SCHEDULE_CHANGE_SNAPSHOT_INVALID');
                    }
                    $resolvedAt = new DateTimeImmutable('now');
                    $workflow = $factory->approvalWorkflowResolver()->resolveForResource(
                        'schedule_change',
                        $actorId,
                        [
                            'actor_id' => $actorId,
                            'request_id' => $requestId,
                            'assignment_id' => (int) ($assignment['assignment_id'] ?? 0),
                            'assignment' => $assignment,
                            'approved_schedule_snapshot' => $approvedSnapshot,
                        ],
                        $effectiveAt,
                        $resolvedAt
                    );
                    $workflowService = $factory->approvalWorkflowService(
                        $attendanceFactory->approvedCoverageChangeGateway(),
                        $attendanceFactory->scheduleChangeApprovalOutcomes()
                    );
                    $approval = $workflowService->submit([
                        'actor_id' => $actorId,
                        'resource_type' => 'schedule_change',
                        'resource_id' => $requestId,
                        'workflow_version_id' => $workflow['workflow_version_id'],
                        'snapshot' => $workflow['snapshot'],
                        'idempotency_key' => (string) ($_POST['workflow_idempotency_key'] ?? ''),
                        'submitted_at' => $resolvedAt,
                    ]);
                    $receipt = $scheduleChanges->linkWorkflow(
                        $requestId,
                        (int) $approval['instance_id'],
                        $actorId,
                        $lockVersion,
                        (string) ($_POST['idempotency_key'] ?? '')
                    );
                    $receipt['workflow_instance_id'] = (int) $approval['instance_id'];
                } else {
                    throw new DomainException('SCHEDULE_CHANGE_SUBMIT_FORBIDDEN');
                }
                $_SESSION['staff_hr_schedule_change_feedback'] = [
                    'kind' => 'success',
                    'message' => 'تم حفظ إجراء تغيير الدوام بنجاح.',
                    'request_id' => (int) ($receipt['id'] ?? 0),
                    'lock_version' => (int) ($receipt['lock_version'] ?? 0),
                    'status' => (string) ($receipt['status'] ?? ''),
                    'workflow_instance_id' => (int) ($receipt['workflow_instance_id'] ?? 0),
                ];
            } elseif ($adjustmentIntent !== '') {
                if ($portalError !== null) {
                    throw new DomainException('ATTENDANCE_ADJUSTMENT_NOT_AUTHORIZED');
                }
                if ($adjustmentIntent === 'create_submit') {
                    if (!$canUseSelfService) {
                        throw new DomainException('ATTENDANCE_ADJUSTMENT_NOT_AUTHORIZED');
                    }
                    $proposed = [];
                    foreach (['first_in', 'last_out', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'missing_minutes', 'status'] as $field) {
                        if (isset($_POST[$field]) && trim((string) $_POST[$field]) !== '') {
                            $value = trim((string) $_POST[$field]);
                            if (in_array($field, ['first_in', 'last_out'], true)
                                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value) === 1) {
                                $value = str_replace('T', ' ', $value) . ':00';
                            }
                            $proposed[$field] = $value;
                        }
                    }
                    if (!$db->inTransaction()) {
                        $db->beginTransaction();
                    }
                    try {
                        $receipt = $adjustmentService->createDraft(
                            $actorId,
                            $actorId,
                            'self',
                            (string) ($_POST['work_date'] ?? ''),
                            (string) ($_POST['reason'] ?? ''),
                            $proposed,
                            (string) ($_POST['idempotency_key'] ?? '')
                        );
                        $adjustmentService->submit(
                            $actorId,
                            (int) $receipt['adjustment_id'],
                            (int) $receipt['lock_version']
                        );
                        $db->commit();
                    } catch (Throwable $exception) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        throw $exception;
                    }
                    $_SESSION['staff_hr_adjustment_feedback'] = ['kind' => 'success', 'message' => 'تم إرسال طلب تصحيح الحضور للمراجعة.'];
                } elseif ($adjustmentIntent === 'decide') {
                    $adjustmentService->decide(
                        $actorId,
                        (int) ($_POST['adjustment_id'] ?? 0),
                        (int) ($_POST['expected_lock_version'] ?? 0),
                        (string) ($_POST['decision'] ?? ''),
                        (string) ($_POST['resolution_comment'] ?? '')
                    );
                    $_SESSION['staff_hr_adjustment_feedback'] = ['kind' => 'success', 'message' => 'تم حفظ قرار تصحيح الحضور وإنشاء النسخة الرسمية الجديدة عند الاعتماد.'];
                } else {
                    throw new DomainException('ATTENDANCE_ADJUSTMENT_NOT_AUTHORIZED');
                }
            } elseif ($approvalIntent !== '') {
                if ($portalError !== null || !$canUseManagerInbox || $approvalIntent !== 'decide') {
                    throw new DomainException('APPROVAL_ACCESS_DENIED');
                }
                $factory->approvalWorkflowService(
                    $attendanceFactory->approvedCoverageChangeGateway(),
                    $attendanceFactory->scheduleChangeApprovalOutcomes()
                )->decide([
                    'actor_id' => $actorId,
                    'step_id' => $_POST['step_id'] ?? null,
                    'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
                    'decision' => $_POST['decision'] ?? null,
                    'comment' => $_POST['comment'] ?? null,
                    'idempotency_key' => $_POST['idempotency_key'] ?? null,
                    'decided_at' => new DateTimeImmutable('now'),
                ]);
                $_SESSION['staff_hr_approval_feedback'] = ['kind' => 'success', 'message' => 'تم تسجيل قرار الاعتماد بنجاح.'];
            } else {
                if ($portalError !== null || !$canUseSelfService) {
                    throw new DomainException('PERMISSION_REQUEST_FORBIDDEN');
                }
            if ($ertaqIntent !== '') {
                if ($ertaqIntent === 'create_ticket') {
                    $immediateRisk = (string)($_POST['immediate_risk'] ?? '') === '1';
                    if ($immediateRisk && !$db->inTransaction()) {
                        $db->beginTransaction();
                    }
                    try {
                        $receipt = $factory->ertaqWorkerTickets()->createTicket([
                            'actor_id' => $actorId,
                            'requester_user_id' => $actorId,
                            'type' => $_POST['type'] ?? null,
                            'subject' => $_POST['subject'] ?? null,
                            'classification' => 'general',
                            'confidentiality_level' => $immediateRisk ? 'highly_restricted' : ($_POST['confidentiality_level'] ?? 'restricted'),
                            'priority' => $immediateRisk ? 'urgent' : ($_POST['priority'] ?? 'normal'),
                            'risk_level' => $immediateRisk ? 'immediate' : 'none',
                            'create_idempotency_key' => $_POST['create_idempotency_key'] ?? null,
                        ]);
                        if ($immediateRisk) {
                            $urgentReceipt = $factory->ertaqUrgentRouting()->routeUrgentTicket([
                                'actor_id' => $actorId,
                                'ticket_id' => (int)($receipt['ticket_id'] ?? 0),
                                'expected_lock_version' => (int)($receipt['lock_version'] ?? 0),
                                'risk_type' => 'immediate_protection',
                                'idempotency_key' => substr('urgent:' . (string)($_POST['create_idempotency_key'] ?? ''), 0, 64),
                            ]);
                        }
                        if ($db->inTransaction()) {
                            $db->commit();
                        }
                    } catch (Throwable $exception) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        throw $exception;
                    }
                    $_SESSION['staff_hr_ertaq_feedback'] = [
                        'kind' => 'success',
                        'code' => $immediateRisk ? 'ERTAQ_URGENT_SUCCESS' : 'ERTAQ_CREATE_SUCCESS',
                        'receipt' => $immediateRisk ? ($urgentReceipt ?? []) : $receipt,
                    ];
                } elseif ($ertaqIntent === 'post_message') {
                    $factory->ertaqWorkerConversation()->postMessage([
                        'actor_id' => $actorId,
                        'ticket_id' => $_POST['ticket_id'] ?? null,
                        'idempotency_key' => $_POST['idempotency_key'] ?? null,
                        'message_type' => 'requester_message',
                        'body' => $_POST['body'] ?? null,
                        'reply_to_message_id' => $_POST['reply_to_message_id'] ?? null,
                        'visibility' => null,
                    ]);
                    $_SESSION['staff_hr_ertaq_feedback'] = ['kind' => 'success', 'message' => 'تم إرسال رسالتك داخل الطلب.'];
                } elseif ($ertaqIntent === 'request_withdrawal') {
                    $factory->ertaqWorkerConversation()->requestWithdrawal([
                        'actor_id' => $actorId,
                        'ticket_id' => $_POST['ticket_id'] ?? null,
                        'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
                        'withdrawal_reason' => $_POST['withdrawal_reason'] ?? null,
                        'idempotency_key' => $_POST['idempotency_key'] ?? null,
                    ]);
                    $_SESSION['staff_hr_ertaq_feedback'] = ['kind' => 'success', 'code' => 'ERTAQ_WITHDRAWAL_SUCCESS'];
                } else {
                    throw new DomainException('ERTAQ_ACCESS_DENIED');
                }
            } elseif ($leaveIntent !== '') {
                $leaveService = $factory->leaveRequests(
                    $attendanceFactory->leaveWorkdayCalendar(),
                    $attendanceFactory->approvedCoverageChangeGateway()
                );
                if ($leaveIntent === 'staffing_override') {
                    $receipt = $factory->leaveStaffingOverrides(
                        $attendanceFactory->leaveWorkdayCalendar()
                    )->decide([
                        'actor_id' => $actorId,
                        'request_id' => $_POST['request_id'] ?? null,
                        'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
                        'decision_outcome' => $_POST['decision_outcome'] ?? null,
                        'reason' => $_POST['reason'] ?? null,
                        'decision_idempotency_key' => $_POST['decision_idempotency_key'] ?? null,
                    ]);
                    $_SESSION['staff_hr_leave_feedback'] = [
                        'kind' => 'success',
                        'message' => (string) ($receipt['decision_outcome'] ?? '') === 'approved'
                            ? 'تم اعتماد تجاوز حد التشغيل للطلب مع تسجيل السبب.'
                            : 'تم رفض تجاوز حد التشغيل للطلب مع تسجيل السبب.',
                    ];
                } elseif (in_array($leaveIntent, ['draft', 'submit'], true)
                    && (int) ($_POST['request_id'] ?? 0) <= 0) {
                    $command = [
                        'actor_id' => $actorId,
                        'staff_user_id' => $actorId,
                        'leave_type_id' => $_POST['leave_type_id'] ?? null,
                        'from_at' => $_POST['from_at'] ?? null,
                        'to_at' => $_POST['to_at'] ?? null,
                        'timezone' => $_POST['timezone'] ?? 'Africa/Cairo',
                        'reason' => $_POST['reason'] ?? null,
                        'reason_code' => $_POST['reason_code'] ?? null,
                        // Private evidence is managed only by LeaveAttachmentService.
                        'supporting_document_ref' => null,
                        'create_idempotency_key' => $_POST['create_idempotency_key'] ?? null,
                    ];
                    if ($leaveIntent === 'submit' && !$db->inTransaction()) {
                        $db->beginTransaction();
                    }
                    try {
                        $receipt = $leaveService->createDraft($command);
                        if ($leaveIntent === 'submit') {
                            $leaveService->submit([
                                'actor_id' => $actorId,
                                'request_id' => (int) $receipt['request_id'],
                                'expected_lock_version' => (int) $receipt['lock_version'],
                                'submission_idempotency_key' => $_POST['submission_idempotency_key'] ?? null,
                            ]);
                        }
                        if ($db->inTransaction()) {
                            $db->commit();
                        }
                    } catch (Throwable $exception) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        throw $exception;
                    }
                    $_SESSION['staff_hr_leave_feedback'] = [
                        'kind' => 'success',
                        'message' => $leaveIntent === 'submit'
                            ? 'تم إرسال طلب الإجازة إلى مسار الاعتماد بنجاح.'
                            : 'تم حفظ مسودة الإجازة بنجاح.',
                    ];
                } elseif ($leaveIntent === 'upload_medical_attachment') {
                    $factory->leaveAttachments()->uploadMedicalAttachment([
                        'actor_id' => $actorId,
                        'request_id' => $_POST['request_id'] ?? null,
                        'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
                        'file' => $_FILES['file'] ?? null,
                    ]);
                    $_SESSION['staff_hr_leave_feedback'] = [
                        'kind' => 'success',
                        'message' => 'تم رفع المستند الطبي وحفظه في التخزين الخاص الآمن.',
                    ];
                } elseif ($leaveIntent === 'submit') {
                    $leaveService->submit([
                        'actor_id' => $actorId,
                        'request_id' => $_POST['request_id'] ?? null,
                        'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
                        'submission_idempotency_key' => $_POST['submission_idempotency_key'] ?? null,
                    ]);
                    $_SESSION['staff_hr_leave_feedback'] = ['kind' => 'success', 'message' => 'تم إرسال مسودة الإجازة إلى مسار الاعتماد.'];
                } elseif ($leaveIntent === 'withdraw') {
                    $leaveService->withdrawDraft(
                        $actorId,
                        (int) ($_POST['request_id'] ?? 0),
                        (int) ($_POST['expected_lock_version'] ?? 0)
                    );
                    $_SESSION['staff_hr_leave_feedback'] = ['kind' => 'success', 'message' => 'تم سحب مسودة الإجازة.'];
                } else {
                    throw new DomainException('LEAVE_REQUEST_FORBIDDEN');
                }
            } else {
                $permissionService = $factory->permissionRequests(
                    $attendanceFactory->approvedCoverageChangeGateway()
                );
                if (in_array($permissionIntent, ['draft', 'submit'], true)
                && (int) ($_POST['request_id'] ?? 0) <= 0) {
                $command = [
                    'actor_id' => $actorId,
                    'staff_user_id' => $actorId,
                    'permission_type_id' => $_POST['permission_type_id'] ?? null,
                    'from_at' => $_POST['from_at'] ?? null,
                    'to_at' => $_POST['to_at'] ?? null,
                    'timezone' => $_POST['timezone'] ?? 'Africa/Cairo',
                    'custom_label' => $_POST['custom_label'] ?? null,
                    'reason' => $_POST['reason'] ?? null,
                    'attachment_ref' => null,
                    'create_idempotency_key' => $_POST['create_idempotency_key'] ?? null,
                ];

                if ($permissionIntent === 'submit' && !$db->inTransaction()) {
                    $db->beginTransaction();
                }
                try {
                    $receipt = $permissionService->createDraft($command);
                    if ($permissionIntent === 'submit') {
                        $permissionService->submit([
                            'actor_id' => $actorId,
                            'request_id' => (int) $receipt['request_id'],
                            'expected_lock_version' => (int) $receipt['lock_version'],
                            'submission_idempotency_key' => $_POST['submission_idempotency_key'] ?? null,
                        ]);
                    }
                    if ($db->inTransaction()) {
                        $db->commit();
                    }
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $exception;
                }
                $_SESSION['staff_hr_permission_feedback'] = [
                    'kind' => 'success',
                    'message' => $permissionIntent === 'submit'
                        ? 'تم إرسال طلب الإذن إلى مسار الاعتماد بنجاح.'
                        : 'تم حفظ مسودة الإذن بنجاح.',
                ];
            } elseif ($permissionIntent === 'submit') {
                $permissionService->submit([
                    'actor_id' => $actorId,
                    'request_id' => $_POST['request_id'] ?? null,
                    'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
                    'submission_idempotency_key' => $_POST['submission_idempotency_key'] ?? null,
                ]);
                $_SESSION['staff_hr_permission_feedback'] = ['kind' => 'success', 'message' => 'تم إرسال مسودة الإذن إلى مسار الاعتماد.'];
            } elseif ($permissionIntent === 'withdraw') {
                $permissionService->withdrawDraft(
                    $actorId,
                    (int) ($_POST['request_id'] ?? 0),
                    (int) ($_POST['expected_lock_version'] ?? 0)
                );
                $_SESSION['staff_hr_permission_feedback'] = ['kind' => 'success', 'message' => 'تم سحب مسودة الإذن.'];
            } else {
                throw new DomainException('PERMISSION_REQUEST_FORBIDDEN');
            }
            }
            }
        } catch (Throwable $exception) {
            $reference = 'STAFF-PORTAL-' . strtoupper(bin2hex(random_bytes(4)));
            error_log($reference . ' staff self-service command failed: ' . $exception->getMessage());
            $feedbackKey = !empty($_POST['alternative_attendance_intent'])
                ? 'staff_hr_alternative_attendance_feedback'
                : (!empty($_POST['schedule_change_intent'])
                ? 'staff_hr_schedule_change_feedback'
                : (!empty($_POST['attendance_adjustment_intent'])
                ? 'staff_hr_adjustment_feedback'
                : (!empty($_POST['approval_intent'])
                ? 'staff_hr_approval_feedback'
                : (!empty($_POST['discipline_intent'])
                ? 'staff_hr_discipline_feedback'
                : (!empty($_POST['ertaq_intent'])
                    ? 'staff_hr_ertaq_feedback'
                    : (!empty($_POST['leave_request_intent']) ? 'staff_hr_leave_feedback' : 'staff_hr_permission_feedback'))))));
            $_SESSION[$feedbackKey] = [
                'kind' => 'danger',
                'code' => $exception->getMessage(),
            ];
        }
    }
    header('Location: staff_hr_portal.php');
    exit;
}

$managerInbox = ['items' => [], 'total' => 0];
if ($portalError === null && $canUseManagerInbox) {
    try {
        $managerInbox = $factory->assignedApprovalInbox()->forAssignee($actorId, ['per_page' => 50]);
    } catch (Throwable $exception) {
        error_log('Staff HR assigned inbox failed: ' . $exception->getMessage());
    }
}

$managerItems = array_map(
    static function (array $item) use ($actorId): array {
        $stepId = (int) ($item['step_id'] ?? 0);
        $lockVersion = (int) ($item['step_lock_version'] ?? 0);
        return $item + ['actions' => $stepId > 0 && $lockVersion > 0 ? [
            'approve' => "approval:approve:{$actorId}:{$stepId}:{$lockVersion}",
            'reject' => "approval:reject:{$actorId}:{$stepId}:{$lockVersion}",
        ] : []];
    },
    (array) ($managerInbox['items'] ?? [])
);
$approvalFeedback = is_array($_SESSION['staff_hr_approval_feedback'] ?? null)
    ? $_SESSION['staff_hr_approval_feedback']
    : null;
unset($_SESSION['staff_hr_approval_feedback']);
$permissionFeedback = is_array($_SESSION['staff_hr_permission_feedback'] ?? null)
    ? $_SESSION['staff_hr_permission_feedback']
    : null;
unset($_SESSION['staff_hr_permission_feedback']);
$permissionPortal = ['permission_types' => [], 'quota_rows' => [], 'requests' => []];
$leaveFeedback = is_array($_SESSION['staff_hr_leave_feedback'] ?? null)
    ? $_SESSION['staff_hr_leave_feedback']
    : null;
unset($_SESSION['staff_hr_leave_feedback']);
$leavePortal = ['leave_types' => [], 'balance_rows' => [], 'requests' => []];
$adjustmentFeedback = is_array($_SESSION['staff_hr_adjustment_feedback'] ?? null)
    ? $_SESSION['staff_hr_adjustment_feedback']
    : null;
unset($_SESSION['staff_hr_adjustment_feedback']);
$scheduleChangeFeedback = is_array($_SESSION['staff_hr_schedule_change_feedback'] ?? null)
    ? $_SESSION['staff_hr_schedule_change_feedback']
    : null;
unset($_SESSION['staff_hr_schedule_change_feedback']);
$alternativeAttendanceFeedback = is_array($_SESSION['staff_hr_alternative_attendance_feedback'] ?? null)
    ? $_SESSION['staff_hr_alternative_attendance_feedback']
    : null;
unset($_SESSION['staff_hr_alternative_attendance_feedback']);
$disciplineFeedback = is_array($_SESSION['staff_hr_discipline_feedback'] ?? null)
    ? $_SESSION['staff_hr_discipline_feedback']
    : null;
unset($_SESSION['staff_hr_discipline_feedback']);
$alternativeAttendanceMethods = $portalError === null ? $alternativeAttendance->methods() : [];
$pendingAlternativeAttendance = $portalError === null && $canUseManagerInbox ? $alternativeAttendance->pendingEvents($actorId) : [];
$ownAdjustments = [];
$reviewAdjustments = [];
if ($portalError === null && $canUseSelfService) {
    try {
        $permissionPortal = $factory->permissionPortal()->forStaff($actorId, date('Y-m'));
    } catch (Throwable $exception) {
        error_log('Staff HR permission portal query failed: ' . $exception->getMessage());
        $permissionFeedback = ['kind' => 'danger', 'code' => 'PERMISSION_REQUEST_FORBIDDEN'];
    }
    try {
        $leavePortal = $factory->permissionPortal()->leaveForStaff($actorId);
    } catch (Throwable $exception) {
        error_log('Staff HR leave portal query failed: ' . $exception->getMessage());
        $leaveFeedback = ['kind' => 'danger', 'code' => 'LEAVE_REQUEST_FORBIDDEN'];
    }
    try {
        $ownAdjustments = $adjustmentService->forRequester($actorId, 50);
    } catch (Throwable $exception) {
        error_log('Staff HR attendance adjustment requester query failed: ' . $exception->getMessage());
        $adjustmentFeedback ??= ['kind' => 'danger', 'code' => 'ATTENDANCE_ADJUSTMENT_NOT_AUTHORIZED'];
    }
}
if ($portalError === null && $canUseManagerInbox) {
    try {
        $reviewAdjustments = $adjustmentService->pendingForReviewer($actorId, 100);
    } catch (Throwable $exception) {
        error_log('Staff HR attendance adjustment reviewer query failed: ' . $exception->getMessage());
    }
}
$staffName = trim((string) ($_SESSION['name'] ?? ''));
$idempotencyPrefix = bin2hex(random_bytes(16));
$ertaqFeedback = is_array($_SESSION['staff_hr_ertaq_feedback'] ?? null)
    ? $_SESSION['staff_hr_ertaq_feedback']
    : null;
unset($_SESSION['staff_hr_ertaq_feedback']);
$ertaqView = ['items' => [], 'total' => 0, 'selected_ticket' => null, 'messages' => [], 'access' => 'none'];
if ($portalError === null && $canUseSelfService) {
    try {
        $selectedTicketId = filter_var($_GET['ertaq_ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $ertaqView = $factory->ertaqInboxQuery()->forRequester(
            $actorId,
            ['status' => $_GET['ertaq_status'] ?? '', 'query' => $_GET['ertaq_query'] ?? '', 'limit' => 50],
            $selectedTicketId !== false && $selectedTicketId !== null ? (int) $selectedTicketId : null
        );
    } catch (Throwable $exception) {
        error_log('Staff HR Ertaq requester query failed: ' . $exception->getMessage());
        $ertaqFeedback = ['kind' => 'danger', 'code' => 'ERTAQ_ACCESS_DENIED'];
    }
}
$canReplyToErtaq = is_array($ertaqView['selected_ticket'] ?? null)
    && !in_array((string) ($ertaqView['selected_ticket']['status'] ?? ''), ['closed', 'cancelled'], true);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>خدمات شؤون العاملين</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/premium-dashboard.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link rel="stylesheet" href="assets/css/admin-unified.css">
</head>
<body class="admin-page app-light-mode">
<main class="container-fluid px-3 px-lg-4 py-4">
    <div class="admin-page-heading">
        <div>
            <h1 class="h2"><i class="fas fa-people-roof me-2 text-primary"></i>خدمات شؤون العاملين</h1>
            <p class="text-muted mb-0">الخدمة الذاتية وصندوق الاعتمادات ومنصة ارتق بهوية العامل الحالية.</p>
        </div>
        <div class="admin-top-actions">
            <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary shadow-sm">
                <i class="fas fa-arrow-right me-1"></i>العودة إلى البوابة
            </a>
        </div>
    </div>

    <?php if ($portalError !== null): ?>
        <div class="alert alert-danger" role="alert"><i class="fas fa-lock me-2"></i><?php echo htmlspecialchars($portalError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif (!$canUseSelfService): ?>
        <div class="alert alert-warning" role="alert"><i class="fas fa-triangle-exclamation me-2"></i>الخدمة الذاتية غير متاحة وفق حالة التعيين الحالية.</div>
    <?php else: ?>
        <?php echo StaffSelfServiceRequests::renderPortal([
            'csrf_token' => $csrfToken,
            'draft_scope' => (string) $actorId,
            'create_idempotency_key' => 'permission-create-' . $idempotencyPrefix,
            'submission_idempotency_key' => 'permission-submit-' . $idempotencyPrefix,
            'action_url' => 'staff_hr_portal.php',
            'timezone' => 'Africa/Cairo',
            'permission_types' => $permissionPortal['permission_types'],
            'quota_rows' => $permissionPortal['quota_rows'],
            'requests' => $permissionPortal['requests'],
            'feedback' => $permissionFeedback,
            'staff_display_name' => $staffName,
        ]); ?>

        <?php echo StaffSelfServiceRequests::renderLeavePortal([
            'csrf_token' => $csrfToken,
            'draft_scope' => (string) $actorId,
            'create_idempotency_key' => 'leave-create-' . $idempotencyPrefix,
            'submission_idempotency_key' => 'leave-submit-' . $idempotencyPrefix,
            'action_url' => 'staff_hr_portal.php',
            'timezone' => 'Africa/Cairo',
            'leave_types' => $leavePortal['leave_types'],
            'balance_rows' => $leavePortal['balance_rows'],
            'requests' => $leavePortal['requests'],
            'feedback' => $leaveFeedback,
            'staff_display_name' => $staffName,
        ]); ?>

        <?php if ($canUseManagerInbox): ?>
            <section class="card shadow-sm admin-card-surface mb-4" aria-labelledby="leaveStaffingOverrideTitle">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0" id="leaveStaffingOverrideTitle"><i class="fas fa-user-shield me-2"></i>تجاوز حد التشغيل وفترة الحظر</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">يُستخدم فقط عند ظهور طلب إجازة يتطلب استثناءً تشغيليًا. يلزم سبب صريح، وتتحقق الخدمة من صلاحية المدير الحالية وبصمة المسودة قبل القرار.</p>
                    <form method="post" action="staff_hr_portal.php" class="row g-3" id="leaveStaffingOverrideForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="leave_request_intent" value="staffing_override">
                        <input type="hidden" name="decision_idempotency_key" value="<?php echo htmlspecialchars('leave-staffing-override-' . $idempotencyPrefix, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="col-md-2"><label class="form-label">رقم الطلب</label><input class="form-control" type="number" min="1" name="request_id" required></div>
                        <div class="col-md-2"><label class="form-label">نسخة القفل</label><input class="form-control" type="number" min="1" name="expected_lock_version" required></div>
                        <div class="col-md-2"><label class="form-label">القرار</label><select class="form-select" name="decision_outcome" required><option value="approved">اعتماد التجاوز</option><option value="rejected">رفض التجاوز</option></select></div>
                        <div class="col-md-4"><label class="form-label">سبب القرار</label><input class="form-control" name="reason" maxlength="1000" required></div>
                        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-check-double me-1"></i>تسجيل القرار</button></div>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <section class="admin-list-surface mb-4" id="scheduleChangeSection">
            <div class="p-3 border-bottom">
                <h2 class="h5 mb-1"><i class="fas fa-right-left me-2 text-primary"></i>تبديل الدوام والعمل الإضافي</h2>
                <p class="text-muted small mb-0">يسجل الطلب أولًا، ويقبل طرف التبديل الطلب، ثم يربطه مقدمه بمسار اعتماد مستقل.</p>
            </div>
            <div class="p-3">
                <?php if ($scheduleChangeFeedback !== null): ?>
                    <div class="alert alert-<?php echo htmlspecialchars((string) ($scheduleChangeFeedback['kind'] ?? 'danger'), ENT_QUOTES, 'UTF-8'); ?>" role="alert"
                         data-schedule-change-request-id="<?php echo (int) ($scheduleChangeFeedback['request_id'] ?? 0); ?>"
                         data-schedule-change-lock-version="<?php echo (int) ($scheduleChangeFeedback['lock_version'] ?? 0); ?>"
                         data-schedule-change-status="<?php echo htmlspecialchars((string) ($scheduleChangeFeedback['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                         data-schedule-change-workflow-id="<?php echo (int) ($scheduleChangeFeedback['workflow_instance_id'] ?? 0); ?>">
                        <i class="fas fa-circle-info me-2"></i><?php echo htmlspecialchars((string) ($scheduleChangeFeedback['message'] ?? 'تعذر تنفيذ إجراء تغيير الدوام.'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="staff_hr_portal.php" class="row g-3 mb-4" id="scheduleChangeSubmitForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="schedule_change_intent" value="submit">
                    <input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars('schedule-change-submit-' . $idempotencyPrefix, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="col-md-3"><label class="form-label">نوع الطلب</label><select class="form-select" name="change_type" required><option value="shift_swap">تبديل وردية</option><option value="overtime">عمل إضافي</option></select></div>
                    <div class="col-md-3"><label class="form-label">العامل الطرف الآخر</label><input class="form-control" name="counterpart_staff_id" type="number" min="1"></div>
                    <div class="col-md-3"><label class="form-label">نسخة الدوام المطلوبة</label><input class="form-control" name="requested_schedule_version_id" type="number" min="1"></div>
                    <div class="col-md-3"><label class="form-label">من</label><input class="form-control" name="from_at" type="datetime-local" required></div>
                    <div class="col-md-3"><label class="form-label">إلى</label><input class="form-control" name="to_at" type="datetime-local" required></div>
                    <div class="col-md-7"><label class="form-label">السبب</label><input class="form-control" name="reason" maxlength="1000" required></div>
                    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-paper-plane me-1"></i>إرسال</button></div>
                </form>
                <div class="row g-3">
                    <div class="col-lg-5">
                        <form method="post" action="staff_hr_portal.php" id="scheduleSwapAcceptForm" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="schedule_change_intent" value="accept_swap"><input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars('schedule-change-accept-' . $idempotencyPrefix, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="col-5"><label class="form-label">رقم طلب التبديل</label><input class="form-control" name="request_id" type="number" min="1" required></div><div class="col-4"><label class="form-label">نسخة القفل</label><input class="form-control" name="expected_lock_version" type="number" min="1" required></div><div class="col-3 d-flex align-items-end"><button class="btn btn-success w-100" type="submit"><i class="fas fa-check me-1"></i>قبول</button></div>
                        </form>
                    </div>
                    <div class="col-lg-7">
                        <form method="post" action="staff_hr_portal.php" id="scheduleChangeWorkflowForm" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="schedule_change_intent" value="link_workflow"><input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars('schedule-change-link-' . $idempotencyPrefix, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="workflow_idempotency_key" value="<?php echo htmlspecialchars('schedule-change-workflow-' . $idempotencyPrefix, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="col-md-2"><label class="form-label">الطلب</label><input class="form-control" name="request_id" type="number" min="1" required></div><div class="col-md-2"><label class="form-label">القفل</label><input class="form-control" name="expected_lock_version" type="number" min="1" required></div><div class="col-md-3"><label class="form-label">تاريخ النفاذ</label><input class="form-control" name="effective_at" type="datetime-local" required></div><div class="col-md-5"><label class="form-label">لقطة الدوام المعتمدة JSON</label><textarea class="form-control" name="approved_schedule_snapshot" rows="2" required>{}</textarea></div><div class="col-12 text-end"><button class="btn btn-primary" type="submit"><i class="fas fa-route me-1"></i>إرسال للاعتماد</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <section class="admin-list-surface mb-4" id="alternativeAttendanceSection">
            <div class="p-3 border-bottom"><h2 class="h5 mb-1"><i class="fas fa-user-check me-2 text-primary"></i>وسيلة حضور بديلة مؤقتة</h2><p class="text-muted small mb-0">الدليل البديل يبقى معلقًا حتى يراجعه مدير آخر، ولا يجوز لمسجله اعتماد نفسه.</p></div>
            <div class="p-3">
                <?php if ($alternativeAttendanceFeedback !== null): $alternativeReceipt = (array) ($alternativeAttendanceFeedback['receipt'] ?? []); ?>
                    <div class="alert alert-<?php echo htmlspecialchars((string) ($alternativeAttendanceFeedback['kind'] ?? 'danger'), ENT_QUOTES, 'UTF-8'); ?>"
                         data-alternative-method-id="<?php echo (int) ($alternativeReceipt['method_id'] ?? 0); ?>"
                         data-alternative-event-id="<?php echo (int) ($alternativeReceipt['event_id'] ?? 0); ?>"
                         data-alternative-review-status="<?php echo htmlspecialchars((string) ($alternativeReceipt['review_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars((string) ($alternativeAttendanceFeedback['message'] ?? 'تعذر تنفيذ إجراء وسيلة الحضور البديلة.'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <?php if ($canUseManagerInbox): ?><form method="post" id="alternativeAttendanceMethodForm" class="row g-2 mb-3"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="alternative_attendance_intent" value="create_method"><div class="col-md-2"><label class="form-label">العامل</label><input class="form-control" name="alternative_target_id" type="number" min="1" required></div><div class="col-md-3"><label class="form-label">كود الوسيلة</label><input class="form-control" name="code" value="Q22-ALT-METHOD" required></div><div class="col-md-4"><label class="form-label">اسم الوسيلة</label><input class="form-control" name="name" value="إثبات حضور بديل مؤقت Q22" required></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-success w-100"><i class="fas fa-plus me-1"></i>منح الوسيلة</button></div></form><form method="post" id="alternativeAttendanceRetireForm" class="row g-2 mb-3"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="alternative_attendance_intent" value="retire_method"><div class="col-md-2"><label class="form-label">العامل</label><input class="form-control" name="alternative_target_id" type="number" min="1" required></div><div class="col-md-7"><label class="form-label">إنهاء الوسيلة المؤقتة</label><select class="form-select" name="entry_method_id" required><?php foreach ($alternativeAttendanceMethods as $method): ?><option value="<?php echo (int) $method['id']; ?>"><?php echo htmlspecialchars((string) $method['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-warning w-100"><i class="fas fa-ban me-1"></i>إنهاء الصلاحية</button></div></form><?php endif; ?>
                <form method="post" id="alternativeAttendanceRecordForm" class="row g-2 mb-3"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="alternative_attendance_intent" value="record"><input type="hidden" name="idempotency_key" value="alternative-record-<?php echo bin2hex(random_bytes(12)); ?>"><div class="col-md-3"><label class="form-label">الوسيلة</label><select class="form-select" name="entry_method_id" required><option value="">اختر</option><?php foreach ($alternativeAttendanceMethods as $method): ?><option value="<?php echo (int) $method['id']; ?>"><?php echo htmlspecialchars((string) $method['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">نوع الحركة</label><select class="form-select" name="event_type"><option value="in">حضور</option><option value="out">انصراف</option></select></div><div class="col-md-3"><label class="form-label">الوقت</label><input class="form-control" name="occurred_at" type="datetime-local" required></div><div class="col-md-2"><label class="form-label">مرجع الدليل</label><input class="form-control" name="evidence_ref" value="q22-form"></div><div class="col-md-2"><label class="form-label">السبب</label><input class="form-control" name="reason" value="تعذر استخدام جهاز البصمة" required></div><div class="col-12 text-end"><button class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>تسجيل للمراجعة</button></div></form>
                <?php if ($pendingAlternativeAttendance !== []): ?><div class="admin-table-wrap"><table class="table table-hover table-striped admin-data-table"><thead><tr><th>#</th><th>العامل</th><th>الوقت</th><th>المراجع</th></tr></thead><tbody><?php foreach ($pendingAlternativeAttendance as $event): ?><tr data-alternative-event-id="<?php echo (int) $event['id']; ?>"><td><?php echo (int) $event['id']; ?></td><td><?php echo (int) $event['staff_user_id']; ?></td><td><?php echo htmlspecialchars((string) $event['event_at_local'], ENT_QUOTES, 'UTF-8'); ?></td><td><form method="post" class="d-flex gap-2" data-no-form-safety="true"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="alternative_attendance_intent" value="review"><input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>"><input type="hidden" name="comment" value="تمت مراجعة الدليل البديل"><button class="btn btn-success btn-sm" name="decision" value="approved"><i class="fas fa-check me-1"></i>اعتماد</button><button class="btn btn-danger btn-sm" name="decision" value="rejected"><i class="fas fa-times me-1"></i>رفض</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            </div>
        </section>

        <section class="admin-list-surface mb-4" id="attendanceAdjustmentsSection">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 border-bottom">
                <div>
                    <h2 class="h5 mb-1"><i class="fas fa-clock-rotate-left me-2 text-primary"></i>طلبات تصحيح الحضور</h2>
                    <p class="text-muted small mb-0">اطلب تصحيح يوم رسمي مع الاحتفاظ بالنسخة السابقة وسجل المراجعة.</p>
                </div>
            </div>
            <div class="p-3">
                <?php if ($adjustmentFeedback !== null): ?>
                    <?php
                    $adjustmentKind = (string) ($adjustmentFeedback['kind'] ?? 'danger');
                    $adjustmentMessage = (string) ($adjustmentFeedback['message'] ?? match ((string) ($adjustmentFeedback['code'] ?? '')) {
                        'ATTENDANCE_ADJUSTMENT_SELF_DECISION_FORBIDDEN' => 'لا يجوز لمقدم طلب التصحيح اعتماد طلبه بنفسه.',
                        'ATTENDANCE_ADJUSTMENT_SOURCE_NOT_FOUND' => 'لا توجد نتيجة حضور رسمية لهذا اليوم يمكن تصحيحها.',
                        'ATTENDANCE_ADJUSTMENT_NOT_AUTHORIZED' => 'لا تملك صلاحية تنفيذ هذا الإجراء على طلب التصحيح.',
                        'ATTENDANCE_ADJUSTMENT_STALE' => 'تغيرت حالة الطلب. حدّث الصفحة ثم حاول مرة أخرى.',
                        default => 'تعذر تنفيذ طلب تصحيح الحضور. راجع البيانات ثم حاول مرة أخرى.',
                    });
                    ?>
                    <div class="alert alert-<?php echo htmlspecialchars($adjustmentKind, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-circle-info me-2"></i><?php echo htmlspecialchars($adjustmentMessage, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                    </div>
                <?php endif; ?>
                <form method="post" action="staff_hr_portal.php" id="attendanceAdjustmentRequestForm" class="row g-3 mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="attendance_adjustment_intent" value="create_submit">
                    <input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars('attendance-adjustment-' . $idempotencyPrefix, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="col-md-3"><label class="form-label" for="adjustmentWorkDate">اليوم المطلوب تصحيحه</label><input class="form-control" id="adjustmentWorkDate" name="work_date" type="date" required></div>
                    <div class="col-md-3"><label class="form-label" for="adjustmentFirstIn">وقت الحضور الصحيح</label><input class="form-control" id="adjustmentFirstIn" name="first_in" type="datetime-local"></div>
                    <div class="col-md-3"><label class="form-label" for="adjustmentLastOut">وقت الانصراف الصحيح</label><input class="form-control" id="adjustmentLastOut" name="last_out" type="datetime-local"></div>
                    <div class="col-md-3"><label class="form-label" for="adjustmentWorkedMinutes">دقائق العمل الصحيحة</label><input class="form-control" id="adjustmentWorkedMinutes" name="worked_minutes" type="number" min="0" max="1440"></div>
                    <div class="col-md-3"><label class="form-label" for="adjustmentStatus">الحالة المقترحة</label><select class="form-select" id="adjustmentStatus" name="status"><option value="">بدون تغيير</option><option value="present">حاضر</option><option value="partial">حضور جزئي</option><option value="absent">غائب</option><option value="non_working">غير يوم عمل</option></select></div>
                    <div class="col-md-9"><label class="form-label" for="adjustmentReason">سبب التصحيح</label><textarea class="form-control" id="adjustmentReason" name="reason" rows="2" maxlength="2000" required></textarea></div>
                    <div class="col-12 text-end"><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال طلب التصحيح</button></div>
                </form>

                <div class="admin-table-wrap mb-4">
                    <table class="table table-hover table-striped admin-data-table mb-0">
                        <thead><tr><th>#</th><th>اليوم</th><th>الحالة</th><th>السبب</th><th>النسخة المعتمدة</th></tr></thead>
                        <tbody>
                        <?php if ($ownAdjustments === []): ?><tr><td colspan="5" class="text-center text-muted py-3">لا توجد طلبات تصحيح مسجلة.</td></tr><?php endif; ?>
                        <?php foreach ($ownAdjustments as $adjustment): ?>
                            <tr data-adjustment-id="<?php echo (int) $adjustment['id']; ?>">
                                <td><?php echo (int) $adjustment['id']; ?></td><td><?php echo htmlspecialchars((string) $adjustment['work_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) $adjustment['status'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) $adjustment['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo !empty($adjustment['approved_version_id']) ? (int) $adjustment['approved_version_id'] : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($reviewAdjustments !== []): ?>
                    <h3 class="h6 mb-3"><i class="fas fa-user-check me-2 text-success"></i>طلبات تصحيح تنتظر قرارك</h3>
                    <div class="admin-table-wrap">
                        <table class="table table-hover table-striped admin-data-table mb-0">
                            <thead><tr><th>#</th><th>العامل</th><th>اليوم</th><th>السبب</th><th>الإجراء</th></tr></thead><tbody>
                            <?php foreach ($reviewAdjustments as $adjustment): ?>
                                <tr data-review-adjustment-id="<?php echo (int) $adjustment['id']; ?>"><td><?php echo (int) $adjustment['id']; ?></td><td><?php echo (int) $adjustment['staff_user_id']; ?></td><td><?php echo htmlspecialchars((string) $adjustment['work_date'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) $adjustment['reason'], ENT_QUOTES, 'UTF-8'); ?></td><td>
                                    <form method="post" action="staff_hr_portal.php" class="d-flex gap-2 flex-wrap" data-no-form-safety="true">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="attendance_adjustment_intent" value="decide"><input type="hidden" name="adjustment_id" value="<?php echo (int) $adjustment['id']; ?>"><input type="hidden" name="expected_lock_version" value="<?php echo (int) $adjustment['lock_version']; ?>">
                                        <input class="form-control form-control-sm" name="resolution_comment" value="تمت مراجعة دليل التصحيح" maxlength="2000" aria-label="تعليق القرار">
                                        <button class="btn btn-success btn-sm" name="decision" value="approved" type="submit"><i class="fas fa-check me-1"></i>اعتماد</button>
                                        <button class="btn btn-danger btn-sm" name="decision" value="rejected" type="submit"><i class="fas fa-times me-1"></i>رفض</button>
                                    </form>
                                </td></tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="admin-list-surface mb-4" id="disciplineSelfServiceSection">
            <div class="p-3 border-bottom">
                <h2 class="h5 mb-1"><i class="fas fa-scale-balanced me-2 text-primary"></i>طلبات مرتبطة بالقضايا التأديبية</h2>
                <p class="text-muted small mb-0">الإجراء المؤقت وإعادة الفتح طلبان مستقلان؛ لا يُمحى القرار أو الدليل السابق، ولا يجوز لمقدم الطلب اعتماد طلبه بنفسه.</p>
            </div>
            <div class="p-3">
                <?php if ($disciplineFeedback !== null): $disciplineReceipt = (array)($disciplineFeedback['receipt'] ?? []); ?>
                    <div class="alert alert-<?php echo htmlspecialchars((string)($disciplineFeedback['kind'] ?? 'danger'), ENT_QUOTES, 'UTF-8'); ?>"
                         data-discipline-feedback-code="<?php echo htmlspecialchars((string)($disciplineFeedback['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                         data-discipline-measure-id="<?php echo (int)($disciplineReceipt['measure_id'] ?? 0); ?>"
                         data-discipline-reopen-event-id="<?php echo (int)($disciplineReceipt['reopen_event_id'] ?? 0); ?>">
                        <?php echo htmlspecialchars((string)($disciplineFeedback['message'] ?? 'تعذر تنفيذ طلب القضية.'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <div class="row g-4">
                    <div class="col-lg-6">
                        <form method="post" action="staff_hr_portal.php" id="disciplineInterimRequestForm" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="discipline_intent" value="request_interim">
                            <input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars(substr('discipline-interim-' . $idempotencyPrefix, 0, 64), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="col-md-4"><label class="form-label">رقم القضية</label><input class="form-control" name="case_id" type="number" min="1" required></div>
                            <div class="col-md-4"><label class="form-label">نسخة القضية</label><input class="form-control" name="expected_case_lock_version" type="number" min="1" required></div>
                            <div class="col-md-4"><label class="form-label">رقم الدليل</label><input class="form-control" name="basis_evidence_id" type="number" min="1"></div>
                            <div class="col-md-6"><label class="form-label">نوع الإجراء المؤقت</label><input class="form-control" name="measure_type" value="temporary_duty_adjustment" maxlength="100" required></div>
                            <div class="col-md-6"><label class="form-label">السبب</label><input class="form-control" name="reason" maxlength="2000" required></div>
                            <div class="col-md-4"><label class="form-label">يبدأ</label><input class="form-control" name="starts_at" type="datetime-local" required></div>
                            <div class="col-md-4"><label class="form-label">ينتهي</label><input class="form-control" name="ends_at" type="datetime-local" required></div>
                            <div class="col-md-4"><label class="form-label">موعد المراجعة</label><input class="form-control" name="review_due_at" type="datetime-local"></div>
                            <div class="col-12 text-end"><button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane me-1"></i>إرسال الإجراء المؤقت</button></div>
                        </form>
                    </div>
                    <div class="col-lg-6">
                        <form method="post" action="staff_hr_portal.php" id="disciplineReopenRequestForm" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="discipline_intent" value="request_reopen">
                            <input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars(substr('discipline-reopen-' . $idempotencyPrefix, 0, 64), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="col-md-4"><label class="form-label">رقم القضية</label><input class="form-control" name="case_id" type="number" min="1" required></div>
                            <div class="col-md-4"><label class="form-label">نسخة القضية</label><input class="form-control" name="expected_case_lock_version" type="number" min="1" required></div>
                            <div class="col-md-4"><label class="form-label">القرار السابق</label><input class="form-control" name="prior_decision_id" type="number" min="1" required></div>
                            <div class="col-md-4"><label class="form-label">الدليل الجديد</label><input class="form-control" name="new_evidence_id" type="number" min="1" required></div>
                            <div class="col-md-8"><label class="form-label">سبب إعادة الفتح</label><input class="form-control" name="reopen_reason" maxlength="2000" required></div>
                            <div class="col-12 text-end"><button class="btn btn-primary" type="submit"><i class="fas fa-folder-open me-1"></i>طلب إعادة الفتح</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <?php echo ErtaqPortal::renderWorkerConversation([
            'items' => $ertaqView['items'],
            'selected_ticket' => $ertaqView['selected_ticket'],
            'messages' => $ertaqView['messages'],
            'staff_display_name' => $staffName,
            'view_url' => 'staff_hr_portal.php',
            'action_url' => 'staff_hr_portal.php',
            'csrf_token' => $csrfToken,
            'draft_scope' => (string) $actorId,
            'create_idempotency_key' => substr('ertaq-create-' . $idempotencyPrefix, 0, 64),
            'reply_idempotency_key' => substr('ertaq-reply-' . $idempotencyPrefix, 0, 64),
            'can_create' => true,
            'can_reply' => $canReplyToErtaq,
            'feedback' => $ertaqFeedback,
        ]); ?>

        <?php if ($canUseManagerInbox): ?>
            <?php echo ManagerApprovalInbox::renderInbox([
                'csrf_token' => $csrfToken,
                'action_url' => 'staff_hr_portal.php',
                'items' => $managerItems,
                'total' => (int) ($managerInbox['total'] ?? 0),
                'feedback' => $approvalFeedback,
            ]); ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/form-safety.js"></script>
<script src="assets/js/premium-dashboard.js"></script>
</body>
</html>
