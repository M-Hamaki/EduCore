<?php

declare(strict_types=1);

/**
 * إدارة مسارات اعتماد شؤون العاملين والتفويضات.
 * يبقى هذا المدخل HTTP فقط؛ الحفظ والقراءة يمران عبر Staff application services.
 */

$page_title = 'مسارات الاعتماد والتفويض';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;
use EduCore\Modules\Staff\Presentation\ApprovalAdministrationErrorPresenter;

$activeTab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'workflows');
if (!in_array($activeTab, ['workflows', 'delegations'], true)) {
    $activeTab = 'workflows';
}

$database = new Database();
$db = $database->getConnection();
$staffFactory = new StaffModuleFactory($db, new AuditService($db));
$approvalAdministration = $staffFactory->approvalAdministration();
$actorId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        $_SESSION['error_message'] = 'انتهت صلاحية نموذج الحفظ. أعد فتح الصفحة ثم حاول مرة أخرى.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'create_workflow_version') {
                $result = $approvalAdministration->createWorkflowVersion($_POST, $actorId);
                $_SESSION['success_message'] = $result['published']
                    ? 'تم نشر نسخة مسار الاعتماد بنجاح.'
                    : 'تم حفظ نسخة المسار كمسودة للمراجعة.';
            } elseif ($action === 'publish_workflow_version') {
                $approvalAdministration->publishVersion((int) ($_POST['workflow_version_id'] ?? 0), $actorId);
                $_SESSION['success_message'] = 'تم نشر نسخة مسار الاعتماد بنجاح.';
            } elseif ($action === 'change_workflow_status') {
                $approvalAdministration->changeWorkflowStatus(
                    (int) ($_POST['workflow_id'] ?? 0),
                    (string) ($_POST['workflow_status'] ?? ''),
                    $actorId
                );
                $_SESSION['success_message'] = 'تم تحديث حالة مسار الاعتماد.';
            } elseif ($action === 'create_delegation') {
                $approvalAdministration->createDelegation($_POST, $actorId);
                $_SESSION['success_message'] = 'تم حفظ التفويض بنجاح.';
            } elseif ($action === 'end_delegation') {
                $approvalAdministration->endDelegation(
                    (int) ($_POST['delegation_id'] ?? 0),
                    (string) ($_POST['delegation_status'] ?? ''),
                    $actorId
                );
                $_SESSION['success_message'] = 'تم تحديث حالة التفويض.';
            } elseif ($action === 'activate_delegation') {
                $approvalAdministration->activateDelegation((int) ($_POST['delegation_id'] ?? 0), $actorId);
                $_SESSION['success_message'] = 'تم تفعيل التفويض بعد التحقق من صلاحيته.';
            } else {
                $_SESSION['error_message'] = 'العملية المطلوبة غير صالحة.';
            }
        } catch (Throwable $exception) {
            $_SESSION['error_message'] = ApprovalAdministrationErrorPresenter::message($exception);
        }
    }

    header('Location: hr_approval_workflows.php?tab=' . rawurlencode($activeTab));
    exit;
}

$successMessage = $_SESSION['success_message'] ?? null;
$errorMessage = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$workflowVersions = [];
$delegations = [];
$activeUsers = [];
$activeRoleKeys = [];
$loadError = null;
try {
    $workflowVersions = $approvalAdministration->workflowVersions();
    $delegations = $approvalAdministration->delegations();
    $activeUsers = $approvalAdministration->activeUsers();
    $activeRoleKeys = $approvalAdministration->activeRoleKeys();
} catch (Throwable) {
    $loadError = 'لا يمكن فتح إعدادات الاعتماد الآن. تحقق من تطبيق ترحيلات شؤون العاملين ثم أعد المحاولة.';
}

$workflowOptions = [];
$activeWorkflowIds = [];
$draftVersionCount = 0;
foreach ($workflowVersions as $workflowVersion) {
    $workflowId = (int) ($workflowVersion['workflow_id'] ?? 0);
    if ($workflowId > 0 && !isset($workflowOptions[$workflowId])) {
        $workflowOptions[$workflowId] = [
            'id' => $workflowId,
            'code' => (string) ($workflowVersion['workflow_code'] ?? ''),
            'name' => (string) ($workflowVersion['workflow_name'] ?? ''),
            'resource_type' => (string) ($workflowVersion['resource_type'] ?? ''),
            'status' => (string) ($workflowVersion['workflow_status'] ?? ''),
        ];
    }
    if ((string) ($workflowVersion['workflow_status'] ?? '') === 'active') {
        $activeWorkflowIds[$workflowId] = true;
    }
    if ((string) ($workflowVersion['version_state'] ?? '') === 'draft') {
        ++$draftVersionCount;
    }
}
$activeDelegationCount = count(array_filter(
    $delegations,
    static fn(array $delegation): bool => (string) ($delegation['status'] ?? '') === 'active'
));

$resourceLabels = [
    'permission_request' => 'إذن',
    'leave_request' => 'إجازة',
    'discipline_case' => 'حالة تأديبية',
    'attendance_adjustment' => 'تصحيح حضور',
    'schedule_change' => 'تغيير دوام',
    'ertaq_ticket' => 'تذكرة ارتق',
];
$workflowStatusLabels = ['active' => 'نشط', 'inactive' => 'موقوف', 'retired' => 'متقاعد'];
$workflowStatusColors = ['active' => 'success', 'inactive' => 'secondary', 'retired' => 'dark'];
$versionStateLabels = ['draft' => 'مسودة', 'published' => 'منشورة', 'retired' => 'متقاعدة'];
$versionStateColors = ['draft' => 'warning', 'published' => 'success', 'retired' => 'secondary'];
$delegationStatusLabels = ['draft' => 'مسودة', 'active' => 'نشط', 'suspended' => 'موقوف', 'revoked' => 'ملغى', 'expired' => 'منتهٍ'];
$delegationStatusColors = ['draft' => 'warning', 'active' => 'success', 'suspended' => 'secondary', 'revoked' => 'danger', 'expired' => 'dark'];
$scopeLabels = ['global' => 'عام', 'org_unit' => 'وحدة تنظيمية', 'group' => 'مجموعة', 'staff' => 'عامل', 'request_type' => 'نوع طلب'];

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="fas fa-route me-2 text-primary"></i>مسارات الاعتماد والتفويض</h1>
        <p class="text-muted mb-0">أنشئ نسخًا مؤرخة للمسارات، واضبط تفويض المديرين دون تعديل تاريخ الطلبات القائمة.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="hr_center.php" class="btn btn-outline-primary shadow-sm px-3 py-2">
            <i class="fas fa-layer-group me-2"></i>مركز شؤون العاملين
        </a>
        <button type="button" class="btn btn-success shadow px-3 py-2" data-bs-toggle="modal" data-bs-target="#workflowModal">
            <i class="fas fa-plus-circle me-2"></i>مسار أو نسخة جديدة
        </button>
        <button type="button" class="btn btn-primary shadow px-3 py-2" data-bs-toggle="modal" data-bs-target="#delegationModal">
            <i class="fas fa-user-shield me-2"></i>تفويض جديد
        </button>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $successMessage, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>
<?php if ($loadError): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-database me-2"></i><?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="row row-cols-2 row-cols-md-3 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-route"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo count($activeWorkflowIds); ?>">0</div>
                <div class="stat-card-label">مسارات نشطة</div>
                <div class="stat-card-sub"><i class="fas fa-circle-check"></i>صالحة للاستخدام عند الإرسال</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-file-pen"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $draftVersionCount; ?>">0</div>
                <div class="stat-card-label">نسخ قيد المراجعة</div>
                <div class="stat-card-sub"><i class="fas fa-clock"></i>لا تستخدم في الطلبات قبل النشر</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-user-shield"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $activeDelegationCount; ?>">0</div>
                <div class="stat-card-label">تفويضات نشطة</div>
                <div class="stat-card-sub"><i class="fas fa-shield-halved"></i>تخضع للتحقق عند القرار</div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-pills mb-3 gap-2" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link <?php echo $activeTab === 'workflows' ? 'active' : ''; ?>" href="hr_approval_workflows.php?tab=workflows">
            <i class="fas fa-diagram-project me-1"></i>مسارات الاعتماد
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?php echo $activeTab === 'delegations' ? 'active' : ''; ?>" href="hr_approval_workflows.php?tab=delegations">
            <i class="fas fa-people-arrows-left-right me-1"></i>تفويضات المديرين
        </a>
    </li>
</ul>

<?php if ($activeTab === 'workflows'): ?>
    <div class="admin-filter-bar">
        <div class="admin-filter-controls">
            <div>
                <div class="fw-semibold"><i class="fas fa-circle-info text-primary me-2"></i>قاعدة النسخ المؤرخة</div>
                <div class="small text-muted">لا تعدّل النسخة المنشورة؛ أنشئ نسخة جديدة وحدد وقت سريانها. إغلاق النسخة المفتوحة السابقة يتم داخل معاملة واحدة عند النشر.</div>
            </div>
        </div>
        <div class="admin-filter-actions">
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#workflowModal">
                <i class="fas fa-plus-circle me-1"></i>إضافة نسخة
            </button>
        </div>
    </div>
    <div class="admin-list-surface">
        <div class="admin-table-wrap">
            <table class="table table-hover table-striped admin-data-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>المسار</th>
                        <th>نوع الطلب</th>
                        <th>النسخة</th>
                        <th>المراحل</th>
                        <th>سريان النسخة</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($workflowVersions === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-route fa-2x d-block mb-3 text-primary"></i>لا توجد مسارات اعتماد بعد. ابدأ بمسار جديد أو نسخة أولى.</td></tr>
                    <?php else: ?>
                        <?php foreach ($workflowVersions as $row): ?>
                            <?php
                            $workflowStatus = (string) ($row['workflow_status'] ?? 'inactive');
                            $versionState = (string) ($row['version_state'] ?? 'draft');
                            $workflowId = (int) ($row['workflow_id'] ?? 0);
                            $versionId = (int) ($row['workflow_version_id'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars((string) ($row['workflow_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="small text-muted font-monospace"><?php echo htmlspecialchars((string) ($row['workflow_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($resourceLabels[(string) ($row['resource_type'] ?? '')] ?? (string) ($row['resource_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ($versionId > 0): ?>
                                        <span class="badge bg-<?php echo htmlspecialchars($versionStateColors[$versionState] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>-subtle text-<?php echo htmlspecialchars($versionStateColors[$versionState] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">#<?php echo (int) ($row['version_no'] ?? 0); ?> · <?php echo htmlspecialchars($versionStateLabels[$versionState] ?? $versionState, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">لا توجد نسخة</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark"><?php echo (int) ($row['stage_count'] ?? 0); ?> مرحلة</span></td>
                                <td>
                                    <?php echo htmlspecialchars((string) ($row['valid_from'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                    <span class="text-muted">←</span>
                                    <?php echo htmlspecialchars((string) (($row['valid_to'] ?? null) ?: 'مفتوحة'), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td><span class="badge bg-<?php echo htmlspecialchars($workflowStatusColors[$workflowStatus] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($workflowStatusLabels[$workflowStatus] ?? $workflowStatus, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td>
                                    <?php if ($versionState === 'draft' && $versionId > 0): ?>
                                        <button type="button" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="نشر النسخة" data-workflow-action="publish" data-version-id="<?php echo $versionId; ?>" data-workflow-name="<?php echo htmlspecialchars((string) ($row['workflow_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-cloud-arrow-up"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($workflowStatus === 'active'): ?>
                                        <button type="button" class="btn btn-action-pills btn-deactivate me-1" data-bs-toggle="tooltip" title="إيقاف المسار" data-workflow-action="status" data-workflow-id="<?php echo $workflowId; ?>" data-workflow-status="inactive" data-workflow-name="<?php echo htmlspecialchars((string) ($row['workflow_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-pause"></i>
                                        </button>
                                    <?php elseif ($workflowStatus === 'inactive'): ?>
                                        <button type="button" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="تفعيل المسار" data-workflow-action="status" data-workflow-id="<?php echo $workflowId; ?>" data-workflow-status="active" data-workflow-name="<?php echo htmlspecialchars((string) ($row['workflow_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($workflowStatus !== 'retired'): ?>
                                        <button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="tooltip" title="تقاعد المسار" data-workflow-action="status" data-workflow-id="<?php echo $workflowId; ?>" data-workflow-status="retired" data-workflow-name="<?php echo htmlspecialchars((string) ($row['workflow_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-box-archive"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="admin-filter-bar">
        <div class="admin-filter-controls">
            <div>
                <div class="fw-semibold"><i class="fas fa-shield-halved text-primary me-2"></i>التفويض يظل قابلًا للتحقق</div>
                <div class="small text-muted">لا يكفي وجود التفويض في السجل: يتحقق النظام من الحساب والتفويض الدقيق عند اعتماد كل طلب.</div>
            </div>
        </div>
        <div class="admin-filter-actions">
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#delegationModal">
                <i class="fas fa-plus-circle me-1"></i>تفويض جديد
            </button>
        </div>
    </div>
    <div class="admin-list-surface">
        <div class="admin-table-wrap">
            <table class="table table-hover table-striped admin-data-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>المدير الأصلي</th>
                        <th>النائب</th>
                        <th>النطاق</th>
                        <th>أنواع الطلبات</th>
                        <th>الفترة</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($delegations === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-user-shield fa-2x d-block mb-3 text-primary"></i>لا توجد تفويضات مسجلة حاليًا.</td></tr>
                    <?php else: ?>
                        <?php foreach ($delegations as $delegation): ?>
                            <?php $delegationStatus = (string) ($delegation['status'] ?? 'draft'); ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) (($delegation['delegator_name'] ?? null) ?: ('مستخدم #' . (int) ($delegation['delegator_user_id'] ?? 0))), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) (($delegation['delegate_name'] ?? null) ?: ('مستخدم #' . (int) ($delegation['delegate_user_id'] ?? 0))), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($scopeLabels[(string) ($delegation['scope_type'] ?? '')] ?? (string) ($delegation['scope_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((int) ($delegation['scope_id'] ?? 0) > 0): ?><span class="small text-muted">#<?php echo (int) $delegation['scope_id']; ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $requestTypes = [];
                                    try {
                                        $decodedRequestTypes = json_decode((string) ($delegation['request_types'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
                                        $requestTypes = is_array($decodedRequestTypes) ? $decodedRequestTypes : [];
                                    } catch (Throwable) {
                                        $requestTypes = [];
                                    }
                                    echo htmlspecialchars($requestTypes === [] ? 'كل الأنواع' : implode('، ', array_map(static fn($type): string => $resourceLabels[(string) $type] ?? (string) $type, $requestTypes)), ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <td><span class="d-block"><?php echo htmlspecialchars((string) ($delegation['valid_from'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span><span class="small text-muted">إلى <?php echo htmlspecialchars((string) ($delegation['valid_to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><span class="badge bg-<?php echo htmlspecialchars($delegationStatusColors[$delegationStatus] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($delegationStatusLabels[$delegationStatus] ?? $delegationStatus, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td>
                                    <?php if (in_array($delegationStatus, ['draft', 'suspended'], true)): ?>
                                        <button type="button" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="تفعيل التفويض" data-delegation-action="activate" data-delegation-id="<?php echo (int) ($delegation['id'] ?? 0); ?>" data-delegation-name="<?php echo htmlspecialchars((string) (($delegation['delegator_name'] ?? '') . ' ← ' . ($delegation['delegate_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($delegationStatus === 'active'): ?>
                                        <button type="button" class="btn btn-action-pills btn-deactivate me-1" data-bs-toggle="tooltip" title="إيقاف التفويض" data-delegation-action="suspend" data-delegation-id="<?php echo (int) ($delegation['id'] ?? 0); ?>" data-delegation-name="<?php echo htmlspecialchars((string) (($delegation['delegator_name'] ?? '') . ' ← ' . ($delegation['delegate_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-pause"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (in_array($delegationStatus, ['draft', 'active', 'suspended'], true)): ?>
                                        <button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="إلغاء التفويض" data-delegation-action="revoke" data-delegation-id="<?php echo (int) ($delegation['id'] ?? 0); ?>" data-delegation-name="<?php echo htmlspecialchars((string) (($delegation['delegator_name'] ?? '') . ' ← ' . ($delegation['delegate_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="workflowModal" tabindex="-1" aria-labelledby="workflowModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium">
            <form method="post" action="hr_approval_workflows.php?tab=workflows" id="workflowForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create_workflow_version">
                <input type="hidden" name="tab" value="workflows">
                <div class="modal-header">
                    <h5 class="modal-title" id="workflowModalLabel"><i class="fas fa-diagram-project me-2"></i>مسار اعتماد أو نسخة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info"><i class="fas fa-circle-info me-2"></i>اختر مسارًا قائمًا لإنشاء نسخة جديدة منه. تبقى النسخ المنشورة ثابتة، ويحدد تاريخ البداية لحظة انتقال الاستخدام إلى النسخة الجديدة.</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label" for="workflowId">المسار السابق <span class="text-muted">(اختياري)</span></label>
                            <select class="form-select" name="workflow_id" id="workflowId">
                                <option value="">إنشاء مسار جديد</option>
                                <?php foreach ($workflowOptions as $option): ?>
                                    <option value="<?php echo (int) $option['id']; ?>" data-code="<?php echo htmlspecialchars($option['code'], ENT_QUOTES, 'UTF-8'); ?>" data-name="<?php echo htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?>" data-resource="<?php echo htmlspecialchars($option['resource_type'], ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($option['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($option['name'] . ' (' . $option['code'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="workflowCode">كود المسار</label>
                            <input type="text" class="form-control text-uppercase" name="code" id="workflowCode" maxlength="80" placeholder="PERMISSION_MAIN" required>
                            <div class="form-text">حروف إنجليزية كبيرة وأرقام وشرطة سفلية.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="workflowName">اسم المسار</label>
                            <input type="text" class="form-control" name="name" id="workflowName" maxlength="200" placeholder="مسار أذونات العاملين" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="workflowResource">نوع الطلب</label>
                            <select class="form-select" name="resource_type" id="workflowResource" required>
                                <?php foreach ($resourceLabels as $resourceType => $resourceLabel): ?>
                                    <option value="<?php echo htmlspecialchars($resourceType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($resourceLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="workflowStatus">حالة المسار الجديد</label>
                            <select class="form-select" name="workflow_status" id="workflowStatus">
                                <option value="active">نشط</option>
                                <option value="inactive">موقوف</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="cancellationRule">قاعدة الإلغاء</label>
                            <select class="form-select" name="cancellation_rule" id="cancellationRule">
                                <option value="workflow_required">يتطلب مسار الاعتماد</option>
                                <option value="request_cancellation">صاحب الطلب يطلب الإلغاء</option>
                                <option value="not_allowed">لا يسمح بالإلغاء</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="workflowValidFrom">يبدأ السريان</label>
                            <input type="datetime-local" class="form-control" name="valid_from" id="workflowValidFrom" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="workflowValidTo">ينتهي السريان <span class="text-muted">(اختياري)</span></label>
                            <input type="datetime-local" class="form-control" name="valid_to" id="workflowValidTo">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                        <div><h6 class="mb-1"><i class="fas fa-list-ol text-primary me-2"></i>مراحل الاعتماد</h6><div class="small text-muted">رتّب المراحل كما ستنفذ. «البديل» اختياري فقط عند اختيار مدير مباشر أو إداري.</div></div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="addStageButton"><i class="fas fa-plus me-1"></i>إضافة مرحلة</button>
                    </div>
                    <div id="stageRows" class="vstack gap-3">
                        <div class="border rounded-3 p-3 bg-light" data-stage-row data-stage-index="0">
                            <div class="d-flex justify-content-between align-items-center mb-3"><span class="badge bg-primary">المرحلة <span data-stage-number>1</span></span><button type="button" class="btn btn-action-pills btn-delete" data-remove-stage data-bs-toggle="tooltip" title="حذف المرحلة"><i class="fas fa-trash"></i></button></div>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">اسم المرحلة</label><input type="text" class="form-control" name="stage_name[]" maxlength="200" placeholder="المدير المباشر" required></div>
                                <div class="col-md-4"><label class="form-label">تحديد المعتمد</label><select class="form-select stage-resolver" name="stage_resolver_type[]"><option value="direct_manager">المدير المباشر</option><option value="admin_manager">المدير الإداري</option><option value="named_users">معتمدون محددون</option><option value="role_scope">نطاق الأدوار</option></select></div>
                                <div class="col-md-4"><label class="form-label">طريقة القرار</label><select class="form-select stage-decision-mode" name="stage_decision_mode[]"><option value="sequential">قرار واحد متسلسل</option><option value="any_one">يكفي أي معتمد</option><option value="all">موافقة الجميع</option><option value="quorum">نصاب محدد</option></select></div>
                                <div class="col-md-3"><label class="form-label">مهلة القرار بالدقائق</label><input type="number" class="form-control" name="stage_sla_minutes[]" min="0" placeholder="اختياري"></div>
                                <div class="col-md-3"><label class="form-label">عند انتهاء المهلة</label><select class="form-select" name="stage_on_timeout[]"><option value="fail_closed">إغلاق آمن</option><option value="escalate">تصعيد</option><option value="reassign">إعادة إسناد</option><option value="expire">انتهاء الطلب</option></select></div>
                                <div class="col-md-3"><label class="form-label">اعتماد صاحب الطلب</label><select class="form-select" name="stage_self_approval_rule[]"><option value="forbid">ممنوع</option><option value="require_alternate">يتطلب بديلًا</option><option value="allow_explicit">مسموح صراحة</option></select></div>
                                <div class="col-md-3"><label class="form-label">تكرار المعتمد</label><select class="form-select" name="stage_same_actor_rule[]"><option value="forbid">ممنوع</option><option value="merge">دمج المرحلة</option><option value="require_alternate">يتطلب بديلًا</option></select></div>
                                <div class="col-md-3 stage-quorum-control d-none"><label class="form-label">عدد النصاب</label><input type="number" class="form-control" name="stage_quorum_count[]" min="1" placeholder="مثال: 2"></div>
                                <div class="col-md-3"><label class="form-label">قاعدة التعادل</label><select class="form-select" name="stage_tie_rule[]"><option value="reject">رفض</option><option value="approve">موافقة</option></select></div>
                                <div class="col-md-3"><label class="form-label">بعد الرفض</label><select class="form-select" name="stage_rejection_rule[]"><option value="stop_workflow">إيقاف المسار</option><option value="continue">استمرار المسار</option></select></div>
                                <div class="col-md-6 stage-user-control"><label class="form-label stage-user-label">بدائل منشورة <span class="text-muted">(اختياري)</span></label><select class="form-select" multiple name="stage_user_ids[0][]" data-stage-user-select size="4"><?php foreach ($activeUsers as $user): ?><option value="<?php echo (int) $user['id']; ?>"><?php echo htmlspecialchars((string) $user['name'] . ' — ' . (string) $user['role'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-6 stage-role-control d-none"><label class="form-label">الأدوار النشطة</label><select class="form-select" multiple name="stage_role_keys[0][]" data-stage-role-select size="4"><?php foreach ($activeRoleKeys as $roleKey): ?><option value="<?php echo htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-outline-secondary" name="publish_now" value="0"><i class="fas fa-file-pen me-1"></i>حفظ مسودة</button>
                    <button type="submit" class="btn btn-success" name="publish_now" value="1"><i class="fas fa-cloud-arrow-up me-1"></i>نشر النسخة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<template id="stageRowTemplate">
    <div class="border rounded-3 p-3 bg-light" data-stage-row data-stage-index="__INDEX__">
        <div class="d-flex justify-content-between align-items-center mb-3"><span class="badge bg-primary">المرحلة <span data-stage-number>__NUMBER__</span></span><button type="button" class="btn btn-action-pills btn-delete" data-remove-stage data-bs-toggle="tooltip" title="حذف المرحلة"><i class="fas fa-trash"></i></button></div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">اسم المرحلة</label><input type="text" class="form-control" name="stage_name[]" maxlength="200" placeholder="المدير الإداري" required></div>
            <div class="col-md-4"><label class="form-label">تحديد المعتمد</label><select class="form-select stage-resolver" name="stage_resolver_type[]"><option value="direct_manager">المدير المباشر</option><option value="admin_manager">المدير الإداري</option><option value="named_users">معتمدون محددون</option><option value="role_scope">نطاق الأدوار</option></select></div>
            <div class="col-md-4"><label class="form-label">طريقة القرار</label><select class="form-select stage-decision-mode" name="stage_decision_mode[]"><option value="sequential">قرار واحد متسلسل</option><option value="any_one">يكفي أي معتمد</option><option value="all">موافقة الجميع</option><option value="quorum">نصاب محدد</option></select></div>
            <div class="col-md-3"><label class="form-label">مهلة القرار بالدقائق</label><input type="number" class="form-control" name="stage_sla_minutes[]" min="0" placeholder="اختياري"></div>
            <div class="col-md-3"><label class="form-label">عند انتهاء المهلة</label><select class="form-select" name="stage_on_timeout[]"><option value="fail_closed">إغلاق آمن</option><option value="escalate">تصعيد</option><option value="reassign">إعادة إسناد</option><option value="expire">انتهاء الطلب</option></select></div>
            <div class="col-md-3"><label class="form-label">اعتماد صاحب الطلب</label><select class="form-select" name="stage_self_approval_rule[]"><option value="forbid">ممنوع</option><option value="require_alternate">يتطلب بديلًا</option><option value="allow_explicit">مسموح صراحة</option></select></div>
            <div class="col-md-3"><label class="form-label">تكرار المعتمد</label><select class="form-select" name="stage_same_actor_rule[]"><option value="forbid">ممنوع</option><option value="merge">دمج المرحلة</option><option value="require_alternate">يتطلب بديلًا</option></select></div>
            <div class="col-md-3 stage-quorum-control d-none"><label class="form-label">عدد النصاب</label><input type="number" class="form-control" name="stage_quorum_count[]" min="1" placeholder="مثال: 2"></div>
            <div class="col-md-3"><label class="form-label">قاعدة التعادل</label><select class="form-select" name="stage_tie_rule[]"><option value="reject">رفض</option><option value="approve">موافقة</option></select></div>
            <div class="col-md-3"><label class="form-label">بعد الرفض</label><select class="form-select" name="stage_rejection_rule[]"><option value="stop_workflow">إيقاف المسار</option><option value="continue">استمرار المسار</option></select></div>
            <div class="col-md-6 stage-user-control"><label class="form-label stage-user-label">بدائل منشورة <span class="text-muted">(اختياري)</span></label><select class="form-select" multiple name="stage_user_ids[__INDEX__][]" data-stage-user-select size="4"><?php foreach ($activeUsers as $user): ?><option value="<?php echo (int) $user['id']; ?>"><?php echo htmlspecialchars((string) $user['name'] . ' — ' . (string) $user['role'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6 stage-role-control d-none"><label class="form-label">الأدوار النشطة</label><select class="form-select" multiple name="stage_role_keys[__INDEX__][]" data-stage-role-select size="4"><?php foreach ($activeRoleKeys as $roleKey): ?><option value="<?php echo htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        </div>
    </div>
</template>

<div class="modal fade" id="delegationModal" tabindex="-1" aria-labelledby="delegationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium">
            <form method="post" action="hr_approval_workflows.php?tab=delegations">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create_delegation">
                <input type="hidden" name="tab" value="delegations">
                <div class="modal-header">
                    <h5 class="modal-title" id="delegationModalLabel"><i class="fas fa-user-shield me-2"></i>تفويض مدير</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info"><i class="fas fa-shield-halved me-2"></i>يظل التفويض مقيدًا بالمدة والنطاق ونوع الطلب، ويتوقف تلقائيًا عند انتهاء صلاحيته أو تعطيل الحساب.</div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="delegatorUser">المدير الأصلي</label><select class="form-select" name="delegator_user_id" id="delegatorUser" required><option value="">اختر المدير</option><?php foreach ($activeUsers as $user): ?><option value="<?php echo (int) $user['id']; ?>"><?php echo htmlspecialchars((string) $user['name'] . ' — ' . (string) $user['role'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label" for="delegateUser">النائب</label><select class="form-select" name="delegate_user_id" id="delegateUser" required><option value="">اختر النائب</option><?php foreach ($activeUsers as $user): ?><option value="<?php echo (int) $user['id']; ?>"><?php echo htmlspecialchars((string) $user['name'] . ' — ' . (string) $user['role'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label" for="delegationScopeType">نطاق التفويض</label><select class="form-select" name="scope_type" id="delegationScopeType"><option value="global">عام</option><option value="org_unit">وحدة تنظيمية</option><option value="group">مجموعة</option><option value="staff">عامل محدد</option><option value="request_type">نوع إذن محدد</option></select></div>
                        <div class="col-md-6" id="delegationScopeIdGroup"><label class="form-label" for="delegationScopeId">معرّف النطاق</label><input type="number" class="form-control" name="scope_id" id="delegationScopeId" min="1" placeholder="يظهر فقط للنطاق غير العام"></div>
                        <div class="col-md-6"><label class="form-label" for="delegationValidFrom">بداية التفويض</label><input type="datetime-local" class="form-control" name="valid_from" id="delegationValidFrom" required></div>
                        <div class="col-md-6"><label class="form-label" for="delegationValidTo">نهاية التفويض</label><input type="datetime-local" class="form-control" name="valid_to" id="delegationValidTo" required></div>
                        <div class="col-12"><label class="form-label" for="delegationRequestTypes">أنواع الطلبات <span class="text-muted">(اختياري: فارغ يعني جميع الأنواع)</span></label><select class="form-select" name="request_types[]" id="delegationRequestTypes" multiple size="4"><?php foreach ($resourceLabels as $resourceType => $resourceLabel): ?><option value="<?php echo htmlspecialchars($resourceType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($resourceLabel, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label" for="delegationStatus">حالة التفويض</label><select class="form-select" name="status" id="delegationStatus"><option value="draft">مسودة</option><option value="active">تفعيل الآن</option></select></div>
                        <div class="col-12"><label class="form-label" for="delegationReason">سبب التفويض</label><textarea class="form-control" name="reason" id="delegationReason" rows="3" maxlength="500" required></textarea><div class="form-text">يستخدم للتوثيق الإداري ولا يظهر في إشعار المعتمد.</div></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ التفويض</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="workflowActionModal" tabindex="-1" aria-labelledby="workflowActionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium">
            <form method="post" action="hr_approval_workflows.php?tab=workflows">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="tab" value="workflows">
                <input type="hidden" name="action" id="workflowAction">
                <input type="hidden" name="workflow_id" id="workflowActionWorkflowId">
                <input type="hidden" name="workflow_version_id" id="workflowActionVersionId">
                <input type="hidden" name="workflow_status" id="workflowActionStatus">
                <div class="modal-header"><h5 class="modal-title" id="workflowActionModalLabel"><i class="fas fa-circle-question me-2"></i>تأكيد العملية</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-shield-halved text-primary" style="font-size: 3rem;"></i></div><p class="text-center mb-0" id="workflowActionText"></p></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary" id="workflowActionSubmit"><i class="fas fa-check me-1"></i>تأكيد</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="delegationActionModal" tabindex="-1" aria-labelledby="delegationActionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium">
            <form method="post" action="hr_approval_workflows.php?tab=delegations">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="tab" value="delegations">
                <input type="hidden" name="action" value="end_delegation" id="delegationAction">
                <input type="hidden" name="delegation_id" id="delegationActionId">
                <input type="hidden" name="delegation_status" id="delegationActionStatus">
                <div class="modal-header"><h5 class="modal-title" id="delegationActionModalLabel"><i class="fas fa-shield-halved me-2"></i>تأكيد إجراء التفويض</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-user-shield text-warning" style="font-size: 3rem;"></i></div><p class="text-center mb-0" id="delegationActionText"></p></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-warning" id="delegationActionSubmit"><i class="fas fa-pause me-1"></i>تأكيد</button></div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const workflowSelect = document.getElementById('workflowId');
    const workflowCode = document.getElementById('workflowCode');
    const workflowName = document.getElementById('workflowName');
    const workflowResource = document.getElementById('workflowResource');
    const workflowStatus = document.getElementById('workflowStatus');
    const stageRows = document.getElementById('stageRows');
    const stageTemplate = document.getElementById('stageRowTemplate');
    let nextStageIndex = 1;

    const updateWorkflowIdentity = function () {
        const option = workflowSelect.options[workflowSelect.selectedIndex];
        const existing = workflowSelect.value !== '';
        if (existing) {
            workflowCode.value = option.dataset.code || '';
            workflowName.value = option.dataset.name || '';
            workflowResource.value = option.dataset.resource || '';
            workflowStatus.value = option.dataset.status === 'inactive' ? 'inactive' : 'active';
        }
        [workflowCode, workflowName, workflowResource, workflowStatus].forEach(function (field) {
            field.disabled = existing;
        });
    };
    workflowSelect.addEventListener('change', updateWorkflowIdentity);

    const updateStageRow = function (row) {
        const resolver = row.querySelector('.stage-resolver');
        const mode = row.querySelector('.stage-decision-mode');
        const userControl = row.querySelector('.stage-user-control');
        const roleControl = row.querySelector('.stage-role-control');
        const userLabel = row.querySelector('.stage-user-label');
        const quorumControl = row.querySelector('.stage-quorum-control');
        const isRoleScope = resolver.value === 'role_scope';
        const isNamed = resolver.value === 'named_users';
        userControl.classList.toggle('d-none', isRoleScope);
        roleControl.classList.toggle('d-none', !isRoleScope);
        userLabel.innerHTML = isNamed ? 'المعتمدون المحددون <span class="text-danger">(مطلوب)</span>' : 'بدائل منشورة <span class="text-muted">(اختياري)</span>';
        quorumControl.classList.toggle('d-none', mode.value !== 'quorum');
    };
    const refreshStageNumbers = function () {
        stageRows.querySelectorAll('[data-stage-row]').forEach(function (row, index) {
            row.querySelector('[data-stage-number]').textContent = String(index + 1);
            row.querySelector('[data-stage-user-select]').name = 'stage_user_ids[' + index + '][]';
            row.querySelector('[data-stage-role-select]').name = 'stage_role_keys[' + index + '][]';
        });
    };
    stageRows.addEventListener('change', function (event) {
        if (event.target.matches('.stage-resolver, .stage-decision-mode')) {
            updateStageRow(event.target.closest('[data-stage-row]'));
        }
    });
    stageRows.addEventListener('click', function (event) {
        const remove = event.target.closest('[data-remove-stage]');
        if (!remove) return;
        const rows = stageRows.querySelectorAll('[data-stage-row]');
        if (rows.length === 1) return;
        remove.closest('[data-stage-row]').remove();
        refreshStageNumbers();
    });
    document.getElementById('addStageButton').addEventListener('click', function () {
        const markup = stageTemplate.innerHTML.replaceAll('__INDEX__', String(nextStageIndex)).replaceAll('__NUMBER__', String(stageRows.querySelectorAll('[data-stage-row]').length + 1));
        const holder = document.createElement('div');
        holder.innerHTML = markup.trim();
        const row = holder.firstElementChild;
        stageRows.appendChild(row);
        updateStageRow(row);
        nextStageIndex += 1;
    });
    stageRows.querySelectorAll('[data-stage-row]').forEach(updateStageRow);

    const scopeType = document.getElementById('delegationScopeType');
    const scopeGroup = document.getElementById('delegationScopeIdGroup');
    const scopeId = document.getElementById('delegationScopeId');
    const updateScope = function () {
        const isGlobal = scopeType.value === 'global';
        scopeGroup.classList.toggle('d-none', isGlobal);
        scopeId.required = !isGlobal;
        if (isGlobal) scopeId.value = '';
    };
    scopeType.addEventListener('change', updateScope);
    updateScope();

    document.querySelectorAll('[data-workflow-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modalElement = document.getElementById('workflowActionModal');
            const action = button.dataset.workflowAction;
            const name = button.dataset.workflowName || 'هذا المسار';
            document.getElementById('workflowAction').value = action === 'publish' ? 'publish_workflow_version' : 'change_workflow_status';
            document.getElementById('workflowActionWorkflowId').value = button.dataset.workflowId || '';
            document.getElementById('workflowActionVersionId').value = button.dataset.versionId || '';
            document.getElementById('workflowActionStatus').value = button.dataset.workflowStatus || '';
            const publish = action === 'publish';
            document.getElementById('workflowActionModalLabel').textContent = publish ? 'نشر نسخة المسار' : 'تحديث حالة المسار';
            document.getElementById('workflowActionText').textContent = publish ? 'هل تريد نشر النسخة المسودة من «' + name + '»؟' : 'هل تريد تغيير حالة «' + name + '»؟';
            document.getElementById('workflowActionSubmit').className = publish ? 'btn btn-success' : 'btn btn-primary';
            document.getElementById('workflowActionSubmit').innerHTML = publish ? '<i class="fas fa-cloud-arrow-up me-1"></i>نشر' : '<i class="fas fa-check me-1"></i>تأكيد';
            new bootstrap.Modal(modalElement).show();
        });
    });
    document.querySelectorAll('[data-delegation-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            const action = button.dataset.delegationAction;
            const revoke = action === 'revoke';
            const activate = action === 'activate';
            const name = button.dataset.delegationName || 'هذا التفويض';
            document.getElementById('delegationAction').value = activate ? 'activate_delegation' : 'end_delegation';
            document.getElementById('delegationActionId').value = button.dataset.delegationId || '';
            document.getElementById('delegationActionStatus').value = revoke ? 'revoked' : (activate ? '' : 'suspended');
            document.getElementById('delegationActionModalLabel').textContent = activate ? 'تفعيل التفويض' : (revoke ? 'إلغاء التفويض' : 'إيقاف التفويض');
            document.getElementById('delegationActionText').textContent = activate ? 'هل تريد تفعيل «' + name + '» بعد إعادة التحقق من صلاحيته؟' : (revoke ? 'هل تريد إلغاء «' + name + '» نهائيًا؟' : 'هل تريد إيقاف «' + name + '»؟');
            document.getElementById('delegationActionSubmit').className = activate ? 'btn btn-success' : (revoke ? 'btn btn-danger' : 'btn btn-warning');
            document.getElementById('delegationActionSubmit').innerHTML = activate ? '<i class="fas fa-play me-1"></i>تفعيل' : (revoke ? '<i class="fas fa-ban me-1"></i>إلغاء التفويض' : '<i class="fas fa-pause me-1"></i>إيقاف');
            new bootstrap.Modal(document.getElementById('delegationActionModal')).show();
        });
    });
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
        new bootstrap.Tooltip(element);
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
