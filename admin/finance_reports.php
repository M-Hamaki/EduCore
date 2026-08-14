<?php
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditService;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
$page_title = 'التقارير المالية'; $custom_page_title = true;
$database = new Database(); $db = $database->getConnection();
$reports = (new FinanceServiceFactory($db, new AuditService($db)))->reportService();
$type = (string) ($_GET['report'] ?? 'trial_balance');
$periodId = (int) ($_GET['finance_period_id'] ?? 0) ?: null;
$yearId = (int) ($_GET['academic_year_id'] ?? 0);
$runId = (int) ($_GET['payroll_run_id'] ?? 0);
$budgetVersionId = (int) ($_GET['budget_version_id'] ?? 0);
$rows = []; $error_message = $_SESSION['error_message'] ?? null; unset($_SESSION['error_message']);
try {
    $rows = match ($type) {
        'trial_balance' => $reports->trialBalance($periodId), 'profit_loss' => $reports->profitAndLoss($periodId),
        'cash_flow' => $reports->cashFlow($periodId), 'collection' => $yearId > 0 ? $reports->collectionSummary($yearId) : [],
        'debt_aging' => $yearId > 0 ? $reports->debtAging($yearId) : [], 'payroll' => $runId > 0 ? $reports->payrollSummary($runId) : [],
        'budget_actual' => $budgetVersionId > 0 ? $reports->budgetVsActual($budgetVersionId) : [],
        default => throw new InvalidArgumentException('نوع التقرير غير مدعوم.'),
    };
} catch (Throwable $exception) { error_log('Finance report failed: ' . $exception->getMessage()); $error_message = 'تعذر إنشاء التقرير المالي.'; }
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require_once '../includes/admin_header.php';
?>
<div class="admin-page-heading mb-4">
    <div class="admin-page-title">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-chart-pie me-2 text-primary"></i>التقارير المالية
        </h1>
    </div>
    <div class="admin-top-actions">
        <a href="finance_dashboard.php" class="btn btn-outline-secondary shadow-sm">
            <i class="fas fa-arrow-right me-2"></i>العودة
        </a>
        <button type="button" class="btn btn-outline-primary shadow-sm" onclick="window.print()">
            <i class="fas fa-print me-2"></i>طباعة
        </button>
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#exportReportModal" <?php echo $rows === [] ? 'disabled' : ''; ?>>
            <i class="fas fa-file-export me-2"></i>تصدير
        </button>
    </div>
</div>
<?php if ($error_message): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo $escape($error_message); ?></div><?php endif; ?>
<form method="get" class="admin-filter-bar"><div class="admin-filter-controls"><select name="report" class="form-select form-select-sm"><option value="trial_balance" <?php echo $type==='trial_balance'?'selected':''; ?>>ميزان المراجعة</option><option value="profit_loss" <?php echo $type==='profit_loss'?'selected':''; ?>>الأرباح والخسائر</option><option value="cash_flow" <?php echo $type==='cash_flow'?'selected':''; ?>>التدفق النقدي</option><option value="collection" <?php echo $type==='collection'?'selected':''; ?>>ملخص التحصيل</option><option value="debt_aging" <?php echo $type==='debt_aging'?'selected':''; ?>>أعمار الديون</option><option value="payroll" <?php echo $type==='payroll'?'selected':''; ?>>ملخص الرواتب</option><option value="budget_actual" <?php echo $type==='budget_actual'?'selected':''; ?>>الموازنة مقابل الفعلي</option></select><input type="number" min="1" name="finance_period_id" class="form-control form-control-sm" placeholder="الفترة المالية" value="<?php echo $periodId ?: ''; ?>"><input type="number" min="1" name="academic_year_id" class="form-control form-control-sm" placeholder="العام الدراسي" value="<?php echo $yearId ?: ''; ?>"><input type="number" min="1" name="payroll_run_id" class="form-control form-control-sm" placeholder="دورة الرواتب" value="<?php echo $runId ?: ''; ?>"><input type="number" min="1" name="budget_version_id" class="form-control form-control-sm" placeholder="إصدار الموازنة" value="<?php echo $budgetVersionId ?: ''; ?>"></div><div class="admin-filter-actions"><button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>إنشاء التقرير</button></div></form>
<div class="row row-cols-2 row-cols-md-3 g-3 mb-4"><div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#3b82f6,#2563eb);"><div class="stat-card-icon"><i class="fas fa-list"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo count($rows); ?>">0</div><div class="stat-card-label">صفوف التقرير</div><div class="stat-card-sub"><i class="fas fa-filter"></i> حسب المحددات</div></div></div></div></div>
<div class="admin-list-surface"><div class="admin-table-wrap"><table class="table table-hover table-striped admin-data-table"><thead><tr><?php foreach (($rows[0] ?? []) as $key => $_): ?><th><?php echo $escape($key); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($row as $value): ?><td><?php echo $escape($value); ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if ($rows===[]): ?><tr><td class="text-center text-muted py-4">لا توجد بيانات للتقرير أو يلزم إدخال المعرّف المطلوب.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="modal fade" id="exportReportModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium"><form method="post" action="finance_report_export.php"><input type="hidden" name="csrf_token" value="<?php echo $escape($_SESSION['csrf_token'] ?? ''); ?>"><input type="hidden" name="report" value="<?php echo $escape($type); ?>"><input type="hidden" name="finance_period_id" value="<?php echo $periodId ?: ''; ?>"><input type="hidden" name="academic_year_id" value="<?php echo $yearId ?: ''; ?>"><input type="hidden" name="payroll_run_id" value="<?php echo $runId ?: ''; ?>"><input type="hidden" name="budget_version_id" value="<?php echo $budgetVersionId ?: ''; ?>"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-export me-2"></i>تصدير التقرير المالي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الصيغة</label><select name="format" class="form-select"><option value="csv">CSV</option><option value="xlsx">Excel XLSX</option><option value="pdf">PDF</option></select><div class="alert alert-info mt-3"><i class="fas fa-clock me-2"></i>ملف التصدير خاص وينتهي تلقائيًا بعد 24 ساعة.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-download me-1"></i>إنشاء وتنزيل</button></div></form></div></div></div>
<?php require_once '../includes/admin_footer.php'; ?>
