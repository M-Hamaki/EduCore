<?php

$page_title = 'المحذوفات المؤقتة';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/utilities.php';
require_once '../classes/UndoManager.php';

Utilities::validateSession('admin');
$db = (new Database())->getConnection();
UndoManager::setDb($db);
UndoManager::getLastUndoable((int)$_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    $undoId = (int)($_POST['undo_log_id'] ?? 0);
    $result = UndoManager::undo((int)$_SESSION['user_id'], $undoId, true);
    $_SESSION[$result['success'] ? 'success_message' : 'error_message'] = $result['message'];
    header('Location: recycle_bin.php');
    exit;
}

$success = $_SESSION['success_message'] ?? null;
$error = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
$rows = $db->query(
    "SELECT rb.*, u.name AS deleted_by_name
     FROM recycle_bin rb
     INNER JOIN undo_log ul ON ul.id = rb.undo_log_id
     LEFT JOIN users u ON u.id = rb.deleted_by
     WHERE rb.restored_at IS NULL AND rb.expires_at > NOW()
       AND ul.can_undo = 1 AND ul.is_undone = 0 AND ul.undo_status = 'pending'
     ORDER BY rb.id DESC LIMIT 500"
)->fetchAll(PDO::FETCH_ASSOC);

include '../includes/admin_header.php';
?>
<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="h3 mb-1"><i class="fas fa-trash-restore text-warning me-2"></i>المحذوفات المؤقتة</h1><p class="text-muted mb-0">تُحفظ العناصر المسجلة لمدة 30 يومًا قبل انتهاء صلاحية الاستعادة.</p></div>
        <span class="badge bg-warning text-dark fs-6"><?= count($rows) ?> عنصر</span>
    </div>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="card shadow-sm" style="border-radius:8px"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>الحذف</th><th>المستخدم</th><th>العنصر</th><th>الوصف</th><th>تنتهي الاستعادة</th><th>إجراء</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-5">لا توجد عناصر قابلة للاستعادة.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?><tr>
            <td class="text-nowrap"><?= htmlspecialchars($row['created_at']) ?></td>
            <td><?= htmlspecialchars($row['deleted_by_name'] ?: ('مستخدم #' . $row['deleted_by'])) ?></td>
            <td><code><?= htmlspecialchars($row['table_name']) ?> #<?= htmlspecialchars($row['record_id']) ?></code></td>
            <td><?= htmlspecialchars($row['description'] ?: '-') ?></td>
            <td class="text-nowrap"><?= htmlspecialchars($row['expires_at']) ?></td>
            <td><form method="post" data-no-form-safety="true" data-confirm-message="هل تريد استعادة هذا العنصر؟"><?= csrfField() ?><input type="hidden" name="undo_log_id" value="<?= (int)$row['undo_log_id'] ?>"><button class="btn btn-sm btn-outline-success"><i class="fas fa-trash-restore me-1"></i>استعادة</button></form></td>
        </tr><?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
<?php include '../includes/admin_footer.php'; ?>
