<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Staff\Presentation\ManagerApprovalInbox;
use EduCore\Modules\Staff\Presentation\StaffSelfServiceRequests;

$view = [
    'csrf_token' => 'csrf-leave-portal-test-token',
    'draft_scope' => '42',
    'create_idempotency_key' => 'leave-create-portal-test-key',
    'submission_idempotency_key' => 'leave-submit-portal-test-key',
    'action_url' => 'self_service_requests.php?tab=leave',
    'staff_display_name' => 'عامل تجريبي',
    'timezone' => 'Africa/Cairo',
    'leave_types' => [[
        'id' => 7,
        'name' => '<script>alert(1)</script> إجازة اعتيادية',
        'unit' => 'day',
        'requires_reason' => false,
        'requires_attachment' => true,
        'requires_medical_document' => true,
    ]],
    'balance_rows' => [[
        'type_name' => '<img src=x onerror=alert(2)> إجازة اعتيادية',
        'period_key' => 'CY-2026',
        'available_units' => '18.000',
        'held_units' => '1.000',
        'used_units' => '2.000',
    ]],
    'field_errors' => [
        'reason' => 'LEAVE_REQUEST_REASON_REQUIRED',
    ],
    'feedback' => [
        'kind' => 'danger',
        'message' => 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry',
    ],
    'requests' => [
        [
            'id' => 81,
            'leave_type_name' => 'إجازة اعتيادية',
            'request_kind' => 'leave',
            'from_at' => '2026-08-08 08:00:00',
            'to_at' => '2026-08-08 16:00:00',
            'requested_units' => '1.000',
            'requested_minutes' => 480,
            'status' => 'draft',
            'workflow_label' => 'راجِع الطلب ثم أرسله',
            'attachment_status' => 'required',
            'lock_version' => 3,
            'actions' => [
                'submit' => ['idempotency_key' => 'leave-submit-existing-test-key'],
                'withdraw' => true,
                'attach_medical' => true,
            ],
        ],
        [
            'id' => 82,
            'leave_type_name' => 'إجازة مرضية',
            'request_kind' => 'extension',
            'from_at' => '2026-08-09 08:00:00',
            'to_at' => '2026-08-10 16:00:00',
            'requested_units' => '2.000',
            'requested_minutes' => 960,
            'status' => 'pending_approval',
            'workflow_label' => 'بانتظار قرار المدير المباشر',
            'attachment_status' => 'attached',
            'lock_version' => 2,
            'actions' => [],
        ],
    ],
];

$html = StaffSelfServiceRequests::renderLeavePortal($view);
$managerHtml = ManagerApprovalInbox::renderInbox([
    'csrf_token' => 'csrf-manager-leave-test-token',
    'action_url' => 'hr_center.php?tab=assigned_approvals',
    'total' => 1,
    'items' => [[
        'instance_id' => 501,
        'step_id' => 502,
        'step_lock_version' => 4,
        'resource_type' => 'leave_request',
        'resource_id' => 81,
        'sequence_no' => 1,
        'stage_name' => 'اعتماد المدير المباشر',
        'decision_mode' => 'sequential',
        'due_state' => 'open',
        'due_at' => '2026-08-08 12:00:00.000000',
        'staff_user_id' => 42,
        'staff_display_name' => 'عامل تجريبي',
        'request_id' => 81,
        'actions' => [
            'approve' => ['idempotency_key' => 'leave-approval-approve-test-key'],
            'reject' => ['idempotency_key' => 'leave-approval-reject-test-key'],
        ],
    ]],
]);
$leaveService = (string) file_get_contents($root . '/src/Modules/Staff/Application/Leave/LeaveRequestService.php');
$attachmentService = (string) file_get_contents($root . '/src/Modules/Staff/Application/Leave/LeaveAttachmentService.php');
$outcomeRouter = (string) file_get_contents($root . '/src/Modules/Staff/Application/Approval/StaffApprovalOutcomeRouter.php');
$portalReadRepository = (string) file_get_contents($root . '/src/Modules/Staff/Infrastructure/PdoStaffSelfServicePortalReadRepository.php');
$formSafety = (string) file_get_contents($root . '/assets/js/form-safety.js');

$checks = [
    'portal_has_explicit_csrf_and_scoped_draft_key' => str_contains($html, 'name="csrf_token" value="csrf-leave-portal-test-token"')
        && str_contains($html, 'data-draft-scope="staff:42:leave"')
        && str_contains($formSafety, 'form.dataset.draftScope ||'),
    'portal_never_accepts_mutable_staff_identity' => !str_contains($html, 'name="staff_user_id"')
        && !str_contains($html, 'name="user_id"'),
    'portal_carries_create_submit_and_lock_evidence' => str_contains($html, 'name="create_idempotency_key" value="leave-create-portal-test-key"')
        && str_contains($html, 'name="submission_idempotency_key" value="leave-submit-portal-test-key"')
        && str_contains($html, 'name="expected_lock_version" value="3"')
        && str_contains($html, 'leave-submit-existing-test-key'),
    'portal_attaches_files_only_through_private_upload_intent' => str_contains($html, 'enctype="multipart/form-data"')
        && str_contains($html, 'name="leave_request_intent" value="upload_medical_attachment"')
        && str_contains($html, 'name="file"')
        && !str_contains($html, 'name="supporting_document_ref"'),
    'portal_uses_bootstrap_modal_for_draft_withdrawal' => str_contains($html, 'staffLeaveWithdrawModal-81')
        && str_contains($html, 'data-bs-toggle="modal"')
        && str_contains($html, 'name="leave_request_intent" value="withdraw"'),
    'portal_escapes_untrusted_leave_type_name' => !str_contains($html, '<script>alert(1)</script>')
        && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt; إجازة اعتيادية'),
    'portal_shows_modern_leave_balance_without_exposing_untrusted_markup' => str_contains($html, 'رصيد إجازاتي')
        && str_contains($html, 'CY-2026')
        && str_contains($html, '18.000 وحدة')
        && str_contains($html, '1.000 وحدة')
        && str_contains($html, '2.000 وحدة')
        && !str_contains($html, '<img src=x onerror=alert(2)>')
        && str_contains($html, '&lt;img src=x onerror=alert(2)&gt; إجازة اعتيادية'),
    'portal_reads_only_open_leave_balance_accounts' => str_contains($portalReadRepository, "account_row.status = 'open'")
        && !str_contains($portalReadRepository, "account_row.status = 'active'"),
    'portal_request_query_carries_server_owned_attachment_requirements' => str_contains($portalReadRepository, 'type_row.requires_attachment')
        && str_contains($portalReadRepository, 'type_row.requires_medical_document'),
    'portal_hides_technical_error_details_and_maps_leave_errors' => !str_contains($html, 'SQLSTATE')
        && !str_contains($html, 'Duplicate entry')
        && str_contains($html, 'تعذر إتمام طلب الإجازة الآن')
        && str_contains($html, 'اكتب سبب الإجازة قبل الإرسال.'),
    'portal_makes_request_lifecycle_visible' => str_contains($html, 'مسار الطلب')
        && str_contains($html, 'مرفق مطلوب')
        && str_contains($html, 'بانتظار الموافقة'),
    'leave_service_owns_self_scope_and_approval_submission' => substr_count($leaveService, 'assertSelfActor(') >= 4
        && str_contains($leaveService, "'leave_request'")
        && str_contains($leaveService, 'submission_idempotency_key'),
    'attachment_service_authorizes_before_private_storage' => str_contains($attachmentService, 'assertSelfActor($actorId, $staffUserId)')
        && str_contains($attachmentService, 'storeUploadedFile($file)')
        && str_contains($attachmentService, "status'] ?? '') !== 'draft'"),
    'manager_inbox_routes_leave_through_shared_workflow_only' => str_contains($managerHtml, 'طلب إجازة')
        && str_contains($managerHtml, 'name="approval_intent" value="decide"')
        && str_contains($managerHtml, 'leave-approval-approve-test-key')
        && str_contains($outcomeRouter, "'leave_request'"),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
