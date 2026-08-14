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
    if ($action === 'create_rule') {
        $capText = trim((string) ($post['cap_amount'] ?? ''));
        if ($capText !== '' && preg_match('/^\d+(?:\.\d{1,2})?$/', $capText) !== 1) { throw new InvalidArgumentException('قيمة الحد الأقصى غير صحيحة.'); }
        $factory->discountService()->createRuleVersion(
            (string) ($post['code'] ?? ''), (int) ($post['academic_year_id'] ?? 0),
            (string) ($post['scope_charge_type_key'] ?? ''), (string) ($post['name_ar'] ?? ''),
            (int) ($post['priority'] ?? 0), !empty($post['combinable']),
            $capText === '' ? null : Money::fromDecimalString($capText),
            (string) ($post['effective_from'] ?? ''), $actorId, ($post['effective_to'] ?? '') ?: null
        );
        return;
    }
    if ($action === 'activate_rule') {
        $factory->discountService()->activateRule((int) ($post['rule_id'] ?? 0), $actorId);
        return;
    }
    if ($action === 'award_discount') {
        $amountText = (string) ($post['amount'] ?? '');
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amountText) !== 1) { throw new InvalidArgumentException('قيمة الخصم غير صحيحة.'); }
        $amount = Money::fromDecimalString($amountText);
        $awardId = $factory->discountService()->createAward(
            (int) ($post['student_account_id'] ?? 0), (int) ($post['rule_id'] ?? 0), $amount,
            (string) ($post['reason'] ?? ''), $actorId, null
        );
        return;
    }
    throw new InvalidArgumentException('عملية الخصم غير مدعومة.');
};
$financePage = [
    'title' => 'الخصومات والإعفاءات', 'icon' => 'fa-percentage', 'view' => 'discounts', 'create_modal' => 'discountRuleModal', 'create_label' => 'قاعدة خصم جديدة',
    'toolbar_links' => [['href' => 'finance_discount_awards.php', 'label' => 'طلبات الخصومات', 'icon' => 'fa-user-tag']],
    'columns' => [
        ['key' => 'code', 'label' => 'الكود'], ['key' => 'name_ar', 'label' => 'الاسم'],
        ['key' => 'academic_year_id', 'label' => 'العام الدراسي'], ['key' => 'scope_charge_type_key', 'label' => 'نوع الرسوم'],
        ['key' => 'version_number', 'label' => 'الإصدار'], ['key' => 'priority', 'label' => 'الأولوية'],
        ['key' => 'combinable', 'label' => 'قابل للجمع', 'type' => 'bool'], ['key' => 'cap_amount', 'label' => 'الحد الأقصى', 'type' => 'money'],
        ['key' => 'effective_from', 'label' => 'من', 'type' => 'date'], ['key' => 'effective_to', 'label' => 'إلى', 'type' => 'date'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'],
    ],
];
$financeRowActions = static function (array $row): string {
    $ruleId = (int) $row['id'];
    $activate = ($row['status'] ?? '') !== 'active' ? '<button type="button" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="modal" data-bs-target="#activateRuleModal" data-rule-id="' . $ruleId . '" title="تفعيل"><i class="fas fa-check"></i></button>' : '';
    return $activate . '<button type="button" class="btn btn-action-pills btn-edit" data-bs-toggle="modal" data-bs-target="#awardModal" data-rule-id="' . $ruleId . '" title="منح الخصم"><i class="fas fa-user-tag"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="discountRuleModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_discounts.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="create_rule"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>قاعدة خصم جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label">نوع الخصم</label><select name="code" class="form-select" required><option value="sibling">الإخوة</option><option value="employee_child">أبناء العاملين</option><option value="scholarship">منحة</option><option value="hardship">حالة اجتماعية</option><option value="manual">يدوي</option><option value="exemption">إعفاء</option><option value="promotional">ترويجي</option></select></div><div class="col-md-6"><label class="form-label">الاسم</label><input name="name_ar" class="form-control" maxlength="150" required></div><div class="col-md-4"><label class="form-label">العام الدراسي</label><input type="number" min="1" name="academic_year_id" class="form-control" required></div><div class="col-md-4"><label class="form-label">مفتاح نوع الرسوم</label><input name="scope_charge_type_key" class="form-control" required></div><div class="col-md-4"><label class="form-label">الأولوية</label><input type="number" min="0" name="priority" class="form-control" value="100" required></div><div class="col-md-4"><label class="form-label">الحد الأقصى</label><input type="number" min="0" step="0.01" name="cap_amount" class="form-control"></div><div class="col-md-4"><label class="form-label">ساري من</label><input type="date" name="effective_from" class="form-control" required></div><div class="col-md-4"><label class="form-label">ساري إلى</label><input type="date" name="effective_to" class="form-control"></div><div class="col-12 form-check"><input type="checkbox" class="form-check-input" name="combinable" id="combinable"><label class="form-check-label" for="combinable">يمكن جمعه مع خصومات أخرى ضمن الحد الأقصى</label></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ</button></div></form></div></div></div>
<div class="modal fade" id="awardModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_discounts.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="award_discount"><input type="hidden" name="rule_id" id="awardRuleId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-tag me-2"></i>طلب خصم لطالب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">حساب الطالب</label><input type="number" min="1" name="student_account_id" class="form-control" required></div><div class="mb-3"><label class="form-label">قيمة الخصم</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control" required></div><div class="mb-3"><label class="form-label">السبب</label><textarea name="reason" class="form-control" required></textarea></div><div class="alert alert-info"><i class="fas fa-user-check me-2"></i>يُحفظ الطلب بحالة معلقة حتى يعتمده مستخدم آخر.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-paper-plane me-1"></i>إرسال الطلب</button></div></form></div></div></div>
<div class="modal fade" id="activateRuleModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_discounts.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="activate_rule"><input type="hidden" name="rule_id" id="activateRuleId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-check me-2"></i>تفعيل قاعدة الخصم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-check-circle text-success" style="font-size:3rem"></i></div><p class="text-center">سيتم تفعيل هذا الإصدار دون تعديل الخصومات التاريخية.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>تأكيد</button></div></form></div></div></div>
<script>document.getElementById('awardModal').addEventListener('show.bs.modal',function(e){document.getElementById('awardRuleId').value=e.relatedTarget.dataset.ruleId;});document.getElementById('activateRuleModal').addEventListener('show.bs.modal',function(e){document.getElementById('activateRuleId').value=e.relatedTarget.dataset.ruleId;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
