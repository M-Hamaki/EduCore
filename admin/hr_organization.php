<?php

declare(strict_types=1);

/** إدارة الوحدات والمسميات والتعيينات المؤرخة ضمن شؤون العاملين. */

$page_title = 'التنظيم والتعيينات';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/Staff/bootstrap.php';
require_once '../src/Modules/Attendance/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;
use EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory;

$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$today = (new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d');
$statusLabels = [
    'active' => 'نشط',
    'inactive' => 'غير نشط',
    'retired' => 'منتهٍ',
    'suspended' => 'موقوف',
    'ended' => 'منتهٍ',
    'rehired' => 'عاد للخدمة',
];
$statusClasses = [
    'active' => 'success',
    'rehired' => 'success',
    'inactive' => 'secondary',
    'retired' => 'secondary',
    'suspended' => 'warning',
    'ended' => 'danger',
];
$correctionKindLabels = [
    'organization_unit' => 'القوة/الوحدة',
    'job_title' => 'المسمى الوظيفي',
    'manager' => 'المدير والمسار',
    'calendar' => 'التقويم',
];
$correctionScopeLabels = [
    'staff' => 'عامل',
    'org_unit' => 'قوة/وحدة',
    'policy_group' => 'مجموعة',
    'global' => 'كل العاملين',
];
$correctionStatusLabels = [
    'previewed' => 'بانتظار القرار',
    'approved' => 'معتمد',
    'rejected' => 'مرفوض',
];
$correctionStatusClasses = [
    'previewed' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
];
$commandMessages = [
    'create_unit' => 'تم تسجيل الوحدة/القوة التنظيمية بنجاح.',
    'create_job_title' => 'تم تسجيل المسمى الوظيفي بنجاح.',
    'create_group' => 'تم إنشاء مجموعة العاملين بنجاح.',
    'add_group_member' => 'تمت إضافة العامل إلى المجموعة بالفترة المحددة.',
    'assign_manager' => 'تم حفظ علاقة المدير بالفترة المحددة.',
    'create_assignment' => 'تم حفظ التعيين الوظيفي بالفترة المحددة.',
    'transfer_employment' => 'تم نقل العامل وحفظ تعيين مؤرخ جديد مع الاحتفاظ بالتاريخ السابق.',
    'end_employment' => 'تم إنهاء خدمة العامل وإلغاء الطلبات المعلقة وتحرير حصصها.',
    'preview_correction' => 'تم تثبيت معاينة أثر التصحيح. يلزم اعتماد مستخدم آخر مخول قبل إنشاء نوايا إعادة الاحتساب.',
    'decide_correction' => 'تم حفظ قرار التصحيح التنظيمي بنجاح.',
    'reverse_correction' => 'تم إنشاء معاينة عكسية مستقلة. يلزم اعتمادها قبل استعادة الإسقاط السابق.',
];
$errorMessages = [
    'STAFF_ORG_ACTOR_FORBIDDEN' => 'لا تملك صلاحية إدارة الهيكل التنظيمي للعاملين.',
    'STAFF_ORG_UNIT_RANGE_CONFLICT' => 'يوجد بالفعل سجل متداخل لنفس رمز الوحدة خلال هذه الفترة.',
    'STAFF_ORG_JOB_TITLE_RANGE_CONFLICT' => 'يوجد بالفعل سجل متداخل لنفس رمز المسمى خلال هذه الفترة.',
    'STAFF_ORG_GROUP_RANGE_CONFLICT' => 'يوجد بالفعل سجل متداخل لنفس رمز المجموعة خلال هذه الفترة.',
    'STAFF_ORG_GROUP_MEMBERSHIP_CONFLICT' => 'العامل عضو نشط بالفعل في هذه المجموعة خلال فترة متداخلة.',
    'STAFF_ORG_MANAGER_SCOPE_CONFLICT' => 'يوجد مدير بنفس النطاق والأولوية خلال فترة متداخلة.',
    'STAFF_ORG_MANAGER_CYCLE' => 'لا يمكن حفظ العلاقة لأنها ستنشئ دورة إدارية بين العاملين.',
    'STAFF_ORG_MANAGER_SELF_REFERENCE' => 'لا يمكن للعامل أن يكون مديرًا مباشرًا لنفسه.',
    'STAFF_ORG_PRIMARY_ASSIGNMENT_CONFLICT' => 'للعامل تعيين أساسي متداخل بالفعل خلال هذه الفترة.',
    'STAFF_ORG_ASSIGNMENT_END_DATE_REQUIRED' => 'التعيين المؤقت أو المنتهي يحتاج تاريخ انتهاء واضحًا.',
    'STAFF_ORG_ASSIGNMENT_REFERENCE_UNAVAILABLE' => 'القوة أو المسمى المختار غير ساريين طوال فترة التعيين.',
    'STAFF_ORG_PARENT_UNAVAILABLE' => 'الوحدة الأم غير سارية طوال الفترة المطلوبة.',
    'STAFF_ORG_GROUP_UNAVAILABLE' => 'المجموعة المختارة غير سارية طوال الفترة المطلوبة.',
    'STAFF_ORG_MEMBER_NOT_STAFF' => 'العامل المختار لا يملك ملف عامل صالحًا.',
    'STAFF_ORG_ASSIGNMENT_SUBJECT_NOT_STAFF' => 'لا يمكن إسناد تعيين إلى حساب لا يملك ملف عامل.',
    'STAFF_ORG_MANAGER_SUBJECT_NOT_STAFF' => 'العامل الخاضع للعلاقة الإدارية غير صالح.',
    'STAFF_ORG_MANAGER_ACCOUNT_INACTIVE' => 'حساب المدير المختار غير نشط.',
    'STAFF_ORG_READ_LIMIT_INVALID' => 'تعذر تحميل البيانات بالحد المطلوب.',
    'STAFF_ORG_CORRECTION_REQUESTER_FORBIDDEN' => 'لا تملك صلاحية طلب تصحيح تنظيمي مؤثر.',
    'STAFF_ORG_CORRECTION_APPROVER_FORBIDDEN' => 'اعتماد التصحيح متاح للسوبر أدمن أو مدير الموارد البشرية المخول فقط.',
    'STAFF_ORG_CORRECTION_SELF_APPROVAL_FORBIDDEN' => 'لا يجوز لمقدم التصحيح اعتماد طلبه بنفسه. يلزم مستخدم مخول آخر.',
    'STAFF_ORG_CORRECTION_ALREADY_DECIDED' => 'تم اتخاذ قرار نهائي لهذا التصحيح بالفعل.',
    'STAFF_ORG_CORRECTION_VERSION_CONFLICT' => 'تغيرت نسخة التصحيح. حدّث الصفحة وراجع المعاينة قبل القرار.',
    'STAFF_ORG_CORRECTION_IDEMPOTENCY_CONFLICT' => 'مفتاح المعاينة مستخدم لمحتوى مختلف. أعد فتح النموذج.',
    'STAFF_ORG_CORRECTION_DECISION_IDEMPOTENCY_CONFLICT' => 'تعذر تكرار القرار لأن محتواه تغير. حدّث الصفحة.',
    'STAFF_ORG_CORRECTION_NO_AFFECTED_STAFF' => 'لم تعثر المعاينة على عاملين متأثرين بهذا النطاق.',
    'STAFF_ORG_CORRECTION_IMPACT_TOO_LARGE' => 'نطاق التصحيح كبير جدًا. قلّص الفترة أو اختر قوة/مجموعة أصغر.',
    'STAFF_ORG_CORRECTION_REFERENCE_UNAVAILABLE' => 'المرجع الجديد غير موجود أو غير متاح.',
    'STAFF_ORG_CORRECTION_REVERSAL_NOT_AVAILABLE' => 'لا يمكن عكس تصحيح غير معتمد أو غير موجود.',
    'STAFF_ORG_CORRECTION_IMPACT_PUBLISH_FAILED' => 'لم تُحفظ نوايا الأثر؛ تم التراجع عن القرار بالكامل ويمكنك المحاولة لاحقًا.',
];

$organizationErrorMessage = static function (Throwable $exception) use ($errorMessages): string {
    $code = $exception->getMessage();
    if (isset($errorMessages[$code])) {
        return $errorMessages[$code];
    }

    return 'تعذر حفظ العملية. راجع الحقول وفترات السريان ثم حاول مرة أخرى.';
};

if ($actorUserId <= 0) {
    http_response_code(403);
    exit('جلسة المستخدم غير صالحة.');
}

$database = new Database();
$db = $database->getConnection();
$factory = new StaffModuleFactory($db, new AuditService($db));
$attendanceFactory = new AttendanceModuleFactory($db, new AuditService($db));
$organizationService = $factory->organizationAdministration();
$organizationQuery = $factory->organizationAdministrationRead();
$correctionService = $factory->organizationCorrections();
$employmentLifecycle = $factory->employmentLifecycle($attendanceFactory->approvedCoverageChangeGateway());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        $_SESSION['hr_organization_feedback'] = [
            'type' => 'danger',
            'message' => 'انتهت صلاحية نموذج الحفظ. أعد فتح النموذج ثم حاول مرة أخرى.',
        ];
        header('Location: hr_organization.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        $payload = $_POST;
        if ($action === 'assign_manager') {
            $payload['subject_id'] = (string) ($payload['subject_type'] ?? '') === 'staff'
                ? ($payload['subject_staff_id'] ?? null)
                : ($payload['subject_org_unit_id'] ?? null);
        }

        $result = match ($action) {
            'create_unit' => $organizationService->createOrganizationUnit($payload, $actorUserId),
            'create_job_title' => $organizationService->createJobTitle($payload, $actorUserId),
            'create_group' => $organizationService->createPolicyGroup($payload, $actorUserId),
            'add_group_member' => $organizationService->addPolicyGroupMembership($payload, $actorUserId),
            'assign_manager' => $organizationService->assignManager($payload, $actorUserId),
            'create_assignment' => $organizationService->createAssignment($payload, $actorUserId),
            'transfer_employment' => $employmentLifecycle->transfer($payload, $actorUserId),
            'end_employment' => $employmentLifecycle->endService($payload, $actorUserId),
            'preview_correction' => $correctionService->previewCorrection($payload, $actorUserId),
            'decide_correction' => $correctionService->decideCorrection($payload, $actorUserId),
            'reverse_correction' => $correctionService->previewReversal($payload, $actorUserId),
            default => throw new InvalidArgumentException('STAFF_ORG_ACTION_INVALID'),
        };
        $message = $commandMessages[$action] ?? 'تم حفظ العملية بنجاح.';
        if ($action === 'preview_correction' && is_array($result)) {
            $message .= ' المتأثرون: ' . count($result['impact']['affected_staff_ids'] ?? [])
                . ' عامل، ' . count($result['impact']['affected_work_dates'] ?? [])
                . ' يوم، ' . count($result['impact']['affected_requests'] ?? []) . ' طلب.';
        } elseif ($action === 'end_employment' && is_array($result)) {
            $message .= ' تم إلغاء ' . (int) ($result['cancelled_permission_count'] ?? 0) . ' طلب إذن معلق وتحرير حصته.';
        }
        $_SESSION['hr_organization_feedback'] = [
            'type' => 'success',
            'message' => $message,
            'cancelled_permission_count' => is_array($result) ? ($result['cancelled_permission_count'] ?? null) : null,
        ];
    } catch (Throwable $exception) {
        error_log('HR organization command failed: ' . $exception->getMessage());
        $_SESSION['hr_organization_feedback'] = [
            'type' => 'danger',
            'message' => $organizationErrorMessage($exception),
        ];
    }

    $correctionActions = ['preview_correction', 'decide_correction', 'reverse_correction'];
    header('Location: hr_organization.php' . (in_array($action, $correctionActions, true) ? '?tab=corrections' : ''));
    exit;
}

$feedback = $_SESSION['hr_organization_feedback'] ?? null;
unset($_SESSION['hr_organization_feedback']);

$organizationData = [
    'org_units' => [],
    'job_titles' => [],
    'policy_groups' => [],
    'group_memberships' => [],
    'manager_assignments' => [],
    'assignments' => [],
    'staff' => [],
];
$loadError = null;
try {
    $organizationData = $organizationQuery->forAdministrator($actorUserId);
} catch (Throwable $exception) {
    error_log('HR organization read unavailable: ' . $exception->getMessage());
    $loadError = $exception->getMessage() === 'STAFF_ORG_ACTOR_FORBIDDEN'
        ? 'لا تملك صلاحية عرض إدارة الهيكل التنظيمي للعاملين.'
        : 'لا يمكن تحميل بيانات التنظيم الآن. تحقق من تطبيق ترحيلات شؤون العاملين ثم أعد المحاولة.';
}

$corrections = [];
$correctionLoadError = null;
$canApproveCorrections = false;
try {
    $corrections = $correctionService->recentForAdministrator($actorUserId, 50);
    $canApproveCorrections = $correctionService->canApprove($actorUserId);
} catch (Throwable $exception) {
    error_log('HR organization corrections unavailable: ' . $exception->getMessage());
    $correctionLoadError = 'لا يمكن تحميل دورة التصحيحات المؤثرة الآن. تحقق من تطبيق ترحيل التصحيحات ثم أعد المحاولة.';
}
$activeOrganizationTab = (string) ($_GET['tab'] ?? 'units');
if (!in_array($activeOrganizationTab, ['units', 'groups', 'management', 'corrections'], true)) {
    $activeOrganizationTab = 'units';
}

$range = static function (array $row, string $fromField = 'valid_from', string $toField = 'valid_to') use ($h): string {
    $from = $h($row[$fromField] ?? '—');
    $to = $row[$toField] ?? null;

    return $from . ' <span class="text-muted">إلى</span> ' . ($to ? $h($to) : '<span class="text-success">مفتوح</span>');
};
$statusBadge = static function (string $status) use ($h, $statusLabels, $statusClasses): string {
    $label = $statusLabels[$status] ?? $status;
    $class = $statusClasses[$status] ?? 'secondary';

    return '<span class="badge text-bg-' . $h($class) . '">' . $h($label) . '</span>';
};

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom animate-up">
    <div>
        <h1 class="h2 fw-bold text-dark"><i class="fas fa-sitemap me-3 text-primary"></i>التنظيم والتعيينات</h1>
        <p class="text-muted m-0">إدارة القوى والمسميات والمجموعات وعلاقات المديرين والتعيينات المؤرخة.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="hr_center.php" class="btn btn-outline-primary shadow-sm px-3 py-2">
            <i class="fas fa-layer-group me-2"></i>مركز شؤون العاملين
        </a>
        <button type="button" class="btn btn-success shadow px-4 py-2" data-bs-toggle="modal" data-bs-target="#assignmentModal">
            <i class="fas fa-user-plus me-2"></i>تعيين عامل
        </button>
        <button type="button" class="btn btn-primary shadow px-3 py-2" data-bs-toggle="modal" data-bs-target="#transferEmploymentModal">
            <i class="fas fa-people-arrows me-2"></i>نقل عامل
        </button>
        <button type="button" class="btn btn-danger shadow px-3 py-2" data-bs-toggle="modal" data-bs-target="#endEmploymentModal">
            <i class="fas fa-user-slash me-2"></i>إنهاء خدمة
        </button>
    </div>
</div>

<div class="modal fade" id="transferEmploymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium"><form method="post" id="transferEmploymentForm">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>"><input type="hidden" name="action" value="transfer_employment">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-people-arrows me-2"></i>نقل عامل بتعيين مؤرخ</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="alert alert-info"><i class="fas fa-clock-rotate-left me-2"></i>يُغلق التعيين السابق في اليوم السابق ويُنشأ تعيين جديد؛ لا يُعاد كتابة التاريخ.</div><div class="row g-3">
            <div class="col-md-6"><label class="form-label">العامل</label><select class="form-select" name="staff_user_id" required><option value="">اختر العامل</option><?php foreach ($organizationData['staff'] as $staff): ?><option value="<?php echo $h($staff['id']); ?>"><?php echo $h($staff['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">القوة/الوحدة الجديدة</label><select class="form-select" name="org_unit_id" required><option value="">اختر الوحدة</option><?php foreach ($organizationData['org_units'] as $unit): ?><option value="<?php echo $h($unit['id']); ?>"><?php echo $h($unit['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">المسمى الجديد</label><select class="form-select" name="job_title_id" required><option value="">اختر المسمى</option><?php foreach ($organizationData['job_titles'] as $title): ?><option value="<?php echo $h($title['id']); ?>"><?php echo $h($title['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">تاريخ السريان</label><input class="form-control" name="effective_date" type="date" required></div>
            <div class="col-12"><label class="form-label">سبب النقل</label><textarea class="form-control" name="reason" rows="2" maxlength="2000" required></textarea></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ النقل</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="endEmploymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" id="endEmploymentForm">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>"><input type="hidden" name="action" value="end_employment">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-slash me-2"></i>إنهاء خدمة عامل</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-user-lock text-danger" style="font-size:3rem;"></i></div><div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>سيُلغى كل إذن معلق وتُحرر حصته في نفس المعاملة، ولن يتمكن العامل من فتح الخدمات بعد تاريخ الإنهاء.</div><div class="row g-3">
            <div class="col-12"><label class="form-label">العامل</label><select class="form-select" name="staff_user_id" required><option value="">اختر العامل</option><?php foreach ($organizationData['staff'] as $staff): ?><option value="<?php echo $h($staff['id']); ?>"><?php echo $h($staff['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">تاريخ انتهاء الخدمة</label><input class="form-control" name="effective_date" type="date" required></div>
            <div class="col-12"><label class="form-label">سبب الإنهاء</label><textarea class="form-control" name="reason" rows="3" maxlength="2000" required></textarea></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-user-slash me-1"></i>تأكيد إنهاء الخدمة</button></div>
    </form></div></div>
</div>

<?php if (is_array($feedback)): ?>
    <div class="alert alert-<?php echo $h($feedback['type'] ?? 'info'); ?> alert-dismissible fade show" role="alert"<?php if (isset($feedback['cancelled_permission_count'])): ?> data-cancelled-permission-count="<?php echo (int) $feedback['cancelled_permission_count']; ?>"<?php endif; ?>>
        <i class="fas fa-<?php echo ($feedback['type'] ?? '') === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i><?php echo $h($feedback['message'] ?? ''); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<?php if ($loadError !== null): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-database me-2"></i><?php echo $h($loadError); ?>
    </div>
<?php endif; ?>

<div class="alert alert-light border border-primary-subtle d-flex align-items-start gap-3 mb-4" role="note">
    <i class="fas fa-clock-rotate-left text-primary mt-1"></i>
    <div>
        <strong>سجل مؤرخ قابل للمراجعة</strong>
        <div class="small text-muted">كل إضافة تبدأ من تاريخ محدد. عند النقل أو التصحيح أضف فترة لاحقة غير متداخلة بدل تغيير السجل السابق؛ يحمي النظام التاريخ وتقارير الحضور.</div>
    </div>
</div>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-building"></i></div>
            <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo count($organizationData['org_units']); ?>">0</div><div class="stat-card-label">القوى والوحدات</div></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-id-badge"></i></div>
            <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo count($organizationData['job_titles']); ?>">0</div><div class="stat-card-label">المسميات</div></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo count($organizationData['policy_groups']); ?>">0</div><div class="stat-card-label">مجموعات العاملين</div></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-briefcase"></i></div>
            <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo count($organizationData['assignments']); ?>">0</div><div class="stat-card-label">تعيينات محفوظة</div></div>
        </div>
    </div>
</div>

<ul class="nav nav-pills nav-fill gap-2 mb-4" id="organizationTabs" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo $activeOrganizationTab === 'units' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#unitsPane" type="button" role="tab"><i class="fas fa-building me-1"></i>القوى والمسميات</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo $activeOrganizationTab === 'groups' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#groupsPane" type="button" role="tab"><i class="fas fa-users me-1"></i>المجموعات</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo $activeOrganizationTab === 'management' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#managementPane" type="button" role="tab"><i class="fas fa-user-tie me-1"></i>المديرون والتعيينات</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo $activeOrganizationTab === 'corrections' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#correctionsPane" type="button" role="tab"><i class="fas fa-code-branch me-1"></i>التصحيحات المؤثرة</button></li>
</ul>

<div class="tab-content">
    <section class="tab-pane fade <?php echo $activeOrganizationTab === 'units' ? 'show active' : ''; ?>" id="unitsPane" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div><h2 class="h5 mb-1">القوى والمسميات الوظيفية</h2><p class="small text-muted mb-0">ابدأ بالقوة والمسمى قبل إنشاء التعيين.</p></div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#unitModal"><i class="fas fa-plus-circle me-1"></i>قوة/وحدة</button>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#jobTitleModal"><i class="fas fa-plus-circle me-1"></i>مسمى وظيفي</button>
            </div>
        </div>
        <div class="admin-list-surface mb-4">
            <div class="admin-table-wrap table-responsive">
                <table class="table table-hover table-striped admin-data-table mb-0">
                    <thead><tr><th>القوة/الوحدة</th><th>الرمز</th><th>النوع</th><th>فترة السريان</th><th>الحالة</th></tr></thead>
                    <tbody>
                    <?php foreach ($organizationData['org_units'] as $unit): ?>
                        <tr><td class="fw-semibold"><?php echo $h($unit['name'] ?? '—'); ?></td><td><code><?php echo $h($unit['code'] ?? ''); ?></code></td><td><?php echo $h($unit['unit_type'] ?? '—'); ?></td><td><?php echo $range($unit); ?></td><td><?php echo $statusBadge((string) ($unit['status'] ?? '')); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($organizationData['org_units'] === []): ?><tr><td colspan="5" class="text-center text-muted py-4">لا توجد وحدات مسجلة بعد.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="admin-list-surface">
            <div class="admin-table-wrap table-responsive">
                <table class="table table-hover table-striped admin-data-table mb-0">
                    <thead><tr><th>المسمى الوظيفي</th><th>الرمز</th><th>فترة السريان</th><th>الحالة</th></tr></thead>
                    <tbody>
                    <?php foreach ($organizationData['job_titles'] as $title): ?>
                        <tr><td class="fw-semibold"><?php echo $h($title['name'] ?? '—'); ?></td><td><code><?php echo $h($title['code'] ?? ''); ?></code></td><td><?php echo $range($title, 'active_from', 'active_to'); ?></td><td><?php echo $statusBadge((string) ($title['status'] ?? '')); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($organizationData['job_titles'] === []): ?><tr><td colspan="4" class="text-center text-muted py-4">لا توجد مسميات مسجلة بعد.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="tab-pane fade <?php echo $activeOrganizationTab === 'groups' ? 'show active' : ''; ?>" id="groupsPane" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div><h2 class="h5 mb-1">مجموعات العاملين</h2><p class="small text-muted mb-0">تستخدم لاحقًا في سياسات الدوام والأذونات والتقارير.</p></div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#groupModal"><i class="fas fa-plus-circle me-1"></i>مجموعة جديدة</button>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#membershipModal" <?php echo ($organizationData['policy_groups'] === [] || $organizationData['staff'] === []) ? 'disabled' : ''; ?>><i class="fas fa-user-plus me-1"></i>إضافة عضو</button>
            </div>
        </div>
        <div class="admin-list-surface mb-4">
            <div class="admin-table-wrap table-responsive">
                <table class="table table-hover table-striped admin-data-table mb-0">
                    <thead><tr><th>المجموعة</th><th>الرمز</th><th>الغرض</th><th>فترة السريان</th><th>الحالة</th></tr></thead>
                    <tbody>
                    <?php foreach ($organizationData['policy_groups'] as $group): ?>
                        <tr><td class="fw-semibold"><?php echo $h($group['name'] ?? '—'); ?></td><td><code><?php echo $h($group['code'] ?? ''); ?></code></td><td><?php echo $h($group['purpose'] ?? '—'); ?></td><td><?php echo $range($group); ?></td><td><?php echo $statusBadge((string) ($group['status'] ?? '')); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($organizationData['policy_groups'] === []): ?><tr><td colspan="5" class="text-center text-muted py-4">أنشئ مجموعة أولًا ثم أضف العاملين إليها بفترات سريان واضحة.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="admin-list-surface">
            <div class="admin-table-wrap table-responsive">
                <table class="table table-hover table-striped admin-data-table mb-0">
                    <thead><tr><th>العامل</th><th>المجموعة</th><th>فترة العضوية</th><th>الحالة</th></tr></thead>
                    <tbody>
                    <?php foreach ($organizationData['group_memberships'] as $membership): ?>
                        <tr><td class="fw-semibold"><?php echo $h($membership['staff_name'] ?? ('عامل #' . ($membership['staff_user_id'] ?? '—'))); ?></td><td><?php echo $h($membership['group_name'] ?? '—'); ?></td><td><?php echo $range($membership); ?></td><td><?php echo $statusBadge((string) ($membership['status'] ?? '')); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($organizationData['group_memberships'] === []): ?><tr><td colspan="4" class="text-center text-muted py-4">لا توجد عضويات مجموعات محفوظة بعد.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="tab-pane fade <?php echo $activeOrganizationTab === 'management' ? 'show active' : ''; ?>" id="managementPane" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div><h2 class="h5 mb-1">المديرون والتعيينات</h2><p class="small text-muted mb-0">تتحقق الخدمة من تعارض التعيين الأساسي ومن الدورات الإدارية قبل الحفظ.</p></div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#managerModal" <?php echo ($organizationData['staff'] === [] && $organizationData['org_units'] === []) ? 'disabled' : ''; ?>><i class="fas fa-user-tie me-1"></i>تعيين مدير</button>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#assignmentModal" <?php echo ($organizationData['staff'] === [] || $organizationData['org_units'] === [] || $organizationData['job_titles'] === []) ? 'disabled' : ''; ?>><i class="fas fa-user-plus me-1"></i>تعيين عامل</button>
            </div>
        </div>
        <div class="admin-list-surface mb-4">
            <div class="admin-table-wrap table-responsive">
                <table class="table table-hover table-striped admin-data-table mb-0">
                    <thead><tr><th>النطاق</th><th>نوع المدير</th><th>المدير</th><th>الأولوية</th><th>الفترة</th><th>الحالة</th></tr></thead>
                    <tbody>
                    <?php foreach ($organizationData['manager_assignments'] as $manager): ?>
                        <?php $subjectLabel = ($manager['subject_type'] ?? '') === 'staff' ? ($manager['subject_staff_name'] ?? ('عامل #' . ($manager['subject_id'] ?? '—'))) : ($manager['subject_unit_name'] ?? ('وحدة #' . ($manager['subject_id'] ?? '—'))); ?>
                        <tr data-manager-subject-type="<?php echo $h($manager['subject_type'] ?? ''); ?>" data-manager-subject-id="<?php echo (int) ($manager['subject_id'] ?? 0); ?>" data-manager-user-id="<?php echo (int) ($manager['manager_user_id'] ?? 0); ?>" data-manager-kind="<?php echo $h($manager['manager_kind'] ?? ''); ?>" data-manager-valid-from="<?php echo $h($manager['valid_from'] ?? ''); ?>"><td><span class="small text-muted"><?php echo ($manager['subject_type'] ?? '') === 'staff' ? 'عامل' : 'وحدة'; ?></span><br><span class="fw-semibold"><?php echo $h($subjectLabel); ?></span></td><td><?php echo $h($manager['manager_kind'] ?? '—'); ?></td><td><?php echo $h($manager['manager_name'] ?? ('مستخدم #' . ($manager['manager_user_id'] ?? '—'))); ?></td><td><?php echo $h($manager['priority'] ?? 0); ?></td><td><?php echo $range($manager); ?></td><td><?php echo $statusBadge((string) ($manager['status'] ?? '')); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($organizationData['manager_assignments'] === []): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد علاقات مديرين مسجلة بعد.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="admin-list-surface">
            <div class="admin-table-wrap table-responsive">
                <table class="table table-hover table-striped admin-data-table mb-0">
                    <thead><tr><th>العامل</th><th>القوة/الوحدة</th><th>المسمى</th><th>نوع التعيين</th><th>الحالة</th><th>النسبة</th><th>الفترة</th></tr></thead>
                    <tbody>
                    <?php foreach ($organizationData['assignments'] as $assignment): ?>
                        <tr data-assignment-staff-id="<?php echo (int) ($assignment['staff_user_id'] ?? 0); ?>" data-assignment-org-unit-id="<?php echo (int) ($assignment['org_unit_id'] ?? 0); ?>" data-assignment-job-title-id="<?php echo (int) ($assignment['job_title_id'] ?? 0); ?>" data-assignment-valid-from="<?php echo $h($assignment['valid_from'] ?? ''); ?>" data-assignment-status="<?php echo $h($assignment['employment_status'] ?? ''); ?>"><td class="fw-semibold"><?php echo $h($assignment['staff_name'] ?? ('عامل #' . ($assignment['staff_user_id'] ?? '—'))); ?></td><td><?php echo $h($assignment['org_unit_name'] ?? '—'); ?></td><td><?php echo $h($assignment['job_title_name'] ?? '—'); ?></td><td><?php echo $h($assignment['assignment_kind'] ?? '—'); ?></td><td><?php echo $statusBadge((string) ($assignment['employment_status'] ?? '')); ?></td><td><?php echo $h($assignment['work_fraction'] ?? '1'); ?></td><td><?php echo $range($assignment); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($organizationData['assignments'] === []): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد تعيينات جديدة محفوظة بعد.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="tab-pane fade <?php echo $activeOrganizationTab === 'corrections' ? 'show active' : ''; ?>" id="correctionsPane" role="tabpanel">
        <div class="alert alert-warning d-flex align-items-start gap-3" role="note">
            <i class="fas fa-shield-halved mt-1"></i>
            <div>
                <strong>معاينة أولًا، ثم اعتماد مستقل</strong>
                <div class="small">لا يغيّر هذا النموذج السجلات أو التقارير مباشرة. يثبت العاملين والأيام والطلبات والفترات المتأثرة، ثم ينشئ الاعتماد نوايا أثر محددة. مقدم المعاينة لا يستطيع اعتمادها.</div>
            </div>
        </div>

        <?php if ($correctionLoadError !== null): ?>
            <div class="alert alert-warning"><i class="fas fa-database me-2"></i><?php echo $h($correctionLoadError); ?></div>
        <?php endif; ?>

        <div class="card shadow admin-card-surface mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-magnifying-glass-chart me-2"></i>إنشاء معاينة تصحيح مؤثر</h5>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>">
                    <input type="hidden" name="action" value="preview_correction">
                    <input type="hidden" name="idempotency_key" value="<?php echo $h(bin2hex(random_bytes(32))); ?>">
                    <div class="col-md-3">
                        <label class="form-label" for="correctionKind">نوع التصحيح</label>
                        <select class="form-select" id="correctionKind" name="correction_kind" required>
                            <?php foreach ($correctionKindLabels as $key => $label): ?><option value="<?php echo $h($key); ?>"><?php echo $h($label); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="correctionScopeType">نطاق التأثير</label>
                        <select class="form-select" id="correctionScopeType" name="scope_type" required>
                            <?php foreach ($correctionScopeLabels as $key => $label): ?><option value="<?php echo $h($key); ?>"><?php echo $h($label); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="correctionScopeId">معرّف النطاق</label>
                        <input type="number" min="1" class="form-control" id="correctionScopeId" name="scope_id" list="correctionScopeReferences" required>
                        <div class="form-text">اتركه معطلًا عند اختيار كل العاملين.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="correctionReferenceId">معرّف المرجع الجديد</label>
                        <input type="number" min="1" class="form-control" id="correctionReferenceId" name="proposed_reference_id" list="correctionTargetReferences" required>
                    </div>
                    <div class="col-md-3"><label class="form-label" for="correctionFrom">من تاريخ</label><input type="date" class="form-control" id="correctionFrom" name="effective_from" value="<?php echo $h($today); ?>" required></div>
                    <div class="col-md-3"><label class="form-label" for="correctionTo">إلى تاريخ</label><input type="date" class="form-control" id="correctionTo" name="effective_to" value="<?php echo $h($today); ?>" required><div class="form-text">الحد الأقصى سنة واحدة.</div></div>
                    <div class="col-md-6"><label class="form-label" for="correctionReason">سبب التصحيح</label><textarea class="form-control" id="correctionReason" name="reason" rows="2" maxlength="1000" required placeholder="اكتب سببًا واضحًا يمكن مراجعته دون تضمين بيانات حساسة غير لازمة"></textarea></div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass-chart me-1"></i>تثبيت المعاينة</button>
                    </div>
                </form>
                <datalist id="correctionScopeReferences">
                    <?php foreach ($organizationData['staff'] as $staff): ?><option value="<?php echo $h($staff['id']); ?>"><?php echo $h('عامل: ' . $staff['name']); ?></option><?php endforeach; ?>
                    <?php foreach ($organizationData['org_units'] as $unit): ?><option value="<?php echo $h($unit['id']); ?>"><?php echo $h('وحدة: ' . $unit['name']); ?></option><?php endforeach; ?>
                    <?php foreach ($organizationData['policy_groups'] as $group): ?><option value="<?php echo $h($group['id']); ?>"><?php echo $h('مجموعة: ' . $group['name']); ?></option><?php endforeach; ?>
                </datalist>
                <datalist id="correctionTargetReferences">
                    <?php foreach ($organizationData['org_units'] as $unit): ?><option value="<?php echo $h($unit['id']); ?>"><?php echo $h('وحدة: ' . $unit['name']); ?></option><?php endforeach; ?>
                    <?php foreach ($organizationData['job_titles'] as $title): ?><option value="<?php echo $h($title['id']); ?>"><?php echo $h('مسمى: ' . $title['name']); ?></option><?php endforeach; ?>
                    <?php foreach ($organizationData['staff'] as $staff): ?><option value="<?php echo $h($staff['id']); ?>"><?php echo $h('مدير: ' . $staff['name']); ?></option><?php endforeach; ?>
                </datalist>
            </div>
        </div>

        <div class="admin-list-surface">
            <div class="admin-table-wrap table-responsive">
                <table class="table table-hover table-striped admin-data-table align-middle mb-0">
                    <thead><tr><th>#</th><th>التصحيح</th><th>النطاق والفترة</th><th>معاينة الأثر</th><th>الحالة</th><th>الإجراءات</th></tr></thead>
                    <tbody>
                    <?php foreach ($corrections as $correction): ?>
                        <?php
                            $correctionStatus = (string) ($correction['status'] ?? 'previewed');
                            $impact = $correction['impact'] ?? [];
                            $isOwnCorrection = (int) ($correction['requested_by'] ?? 0) === $actorUserId;
                        ?>
                        <tr>
                            <td><?php echo (int) ($correction['correction_id'] ?? 0); ?></td>
                            <td>
                                <span class="fw-semibold"><?php echo $h($correctionKindLabels[$correction['correction_kind']] ?? $correction['correction_kind']); ?></span>
                                <div class="small text-muted"><?php echo ($correction['direction'] ?? 'apply') === 'reverse' ? 'عكس التصحيح #' . (int) ($correction['reverses_correction_id'] ?? 0) : 'مرجع جديد #' . (int) ($correction['proposed_reference_id'] ?? 0); ?></div>
                            </td>
                            <td><?php echo $h($correctionScopeLabels[$correction['scope_type']] ?? $correction['scope_type']); ?><?php echo $correction['scope_id'] ? ' #' . (int) $correction['scope_id'] : ''; ?><div class="small text-muted"><?php echo $h($correction['effective_from']); ?> — <?php echo $h($correction['effective_to']); ?></div></td>
                            <td>
                                <span class="badge text-bg-primary"><?php echo count($impact['affected_staff_ids'] ?? []); ?> عامل</span>
                                <span class="badge text-bg-info"><?php echo count($impact['affected_work_dates'] ?? []); ?> يوم</span>
                                <span class="badge text-bg-warning"><?php echo count($impact['affected_requests'] ?? []); ?> طلب</span>
                                <span class="badge text-bg-secondary"><?php echo count($impact['affected_report_periods'] ?? []); ?> فترة</span>
                            </td>
                            <td><span class="badge text-bg-<?php echo $h($correctionStatusClasses[$correctionStatus] ?? 'secondary'); ?>"><?php echo $h($correctionStatusLabels[$correctionStatus] ?? $correctionStatus); ?></span><?php echo $isOwnCorrection && $correctionStatus === 'previewed' ? '<div class="small text-muted mt-1">يلزم معتمد آخر</div>' : ''; ?></td>
                            <td>
                                <?php if ($correctionStatus === 'previewed' && $canApproveCorrections && !$isOwnCorrection): ?>
                                    <button type="button" class="btn btn-action-pills btn-activate me-1 correction-decision" data-id="<?php echo (int) $correction['correction_id']; ?>" data-version="<?php echo (int) $correction['lock_version']; ?>" data-decision="approved" data-key="<?php echo $h(bin2hex(random_bytes(32))); ?>" data-bs-toggle="tooltip" title="اعتماد"><i class="fas fa-check"></i></button>
                                    <button type="button" class="btn btn-action-pills btn-delete me-1 correction-decision" data-id="<?php echo (int) $correction['correction_id']; ?>" data-version="<?php echo (int) $correction['lock_version']; ?>" data-decision="rejected" data-key="<?php echo $h(bin2hex(random_bytes(32))); ?>" data-bs-toggle="tooltip" title="رفض"><i class="fas fa-times"></i></button>
                                <?php elseif ($correctionStatus === 'approved' && ($correction['direction'] ?? 'apply') === 'apply'): ?>
                                    <button type="button" class="btn btn-action-pills btn-edit correction-reversal" data-id="<?php echo (int) $correction['correction_id']; ?>" data-key="<?php echo $h(bin2hex(random_bytes(32))); ?>" data-bs-toggle="tooltip" title="إنشاء عكس مدقق"><i class="fas fa-rotate-left"></i></button>
                                <?php else: ?>
                                    <span class="text-muted small">لا يوجد إجراء متاح</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($corrections === []): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد معاينات تصحيح محفوظة بعد.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="unitModal" tabindex="-1" aria-labelledby="unitModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium"><form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>"><input type="hidden" name="action" value="create_unit">
        <div class="modal-header"><h5 class="modal-title" id="unitModalTitle"><i class="fas fa-building me-2"></i>إضافة قوة أو وحدة تنظيمية</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="unitName">اسم القوة/الوحدة</label><input class="form-control" id="unitName" name="name" required maxlength="200"></div>
            <div class="col-md-6"><label class="form-label" for="unitCode">رمز ثابت</label><input class="form-control text-uppercase" id="unitCode" name="code" required maxlength="80" pattern="[A-Za-z0-9_-]{2,80}" placeholder="HR-TEAM"><div class="form-text">حروف إنجليزية وأرقام وشرطة/شرطة سفلية فقط.</div></div>
            <div class="col-md-4"><label class="form-label" for="unitType">النوع</label><input class="form-control" id="unitType" name="unit_type" required maxlength="50" pattern="[A-Za-z][A-Za-z0-9_-]{1,49}" placeholder="department"></div>
            <div class="col-md-4"><label class="form-label" for="unitFrom">تاريخ السريان</label><input type="date" class="form-control" id="unitFrom" name="valid_from" value="<?php echo $h($today); ?>" required></div>
            <div class="col-md-4"><label class="form-label" for="unitTo">تاريخ الانتهاء</label><input type="date" class="form-control" id="unitTo" name="valid_to"></div>
            <div class="col-md-6"><label class="form-label" for="unitParent">الوحدة الأم (اختياري)</label><select class="form-select" id="unitParent" name="parent_id"><option value="">بدون وحدة أم</option><?php foreach ($organizationData['org_units'] as $unit): ?><option value="<?php echo $h($unit['id']); ?>"><?php echo $h($unit['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="unitStatus">الحالة</label><select class="form-select" id="unitStatus" name="status"><option value="active">نشط</option><option value="inactive">غير نشط</option><option value="retired">منتهٍ</option></select></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ الوحدة</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="jobTitleModal" tabindex="-1" aria-labelledby="jobTitleModalTitle" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium"><form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>"><input type="hidden" name="action" value="create_job_title">
        <div class="modal-header"><h5 class="modal-title" id="jobTitleModalTitle"><i class="fas fa-id-badge me-2"></i>إضافة مسمى وظيفي</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" for="titleName">اسم المسمى</label><input class="form-control" id="titleName" name="name" required maxlength="200"></div>
            <div class="col-12"><label class="form-label" for="titleCode">رمز ثابت</label><input class="form-control text-uppercase" id="titleCode" name="code" required maxlength="80" pattern="[A-Za-z0-9_-]{2,80}" placeholder="HR-OFFICER"></div>
            <div class="col-md-6"><label class="form-label" for="titleFrom">تاريخ السريان</label><input type="date" class="form-control" id="titleFrom" name="active_from" value="<?php echo $h($today); ?>" required></div>
            <div class="col-md-6"><label class="form-label" for="titleTo">تاريخ الانتهاء</label><input type="date" class="form-control" id="titleTo" name="active_to"></div>
            <div class="col-12"><label class="form-label" for="titleStatus">الحالة</label><select class="form-select" id="titleStatus" name="status"><option value="active">نشط</option><option value="inactive">غير نشط</option><option value="retired">منتهٍ</option></select></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ المسمى</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="groupModal" tabindex="-1" aria-labelledby="groupModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium"><form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>"><input type="hidden" name="action" value="create_group">
        <div class="modal-header"><h5 class="modal-title" id="groupModalTitle"><i class="fas fa-users me-2"></i>إنشاء مجموعة عاملين</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="groupName">اسم المجموعة</label><input class="form-control" id="groupName" name="name" required maxlength="200"></div>
            <div class="col-md-6"><label class="form-label" for="groupCode">رمز ثابت</label><input class="form-control text-uppercase" id="groupCode" name="code" required maxlength="80" pattern="[A-Za-z0-9_-]{2,80}" placeholder="MORNING-SHIFT"></div>
            <div class="col-12"><label class="form-label" for="groupPurpose">الغرض (اختياري)</label><textarea class="form-control" id="groupPurpose" name="purpose" rows="2" maxlength="500" placeholder="مثال: سياسة دوام أو فئة تقارير"></textarea></div>
            <div class="col-md-4"><label class="form-label" for="groupFrom">تاريخ السريان</label><input type="date" class="form-control" id="groupFrom" name="valid_from" value="<?php echo $h($today); ?>" required></div>
            <div class="col-md-4"><label class="form-label" for="groupTo">تاريخ الانتهاء</label><input type="date" class="form-control" id="groupTo" name="valid_to"></div>
            <div class="col-md-4"><label class="form-label" for="groupStatus">الحالة</label><select class="form-select" id="groupStatus" name="status"><option value="active">نشط</option><option value="inactive">غير نشط</option><option value="retired">منتهٍ</option></select></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ المجموعة</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="membershipModal" tabindex="-1" aria-labelledby="membershipModalTitle" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium"><form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>"><input type="hidden" name="action" value="add_group_member">
        <div class="modal-header"><h5 class="modal-title" id="membershipModalTitle"><i class="fas fa-user-plus me-2"></i>إضافة عامل إلى مجموعة</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" for="membershipGroup">المجموعة</label><select class="form-select" id="membershipGroup" name="group_id" required><option value="">اختر المجموعة</option><?php foreach ($organizationData['policy_groups'] as $group): ?><option value="<?php echo $h($group['id']); ?>"><?php echo $h($group['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label" for="membershipStaff">العامل</label><select class="form-select" id="membershipStaff" name="staff_user_id" required><option value="">اختر العامل</option><?php foreach ($organizationData['staff'] as $staff): ?><option value="<?php echo $h($staff['id']); ?>"><?php echo $h($staff['name']); ?><?php echo ($staff['account_status'] ?? 'active') !== 'active' ? ' — حساب غير نشط' : ''; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="membershipFrom">تاريخ السريان</label><input type="date" class="form-control" id="membershipFrom" name="valid_from" value="<?php echo $h($today); ?>" required></div>
            <div class="col-md-6"><label class="form-label" for="membershipTo">تاريخ الانتهاء</label><input type="date" class="form-control" id="membershipTo" name="valid_to"></div>
            <div class="col-12"><label class="form-label" for="membershipStatus">الحالة</label><select class="form-select" id="membershipStatus" name="status"><option value="active">نشطة</option><option value="suspended">موقوفة</option><option value="retired">منتهية</option></select></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ العضوية</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="managerModal" tabindex="-1" aria-labelledby="managerModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium"><form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>"><input type="hidden" name="action" value="assign_manager">
        <div class="modal-header"><h5 class="modal-title" id="managerModalTitle"><i class="fas fa-user-tie me-2"></i>تعيين مدير</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="managerSubjectType">النطاق</label><select class="form-select" id="managerSubjectType" name="subject_type" required><option value="staff">عامل محدد</option><option value="org_unit">قوة/وحدة</option></select></div>
            <div class="col-md-8" data-manager-subject="staff"><label class="form-label" for="managerStaffSubject">العامل الخاضع</label><select class="form-select" id="managerStaffSubject" name="subject_staff_id"><option value="">اختر العامل</option><?php foreach ($organizationData['staff'] as $staff): ?><option value="<?php echo $h($staff['id']); ?>"><?php echo $h($staff['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-8 d-none" data-manager-subject="org_unit"><label class="form-label" for="managerUnitSubject">القوة/الوحدة الخاضعة</label><select class="form-select" id="managerUnitSubject" name="subject_org_unit_id"><option value="">اختر القوة/الوحدة</option><?php foreach ($organizationData['org_units'] as $unit): ?><option value="<?php echo $h($unit['id']); ?>"><?php echo $h($unit['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="managerUser">المدير</label><select class="form-select" id="managerUser" name="manager_user_id" required><option value="">اختر المدير</option><?php foreach ($organizationData['staff'] as $staff): ?><?php if (($staff['account_status'] ?? '') === 'active'): ?><option value="<?php echo $h($staff['id']); ?>"><?php echo $h($staff['name']); ?></option><?php endif; ?><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="managerKind">نوع المدير</label><select class="form-select" id="managerKind" name="manager_kind"><option value="direct">مباشر</option><option value="administrative">إداري</option><option value="hr">موارد بشرية</option></select></div>
            <div class="col-md-3"><label class="form-label" for="managerPriority">الأولوية</label><input type="number" class="form-control" id="managerPriority" name="priority" min="0" max="32767" value="0" required></div>
            <div class="col-md-4"><label class="form-label" for="managerFrom">تاريخ السريان</label><input type="date" class="form-control" id="managerFrom" name="valid_from" value="<?php echo $h($today); ?>" required></div>
            <div class="col-md-4"><label class="form-label" for="managerTo">تاريخ الانتهاء</label><input type="date" class="form-control" id="managerTo" name="valid_to"></div>
            <div class="col-md-4"><label class="form-label" for="managerStatus">الحالة</label><select class="form-select" id="managerStatus" name="status"><option value="active">نشطة</option><option value="suspended">موقوفة</option><option value="retired">منتهية</option></select></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ علاقة المدير</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="assignmentModal" tabindex="-1" aria-labelledby="assignmentModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium"><form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>"><input type="hidden" name="action" value="create_assignment">
        <div class="modal-header"><h5 class="modal-title" id="assignmentModalTitle"><i class="fas fa-briefcase me-2"></i>تعيين عامل</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="assignmentStaff">العامل</label><select class="form-select" id="assignmentStaff" name="staff_user_id" required><option value="">اختر العامل</option><?php foreach ($organizationData['staff'] as $staff): ?><option value="<?php echo $h($staff['id']); ?>"><?php echo $h($staff['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="assignmentUnit">القوة/الوحدة</label><select class="form-select" id="assignmentUnit" name="org_unit_id" required><option value="">اختر القوة/الوحدة</option><?php foreach ($organizationData['org_units'] as $unit): ?><option value="<?php echo $h($unit['id']); ?>"><?php echo $h($unit['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="assignmentTitle">المسمى الوظيفي</label><select class="form-select" id="assignmentTitle" name="job_title_id" required><option value="">اختر المسمى</option><?php foreach ($organizationData['job_titles'] as $title): ?><option value="<?php echo $h($title['id']); ?>"><?php echo $h($title['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="assignmentKind">نوع التعيين</label><select class="form-select" id="assignmentKind" name="assignment_kind"><option value="primary">أساسي</option><option value="secondary">ثانوي</option><option value="temporary">مؤقت</option></select></div>
            <div class="col-md-3"><label class="form-label" for="assignmentStatus">الحالة الوظيفية</label><select class="form-select" id="assignmentStatus" name="employment_status"><option value="active">نشط</option><option value="suspended">موقوف</option><option value="ended">منتهٍ</option><option value="rehired">عاد للخدمة</option></select></div>
            <div class="col-md-4"><label class="form-label" for="assignmentFraction">نسبة العمل</label><input type="number" step="0.0001" min="0.0001" max="1" class="form-control" id="assignmentFraction" name="work_fraction" value="1" required></div>
            <div class="col-md-4"><label class="form-label" for="assignmentFrom">تاريخ السريان</label><input type="date" class="form-control" id="assignmentFrom" name="valid_from" value="<?php echo $h($today); ?>" required></div>
            <div class="col-md-4"><label class="form-label" for="assignmentTo">تاريخ الانتهاء</label><input type="date" class="form-control" id="assignmentTo" name="valid_to"><div class="form-text">إلزامي للتعيين المؤقت أو المنتهي.</div></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ التعيين</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="correctionDecisionModal" tabindex="-1" aria-labelledby="correctionDecisionModalTitle" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-view"><form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="action" value="decide_correction">
        <input type="hidden" name="correction_id" id="correctionDecisionId">
        <input type="hidden" name="expected_lock_version" id="correctionDecisionVersion">
        <input type="hidden" name="decision" id="correctionDecisionValue">
        <input type="hidden" name="idempotency_key" id="correctionDecisionKey">
        <div class="modal-header"><h5 class="modal-title" id="correctionDecisionModalTitle"><i class="fas fa-shield-halved me-2"></i>قرار التصحيح المؤثر</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <div class="text-center mb-3"><i class="fas fa-code-branch text-primary" style="font-size:3rem;"></i></div>
            <p class="text-center" id="correctionDecisionPrompt">راجع نطاق التأثير قبل حفظ القرار النهائي.</p>
            <div class="alert alert-warning"><i class="fas fa-info-circle me-2"></i>الاعتماد ينشئ نوايا إعادة احتساب وإعادة توجيه محددة، ولا يحذف أي سجل تاريخي.</div>
            <label class="form-label" for="correctionDecisionComment">ملاحظة القرار (اختيارية)</label>
            <textarea class="form-control" id="correctionDecisionComment" name="comment" rows="2" maxlength="1000"></textarea>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary" id="correctionDecisionSubmit"><i class="fas fa-check me-1"></i>حفظ القرار</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="correctionReversalModal" tabindex="-1" aria-labelledby="correctionReversalModalTitle" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-view"><form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="action" value="reverse_correction">
        <input type="hidden" name="correction_id" id="correctionReversalId">
        <input type="hidden" name="idempotency_key" id="correctionReversalKey">
        <div class="modal-header"><h5 class="modal-title" id="correctionReversalModalTitle"><i class="fas fa-rotate-left me-2"></i>إنشاء عكس مدقق</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <div class="text-center mb-3"><i class="fas fa-rotate-left text-warning" style="font-size:3rem;"></i></div>
            <p class="text-center">سينشئ النظام معاينة عكسية جديدة بنفس النطاق المثبت. لن يُحذف التصحيح الأصلي.</p>
            <label class="form-label" for="correctionReversalReason">سبب العكس</label>
            <textarea class="form-control" id="correctionReversalReason" name="reason" rows="3" maxlength="1000" required></textarea>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass-chart me-1"></i>إنشاء معاينة العكس</button></div>
    </form></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var subjectType = document.getElementById('managerSubjectType');
    var applyManagerSubject = function () {
        var selected = subjectType ? subjectType.value : 'staff';
        document.querySelectorAll('[data-manager-subject]').forEach(function (element) {
            element.classList.toggle('d-none', element.getAttribute('data-manager-subject') !== selected);
        });
    };
    if (subjectType) {
        subjectType.addEventListener('change', applyManagerSubject);
        applyManagerSubject();
    }

    var correctionScopeType = document.getElementById('correctionScopeType');
    var correctionScopeId = document.getElementById('correctionScopeId');
    var syncCorrectionScope = function () {
        var isGlobal = correctionScopeType && correctionScopeType.value === 'global';
        if (correctionScopeId) {
            correctionScopeId.disabled = isGlobal;
            correctionScopeId.required = !isGlobal;
            if (isGlobal) {
                correctionScopeId.value = '';
            }
        }
    };
    if (correctionScopeType) {
        correctionScopeType.addEventListener('change', syncCorrectionScope);
        syncCorrectionScope();
    }

    var decisionModalElement = document.getElementById('correctionDecisionModal');
    var decisionModal = decisionModalElement ? new bootstrap.Modal(decisionModalElement) : null;
    document.querySelectorAll('.correction-decision').forEach(function (button) {
        button.addEventListener('click', function () {
            var decision = this.dataset.decision;
            document.getElementById('correctionDecisionId').value = this.dataset.id;
            document.getElementById('correctionDecisionVersion').value = this.dataset.version;
            document.getElementById('correctionDecisionValue').value = decision;
            document.getElementById('correctionDecisionKey').value = this.dataset.key;
            document.getElementById('correctionDecisionComment').value = '';
            document.getElementById('correctionDecisionPrompt').textContent = decision === 'approved'
                ? 'هل راجعت أعداد العاملين والأيام والطلبات والفترات وتريد اعتماد التصحيح؟'
                : 'هل تريد رفض التصحيح ومنع نشر أي أثر له؟';
            var submit = document.getElementById('correctionDecisionSubmit');
            submit.className = decision === 'approved' ? 'btn btn-primary' : 'btn btn-danger';
            submit.innerHTML = decision === 'approved'
                ? '<i class="fas fa-check me-1"></i>اعتماد التصحيح'
                : '<i class="fas fa-ban me-1"></i>رفض التصحيح';
            decisionModal.show();
        });
    });

    var reversalModalElement = document.getElementById('correctionReversalModal');
    var reversalModal = reversalModalElement ? new bootstrap.Modal(reversalModalElement) : null;
    document.querySelectorAll('.correction-reversal').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('correctionReversalId').value = this.dataset.id;
            document.getElementById('correctionReversalKey').value = this.dataset.key;
            document.getElementById('correctionReversalReason').value = '';
            reversalModal.show();
        });
    });

    document.querySelectorAll('#organizationTabs [data-bs-toggle="tab"]').forEach(function (button) {
        button.addEventListener('shown.bs.tab', function () {
            var tab = this.getAttribute('data-bs-target').replace('#', '').replace('Pane', '');
            var url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        });
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
