<?php
$page_title = "أدوات النظام";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-toolbox me-2 text-primary"></i>أدوات النظام</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="school_settings.php?active_tab=years" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-calendar-days me-2"></i>الأعوام الدراسية
        </a>
    </div>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    هذه الصفحة تجمع الأدوات الإدارية التي لا تحتاج للظهور في لوحة التحكم اليومية.
</div>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>إجراءات إدارية</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-3 col-md-6">
                <a href="school_settings.php?active_tab=year_setup" class="btn btn-primary w-100 py-3">
                    <i class="fas fa-calendar-plus me-2"></i>
                    <strong>تهيئة عام دراسي جديد</strong>
                    <small class="d-block text-light mt-1">ترقية الطلاب وتجهيز العام</small>
                </a>
            </div>
            <div class="col-lg-3 col-md-6">
                <a href="evaluation_settings.php" class="btn btn-primary w-100 py-3">
                    <i class="fas fa-sliders-h me-2"></i>
                    <strong>إعدادات التقييمات</strong>
                    <small class="d-block text-light mt-1">التحكم في نظام التقييم</small>
                </a>
            </div>
            <div class="col-lg-3 col-md-6">
                <a href="assessment_reports.php" class="btn btn-outline-primary w-100 py-3">
                    <i class="fas fa-file-alt me-2"></i>
                    <strong>نوافذ التقارير</strong>
                    <small class="d-block text-muted mt-1">فترات وبنود التقارير المنشورة</small>
                </a>
            </div>
            <div class="col-lg-3 col-md-6">
                <a href="../generate_test_data.php" class="btn btn-outline-secondary w-100 py-3">
                    <i class="fas fa-database me-2"></i>
                    <strong>إضافة بيانات تجريبية</strong>
                    <small class="d-block text-muted mt-1">بيانات للاختبار فقط</small>
                </a>
            </div>
            <div class="col-lg-3 col-md-6">
                <a href="../delete_test_data.php" class="btn btn-outline-warning w-100 py-3">
                    <i class="fas fa-trash-alt me-2"></i>
                    <strong>حذف البيانات التجريبية</strong>
                    <small class="d-block text-muted mt-1">إزالة البيانات الوهمية</small>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="card shadow border-danger border-2">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="fas fa-radiation me-2"></i>المنطقة الخطرة</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-danger text-center">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>تحذير:</strong> هذه الإجراءات عالية الخطورة. تأكد من وجود نسخة احتياطية حديثة قبل استخدامها.
        </div>

        <div class="row g-3">
            <div class="col-lg-3 col-md-6">
                <a href="manage_backups.php" class="btn btn-primary w-100 py-3">
                    <i class="fas fa-database me-2"></i>
                    <strong>النسخ الاحتياطية</strong>
                    <small class="d-block text-light mt-1">استرجاع وإدارة النسخ</small>
                </a>
            </div>
            <div class="col-lg-3 col-md-6">
                <a href="reset_points.php" class="btn btn-danger w-100 py-3">
                    <i class="fas fa-eraser me-2"></i>
                    <strong>تصفير النقاط</strong>
                    <small class="d-block text-light mt-1">حذف جميع التقييمات</small>
                </a>
            </div>
            <div class="col-lg-3 col-md-6">
                <a href="../install.php?force=1" class="btn btn-danger w-100 py-3">
                    <i class="fas fa-sync-alt me-2"></i>
                    <strong>إعادة تهيئة كاملة</strong>
                    <small class="d-block text-light mt-1">إعادة تثبيت النظام</small>
                </a>
            </div>
            <div class="col-lg-3 col-md-6">
                <a href="../clear_all_data.php" class="btn btn-danger w-100 py-3 border-3">
                    <i class="fas fa-trash me-2"></i>
                    <strong>مسح جميع البيانات</strong>
                    <small class="d-block text-light mt-1">عملية غير قابلة للتراجع</small>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
