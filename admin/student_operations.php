<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

require_once '../includes/csrf.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/UndoManager.php';
require_once '../classes/StudentOperationLogQuery.php';
require_once '../classes/AcademicYear.php';

$database = new Database();
$db = $database->getConnection();
ActivityLog::setDb($db);
UndoManager::setDb($db);
$currentAcademicYear = AcademicYear::getCurrent($db);
$currentAcademicYearId = (int) ($currentAcademicYear['id'] ?? 0);
$operationQuery = new StudentOperationLogQuery($db, $currentAcademicYearId);

function student_operations_redirect_query(array $source): string
{
    $allowed = [
        'log_action',
        'log_type',
        'undo_state',
        'log_from',
        'log_to',
        'log_search',
        'log_page',
        'log_tab',
    ];
    $query = [];
    foreach ($allowed as $key) {
        $value = trim((string) ($source[$key] ?? ''));
        if ($value !== '') {
            $query[$key] = mb_substr($value, 0, 120);
        }
    }
    return $query ? '?' . http_build_query($query) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();

    $requestedAction = (string) ($_POST['action'] ?? '');
    if (in_array($requestedAction, ['undo_student_operation', 'redo_student_operation'], true)) {
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        $undoId = (int) ($_POST['undo_id'] ?? 0);
        $isRedo = $requestedAction === 'redo_student_operation';
        $operation = $isRedo
            ? $operationQuery->findRedoableOperation($activityId, $undoId)
            : $operationQuery->findUndoableOperation($activityId, $undoId);

        if (!$operation) {
            $_SESSION['student_operations_error'] = $isRedo
                ? 'هذه العملية لم تعد قابلة لإعادة التنفيذ الآمن أو لا تخص سجل شؤون الطلاب.'
                : 'هذه العملية لم تعد قابلة للتراجع الآمن أو لا تخص سجل شؤون الطلاب.';
        } else {
            $result = $isRedo
                ? UndoManager::redo((int) ($_SESSION['user_id'] ?? 0), $undoId, true)
                : UndoManager::undo((int) ($_SESSION['user_id'] ?? 0), $undoId, true, null);
            if (!empty($result['success'])) {
                $_SESSION['student_operations_success'] = (string) ($result['message'] ?? ($isRedo
                    ? 'تمت إعادة تنفيذ العملية بنجاح.'
                    : 'تم التراجع عن العملية بنجاح.'));
            } else {
                $_SESSION['student_operations_error'] = (string) ($result['message'] ?? ($isRedo
                    ? 'تعذرت إعادة تنفيذ العملية.'
                    : 'تعذر التراجع عن العملية.'));
            }
        }

        header('Location: student_operations.php' . student_operations_redirect_query($_POST));
        exit;
    }

    $_SESSION['student_operations_error'] = 'الإجراء المطلوب غير معروف.';
    header('Location: student_operations.php');
    exit;
}

$successMessage = $_SESSION['student_operations_success'] ?? null;
$errorMessage = $_SESSION['student_operations_error'] ?? null;
unset($_SESSION['student_operations_success'], $_SESSION['student_operations_error']);

$operationData = [
    'rows' => [],
    'total' => 0,
    'page' => 1,
    'pages' => 1,
    'filters' => [
        'action' => '',
        'target_type' => '',
        'date_from' => '',
        'date_to' => '',
        'search' => '',
        'undo_state' => '',
        'tab' => 'active',
    ],
    'action_options' => [],
    'type_options' => [],
    'stats' => ['total' => 0, 'available' => 0, 'completed' => 0, 'unavailable' => 0],
];

try {
    $operationData = $operationQuery->load($_GET);
} catch (Throwable $error) {
    error_log('Student operation log query failed: ' . $error->getMessage());
    $errorMessage = $errorMessage ?: 'تعذر تحميل سجل عمليات الطلاب حاليًا.';
}

$rows = $operationData['rows'];
$filters = $operationData['filters'];
$stats = $operationData['stats'];
$activeTab = ($filters['tab'] ?? 'active') === 'undone' ? 'undone' : 'active';
$page_title = 'سجل عمليات الطلاب';
$custom_page_title = true;
$adminAssetOptions = [
    'datatables' => false,
    'sweetalert' => false,
    'sortable' => false,
    'dashboard_sortable' => false,
];
require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2 d-flex align-items-center">
        <i class="fas fa-clock-rotate-left me-2 text-primary"></i>
        <span>سجل عمليات الطلاب</span>
        <i class="fas fa-info-circle ms-2 text-muted fs-6" 
           data-bs-toggle="tooltip" 
           data-bs-placement="top" 
           title="يعرض هذا السجل عمليات شؤون الطلاب الفعلية. محاولات الحفظ التي لم تغيّر أي بيانات تبقى محفوظة في سجل النظام العام للمراجعة التقنية." 
           style="cursor: pointer;"
           aria-label="معلومات عن سجل عمليات الطلاب"></i>
    </h1>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $successMessage, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-circle-exclamation me-2"></i><?php echo htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<?php
$activeTabQuery = $_GET;
unset($activeTabQuery['log_page'], $activeTabQuery['undo_state']);
$undoneTabQuery = $activeTabQuery;
$activeTabQuery['log_tab'] = 'active';
$undoneTabQuery['log_tab'] = 'undone';
?>
<ul class="nav nav-tabs mb-3" aria-label="أقسام سجل عمليات الطلاب">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'active' ? 'active' : ''; ?>"
            href="?<?php echo htmlspecialchars(http_build_query($activeTabQuery), ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fas fa-list-check me-1"></i>العمليات الحالية
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'undone' ? 'active' : ''; ?>"
            href="?<?php echo htmlspecialchars(http_build_query($undoneTabQuery), ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fas fa-clock-rotate-left me-1"></i>العمليات المتراجع عنها
            <span class="badge bg-secondary ms-1"><?php echo (int) ($stats['completed'] ?? 0); ?></span>
        </a>
    </li>
</ul>

<form method="GET" action="student_operations.php" class="admin-filter-bar" data-no-form-safety="true">
    <input type="hidden" name="log_tab" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="admin-filter-controls">
        <select name="log_action" class="form-select form-select-sm admin-inline-select-sm" aria-label="نوع العملية">
            <option value="">نوع العملية: الكل</option>
            <?php foreach ($operationData['action_options'] as $action => $unused): ?>
                <option value="<?php echo htmlspecialchars((string) $action, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['action'] === $action ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(ActivityLog::getActionLabel((string) $action), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="log_type" class="form-select form-select-sm admin-inline-select-sm" aria-label="قسم العملية">
            <option value="">قسم العملية: الكل</option>
            <?php foreach ($operationData['type_options'] as $targetType => $targetLabel): ?>
                <option value="<?php echo htmlspecialchars((string) $targetType, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['target_type'] === $targetType ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) $targetLabel, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($activeTab === 'active'): ?>
            <select name="undo_state" class="form-select form-select-sm admin-inline-select-sm" aria-label="حالة التراجع">
                <option value="">حالة التراجع: الكل</option>
                <option value="available" <?php echo $filters['undo_state'] === 'available' ? 'selected' : ''; ?>>متاح للتراجع</option>
                <option value="unavailable" <?php echo $filters['undo_state'] === 'unavailable' ? 'selected' : ''; ?>>غير قابل للتراجع</option>
            </select>
        <?php endif; ?>

        <div class="d-flex align-items-center gap-1">
            <span class="small text-secondary fw-bold">من:</span>
            <input type="text" name="log_from" class="form-control form-control-sm admin-inline-date-sm flatpickr-date"
                value="<?php echo htmlspecialchars((string) $filters['date_from'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="d-flex align-items-center gap-1">
            <span class="small text-secondary fw-bold">إلى:</span>
            <input type="text" name="log_to" class="form-control form-control-sm admin-inline-date-sm flatpickr-date"
                value="<?php echo htmlspecialchars((string) $filters['date_to'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <input type="search" name="log_search" class="form-control form-control-sm admin-inline-search-sm"
            placeholder="اسم الطالب أو المستخدم أو التفاصيل"
            value="<?php echo htmlspecialchars((string) $filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="student_operations.php?log_tab=<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
    </div>
</form>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped admin-data-table mb-0">
            <thead>
                <tr>
                    <th class="text-nowrap">التاريخ والوقت</th>
                    <th>المستخدم</th>
                    <th>نوع الإجراء</th>
                    <th>مجال البيانات</th>
                    <th>البيان المتأثر</th>
                    <th>ما الذي حدث؟</th>
                    <th><?php echo $activeTab === 'undone' ? 'بيانات التراجع' : 'حالة التراجع'; ?></th>
                    <th><?php echo $activeTab === 'undone' ? 'إعادة العمل' : 'الإجراء'; ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fa-3x d-block mb-3"></i><?php echo $activeTab === 'undone' ? 'لا توجد عمليات متراجع عنها مطابقة للفلاتر الحالية.' : 'لا توجد عمليات مطابقة للفلاتر الحالية.'; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $undoState = StudentOperationLogQuery::undoState($row);
                        $undoReason = $activeTab === 'undone'
                            ? StudentOperationLogQuery::redoReason($row)
                            : StudentOperationLogQuery::undoReason($row);
                        $canRedoRow = $activeTab === 'undone'
                            && $undoState === 'completed'
                            && (int) ($row['can_undo'] ?? 0) === 1
                            && (int) ($row['is_undone'] ?? 0) === 1
                            && ($row['undo_status'] ?? '') === 'completed';
                        $details = !empty($row['details']) ? json_decode((string) $row['details'], true) : null;
                        $presentation = StudentOperationLogQuery::operationPresentation($row);
                        ?>
                        <tr>
                            <td class="small text-nowrap align-middle">
                                <div class="fw-semibold"><?php echo htmlspecialchars(date('Y/m/d', strtotime((string) $row['created_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-muted"><?php echo htmlspecialchars(date('H:i:s', strtotime((string) $row['created_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td class="align-middle">
                                <div class="fw-bold"><?php echo htmlspecialchars((string) ($row['user_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars(Utilities::translateRole((string) ($row['user_role'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td class="align-middle">
                                <span class="badge <?php echo htmlspecialchars(ActivityLog::getActionBadgeClass((string) $row['action']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas <?php echo htmlspecialchars(ActivityLog::getActionIcon((string) $row['action']), ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
                                    <?php echo htmlspecialchars(ActivityLog::getActionLabel((string) $row['action']), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="align-middle">
                                <?php echo htmlspecialchars(StudentOperationLogQuery::targetLabel((string) ($row['target_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="align-middle">
                                <div class="fw-semibold"><?php echo htmlspecialchars($presentation['subject'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars(StudentOperationLogQuery::targetLabel((string) ($row['target_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td class="small align-middle">
                                <div class="fw-semibold text-body"><?php echo htmlspecialchars($presentation['summary'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-muted mt-1"><?php echo htmlspecialchars($presentation['context'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if ($presentation['technical_reference'] !== '' || is_array($details)): ?>
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
                            <td class="align-middle text-nowrap">
                                <?php if ($undoState === 'available'): ?>
                                    <span class="badge bg-success"><i class="fas fa-rotate-left me-1"></i>متاح</span>
                                <?php elseif ($undoState === 'completed'): ?>
                                    <span class="badge bg-primary"><i class="fas fa-check-double me-1"></i>تم التراجع</span>
                                    <?php if (!empty($row['undone_at'])): ?>
                                        <div class="small text-muted mt-1"><?php echo htmlspecialchars(date('Y/m/d H:i', strtotime((string) $row['undone_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i class="fas fa-lock me-1"></i>غير متاح</span>
                                <?php endif; ?>
                                <div class="small text-muted mt-1"><?php echo htmlspecialchars($undoReason, ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td class="align-middle admin-table-actions">
                                <?php if ($activeTab === 'active' && $undoState === 'available'): ?>
                                    <button type="button" class="btn btn-action-pills btn-edit student-operation-undo"
                                        data-activity-id="<?php echo (int) $row['id']; ?>"
                                        data-undo-id="<?php echo (int) $row['undo_id']; ?>"
                                        data-target-name="<?php echo htmlspecialchars($presentation['subject'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-bs-toggle="tooltip" title="التراجع عن العملية">
                                        <i class="fas fa-rotate-left"></i>
                                    </button>
                                <?php elseif ($canRedoRow): ?>
                                    <button type="button" class="btn btn-action-pills btn-activate student-operation-redo"
                                        data-activity-id="<?php echo (int) $row['id']; ?>"
                                        data-undo-id="<?php echo (int) $row['undo_id']; ?>"
                                        data-target-name="<?php echo htmlspecialchars($presentation['subject'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-bs-toggle="tooltip" title="إعادة تنفيذ العملية">
                                        <i class="fas fa-rotate-right"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-action-pills btn-deactivate" disabled aria-disabled="true"
                                        data-bs-toggle="tooltip" title="<?php echo htmlspecialchars($undoReason, ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas <?php echo $activeTab === 'undone' ? 'fa-rotate-right' : 'fa-rotate-left'; ?>"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ((int) $operationData['pages'] > 1): ?>
        <nav class="p-3 border-top" aria-label="صفحات سجل عمليات الطلاب">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php
                $pageNumber = (int) $operationData['page'];
                $pageCount = (int) $operationData['pages'];
                $pageStart = max(1, $pageNumber - 2);
                $pageEnd = min($pageCount, $pageNumber + 2);
                $baseQuery = $_GET;
                unset($baseQuery['log_page']);
                ?>
                <?php if ($pageNumber > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($baseQuery, ['log_page' => $pageNumber - 1])), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-chevron-right"></i></a></li>
                <?php endif; ?>
                <?php for ($index = $pageStart; $index <= $pageEnd; $index++): ?>
                    <li class="page-item <?php echo $index === $pageNumber ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($baseQuery, ['log_page' => $index])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $index; ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($pageNumber < $pageCount): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($baseQuery, ['log_page' => $pageNumber + 1])), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-chevron-left"></i></a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<div class="modal fade" id="undoStudentOperationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <form method="POST" action="student_operations.php" data-no-form-safety="true">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="undo_student_operation">
                <input type="hidden" name="activity_id" id="undoStudentActivityId">
                <input type="hidden" name="undo_id" id="undoStudentOperationId">
                <?php foreach (['log_action', 'log_type', 'undo_state', 'log_from', 'log_to', 'log_search', 'log_page', 'log_tab'] as $filterKey): ?>
                    <?php if (isset($_GET[$filterKey]) && is_scalar($_GET[$filterKey])): ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($filterKey, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) $_GET[$filterKey], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-rotate-left me-2"></i>تأكيد التراجع</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-clock-rotate-left text-warning" style="font-size: 3rem;"></i></div>
                    <p class="text-center">هل تريد التراجع عن العملية الخاصة بـ <span class="fw-bold text-primary" id="undoStudentOperationTarget"></span>؟</p>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-triangle-exclamation me-2"></i>
                        سيُنفذ التراجع كدفعة واحدة، وسيتوقف بالكامل إذا اكتشف النظام أي تعديل لاحق أو تعارض في البيانات.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-rotate-left me-1"></i>تأكيد التراجع</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="redoStudentOperationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <form method="POST" action="student_operations.php" data-no-form-safety="true">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="redo_student_operation">
                <input type="hidden" name="activity_id" id="redoStudentActivityId">
                <input type="hidden" name="undo_id" id="redoStudentOperationId">
                <?php foreach (['log_action', 'log_type', 'log_from', 'log_to', 'log_search', 'log_page', 'log_tab'] as $filterKey): ?>
                    <?php if (isset($_GET[$filterKey]) && is_scalar($_GET[$filterKey])): ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($filterKey, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) $_GET[$filterKey], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-rotate-right me-2"></i>تأكيد إعادة العمل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-rotate-right text-primary" style="font-size: 3rem;"></i></div>
                    <p class="text-center">هل تريد إعادة تنفيذ العملية الخاصة بـ <span class="fw-bold text-primary" id="redoStudentOperationTarget"></span>؟</p>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-shield-alt me-2"></i>
                        سيتحقق النظام من أن البيانات ما زالت مطابقة لحالة ما بعد التراجع. عند وجود أي تعديل لاحق ستتوقف الدفعة كاملة دون تغيير جزئي.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-rotate-right me-1"></i>تأكيد إعادة العمل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset_url('../assets/js/student-operations.js'); ?>"></script>

<?php require_once '../includes/admin_footer.php'; ?>
