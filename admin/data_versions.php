<?php

$page_title = 'سجل الإصدارات والتراجع';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../includes/session_config.php';
require_once '../classes/utilities.php';
require_once '../classes/UndoManager.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
$db = (new Database())->getConnection();
UndoManager::setDb($db);
UndoManager::getLastUndoable((int)$_SESSION['user_id']);
$retentionHours = UndoManager::retentionHours();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    $entryId = (int)($_POST['entry_id'] ?? 0);
    $result = UndoManager::undo((int)$_SESSION['user_id'], $entryId, true);
    $_SESSION[$result['success'] ? 'success_message' : 'error_message'] = $result['message'];
    header('Location: data_versions.php');
    exit;
}

$success = $_SESSION['success_message'] ?? null;
$error = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$action = $_GET['action_type'] ?? '';
$table = trim((string)($_GET['table_name'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;
$where = [];
$params = [];
if (in_array($action, ['insert', 'update', 'delete'], true)) {
    $where[] = 'ul.action_type = ?';
    $params[] = $action;
}
if ($table !== '') {
    $where[] = 'ul.table_name = ?';
    $params[] = $table;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM undo_log ul {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT ul.*, u.name AS user_name,
            (ul.can_undo = 1 AND ul.is_undone = 0 AND ul.undo_status = 'pending'
             AND ul.created_at > DATE_SUB(NOW(), INTERVAL {$retentionHours} HOUR)) AS is_available
     FROM undo_log ul
     LEFT JOIN users u ON u.id = ul.user_id
     {$whereSql}
     ORDER BY ul.id DESC
     LIMIT {$limit} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$tables = $db->query('SELECT DISTINCT table_name FROM undo_log ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
$pages = max(1, (int)ceil($total / $limit));

$actionLabels = ['insert' => 'إضافة', 'update' => 'تعديل', 'delete' => 'حذف'];
$actionColors = ['insert' => 'success', 'update' => 'primary', 'delete' => 'danger'];

include '../includes/admin_header.php';
?>

<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-history text-primary me-2"></i>سجل الإصدارات والتراجع</h1>
            <p class="text-muted mb-0">مراجعة عمليات تغيير البيانات وحالة التراجع عنها.</p>
        </div>
        <span class="badge bg-primary fs-6"><?= number_format($total) ?> عملية</span>
    </div>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-4" style="border-radius:8px">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end" data-no-form-safety="true">
                <div class="col-md-4">
                    <label class="form-label">نوع العملية</label>
                    <select class="form-select" name="action_type">
                        <option value="">كل العمليات</option>
                        <?php foreach ($actionLabels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $action === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">الجدول</label>
                    <select class="form-select" name="table_name">
                        <option value="">كل الجداول</option>
                        <?php foreach ($tables as $tableName): ?>
                            <option value="<?= htmlspecialchars($tableName) ?>" <?= $table === $tableName ? 'selected' : '' ?>><?= htmlspecialchars($tableName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1"><i class="fas fa-filter me-1"></i>تطبيق</button>
                    <a class="btn btn-outline-secondary" href="data_versions.php"><i class="fas fa-rotate-left"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm" style="border-radius:8px">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>الوقت</th><th>المستخدم</th><th>العملية</th><th>البيانات</th><th>الوصف</th><th>الحالة</th><th>إجراء</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5">لا توجد إصدارات مسجلة.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars($row['created_at']) ?></td>
                        <td><?= htmlspecialchars($row['user_name'] ?: ('مستخدم #' . $row['user_id'])) ?></td>
                        <td><span class="badge bg-<?= $actionColors[$row['action_type']] ?? 'secondary' ?>"><?= $actionLabels[$row['action_type']] ?? $row['action_type'] ?></span></td>
                        <td><code><?= htmlspecialchars($row['table_name']) ?> #<?= htmlspecialchars($row['record_id']) ?></code></td>
                        <td><?= htmlspecialchars($row['description'] ?: '-') ?></td>
                        <td>
                            <?php if ($row['is_undone']): ?>
                                <span class="badge bg-secondary">تم التراجع</span>
                            <?php elseif (!(int)$row['can_undo']): ?>
                                <span class="badge bg-info text-dark" title="<?= htmlspecialchars($row['failure_reason'] ?: 'تتطلب معالجة بديلة') ?>">مسجل فقط</span>
                            <?php elseif ($row['undo_status'] !== 'pending'): ?>
                                <span class="badge bg-danger">تعذر التراجع</span>
                            <?php elseif (!(int)$row['is_available']): ?>
                                <span class="badge bg-light text-dark">انتهت المهلة</span>
                            <?php else: ?>
                                <span class="badge bg-success">قابل للتراجع</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$row['is_available']): ?>
                                <form method="post" class="d-inline" data-no-form-safety="true" data-confirm-message="سيتم فحص التعارضات ثم استعادة هذه العملية. هل تريد المتابعة؟">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="entry_id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-sm btn-outline-warning"><i class="fas fa-undo"></i> استعادة</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="card-footer d-flex justify-content-center gap-2">
                <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-primary" href="?<?= http_build_query(['action_type'=>$action,'table_name'=>$table,'page'=>$page-1]) ?>">السابق</a><?php endif; ?>
                <span class="btn btn-sm btn-light disabled"><?= $page ?> / <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-primary" href="?<?= http_build_query(['action_type'=>$action,'table_name'=>$table,'page'=>$page+1]) ?>">التالي</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
