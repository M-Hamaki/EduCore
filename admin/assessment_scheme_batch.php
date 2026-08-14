<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AssessmentSchemeBatchService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$isBatchEmbed = isset($_GET['embed']);
$isBatchStandalone = isset($_GET['standalone']);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$isBatchEmbed && !$isBatchStandalone) {
    header('Location: assessment_schemes.php?open_batch=1');
    exit;
}

$database = new Database();
$db = $database->getConnection();
$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = (string) ($currentAcademicYear['name'] ?? '');
$successMessage = null;
$errorMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $isBatchStandalone) {
    $successMessage = $_SESSION['success_message'] ?? null;
    $errorMessage = $_SESSION['error_message'] ?? null;
    unset($_SESSION['success_message'], $_SESSION['error_message']);
}

function scheme_batch_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function scheme_batch_is_ajax(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function scheme_batch_public_error(Throwable $error): string
{
    $cursor = $error;
    do {
        if ($cursor instanceof PDOException) {
            error_log('Assessment scheme batch database operation failed: ' . $error->getMessage());
            return 'تعذر تنفيذ العملية في قاعدة البيانات. لم يتم حفظ أي تغييرات جزئية.';
        }
        $cursor = $cursor->getPrevious();
    } while ($cursor instanceof Throwable);

    if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
        return $error->getMessage();
    }

    error_log('Assessment scheme batch operation failed [' . get_class($error) . ']: ' . $error->getMessage());
    return 'تعذر تنفيذ العملية. راجع البيانات ثم حاول مرة أخرى.';
}

/** @param array<string,mixed> $preview */
function scheme_batch_preview_signature(int $academicYearId, array $preview): string
{
    $csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
    if ($csrfToken === '') {
        throw new RuntimeException('انتهت جلسة الأمان. أعد تحميل الصفحة ثم حاول مرة أخرى.');
    }

    $familyKeys = array_values(array_map('strval', (array) ($preview['family_request_keys'] ?? [])));
    sort($familyKeys, SORT_STRING);
    if ($familyKeys === []) {
        throw new RuntimeException('تعذر تثبيت بيانات المعاينة. راجع الإنشاء مرة أخرى.');
    }

    $payload = json_encode([
        'academic_year_id' => $academicYearId,
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'request_key' => (string) ($preview['request_key'] ?? ''),
        'family_request_keys' => $familyKeys,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return hash_hmac('sha256', $payload, $csrfToken);
}

function scheme_batch_schema_ready(PDO $db): bool
{
    foreach (['assessment_schemes', 'assessment_scheme_families', 'assessment_scheme_scopes', 'assessment_annual_policies', 'assessment_annual_policy_terms'] as $table) {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            return false;
        }
    }
    foreach (['family_id', 'readiness_status', 'readiness_reason', 'batch_id'] as $column) {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['assessment_schemes', $column]);
        if (!$stmt->fetchColumn()) {
            return false;
        }
    }
    $scopeIdentityStmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $scopeIdentityStmt->execute(['assessment_scheme_scopes', 'scope_identity']);
    if (!$scopeIdentityStmt->fetchColumn()) {
        return false;
    }
    return true;
}

$form = [
    'request_key' => hash('sha256', random_bytes(32)),
    'name' => '',
    'subject_id' => '',
    'term_ids' => [],
    'scopes' => [],
    'template_scheme_id' => '',
    'total_grade' => '100',
    'pass_grade' => '',
    'counts_in_total' => '1',
    'normal_absence_policy' => 'zero',
    'excused_absence_policy' => 'exclude',
    'rounding_mode' => 'none',
    'rounding_scope' => 'total',
    'scale_template_components' => '1',
    'annual_weights' => [],
];
$preview = null;
$previewSignature = null;
$schemaReady = scheme_batch_schema_ready($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_replace($form, $_POST);
    foreach (['counts_in_total', 'scale_template_components', 'enable_excused_absence', 'rounding_enabled', 'annual_enabled'] as $checkboxField) {
        $form[$checkboxField] = isset($_POST[$checkboxField]) ? '1' : '0';
    }
    try {
        if (!$schemaReady) {
            throw new RuntimeException('ميزة الخطط الجماعية تحتاج تطبيق ترقية قاعدة البيانات أولًا.');
        }
        if ($currentAcademicYearId <= 0) {
            throw new RuntimeException('لا يوجد عام دراسي نشط حاليًا.');
        }
        $service = new AssessmentSchemeBatchService($db);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'preview_batch') {
            $preview = $service->preview($currentAcademicYearId, $form);
        } elseif ($action === 'create_batch') {
            $preview = $service->preview($currentAcademicYearId, $form);
            $previewSignature = scheme_batch_preview_signature($currentAcademicYearId, $preview);
            $submittedSignature = strtolower(trim((string) ($_POST['preview_signature'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $submittedSignature)
                || !hash_equals($previewSignature, $submittedSignature)) {
                throw new RuntimeException('تم تعديل بيانات النموذج بعد المعاينة. راجع الإنشاء مرة أخرى قبل الحفظ.');
            }
            $result = $service->create($currentAcademicYearId, $form, (int) ($_SESSION['user_id'] ?? 0) ?: null);
            $count = count($result['scheme_ids']);
            $successMessage = $result['idempotent']
                ? "هذا الطلب موجود بالفعل ويحتوي على {$count} خطة."
                : "تم إنشاء {$count} خطة درجات كمسودات بنجاح.";
            $_SESSION['success_message'] = $successMessage;
            $redirectUrl = 'assessment_schemes.php?batch=' . rawurlencode($result['batch_id']);
            if (scheme_batch_is_ajax()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success' => true,
                    'redirect' => $redirectUrl,
                    'message' => $successMessage,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            header('Location: ' . $redirectUrl);
            exit;
        }
    } catch (Throwable $error) {
        $errorMessage = scheme_batch_public_error($error);
    }
}

if ($preview !== null && $previewSignature === null) {
    try {
        $previewSignature = scheme_batch_preview_signature($currentAcademicYearId, $preview);
    } catch (Throwable $error) {
        $preview = null;
        $errorMessage = scheme_batch_public_error($error);
    }
}

$subjects = $db->query("SELECT id, name FROM subjects WHERE COALESCE(is_active, 1) = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$terms = [];
if ($currentAcademicYearId > 0) {
    $termStmt = $db->prepare("SELECT id, name, term_order FROM academic_terms WHERE academic_year_id = ? AND status = 'active' ORDER BY term_order, id");
    $termStmt->execute([$currentAcademicYearId]);
    $terms = $termStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY stage_id, grade_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$classes = $db->query("SELECT id, name, grade_id FROM classes WHERE status = 'active' ORDER BY grade_id, display_order, name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$existingSchemes = [];
if ($currentAcademicYearId > 0) {
    $templateStmt = $db->prepare("SELECT scheme.id, scheme.subject_id, scheme.total_grade, scheme.name AS scheme_name,
            subject.name AS subject_name, grade.grade_name, term.name AS term_name
        FROM assessment_schemes scheme
        JOIN subjects subject ON subject.id = scheme.subject_id
        JOIN grades grade ON grade.id = scheme.grade_id
        JOIN academic_terms term ON term.id = scheme.term_id
        WHERE scheme.academic_year_id = ?
        ORDER BY subject.name, grade.grade_order, term.term_order, scheme.name");
    $templateStmt->execute([$currentAcademicYearId]);
    $existingSchemes = $templateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$gradesByStage = [];
$classesByGrade = [];
foreach ($grades as $grade) {
    $gradesByStage[(int) $grade['stage_id']][] = $grade;
}
foreach ($classes as $class) {
    $classesByGrade[(int) $class['grade_id']][] = $class;
}
$selectedTermIds = array_map('intval', (array) ($form['term_ids'] ?? []));

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-layer-group me-2 text-primary"></i>إنشاء خطط درجات جماعية</h1>
    <div class="admin-top-actions">
        <a class="btn btn-outline-secondary" href="assessment_schemes.php"><i class="fas fa-arrow-right me-2"></i>العودة إلى خطط الدرجات</a>
    </div>
</div>

<div id="assessmentSchemeBatchContent">
<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo scheme_batch_h($successMessage); ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-triangle-exclamation me-2"></i><?php echo scheme_batch_h($errorMessage); ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>
<?php endif; ?>

<?php if (!$schemaReady): ?>
    <div class="alert alert-warning"><i class="fas fa-database me-2"></i>طبّق ترقية قاعدة البيانات أولًا لتفعيل إنشاء الخطط الجماعية. لا يتم تغيير الخطط القديمة تلقائيًا.</div>
<?php elseif ($currentAcademicYearId <= 0): ?>
    <div class="alert alert-warning"><i class="fas fa-calendar-xmark me-2"></i>لا يوجد عام دراسي نشط لإنشاء الخطط فيه.</div>
<?php else: ?>
    <div class="alert alert-info"><i class="fas fa-circle-info me-2"></i>ينشئ النموذج خطة مستقلة لكل ترم وصف مختار. يمكن إنشاء المسودات قبل ربط المادة؛ ستظل غير قابلة للتفعيل حتى يكتمل الربط وتطابق بنود التقييم مجموع الخطة.</div>

    <form method="post" action="assessment_scheme_batch.php" id="assessmentSchemeBatchForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="request_key" value="<?php echo scheme_batch_h($form['request_key']); ?>">
        <?php if ($previewSignature !== null): ?><input type="hidden" name="preview_signature" value="<?php echo scheme_batch_h($previewSignature); ?>"><?php endif; ?>

        <div class="card shadow-sm admin-card-surface mb-3">
            <div class="d-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 bg-light rounded border-start border-3 border-primary">
                <span class="fw-bold text-dark"><i class="fas fa-sliders text-primary me-2"></i>بيانات الخطة</span>
            </div>
            <div class="card-body pt-1">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label" for="batchSubject">المادة <span class="text-danger">*</span></label>
                        <select class="form-select" name="subject_id" id="batchSubject" required>
                            <option value="">اختر المادة</option>
                            <?php foreach ($subjects as $subject): ?><option value="<?php echo (int) $subject['id']; ?>" <?php echo (int) $form['subject_id'] === (int) $subject['id'] ? 'selected' : ''; ?>><?php echo scheme_batch_h($subject['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="batchName">اسم الخطة <span class="text-danger">*</span></label>
                        <input class="form-control" type="text" name="name" id="batchName" maxlength="190" required value="<?php echo scheme_batch_h($form['name']); ?>" placeholder="مثال: خطة أعمال السنة">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="batchTemplate">نسخ بنود من خطة سابقة <span class="text-muted">(اختياري)</span></label>
                        <select class="form-select" name="template_scheme_id" id="batchTemplate">
                            <option value="">بدون قالب — أضيف البنود لاحقًا</option>
                            <?php foreach ($existingSchemes as $scheme): ?><option value="<?php echo (int) $scheme['id']; ?>" <?php echo (int) $form['template_scheme_id'] === (int) $scheme['id'] ? 'selected' : ''; ?> data-subject="<?php echo (int) $scheme['subject_id']; ?>"><?php echo scheme_batch_h($scheme['subject_name'] . ' — ' . $scheme['grade_name'] . ' — ' . $scheme['term_name'] . ' — ' . $scheme['scheme_name']); ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">يُنسخ المحتوى فقط؛ قواعد الأسابيع لا تنسخ إلى ترم مختلف.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="batchTotal">المجموع الكلي</label>
                        <input class="form-control" type="number" name="total_grade" id="batchTotal" min="0.01" max="100000" step="0.01" required value="<?php echo scheme_batch_h($form['total_grade']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="batchPass">درجة النجاح <span class="text-muted">(اختياري)</span></label>
                        <input class="form-control" type="number" name="pass_grade" id="batchPass" min="0" step="0.01" value="<?php echo scheme_batch_h($form['pass_grade']); ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="scale_template_components" id="batchScaleTemplate" value="1" <?php echo !empty($form['scale_template_components']) ? 'checked' : ''; ?>><label class="form-check-label" for="batchScaleTemplate">تحجيم بنود القالب لمجموع الخطة</label></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm admin-card-surface mb-3">
            <div class="d-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 bg-light rounded border-start border-3 border-primary">
                <span class="fw-bold text-dark"><i class="fas fa-calendar-days text-primary me-2"></i>الترمات</span>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small" id="batchSelectAllTerms"><i class="fas fa-check-double me-1"></i>تحديد كل الترمات</button>
            </div>
            <div class="card-body pt-1">
                <div class="row g-2">
                    <?php foreach ($terms as $term): ?>
                        <?php $termId = (int) $term['id']; ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="p-2 px-3 rounded border bg-light-subtle d-flex align-items-center h-100 batch-term-card" data-term-id="<?php echo $termId; ?>">
                                <input class="form-check-input batch-term me-2 mt-0" type="checkbox" name="term_ids[]" id="term<?php echo $termId; ?>" value="<?php echo $termId; ?>" <?php echo in_array($termId, $selectedTermIds, true) ? 'checked' : ''; ?> autocomplete="off">
                                <label class="form-check-label fw-semibold text-dark cursor-pointer flex-grow-1 mb-0" for="term<?php echo $termId; ?>">
                                    <?php echo scheme_batch_h($term['name']); ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm admin-card-surface mb-3">
            <div class="d-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 bg-light rounded border-start border-3 border-primary">
                <span class="fw-bold text-dark"><i class="fas fa-scale-balanced text-primary me-2"></i>سياسات الدرجات ونهاية العام</span>
            </div>
            <div class="card-body pt-1">
                <div class="row g-3 mb-3">
                    <div class="col-md-4"><label class="form-label">الغياب العادي</label><select class="form-select" name="normal_absence_policy"><option value="zero" <?php echo ($form['normal_absence_policy'] ?? '') === 'zero' ? 'selected' : ''; ?>>الغياب = صفر</option><option value="exclude" <?php echo ($form['normal_absence_policy'] ?? '') === 'exclude' ? 'selected' : ''; ?>>استبعاد الغياب</option><option value="note" <?php echo ($form['normal_absence_policy'] ?? '') === 'note' ? 'selected' : ''; ?>>ملاحظة فقط</option></select></div>
                    <div class="col-md-4"><label class="form-label">الغياب بعذر</label><select class="form-select" name="excused_absence_policy"><option value="exclude" <?php echo ($form['excused_absence_policy'] ?? '') === 'exclude' ? 'selected' : ''; ?>>استبعاد بعذر</option><option value="zero" <?php echo ($form['excused_absence_policy'] ?? '') === 'zero' ? 'selected' : ''; ?>>بعذر = صفر</option><option value="note" <?php echo ($form['excused_absence_policy'] ?? '') === 'note' ? 'selected' : ''; ?>>بعذر كملاحظة</option></select></div>
                    <div class="col-md-4"><label class="form-label">التقريب</label><select class="form-select" name="rounding_mode"><option value="none" <?php echo ($form['rounding_mode'] ?? '') === 'none' ? 'selected' : ''; ?>>بدون تقريب</option><option value="nearest_half" <?php echo ($form['rounding_mode'] ?? '') === 'nearest_half' ? 'selected' : ''; ?>>أقرب نصف</option><option value="integer" <?php echo ($form['rounding_mode'] ?? '') === 'integer' ? 'selected' : ''; ?>>رقم صحيح</option><option value="two_decimals" <?php echo ($form['rounding_mode'] ?? '') === 'two_decimals' ? 'selected' : ''; ?>>منزلتان</option></select></div>
                </div>
                <div class="form-check mb-3" data-annual-control>
                    <input class="form-check-input" type="checkbox" name="annual_enabled" id="annualEnabled" value="1" aria-describedby="annualEligibilityHelp" aria-controls="annualWeights annualWeightSummary" <?php echo !empty($form['annual_enabled']) ? 'checked' : ''; ?> <?php echo count($selectedTermIds) < 2 ? 'disabled' : ''; ?>>
                    <label class="form-check-label fw-semibold" for="annualEnabled">إنشاء نتيجة سنوية مجمعة للترمات المحددة</label>
                    <div class="form-text" id="annualEligibilityHelp">اختر ترمين على الأقل لإتاحة النتيجة السنوية وإدخال أوزان الترمات.</div>
                </div>
                <div class="row g-3 d-none" id="annualWeights">
                    <?php foreach ($terms as $term): ?>
                        <div class="col-sm-6 col-lg-3" data-annual-term-id="<?php echo (int) $term['id']; ?>"><label class="form-label" for="annualWeight<?php echo (int) $term['id']; ?>"><?php echo scheme_batch_h($term['name']); ?> (%)</label><input class="form-control annual-weight-input" type="number" name="annual_weights[<?php echo (int) $term['id']; ?>]" id="annualWeight<?php echo (int) $term['id']; ?>" min="0" max="100" step="0.001" value="<?php echo scheme_batch_h(($form['annual_weights'][$term['id']] ?? '')); ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="alert py-2 mt-3 mb-0 d-none" id="annualWeightSummary" role="status" aria-live="polite">
                    <i class="fas fa-calculator me-2"></i>مجموع الأوزان: <strong><span id="annualWeightTotal">0</span>%</strong>
                    <span class="ms-2" id="annualWeightStatus"></span>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="counts_in_total" id="batchCountsTotal" value="1" <?php echo !empty($form['counts_in_total']) ? 'checked' : ''; ?>><label class="form-check-label" for="batchCountsTotal">يدخل في المجموع</label></div></div>
                    <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="enable_excused_absence" id="batchExcused" value="1" <?php echo !empty($form['enable_excused_absence']) ? 'checked' : ''; ?>><label class="form-check-label" for="batchExcused">تفعيل الغياب بعذر</label></div></div>
                    <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="rounding_enabled" id="batchRounding" value="1" <?php echo !empty($form['rounding_enabled']) ? 'checked' : ''; ?>><label class="form-check-label" for="batchRounding">تفعيل التقريب</label></div></div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm admin-card-surface mb-3">
            <div class="d-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 bg-light rounded border-start border-3 border-primary">
                <span class="fw-bold text-dark"><i class="fas fa-school text-primary me-2"></i>الصفوف والفصول</span>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small" id="batchSelectAllGrades"><i class="fas fa-check-double me-1"></i>تحديد كل الصفوف</button>
            </div>
            <div class="card-body pt-1">
                <?php foreach ($stages as $stage): ?>
                    <?php
                    $stageId = (int) $stage['id'];
                    $stageGrades = $gradesByStage[$stageId] ?? [];
                    if ($stageGrades === []) continue;
                    ?>
                    <div class="stage-group assignment-stage-group mb-3" data-stage-id="<?php echo $stageId; ?>">
                        <div class="d-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 rounded border bg-light shadow-sm">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-graduation-cap text-primary"></i>
                                <span class="fw-bold text-dark"><?php echo scheme_batch_h($stage['stage_name']); ?></span>
                                <span class="badge bg-secondary"><?php echo count($stageGrades); ?> صفوف</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small select-assignment-stage-btn">
                                <i class="fas fa-check-double me-1"></i>تحديد المرحلة
                            </button>
                        </div>

                        <div class="row g-2">
                            <?php foreach ($stageGrades as $grade): ?>
                                <?php
                                $gradeId = (int) $grade['id'];
                                $scopeValue = is_array($form['scopes'][$gradeId] ?? null) ? $form['scopes'][$gradeId] : [];
                                $allClasses = !empty($scopeValue['all_classes']);
                                $selectedClasses = array_map('intval', (array) ($scopeValue['class_ids'] ?? []));
                                $gradeClasses = $classesByGrade[$gradeId] ?? [];
                                ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="border rounded-3 p-2 bg-white shadow-sm h-100 assignment-grade-card" data-grade-id="<?php echo $gradeId; ?>">
                                        <div class="d-flex align-items-center justify-content-between gap-2 p-2 mb-2 bg-light rounded border-start border-3 border-primary">
                                            <div class="d-flex align-items-center gap-2">
                                                <input class="form-check-input batch-grade-all mt-0" type="checkbox" name="scopes[<?php echo $gradeId; ?>][all_classes]" id="gradeAll<?php echo $gradeId; ?>" value="1" data-grade="<?php echo $gradeId; ?>" data-stage-id="<?php echo $stageId; ?>" <?php echo $allClasses ? 'checked' : ''; ?> autocomplete="off">
                                                <label class="fw-bold text-dark cursor-pointer mb-0" for="gradeAll<?php echo $gradeId; ?>"><?php echo scheme_batch_h($grade['grade_name']); ?></label>
                                                <span class="badge bg-light text-dark border assignment-grade-scope-badge">غير محدد</span>
                                            </div>
                                        </div>

                                        <div class="px-2 py-1 assignment-class-options" data-grade-id="<?php echo $gradeId; ?>">
                                            <?php if (!empty($gradeClasses)): ?>
                                                <div class="row row-cols-2 g-2">
                                                    <?php foreach ($gradeClasses as $class): ?>
                                                        <?php $classId = (int) $class['id']; ?>
                                                        <div class="col">
                                                            <div class="form-check mb-1">
                                                                <input class="form-check-input batch-grade-class" type="checkbox" name="scopes[<?php echo $gradeId; ?>][class_ids][]" id="class<?php echo $classId; ?>" value="<?php echo $classId; ?>" data-grade="<?php echo $gradeId; ?>" <?php echo $allClasses ? 'disabled' : ''; ?> <?php echo in_array($classId, $selectedClasses, true) ? 'checked' : ''; ?> autocomplete="off">
                                                                <label class="form-check-label small fw-semibold cursor-pointer mb-0" for="class<?php echo $classId; ?>">
                                                                    <?php echo scheme_batch_h($class['name']); ?>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-muted small p-1">لا توجد فصول نشطة حاليًا؛ اختيار الصف بالكامل سيشمل الفصول التي تُنشأ لاحقًا.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($preview !== null): ?>
            <div class="card shadow-sm admin-card-surface mb-3 border-primary" data-batch-preview>
                <div class="d-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 bg-light rounded border-start border-3 border-primary">
                    <span class="fw-bold text-dark"><i class="fas fa-clipboard-check text-primary me-2"></i>مراجعة قبل الإنشاء</span>
                </div>
                <div class="card-body pt-1">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><div class="border rounded p-3 text-center"><div class="h4 mb-1"><?php echo count($preview['grades']) * count($preview['terms']); ?></div><div class="text-muted">خطة مستقلة ستُنشأ</div></div></div>
                        <div class="col-md-4"><div class="border rounded p-3 text-center"><div class="h4 mb-1"><?php echo count($preview['grades']); ?></div><div class="text-muted">صفوف محددة</div></div></div>
                        <div class="col-md-4"><div class="border rounded p-3 text-center"><div class="h4 mb-1"><?php echo count($preview['missing_links']); ?></div><div class="text-muted">نطاقات تنتظر ربط المادة</div></div></div>
                    </div>
                    <?php if (!empty($preview['missing_links'])): ?><div class="alert alert-warning mb-0"><i class="fas fa-link-slash me-2"></i>ستُنشأ الخطط المطلوبة كمسودات، وسيمنع التفعيل فقط في النطاقات التي لم يُربط بها نفس المادة بعد.</div><?php else: ?><div class="alert alert-success mb-0"><i class="fas fa-check-circle me-2"></i>ربط المادة متاح لجميع النطاقات المختارة. تحتاج الخطط إلى بنود متوازنة قبل التفعيل إذا لم يُنسخ قالب.</div><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-4" data-batch-form-actions>
            <a class="btn btn-secondary" href="assessment_schemes.php"><i class="fas fa-times me-1"></i>إلغاء</a>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary" type="submit" name="action" value="preview_batch"><i class="fas fa-eye me-1"></i>مراجعة الإنشاء</button>
                <?php if ($preview !== null): ?><button class="btn btn-success" type="submit" name="action" value="create_batch"><i class="fas fa-plus-circle me-1"></i>إنشاء الخطط كمسودات</button><?php endif; ?>
            </div>
        </div>
    </form>
<?php endif; ?>
</div>

<script src="../assets/js/assessment-scheme-batch-form.js?v=<?php echo file_exists('../assets/js/assessment-scheme-batch-form.js') ? filemtime('../assets/js/assessment-scheme-batch-form.js') : '2.0'; ?>"></script>

<?php require_once '../includes/admin_footer.php'; ?>
