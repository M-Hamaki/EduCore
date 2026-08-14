<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();

use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditService;

$page_title = 'القسائم المالية';
$custom_page_title = true;

$database = new Database();
$db = $database->getConnection();
$financeFactory = new FinanceServiceFactory($db, new AuditService($db));

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverse_voucher') {
    try {
        $financeFactory->approvalWorkflowService()->request('voucher_reverse', ['voucher_id' => (int) ($_POST['voucher_id'] ?? 0), 'entry_date' => (string) ($_POST['entry_date'] ?? date('Y-m-d')), 'reason' => (string) ($_POST['reason'] ?? '')], (int) $_SESSION['user_id']);
        $_SESSION['success_message'] = 'تم إرسال طلب عكس القسيمة للاعتماد.';
    } catch (Throwable $exception) {
        error_log('Finance voucher reversal request failed: ' . $exception->getMessage());
        $_SESSION['error_message'] = 'تعذر إرسال طلب عكس القسيمة.';
    }
    header('Location: finance_vouchers.php');
    exit();
}

// Sensitive voucher writes are requested by the current actor and executed only by another authenticated checker.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_voucher') {
    try {
        $voucherType = (string) ($_POST['voucher_type'] ?? '');
        $amount = (string) ($_POST['amount'] ?? '');
        $entryDate = (string) ($_POST['entry_date'] ?? date('Y-m-d'));
        if (!in_array($voucherType, ['expense', 'other_income', 'cash_transfer'], true) || preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) !== 1) { throw new RuntimeException('بيانات القسيمة غير صحيحة.'); }
        $financeFactory->approvalWorkflowService()->request('voucher_post', [
            'voucher_type' => $voucherType,
            'cashbox_id' => (int) ($_POST['cashbox_id'] ?? 0),
            'source_cashbox_id' => (int) ($_POST['source_cashbox_id'] ?? 0),
            'destination_cashbox_id' => (int) ($_POST['destination_cashbox_id'] ?? 0),
            'bank_account_id' => (int) ($_POST['bank_account_id'] ?? 0),
            'amount' => Money::fromDecimalString($amount)->toDatabaseString(),
            'finance_period_id' => (int) ($_POST['finance_period_id'] ?? 0),
            'entry_date' => $entryDate,
            'cost_center_id' => (int) ($_POST['cost_center_id'] ?? 0),
            'description' => (string) ($_POST['notes'] ?? ''),
        ], (int) $_SESSION['user_id']);
        $_SESSION['success_message'] = 'تم إرسال القسيمة للاعتماد من مستخدم آخر.';
    } catch (Throwable $e) {
        error_log('Finance voucher request failed: ' . $e->getMessage());
        $_SESSION['error_message'] = 'تعذر إرسال القسيمة للاعتماد.';
    }
    header('Location: finance_vouchers.php');
    exit();
}

// Read models stay behind the Finance application query boundary.
try {
    $cashboxes = array_values(array_filter($financeFactory->adminReadService()->rows('cashboxes', [], 500), static fn (array $row): bool => (bool) $row['is_active']));
    $accounts = array_values(array_filter($financeFactory->adminReadService()->rows('accounts', [], 500), static fn (array $row): bool => (bool) $row['is_active']));
    $vouchers = [];
} catch (Throwable $e) {
    error_log('Finance voucher read failed: ' . $e->getMessage());
    $cashboxes = $accounts = $vouchers = [];
}

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-file-invoice me-2 text-primary"></i>القسائم المالية</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="finance_dashboard.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-arrow-right me-2"></i>العودة للوحة
        </a>
        <button class="btn btn-success shadow px-4 py-2" data-bs-toggle="modal" data-bs-target="#voucherModal">
            <i class="fas fa-plus-circle me-2"></i>قسيمة جديدة
        </button>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="admin-list-surface">
    <div class="admin-table-wrap">
        <table id="financeVoucherTable" class="table table-hover table-striped admin-data-table">
            <thead>
                <tr>
                    <th>رقم القسيمة</th>
                    <th>النوع</th>
                    <th>الصندوق</th>
                    <th>المبلغ</th>
                    <th>التاريخ</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="reverseVoucherModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="finance_vouchers.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="reverse_voucher"><input type="hidden" name="voucher_id" id="reverseVoucherId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-undo me-2"></i>طلب عكس القسيمة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-undo text-danger" style="font-size:3rem"></i></div><div class="mb-3"><label class="form-label">تاريخ العكس</label><input type="date" name="entry_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required></div><div class="mb-3"><label class="form-label">السبب</label><textarea name="reason" class="form-control" required></textarea></div><div class="alert alert-warning"><i class="fas fa-user-check me-2"></i>يتطلب الطلب اعتماد مستخدم آخر.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-paper-plane me-1"></i>إرسال الطلب</button></div></form></div></div></div>
<script>document.getElementById('reverseVoucherModal').addEventListener('show.bs.modal',function(e){document.getElementById('reverseVoucherId').value=e.relatedTarget.dataset.voucherId;});</script>

<!-- Voucher Modal -->
<div class="modal fade" id="voucherModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="post" action="finance_vouchers.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create_voucher">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>قسيمة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">نوع القسيمة</label>
                        <select name="voucher_type" class="form-select" required>
                            <option value="expense">مصروف</option>
                            <option value="other_income">إيراد آخر</option>
                            <option value="cash_transfer">تحويل نقدي</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الصندوق (للمصروف أو الإيراد)</label>
                        <select name="cashbox_id" class="form-select">
                            <option value="">اختر الصندوق</option>
                            <?php foreach ($cashboxes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['code'] . ' - ' . $c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صندوق التحويل المصدر</label>
                        <select name="source_cashbox_id" class="form-select"><option value="">غير مستخدم</option><?php foreach ($cashboxes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['code'] . ' - ' . $c['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صندوق التحويل الوجهة</label>
                        <select name="destination_cashbox_id" class="form-select"><option value="">غير مستخدم</option><?php foreach ($cashboxes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['code'] . ' - ' . $c['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المبلغ (ج.م)</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">التاريخ</label>
                        <input type="date" name="entry_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3"><label class="form-label">الفترة المالية (اختياري)</label><input type="number" min="1" name="finance_period_id" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">مركز التكلفة (اختياري)</label><input type="number" min="1" name="cost_center_id" class="form-control"></div>
                    <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>تُحل حسابات المدين والدائن تلقائيًا من سياسات الربط المحاسبي المعتمدة.</div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/js/admin-server-side-table.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var escapeHtml = function (value) { return $('<div>').text(value == null ? '' : String(value)).html(); };
    AdminServerSideTable.init({
        selector: '#financeVoucherTable',
        url: 'finance_datatable.php',
        order: [[4, 'desc']],
        requestData: function () { return {view: 'vouchers'}; },
        dtOptions: {
            columns: [
                {data: 'voucher_number', render: function (v) { return escapeHtml(v); }},
                {data: 'voucher_type', render: function (v) { return escapeHtml(v); }},
                {data: 'cashbox_code', defaultContent: '-', render: function (v) { return escapeHtml(v || '-'); }},
                {data: 'amount', render: function (v) { return escapeHtml(v || '0.00') + ' ج.م'; }},
                {data: 'entry_date', render: function (v) { return escapeHtml(v || '-'); }},
                {data: 'status', render: function (v) { return '<span class="badge bg-' + (v === 'posted' ? 'success' : 'secondary') + '">' + escapeHtml(v || '-') + '</span>'; }},
                {data: '__actions', orderable: false, searchable: false, defaultContent: ''}
            ]
        }
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
