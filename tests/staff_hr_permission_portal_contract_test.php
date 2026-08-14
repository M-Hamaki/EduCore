<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Staff\Presentation\StaffSelfServiceRequests;

$view = [
    'csrf_token' => 'csrf-portal-test-token',
    'draft_scope' => '42',
    'create_idempotency_key' => 'create-portal-test-key',
    'submission_idempotency_key' => 'new-submit-portal-test-key',
    'action_url' => 'permissions.php',
    'staff_display_name' => 'عامل تجريبي',
    'timezone' => 'Africa/Cairo',
    'permission_types' => [
        [
            'id' => 7,
            'name' => '<script>alert(1)</script> إذن آخر',
            'requires_reason' => true,
            'requires_custom_label' => true,
            'requires_attachment' => false,
        ],
    ],
    'quota_rows' => [
        [
            'type_name' => 'حضور متأخر',
            'available_count' => 2,
            'available_minutes' => 120,
            'held_count' => 1,
            'held_minutes' => 60,
            'used_count' => 1,
            'used_minutes' => 60,
        ],
    ],
    'field_errors' => [
        'reason' => 'PERMISSION_REQUEST_REASON_REQUIRED',
    ],
    'feedback' => [
        'kind' => 'danger',
        'message' => 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry',
    ],
    'requests' => [
        [
            'id' => 71,
            'type_name' => 'حضور متأخر',
            'from_at' => '2026-08-08 07:30:00',
            'to_at' => '2026-08-08 09:30:00',
            'requested_minutes' => 120,
            'status' => 'draft',
            'workflow_label' => 'راجِع الطلب ثم أرسله',
            'lock_version' => 3,
            'actions' => [
                'submit' => ['idempotency_key' => 'submit-portal-test-key'],
                'withdraw' => true,
            ],
        ],
        [
            'id' => 72,
            'type_name' => 'انصراف مبكر',
            'from_at' => '2026-08-09 12:00:00',
            'to_at' => '2026-08-09 14:30:00',
            'requested_minutes' => 150,
            'status' => 'pending_approval',
            'workflow_label' => 'بانتظار قرار المدير المباشر',
            'lock_version' => 2,
            'actions' => ['cancel' => true],
        ],
    ],
];

$html = StaffSelfServiceRequests::renderPortal($view);
$legacyHtml = StaffSelfServiceRequests::renderLegacyAdminModals([
    'csrf_token' => 'legacy-csrf',
    'staff_list' => [['id' => 12, 'name' => 'موظف تجريبي']],
    'permission_types' => ['late_arrival' => 'تأخير'],
    'status_labels' => ['approved' => 'موافق عليه'],
    'today' => '2026-08-08',
]);
$service = (string) file_get_contents($root . '/src/Modules/Staff/Application/Permission/PermissionRequestService.php');
$formSafety = (string) file_get_contents($root . '/assets/js/form-safety.js');

$checks = [
    'portal_has_explicit_csrf_field' => str_contains($html, 'name="csrf_token" value="csrf-portal-test-token"'),
    'portal_uses_session_scoped_draft_key' => str_contains($html, 'data-draft-scope="staff:42"')
        && str_contains($formSafety, 'form.dataset.draftScope ||'),
    'portal_does_not_render_mutable_staff_identifier' => !str_contains($html, 'name="staff_user_id"')
        && !str_contains($html, 'name="user_id"'),
    'portal_uses_service_intents_and_versions' => str_contains($html, 'name="permission_request_intent" value="submit"')
        && str_contains($html, 'name="submission_idempotency_key" value="new-submit-portal-test-key"')
        && str_contains($html, 'name="expected_lock_version" value="3"')
        && str_contains($html, 'name="submission_idempotency_key" value="submit-portal-test-key"'),
    'portal_shows_cancellation_reason_in_bootstrap_modal' => str_contains($html, 'staffPermissionCancelModal-72')
        && str_contains($html, 'name="reason"')
        && str_contains($html, 'class="btn btn-warning"'),
    'portal_escapes_untrusted_labels' => !str_contains($html, '<script>alert(1)</script>')
        && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt; إذن آخر'),
    'portal_hides_technical_error_details' => !str_contains($html, 'SQLSTATE')
        && !str_contains($html, 'Duplicate entry')
        && str_contains($html, 'تعذر إتمام طلب الإذن الآن'),
    'portal_maps_field_error_to_arabic' => str_contains($html, 'اكتب سبب الإذن قبل الإرسال.'),
    'service_rechecks_self_scope_for_idor_attempts' => substr_count($service, 'assertSelfActor(') >= 4
        && str_contains($service, "'PERMISSION_REQUEST_OWNER_ONLY'"),
    'legacy_modal_preserves_public_field_names_and_ids' => str_contains($legacyHtml, 'id="permissionForm"')
        && str_contains($legacyHtml, 'name="permission_form_mode"')
        && str_contains($legacyHtml, 'name="user_id" id="permission_user_id"')
        && str_contains($legacyHtml, 'name="delete_permission"'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
