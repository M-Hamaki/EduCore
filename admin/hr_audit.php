<?php

declare(strict_types=1);

/** Read-only, Staff-scoped audit surface; undo ownership remains centralized. */

$page_title = 'سجل مراجعة شؤون العاملين';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
require_once '../src/Modules/Operations/Audit/SystemActivityLogQuery.php';

use EduCore\Modules\Operations\Audit\SystemActivityLogQuery;

$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$tab = (string) ($_GET['tab'] ?? 'active') === 'undone' ? 'undone' : 'active';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$filters = [
    'target_type_prefix' => 'staff_',
    'operational_search' => true,
    'action' => trim((string) ($_GET['action'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
$auditData = ['rows' => [], 'total' => 0, 'undone_total' => 0];
$auditLoadError = null;

try {
    $database = new Database();
    $query = new SystemActivityLogQuery($database->getConnection());
    $auditData = $query->load($filters, $tab, $perPage, ($page - 1) * $perPage);
} catch (Throwable $exception) {
    error_log('Staff HR audit read unavailable: ' . $exception->getMessage());
    $auditLoadError = 'لا يمكن تحميل سجل المراجعة الآن. أعد المحاولة لاحقًا أو تحقق من حالة مخطط السجل.';
}

$totalPages = max(1, (int) ceil((int) ($auditData['total'] ?? 0) / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$undoLabels = [
    'available' => ['متاح وفق السياسة', 'success'],
    'completed' => ['تم التراجع', 'secondary'],
    'unavailable' => ['غير متاح', 'secondary'],
];
$queryForPage = static function (int $targetPage, ?string $targetTab = null) use ($filters, $tab): string {
    $params = array_filter(array_merge($filters, ['tab' => $targetTab ?? $tab, 'page' => $targetPage]), static fn (mixed $value): bool => $value !== '');
    unset($params['target_type_prefix'], $params['operational_search']);

    return '?' . http_build_query($params);
};

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom animate-up">
    <div>
        <h1 class="h2 fw-bold text-dark"><i class="fas fa-shield-halved me-3 text-primary"></i>سجل مراجعة شؤون العاملين</h1>
        <p class="text-muted m-0">عرض تشغيلي للعمليات المسجلة فقط؛ لا يكشف هذا السطح أسباب الطلبات أو المرفقات أو نصوص الشكاوى.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="hr_center.php" class="btn btn-outline-primary shadow-sm px-3 py-2"><i class="fas fa-layer-group me-2"></i>مركز شؤون العاملين</a>
        <a href="activity_logs.php" class="btn btn-outline-secondary shadow-sm px-3 py-2"><i class="fas fa-list me-2"></i>السجل العام</a>
    </div>
</div>

<?php if ($auditLoadError !== null): ?>
    <div class="alert alert-warning" role="alert"><i class="fas fa-triangle-exclamation me-2"></i><?php echo $h($auditLoadError); ?></div>
<?php endif; ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-list-check"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int) ($auditData['total'] ?? 0); ?>">0</div><div class="stat-card-label">عمليات Staff</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-clock-rotate-left"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int) ($auditData['undone_total'] ?? 0); ?>">0</div><div class="stat-card-label">عمليات تم التراجع عنها</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);"><div class="stat-card-icon"><i class="fas fa-table-list"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo count($auditData['rows'] ?? []); ?>">0</div><div class="stat-card-label">صفوف هذه الصفحة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-filter"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $tab === 'undone' ? 1 : 0; ?>">0</div><div class="stat-card-label"><?php echo $tab === 'undone' ? 'عرض المتراجع عنه' : 'عرض العمليات السارية'; ?></div></div></div></div>
</div>

<form method="get" class="admin-filter-bar mb-3">
    <input type="hidden" name="tab" value="<?php echo $h($tab); ?>">
    <div class="admin-filter-controls">
        <div><label class="form-label visually-hidden" for="auditAction">العملية</label><input class="form-control form-control-sm" id="auditAction" name="action" value="<?php echo $h($filters['action']); ?>" maxlength="100" placeholder="اسم العملية"></div>
        <div><label class="form-label visually-hidden" for="auditFrom">من</label><input type="date" class="form-control form-control-sm" id="auditFrom" name="date_from" value="<?php echo $h($filters['date_from']); ?>"></div>
        <div><label class="form-label visually-hidden" for="auditTo">إلى</label><input type="date" class="form-control form-control-sm" id="auditTo" name="date_to" value="<?php echo $h($filters['date_to']); ?>"></div>
        <div><label class="form-label visually-hidden" for="auditSearch">بحث</label><input class="form-control form-control-sm" id="auditSearch" name="search" value="<?php echo $h($filters['search']); ?>" maxlength="120" placeholder="الفاعل أو العملية أو نوع المورد"></div>
    </div>
    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="hr_audit.php?tab=<?php echo $h($tab); ?>" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
    </div>
</form>

<ul class="nav nav-pills gap-2 mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'active' ? 'active' : ''; ?>" href="hr_audit.php<?php echo $h($queryForPage(1, 'active')); ?>"><i class="fas fa-list-check me-1"></i>العمليات السارية</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'undone' ? 'active' : ''; ?>" href="hr_audit.php<?php echo $h($queryForPage(1, 'undone')); ?>"><i class="fas fa-clock-rotate-left me-1"></i>المتراجع عنها</a></li>
</ul>

<div class="admin-list-surface">
    <div class="admin-table-wrap table-responsive">
        <table class="table table-hover table-striped admin-data-table mb-0">
            <thead><tr><th>الوقت</th><th>الفاعل</th><th>العملية</th><th>المورد</th><th>النتيجة</th><th>حالة الاستعادة</th></tr></thead>
            <tbody>
            <?php foreach ($auditData['rows'] as $row): ?>
                <?php $undo = $undoLabels[SystemActivityLogQuery::undoState($row)] ?? $undoLabels['unavailable']; ?>
                <tr>
                    <td class="text-nowrap"><?php echo $h($row['created_at'] ?? '—'); ?></td>
                    <td><?php echo $h($row['user_name'] ?? 'النظام'); ?></td>
                    <td><code><?php echo $h($row['action'] ?? '—'); ?></code></td>
                    <td><div class="fw-semibold">#<?php echo (int) ($row['target_id'] ?? 0); ?></div><small class="text-muted"><?php echo $h($row['target_type'] ?? '—'); ?></small></td>
                    <td><?php echo $h($row['result'] ?? 'مسجل'); ?></td>
                    <td><span class="badge text-bg-<?php echo $h($undo[1]); ?>"><?php echo $h($undo[0]); ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (($auditData['rows'] ?? []) === []): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد عمليات Staff مطابقة للفلاتر الحالية.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="mt-3" aria-label="صفحات سجل المراجعة"><ul class="pagination justify-content-center mb-0">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="hr_audit.php<?php echo $h($queryForPage(max(1, $page - 1))); ?>">السابق</a></li>
        <li class="page-item disabled"><span class="page-link">صفحة <?php echo $page; ?> من <?php echo $totalPages; ?></span></li>
        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="hr_audit.php<?php echo $h($queryForPage(min($totalPages, $page + 1))); ?>">التالي</a></li>
    </ul></nav>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
