<?php
/**
 * إدارة الجزاءات والتأديب
 */
$page_title = "الجزاءات والتأديب";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/pagination.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/Staff/bootstrap.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$staffHrFlags = \EduCore\Modules\Staff\Infrastructure\StaffHrFeatureFlags::fromEnvironment();
$showsCaseSurface = $staffHrFlags->exposesNewResults();
$legacySurfaceAvailable = $staffHrFlags->usesLegacyFallback();

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$user = new User($db);

$actionTypes = [
    'verbal_warning' => 'إنذار شفهي',
    'written_warning' => 'إنذار كتابي',
    'salary_deduction' => 'خصم من الراتب',
    'suspension' => 'إيقاف عن العمل',
    'demotion' => 'تخفيض درجة',
    'termination' => 'إنهاء خدمة',
    'other' => 'أخرى'
];
$typeBadges = [
    'verbal_warning' => 'warning',
    'written_warning' => 'orange',
    'salary_deduction' => 'danger',
    'suspension' => 'dark',
    'demotion' => 'secondary',
    'termination' => 'danger',
    'other' => 'info'
];

// يبقى اختيار العامل مطلوباً فقط لمسار السجل التاريخي المتوافق.
$staffList = [];
if ($legacySurfaceAvailable) {
    $staffStmt = $db->query("SELECT u.id, COALESCE(NULLIF(sp.full_name_ar, ''), u.name) AS name
        FROM users u
        JOIN staff_profiles sp ON sp.user_id = u.id
        WHERE (u.role IS NULL OR u.role NOT IN ('admin','student'))
          AND u.status = 'active'
        ORDER BY name");
    $staffList = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
}

$editRecord = null;

// معالجة النماذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    $caseIntent = trim((string)($_POST['discipline_case_intent'] ?? ''));
    if ($caseIntent !== '') {
        try {
            $caseService = (new \EduCore\Modules\Staff\Infrastructure\StaffModuleFactory(
                $db,
                new \EduCore\Modules\Operations\Audit\AuditService($db)
            ))->disciplineAppeals();
            $actorId = (int)($_SESSION['user_id'] ?? 0);
            if ($caseIntent === 'activate_interim') {
                $caseService->activateInterimMeasure([
                    'actor_id' => $actorId,
                    'measure_id' => $_POST['measure_id'] ?? null,
                    'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
                ]);
                $_SESSION['success_message'] = 'تم اعتماد الإجراء المؤقت مع حفظ فصل طالب الإجراء عن معتمده.';
            } elseif ($caseIntent === 'decide_reopen') {
                $caseService->decideReopen([
                    'actor_id' => $actorId,
                    'request_event_id' => $_POST['request_event_id'] ?? null,
                    'expected_case_lock_version' => $_POST['expected_case_lock_version'] ?? null,
                    'outcome' => $_POST['outcome'] ?? null,
                    'idempotency_key' => $_POST['idempotency_key'] ?? null,
                ]);
                $_SESSION['success_message'] = ($_POST['outcome'] ?? '') === 'authorized'
                    ? 'تمت الموافقة على إعادة فتح القضية دون محو القرار السابق.'
                    : 'تم رفض طلب إعادة الفتح مع بقاء كامل السجل.';
            } else {
                throw new DomainException('DISCIPLINE_ACCESS_DENIED');
            }
        } catch (Throwable $e) {
            error_log('disciplinary case command failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر تنفيذ إجراء القضية (' . preg_replace('/[^A-Z0-9_]/', '', $e->getMessage()) . ').';
        }
        header('Location: disciplinary.php');
        exit();
    }
    if (isset($_POST['save_action'])) {
        if (!$legacySurfaceAvailable) {
            $_SESSION['error_message'] = 'تم إيقاف تعديل السجل التاريخي بعد التحويل الرسمي. أنشئ واقعة جديدة عبر مسار القضية المعتمد.';
            header("Location: disciplinary.php");
            exit();
        }
        $data = [
            'user_id' => (int)$_POST['user_id'],
            'action_type' => $_POST['action_type'],
            'action_date' => $_POST['action_date'],
            'reason' => trim($_POST['reason']),
            'penalty' => trim($_POST['penalty']),
            'duration' => trim($_POST['duration']),
            'issued_by' => trim($_POST['issued_by']),
            'notes' => trim($_POST['notes'])
        ];

        try {
            if ($data['user_id'] <= 0 || !isset($actionTypes[$data['action_type']])) {
                throw new InvalidArgumentException('Disciplinary legacy input is invalid.');
            }
            $db->beginTransaction();
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);
            $staffNameStmt = $db->prepare('SELECT name FROM users WHERE id = ?');
            $staffNameStmt->execute([$data['user_id']]);
            $staffName = (string)($staffNameStmt->fetchColumn() ?: ('موظف #' . $data['user_id']));
            if (!empty($_POST['edit_id'])) {
                $recordId = (int)$_POST['edit_id'];
                $oldStmt = $db->prepare('SELECT * FROM staff_disciplinary WHERE id = ? FOR UPDATE');
                $oldStmt->execute([$recordId]);
                $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);
                if (!$oldData) {
                    throw new RuntimeException('Disciplinary record not found.');
                }
                $stmt = $db->prepare("UPDATE staff_disciplinary SET user_id=?, action_type=?, action_date=?, reason=?, penalty=?, duration=?, issued_by=?, notes=? WHERE id=?");
                $stmt->execute([$data['user_id'], $data['action_type'], $data['action_date'], $data['reason'], $data['penalty'], $data['duration'], $data['issued_by'], $data['notes'], $recordId]);
                $newStmt = $db->prepare('SELECT * FROM staff_disciplinary WHERE id = ?');
                $newStmt->execute([$recordId]);
                $audit->recordUpdate('discipline', 'staff_disciplinary', $recordId, $staffName, $oldData, $newStmt->fetch(PDO::FETCH_ASSOC) ?: [], 'تعديل إجراء تأديبي');
                $_SESSION['success_message'] = 'تم تحديث الإجراء التأديبي';
            } else {
                $stmt = $db->prepare("INSERT INTO staff_disciplinary (user_id, action_type, action_date, reason, penalty, duration, issued_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$data['user_id'], $data['action_type'], $data['action_date'], $data['reason'], $data['penalty'], $data['duration'], $data['issued_by'], $data['notes']]);
                $recordId = (int)$db->lastInsertId();
                $newStmt = $db->prepare('SELECT * FROM staff_disciplinary WHERE id = ?');
                $newStmt->execute([$recordId]);
                $audit->recordInsert('discipline', 'staff_disciplinary', $recordId, $staffName, $newStmt->fetch(PDO::FETCH_ASSOC) ?: [], 'إضافة إجراء تأديبي');
                $_SESSION['success_message'] = 'تم إضافة الإجراء التأديبي';
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('disciplinary save error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر حفظ الإجراء التأديبي.';
        }
        header("Location: disciplinary.php");
        exit();
    }

    if (isset($_POST['delete_action'])) {
        // اسم الإجراء محفوظ لتوافق الروابط والنماذج القديمة، لكن السجل
        // التأديبي لا يُحذف مادياً. التصحيح يجري بقضية/قرار لاحق مدقق.
        $_SESSION['error_message'] = 'لا يمكن حذف سجل تأديبي. استخدم مسار القضية أو الإلغاء المسبب للحفاظ على سلامة السجل.';
        header("Location: disciplinary.php");
        exit();
    }
}

// تعديل سجل
if ($legacySurfaceAvailable && isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM staff_disciplinary WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
}

// الفلاتر
$filterUser = $_GET['user_id'] ?? '';
$filterType = $_GET['filter_type'] ?? '';
$records = [];
$stats = [];
$pagination = paginationState(0, 50);
if ($legacySurfaceAvailable) {
    $where = "1=1";
    $params = [];
    if ($filterUser) { $where .= " AND d.user_id = ?"; $params[] = (int)$filterUser; }
    if ($filterType) { $where .= " AND d.action_type = ?"; $params[] = $filterType; }
    if (!empty($_GET['date_from'])) { $where .= " AND d.action_date >= ?"; $params[] = $_GET['date_from']; }
    if (!empty($_GET['date_to'])) { $where .= " AND d.action_date <= ?"; $params[] = $_GET['date_to']; }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM staff_disciplinary d WHERE $where");
    $countStmt->execute($params);
    $pagination = paginationState((int)$countStmt->fetchColumn(), 50);
    // قيمتان صحيحتان مضمونتان بـ (int) — الاستيفاء المباشر آمن لـ LIMIT/OFFSET
    // لأن PDO في وضع emulate-prepares يُقتبس قيم bound params ويُسبب خطأ 1064.
    $limit  = max(1, (int)$pagination['limit']);
    $offset = max(0, (int)$pagination['offset']);
    $stmt = $db->prepare("SELECT d.*, COALESCE(NULLIF(sp.full_name_ar, ''), u.name) as staff_name
        FROM staff_disciplinary d
        JOIN users u ON d.user_id = u.id
        LEFT JOIN staff_profiles sp ON sp.user_id = u.id
        WHERE $where
        ORDER BY d.action_date DESC
        LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // إحصائيات الأرشيف المتوافق، لا تُخلط بعدادات القضية الجديدة.
    $statsStmt = $db->query("SELECT action_type, COUNT(*) as cnt FROM staff_disciplinary GROUP BY action_type");
    $stats = $statsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$caseStatuses = [
    'reported' => 'مسجلة',
    'triage' => 'فرز أولي',
    'under_investigation' => 'قيد التحقيق',
    'pending_decision' => 'بانتظار القرار',
    'decided' => 'صدر القرار',
    'appeal_pending' => 'تظلم قيد المراجعة',
    'upheld' => 'تم تأييد القرار',
    'amended' => 'تم تعديل القرار',
    'revoked' => 'تم إلغاء القرار',
    'closed' => 'مغلقة',
    'reopened' => 'أعيد فتحها',
    'cancelled' => 'ملغاة',
];
$caseConfidentialityLabels = [
    'normal' => 'عادية',
    'restricted' => 'مقيدة',
    'highly_restricted' => 'شديدة التقييد',
];
$caseRecords = [];
$casePagination = paginationState(0, 50);
$caseSurfaceError = null;
$selectedCase = null;
$caseFilters = [
    'status' => $_GET['case_status'] ?? '',
    'confidentiality_level' => 'normal',
    'date_from' => $_GET['case_date_from'] ?? '',
    'date_to' => $_GET['case_date_to'] ?? '',
];
if ($showsCaseSurface) {
    try {
        $caseQuery = (new \EduCore\Modules\Staff\Infrastructure\StaffModuleFactory(
            $db,
            new \EduCore\Modules\Operations\Audit\AuditService($db)
        ))->disciplineCaseAdminQuery();
        $caseTotal = (int)$caseQuery->paginated($caseFilters, 1, 0)['total'];
        $casePagination = paginationState($caseTotal, 50);
        $caseResult = $caseQuery->paginated(
            $caseFilters,
            max(1, (int)$casePagination['limit']),
            max(0, (int)$casePagination['offset'])
        );
        $caseRecords = $caseResult['items'];
        $caseOperations = (new \EduCore\Modules\Staff\Infrastructure\StaffModuleFactory(
            $db,
            new \EduCore\Modules\Operations\Audit\AuditService($db)
        ))->disciplineCaseOperationsQuery()->forCaseIds(array_column($caseRecords, 'id'));
        foreach ($caseRecords as &$caseRecord) {
            $caseRecord += $caseOperations[(int)$caseRecord['id']] ?? [];
        }
        unset($caseRecord);
        $caseFilters = $caseResult['filters'];
        $selectedCaseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT);
        if ($selectedCaseId !== false && $selectedCaseId !== null && (int)$selectedCaseId > 0) {
            $selectedCase = $caseQuery->summary((int)$selectedCaseId);
        }
    } catch (Throwable $e) {
        error_log('disciplinary case surface unavailable: ' . $e->getMessage());
        $caseSurfaceError = 'تعذر تحميل فهرس القضايا الجديد حالياً. بقي السجل التاريخي متاحاً وفق وضع التشغيل.';
    }
}

require_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-gavel me-2 text-primary"></i>القضايا التأديبية والجزاءات</h1>
    <div class="admin-top-actions no-print">
        <?php if ($showsCaseSurface): ?>
            <a href="#disciplineCasesSurface" class="btn btn-outline-primary shadow-sm">
                <i class="fas fa-folder-open me-1"></i>فهرس القضايا
            </a>
        <?php endif; ?>
        <?php if ($legacySurfaceAvailable): ?>
        <button type="button" class="btn btn-success shadow px-4 py-2" onclick="openAddDisciplinaryModal()">
            <i class="fas fa-plus-circle me-1"></i>إضافة سجل تاريخي
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($showsCaseSurface): ?>
<section id="disciplineCasesSurface" class="mb-4" aria-labelledby="disciplineCasesTitle">
    <div class="alert alert-info d-flex align-items-start gap-2" role="status">
        <i class="fas fa-shield-alt mt-1"></i>
        <div>
            <strong id="disciplineCasesTitle">فهرس القضايا الجديد</strong>
            <div class="small">يعرض هذا الفهرس القضايا العادية ومؤشراتها التشغيلية فقط. لا تظهر الأسباب أو الأدلة أو نص القرار أو المرفقات، ولا تظهر القضايا المقيدة حتى تتوافر صلاحية عرض قضية محددة.</div>
        </div>
    </div>
    <?php if (!$legacySurfaceAvailable): ?>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-lock me-2"></i>وضع التحويل الرسمي مفعل: السجل التاريخي للجزاءات أصبح للقراءة والتتبع فقط، ولا يقبل إضافة أو تعديل أو حذفاً مادياً.
        </div>
    <?php endif; ?>

    <?php if ($caseSurfaceError): ?>
        <div class="alert alert-warning mb-0" role="alert">
            <i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($caseSurfaceError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php else: ?>
        <?php if ($selectedCase): ?>
            <div class="admin-list-surface mb-3">
                <div class="p-3 d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <div class="small text-muted mb-1">ملخص قضية مقيد</div>
                        <h2 class="h5 mb-1"><i class="fas fa-folder-open me-2 text-primary"></i><?php echo htmlspecialchars((string)$selectedCase['case_no'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($caseStatuses[$selectedCase['status']] ?? (string)$selectedCase['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="badge bg-dark"><?php echo htmlspecialchars($caseConfidentialityLabels[$selectedCase['confidentiality_level']] ?? (string)$selectedCase['confidentiality_level'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="small text-muted">
                        <div>تحقيقات: <?php echo (int)$selectedCase['investigation_count']; ?> · قرارات: <?php echo (int)$selectedCase['decision_count']; ?> · تظلمات: <?php echo (int)$selectedCase['appeal_count']; ?></div>
                        <?php if (($selectedCase['confidentiality_level'] ?? '') === 'normal' && !empty($selectedCase['subject_display_name'])): ?>
                            <div class="mt-1">العامل: <?php echo htmlspecialchars((string)$selectedCase['subject_display_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php else: ?>
                            <div class="mt-1"><i class="fas fa-eye-slash me-1"></i>بيانات العامل محجوبة في الفهرس العام.</div>
                        <?php endif; ?>
                    </div>
                    <a href="disciplinary.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-right me-1"></i>العودة للفهرس</a>
                </div>
            </div>
        <?php endif; ?>

        <form method="GET" class="admin-filter-bar mb-3" novalidate>
            <div class="admin-filter-controls">
                <select class="form-select form-select-sm admin-inline-select-sm" name="case_status" aria-label="فلترة حالة القضية">
                    <option value="">كل الحالات</option>
                    <?php foreach ($caseStatuses as $statusKey => $statusLabel): ?>
                        <option value="<?php echo htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($caseFilters['status'] ?? '') === $statusKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" class="form-control form-control-sm flatpickr-date admin-inline-select-sm" name="case_date_from" value="<?php echo htmlspecialchars((string)($caseFilters['date_from'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="فتحت من" style="width: 140px;">
                <input type="text" class="form-control form-control-sm flatpickr-date admin-inline-select-sm" name="case_date_to" value="<?php echo htmlspecialchars((string)($caseFilters['date_to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="فتحت إلى" style="width: 140px;">
            </div>
            <div class="admin-filter-actions">
                <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
                <a href="disciplinary.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
            </div>
        </form>

        <div class="admin-list-surface">
            <?php if ($caseRecords !== []): ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped admin-data-table" id="disciplineCasesTable">
                        <thead>
                            <tr>
                                <th>رقم القضية</th>
                                <th>الحالة</th>
                                <th>السرية</th>
                                <th>فتحت في</th>
                                <th>العامل</th>
                                <th>مؤشرات المسار</th>
                                <th class="text-center" width="70">عرض</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($caseRecords as $caseRecord): ?>
                                <tr data-discipline-case-no="<?php echo htmlspecialchars((string)$caseRecord['case_no'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-case-id="<?php echo (int)$caseRecord['id']; ?>"
                                    data-case-lock="<?php echo (int)$caseRecord['lock_version']; ?>"
                                    data-decision-id="<?php echo (int)($caseRecord['decision_id'] ?? 0); ?>"
                                    data-evidence-id="<?php echo (int)($caseRecord['evidence_id'] ?? 0); ?>"
                                    data-interim-id="<?php echo (int)($caseRecord['interim_id'] ?? 0); ?>"
                                    data-interim-status="<?php echo htmlspecialchars((string)($caseRecord['interim_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-reopen-request-id="<?php echo (int)($caseRecord['reopen_request_id'] ?? 0); ?>">
                                    <td class="fw-bold text-primary"><?php echo htmlspecialchars((string)$caseRecord['case_no'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($caseStatuses[$caseRecord['status']] ?? (string)$caseRecord['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><span class="badge bg-dark"><?php echo htmlspecialchars($caseConfidentialityLabels[$caseRecord['confidentiality_level']] ?? (string)$caseRecord['confidentiality_level'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?php echo htmlspecialchars((string)$caseRecord['opened_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if (($caseRecord['confidentiality_level'] ?? '') === 'normal' && !empty($caseRecord['subject_display_name'])): ?>
                                            <?php echo htmlspecialchars((string)$caseRecord['subject_display_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="fas fa-eye-slash me-1"></i>بيانات مقيدة</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">تحقيقات <?php echo (int)$caseRecord['investigation_count']; ?> · قرارات <?php echo (int)$caseRecord['decision_count']; ?> · تظلمات <?php echo (int)$caseRecord['appeal_count']; ?></td>
                                    <td class="text-center actions-column admin-table-actions">
                                        <a href="disciplinary.php?case_id=<?php echo (int)$caseRecord['id']; ?>" class="btn btn-action-pills btn-edit" data-bs-toggle="tooltip" title="عرض ملخص القضية">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (($caseRecord['interim_status'] ?? '') === 'draft'): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="discipline_case_intent" value="activate_interim">
                                                <input type="hidden" name="measure_id" value="<?php echo (int)$caseRecord['interim_id']; ?>">
                                                <input type="hidden" name="expected_lock_version" value="<?php echo (int)$caseRecord['interim_lock_version']; ?>">
                                                <button type="submit" class="btn btn-action-pills btn-activate" data-bs-toggle="tooltip" title="اعتماد الإجراء المؤقت"><i class="fas fa-shield-halved"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ((int)($caseRecord['reopen_request_id'] ?? 0) > 0): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="discipline_case_intent" value="decide_reopen">
                                                <input type="hidden" name="request_event_id" value="<?php echo (int)$caseRecord['reopen_request_id']; ?>">
                                                <input type="hidden" name="expected_case_lock_version" value="<?php echo (int)$caseRecord['lock_version']; ?>">
                                                <input type="hidden" name="outcome" value="authorized">
                                                <input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars(bin2hex(random_bytes(16)), ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-action-pills btn-activate" data-bs-toggle="tooltip" title="الموافقة على إعادة الفتح"><i class="fas fa-folder-open"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php renderPagination($casePagination); ?>
            <?php else: ?>
                <div class="alert alert-info m-3 mb-0"><i class="fas fa-info-circle me-2"></i>لا توجد قضايا تأديبية تطابق الفلاتر الحالية.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($legacySurfaceAvailable): ?>
<!-- Stat Cards -->
<div class="dashboard-canvas sortable-dashboard mb-4">
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="stat-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($stats['verbal_warning'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">إنذارات شفهية</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                <div class="stat-card-icon"><i class="fas fa-file-signature"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($stats['written_warning'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">إنذارات كتابية</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <div class="stat-card-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($stats['salary_deduction'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">خصومات من الراتب</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-clipboard-list"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)array_sum($stats); ?>">0</div>
                    <div class="stat-card-label">إجمالي الإجراءات</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="admin-filter-bar mb-3" novalidate>
    <div class="admin-filter-controls">
        <select class="form-select form-select-sm admin-inline-select-sm" name="user_id" aria-label="فلترة الموظف">
            <option value="">كل الموظفين</option>
            <?php foreach ($staffList as $s): ?>
                <option value="<?php echo $s['id']; ?>" <?php echo $filterUser == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm admin-inline-select-sm" name="filter_type" aria-label="فلترة نوع الإجراء">
            <option value="">كل الأنواع</option>
            <?php foreach ($actionTypes as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $filterType === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" class="form-control form-control-sm flatpickr-date admin-inline-select-sm" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="من تاريخ" style="width: 140px;">
        <input type="text" class="form-control form-control-sm flatpickr-date admin-inline-select-sm" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="إلى تاريخ" style="width: 140px;">
    </div>
    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
        <?php if ($filterUser || $filterType || !empty($_GET['date_from']) || !empty($_GET['date_to'])): ?>
            <a href="disciplinary.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
        <?php endif; ?>
    </div>
</form>

<!-- Table Surface -->
<div class="admin-list-surface mb-4">
    <?php if (count($records) > 0): ?>
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped admin-data-table" id="disciplinaryTable">
            <thead>
                <tr>
                    <th width="40">#</th>
                    <th>الموظف</th>
                    <th>نوع الإجراء</th>
                    <th>التاريخ</th>
                    <th>السبب</th>
                    <th>العقوبة</th>
                    <th>المدة</th>
                    <th>صادر من</th>
                    <th class="text-center" width="100">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $i => $r): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td class="fw-bold text-primary"><?php echo htmlspecialchars($r['staff_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-<?php echo $typeBadges[$r['action_type']] ?? 'secondary'; ?>"><?php echo $actionTypes[$r['action_type']] ?? $r['action_type']; ?></span></td>
                    <td><?php echo htmlspecialchars($r['action_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(mb_strimwidth($r['reason'], 0, 60, '...'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($r['penalty'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($r['duration'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($r['issued_by'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-center actions-column admin-table-actions">
                        <button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل الإجراء"
                            onclick="openEditDisciplinaryModal(<?php echo htmlspecialchars(json_encode($r, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="tooltip" title="السجل محمي من الحذف"
                            onclick="openDisciplinaryRecordProtectedModal(<?php echo htmlspecialchars(json_encode((string)$r['staff_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($r['action_date'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                            <i class="fas fa-lock"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php renderPagination($pagination); ?>
    <?php else: ?>
        <div class="alert alert-info m-3"><i class="fas fa-info-circle me-2"></i>لا توجد إجراءات تأديبية مسجلة.</div>
    <?php endif; ?>
</div>

<!-- Modal الإضافة / التعديل -->
<div class="modal fade" id="disciplinaryModal" tabindex="-1" aria-labelledby="disciplinaryModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium <?php echo $editRecord ? 'admin-modal-edit' : 'admin-modal-create'; ?>" id="discModalContent">
            <form method="POST" id="disciplinaryForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="edit_id" id="discEditId" value="<?php echo $editRecord['id'] ?? ''; ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="disciplinaryModalTitle">
                        <i class="fas fa-<?php echo $editRecord ? 'edit' : 'plus-circle'; ?> me-2" id="discModalIcon"></i>
                        <span id="discModalTitleText"><?php echo $editRecord ? 'تعديل إجراء تأديبي' : 'إضافة إجراء تأديبي جديد'; ?></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="discUserId">الموظف <span class="text-danger">*</span></label>
                            <select class="form-select" name="user_id" id="discUserId" required>
                                <option value="">اختر الموظف</option>
                                <?php foreach ($staffList as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo ($editRecord['user_id'] ?? '') == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="discActionType">نوع الإجراء <span class="text-danger">*</span></label>
                            <select class="form-select" name="action_type" id="discActionType" required>
                                <option value="">اختر النوع</option>
                                <?php foreach ($actionTypes as $k => $v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo ($editRecord['action_type'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="discActionDate">تاريخ الإجراء <span class="text-danger">*</span></label>
                            <input type="text" class="form-control flatpickr-date" name="action_date" id="discActionDate" value="<?php echo $editRecord['action_date'] ?? date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="discIssuedBy">صادر من</label>
                            <input type="text" class="form-control" name="issued_by" id="discIssuedBy" value="<?php echo htmlspecialchars($editRecord['issued_by'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="اسم مصدر القرار">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="discReason">السبب <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="reason" id="discReason" rows="2" required placeholder="سبب الإجراء التأديبي"><?php echo htmlspecialchars($editRecord['reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="discPenalty">العقوبة / الجزاء</label>
                            <input type="text" class="form-control" name="penalty" id="discPenalty" value="<?php echo htmlspecialchars($editRecord['penalty'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="مثال: خصم 3 أيام">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="discDuration">المدة</label>
                            <input type="text" class="form-control" name="duration" id="discDuration" value="<?php echo htmlspecialchars($editRecord['duration'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="مثال: 3 أيام">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="discNotes">ملاحظات</label>
                            <input type="text" class="form-control" name="notes" id="discNotes" value="<?php echo htmlspecialchars($editRecord['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="save_action" class="btn btn-success" id="discSaveBtn">
                        <i class="fas fa-save me-1"></i><span id="discSaveBtnText"><?php echo $editRecord ? 'تحديث' : 'حفظ'; ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="disciplinaryRecordProtectedModal" tabindex="-1" aria-labelledby="disciplinaryRecordProtectedTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium">
            <div class="modal-header">
                <h5 class="modal-title" id="disciplinaryRecordProtectedTitle"><i class="fas fa-lock me-2"></i>السجل التأديبي محمي</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-shield-alt text-warning" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center mb-2">سجل الموظف <span class="fw-bold text-primary" id="protectedDisciplinaryName"></span> بتاريخ <span class="fw-bold" id="protectedDisciplinaryDate"></span> لا يُحذف مادياً.</p>
                <div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>إذا كان السجل التجريبي غير صحيح، عالجه عبر قضية موثقة أو قرار لاحق؛ يبقى الأصل قابلاً للمراجعة والتدقيق.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>فهمت</button>
            </div>
        </div>
    </div>
</div>

<script>
function openAddDisciplinaryModal() {
    var form = document.getElementById('disciplinaryForm');
    if (form) form.reset();
    document.getElementById('discEditId').value = '';
    document.getElementById('discActionDate').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('discModalTitleText').textContent = 'إضافة إجراء تأديبي جديد';
    document.getElementById('discModalIcon').className = 'fas fa-plus-circle me-2';
    document.getElementById('discSaveBtnText').textContent = 'حفظ';
    var saveBtn = document.getElementById('discSaveBtn');
    if (saveBtn) {
        saveBtn.className = 'btn btn-success';
    }
    var modalContent = document.getElementById('discModalContent');
    if (modalContent) {
        modalContent.className = 'modal-content admin-modal admin-modal-premium admin-modal-create';
    }
    var modalEl = document.getElementById('disciplinaryModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function openEditDisciplinaryModal(record) {
    if (!record) return;
    document.getElementById('discEditId').value = record.id || '';
    document.getElementById('discUserId').value = record.user_id || '';
    document.getElementById('discActionType').value = record.action_type || '';
    document.getElementById('discActionDate').value = record.action_date || '';
    document.getElementById('discIssuedBy').value = record.issued_by || '';
    document.getElementById('discReason').value = record.reason || '';
    document.getElementById('discPenalty').value = record.penalty || '';
    document.getElementById('discDuration').value = record.duration || '';
    document.getElementById('discNotes').value = record.notes || '';

    document.getElementById('discModalTitleText').textContent = 'تعديل إجراء تأديبي';
    document.getElementById('discModalIcon').className = 'fas fa-edit me-2';
    document.getElementById('discSaveBtnText').textContent = 'تحديث';
    var saveBtn = document.getElementById('discSaveBtn');
    if (saveBtn) {
        saveBtn.className = 'btn btn-primary';
    }
    var modalContent = document.getElementById('discModalContent');
    if (modalContent) {
        modalContent.className = 'modal-content admin-modal admin-modal-premium admin-modal-edit';
    }
    var modalEl = document.getElementById('disciplinaryModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function openDisciplinaryRecordProtectedModal(name, date) {
    document.getElementById('protectedDisciplinaryName').textContent = name || '-';
    document.getElementById('protectedDisciplinaryDate').textContent = date || '-';
    var modalEl = document.getElementById('disciplinaryRecordProtectedModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if ($editRecord): ?>
    var modalEl = document.getElementById('disciplinaryModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    <?php endif; ?>

    if (typeof $ !== 'undefined' && $.fn.DataTable && !$.fn.DataTable.isDataTable('#disciplinaryTable')) {
        $('#disciplinaryTable').DataTable({
            pageLength: 50,
            order: [[3, 'desc']],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json',
                search: "بحث:", lengthMenu: "عرض _MENU_ سجل",
                info: "عرض _START_ إلى _END_ من _TOTAL_ سجل",
                paginate: { first: "الأول", last: "الأخير", next: "التالي", previous: "السابق" }
            }
        });
    }
});
</script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
