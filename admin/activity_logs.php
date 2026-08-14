<?php
/**
 * سجل نشاطات المشرفين - Activity Logs
 */
$page_title = "سجل النشاطات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';

Utilities::validateSession('admin');

require_once '../includes/csrf.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/UndoManager.php';
require_once '../classes/SystemActivityLogQuery.php';

$database = new Database();
$db = $database->getConnection();

ActivityLog::setDb($db);
UndoManager::setDb($db);
$systemActivityLogQuery = new SystemActivityLogQuery($db);

$activeRole = (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
$canManageSystemUndo = $activeRole === 'super_admin';
$activeLogTab = ($_GET['log_tab'] ?? '') === 'undone' ? 'undone' : 'active';

/** @return array<string,string|int> */
function systemActivityLogReturnQuery(array $input): array
{
    $query = [];
    foreach (['action', 'target_type', 'date_from', 'date_to', 'search', 'log_tab'] as $key) {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value !== '') {
            $query[$key] = mb_substr($value, 0, 150);
        }
    }
    $page = max(1, (int) ($input['page'] ?? 1));
    if ($page > 1) {
        $query['page'] = $page;
    }

    return $query;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();

    $requestedAction = (string) ($_POST['action'] ?? '');
    $isRedo = $requestedAction === 'redo_system_activity';
    $flash = ['type' => 'danger', 'message' => $isRedo ? 'تعذر تنفيذ طلب إعادة العمل.' : 'تعذر تنفيذ طلب التراجع.'];
    if (!in_array($requestedAction, ['undo_system_activity', 'redo_system_activity'], true)) {
        $flash['message'] = 'طلب العملية غير معروف.';
    } elseif (!$canManageSystemUndo) {
        $flash['message'] = 'التراجع وإعادة العمل من سجل النظام الموحّد متاحان للمدير العام فقط.';
    } else {
        $activityId = filter_var($_POST['activity_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $undoId = filter_var($_POST['undo_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $operation = $isRedo
            ? $systemActivityLogQuery->findRedoableOperation($activityId, $undoId)
            : $systemActivityLogQuery->findUndoableOperation($activityId, $undoId);

        if ($operation === null) {
            $flash['message'] = $isRedo
                ? 'العملية غير مرتبطة بسجل متراجع عنه أو لم تعد قابلة لإعادة العمل.'
                : 'العملية غير مرتبطة بسجل تراجع معلّق، أو تم التراجع عنها بالفعل.';
        } else {
            $result = $isRedo
                ? UndoManager::redo((int) ($_SESSION['user_id'] ?? 0), $undoId, true)
                : UndoManager::undo((int) ($_SESSION['user_id'] ?? 0), $undoId, true, null);
            if (!empty($result['success'])) {
                $flash = [
                    'type' => 'success',
                    'message' => (string) ($result['message'] ?? ($isRedo ? 'تمت إعادة تنفيذ العملية بنجاح.' : 'تم التراجع عن العملية بنجاح.')),
                ];
            } else {
                $flash['message'] = (string) ($result['message'] ?? ($isRedo
                    ? 'تعذرت إعادة العمل بسبب تعارض أو تغيّر البيانات.'
                    : 'تعذر التراجع بسبب تعارض أو تغيّر البيانات.'));
            }
        }
    }

    $_SESSION['system_activity_log_flash'] = $flash;
    $returnQuery = systemActivityLogReturnQuery($_GET);
    header('Location: activity_logs.php' . ($returnQuery !== [] ? '?' . http_build_query($returnQuery) : ''));
    exit;
}

$activityLogFlash = $_SESSION['system_activity_log_flash'] ?? null;
unset($_SESSION['system_activity_log_flash']);

// Include header
require_once '../includes/admin_header.php';

// Filters
$filters = [];
if (!empty($_GET['action'])) $filters['action'] = $_GET['action'];
if (!empty($_GET['target_type'])) $filters['target_type'] = $_GET['target_type'];
if (!empty($_GET['date_from'])) $filters['date_from'] = $_GET['date_from'];
if (!empty($_GET['date_to'])) $filters['date_to'] = $_GET['date_to'];
if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];

// Pagination
$per_page = 50;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$total_logs = 0;
$undone_total = 0;
$logs = [];
try {
    $logData = $systemActivityLogQuery->load($filters, $activeLogTab, $per_page, $offset);
    $logs = $logData['rows'];
    $total_logs = (int) $logData['total'];
    $undone_total = (int) $logData['undone_total'];
} catch (Throwable $exception) {
    error_log('System activity undo metadata unavailable: ' . $exception->getMessage());
}
$total_pages = max(1, (int) ceil($total_logs / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
    try {
        $logData = $systemActivityLogQuery->load($filters, $activeLogTab, $per_page, $offset);
        $logs = $logData['rows'];
        $total_logs = (int) $logData['total'];
        $undone_total = (int) $logData['undone_total'];
    } catch (Throwable $exception) {
        error_log('System activity page correction failed: ' . $exception->getMessage());
    }
}
$all_activity_count = ActivityLog::countLogs();

// Get distinct actions and target types for filter dropdowns
$actions_result = $db->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");
$available_actions = $actions_result->fetchAll(PDO::FETCH_COLUMN);

$targets_result = $db->query("SELECT DISTINCT target_type FROM activity_logs WHERE target_type IS NOT NULL ORDER BY target_type");
$available_targets = $targets_result->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-history me-2 text-primary"></i>سجل النشاطات</h1>
    <div class="admin-top-actions no-print">
        <?php if (!empty(array_filter($filters))): ?>
            <a href="activity_logs.php?log_tab=<?php echo htmlspecialchars($activeLogTab, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-header-premium btn-import-soft">
                <i class="fas fa-rotate-left me-1"></i>إعادة تعيين الفلاتر
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (is_array($activityLogFlash) && !empty($activityLogFlash['message'])): ?>
    <div class="alert alert-<?php echo htmlspecialchars((string) ($activityLogFlash['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show mb-4" role="alert">
        <i class="fas fa-<?php echo ($activityLogFlash['type'] ?? '') === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
        <?php echo htmlspecialchars((string) $activityLogFlash['message'], ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<?php if (!$canManageSystemUndo): ?>
    <div class="alert alert-info mb-4" role="status">
        <i class="fas fa-shield-alt me-2"></i>
        تظهر حالة التراجع لكل السجلات، بينما تنفيذ التراجع وإعادة العمل الشاملين متاح للمدير العام فقط.
    </div>
<?php endif; ?>

<!-- Quick Stats -->
<div class="dashboard-canvas">
    <?php
    $today_count = $db->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $week_count = $db->query("SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
    $delete_count = $db->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'delete' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    ?>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #6366f1, #4f46e5);">
                <div class="stat-card-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$today_count; ?>">0</div>
                    <div class="stat-card-label">نشاطات اليوم</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$week_count; ?>">0</div>
                    <div class="stat-card-label">آخر 7 أيام</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                <div class="stat-card-icon"><i class="fas fa-database"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$all_activity_count; ?>">0</div>
                    <div class="stat-card-label">إجمالي السجلات</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                <div class="stat-card-icon"><i class="fas fa-trash-alt"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$delete_count; ?>">0</div>
                    <div class="stat-card-label">عمليات حذف (30 يوم)</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$activeTabQuery = $filters;
$undoneTabQuery = $filters;
$activeTabQuery['log_tab'] = 'active';
$undoneTabQuery['log_tab'] = 'undone';
?>
<ul class="nav nav-tabs mb-3" aria-label="أقسام سجل النظام الموحّد">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeLogTab === 'active' ? 'active' : ''; ?>"
            href="?<?php echo htmlspecialchars(http_build_query($activeTabQuery), ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fas fa-list-check me-1"></i>سجل العمليات
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeLogTab === 'undone' ? 'active' : ''; ?>"
            href="?<?php echo htmlspecialchars(http_build_query($undoneTabQuery), ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fas fa-clock-rotate-left me-1"></i>العمليات المتراجع عنها
            <span class="badge bg-secondary ms-1"><?php echo $undone_total; ?></span>
        </a>
    </li>
</ul>

<!-- Filters -->
<form method="GET" class="admin-filter-bar mb-4">
    <input type="hidden" name="log_tab" value="<?php echo htmlspecialchars($activeLogTab, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="admin-filter-controls">
        <select name="action" class="form-select form-select-sm admin-inline-select-sm" aria-label="نوع العملية">
            <option value="">كل العمليات</option>
            <?php foreach ($available_actions as $act): ?>
                <option value="<?php echo htmlspecialchars((string) $act, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($filters['action'] ?? '') === $act ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(ActivityLog::getActionLabel($act), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="target_type" class="form-select form-select-sm admin-inline-select-sm" aria-label="نوع الهدف">
            <option value="">كل الأهداف</option>
            <?php foreach ($available_targets as $tgt): ?>
                <option value="<?php echo htmlspecialchars((string) $tgt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($filters['target_type'] ?? '') === $tgt ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(ActivityLog::getTargetLabel($tgt), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date" placeholder="من تاريخ" value="<?php echo htmlspecialchars((string) ($filters['date_from'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" aria-label="من تاريخ">
        <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date" placeholder="إلى تاريخ" value="<?php echo htmlspecialchars((string) ($filters['date_to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" aria-label="إلى تاريخ">

        <input type="text" name="search" class="form-control form-control-sm" placeholder="اسم مستخدم أو هدف..." value="<?php echo htmlspecialchars((string) ($filters['search'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" aria-label="بحث">
    </div>
    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>فلترة</button>
        <?php if (!empty(array_filter($filters))): ?>
            <a href="activity_logs.php?log_tab=<?php echo htmlspecialchars($activeLogTab, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
        <?php endif; ?>
    </div>
</form>

<!-- Logs Table -->
<div class="system-activity-log-undo-guide mb-3" role="note">
    <?php if ($activeLogTab === 'undone'): ?>
        <span class="system-activity-log-undo-guide-title"><i class="fas fa-circle-info"></i>دليل إعادة العمل</span>
        <span class="badge bg-primary"><i class="fas fa-rotate-right me-1"></i>متاح: يعيد تنفيذ العملية بعد فحص التعارض</span>
        <span class="badge bg-light text-dark border"><i class="fas fa-shield-alt me-1"></i>أي تعارض يوقف الدفعة كاملة دون تغيير جزئي</span>
    <?php else: ?>
        <span class="system-activity-log-undo-guide-title"><i class="fas fa-circle-info"></i>دليل التراجع</span>
        <span class="badge bg-success"><i class="fas fa-rotate-left me-1"></i>متاح: يمكن استعادة العملية بأمان</span>
        <span class="badge bg-light text-dark border"><i class="fas fa-lock me-1"></i>غير متاح: لا توجد لقطة آمنة أو تمنعها السياسة</span>
    <?php endif; ?>
</div>
<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table system-activity-log-table">
            <thead>
                <tr>
                    <th style="width: 150px;">التاريخ والوقت</th>
                    <th>المستخدم</th>
                    <th style="width: 110px;">العملية</th>
                    <th>الهدف</th>
                    <th>التفاصيل</th>
                    <th style="width: 110px;">IP</th>
                    <th style="width: 150px;" class="system-activity-log-undo-state-col"><?php echo $activeLogTab === 'undone' ? 'بيانات التراجع' : 'حالة التراجع'; ?></th>
                    <th style="width: 110px;" class="text-center system-activity-log-undo-action-col"><i class="fas <?php echo $activeLogTab === 'undone' ? 'fa-rotate-right' : 'fa-rotate-left'; ?> me-1"></i><?php echo $activeLogTab === 'undone' ? 'إعادة العمل' : 'التراجع'; ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                            <?php echo $activeLogTab === 'undone' ? 'لا توجد عمليات متراجع عنها مطابقة' : 'لا توجد سجلات مطابقة'; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $undoState = SystemActivityLogQuery::undoState($log);
                        $undoReason = $activeLogTab === 'undone'
                            ? SystemActivityLogQuery::redoReason($log, $canManageSystemUndo)
                            : SystemActivityLogQuery::undoReason($log, $canManageSystemUndo);
                        $canUndoRow = $undoState === 'available' && $canManageSystemUndo;
                        $canRedoRow = $activeLogTab === 'undone'
                            && $canManageSystemUndo
                            && $undoState === 'completed'
                            && (int) ($log['can_undo'] ?? 0) === 1
                            && (int) ($log['is_undone'] ?? 0) === 1
                            && ($log['undo_status'] ?? '') === 'completed';
                        $details = !empty($log['details']) ? json_decode((string) $log['details'], true) : null;
                        $presentation = ActivityLog::getOperationPresentation($log);
                        ?>
                        <tr>
                            <td class="small text-nowrap">
                                <i class="fas fa-clock text-muted me-1"></i>
                                <?php echo date('Y/m/d H:i', strtotime($log['created_at'])); ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($log['user_name']); ?></strong>
                                <br><small class="text-muted"><?php echo ActivityLog::getTargetLabel($log['user_role']); ?></small>
                            </td>
                            <td>
                                <span class="badge <?php echo ActivityLog::getActionBadgeClass($log['action']); ?>">
                                    <i class="fas <?php echo ActivityLog::getActionIcon($log['action']); ?> me-1"></i>
                                    <?php echo ActivityLog::getActionLabel($log['action']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($log['target_type']): ?>
                                    <span class="text-muted small"><?php echo ActivityLog::getTargetLabel($log['target_type']); ?>:</span>
                                    <br>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($log['target_name'] ?? '-'); ?>
                            </td>
                            <td class="small align-middle">
                                <div class="fw-semibold text-body"><?php echo htmlspecialchars($presentation['summary'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-muted mt-1"><?php echo htmlspecialchars($presentation['context'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if ($presentation['technical_reference'] !== '' || (is_array($details) && $details !== [])): ?>
                                    <details class="mt-2">
                                        <summary class="text-primary fw-semibold">عرض التفاصيل الفنية</summary>
                                        <?php if ($presentation['technical_reference'] !== ''): ?>
                                            <div class="text-muted mt-2"><?php echo htmlspecialchars($presentation['technical_reference'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                        <?php if (is_array($details) && $details !== []): ?>
                                            <div class="mt-2"><?php echo ActivityLog::formatDetailsHtml($details, 'diff_table'); ?></div>
                                        <?php endif; ?>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                            <td class="system-activity-log-undo-state-col">
                                <?php if ($undoState === 'available'): ?>
                                    <span class="badge bg-success"><i class="fas fa-rotate-left me-1"></i>متاح</span>
                                <?php elseif ($undoState === 'completed'): ?>
                                    <span class="badge bg-primary"><i class="fas fa-check me-1"></i>تم التراجع</span>
                                    <?php if (!empty($log['undone_at'])): ?>
                                        <div class="small text-muted mt-1"><?php echo htmlspecialchars(date('Y/m/d H:i', strtotime((string) $log['undone_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border"><i class="fas fa-lock me-1"></i>غير متاح</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center actions-column admin-table-actions system-activity-log-undo-action-col">
                                <?php if ($activeLogTab === 'active' && $canUndoRow): ?>
                                    <button type="button"
                                            class="btn btn-action-pills btn-edit js-system-undo"
                                            data-activity-id="<?php echo (int) $log['id']; ?>"
                                            data-undo-id="<?php echo (int) $log['undo_id']; ?>"
                                            data-operation-name="<?php echo htmlspecialchars((string) ($log['target_name'] ?? ActivityLog::getActionLabel($log['action'])), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-bs-toggle="tooltip"
                                            title="<?php echo htmlspecialchars($undoReason, ENT_QUOTES, 'UTF-8'); ?>"
                                            aria-label="التراجع عن العملية">
                                        <i class="fas fa-rotate-left"></i>
                                    </button>
                                <?php elseif ($canRedoRow): ?>
                                    <button type="button"
                                        class="btn btn-action-pills btn-activate js-system-redo"
                                        data-activity-id="<?php echo (int) $log['id']; ?>"
                                        data-undo-id="<?php echo (int) $log['undo_id']; ?>"
                                        data-operation-name="<?php echo htmlspecialchars((string) ($log['target_name'] ?? ActivityLog::getActionLabel($log['action'])), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-bs-toggle="tooltip"
                                        title="<?php echo htmlspecialchars($undoReason, ENT_QUOTES, 'UTF-8'); ?>"
                                        aria-label="إعادة تنفيذ العملية">
                                        <i class="fas fa-rotate-right"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button"
                                            class="btn btn-action-pills btn-deactivate"
                                            disabled
                                            data-bs-toggle="tooltip"
                                            title="<?php echo htmlspecialchars($undoReason, ENT_QUOTES, 'UTF-8'); ?>"
                                            aria-label="<?php echo $activeLogTab === 'undone' ? 'إعادة العمل غير متاحة' : 'التراجع غير متاح'; ?>">
                                        <i class="fas <?php echo $activeLogTab === 'undone' ? 'fa-rotate-right' : 'fa-rotate-left'; ?>"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
        <?php $paginationFilters = array_merge($filters, ['log_tab' => $activeLogTab]); ?>
        <div class="d-flex justify-content-center pt-3 border-top">
            <nav aria-label="تصفح الصفحات">
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($paginationFilters, ['page' => $page - 1])); ?>" aria-label="السابق">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($paginationFilters, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($paginationFilters, ['page' => $page + 1])); ?>" aria-label="التالي">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="systemUndoModal" tabindex="-1" aria-labelledby="systemUndoModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <form method="post" action="activity_logs.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['page' => $page, 'log_tab' => $activeLogTab])), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="undo_system_activity">
                <input type="hidden" name="activity_id" id="systemUndoActivityId" value="">
                <input type="hidden" name="undo_id" id="systemUndoId" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="systemUndoModalLabel"><i class="fas fa-rotate-left me-2"></i>تأكيد التراجع</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-clock-rotate-left text-warning admin-modal-icon-lg mb-2"></i>
                    </div>
                    <p class="text-center mb-3">هل تريد التراجع عن العملية المرتبطة بـ <span class="fw-bold text-primary" id="systemUndoOperationName">هذا السجل</span>؟</p>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-shield-alt me-2"></i>
                        سيتحقق النظام من الحالة الحالية أولًا، وإذا كانت العملية ضمن دفعة فسيتم التراجع عن الدفعة كاملة بصورة ذرّية. أي تعارض يوقف التراجع دون تعديل جزئي.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-rotate-left me-1"></i>تأكيد التراجع
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="systemRedoModal" tabindex="-1" aria-labelledby="systemRedoModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <form method="post" action="activity_logs.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['page' => $page, 'log_tab' => $activeLogTab])), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="redo_system_activity">
                <input type="hidden" name="activity_id" id="systemRedoActivityId" value="">
                <input type="hidden" name="undo_id" id="systemRedoId" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="systemRedoModalLabel"><i class="fas fa-rotate-right me-2"></i>تأكيد إعادة العمل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-rotate-right text-primary admin-modal-icon-lg mb-2"></i>
                    </div>
                    <p class="text-center mb-3">هل تريد إعادة تنفيذ العملية المرتبطة بـ <span class="fw-bold text-primary" id="systemRedoOperationName">هذا السجل</span>؟</p>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-shield-alt me-2"></i>
                        سيتحقق النظام من مطابقة البيانات لحالة ما بعد التراجع. إذا كانت العملية ضمن دفعة فستُعاد الدفعة كاملة، وأي تعارض يوقفها دون تعديل جزئي.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-rotate-right me-1"></i>تأكيد إعادة العمل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/js/activity-logs-undo.js"></script>
<?php require_once '../includes/admin_footer.php'; ?>
