<?php
/**
 * إدارة الموظفين الموحدة - معلمين + أخصائيين + مشرفي مواد
 * نظام الموارد البشرية - المرحلة 1
 */
$page_title = "إدارة الموظفين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/utilities.php';
require_once '../classes/excel_handler.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../classes/UndoManager.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once __DIR__ . '/includes/profile_excel_import.php';
require_once '../classes/ProfileInputValidator.php';
require_once '../classes/StaffSchemaGuard.php';
require_once '../classes/StaffEmploymentLifecycleService.php';
require_once '../classes/StaffProfilePayload.php';
require_once '../classes/StaffProfileRepository.php';
require_once '../classes/StaffProfileRequestMapper.php';
require_once '../classes/StaffProfileCommandService.php';
require_once '../classes/StaffProfileErrorPresenter.php';
require_once '../classes/StaffAttachmentService.php';
require_once '../classes/StaffDeletionService.php';
require_once '../classes/StaffProfilePageQuery.php';
require_once '../classes/StaffListPageQuery.php';
Utilities::validateSession('admin');

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
$staffFormRetry = $_SESSION['staff_form_retry'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
unset($_SESSION['staff_form_retry']);

// Determine active tab for redirects and UI
$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'basic');
$validTabs = ['basic', 'employment', 'qualifications', 'health', 'attachments'];
if (!in_array($activeTab, $validTabs)) { $activeTab = 'basic'; }

$database = new Database();
$db = $database->getConnection();
ActivityLog::setDb($db);
UndoManager::setDb($db);
$user = new User($db);
$class = new ClassRoom($db);
$excel_handler = new ExcelHandler();
$staffSchemaGuard = new StaffSchemaGuard($db);
$staffSchemaGuard->assertReady();
$staffEmploymentLifecycle = new StaffEmploymentLifecycleService($db);
$staffProfileRequestMapper = new StaffProfileRequestMapper($staffEmploymentLifecycle);
$staffProfileRepository = new StaffProfileRepository($db);
$staffProfileCommandService = new StaffProfileCommandService(
    $db,
    $user,
    $staffProfileRequestMapper,
    $staffEmploymentLifecycle,
    $staffProfileRepository,
    __DIR__ . '/../uploads/staff'
);
$staffAttachmentService = new StaffAttachmentService(
    $db,
    $staffProfileRepository,
    new ProfileAttachmentStorage(),
    __DIR__ . '/../uploads/staff'
);
$staffDeletionService = new StaffDeletionService($db, $user, $staffProfileRepository);
$staffProfilePageQuery = new StaffProfilePageQuery($db, $user, $staffEmploymentLifecycle);
$staffListPageQuery = new StaffListPageQuery($db, $user);

if (($_GET['download_profile_template'] ?? '') === 'staff') {
    profile_import_download_template('staff');
}

$roleLabels = ['teacher' => 'معلم', 'specialist' => 'أخصائي'];
$roleBadges = ['teacher' => 'primary', 'specialist' => 'info'];

// قوائم المسميات الوظيفية والأقسام
$jobTitles = StaffEmploymentLifecycleService::jobTitleOptions();
$departments = StaffEmploymentLifecycleService::departmentOptions();

function compose_staff_name_parts($parts): string {
    return StaffProfilePayload::composeNameParts($parts);
}

function split_staff_name_parts(?string $fullName, int $maxParts = 5): array {
    return StaffProfilePayload::splitNameParts($fullName, $maxParts);
}

function get_staff_activity_snapshot(PDO $db, int $userId): array {
    return (new StaffProfileRepository($db))->activitySnapshot($userId);
}

function build_staff_activity_details(array $before, array $after, bool $passwordChanged = false): ?array {
    return StaffProfilePayload::activityDetails($before, $after, $passwordChanged);
}

function normalize_staff_form_payload(array &$post): void {
    $post = StaffProfilePayload::normalizeForm($post);
}

    // بناء مصفوفة JSON للأرقام الإضافية للموظف
    function build_staff_extra_phones(array $post): ?string {
        return StaffProfilePayload::extraPhones($post);
    }

    // بناء مصفوفة JSON للبيانات الإضافية الحرة للموظف
    function build_staff_extra_data(array $post): ?string {
        return StaffProfilePayload::extraData($post);
    }

    // بناء مصفوفة JSON للبيانات الوظيفية الإضافية للموظف
    function build_staff_extra_employment_data(array $post): ?string {
        return StaffProfilePayload::extraEmploymentData($post);
    }

// معالجة النماذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost(); // حماية CSRF لكل عمليات POST (إضافة/تعديل/حذف/استيراد)
    normalize_staff_form_payload($_POST);

    // ===== إضافة موظف =====
    if (isset($_POST['add_staff'])) {
        try {
            $staffProfileCommandService->create(
                $_POST,
                $_FILES,
                $departments,
                (int) ($_SESSION['user_id'] ?? 0)
            );
            unset($_SESSION['staff_form_retry']);
            $_SESSION['success_message'] = "تمت إضافة الموظف بنجاح.";
            header("Location: staff.php" . Utilities::buildQueryString(['tab' => $activeTab]));
            exit();
        } catch (Throwable $exception) {
            $retryData = $_POST;
            unset($retryData['csrf_token'], $retryData['add_staff'], $retryData['edit_staff']);
            $_SESSION['staff_form_retry'] = [
                'action' => 'add',
                'user_id' => 0,
                'data' => $retryData,
            ];
            $_SESSION['error_message'] = StaffProfileErrorPresenter::saveMessage(
                $exception,
                'create'
            );
            header("Location: staff.php" . Utilities::buildQueryString([
                'action' => 'add',
                'tab' => $activeTab,
            ]));
            exit();
        }
    }

    // ===== تعديل موظف =====
    elseif (isset($_POST['edit_staff'])) {
        $userId = (int) ($_POST['id'] ?? 0);
        try {
            $staffProfileCommandService->update(
                $userId,
                $_POST,
                $_FILES,
                $departments,
                (int) ($_SESSION['user_id'] ?? 0)
            );
            unset($_SESSION['staff_form_retry']);
            $_SESSION['success_message'] = "تم تحديث بيانات الموظف بنجاح.";
            header("Location: staff.php");
            exit();
        } catch (Throwable $exception) {
            $retryData = $_POST;
            unset($retryData['csrf_token'], $retryData['add_staff'], $retryData['edit_staff']);
            $_SESSION['staff_form_retry'] = [
                'action' => 'edit',
                'user_id' => $userId,
                'data' => $retryData,
            ];
            $_SESSION['error_message'] = StaffProfileErrorPresenter::saveMessage(
                $exception,
                'update'
            );
            header("Location: staff.php" . Utilities::buildQueryString([
                'action' => 'edit',
                'id' => $userId,
                'tab' => $activeTab,
            ]));
            exit();
        }
    }

    // ===== رفع صورة الملف الشخصي للموظف =====
    if (isset($_POST['action']) && $_POST['action'] === 'upload_staff_profile_image') {
        $userId = (int) ($_POST['id'] ?? 0);
        try {
            $staffAttachmentService->uploadProfileImage(
                $userId,
                $_FILES['profile_image'] ?? []
            );
            $_SESSION['success_message'] = "تم رفع الصورة الشخصية بنجاح.";
        } catch (Throwable $exception) {
            $_SESSION['error_message'] = "خطأ: " . $exception->getMessage();
        }
        header("Location: staff.php?action=edit&id=" . $userId . "&tab=attachments");
        exit();
    }

    // ===== حذف مرفق موظف =====
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_staff_attachment') {
        $userId = (int) ($_POST['id'] ?? 0);
        try {
            $deleted = $staffAttachmentService->deleteAttachment(
                $userId,
                (int) ($_POST['attachment_id'] ?? 0)
            );
            if ($deleted) {
                $_SESSION['success_message'] = "تم حذف المرفق بنجاح.";
            } else {
                $_SESSION['error_message'] = "المرفق غير موجود.";
            }
        } catch (Throwable $exception) {
            $_SESSION['error_message'] = "خطأ: " . $exception->getMessage();
        }
        header("Location: staff.php?action=edit&id=" . $userId . "&tab=attachments");
        exit();
    }

    // ===== حذف موظف =====
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        try {
            $deleted = $staffDeletionService->delete((int) ($_POST['id'] ?? 0));
            if ($deleted) {
                $_SESSION['success_message'] = "تم حذف الموظف بنجاح.";
            } else {
                $_SESSION['error_message'] = "لا يمكن الحذف لوجود تقييمات مرتبطة. يمكنك تعطيل الحساب بدلاً من ذلك.";
            }
        } catch (Throwable $exception) {
            $_SESSION['error_message'] = "خطأ: " . $exception->getMessage();
        }
        header("Location: staff.php" . Utilities::buildQueryString(['tab' => $activeTab]));
        exit();
    }

    // ===== استيراد Excel تفصيلي: تحقق كامل قبل كتابة أي سجل =====
    elseif (isset($_POST['import_staff']) && isset($_FILES['excel_file'])) {
        try {
            $result = profile_import_staff($_FILES['excel_file'], $db, $user);
            $_SESSION['success_message'] = 'تم استيراد ' . $result['count'] . ' موظف بنجاح مع بياناتهم التفصيلية.';
        } catch (Throwable $e) {
        $_SESSION['error_message'] = StaffProfileErrorPresenter::saveMessage($e, 'import');
            error_log('Staff detailed Excel import error: ' . $e->getMessage());
        }
        header("Location: staff.php" . Utilities::buildQueryString(['tab' => $activeTab]));
        exit();
    }
}

// جلب بيانات التعديل والعرض
$staffProfile = null;
$staffAttachments = [];
$editExtraPhones = [];
$editExtraData = [];
$editExtraEmploymentData = [];
if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $staffEditData = $staffProfilePageQuery->editData((int) $_GET['id']);
    $staffProfile = $staffEditData['profile'];
    $staffAttachments = $staffEditData['attachments'];
    $editExtraPhones = $staffEditData['extra_phones'];
    $editExtraData = $staffEditData['extra_data'];
    $editExtraEmploymentData = $staffEditData['extra_employment_data'];
}

if (is_array($staffFormRetry)
    && ($staffFormRetry['action'] ?? '') === ($_GET['action'] ?? '')
    && (
        ($staffFormRetry['action'] ?? '') === 'add'
        || (int)($staffFormRetry['user_id'] ?? 0) === (int)($_GET['id'] ?? 0)
    )
    && is_array($staffFormRetry['data'] ?? null)) {
    $retryData = StaffProfilePayload::normalizeForm($staffFormRetry['data']);
    $staffProfile = array_merge($staffProfile ?: [], $retryData);
    $editExtraPhones = json_decode(
        StaffProfilePayload::extraPhones($retryData) ?? '[]',
        true
    ) ?: [];
    $editExtraData = json_decode(
        StaffProfilePayload::extraData($retryData) ?? '[]',
        true
    ) ?: [];
    $editExtraEmploymentData = json_decode(
        StaffProfilePayload::extraEmploymentData($retryData) ?? '[]',
        true
    ) ?: [];
}

$viewProfile = null;
$viewUser = null;
if (($_GET['action'] ?? '') === 'view' && isset($_GET['id'])) {
    $staffViewData = $staffProfilePageQuery->viewData((int) $_GET['id']);
    $viewUser = $staffViewData['user'];
    $viewProfile = $staffViewData['profile'];
}

$staffListData = $staffListPageQuery->load($_GET);
$action = $staffListData['action'];
$mainTab = $staffListData['main_tab'];
$staffLogFilters = $staffListData['activity_filters'];
$staffLogPage = $staffListData['activity_page'];
$staffLogPerPage = $staffListData['activity_per_page'];
$staffLogOffset = $staffListData['activity_offset'];
$staffLogTotal = $staffListData['activity_total'];
$staffLogTotalPages = $staffListData['activity_pages'];
$staffLogs = $staffListData['activity_logs'];
$allStaff = $staffListData['staff'];
$staffTotal = $staffListData['staff_total'];
$staffServerSide = $staffListData['staff_server_side'];
$staffFilterJobTitles = $staffListData['filter_job_titles'];
$staffFilterForces = $staffListData['filter_forces'];

// تضمين الهيدر
$adminAssetOptions = [
    'datatables' => $action !== 'view',
    'sweetalert' => false,
    'sortable' => false,
    'instant_attachment_upload' => $action === 'add' || $action === 'edit',
    'dashboard_sortable' => false,
];
include_once '../includes/admin_header.php';

// تسميات عربية
$genderLabels = ['male' => 'ذكر', 'female' => 'أنثى'];
$religionLabels = ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى'];
$maritalLabels = ['single' => 'أعزب', 'married' => 'متزوج', 'divorced' => 'مطلق', 'widowed' => 'أرمل'];
$contractLabels = ['permanent' => 'دائم', 'temporary' => 'مؤقت', 'parttime' => 'جزئي', 'other' => 'أخرى'];
$viewMaritalStatusLabel = '-';
if (!empty($viewProfile['marital_status'])) {
    $viewMaritalStatusLabel = $maritalLabels[$viewProfile['marital_status']]
        ?? $viewProfile['marital_status'];
}
?>

<?php if ($action !== 'view'): ?>
<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="fas fa-users-cog me-2 text-primary"></i>إدارة شؤون العاملين</h1>
        <p class="text-muted m-0">إدارة الملفات الوظيفية، المعلمين، الأخصائيين، والأطقم الإدارية</p>
    </div>
    <div class="admin-top-actions no-print">
        <a href="staff.php?action=add" class="btn btn-header-premium btn-success shadow-sm">
            <i class="fas fa-plus-circle me-1"></i>إضافة موظف جديد
        </a>
        <a href="export_staff.php" class="btn btn-header-premium btn-export-soft">
            <i class="fas fa-file-excel me-1"></i>تصدير Excel
        </a>
        <button type="button" class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal" data-bs-target="#importStaffModal">
            <i class="fas fa-file-import me-1"></i>استيراد Excel
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Alerts -->
<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars((string)$error_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../src/Modules/Staff/Presentation/profile_view.php'; ?>
<?php require __DIR__ . '/../src/Modules/Staff/Presentation/profile_form.php'; ?>
<?php require __DIR__ . '/../src/Modules/Staff/Presentation/list_view.php'; ?>

<?php require __DIR__ . '/../src/Modules/Staff/Presentation/page_scripts.php'; ?>

<?php
include_once '../includes/admin_footer.php';
?>
