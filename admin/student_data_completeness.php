<?php

declare(strict_types=1);

$page_title = 'اكتمال بيانات الطلاب';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Students/StudentCompletenessConfigService.php';
require_once '../src/Modules/Students/StudentCompletenessReadRepository.php';

use EduCore\Modules\Students\StudentCompletenessConfigService;
use EduCore\Modules\Students\StudentCompletenessReadRepository;

Utilities::validateSession('admin');

$db = (new Database())->getConnection();
$selectedAcademicYear = AcademicYear::getCurrent($db);
$activeAcademicYear = AcademicYear::getActive($db);
if (!$selectedAcademicYear) {
    throw new RuntimeException('لا يوجد عام دراسي صالح لعرض اكتمال بيانات الطلاب.');
}
$selectedAcademicYearId = (int) $selectedAcademicYear['id'];
$activeAcademicYearId = $activeAcademicYear ? (int) $activeAcademicYear['id'] : 0;

$scope = new ScopedStaffPortalContext($db, $selectedAcademicYearId);
$configService = new StudentCompletenessConfigService($db);
$config = $configService->load();
$allFields = $config['fields'];
$activeFields = array_values(array_filter(
    $allFields,
    static fn(array $field): bool => ($field['priority'] ?? 'ignored') !== 'ignored'
));
$sectionNames = array_values(array_unique(array_map(
    static fn(array $field): string => (string) $field['section'],
    $activeFields
)));
$fieldsBySection = [];
foreach ($allFields as $field) {
    $fieldsBySection[(string) $field['section']][] = $field;
}

$repository = new StudentCompletenessReadRepository(
    $db,
    $selectedAcademicYearId,
    $activeAcademicYearId,
    $allFields
);
$filterOptions = $repository->filterOptions($scope->allowedClassIds());
$activeRole = trim((string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? ''));
$canManageConfig = !$scope->isScoped() && in_array($activeRole, ['admin', 'super_admin'], true);
$isCurrentYear = $selectedAcademicYearId === $activeAcademicYearId;
$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

require_once '../includes/admin_header.php';
?>

<div class="students-page" id="studentCompletenessPage">
    <div class="admin-page-heading">
        <h1 class="h2 d-flex align-items-center mb-0">
            <i class="fas fa-clipboard-check me-2 text-primary"></i>
            <span>اكتمال بيانات الطلاب</span>
            <i class="fas fa-info-circle ms-2 text-muted fs-6"
               data-bs-toggle="tooltip"
               data-bs-placement="top"
               title="المعروض الآن هو سجل الطلاب في عام <?php echo $h((string) $selectedAcademicYear['name']); ?>. المرحلة والصف والفصل وحالتا القيد والدراسة تُقرأ من سجل هذا العام، بينما نسبة الاكتمال تخص ملف الطالب الدائم."
               style="cursor: pointer;"
               aria-label="معلومات عن اكتمال بيانات الطلاب"></i>
            <?php if (!$isCurrentYear): ?>
                <span class="badge bg-warning text-dark ms-2 fs-6 fw-normal">عام غير حالي</span>
            <?php endif; ?>
        </h1>
        <div class="admin-top-actions no-print">
            <?php if ($canManageConfig): ?>
                <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="modal" data-bs-target="#fieldsConfigModal">
                    <i class="fas fa-sliders-h me-1"></i>إعداد الاحتساب
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div id="pageFeedback" class="alert alert-dismissible fade show d-none" role="alert">
        <i class="fas fa-circle-info me-2"></i><span id="pageFeedbackText"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>

    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4" aria-label="إحصاءات اكتمال بيانات الطلاب">
        <div class="col">
            <div class="stat-card" style="--card-gradient:linear-gradient(135deg,#3b82f6,#2563eb);">
                <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number" id="statTotal">0</div>
                    <div class="stat-card-label">الطلاب ضمن الفلاتر</div>
                    <div class="stat-card-sub"><i class="fas fa-filter"></i> يتغير مع البحث والفلاتر</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient:linear-gradient(135deg,#10b981,#059669);">
                <div class="stat-card-icon"><i class="fas fa-file-circle-check"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number" id="statProfileComplete">0</div>
                    <div class="stat-card-label">ملفات مكتملة</div>
                    <div class="stat-card-sub"><i class="fas fa-percent"></i> 80% فأكثر</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient:linear-gradient(135deg,#f59e0b,#d97706);">
                <div class="stat-card-icon"><i class="fas fa-pen-to-square"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number" id="statProfileAttention">0</div>
                    <div class="stat-card-label">ملفات تحتاج استكمالاً</div>
                    <div class="stat-card-sub"><i class="fas fa-chart-simple"></i> المتوسط <span id="statAverage">0%</span></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient:linear-gradient(135deg,#ef4444,#dc2626);">
                <div class="stat-card-icon"><i class="fas fa-triangle-exclamation"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number" id="statAnnualAttention">0</div>
                    <div class="stat-card-label">سجلات سنوية تحتاج مراجعة</div>
                    <div class="stat-card-sub"><i class="fas fa-school"></i> التسكين والحالات السنوية</div>
                </div>
            </div>
        </div>
    </div>

    <form id="completenessFilters" class="admin-filter-bar" novalidate>
        <div class="admin-filter-controls">
            <select id="stageFilter" class="form-select form-select-sm admin-inline-select-sm" aria-label="المرحلة" data-default-value="">
                <option value="">كل المراحل</option>
                <?php foreach ($filterOptions['stages'] as $stage): ?>
                    <option value="<?php echo (int) $stage['id']; ?>">
                        <?php echo $h((string) $stage['stage_name']); ?><?php echo (int) $stage['is_experimental'] === 1 ? ' — تجريبية' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select id="gradeFilter" class="form-select form-select-sm admin-inline-select-sm" aria-label="الصف" data-default-value="">
                <option value="">كل الصفوف</option>
                <?php foreach ($filterOptions['grades'] as $grade): ?>
                    <option value="<?php echo (int) $grade['id']; ?>" data-stage-id="<?php echo (int) $grade['stage_id']; ?>">
                        <?php echo $h((string) $grade['grade_name']); ?><?php echo (int) $grade['is_experimental'] === 1 ? ' — تجريبي' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select id="classFilter" class="form-select form-select-sm admin-inline-select-sm" aria-label="الفصل" data-default-value="">
                <option value="">كل الفصول</option>
                <?php foreach ($filterOptions['classes'] as $class): ?>
                    <option value="<?php echo (int) $class['id']; ?>" data-grade-id="<?php echo (int) $class['grade_id']; ?>">
                        <?php echo $h((string) $class['name']); ?><?php echo (int) $class['is_experimental'] === 1 ? ' — تجريبي' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select id="enrollmentStatusFilter" class="form-select form-select-sm admin-inline-select-sm" aria-label="حالة القيد" data-default-value="enrolled">
                <option value="enrolled">مقيد</option>
                <option value="all">كل حالات القيد</option>
                <option value="transferred">منقول</option>
                <option value="discontinued">منقطع</option>
                <option value="graduated">خريج</option>
            </select>
        </div>
        <div class="admin-filter-actions">
            <button type="button" class="btn btn-light btn-sm" id="advancedFiltersToggle" data-bs-toggle="collapse" data-bs-target="#advancedFilters" aria-expanded="false" aria-controls="advancedFilters">
                <i class="fas fa-filter me-1"></i>فلاتر متقدمة
                <span class="badge bg-primary ms-1 d-none" id="advancedFiltersCount">0</span>
            </button>
            <button type="button" class="btn btn-light btn-sm" id="resetFiltersBtn">
                <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
            </button>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal">
                <i class="fas fa-cog me-1"></i>إعدادات الجدول
            </button>
        </div>
        <div class="collapse w-100" id="advancedFilters">
            <div class="admin-filter-controls border-top pt-3">
                <select id="academicStatusFilter" class="form-select form-select-sm admin-inline-select-sm" aria-label="الحالة الدراسية" data-default-value="">
                    <option value="">كل الحالات الدراسية</option>
                    <option value="new">مستجد</option>
                    <option value="promoted">ناجح ومنقول</option>
                    <option value="retained">راسب</option>
                    <option value="graduated">خريج</option>
                </select>
                <select id="annualStateFilter" class="form-select form-select-sm admin-inline-select-sm" aria-label="سلامة السجل السنوي" data-default-value="">
                    <option value="">كل حالات السجل السنوي</option>
                    <option value="ready">سجل سنوي سليم</option>
                    <option value="missing_enrollment">لا يوجد سجل للعام</option>
                    <option value="missing_structure">بيانات دراسية ناقصة</option>
                    <option value="inconsistent_structure">تسكين غير متسق</option>
                    <option value="awaiting_placement">بانتظار التسكين</option>
                </select>
                <select id="profileLevelFilter" class="form-select form-select-sm admin-inline-select-sm" aria-label="اكتمال الملف" data-default-value="">
                    <option value="">كل مستويات اكتمال الملف</option>
                    <option value="complete">مكتمل 80% فأكثر</option>
                    <option value="partial">ناقص جزئياً 50–79%</option>
                    <option value="critical">نواقص جوهرية أقل من 50%</option>
                </select>
                <select id="missingSectionFilter" class="form-select form-select-sm admin-inline-select-sm" aria-label="القسم الناقص" data-default-value="">
                    <option value="">كل أقسام الملف</option>
                    <?php foreach ($sectionNames as $sectionName): ?>
                        <option value="<?php echo $h($sectionName); ?>">ناقص: <?php echo $h($sectionName); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="experimentalScopeFilter" class="form-select form-select-sm admin-inline-select-sm" aria-label="نطاق البيانات التجريبية" data-default-value="official">
                    <option value="official">البيانات الرسمية فقط</option>
                    <option value="all">الرسمية والتجريبية</option>
                    <option value="experimental">التجريبية فقط</option>
                </select>
            </div>
        </div>
    </form>

    <div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped admin-data-table align-middle" id="completenessTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الطالب</th>
                        <th>المسار الدراسي في العام</th>
                        <th>حالة القيد والدراسة</th>
                        <th>اكتمال الملف</th>
                        <th>سلامة السجل السنوي</th>
                        <th>النواقص الأساسية</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="completenessDetailsModal" tabindex="-1" aria-labelledby="completenessDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="completenessDetailsModalLabel"><i class="fas fa-clipboard-list me-2"></i>تفاصيل اكتمال بيانات الطالب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h5 class="mb-1" id="detailsStudentName">—</h5>
                        <div class="text-muted" id="detailsStudentMeta">—</div>
                    </div>
                    <span class="badge bg-primary" id="detailsProfilePct">0%</span>
                </div>
                <div class="alert alert-light border" id="detailsAnnualState">—</div>
                <h6><i class="fas fa-chart-pie me-2 text-primary"></i>اكتمال أقسام الملف</h6>
                <div id="detailsSections" class="mb-4"></div>
                <h6><i class="fas fa-list-check me-2 text-warning"></i>الحقول غير المستكملة</h6>
                <div id="detailsMissingFields" class="row g-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
                <a href="#" class="btn btn-primary" id="detailsEditLink"><i class="fas fa-edit me-1"></i>تعديل بيانات الطالب</a>
            </div>
        </div>
    </div>
</div>

<?php if ($canManageConfig): ?>
<div class="modal fade" id="fieldsConfigModal" tabindex="-1" aria-labelledby="fieldsConfigModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <div class="modal-header">
                <h5 class="modal-title" id="fieldsConfigModalLabel"><i class="fas fa-sliders-h me-2"></i>إعداد احتساب اكتمال ملف الطالب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-circle-info me-2"></i>
                    هذه الإعدادات تخص الملف الدائم فقط، ولا تغيّر تقييم سلامة المرحلة والصف والفصل والحالات السنوية.
                </div>
                <div class="accordion" id="fieldsConfigAccordion">
                    <?php $sectionIndex = 0; foreach ($fieldsBySection as $section => $sectionFields): $sectionIndex++; ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="fieldSectionHeading<?php echo $sectionIndex; ?>">
                                <button class="accordion-button <?php echo $sectionIndex > 1 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#fieldSection<?php echo $sectionIndex; ?>" aria-expanded="<?php echo $sectionIndex === 1 ? 'true' : 'false'; ?>">
                                    <?php echo $h((string) $section); ?>
                                </button>
                            </h2>
                            <div id="fieldSection<?php echo $sectionIndex; ?>" class="accordion-collapse collapse <?php echo $sectionIndex === 1 ? 'show' : ''; ?>" data-bs-parent="#fieldsConfigAccordion">
                                <div class="accordion-body">
                                    <div class="row g-3">
                                        <?php foreach ($sectionFields as $field): ?>
                                            <div class="col-md-6">
                                                <label class="form-label" for="priority_<?php echo $h((string) $field['key']); ?>"><?php echo $h((string) $field['label']); ?></label>
                                                <select class="form-select field-priority-select" id="priority_<?php echo $h((string) $field['key']); ?>" data-key="<?php echo $h((string) $field['key']); ?>">
                                                    <option value="required" <?php echo $field['priority'] === 'required' ? 'selected' : ''; ?>>إلزامي</option>
                                                    <option value="important" <?php echo $field['priority'] === 'important' ? 'selected' : ''; ?>>مهم</option>
                                                    <option value="optional" <?php echo $field['priority'] === 'optional' ? 'selected' : ''; ?>>اختياري</option>
                                                    <option value="ignored" <?php echo $field['priority'] === 'ignored' ? 'selected' : ''; ?>>لا يدخل في الاحتساب</option>
                                                </select>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <details class="mt-4">
                    <summary class="fw-semibold text-primary">إعدادات الأوزان المتقدمة</summary>
                    <p class="text-muted small mt-2">الوزن يحدد تأثير الحقل على النسبة، ويجب أن يكون بين 0 و20.</p>
                    <div class="row g-3">
                        <?php foreach ($allFields as $field): ?>
                            <div class="col-md-4">
                                <label class="form-label" for="weight_<?php echo $h((string) $field['key']); ?>"><?php echo $h((string) $field['label']); ?></label>
                                <input type="number" min="0" max="20" class="form-control field-weight-input" id="weight_<?php echo $h((string) $field['key']); ?>" data-key="<?php echo $h((string) $field['key']); ?>" value="<?php echo (int) $field['weight']; ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="button" class="btn btn-primary" id="saveFieldsConfigBtn"><i class="fas fa-save me-1"></i>حفظ الإعدادات</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-labelledby="tableSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="tableSettingsModalLabel"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2">
                    <?php
                    $columnSettings = [
                        'colPlacement' => ['index' => 2, 'label' => 'المسار الدراسي'],
                        'colAnnualStatus' => ['index' => 3, 'label' => 'حالة القيد والدراسة'],
                        'colProfile' => ['index' => 4, 'label' => 'اكتمال الملف'],
                        'colAnnualReadiness' => ['index' => 5, 'label' => 'سلامة السجل السنوي'],
                        'colMissing' => ['index' => 6, 'label' => 'النواقص الأساسية'],
                    ];
                    foreach ($columnSettings as $checkboxId => $setting):
                    ?>
                        <div class="col-12 col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="<?php echo $h($checkboxId); ?>" checked>
                                <label class="form-check-label" for="<?php echo $h($checkboxId); ?>"><?php echo $h($setting['label']); ?></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo asset_url('../assets/js/admin_table_actions.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var endpoint = 'ajax_student_completeness.php';
    var tableElement = document.getElementById('completenessTable');
    var filterIds = [
        'stageFilter', 'gradeFilter', 'classFilter', 'enrollmentStatusFilter',
        'academicStatusFilter', 'annualStateFilter', 'profileLevelFilter',
        'missingSectionFilter', 'experimentalScopeFilter'
    ];
    var advancedFilterIds = [
        'academicStatusFilter', 'annualStateFilter', 'profileLevelFilter',
        'missingSectionFilter', 'experimentalScopeFilter'
    ];

    function filterParams() {
        return {
            stage_id: document.getElementById('stageFilter').value,
            grade_id: document.getElementById('gradeFilter').value,
            class_id: document.getElementById('classFilter').value,
            enrollment_status: document.getElementById('enrollmentStatusFilter').value,
            academic_status: document.getElementById('academicStatusFilter').value,
            annual_state: document.getElementById('annualStateFilter').value,
            profile_level: document.getElementById('profileLevelFilter').value,
            missing_section: document.getElementById('missingSectionFilter').value,
            experimental_scope: document.getElementById('experimentalScopeFilter').value
        };
    }

    function showFeedback(message, type) {
        var box = document.getElementById('pageFeedback');
        document.getElementById('pageFeedbackText').textContent = message;
        box.className = 'alert alert-' + type + ' alert-dismissible fade show';
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function formatNumber(value, maximumFractionDigits) {
        return new Intl.NumberFormat('en-US', {
            maximumFractionDigits: maximumFractionDigits || 0
        }).format(Number(value || 0));
    }

    function setStat(id, value) {
        var target = document.getElementById(id);
        if (target) {
            target.textContent = formatNumber(value, 0);
        }
    }

    function updateFilterPresentation() {
        document.querySelectorAll('#completenessFilters [data-default-value]').forEach(function (control) {
            control.classList.toggle('active-filter', control.value !== control.dataset.defaultValue);
        });

        var advancedCount = advancedFilterIds.reduce(function (count, id) {
            var control = document.getElementById(id);
            return count + (control && control.value !== control.dataset.defaultValue ? 1 : 0);
        }, 0);
        var countBadge = document.getElementById('advancedFiltersCount');
        countBadge.textContent = String(advancedCount);
        countBadge.classList.toggle('d-none', advancedCount === 0);
        document.getElementById('advancedFiltersToggle').setAttribute(
            'aria-label',
            advancedCount > 0 ? 'فلاتر متقدمة، ' + advancedCount + ' مفعلة' : 'فلاتر متقدمة'
        );
    }

    var dataTable = $(tableElement).DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: endpoint,
            type: 'GET',
            data: function (request) {
                Object.assign(request, filterParams(), { action: 'datatable_data' });
            },
            dataSrc: function (json) {
                if (json && Array.isArray(json.data)) {
                    return json.data;
                }
                showFeedback((json && json.message) || 'تعذر تحميل قائمة الطلاب.', 'danger');
                return [];
            },
            error: function () {
                showFeedback('تعذر الاتصال بالخادم لتحميل بيانات الطلاب.', 'danger');
            }
        },
        columns: [
            { data: 'num', orderable: false },
            { data: 'student', orderable: true },
            { data: 'placement', orderable: true },
            { data: 'annual_status', orderable: false },
            { data: 'profile', orderable: true },
            { data: 'annual_readiness', orderable: true },
            { data: 'missing', orderable: false },
            { data: 'actions', orderable: false, searchable: false, className: 'text-center actions-column admin-table-actions' }
        ],
        order: [[1, 'asc']],
        pageLength: 50,
        lengthMenu: [[10, 25, 50, 100, 200, 500, -1], [10, 25, 50, 100, 200, 500, 'الكل']],
        dom: '<"d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"fl>rt<"d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2"ip>',
        language: {
            search: 'البحث:',
            searchPlaceholder: 'اسم الطالب أو الكود',
            lengthMenu: 'عرض _MENU_ سجل',
            info: 'عرض _START_ إلى _END_ من أصل _TOTAL_ سجل',
            infoEmpty: 'لا توجد سجلات للعرض',
            infoFiltered: '(من أصل _MAX_ سجل)',
            processing: '<div class="text-center py-3"><i class="fas fa-spinner fa-spin text-primary fa-2x"></i><div class="text-muted mt-2">جاري التحميل...</div></div>',
            zeroRecords: 'لا توجد نتائج تطابق الفلاتر الحالية',
            emptyTable: 'لا توجد بيانات طلاب لهذا العام والنطاق',
            paginate: { first: 'الأول', last: 'الأخير', next: 'التالي', previous: 'السابق' }
        },
        drawCallback: function () {
            document.querySelectorAll('#completenessTable [data-bs-toggle="tooltip"]').forEach(function (element) {
                var existing = bootstrap.Tooltip.getInstance(element);
                if (existing) existing.dispose();
                new bootstrap.Tooltip(element);
            });
        }
    });

    function loadStats() {
        var params = new URLSearchParams(filterParams());
        params.set('action', 'stats');
        params.set('search_value', dataTable.search() || '');
        fetch(endpoint + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                if (!response.ok) throw new Error('stats');
                return response.json();
            })
            .then(function (stats) {
                setStat('statTotal', stats.total);
                setStat('statProfileComplete', stats.profile_complete);
                setStat('statProfileAttention', stats.profile_attention);
                setStat('statAnnualAttention', stats.annual_attention);
                document.getElementById('statAverage').textContent = formatNumber(stats.avg_profile, 1) + '%';
            })
            .catch(function () {
                showFeedback('تم تحميل الجدول، لكن تعذر تحديث الإحصاءات.', 'warning');
            });
    }

    $('#completenessTable').on('xhr.dt', loadStats);

    function updateCascadingOptions() {
        var stageId = document.getElementById('stageFilter').value;
        var gradeSelect = document.getElementById('gradeFilter');
        Array.prototype.forEach.call(gradeSelect.options, function (option) {
            if (!option.value) return;
            var visible = !stageId || option.dataset.stageId === stageId;
            option.hidden = !visible;
            option.disabled = !visible;
            if (!visible && option.selected) gradeSelect.value = '';
        });

        var gradeId = gradeSelect.value;
        var classSelect = document.getElementById('classFilter');
        Array.prototype.forEach.call(classSelect.options, function (option) {
            if (!option.value) return;
            var visible = !gradeId || option.dataset.gradeId === gradeId;
            option.hidden = !visible;
            option.disabled = !visible;
            if (!visible && option.selected) classSelect.value = '';
        });
    }

    filterIds.forEach(function (id) {
        document.getElementById(id).addEventListener('change', function () {
            if (id === 'stageFilter' || id === 'gradeFilter') updateCascadingOptions();
            window.setTimeout(updateFilterPresentation, 0);
            dataTable.ajax.reload();
        });
    });
    document.getElementById('resetFiltersBtn').addEventListener('click', function () {
        document.getElementById('stageFilter').value = '';
        document.getElementById('gradeFilter').value = '';
        document.getElementById('classFilter').value = '';
        document.getElementById('enrollmentStatusFilter').value = 'enrolled';
        document.getElementById('academicStatusFilter').value = '';
        document.getElementById('annualStateFilter').value = '';
        document.getElementById('profileLevelFilter').value = '';
        document.getElementById('missingSectionFilter').value = '';
        document.getElementById('experimentalScopeFilter').value = 'official';
        updateCascadingOptions();
        updateFilterPresentation();
        bootstrap.Collapse.getOrCreateInstance(document.getElementById('advancedFilters'), { toggle: false }).hide();
        dataTable.search('').ajax.reload();
    });

    $('#completenessTable').on('click', '.js-completeness-details', function () {
        var row = dataTable.row($(this).closest('tr')).data();
        if (!row || !row.details) return;
        var details = row.details;
        document.getElementById('detailsStudentName').textContent = details.name || '—';
        document.getElementById('detailsStudentMeta').textContent = [details.student_code, details.stage_name, details.grade_name, details.class_name].filter(Boolean).join(' · ') || 'لا توجد بيانات مسار دراسي';
        document.getElementById('detailsProfilePct').textContent = details.profile_pct + '% — ' + details.profile_level_label;
        document.getElementById('detailsAnnualState').textContent = 'السجل السنوي: ' + details.annual_state_label + ' · حالة القيد: ' + details.enrollment_status_label + ' · الحالة الدراسية: ' + details.academic_status_label;
        document.getElementById('detailsEditLink').href = 'students.php?action=edit&id=' + encodeURIComponent(details.student_id);

        var sectionsContainer = document.getElementById('detailsSections');
        sectionsContainer.textContent = '';
        Object.keys(details.section_percentages || {}).forEach(function (section) {
            var pct = Number(details.section_percentages[section] || 0);
            var block = document.createElement('div');
            block.className = 'mb-2';
            var labelRow = document.createElement('div');
            labelRow.className = 'd-flex justify-content-between small mb-1';
            var label = document.createElement('span');
            label.textContent = section;
            var value = document.createElement('strong');
            value.textContent = pct + '%';
            labelRow.append(label, value);
            var progress = document.createElement('div');
            progress.className = 'progress';
            var bar = document.createElement('div');
            bar.className = 'progress-bar bg-' + (pct >= 80 ? 'success' : (pct >= 50 ? 'warning' : 'danger'));
            bar.style.width = pct + '%';
            progress.appendChild(bar);
            block.append(labelRow, progress);
            sectionsContainer.appendChild(block);
        });

        var missingContainer = document.getElementById('detailsMissingFields');
        missingContainer.textContent = '';
        if (!details.missing_fields || details.missing_fields.length === 0) {
            var complete = document.createElement('div');
            complete.className = 'col-12 text-success';
            complete.innerHTML = '<i class="fas fa-check-circle me-1"></i>جميع الحقول الداخلة في الاحتساب مكتملة.';
            missingContainer.appendChild(complete);
        } else {
            details.missing_fields.forEach(function (field) {
                var column = document.createElement('div');
                column.className = 'col-md-6';
                var item = document.createElement('div');
                item.className = 'border rounded p-2 h-100';
                var label = document.createElement('div');
                label.className = 'fw-semibold';
                label.textContent = field.label;
                var meta = document.createElement('div');
                meta.className = 'small text-muted';
                meta.textContent = field.section + ' · ' + ({required: 'إلزامي', important: 'مهم', optional: 'اختياري'}[field.priority] || field.priority);
                item.append(label, meta);
                column.appendChild(item);
                missingContainer.appendChild(column);
            });
        }
        new bootstrap.Modal(document.getElementById('completenessDetailsModal')).show();
    });

    if (window.initializeTableColumnSettings) {
        window.initializeTableColumnSettings('completenessTable', {
            colPlacement: 2,
            colAnnualStatus: 3,
            colProfile: 4,
            colAnnualReadiness: 5,
            colMissing: 6
        }, 'student_completeness_table_columns_v2');
    }

    var saveConfigButton = document.getElementById('saveFieldsConfigBtn');
    if (saveConfigButton) {
        saveConfigButton.addEventListener('click', function () {
            var fields = [];
            document.querySelectorAll('.field-priority-select').forEach(function (select) {
                var weight = document.querySelector('.field-weight-input[data-key="' + select.dataset.key + '"]');
                fields.push({
                    key: select.dataset.key,
                    priority: select.value,
                    weight: weight ? Number(weight.value) : 0
                });
            });
            saveConfigButton.disabled = true;
            saveConfigButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>جاري الحفظ...';
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body: new URLSearchParams({
                    action: 'save_fields_config',
                    csrf_token: <?php echo json_encode(csrfToken(), JSON_UNESCAPED_UNICODE); ?>,
                    fields: JSON.stringify(fields)
                })
            })
                .then(function (response) {
                    return response.json().then(function (body) { return { ok: response.ok, body: body }; });
                })
                .then(function (result) {
                    if (!result.ok || !result.body.success) throw new Error(result.body.message || 'تعذر حفظ الإعدادات.');
                    showFeedback(result.body.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('fieldsConfigModal')).hide();
                    window.setTimeout(function () { window.location.reload(); }, 700);
                })
                .catch(function (error) {
                    showFeedback(error.message || 'تعذر حفظ الإعدادات.', 'danger');
                    saveConfigButton.disabled = false;
                    saveConfigButton.innerHTML = '<i class="fas fa-save me-1"></i>حفظ الإعدادات';
                });
        });
    }

    updateCascadingOptions();
    window.setTimeout(updateFilterPresentation, 0);
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
