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
    $action = (string) ($post['action'] ?? ''); $service = $factory->budgetService();
    if ($action === 'create_budget') {
        $budgetId = $service->createBudget((int) ($post['academic_year_id'] ?? 0), (string) ($post['name'] ?? ''), $actorId);
        $versionId = $service->createVersion($budgetId, $actorId);
        $amountText = trim((string) ($post['planned_amount'] ?? ''));
        if ($amountText !== '') {
            if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amountText) !== 1) { throw new InvalidArgumentException('القيمة المخططة غير صحيحة.'); }
            $service->addLine($versionId, (int) ($post['account_id'] ?? 0), (int) ($post['cost_center_id'] ?? 0) ?: null, (int) ($post['finance_period_id'] ?? 0) ?: null, Money::fromDecimalString($amountText));
        }
        return;
    }
    if ($action === 'add_line') {
        $amountText = (string) ($post['planned_amount'] ?? '');
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amountText) !== 1) { throw new InvalidArgumentException('القيمة المخططة غير صحيحة.'); }
        $service->addLine((int) ($post['version_id'] ?? 0), (int) ($post['account_id'] ?? 0), (int) ($post['cost_center_id'] ?? 0) ?: null, (int) ($post['finance_period_id'] ?? 0) ?: null, Money::fromDecimalString($amountText)); return;
    }
    $budgetId = (int) ($post['budget_id'] ?? 0);
    if ($action === 'review_budget') { $service->reviewBudget($budgetId, $actorId); return; }
    if ($action === 'approve_budget') { $service->approveBudget($budgetId, $actorId); return; }
    if ($action === 'lock_budget') { $service->lockBudget($budgetId, $actorId); return; }
    if ($action === 'revise_budget') { $service->reviseBudget($budgetId, $actorId); return; }
    throw new InvalidArgumentException('عملية الموازنة غير مدعومة.');
};
$financePage = [
    'title' => 'الموازنات', 'icon' => 'fa-chart-line', 'view' => 'budgets', 'create_modal' => 'budgetModal', 'create_label' => 'موازنة جديدة',
    'columns' => [
        ['key' => 'id', 'label' => 'الرقم'], ['key' => 'name', 'label' => 'الموازنة'],
        ['key' => 'academic_year_id', 'label' => 'العام الدراسي'], ['key' => 'version_number', 'label' => 'الإصدار'],
        ['key' => 'planned_total', 'label' => 'الإجمالي المخطط', 'type' => 'money'],
        ['key' => 'version_status', 'label' => 'حالة الإصدار', 'type' => 'status'], ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'],
    ],
    'money_total_field' => 'planned_total',
];
$financeRowActions = static function (array $row): string {
    $status = (string) ($row['status'] ?? '');
    $action = match ($status) { 'draft', 'revised' => 'review_budget', 'reviewed' => 'approve_budget', 'approved' => 'lock_budget', 'locked' => 'revise_budget', default => '' };
    $label = ['review_budget'=>'إرسال للمراجعة','approve_budget'=>'اعتماد','lock_budget'=>'إقفال','revise_budget'=>'إنشاء مراجعة'][$action] ?? '';
    $buttons = (int) ($row['budget_version_id'] ?? 0) > 0 && in_array($status, ['draft','revised'], true) ? '<button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="modal" data-bs-target="#budgetLineModal" data-version-id="' . (int) $row['budget_version_id'] . '" title="إضافة بند"><i class="fas fa-plus"></i></button>' : '';
    return $buttons . ($action !== '' ? '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#budgetTransitionModal" data-budget-id="' . (int) $row['id'] . '" data-action="' . $action . '" data-label="' . $label . '" title="' . $label . '"><i class="fas fa-forward"></i></button>' : '');
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="budgetModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_budgets.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="create_budget"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>موازنة جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">العام الدراسي</label><input type="number" min="1" name="academic_year_id" class="form-control" required></div><div class="mb-3"><label class="form-label">اسم الموازنة</label><input name="name" class="form-control" required></div><hr><p class="text-muted">بند افتتاحي اختياري</p><div class="mb-3"><label class="form-label">الحساب</label><input type="number" min="1" name="account_id" class="form-control"></div><div class="mb-3"><label class="form-label">القيمة المخططة</label><input type="number" min="0.01" step="0.01" name="planned_amount" class="form-control"></div><div class="mb-3"><label class="form-label">مركز التكلفة</label><input type="number" min="1" name="cost_center_id" class="form-control"></div><div class="mb-3"><label class="form-label">الفترة المالية</label><input type="number" min="1" name="finance_period_id" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>إنشاء</button></div></form></div></div></div>
<div class="modal fade" id="budgetLineModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_budgets.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="add_line"><input type="hidden" name="version_id" id="budgetVersionId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus me-2"></i>إضافة بند موازنة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">الحساب</label><input type="number" min="1" name="account_id" class="form-control" required></div><div class="mb-3"><label class="form-label">القيمة المخططة</label><input type="number" min="0.01" step="0.01" name="planned_amount" class="form-control" required></div><div class="mb-3"><label class="form-label">مركز التكلفة</label><input type="number" min="1" name="cost_center_id" class="form-control"></div><div class="mb-3"><label class="form-label">الفترة المالية</label><input type="number" min="1" name="finance_period_id" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>إضافة</button></div></form></div></div></div>
<div class="modal fade" id="budgetTransitionModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_budgets.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" id="budgetAction"><input type="hidden" name="budget_id" id="budgetId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-forward me-2"></i><span id="budgetActionLabel">تحديث الموازنة</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-chart-line text-primary" style="font-size:3rem"></i></div><p class="text-center">سيتم حفظ الانتقال في سجل العمليات. لا يستطيع منشئ الموازنة اعتمادها بنفسه.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>تأكيد</button></div></form></div></div></div>
<script>document.getElementById('budgetLineModal').addEventListener('show.bs.modal',function(e){document.getElementById('budgetVersionId').value=e.relatedTarget.dataset.versionId;});document.getElementById('budgetTransitionModal').addEventListener('show.bs.modal',function(e){var d=e.relatedTarget.dataset;document.getElementById('budgetId').value=d.budgetId;document.getElementById('budgetAction').value=d.action;document.getElementById('budgetActionLabel').textContent=d.label;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
