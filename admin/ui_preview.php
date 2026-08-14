<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$page_title = 'معاينة النظام الموحد';
$custom_page_title = true;

$previewNames = [
    'آدم محمد عثمان', 'مريم أحمد علي', 'سليم محمود حسن', 'ليان خالد يوسف',
    'يوسف سامي إبراهيم', 'نور أحمد عبد الله', 'زياد طارق محمود', 'تالا محمد عاطف',
    'عمر كريم السيد', 'حبيبة أشرف جمال', 'سارة عمرو فؤاد', 'مالك أحمد رجب'
];
$previewStages = ['المرحلة الابتدائية', 'المرحلة الإعدادية', 'المرحلة الثانوية'];
$previewGrades = ['الصف الأول الابتدائي', 'الصف الثاني الإعدادي', 'الصف الأول الثانوي'];
$previewClasses = ['Flowers 1', 'Prep 1C', 'Sec 1A', 'Towers 2'];
$previewStatuses = ['نشط', 'معلق', 'منقول'];
$previewStudents = [];

for ($index = 1; $index <= 36; $index++) {
    $stageIndex = ($index - 1) % count($previewStages);
    $previewStudents[] = [
        'number' => $index,
        'code' => 'S2026' . str_pad((string) (8100 + $index), 4, '0', STR_PAD_LEFT),
        'name' => $previewNames[($index - 1) % count($previewNames)],
        'stage' => $previewStages[$stageIndex],
        'grade' => $previewGrades[$stageIndex],
        'class' => $previewClasses[($index - 1) % count($previewClasses)],
        'date' => '2026-0' . (($index % 6) + 1) . '-' . str_pad((string) (($index % 27) + 1), 2, '0', STR_PAD_LEFT),
        'status' => $previewStatuses[($index - 1) % count($previewStatuses)]
    ];
}

require_once '../includes/admin_header.php';
?>

<div id="uiPreview" class="students-page ui-preview" aria-label="معاينة تنسيق صفحات الإدارة">
    <div class="admin-page-heading">
        <h1 class="h2"><i class="fas fa-users me-2 text-primary"></i>إدارة الطلاب المقيدين</h1>
        <div class="admin-top-actions no-print">
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#previewCreateModal">
                <i class="fas fa-plus-circle"></i>إضافة طالب
            </button>
            <button type="button" class="btn btn-header-premium btn-import-soft" id="previewImportButton">
                <i class="fas fa-file-import"></i>استيراد Excel
            </button>
            <button type="button" class="btn btn-header-premium btn-export-soft" id="previewExportButton">
                <i class="fas fa-file-excel"></i>تصدير Excel
            </button>
            <button type="button" class="btn btn-header-premium btn-pdf-soft" id="previewPdfButton">
                <i class="fas fa-file-pdf"></i>PDF
            </button>
            <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="modal" data-bs-target="#previewTableSettingsModal">
                <i class="fas fa-sliders-h me-1"></i>تخصيص
            </button>
            <button type="button" class="btn btn-header-premium btn-print-soft" id="previewPrintButton">
                <i class="fas fa-print me-1"></i>طباعة
            </button>
            <div class="dropdown">
                <button type="button" class="btn btn-header-premium btn-print-soft dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-layer-group me-1"></i>مودالات المرحلة
                </button>
                <ul class="dropdown-menu shadow-sm">
                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#previewAddStageModal"><i class="fas fa-plus text-success me-2"></i>إضافة مرحلة</button></li>
                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#previewEditStageModal"><i class="fas fa-pen text-primary me-2"></i>تعديل مرحلة</button></li>
                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#previewDisableStageModal"><i class="fas fa-ban text-warning me-2"></i>تعطيل مرحلة</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#previewDeleteStageModal"><i class="fas fa-trash me-2"></i>حذف مرحلة</button></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="dashboard-canvas sortable-dashboard">
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4 sortable-dashboard" id="widget-preview-stats" aria-label="كروت إحصائيات تجريبية">
        <div class="col" id="preview-stat-stages">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#6366f1,#4f46e5);">
                <div class="stat-card-icon"><i class="fas fa-layer-group"></i></div>
                <div class="stat-card-badge">4 نشطة</div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="4">0</div>
                    <div class="stat-card-label">إجمالي المراحل</div>
                </div>
            </div>
        </div>
        <div class="col" id="preview-stat-grades">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#0ea5e9,#0284c7);">
                <div class="stat-card-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="stat-card-badge">15 نشط</div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="15">0</div>
                    <div class="stat-card-label">إجمالي الصفوف</div>
                </div>
            </div>
        </div>
        <div class="col" id="preview-stat-classes">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#f59e0b,#d97706);">
                <div class="stat-card-icon"><i class="fas fa-door-open"></i></div>
                <div class="stat-card-badge">51 نشط</div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="51">0</div>
                    <div class="stat-card-label">إجمالي الفصول</div>
                </div>
            </div>
        </div>
        <div class="col" id="preview-stat-students">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#10b981,#059669);">
                <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
                <div class="stat-card-badge">1,253 نشط</div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="1255">0</div>
                    <div class="stat-card-label">إجمالي الطلاب</div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <ul class="nav nav-tabs mb-3 border-bottom" id="previewStudentsPageTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link fw-semibold active" href="#tab-students">
                <i class="fas fa-user-graduate me-2"></i>قائمة الطلاب المقيدين <span class="badge bg-primary ms-1">1,253</span>
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link fw-semibold" href="#tab-activity-log" id="previewActivityButton">
                <i class="fas fa-history me-2"></i>سجل العمليات <span class="badge bg-secondary ms-1">59</span>
            </a>
        </li>
    </ul>

    <form id="uiPreviewFilterForm" class="admin-filter-bar" novalidate>
        <div class="admin-filter-controls">
            <select id="uiPreviewStage" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة المرحلة">
                <option value="">كل المراحل</option>
                <?php foreach ($previewStages as $stage): ?>
                    <option value="<?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="uiPreviewGrade" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة الصف">
                <option value="">كل الصفوف</option>
                <?php foreach ($previewGrades as $grade): ?>
                    <option value="<?php echo htmlspecialchars($grade, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($grade, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="uiPreviewClass" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة الفصل">
                <option value="">كل الفصول</option>
                <?php foreach ($previewClasses as $className): ?>
                    <option value="<?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-filter-actions">
            <button type="button" class="btn btn-light btn-sm" id="uiPreviewReset"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</button>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#previewTableSettingsModal">
                <i class="fas fa-cog me-1"></i>إعدادات الجدول
            </button>
        </div>
    </form>

    <div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table" id="uiPreviewTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الكود</th>
                        <th>اسم الطالب</th>
                        <th>المرحلة</th>
                        <th>الصف</th>
                        <th>الفصل</th>
                        <th>تاريخ القيد</th>
                        <th>الحالة</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewStudents as $student): ?>
                        <tr>
                            <td><?php echo (int) $student['number']; ?></td>
                            <td><?php echo htmlspecialchars($student['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($student['stage'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($student['grade'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($student['class'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($student['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge <?php echo $student['status'] === 'نشط' ? 'bg-success' : ($student['status'] === 'معلق' ? 'bg-warning text-dark' : 'bg-primary'); ?>"><?php echo htmlspecialchars($student['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="text-center actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-edit has-tooltip me-1" title="تعديل البيانات" data-bs-toggle="modal" data-bs-target="#previewEditModal" aria-label="تعديل البيانات"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-action-pills btn-deactivate me-1" title="تعطيل الطالب" data-bs-toggle="modal" data-bs-target="#previewStatusModal" aria-label="تعطيل الطالب"><i class="fas fa-ban"></i></button>
                                <button type="button" class="btn btn-action-pills btn-delete has-tooltip" title="حذف" data-bs-toggle="modal" data-bs-target="#previewDeleteModal" aria-label="حذف"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <input type="file" id="previewImportInput" class="d-none" accept=".xlsx,.xls,.csv">

    <div class="toast align-items-center border-0 position-fixed bottom-0 start-0 m-3 ui-preview-toast" id="uiPreviewToast" role="status" aria-live="polite" aria-atomic="true" data-bs-delay="3200">
        <div class="d-flex">
            <div class="toast-body" id="uiPreviewToastBody"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="إغلاق"></button>
        </div>
    </div>

    <div class="modal fade" id="previewTableSettingsModal" tabindex="-1" aria-labelledby="previewTableSettingsTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
                <div class="modal-header">
                    <h5 class="modal-title" id="previewTableSettingsTitle"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="previewColCode" checked><label class="form-check-label" for="previewColCode">الكود</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="previewColStage" checked><label class="form-check-label" for="previewColStage">المرحلة</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="previewColGrade" checked><label class="form-check-label" for="previewColGrade">الصف</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="previewColClass" checked><label class="form-check-label" for="previewColClass">الفصل</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="previewColDate" checked><label class="form-check-label" for="previewColDate">تاريخ القيد</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="previewColStatus" checked><label class="form-check-label" for="previewColStatus">الحالة</label></div></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
                </div>
            </div>
        </div>
    </div>
 
    <div class="modal fade" id="previewCreateModal" tabindex="-1" aria-labelledby="previewCreateTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                <div class="modal-header"><h5 class="modal-title" id="previewCreateTitle"><i class="fas fa-user-plus me-2"></i>إضافة طالب</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body">
                    <div class="row g-3"><div class="col-12"><label class="form-label" for="previewStudentName">اسم الطالب</label><input id="previewStudentName" type="text" class="form-control" placeholder="الاسم الثلاثي"></div><div class="col-md-6"><label class="form-label" for="previewStudentStage">المرحلة</label><select id="previewStudentStage" class="form-select"><option>المرحلة الابتدائية</option><option>المرحلة الإعدادية</option><option>المرحلة الثانوية</option></select></div><div class="col-md-6"><label class="form-label" for="previewStudentClass">الفصل</label><select id="previewStudentClass" class="form-select"><option>Flowers 1</option><option>Prep 1C</option><option>Sec 1A</option></select></div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="button" class="btn btn-success" data-preview-success="تمت محاكاة إضافة الطالب"><i class="fas fa-save me-1"></i>حفظ</button></div>
            </div>
        </div>
    </div>
 
    <div class="modal fade" id="previewEditModal" tabindex="-1" aria-labelledby="previewEditTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
                <div class="modal-header"><h5 class="modal-title" id="previewEditTitle"><i class="fas fa-pen me-2"></i>تعديل بيانات الطالب</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body"><label class="form-label" for="previewEditName">اسم الطالب</label><input id="previewEditName" class="form-control" value="آدم محمد عثمان"><label class="form-label mt-3" for="previewEditClass">الفصل</label><select id="previewEditClass" class="form-select"><option>Prep 1C</option><option>Flowers 1</option><option>Sec 1A</option></select></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="button" class="btn btn-primary" data-preview-success="تمت محاكاة تعديل البيانات"><i class="fas fa-save me-1"></i>حفظ التعديل</button></div>
            </div>
        </div>
    </div>
 
    <div class="modal fade" id="previewViewModal" tabindex="-1" aria-labelledby="previewViewTitle" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-view"><div class="modal-header"><h5 class="modal-title" id="previewViewTitle"><i class="fas fa-eye me-2"></i>بيانات الطالب</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><dl class="row mb-0"><dt class="col-sm-4">الاسم</dt><dd class="col-sm-8">آدم محمد عثمان</dd><dt class="col-sm-4">الكود</dt><dd class="col-sm-8">S20268101</dd><dt class="col-sm-4">الفصل</dt><dd class="col-sm-8">Prep 1C</dd><dt class="col-sm-4">الحالة</dt><dd class="col-sm-8"><span class="ui-preview-status ui-preview-status--active">نشط</span></dd></dl></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button></div></div></div>
    </div>
 
    <div class="modal fade" id="previewStatusModal" tabindex="-1" aria-labelledby="previewStatusTitle" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning"><div class="modal-header"><h5 class="modal-title" id="previewStatusTitle"><i class="fas fa-power-off me-2"></i>تغيير حالة الطالب</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body text-center"><i class="fas fa-toggle-on text-warning admin-modal-icon-lg mb-3"></i><p class="mb-0">سيتم تغيير حالة الطالب في المعاينة فقط.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="button" class="btn btn-warning" data-preview-success="تمت محاكاة تغيير الحالة"><i class="fas fa-power-off me-1"></i>تأكيد</button></div></div></div>
    </div>
 
    <div class="modal fade" id="previewDeleteModal" tabindex="-1" aria-labelledby="previewDeleteTitle" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><div class="modal-header"><h5 class="modal-title" id="previewDeleteTitle"><i class="fas fa-trash me-2"></i>حذف سجل الطالب</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-danger admin-modal-icon-lg mb-3"></i><p class="mb-0">لن يتم حذف أي بيانات حقيقية من هذه الصفحة التجريبية.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="button" class="btn btn-danger" data-preview-success="تمت محاكاة حذف السجل"><i class="fas fa-trash me-1"></i>حذف</button></div></div></div>
    </div>

    <div class="modal fade" id="previewAddStageModal" tabindex="-1" aria-labelledby="previewAddStageTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                <div class="modal-header">
                    <h5 class="modal-title" id="previewAddStageTitle"><i class="fas fa-plus me-2"></i>إضافة مرحلة دراسية جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="previewStageName">اسم المرحلة (عربي) <span class="text-danger">*</span></label><input id="previewStageName" class="form-control" placeholder="مثال: المرحلة الابتدائية"></div>
                        <div class="col-md-6"><label class="form-label" for="previewStageNameEn">اسم المرحلة (English) <span class="text-danger">*</span></label><input id="previewStageNameEn" class="form-control" placeholder="Example: Primary"></div>
                        <div class="col-md-6"><label class="form-label" for="previewStageCode">كود المرحلة <span class="text-danger">*</span></label><input id="previewStageCode" class="form-control" placeholder="primary"><div class="form-text">حروف إنجليزية صغيرة فقط بدون مسافات.</div></div>
                        <div class="col-md-6"><label class="form-label" for="previewStageOrder">ترتيب المرحلة <span class="text-danger">*</span></label><input id="previewStageOrder" type="number" class="form-control" value="1" min="1"></div>
                        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="previewStageVisible" checked><label class="form-check-label" for="previewStageVisible">عرض بطاقة هذه المرحلة في البوابة الرئيسية</label></div></div>
                        <div class="col-12"><label class="form-label" for="previewStageDescription">وصف بطاقة المرحلة في البوابة الرئيسية</label><textarea id="previewStageDescription" class="form-control" rows="2" placeholder="وصف مختصر يظهر أسفل اسم المرحلة"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="button" class="btn btn-success" data-preview-success="تمت محاكاة إضافة مرحلة دراسية"><i class="fas fa-save me-1"></i>حفظ</button></div>
            </div>
        </div>
    </div>
 
    <div class="modal fade" id="previewEditStageModal" tabindex="-1" aria-labelledby="previewEditStageTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
                <div class="modal-header">
                    <h5 class="modal-title" id="previewEditStageTitle"><i class="fas fa-pen me-2"></i>تعديل مرحلة دراسية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="previewEditStageName">اسم المرحلة (عربي) <span class="text-danger">*</span></label><input id="previewEditStageName" class="form-control" value="المرحلة الإعدادية"></div>
                        <div class="col-md-6"><label class="form-label" for="previewEditStageNameEn">اسم المرحلة (English) <span class="text-danger">*</span></label><input id="previewEditStageNameEn" class="form-control" value="Preparatory"></div>
                        <div class="col-md-6"><label class="form-label" for="previewEditStageCode">كود المرحلة <span class="text-danger">*</span></label><input id="previewEditStageCode" class="form-control" value="preparatory"></div>
                        <div class="col-md-6"><label class="form-label" for="previewEditStageOrder">ترتيب المرحلة <span class="text-danger">*</span></label><input id="previewEditStageOrder" type="number" class="form-control" value="2" min="1"></div>
                        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="previewEditStageVisible" checked><label class="form-check-label" for="previewEditStageVisible">عرض بطاقة هذه المرحلة في البوابة الرئيسية</label></div></div>
                        <div class="col-12"><label class="form-label" for="previewEditStageDescription">وصف بطاقة المرحلة في البوابة الرئيسية</label><textarea id="previewEditStageDescription" class="form-control" rows="2">مرحلة التعليم الإعدادي</textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="button" class="btn btn-primary" data-preview-success="تمت محاكاة تعديل المرحلة الدراسية"><i class="fas fa-save me-1"></i>حفظ التعديل</button></div>
            </div>
        </div>
    </div>
 
    <div class="modal fade" id="previewDisableStageModal" tabindex="-1" aria-labelledby="previewDisableStageTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
                <div class="modal-header"><h5 class="modal-title" id="previewDisableStageTitle"><i class="fas fa-ban me-2"></i>تعطيل المرحلة الدراسية</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-warning admin-modal-icon-lg mb-3"></i><p>هل تريد تعطيل <strong class="text-primary">المرحلة الإعدادية</strong>؟</p><div class="alert alert-warning text-start mb-0"><i class="fas fa-info-circle me-2"></i>لن تظهر المرحلة في القوائم الجديدة، مع الاحتفاظ بالصفوف والفصول والطلاب المرتبطين بها.</div></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="button" class="btn btn-warning" data-preview-success="تمت محاكاة تعطيل المرحلة الدراسية"><i class="fas fa-ban me-1"></i>تعطيل</button></div>
            </div>
        </div>
    </div>
 
    <div class="modal fade" id="previewDeleteStageModal" tabindex="-1" aria-labelledby="previewDeleteStageTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
                <div class="modal-header"><h5 class="modal-title" id="previewDeleteStageTitle"><i class="fas fa-trash me-2"></i>حذف مرحلة دراسية</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-danger admin-modal-icon-lg mb-3"></i><p>هل أنت متأكد من حذف <strong class="text-primary">المرحلة الإعدادية</strong>؟</p><div class="alert alert-warning text-start"><i class="fas fa-info-circle me-2"></i>سيتم التحقق أولاً من عدم وجود صفوف أو فصول مرتبطة بالمرحلة.</div><p class="text-danger mb-0"><i class="fas fa-exclamation-circle me-1"></i>هذا الإجراء لا يمكن التراجع عنه.</p></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="button" class="btn btn-danger" data-preview-success="تمت محاكاة حذف المرحلة الدراسية"><i class="fas fa-trash me-1"></i>حذف</button></div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo asset_url('../assets/js/admin_table_actions.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#uiPreview .modal').forEach(function (modalElement) {
        modalElement.classList.add('no-sort');
    });

    function showPreviewToast(message, tone) {
        var toastElement = document.getElementById('uiPreviewToast');
        var toastBody = document.getElementById('uiPreviewToastBody');
        if (!toastElement || !toastBody || typeof bootstrap === 'undefined') return;
        toastElement.className = 'toast align-items-center border-0 position-fixed bottom-0 start-0 m-3 ui-preview-toast bg-' + (tone || 'primary') + ' text-white';
        toastBody.innerHTML = '<i class="fas fa-circle-check me-2"></i>' + message;
        bootstrap.Toast.getOrCreateInstance(toastElement).show();
    }

    document.querySelectorAll('#uiPreview [title]').forEach(function (element) {
        if (typeof bootstrap !== 'undefined') {
            new bootstrap.Tooltip(element);
        }
    });

    initializeTableColumnSettings('uiPreviewTable', {
        previewColCode: 1,
        previewColStage: 3,
        previewColGrade: 4,
        previewColClass: 5,
        previewColDate: 6,
        previewColStatus: 7
    }, 'educore_ui_preview_columns');

    window.setTimeout(function () {
        var table = $('#uiPreviewTable').DataTable();
        table.page.len(10).draw(false);

        document.getElementById('uiPreviewFilterForm').addEventListener('submit', function (event) {
            event.preventDefault();
            table.column(3).search(document.getElementById('uiPreviewStage').value);
            table.column(4).search(document.getElementById('uiPreviewGrade').value);
            table.column(5).search(document.getElementById('uiPreviewClass').value).draw();
            showPreviewToast('تم تطبيق الفلاتر على جدول المعاينة', 'primary');
        });

        document.querySelectorAll('#uiPreviewFilterForm select').forEach(function (select) {
            select.addEventListener('change', function () {
                document.getElementById('uiPreviewFilterForm').dispatchEvent(new Event('submit', { cancelable: true }));
            });
        });

        document.getElementById('uiPreviewReset').addEventListener('click', function () {
            document.getElementById('uiPreviewFilterForm').reset();
            table.search('').columns().search('').draw();
            showPreviewToast('تمت إعادة تعيين الفلاتر', 'secondary');
        });
    }, 0);

    document.getElementById('previewImportButton').addEventListener('click', function () {
        document.getElementById('previewImportInput').click();
    });
    document.getElementById('previewImportInput').addEventListener('change', function () {
        showPreviewToast(this.files && this.files.length ? 'تم اختيار ملف للاستيراد التجريبي' : 'لم يتم اختيار ملف', 'info');
    });
    document.getElementById('previewExportButton').addEventListener('click', function () {
        exportTableToCsv('uiPreviewTable', 'ui-preview-students.csv');
    });
    document.getElementById('previewPrintButton').addEventListener('click', function () {
        window.print();
    });
    document.getElementById('previewPdfButton').addEventListener('click', function () {
        showPreviewToast('هذه محاكاة لتصدير PDF في صفحة الاختبار', 'danger');
    });
    document.getElementById('previewActivityButton').addEventListener('click', function (event) {
        event.preventDefault();
        showPreviewToast('سجل العمليات عنصر سياقي في هذا النموذج', 'secondary');
    });
    document.querySelectorAll('[data-preview-success]').forEach(function (button) {
        button.addEventListener('click', function () {
            var modalElement = this.closest('.modal');
            if (modalElement && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(modalElement).hide();
            }
            showPreviewToast(this.getAttribute('data-preview-success'), 'success');
        });
    });

    document.querySelectorAll('#uiPreview .modal').forEach(function (modalElement) {
        var dialog = modalElement.querySelector('.modal-dialog');
        var header = modalElement.querySelector('.admin-modal .modal-header');
        if (!dialog || !header) return;

        var dragging = false;
        var pointerOffsetX = 0;
        var pointerOffsetY = 0;

        header.style.cursor = 'grab';
        header.style.userSelect = 'none';

        header.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 || event.target.closest('button, input, select, textarea, a')) return;

            var rect = dialog.getBoundingClientRect();
            dialog.style.position = 'absolute';
            dialog.style.margin = '0';
            dialog.style.width = rect.width + 'px';
            dialog.style.boxSizing = 'border-box';
            dialog.style.left = rect.left + 'px';
            dialog.style.top = rect.top + 'px';
            dialog.style.right = 'auto';
            dialog.style.transform = 'none';

            dragging = true;
            pointerOffsetX = event.clientX - rect.left;
            pointerOffsetY = event.clientY - rect.top;
            header.style.cursor = 'grabbing';
            header.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        header.addEventListener('pointermove', function (event) {
            if (!dragging) return;

            var dialogWidth = dialog.offsetWidth;
            var dialogHeight = dialog.offsetHeight;
            var maxLeft = Math.max(0, window.innerWidth - dialogWidth);
            var maxTop = Math.max(0, window.innerHeight - dialogHeight);
            var left = Math.min(Math.max(0, event.clientX - pointerOffsetX), maxLeft);
            var top = Math.min(Math.max(0, event.clientY - pointerOffsetY), maxTop);

            dialog.style.left = left + 'px';
            dialog.style.top = top + 'px';
        });

        function stopDragging(event) {
            if (!dragging) return;
            dragging = false;
            header.style.cursor = 'grab';
            if (event && header.hasPointerCapture(event.pointerId)) {
                header.releasePointerCapture(event.pointerId);
            }
        }

        header.addEventListener('pointerup', stopDragging);
        header.addEventListener('pointercancel', stopDragging);

        modalElement.addEventListener('hidden.bs.modal', function () {
            dialog.style.removeProperty('position');
            dialog.style.removeProperty('margin');
            dialog.style.removeProperty('width');
            dialog.style.removeProperty('box-sizing');
            dialog.style.removeProperty('left');
            dialog.style.removeProperty('top');
            dialog.style.removeProperty('right');
            dialog.style.removeProperty('transform');
            dragging = false;
            header.style.cursor = 'grab';
        });
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
