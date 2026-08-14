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
    if (($post['action'] ?? '') !== 'restore') { throw new InvalidArgumentException('عملية الأرشيف غير مدعومة.'); }
    $factory->archiveService()->restore((string) ($post['entity_type'] ?? ''), (int) ($post['entity_id'] ?? 0), $actorId);
};
$financePage = [
    'title' => 'الأرشيف المالي', 'icon' => 'fa-archive', 'view' => 'archive',
    'columns' => [
        ['key' => 'entity_type', 'label' => 'نوع السجل'], ['key' => 'entity_id', 'label' => 'الرقم'],
        ['key' => 'entity_name', 'label' => 'الاسم'], ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'],
    ],
    'empty_message' => 'لا توجد سجلات مالية مؤرشفة.',
];
$financeRowActions = static function (array $row): string {
    return '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#restoreModal" data-entity-type="' . htmlspecialchars((string) $row['entity_type'], ENT_QUOTES, 'UTF-8') . '" data-entity-id="' . (int) $row['entity_id'] . '" title="استعادة"><i class="fas fa-trash-restore"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="restoreModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_archive.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="entity_type" id="restoreEntityType"><input type="hidden" name="entity_id" id="restoreEntityId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash-restore me-2"></i>استعادة السجل</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-trash-restore text-success" style="font-size:3rem"></i></div><p class="text-center">سيُعاد السجل للحالة المسودة أو النشطة إذا لم توجد موانع مرجعية.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-trash-restore me-1"></i>استعادة</button></div></form></div></div></div>
<script>document.getElementById('restoreModal').addEventListener('show.bs.modal',function(e){var d=e.relatedTarget.dataset;document.getElementById('restoreEntityType').value=d.entityType;document.getElementById('restoreEntityId').value=d.entityId;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
