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
    $action = (string) ($post['action'] ?? '');
    $service = $factory->payrollRunService();
    if ($action === 'create_run') { $service->createRun((int) ($post['payroll_period_id'] ?? 0), $actorId, !empty($post['is_settlement'])); return; }
    if ($action === 'calculate_run') { $service->markCalculated((int) ($post['run_id'] ?? 0), $actorId); return; }
    if ($action === 'review_run') { $service->reviewRun((int) ($post['run_id'] ?? 0), $actorId); return; }
    if ($action === 'approve_run') { $factory->approvalWorkflowService()->request('payroll_approve', ['run_id' => (int) ($post['run_id'] ?? 0)], $actorId); return; }
    if ($action === 'finalize_run') { $factory->approvalWorkflowService()->request('payroll_finalize', ['run_id' => (int) ($post['run_id'] ?? 0)], $actorId); return; }
    if ($action === 'reverse_run') { $factory->approvalWorkflowService()->request('payroll_run_reverse', ['run_id' => (int) ($post['run_id'] ?? 0), 'reason' => (string) ($post['reason'] ?? '')], $actorId); return; }
    throw new InvalidArgumentException('عملية دورة الرواتب غير مدعومة.');
};
$financePage = [
    'title' => 'دورات الرواتب', 'icon' => 'fa-money-check-alt', 'view' => 'payroll_runs', 'create_modal' => 'payrollRunModal', 'create_label' => 'دورة رواتب جديدة',
    'toolbar_links' => [['href' => 'finance_payroll_items.php', 'label' => 'قسائم الرواتب', 'icon' => 'fa-file-invoice-dollar']],
    'columns' => [
        ['key' => 'id', 'label' => 'الدورة'], ['key' => 'payroll_period_id', 'label' => 'الفترة'],
        ['key' => 'start_date', 'label' => 'من', 'type' => 'date'], ['key' => 'end_date', 'label' => 'إلى', 'type' => 'date'],
        ['key' => 'version_number', 'label' => 'الإصدار'], ['key' => 'is_settlement', 'label' => 'تسوية', 'type' => 'bool'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'], ['key' => 'reversal_of', 'label' => 'عكس دورة'],
    ],
    'toolbar_links' => [['href' => 'finance_staff_contracts.php', 'label' => 'عقود العاملين', 'icon' => 'fa-file-signature']],
];
$financeRowActions = static function (array $row): string {
    if ((string) ($row['status'] ?? '') === 'posted' && empty($row['reversal_of'])) { return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#payrollReverseModal" data-run-id="' . (int) $row['id'] . '" title="عكس الدورة"><i class="fas fa-undo"></i></button>'; }
    $next = match ((string) ($row['status'] ?? '')) { 'draft' => 'calculate_run', 'calculated' => 'review_run', 'reviewed' => 'approve_run', 'approved' => 'finalize_run', default => '' };
    if ($next === '') { return ''; }
    $label = ['calculate_run' => 'احتساب', 'review_run' => 'مراجعة', 'approve_run' => 'اعتماد', 'finalize_run' => 'ترحيل'][$next];
    return '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#payrollTransitionModal" data-run-id="' . (int) $row['id'] . '" data-action="' . $next . '" data-label="' . $label . '" title="' . $label . '"><i class="fas fa-forward"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="payrollRunModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_payroll_runs.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="create_run"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>دورة رواتب جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">فترة الرواتب</label><input type="number" min="1" name="payroll_period_id" class="form-control" required></div><div class="form-check"><input type="checkbox" name="is_settlement" class="form-check-input" id="isSettlement"><label for="isSettlement" class="form-check-label">دورة تسوية فروقات رجعية</label></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>إنشاء</button></div></form></div></div></div>
<div class="modal fade" id="payrollTransitionModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_payroll_runs.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" id="payrollAction"><input type="hidden" name="run_id" id="payrollRunId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-forward me-2"></i><span id="payrollActionLabel">تحديث الدورة</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-money-check-alt text-primary" style="font-size:3rem"></i></div><p class="text-center">سيتم الانتقال إلى المرحلة التالية مع تسجيل المستخدم والوقت. يمنع النظام منشئ الدورة من اعتمادها بنفسه.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>تأكيد</button></div></form></div></div></div>
<div class="modal fade" id="payrollReverseModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_payroll_runs.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="reverse_run"><input type="hidden" name="run_id" id="reversePayrollRunId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-undo me-2"></i>طلب عكس دورة الرواتب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-undo text-warning" style="font-size:3rem"></i></div><label class="form-label">سبب العكس</label><textarea name="reason" class="form-control" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div></form></div></div></div>
<script>document.getElementById('payrollTransitionModal').addEventListener('show.bs.modal',function(e){var d=e.relatedTarget.dataset;document.getElementById('payrollRunId').value=d.runId;document.getElementById('payrollAction').value=d.action;document.getElementById('payrollActionLabel').textContent=d.label+' دورة الرواتب';});document.getElementById('payrollReverseModal').addEventListener('show.bs.modal',function(e){document.getElementById('reversePayrollRunId').value=e.relatedTarget.dataset.runId;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
