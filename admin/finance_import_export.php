<?php
use EduCore\Modules\Finance\Infrastructure\FinanceImportFileParser;
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Finance\Infrastructure\LocalFinanceImportStorage;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();

$financeActionHandler = static function (FinanceServiceFactory $factory, array $post, int $actorId): ?string {
    $action = (string) ($post['action'] ?? '');
    if ($action === 'upload_import') {
        $storage = new LocalFinanceImportStorage(dirname(__DIR__));
        $stored = null;
        $batchId = null;
        try {
            $stored = $storage->storeUploadedFile($_FILES['import_file'] ?? []);
            $parsed = (new FinanceImportFileParser())->parse($stored['absolute_path'], $stored['extension']);
            $service = $factory->importService();
            $batchId = $service->createBatch(bin2hex(random_bytes(16)), $parsed['schema_version'], $stored['relative_ref'], $actorId, (string) ($post['operation_type'] ?? 'vouchers'), (int) ($post['academic_year_id'] ?? 0) ?: null);
            foreach ($parsed['rows'] as $index => $payload) {
                $service->stagePayload($batchId, $index + 1, $payload);
            }
            $preview = $service->previewBatch($batchId);
            $errors = count(array_filter($preview, static fn (array $row): bool => (string) $row['validation_status'] !== 'valid'));
            $service->updateCounts($batchId, count($preview), $errors);
        } catch (Throwable $exception) {
            if ($batchId !== null) { try { $factory->importService()->abandonBatch($batchId, $actorId); } catch (Throwable) {} }
            if (is_array($stored) && isset($stored['relative_ref'])) { $storage->delete((string) $stored['relative_ref']); }
            throw $exception;
        }
        return null;
    }
    if ($action === 'request_import_post') {
        $factory->approvalWorkflowService()->request('import_post', ['batch_id' => (int) ($post['batch_id'] ?? 0)], $actorId);
        return null;
    }
    if ($action === 'request_import_reverse') {
        $factory->approvalWorkflowService()->request('import_reverse', ['batch_id' => (int) ($post['batch_id'] ?? 0)], $actorId);
        return null;
    }
    if ($action === 'abandon_import') {
        $factory->importService()->abandonBatch((int) ($post['batch_id'] ?? 0), $actorId);
        return null;
    }
    if ($action === 'export_imports') {
        $columns = ['batch_id', 'operation_type', 'schema_version', 'row_count', 'error_count', 'status', 'created_by', 'created_at'];
        $rows = $factory->adminReadService()->rows('imports', [], 500);
        $ref = $factory->exportService()->export('finance-import-register', $rows, $columns, $columns, [], $actorId, (string) ($post['format'] ?? 'csv'));
        return 'finance_export_download.php?ref=' . rawurlencode($ref);
    }
    throw new InvalidArgumentException('إجراء الاستيراد أو التصدير غير مدعوم.');
};

$financeRowActions = static function (array $row): void {
    $id = (int) ($row['id'] ?? 0); $status = (string) ($row['status'] ?? ''); $operation = (string) ($row['operation_type'] ?? '');
    if ($status === 'staged' && (int) ($row['error_count'] ?? 0) === 0) {
        echo '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="action" value="request_import_post"><input type="hidden" name="batch_id" value="' . $id . '"><button class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="إرسال للاعتماد"><i class="fas fa-user-check"></i></button></form>';
    }
    if ($status === 'posted' && $operation !== 'staging_only' && empty($row['reversal_of'])) {
        echo '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="action" value="request_import_reverse"><input type="hidden" name="batch_id" value="' . $id . '"><button class="btn btn-action-pills btn-deactivate me-1" data-bs-toggle="tooltip" title="طلب عكس الدفعة"><i class="fas fa-undo"></i></button></form>';
    }
    if ($status === 'staged') {
        echo '<button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="modal" data-bs-target="#abandonImportModal" data-batch-id="' . $id . '" title="استبعاد الدفعة"><i class="fas fa-archive"></i></button>';
    }
};

$financePage = [
    'title' => 'الاستيراد والتصدير', 'icon' => 'fa-file-import', 'view' => 'imports',
    'create_modal' => 'uploadImportModal', 'create_label' => 'رفع ملف استيراد',
    'toolbar_links' => [['href' => '#', 'label' => 'تصدير السجل', 'icon' => 'fa-file-export', 'class' => 'btn-outline-primary', 'modal' => 'exportImportModal']],
    'success_message' => 'تم تنفيذ إجراء الاستيراد أو التصدير بنجاح.',
    'columns' => [
        ['key' => 'batch_id', 'label' => 'رقم الدفعة'], ['key' => 'operation_type', 'label' => 'العملية'],
        ['key' => 'schema_version', 'label' => 'نسخة المخطط'], ['key' => 'row_count', 'label' => 'الصفوف'],
        ['key' => 'error_count', 'label' => 'الأخطاء'], ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'],
        ['key' => 'reversal_of', 'label' => 'عكس دفعة'], ['key' => 'created_by', 'label' => 'أنشأها'],
        ['key' => 'created_at', 'label' => 'وقت الإنشاء', 'type' => 'date'],
    ],
];
require __DIR__ . '/includes/finance_list_page.php';
?>
<div class="modal fade" id="uploadImportModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="upload_import"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-import me-2"></i>رفع ملف استيراد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">نوع البيانات</label><select name="operation_type" class="form-select" required><option value="vouchers">قسائم المصروفات والإيرادات والتحويلات</option></select></div><div class="mb-3"><label class="form-label">العام الدراسي</label><input type="number" min="1" name="academic_year_id" class="form-control" required></div><div class="mb-3"><label class="form-label">ملف CSV أو XLSX</label><input type="file" name="import_file" class="form-control" accept=".csv,.xlsx" required></div><div class="alert alert-info"><i class="fas fa-shield-alt me-2"></i>يُحفظ الملف في مساحة خاصة، وتُفحص كل صفوفه قبل طلب الاعتماد.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-upload me-1"></i>رفع وفحص</button></div></form></div></div></div>
<div class="modal fade" id="exportImportModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium"><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="export_imports"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-export me-2"></i>تصدير سجل الدفعات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الصيغة</label><select name="format" class="form-select"><option value="csv">CSV</option><option value="xlsx">Excel XLSX</option><option value="pdf">PDF</option></select></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-download me-1"></i>إنشاء وتنزيل</button></div></form></div></div></div>
<div class="modal fade" id="abandonImportModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="abandon_import"><input type="hidden" name="batch_id" id="abandonImportId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-archive me-2"></i>استبعاد دفعة الاستيراد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-archive text-danger" style="font-size:3rem"></i></div><p class="text-center">سيتم أرشفة الدفعة غير المرحلة دون حذف السجل.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-archive me-1"></i>استبعاد</button></div></form></div></div></div>
<script>document.getElementById('abandonImportModal')?.addEventListener('show.bs.modal',function(event){document.getElementById('abandonImportId').value=event.relatedTarget.dataset.batchId;});</script>
<?php
require_once '../includes/admin_footer.php';
