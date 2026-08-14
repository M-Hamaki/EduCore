<?php

declare(strict_types=1);

use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditService;

if (!isset($financePage) || !is_array($financePage)) {
    throw new RuntimeException('Finance page configuration is required.');
}
requireCsrfPost();

$page_title = (string) $financePage['title'];
$custom_page_title = true;
$database = new Database();
$db = $database->getConnection();
$audit = new AuditService($db);
$financeFactory = new FinanceServiceFactory($db, $audit);
$actorId = (int) ($_SESSION['user_id'] ?? 0);
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($financeActionHandler) && is_callable($financeActionHandler)) {
    try {
        $actionRedirect = $financeActionHandler($financeFactory, $_POST, $actorId, $db);
        $_SESSION['success_message'] = (string) ($financePage['success_message'] ?? 'تم تنفيذ العملية المالية بنجاح.');
    } catch (Throwable $exception) {
        error_log('Finance admin action failed: ' . $exception->getMessage());
        $_SESSION['error_message'] = 'تعذر تنفيذ العملية المالية. راجع البيانات والصلاحيات ثم حاول مرة أخرى.';
    }
    header('Location: ' . (is_string($actionRedirect ?? null) && $actionRedirect !== '' ? $actionRedirect : basename((string) ($_SERVER['PHP_SELF'] ?? 'finance_dashboard.php'))));
    exit();
}

$filters = [
    'student_id' => max(0, (int) ($_GET['student_id'] ?? 0)),
    'staff_id' => max(0, (int) ($_GET['staff_id'] ?? 0)),
    'academic_year_id' => max(0, (int) ($_GET['academic_year_id'] ?? 0)),
];
$serverSideViews = ['receipts', 'student_ledger', 'staff_ledger', 'payroll_runs', 'payroll_items', 'journal', 'audit_log'];
$serverSide = in_array((string) $financePage['view'], $serverSideViews, true);
$rows = [];
if (!$serverSide) {
    try {
        $rows = $financeFactory->adminReadService()->rows((string) $financePage['view'], $filters, 200);
    } catch (Throwable $exception) {
        error_log('Finance admin read failed: ' . $exception->getMessage());
        $error_message = $error_message ?: 'تعذر تحميل البيانات المالية. تأكد من تطبيق migrations الخاصة بالميزة.';
    }
}

$moneyField = $financePage['money_total_field'] ?? null;
$moneyTotal = 0.0;
if (is_string($moneyField) && $moneyField !== '') {
    foreach ($rows as $row) {
        $moneyTotal += (float) ($row[$moneyField] ?? 0);
    }
}
$statusField = (string) ($financePage['status_field'] ?? 'status');
$activeStatuses = (array) ($financePage['active_statuses'] ?? ['active', 'posted', 'approved', 'open', 'locked']);
$activeCount = count(array_filter($rows, static fn (array $row): bool => in_array((string) ($row[$statusField] ?? ''), $activeStatuses, true)));

require_once __DIR__ . '/../../includes/admin_header.php';

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusClass = static function (string $status): string {
    return match ($status) {
        'active', 'posted', 'approved', 'paid', 'open', 'locked', 'settled' => 'success',
        'draft', 'pending', 'staged', 'calculated', 'reviewed' => 'warning text-dark',
        'reversed', 'superseded', 'archived', 'closed', 'abandoned', 'written_off' => 'secondary',
        'rejected', 'error' => 'danger',
        default => 'info text-dark',
    };
};
$formatValue = static function (mixed $value, string $type) use ($escape, $statusClass): string {
    if ($value === null || $value === '') {
        return '<span class="text-muted">—</span>';
    }
    return match ($type) {
        'money' => '<span class="fw-semibold">' . number_format((float) $value, 2) . ' ج.م</span>',
        'status' => '<span class="badge bg-' . $statusClass((string) $value) . '">' . $escape($value) . '</span>',
        'bool' => (bool) $value ? '<span class="badge bg-success">نعم</span>' : '<span class="badge bg-secondary">لا</span>',
        'date' => '<span dir="ltr">' . $escape($value) . '</span>',
        default => $escape($value),
    };
};
?>

<div class="admin-page-heading mb-4">
    <div class="admin-page-title">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas <?php echo $escape($financePage['icon'] ?? 'fa-coins'); ?> me-2 text-primary"></i><?php echo $escape($financePage['title']); ?>
        </h1>
    </div>
    <div class="admin-top-actions">
        <a href="finance_dashboard.php" class="btn btn-outline-secondary shadow-sm">
            <i class="fas fa-arrow-right me-2"></i>العودة للوحة
        </a>
        <?php if (!empty($financePage['create_modal'])): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#<?php echo $escape($financePage['create_modal']); ?>">
                <i class="fas fa-plus-circle me-2"></i><?php echo $escape($financePage['create_label'] ?? 'إضافة جديد'); ?>
            </button>
        <?php endif; ?>
        <?php foreach ((array) ($financePage['toolbar_links'] ?? []) as $link): ?>
            <?php if (!empty($link['modal'])): ?>
                <button type="button" class="btn <?php echo $escape($link['class'] ?? 'btn-outline-primary'); ?> shadow-sm" data-bs-toggle="modal" data-bs-target="#<?php echo $escape($link['modal']); ?>">
                    <i class="fas <?php echo $escape($link['icon'] ?? 'fa-chart-pie'); ?> me-2"></i><?php echo $escape($link['label']); ?>
                </button>
            <?php else: ?>
                <a href="<?php echo $escape($link['href']); ?>" class="btn <?php echo $escape($link['class'] ?? 'btn-outline-primary'); ?> shadow-sm">
                    <i class="fas <?php echo $escape($link['icon'] ?? 'fa-chart-pie'); ?> me-2"></i><?php echo $escape($link['label']); ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($success_message): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo $escape($success_message); ?><button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
<?php if ($error_message): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo $escape($error_message); ?><button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>

<div class="row row-cols-2 row-cols-md-3 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-list"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo count($rows); ?>">0</div><div class="stat-card-label">إجمالي السجلات</div><div class="stat-card-sub"><i class="fas fa-database"></i> ضمن الفلتر الحالي</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-check-circle"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $activeCount; ?>">0</div><div class="stat-card-label">نشط / مثبت</div><div class="stat-card-sub"><i class="fas fa-shield-alt"></i> حسب الحالة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);"><div class="stat-card-icon"><i class="fas fa-coins"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int) round($moneyTotal); ?>">0</div><div class="stat-card-label">إجمالي القيمة</div><div class="stat-card-sub"><i class="fas fa-money-bill"></i> ج.م</div></div></div></div>
</div>

<?php if (!empty($financePage['filters'])): ?>
<form method="get" class="admin-filter-bar">
    <div class="admin-filter-controls">
        <?php foreach ((array) $financePage['filters'] as $filter): ?>
            <label class="visually-hidden" for="<?php echo $escape($filter); ?>"><?php echo $escape($filter); ?></label>
            <input type="number" min="1" class="form-control form-control-sm" id="<?php echo $escape($filter); ?>" name="<?php echo $escape($filter); ?>" value="<?php echo $filters[$filter] ?: ''; ?>" placeholder="<?php echo $filter === 'student_id' ? 'معرف الطالب' : ($filter === 'staff_id' ? 'معرف العامل' : 'معرف العام الدراسي'); ?>">
        <?php endforeach; ?>
    </div>
    <div class="admin-filter-actions"><a href="<?php echo $escape(basename((string) ($_SERVER['PHP_SELF'] ?? ''))); ?>" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a><button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button></div>
</form>
<?php endif; ?>

<div class="admin-list-surface">
    <div class="admin-table-wrap">
        <table id="financeDataTable" class="table table-hover table-striped <?php echo $serverSide ? '' : 'datatable'; ?> admin-data-table">
            <thead><tr><?php foreach ((array) $financePage['columns'] as $column): ?><th><?php echo $escape($column['label']); ?></th><?php endforeach; ?><?php if (isset($financeRowActions) && is_callable($financeRowActions)): ?><th class="text-center actions-column admin-table-actions">الإجراءات</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?><tr>
                <?php foreach ((array) $financePage['columns'] as $column): ?><td><?php echo $formatValue($row[$column['key']] ?? null, (string) ($column['type'] ?? 'text')); ?></td><?php endforeach; ?>
                <?php if (isset($financeRowActions) && is_callable($financeRowActions)): ?><td class="text-center actions-column admin-table-actions"><?php echo $financeRowActions($row, $escape); ?></td><?php endif; ?>
            </tr><?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="<?php echo count((array) $financePage['columns']) + (isset($financeRowActions) ? 1 : 0); ?>" class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>لا توجد بيانات مطابقة.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (isset($financeModalRenderer) && is_callable($financeModalRenderer)) { $financeModalRenderer($rows, $escape); } ?>

<?php if ($serverSide): ?>
<script src="../assets/js/admin-server-side-table.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var escapeHtml = function (value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    };
    var statusClass = function (value) {
        return ['posted','approved','active','open','paid','completed'].indexOf(String(value)) >= 0 ? 'success'
            : (['draft','pending','reviewed','calculated'].indexOf(String(value)) >= 0 ? 'warning'
            : (['reversed','rejected','closed','failed'].indexOf(String(value)) >= 0 ? 'danger' : 'secondary'));
    };
    var columns = <?php echo json_encode(array_map(static function (array $column): array {
        return ['data' => (string) $column['key'], 'type' => (string) ($column['type'] ?? 'text')];
    }, (array) $financePage['columns']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    columns = columns.map(function (column) {
        return {
            data: column.data,
            name: column.data,
            render: function (value, displayType) {
                if (displayType !== 'display') return value;
                if (column.type === 'money') return escapeHtml(value || '0.00') + ' ج.م';
                if (column.type === 'status') return '<span class="badge bg-' + statusClass(value) + '">' + escapeHtml(value || '-') + '</span>';
                if (column.type === 'bool') return Number(value) === 1 ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>';
                return escapeHtml(value == null || value === '' ? '-' : value);
            }
        };
    });
    <?php if (isset($financeRowActions) && is_callable($financeRowActions)): ?>
    columns.push({data: '__actions', name: '__actions', orderable: false, searchable: false, defaultContent: ''});
    <?php endif; ?>
    AdminServerSideTable.init({
        selector: '#financeDataTable',
        url: 'finance_datatable.php',
        order: [[0, 'desc']],
        requestData: function () {
            return {
                view: <?php echo json_encode((string) $financePage['view']); ?>,
                student_id: <?php echo (int) $filters['student_id']; ?>,
                staff_id: <?php echo (int) $filters['staff_id']; ?>,
                academic_year_id: <?php echo (int) $filters['academic_year_id']; ?>
            };
        },
        dtOptions: {columns: columns}
    });
});
</script>
<?php endif; ?>
