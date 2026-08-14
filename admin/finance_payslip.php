<?php

declare(strict_types=1);

use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditService;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');

$page_title = 'قسيمة راتب';
$custom_page_title = true;
$itemId = max(0, (int) ($_GET['item_id'] ?? 0));
if ($itemId <= 0) { http_response_code(400); exit('معرف قسيمة الراتب مطلوب.'); }
$database = new Database();
$db = $database->getConnection();
$factory = new FinanceServiceFactory($db, new AuditService($db));
try { $payslip = $factory->payrollRunService()->payslip($itemId); }
catch (Throwable $exception) { error_log('Payslip read failed: ' . $exception->getMessage()); http_response_code(404); exit('قسيمة الراتب غير موجودة.'); }
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$paid = array_sum(array_map(static fn (array $payment): float => ($payment['status'] ?? '') === 'posted' ? (float) $payment['amount'] : 0.0, $payslip['payments'] ?? []));
require_once '../includes/admin_header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>قسيمة راتب</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2"><a href="finance_payroll_runs.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-right me-2"></i>العودة</a><button type="button" class="btn btn-outline-primary" onclick="window.print()"><i class="fas fa-print me-2"></i>طباعة</button></div>
</div>
<div class="card shadow admin-card-surface">
    <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-receipt me-2"></i>المرجع: <?php echo $escape($payslip['payslip_ref_number'] ?? ('PAY-' . $itemId)); ?></h5></div>
    <div class="card-body">
        <div class="row g-3 mb-4"><div class="col-md-3"><strong>العامل:</strong> <?php echo (int) $payslip['staff_id']; ?></div><div class="col-md-3"><strong>دورة الرواتب:</strong> <?php echo (int) $payslip['payroll_run_id']; ?></div><div class="col-md-3"><strong>حالة القسيمة:</strong> <span class="badge bg-info text-dark"><?php echo $escape($payslip['status']); ?></span></div><div class="col-md-3"><strong>حالة السداد:</strong> <span class="badge bg-<?php echo ($payslip['payment_status'] ?? '') === 'paid' ? 'success' : 'warning text-dark'; ?>"><?php echo $escape($payslip['payment_status'] ?? 'unpaid'); ?></span></div></div>
        <div class="admin-list-surface"><div class="admin-table-wrap"><table class="table table-hover table-striped admin-data-table"><thead><tr><th>المكون</th><th>النوع</th><th>القيمة</th></tr></thead><tbody><?php foreach (($payslip['components'] ?? []) as $component): ?><tr><td><?php echo (int) $component['payroll_component_id']; ?></td><td><?php echo $escape($component['direction']); ?></td><td><?php echo number_format((float) $component['amount'], 2); ?> ج.م</td></tr><?php endforeach; ?></tbody><tfoot><tr><th>الإجمالي</th><th>الإجمالي المستحق: <?php echo number_format((float) $payslip['gross'], 2); ?> ج.م<br>الاستقطاعات: <?php echo number_format((float) $payslip['total_deductions'], 2); ?> ج.م</th><th>الصافي: <?php echo number_format((float) $payslip['net'], 2); ?> ج.م</th></tr></tfoot></table></div></div>
        <div class="alert alert-info mt-3"><i class="fas fa-money-check-alt me-2"></i>المدفوع: <strong><?php echo number_format($paid, 2); ?> ج.م</strong> — المتبقي: <strong><?php echo number_format(max(0, (float) $payslip['net'] - $paid), 2); ?> ج.م</strong></div>
    </div>
</div>
<?php require_once '../includes/admin_footer.php'; ?>
