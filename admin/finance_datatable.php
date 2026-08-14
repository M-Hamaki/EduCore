<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';

use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditService;

Utilities::validateSession('admin');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$providedToken = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
if ($sessionToken === '' || $providedToken === '' || !hash_equals($sessionToken, $providedToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'انتهت صلاحية رمز الأمان. أعد تحميل الصفحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$columnsByView = [
    'receipts' => ['receipt_number', 'student_id', 'cashbox_code', 'gross_amount', 'payment_method', 'posted_at', 'status', 'reversal_of'],
    'student_ledger' => ['transaction_id', 'source_type', 'bucket_code', 'amount_delta', 'description', 'posted_at', 'status', 'reversal_of'],
    'staff_ledger' => ['staff_id', 'transaction_id', 'source_type', 'bucket_code', 'amount_delta', 'description', 'posted_at', 'reversal_of'],
    'payroll_runs' => ['id', 'payroll_period_id', 'start_date', 'end_date', 'version_number', 'is_settlement', 'status', 'reversal_of'],
    'payroll_items' => ['id', 'payroll_run_id', 'staff_id', 'gross', 'total_deductions', 'net', 'status', 'payslip_ref_number', 'payment_status'],
    'journal' => ['entry_number', 'entry_date', 'source_type', 'total_debit', 'total_credit', 'status', 'subledger_transaction_id', 'reversal_of'],
    'audit_log' => ['created_at', 'user_name', 'action', 'target_type', 'target_id', 'target_name', 'result'],
    'vouchers' => ['voucher_number', 'voucher_type', 'cashbox_code', 'amount', 'entry_date', 'status'],
];

$view = (string) ($_POST['view'] ?? '');
if (!isset($columnsByView[$view])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'عرض مالي غير مدعوم.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$requestedOrderIndex = max(0, (int) ($_POST['order'][0]['column'] ?? 0));
$orderBy = $columnsByView[$view][$requestedOrderIndex] ?? $columnsByView[$view][0];
$filters = [
    'student_id' => max(0, (int) ($_POST['student_id'] ?? 0)),
    'staff_id' => max(0, (int) ($_POST['staff_id'] ?? 0)),
    'academic_year_id' => max(0, (int) ($_POST['academic_year_id'] ?? 0)),
];

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Finance database connection is unavailable.');
    }
    $factory = new FinanceServiceFactory($db, new AuditService($db));
    $page = $factory->adminReadService()->page(
        $view,
        $filters,
        (string) ($_POST['search']['value'] ?? ''),
        $orderBy,
        (string) ($_POST['order'][0]['dir'] ?? 'desc'),
        max(0, (int) ($_POST['start'] ?? 0)),
        (int) ($_POST['length'] ?? 50)
    );
    foreach ($page['rows'] as &$row) {
        $row['__actions'] = financeDataTableActions($view, $row);
    }
    unset($row);

    echo json_encode([
        'draw' => max(0, (int) ($_POST['draw'] ?? 0)),
        'recordsTotal' => $page['total'],
        'recordsFiltered' => $page['filtered'],
        'data' => $page['rows'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Finance DataTable failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'draw' => max(0, (int) ($_POST['draw'] ?? 0)),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'تعذر تحميل البيانات المالية.',
    ], JSON_UNESCAPED_UNICODE);
}

/** @param array<string,mixed> $row */
function financeDataTableActions(string $view, array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    if ($view === 'receipts' && ($row['status'] ?? '') === 'posted' && empty($row['reversal_of'])) {
        return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#reverseReceiptModal" data-receipt-id="' . $id . '" data-student-id="' . (int) ($row['student_id'] ?? 0) . '" title="عكس الإيصال"><i class="fas fa-undo"></i></button>';
    }
    if ($view === 'payroll_runs') {
        if (($row['status'] ?? '') === 'posted' && empty($row['reversal_of'])) {
            return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#payrollReverseModal" data-run-id="' . $id . '" title="عكس الدورة"><i class="fas fa-undo"></i></button>';
        }
        $next = match ((string) ($row['status'] ?? '')) {
            'draft' => ['calculate_run', 'احتساب'],
            'calculated' => ['review_run', 'مراجعة'],
            'reviewed' => ['approve_run', 'اعتماد'],
            'approved' => ['finalize_run', 'ترحيل'],
            default => null,
        };
        return $next === null ? '' : '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#payrollTransitionModal" data-run-id="' . $id . '" data-action="' . $next[0] . '" data-label="' . $next[1] . '" title="' . $next[1] . '"><i class="fas fa-forward"></i></button>';
    }
    if ($view === 'payroll_items') {
        $buttons = '<a class="btn btn-action-pills btn-edit me-1" href="finance_payslip.php?item_id=' . $id . '" title="عرض وطباعة القسيمة"><i class="fas fa-print"></i></a>';
        if (empty($row['reversal_of']) && ($row['payment_status'] ?? '') !== 'paid') {
            $buttons .= '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#payrollPaymentModal" data-item-id="' . $id . '" data-staff-id="' . (int) ($row['staff_id'] ?? 0) . '" data-net="' . htmlspecialchars((string) ($row['net'] ?? '0.00'), ENT_QUOTES, 'UTF-8') . '" title="صرف الراتب"><i class="fas fa-money-bill-wave"></i></button>';
        }
        return $buttons;
    }
    if ($view === 'journal' && ($row['source_type'] ?? '') === 'manual' && ($row['status'] ?? '') === 'posted' && empty($row['reversal_of'])) {
        return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#manualReversalModal" data-idempotency-key="' . htmlspecialchars((string) ($row['source_idempotency_key'] ?? ''), ENT_QUOTES, 'UTF-8') . '" title="عكس القيد"><i class="fas fa-undo"></i></button>';
    }
    if ($view === 'vouchers' && ($row['status'] ?? '') === 'posted' && empty($row['reversal_of'])) {
        return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#reverseVoucherModal" data-voucher-id="' . $id . '" title="عكس القسيمة"><i class="fas fa-undo"></i></button>';
    }
    return '';
}
