<?php
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditService;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
$page_title = 'لوحة المالية العامة'; $custom_page_title = true;
$database = new Database(); $db = $database->getConnection();
try { $summary = (new FinanceServiceFactory($db, new AuditService($db)))->dashboardService()->summary(); }
catch (Throwable $exception) { error_log('Finance dashboard failed: ' . $exception->getMessage()); $summary = ['academic_year_id'=>0,'total_charges'=>'0.00','total_collected'=>'0.00','total_outstanding'=>'0.00','receipt_count'=>0]; }
$currentYearId = (int) $summary['academic_year_id'];
$totalCharges = (string) $summary['total_charges']; $totalCollected = (string) $summary['total_collected'];
$totalOutstanding = (string) $summary['total_outstanding']; $receiptCount = (int) $summary['receipt_count'];
require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading mb-4">
    <div class="admin-page-title">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-coins me-2 text-primary"></i>لوحة المالية العامة
        </h1>
    </div>
    <div class="admin-top-actions">
        <a href="finance_receipts.php" class="btn btn-header-premium btn-success shadow-sm">
            <i class="fas fa-cash-register me-2"></i>التحصيل
        </a>
        <a href="finance_reports.php" class="btn btn-outline-primary shadow-sm">
            <i class="fas fa-chart-pie me-2"></i>التقارير
        </a>
    </div>
</div>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int) round((float) $totalCharges); ?>">0</div>
                <div class="stat-card-label">إجمالي المستحقات</div>
                <div class="stat-card-sub"><i class="fas fa-money-bill"></i> ج.م</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int) round((float) $totalCollected); ?>">0</div>
                <div class="stat-card-label">إجمالي المحصّل</div>
                <div class="stat-card-sub"><i class="fas fa-check-circle"></i> ج.م</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-balance-scale"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int) round((float) $totalOutstanding); ?>">0</div>
                <div class="stat-card-label">المتبقي</div>
                <div class="stat-card-sub"><i class="fas fa-clock"></i> ج.م</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-receipt"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $receiptCount; ?>">0</div>
                <div class="stat-card-label">عدد الإيصالات</div>
                <div class="stat-card-sub"><i class="fas fa-file-alt"></i> إيصال</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow admin-card-surface">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-link me-2"></i>روابط سريعة</h5>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-6"><a href="finance_fee_plans.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-list me-2"></i>خطط الرسوم</a></div>
                    <div class="col-md-6"><a href="finance_student_accounts.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-user-graduate me-2"></i>حسابات الطلاب</a></div>
                    <div class="col-md-6"><a href="finance_student_ledger.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-book-open me-2"></i>سجل الطالب المالي</a></div>
                    <div class="col-md-6"><a href="finance_receipts.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-cash-register me-2"></i>التحصيل والإيصالات</a></div>
                    <div class="col-md-6"><a href="finance_debts.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-exclamation-triangle me-2"></i>المديونيات</a></div>
                    <div class="col-md-6"><a href="finance_discounts.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-percentage me-2"></i>الخصومات</a></div>
                    <div class="col-md-6"><a href="finance_discount_awards.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-user-tag me-2"></i>طلبات الخصومات</a></div>
                    <div class="col-md-6"><a href="finance_payroll_runs.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-users me-2"></i>دورات الرواتب</a></div>
                    <div class="col-md-6"><a href="finance_payroll_items.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-file-invoice-dollar me-2"></i>قسائم الرواتب</a></div>
                    <div class="col-md-6"><a href="finance_staff_contracts.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-file-signature me-2"></i>عقود العاملين</a></div>
                    <div class="col-md-6"><a href="finance_staff_advances.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-hand-holding-usd me-2"></i>سلف العاملين</a></div>
                    <div class="col-md-6"><a href="finance_staff_ledger.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-address-book me-2"></i>سجل العامل المالي</a></div>
                    <div class="col-md-6"><a href="finance_cashboxes.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-cash-register me-2"></i>الصناديق</a></div>
                    <div class="col-md-6"><a href="finance_vouchers.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-file-invoice me-2"></i>القسائم</a></div>
                    <div class="col-md-6"><a href="finance_journal.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-book me-2"></i>القيود اليومية</a></div>
                    <div class="col-md-6"><a href="finance_budgets.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-chart-line me-2"></i>الميزانية</a></div>
                    <div class="col-md-6"><a href="finance_reports.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-chart-pie me-2"></i>التقارير</a></div>
                    <div class="col-md-6"><a href="finance_buses.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-bus me-2"></i>مالية الحافلات</a></div>
                    <div class="col-md-6"><a href="finance_import_export.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-file-import me-2"></i>الاستيراد والتصدير</a></div>
                    <div class="col-md-6"><a href="finance_archive.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-archive me-2"></i>الأرشيف المالي</a></div>
                    <div class="col-md-6"><a href="finance_audit_log.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-history me-2"></i>سجل العمليات</a></div>
                    <div class="col-md-6"><a href="finance_approvals.php" class="btn btn-outline-primary w-100 text-start"><i class="fas fa-user-check me-2"></i>طلبات الاعتماد</a></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow admin-card-surface">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>معلومات النظام المالي</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">النظام المالي يعتمد على <strong>دفتر أستاذ موحد</strong> للطلاب والعاملين مع محاسبة مزدوجة كاملة.</p>
                <ul class="list-unstyled">
                    <li class="mb-2"><i class="fas fa-check text-success me-2"></i>جميع الأرصدة مشتقة من حركات الدفتر</li>
                    <li class="mb-2"><i class="fas fa-check text-success me-2"></i>المدفوعات والرواتب reversal-only</li>
                    <li class="mb-2"><i class="fas fa-check text-success me-2"></i>كل عملية تنشئ قيد GL متوازن</li>
                    <li class="mb-2"><i class="fas fa-check text-success me-2"></i>maker-checker إلزامي للعمليات الحساسة</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
