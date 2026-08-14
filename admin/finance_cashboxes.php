<?php
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();
$financeActionHandler = static function (FinanceServiceFactory $factory, array $post, int $actorId): void {
    $action = (string) ($post['action'] ?? ''); $service = $factory->dailySettlementService();
    if ($action === 'open_settlement') {
        $opening = (string) ($post['opening_float'] ?? '0');
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $opening) !== 1) { throw new InvalidArgumentException('عهدة البداية غير صحيحة.'); }
        $service->openSettlement((int) ($post['cashbox_id'] ?? 0), (int) ($post['finance_period_id'] ?? 0) ?: null, (string) ($post['settlement_date'] ?? ''), $opening, $actorId); return;
    }
    if ($action === 'settle') {
        $counted = (string) ($post['counted_total'] ?? '');
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $counted) !== 1) { throw new InvalidArgumentException('القيمة الفعلية غير صحيحة.'); }
        $service->settleSettlement((int) ($post['settlement_id'] ?? 0), $counted, $actorId); return;
    }
    throw new InvalidArgumentException('عملية الصندوق غير مدعومة.');
};
$financePage = [
    'title' => 'الصناديق والبنوك', 'icon' => 'fa-cash-register', 'view' => 'cashboxes',
    'columns' => [
        ['key' => 'code', 'label' => 'الكود'], ['key' => 'name', 'label' => 'الاسم'],
        ['key' => 'type', 'label' => 'النوع'], ['key' => 'is_active', 'label' => 'نشط', 'type' => 'bool'],
        ['key' => 'accountability_role', 'label' => 'مسؤول العهدة'], ['key' => 'receipt_prefix', 'label' => 'بادئة الإيصال'],
        ['key' => 'settlement_date', 'label' => 'آخر تسوية', 'type' => 'date'], ['key' => 'expected_total', 'label' => 'المتوقع', 'type' => 'money'],
        ['key' => 'counted_total', 'label' => 'الفعلي', 'type' => 'money'], ['key' => 'difference', 'label' => 'الفرق', 'type' => 'money'],
        ['key' => 'settlement_status', 'label' => 'حالة التسوية', 'type' => 'status'],
    ],
    'money_total_field' => 'expected_total',
];
$financeRowActions = static function (array $row): string {
    if (($row['settlement_status'] ?? '') === 'open') { return '<button type="button" class="btn btn-action-pills btn-edit" data-bs-toggle="modal" data-bs-target="#settleModal" data-settlement-id="' . (int) $row['settlement_id'] . '" title="إقفال اليومية"><i class="fas fa-check-double"></i></button>'; }
    return '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#openSettlementModal" data-cashbox-id="' . (int) $row['id'] . '" title="فتح يومية"><i class="fas fa-door-open"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="openSettlementModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_cashboxes.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="open_settlement"><input type="hidden" name="cashbox_id" id="openCashboxId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-door-open me-2"></i>فتح يومية صندوق</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">التاريخ</label><input type="date" name="settlement_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required></div><div class="mb-3"><label class="form-label">عهدة البداية</label><input type="number" min="0" step="0.01" name="opening_float" value="0.00" class="form-control" required></div><div class="mb-3"><label class="form-label">الفترة المالية</label><input type="number" min="1" name="finance_period_id" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-door-open me-1"></i>فتح اليومية</button></div></form></div></div></div>
<div class="modal fade" id="settleModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_cashboxes.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="settle"><input type="hidden" name="settlement_id" id="settlementId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-check-double me-2"></i>إقفال اليومية</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-cash-register text-primary" style="font-size:3rem"></i></div><div class="mb-3"><label class="form-label">المبلغ الفعلي المعدود</label><input type="number" min="0" step="0.01" name="counted_total" class="form-control" required></div><div class="alert alert-info"><i class="fas fa-balance-scale me-2"></i>سيحسب النظام الفرق عن الإيصالات المرحلة ويسجله في اليومية.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>إقفال</button></div></form></div></div></div>
<script>document.getElementById('openSettlementModal').addEventListener('show.bs.modal',function(e){document.getElementById('openCashboxId').value=e.relatedTarget.dataset.cashboxId;});document.getElementById('settleModal').addEventListener('show.bs.modal',function(e){document.getElementById('settlementId').value=e.relatedTarget.dataset.settlementId;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
