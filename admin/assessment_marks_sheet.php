<?php

declare(strict_types=1);

$page_title = 'شيت درجات الطلاب';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AssessmentMarkSheetQuery.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');

$db = (new Database())->getConnection();
$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = (int) ($currentAcademicYear['id'] ?? 0);
$activeRole = (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
$isSuperAdmin = $activeRole === 'super_admin';

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function assessment_marks_sheet_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function assessment_marks_sheet_english_digits(string $value): string
{
    return strtr($value, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
}

function assessment_marks_sheet_filters(array $source): array
{
    $filters = [];
    foreach (['grade_id', 'term_id', 'scheme_id', 'class_id'] as $key) {
        $value = max(0, (int) ($source['sheet_' . $key] ?? 0));
        if ($value > 0) {
            $filters['sheet_' . $key] = $value;
        }
    }
    return $filters;
}

$requiredTables = [
    'student_marks', 'student_enrollments', 'assessment_schemes', 'assessment_components',
    'assessment_component_week_rules', 'assessment_windows', 'academic_terms', 'academic_weeks',
];
$foundationReady = $currentAcademicYearId > 0;
foreach ($requiredTables as $requiredTable) {
    $foundationReady = $foundationReady && assessment_marks_sheet_table_exists($db, $requiredTable);
}

$sheetQuery = new AssessmentMarkSheetQuery($db);
$sheetOptions = $foundationReady ? $sheetQuery->options($currentAcademicYearId) : [
    'grades' => [], 'terms' => [], 'classes' => [], 'schemes' => [],
];
$requestedFilters = assessment_marks_sheet_filters($_GET);
$validGradeIds = array_map('intval', array_column($sheetOptions['grades'], 'id'));
$requestedGradeId = (int) ($requestedFilters['sheet_grade_id'] ?? 0);
$initialGradeId = in_array($requestedGradeId, $validGradeIds, true)
    ? $requestedGradeId
    : (int) ($sheetOptions['grades'][0]['id'] ?? 0);
$activeTerm = null;
foreach ($sheetOptions['terms'] as $termOption) {
    if (($termOption['status'] ?? '') === 'active') {
        $activeTerm = $termOption;
        break;
    }
}
$validTermIds = array_map('intval', array_column($sheetOptions['terms'], 'id'));
$requestedTermId = (int) ($requestedFilters['sheet_term_id'] ?? 0);
$initialTermId = in_array($requestedTermId, $validTermIds, true)
    ? $requestedTermId
    : (int) ($activeTerm['id'] ?? ($sheetOptions['terms'][0]['id'] ?? 0));
$requestedSchemeId = (int) ($requestedFilters['sheet_scheme_id'] ?? 0);
$initialSchemeId = 0;
foreach ($sheetOptions['schemes'] as $schemeOption) {
    if ((int) $schemeOption['grade_id'] !== $initialGradeId || (int) $schemeOption['term_id'] !== $initialTermId) {
        continue;
    }
    if ($initialSchemeId === 0 || (int) $schemeOption['id'] === $requestedSchemeId) {
        $initialSchemeId = (int) $schemeOption['id'];
    }
    if ($initialSchemeId === $requestedSchemeId) {
        break;
    }
}
$requestedClassId = (int) ($requestedFilters['sheet_class_id'] ?? 0);
$initialClassId = 0;
foreach ($sheetOptions['classes'] as $classOption) {
    if ((int) $classOption['id'] === $requestedClassId && (int) $classOption['grade_id'] === $initialGradeId) {
        $initialClassId = $requestedClassId;
        break;
    }
}

$page_stylesheets = ['../assets/vendor/tabulator/6.5.0/tabulator_bootstrap5.min.css'];
require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-table-cells-large me-2 text-primary"></i>شيت درجات الطلاب</h1>
    <div class="admin-top-actions">
        <a class="btn btn-outline-secondary" href="assessment_marks.php"><i class="fas fa-list me-2"></i>سجل الدرجات</a>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $success_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $error_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>
<?php endif; ?>

<?php if (!$foundationReady): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>طبّق جداول نظام التقييم وحدد عامًا دراسيًا قبل استخدام شيت الدرجات.</div>
<?php elseif ($sheetOptions['grades'] === [] || $sheetOptions['terms'] === []): ?>
    <div class="alert alert-info"><i class="fas fa-circle-info me-2"></i>أنشئ خطة تقييم مرتبطة بصف وترم حتى يظهر شيت الدرجات.</div>
<?php else: ?>
    <div class="alert alert-info assessment-sheet-help">
        <i class="fas fa-keyboard me-2"></i>
        حدّد الخلايا بالسحب مثل Excel. اكتب رقمًا لبدء التحرير، واضغط Enter للحفظ والانتقال، وEsc للإلغاء. استخدم Ctrl+C وCtrl+V للنسخ واللصق الذري، وDelete لمسح قيم النطاق دون حذف سجلاته.
    </div>

    <div class="admin-filter-bar assessment-sheet-filter-bar">
        <div class="admin-filter-controls">
            <select id="sheetGrade" class="form-select form-select-sm" aria-label="الصف الدراسي">
                <?php foreach ($sheetOptions['grades'] as $option): ?>
                    <option value="<?php echo (int) $option['id']; ?>" <?php echo (int) $option['id'] === $initialGradeId ? 'selected' : ''; ?>><?php echo htmlspecialchars(assessment_marks_sheet_english_digits(!empty($option['stage_name']) ? ($option['stage_name'] . ' — ' . $option['name']) : $option['name']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="sheetTerm" class="form-select form-select-sm" aria-label="الفصل الدراسي">
                <?php foreach ($sheetOptions['terms'] as $option): ?>
                    <option value="<?php echo (int) $option['id']; ?>" <?php echo (int) $option['id'] === $initialTermId ? 'selected' : ''; ?>><?php echo htmlspecialchars(assessment_marks_sheet_english_digits((string) $option['name']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="sheetClass" class="form-select form-select-sm" aria-label="الفصل">
                <option value="">كل الفصول</option>
                <?php foreach ($sheetOptions['classes'] as $option): ?>
                    <option value="<?php echo (int) $option['id']; ?>" data-grade="<?php echo (int) $option['grade_id']; ?>" <?php echo (int) $option['id'] === $initialClassId ? 'selected' : ''; ?>><?php echo htmlspecialchars(assessment_marks_sheet_english_digits((string) $option['name']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="input-group input-group-sm assessment-sheet-search">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="search" id="sheetStudentSearch" class="form-control" placeholder="اسم الطالب، الكود أو اسم المستخدم" autocomplete="off">
            </div>
            <div class="form-check form-switch assessment-sheet-missing-toggle">
                <input class="form-check-input" type="checkbox" role="switch" id="sheetMissingOnly">
                <label class="form-check-label" for="sheetMissingOnly">الناقص فقط</label>
            </div>
        </div>
        <div class="admin-filter-actions">
            <button type="button" id="reloadMarksSheet" class="btn btn-light btn-sm"><i class="fas fa-rotate me-1"></i>تحديث</button>
        </div>
    </div>

    <div id="sheetInlineFeedback" class="alert d-none" role="status" aria-live="polite"></div>

    <section class="assessment-workbook" aria-label="مصنف درجات الطلاب">
        <div class="assessment-sheet-formula-bar">
            <output id="sheetNameBox" class="assessment-sheet-name-box" aria-label="عنوان الخلية">A1</output>
            <span class="assessment-sheet-fx" aria-hidden="true">fx</span>
            <output id="sheetFormulaText" class="assessment-sheet-formula-text">حدّد خلية درجة لعرض الطالب والبند والحد الأقصى.</output>
            <output id="sheetSaveState" class="assessment-sheet-save-state" data-state="idle"><i class="fas fa-cloud"></i><span>جاهز</span></output>
        </div>

        <div id="sheetSelectionToolbar" class="assessment-sheet-selection-toolbar d-none" aria-live="polite">
            <div class="assessment-sheet-selection-summary">
                <i class="fas fa-border-all"></i>
                <span><strong id="sheetSelectedCount">0</strong> خلية محددة · <strong id="sheetEditableSelectedCount">0</strong> قابلة للتعديل</span>
            </div>
            <div class="assessment-sheet-selection-actions">
                <button type="button" id="sheetBulkEdit" class="btn btn-primary btn-sm"><i class="fas fa-pen-to-square me-1"></i>تعديل النطاق</button>
                <button type="button" id="sheetClearValues" class="btn btn-secondary btn-sm"><i class="fas fa-eraser me-1"></i>مسح القيم</button>
                <?php if ($isSuperAdmin): ?>
                    <button type="button" id="sheetBulkDelete" class="btn btn-danger btn-sm"><i class="fas fa-trash me-1"></i>حذف السجلات</button>
                <?php endif; ?>
                <button type="button" id="sheetClearSelection" class="btn btn-light btn-sm"><i class="fas fa-xmark me-1"></i>إلغاء التحديد</button>
            </div>
        </div>

        <div id="sheetBulkEditor" class="assessment-sheet-bulk-editor d-none">
            <div class="assessment-sheet-bulk-fields">
                <select id="sheetBulkStatus" class="form-select form-select-sm" aria-label="الحالة الجماعية">
                    <option value="present">درجة</option>
                    <option value="absent">غائب</option>
                    <option value="excused_absent">غياب بعذر</option>
                    <option value="exempt">معفى</option>
                    <option value="empty">فارغ</option>
                </select>
                <input type="number" id="sheetBulkValue" class="form-control form-control-sm" min="0" step="0.01" inputmode="decimal" placeholder="القيمة" aria-label="القيمة الجماعية">
                <div class="form-check assessment-sheet-note-toggle">
                    <input class="form-check-input" type="checkbox" id="sheetBulkChangeNote">
                    <label class="form-check-label" for="sheetBulkChangeNote">تغيير الملاحظة</label>
                </div>
                <input type="text" id="sheetBulkNote" class="form-control form-control-sm" maxlength="500" placeholder="ملاحظة موحدة" aria-label="الملاحظة الجماعية" disabled>
                <input type="text" id="sheetBulkReason" class="form-control form-control-sm" minlength="5" maxlength="500" placeholder="سبب العملية الجماعية — مطلوب" autocomplete="off" aria-label="سبب العملية الجماعية">
            </div>
            <div class="assessment-sheet-selection-actions">
                <button type="button" id="sheetApplyBulkEdit" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>تطبيق ذري</button>
                <button type="button" id="sheetCancelBulkEdit" class="btn btn-secondary btn-sm"><i class="fas fa-xmark me-1"></i>إلغاء</button>
            </div>
        </div>

        <div class="assessment-sheet-legend" aria-label="دليل حالات الخلايا">
            <span><i class="assessment-sheet-swatch is-present"></i>مرصودة</span>
            <span><i class="assessment-sheet-swatch is-absent"></i>غائب</span>
            <span><i class="assessment-sheet-swatch is-excused"></i>غياب بعذر</span>
            <span><i class="assessment-sheet-swatch is-missing"></i>غير مرصودة</span>
            <span><i class="fas fa-lock text-danger"></i>مقفلة</span>
            <span><i class="fas fa-file-lines text-info"></i>نسخة منشورة</span>
        </div>

        <div class="assessment-sheet-surface">
            <div id="marksSheetStatus" class="assessment-sheet-status" role="status" aria-live="polite">
                <i class="fas fa-spinner fa-spin me-2"></i>جاري إعداد الشيت…
            </div>
            <div id="marksSheetViewport" class="assessment-sheet-viewport d-none" role="grid" aria-label="شيت درجات الطلاب"></div>
        </div>

        <div class="assessment-sheet-workbook-footer">
            <div class="assessment-subject-strip" aria-label="أوراق مواد الصف">
                <div id="sheetSubjectTabs" class="assessment-subject-tabs" role="tablist"></div>
            </div>
            <div class="assessment-sheet-status-bar" aria-live="polite">
                <span>الطلاب <strong data-sheet-summary="students">0</strong></span>
                <span>الأعمدة <strong data-sheet-summary="columns">0</strong></span>
                <span>المرصود <strong data-sheet-summary="marks">0</strong></span>
                <span>الناقص <strong data-sheet-summary="missing">0</strong></span>
                <span id="sheetSelectionStats">التحديد: 0</span>
            </div>
        </div>
    </section>

    <?php if ($isSuperAdmin): ?>
        <div class="modal fade" id="sheetDeleteSelectedModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
                    <form id="sheetDeleteSelectedForm" method="post" action="ajax_assessment_marks_bulk.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="academic_year_id" value="<?php echo $currentAcademicYearId; ?>">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف سجلات الدرجات المحددة</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                        </div>
                        <div class="modal-body">
                            <div class="text-center mb-3"><i class="fas fa-triangle-exclamation text-danger admin-modal-icon-lg"></i></div>
                            <p class="text-center">سيتم حذف <strong id="sheetDeleteSelectedCount">0</strong> سجل درجة أصلي.</p>
                            <div class="alert alert-danger"><i class="fas fa-circle-exclamation me-2"></i>هذا يختلف عن مسح القيمة. النسخ المنشورة ستظل لقطات مستقلة حتى إلغاء نشرها أو إعادة نشرها صراحةً.</div>
                            <label class="form-label" for="sheetDeleteReason">سبب الحذف</label>
                            <textarea id="sheetDeleteReason" class="form-control" name="reason" rows="2" minlength="5" maxlength="500" required></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                            <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>تأكيد الحذف</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
    window.assessmentMarksSheetConfig = <?php echo json_encode([
        'academicYearId' => $currentAcademicYearId,
        'isSuperAdmin' => $isSuperAdmin,
        'endpoint' => 'ajax_assessment_marks_sheet.php',
        'updateEndpoint' => 'ajax_assessment_mark_update.php',
        'bulkEndpoint' => 'ajax_assessment_marks_bulk.php',
        'csrfToken' => (string) ($_SESSION['csrf_token'] ?? ''),
        'options' => $sheetOptions,
        'initial' => [
            'gradeId' => $initialGradeId,
            'termId' => $initialTermId,
            'schemeId' => $initialSchemeId,
            'classId' => $initialClassId,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="../assets/vendor/tabulator/6.5.0/tabulator.min.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/vendor/tabulator/6.5.0/tabulator.min.js'); ?>"></script>
    <script src="../assets/js/assessment-marks-sheet.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/assessment-marks-sheet.js'); ?>"></script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
