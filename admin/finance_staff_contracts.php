<?php
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();
$financeActionHandler = static function (FinanceServiceFactory $factory, array $post, int $actorId): void {
    $action = (string) ($post['action'] ?? '');
    if ($action === 'create_contract') {
        $components = [];
        foreach ((array) ($post['component_id'] ?? []) as $index => $componentId) {
            if ((int) $componentId <= 0) { continue; }
            $amountText = (string) ($post['component_amount'][$index] ?? '');
            if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amountText) !== 1) { throw new InvalidArgumentException('قيمة مكون الراتب غير صحيحة.'); }
            $components[] = ['component_id' => (int) $componentId, 'amount' => Money::fromDecimalString($amountText), 'direction' => (string) ($post['component_direction'][$index] ?? 'earning')];
        }
        $factory->staffCompensationService()->createDraft(
            (int) ($post['staff_id'] ?? 0), (string) ($post['effective_from'] ?? ''),
            (string) ($post['provenance'] ?? 'business_decision'), (string) ($post['history_confidence'] ?? 'confirmed'),
            $components, $actorId
        );
        return;
    }
    if ($action === 'activate_contract') {
        $factory->staffCompensationService()->activate((int) ($post['contract_id'] ?? 0), $actorId);
        return;
    }
    throw new InvalidArgumentException('عملية عقد العامل غير مدعومة.');
};
$financePage = [
    'title' => 'عقود العاملين المالية', 'icon' => 'fa-file-signature', 'view' => 'staff_contracts', 'filters' => ['staff_id'], 'create_modal' => 'contractModal', 'create_label' => 'عقد مالي جديد',
    'columns' => [
        ['key' => 'id', 'label' => 'العقد'], ['key' => 'staff_id', 'label' => 'العامل'],
        ['key' => 'effective_from', 'label' => 'ساري من', 'type' => 'date'], ['key' => 'effective_to', 'label' => 'حتى', 'type' => 'date'],
        ['key' => 'provenance', 'label' => 'المصدر'], ['key' => 'history_confidence', 'label' => 'الثقة التاريخية'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'], ['key' => 'approved_at', 'label' => 'وقت الاعتماد', 'type' => 'date'],
    ],
];
$financeRowActions = static function (array $row): string {
    return ($row['status'] ?? '') === 'draft' ? '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#activateContractModal" data-contract-id="' . (int) $row['id'] . '" title="اعتماد العقد"><i class="fas fa-check"></i></button>' : '';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="contractModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_staff_contracts.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="create_contract"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>عقد مالي جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label">العامل</label><input type="number" min="1" name="staff_id" class="form-control" required></div><div class="col-md-4"><label class="form-label">ساري من</label><input type="date" name="effective_from" class="form-control" required></div><div class="col-md-4"><label class="form-label">المصدر</label><select name="provenance" class="form-select"><option value="business_decision">قرار إداري</option><option value="legacy_migration">ترحيل قديم</option><option value="other">أخرى</option></select></div><div class="col-md-4"><label class="form-label">الثقة التاريخية</label><select name="history_confidence" class="form-select"><option value="confirmed">مؤكد</option><option value="uncertain">غير مؤكد</option></select></div></div><h6>مكونات الراتب</h6><?php for ($i=0;$i<4;$i++): ?><div class="row g-2 mb-2"><div class="col-md-4"><input type="number" min="1" name="component_id[]" class="form-control" placeholder="معرف المكون" <?php echo $i===0?'required':''; ?>></div><div class="col-md-4"><input type="number" min="0.01" step="0.01" name="component_amount[]" class="form-control" placeholder="القيمة" <?php echo $i===0?'required':''; ?>></div><div class="col-md-4"><select name="component_direction[]" class="form-select"><option value="earning">استحقاق</option><option value="deduction">استقطاع</option></select></div></div><?php endfor; ?></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ المسودة</button></div></form></div></div></div>
<div class="modal fade" id="activateContractModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_staff_contracts.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="activate_contract"><input type="hidden" name="contract_id" id="activateContractId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-check me-2"></i>اعتماد العقد المالي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-file-signature text-success" style="font-size:3rem"></i></div><p class="text-center">سيُغلق العقد النشط السابق تلقائيًا مع الاحتفاظ بكامل تاريخه. يجب أن يكون حسابك مختلفًا عن منشئ العقد.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>اعتماد</button></div></form></div></div></div>
<script>document.getElementById('activateContractModal').addEventListener('show.bs.modal',function(e){document.getElementById('activateContractId').value=e.relatedTarget.dataset.contractId;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
