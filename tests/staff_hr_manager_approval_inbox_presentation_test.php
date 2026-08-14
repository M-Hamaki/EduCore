<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Staff\Presentation\ManagerApprovalInbox;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertError = static function (callable $callback, string $expectedCode, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message . ' (no error)');
    } catch (Throwable $exception) {
        $assert($exception->getMessage() === $expectedCode, $message . ' (' . $exception->getMessage() . ')');
    }
};

try {
    $html = ManagerApprovalInbox::renderInbox([
        'csrf_token' => 'csrf-test-token',
        'action_url' => 'hr_center.php?tab=approvals',
        'total' => 1,
        'feedback' => ['kind' => 'danger', 'code' => 'STALE_APPROVAL_STEP'],
        'items' => [[
            'instance_id' => 101,
            'step_id' => 202,
            'step_lock_version' => 4,
            'resource_type' => 'permission_request',
            'resource_id' => 303,
            'sequence_no' => 2,
            'stage_name' => 'مراجعة المدير الإداري',
            'decision_mode' => 'sequential',
            'due_state' => 'overdue',
            'due_at' => '2026-10-01 08:30:00.000000',
            'staff_user_id' => 404,
            'staff_display_name' => 'أحمد علي',
            'request_id' => 505,
            'acting_for_user_id' => 606,
            'actions' => [
                'approve' => ['idempotency_key' => 'approve-inbox-101'],
                'reject' => ['idempotency_key' => 'reject-inbox-101'],
            ],
        ]],
    ]);
    $assert(str_contains($html, 'اعتماداتي المعيّنة') && str_contains($html, 'أحمد علي'), 'manager inbox renders assigned work with a clear title');
    $assert(str_contains($html, 'name="csrf_token" value="csrf-test-token"') && str_contains($html, 'name="step_id" value="202"'), 'decision forms carry CSRF and the frozen step identifier');
    $assert(str_contains($html, 'name="expected_lock_version" value="4"') && str_contains($html, 'approve-inbox-101'), 'decision forms carry optimistic lock and idempotency evidence');
    $assert(str_contains($html, 'managerApprovalRejectModal-101-202') && str_contains($html, 'name="comment"') && str_contains($html, 'required'), 'rejection uses a Bootstrap modal with a required reason');
    $assert(str_contains($html, 'بالإنابة') && str_contains($html, 'متأخر'), 'delegated and overdue decisions are visibly distinguished');
    $assert(!str_contains($html, 'name="staff_user_id"') && !str_contains($html, 'snapshot_json'), 'presentation never accepts a mutable staff identity or leaks raw snapshots');
    $assert(!str_contains($html, 'STALE_APPROVAL_STEP') && str_contains($html, 'تم تحديث المرحلة من جلسة أخرى'), 'domain errors are rendered as understandable Arabic guidance');

    $empty = ManagerApprovalInbox::renderInbox([
        'csrf_token' => 'csrf-test-token',
        'action_url' => 'hr_center.php',
        'items' => [],
        'total' => 0,
    ]);
    $assert(str_contains($empty, 'لا توجد اعتمادات نشطة مسندة إليك حاليًا'), 'empty inbox explains the next state clearly');

    $counter = ManagerApprovalInbox::renderDashboardCounter(3, 'hr_center.php?tab=approvals');
    $assert(str_contains($counter, 'اعتماداتي المعيّنة') && str_contains($counter, '>3<'), 'dashboard counter links to the scoped inbox and displays its count');
    $assertError(
        static fn (): string => ManagerApprovalInbox::renderDashboardCounter(1, 'https://outside.example/approvals'),
        'APPROVAL_INBOX_ACTION_URL_INVALID',
        'dashboard counter rejects an external action URL'
    );
    $assertError(
        static fn (): string => ManagerApprovalInbox::renderInbox([
            'csrf_token' => 'token',
            'action_url' => 'hr_center.php',
            'items' => [[
                'instance_id' => 1,
                'step_id' => 2,
                'step_lock_version' => 1,
                'resource_type' => 'permission_request',
                'resource_id' => 3,
                'sequence_no' => 1,
                'stage_name' => 'مرحلة',
                'decision_mode' => 'sequential',
                'due_state' => 'open',
                'due_at' => null,
                'actions' => ['approve' => ['idempotency_key' => '']],
            ]],
        ]),
        'APPROVAL_INBOX_ACTIONS_INVALID',
        'empty decision idempotency evidence fails before rendering a write form'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: manager inbox presentation exercise failed: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} manager inbox presentation failure(s).\n");
    exit(1);
}

echo "Staff-HR manager approval inbox presentation test passed.\n";
