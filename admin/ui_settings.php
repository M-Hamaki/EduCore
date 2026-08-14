<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$page_title = 'تفضيلات الواجهة والمظهر';
$custom_page_title = true;

// معالجة حفظ التفضيلات
$message = '';
$error = '';
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = 'تم حفظ كافة تفضيلات المظهر بنجاح! تم تطبيق التعديلات فورياً على جميع صفحات لوحة التحكم.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'فشل التحقق من رمز الحماية (CSRF). يرجى المحاولة مرة أخرى.';
    } else {
        // جمع كل الخيارات وتصفيتها للتأكد من أمانها وصحتها
        $layout = $_POST['student_sidebar_layout'] ?? 'flat';
        $theme = $_POST['sidebar_theme'] ?? 'light';
        $color = $_POST['accent_color'] ?? '#0078d4';
        $fontSize = $_POST['font_size'] ?? '100';
        $animation = $_POST['counter_animation'] ?? 'enabled';
        $btnStyle = $_POST['button_style'] ?? 'solid';
        $appTheme = $_POST['app_theme'] ?? 'light';
        $layoutDensity = $_POST['layout_density'] ?? 'cozy';
        $microInteractions = $_POST['micro_interactions'] ?? 'active';
        $tableHeaderStyle = $_POST['table_header_style'] ?? 'transparent';
        $statusBadgeStyle = $_POST['status_badge_style'] ?? 'subtle';
        $pageTitleStyle = $_POST['page_title_style'] ?? 'simple';

        $validColors = ['#0078d4', '#10b981', '#8b5cf6', '#ef4444', '#f97316'];
        $validSizes = ['100', '110', '120'];

        if (
            in_array($layout, ['flat', 'nested']) &&
            in_array($theme, ['light', 'dark']) &&
            in_array($color, $validColors) &&
            in_array($fontSize, $validSizes) &&
            in_array($animation, ['enabled', 'disabled']) &&
            in_array($btnStyle, ['solid', 'glass']) &&
            in_array($appTheme, ['light', 'dark']) &&
            in_array($layoutDensity, ['cozy', 'compact']) &&
            in_array($microInteractions, ['active', 'none']) &&
            in_array($tableHeaderStyle, ['transparent', 'accent', 'dark']) &&
            in_array($statusBadgeStyle, ['subtle', 'solid', 'outline']) &&
            in_array($pageTitleStyle, ['simple', 'gradient'])
        ) {
            Utilities::setUserPreference('student_sidebar_layout', $layout);
            Utilities::setUserPreference('sidebar_theme', $theme);
            Utilities::setUserPreference('accent_color', $color);
            Utilities::setUserPreference('font_size', $fontSize);
            Utilities::setUserPreference('counter_animation', $animation);
            Utilities::setUserPreference('button_style', $btnStyle);
            Utilities::setUserPreference('app_theme', $appTheme);
            Utilities::setUserPreference('layout_density', $layoutDensity);
            Utilities::setUserPreference('micro_interactions', $microInteractions);
            Utilities::setUserPreference('table_header_style', $tableHeaderStyle);
            Utilities::setUserPreference('status_badge_style', $statusBadgeStyle);
            Utilities::setUserPreference('page_title_style', $pageTitleStyle);

            // إعادة توجيه لتطبيق التفضيلات فوراً من خلال تحديث الصفحة
            header('Location: ui_settings.php?saved=1');
            exit;
        } else {
            $error = 'يوجد خيار غير صالح، يرجى التحقق وإعادة المحاولة.';
        }
    }
}

// جلب التفضيلات الحالية للمستخدم
$studentSidebarLayout = Utilities::getUserPreference('student_sidebar_layout', 'flat');
$sidebarTheme = Utilities::getUserPreference('sidebar_theme', 'light');
$accentColor = Utilities::getUserPreference('accent_color', '#0078d4');
$fontSize = Utilities::getUserPreference('font_size', '100');
$counterAnimation = Utilities::getUserPreference('counter_animation', 'enabled');
$buttonStyle = Utilities::getUserPreference('button_style', 'solid');
$appTheme = Utilities::getUserPreference('app_theme', 'light');
$layoutDensity = Utilities::getUserPreference('layout_density', 'cozy');
$microInteractions = Utilities::getUserPreference('micro_interactions', 'active');
$tableHeaderStyle = Utilities::getUserPreference('table_header_style', 'transparent');
$statusBadgeStyle = Utilities::getUserPreference('status_badge_style', 'subtle');
$pageTitleStyle = Utilities::getUserPreference('page_title_style', 'simple');

require_once '../includes/admin_header.php';
?>

<style>
/* تبويبات المظهر الفاخرة */
.nav-pills-premium {
    background: #f1f5f9;
    padding: 6px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}
.nav-pills-premium .nav-link {
    color: #475569;
    font-weight: 600;
    font-size: 0.9rem;
    padding: 10px 16px;
    border-radius: 8px;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.nav-pills-premium .nav-link.active {
    background-color: var(--ms-primary, #0078d4) !important;
    color: #ffffff !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
}
.tab-pane-animated {
    animation: slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
@keyframes slideUp {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* تأثيرات بطاقة التفضيلات */
.preference-section-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #4a5568;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.pref-card-group {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
.preference-card {
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    background-color: #ffffff;
    position: relative;
    overflow: hidden;
    height: 100%;
}
.preference-card:hover {
    border-color: var(--ms-primary, #3b82f6);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.04);
    transform: translateY(-2px);
}
.preference-radio {
    position: absolute;
    top: 15px;
    left: 15px;
    width: 18px;
    height: 18px;
    cursor: pointer;
}
[dir="rtl"] .preference-radio {
    left: auto;
    right: 15px;
}
.preference-card.active {
    border-color: var(--ms-primary, #2563eb);
    background-color: rgba(37, 99, 235, 0.01);
}
.preference-card .preview-icon-wrap {
    width: 42px;
    height: 42px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 12px;
    font-size: 1.25rem;
}
/* ألوان الخيارات المحددة */
.color-picker-wrapper {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 5px;
}
.color-option {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    cursor: pointer;
    border: 3px solid #ffffff;
    box-shadow: 0 0 0 2px #e2e8f0;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.1rem;
}
.color-option:hover {
    transform: scale(1.1);
}
.color-option.active {
    box-shadow: 0 0 0 2px var(--ms-primary, #2563eb);
    transform: scale(1.05);
}
/* لوحة المعاينة الحية */
.preview-sticky-panel {
    position: sticky;
    top: 80px;
}
.sidebar-mockup {
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
    background: #ffffff;
}
.sidebar-mockup-header {
    height: 45px;
    border-bottom: 1px solid #f1f5f9;
    padding: 0 15px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.3s ease;
}
.sidebar-mockup-body {
    display: flex;
    height: 310px;
}
.mockup-nav {
    width: 130px;
    border-left: 1px solid #f1f5f9;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: all 0.3s ease;
}
.mockup-nav-item {
    height: 26px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    padding: 0 8px;
    font-size: 0.72rem;
    color: #475569;
    font-weight: 500;
    transition: all 0.3s ease;
}
.mockup-nav-item.active {
    color: #ffffff;
    background: var(--ms-primary, #0078d4);
}
.mockup-sub-nav {
    padding-right: 12px;
    margin-top: 4px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    border-right: 1px solid #e2e8f0;
}
.mockup-sub-item {
    font-size: 0.65rem;
    color: #64748b;
    padding: 2px 6px;
}
.mockup-sub-title {
    font-size: 0.6rem;
    font-weight: 700;
    color: #94a3b8;
    padding: 4px 6px 2px;
    text-transform: uppercase;
}
.mockup-content {
    flex: 1;
    background: #faf9f8;
    padding: 15px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    transition: all 0.3s ease;
}
.mockup-card {
    background: #ffffff;
    border: 1px solid #edebe9;
    border-radius: 8px;
    padding: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    transition: all 0.25s ease;
}
.mockup-btn {
    height: 24px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 10px;
    font-size: 0.65rem;
    color: #ffffff;
    background: var(--ms-primary, #0078d4);
    border: none;
    transition: all 0.2s ease;
}
.mockup-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.6rem;
    margin-top: 5px;
}
.mockup-table th {
    padding: 4px;
    text-align: right;
    border-bottom: 1px solid #cbd5e1;
    transition: all 0.25s ease;
}
.mockup-table td {
    padding: 4px;
    border-bottom: 1px solid #edebe9;
}
.mockup-badge {
    display: inline-block;
    padding: 1px 4px;
    font-size: 0.55rem;
    font-weight: bold;
    border-radius: 4px;
    transition: all 0.25s ease;
}
</style>

<div class="container-fluid pt-3 pb-5">
    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-dark"><i class="fas fa-palette me-3 text-primary"></i>تفضيلات الواجهة والمظهر</h1>
            <p class="text-muted m-0">تخصيص كامل للمظهر والخطوط والألوان والقوائم الجانبية</p>
        </div>
    </div>

    <!-- رسائل التأكيد والخطأ -->
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- قسم الخيارات الإعدادية بنظام علامات التبويب -->
        <div class="col-lg-8">
            <!-- التبويبات الرئيسية -->
            <ul class="nav nav-pills nav-fill nav-pills-premium mb-4" id="prefTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="theme-tab" data-bs-toggle="tab" data-bs-target="#tab-theme" type="button" role="tab" aria-controls="tab-theme" aria-selected="true">
                        <i class="fas fa-paint-roller me-2"></i>السمة والألوان
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="layout-tab" data-bs-toggle="tab" data-bs-target="#tab-layout" type="button" role="tab" aria-controls="tab-layout" aria-selected="false">
                        <i class="fas fa-table-columns me-2"></i>تخطيط القوائم والجداول
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="effects-tab" data-bs-toggle="tab" data-bs-target="#tab-effects" type="button" role="tab" aria-controls="tab-effects" aria-selected="false">
                        <i class="fas fa-wand-magic-sparkles me-2"></i>الخطوط والمؤثرات
                    </button>
                </li>
            </ul>

            <form method="POST" id="preferencesForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="tab-content" id="prefTabsContent">
                    
                    <!-- التبويب الأول: السمة والألوان -->
                    <div class="tab-pane fade show active tab-pane-animated" id="tab-theme" role="tabpanel" aria-labelledby="theme-tab">
                        <!-- سمة مظهر النظام العامة (Dark Mode) -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h5 class="m-0 fw-bold text-dark"><i class="fas fa-laptop me-2 text-primary"></i>سمة مظهر النظام العامة (Dark Mode)</h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="pref-card-group">
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $appTheme === 'light' ? 'active' : ''; ?>" id="card-apptheme-light">
                                            <input type="radio" name="app_theme" value="light" class="form-check-input preference-radio" <?php echo $appTheme === 'light' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-warning-subtle text-warning">
                                                <i class="fas fa-sun"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">الوضع الفاتح الافتراضي</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                خلفية بيضاء نقية ومريحة للقراءة النهارية.
                                            </p>
                                        </div>
                                    </label>
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $appTheme === 'dark' ? 'active' : ''; ?>" id="card-apptheme-dark">
                                            <input type="radio" name="app_theme" value="dark" class="form-check-input preference-radio" <?php echo $appTheme === 'dark' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-dark text-white">
                                                <i class="fas fa-moon"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">الوضع الداكن للنظام</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                يحول الكروت والجداول والمودالات بالكامل إلى تدرجات داكنة مريحة للعين ليلاً.
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- سمة شريط التنقل الجانبي -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h5 class="m-0 fw-bold text-dark"><i class="fas fa-moon me-2 text-primary"></i>سمة شريط التنقل الجانبي (Sidebar Theme)</h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="pref-card-group">
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $sidebarTheme === 'light' ? 'active' : ''; ?>" id="card-theme-light">
                                            <input type="radio" name="sidebar_theme" value="light" class="form-check-input preference-radio" <?php echo $sidebarTheme === 'light' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-warning-subtle text-warning">
                                                <i class="fas fa-sun"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">الوضع الفاتح</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                خلفية بيضاء نظيفة ومريحة للقراءة.
                                            </p>
                                        </div>
                                    </label>
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $sidebarTheme === 'dark' ? 'active' : ''; ?>" id="card-theme-dark">
                                            <input type="radio" name="sidebar_theme" value="dark" class="form-check-input preference-radio" <?php echo $sidebarTheme === 'dark' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-dark text-white">
                                                <i class="fas fa-moon"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">الوضع الداكن الفاخر</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                مظهر غامق بلمسات زرقاء ورمادية فخمة لشريط القائمة الجانبية.
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- اللون الأساسي للواجهة (Accent Color) -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h5 class="m-0 fw-bold text-dark"><i class="fas fa-droplet me-2 text-primary"></i>اللون الأساسي للواجهة (Accent Theme)</h5>
                            </div>
                            <div class="card-body p-4">
                                <p class="text-muted small mb-3">سيتم تطبيق هذا اللون على الأزرار الرئيسية، الروابط النشطة، وجميع مؤشرات لوحة القيادة الخاصة بك:</p>
                                <div class="color-picker-wrapper">
                                    <input type="hidden" name="accent_color" id="accentColorInput" value="<?php echo $accentColor; ?>">
                                    
                                    <div class="color-option <?php echo $accentColor === '#0078d4' ? 'active' : ''; ?>" style="background-color: #0078d4;" data-color="#0078d4" onclick="selectAccentColor(this)" title="أزرق مايكروسوفت">
                                        <?php if ($accentColor === '#0078d4') echo '<i class="fas fa-check"></i>'; ?>
                                    </div>
                                    <div class="color-option <?php echo $accentColor === '#10b981' ? 'active' : ''; ?>" style="background-color: #10b981;" data-color="#10b981" onclick="selectAccentColor(this)" title="أخضر زمردي">
                                        <?php if ($accentColor === '#10b981') echo '<i class="fas fa-check"></i>'; ?>
                                    </div>
                                    <div class="color-option <?php echo $accentColor === '#8b5cf6' ? 'active' : ''; ?>" style="background-color: #8b5cf6;" data-color="#8b5cf6" onclick="selectAccentColor(this)" title="بنفسجي فاخر">
                                        <?php if ($accentColor === '#8b5cf6') echo '<i class="fas fa-check"></i>'; ?>
                                    </div>
                                    <div class="color-option <?php echo $accentColor === '#ef4444' ? 'active' : ''; ?>" style="background-color: #ef4444;" data-color="#ef4444" onclick="selectAccentColor(this)" title="أحمر قرمزي">
                                        <?php if ($accentColor === '#ef4444') echo '<i class="fas fa-check"></i>'; ?>
                                    </div>
                                    <div class="color-option <?php echo $accentColor === '#f97316' ? 'active' : ''; ?>" style="background-color: #f97316;" data-color="#f97316" onclick="selectAccentColor(this)" title="برتقالي دافئ">
                                        <?php if ($accentColor === '#f97316') echo '<i class="fas fa-check"></i>'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- التبويب الثاني: تخطيط القوائم والجداول -->
                    <div class="tab-pane fade tab-pane-animated" id="tab-layout" role="tabpanel" aria-labelledby="layout-tab">
                        <!-- تخطيط قائمة شؤون الطلاب -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h5 class="m-0 fw-bold text-dark"><i class="fas fa-bars-staggered me-2 text-primary"></i>تخطيط قائمة شؤون الطلاب</h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="pref-card-group">
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $studentSidebarLayout === 'flat' ? 'active' : ''; ?>" id="card-layout-flat">
                                            <input type="radio" name="student_sidebar_layout" value="flat" class="form-check-input preference-radio" <?php echo $studentSidebarLayout === 'flat' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-primary-subtle text-primary">
                                                <i class="fas fa-th-list"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">الوضع المسطح والمقسم</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                عرض روابط الطلاب مباشرة مقسمة بخطوط فاصلة وملاحظات ملونة.
                                            </p>
                                        </div>
                                    </label>
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $studentSidebarLayout === 'nested' ? 'active' : ''; ?>" id="card-layout-nested">
                                            <input type="radio" name="student_sidebar_layout" value="nested" class="form-check-input preference-radio" <?php echo $studentSidebarLayout === 'nested' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-success-subtle text-success">
                                                <i class="fas fa-folder"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">الوضع المطوي المتداخل</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                تجميع الروابط في مجموعات قابلة للطي (أكورديون) لتوفير المساحة.
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- تخصيص الجداول وشارات الحالات -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h5 class="m-0 fw-bold text-dark"><i class="fas fa-table-list me-2 text-primary"></i>تخصيص الجداول وشارات الحالات</h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="row">
                                    <!-- ترويسة الجداول -->
                                    <div class="col-md-6 mb-3 mb-md-0">
                                        <div class="preference-section-title"><i class="fas fa-heading text-secondary"></i>نمط ترويسة الجداول</div>
                                        <div class="pref-card-group" style="grid-template-columns: repeat(3, 1fr); margin-bottom:0;">
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $tableHeaderStyle === 'transparent' ? 'active' : ''; ?>" id="card-table-transparent">
                                                    <input type="radio" name="table_header_style" value="transparent" class="form-check-input preference-radio" <?php echo $tableHeaderStyle === 'transparent' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.8rem;">شفاف</span>
                                                </div>
                                            </label>
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $tableHeaderStyle === 'accent' ? 'active' : ''; ?>" id="card-table-accent">
                                                    <input type="radio" name="table_header_style" value="accent" class="form-check-input preference-radio" <?php echo $tableHeaderStyle === 'accent' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.8rem;">بلون الواجهة</span>
                                                </div>
                                            </label>
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $tableHeaderStyle === 'dark' ? 'active' : ''; ?>" id="card-table-dark">
                                                    <input type="radio" name="table_header_style" value="dark" class="form-check-input preference-radio" <?php echo $tableHeaderStyle === 'dark' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.8rem;">رمادي داكن</span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- تصميم شارات الحالات -->
                                    <div class="col-md-6">
                                        <div class="preference-section-title"><i class="fas fa-certificate text-secondary"></i>تصميم شارات الحالة (Status Badges)</div>
                                        <div class="pref-card-group" style="grid-template-columns: repeat(3, 1fr); margin-bottom:0;">
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $statusBadgeStyle === 'subtle' ? 'active' : ''; ?>" id="card-badge-subtle">
                                                    <input type="radio" name="status_badge_style" value="subtle" class="form-check-input preference-radio" <?php echo $statusBadgeStyle === 'subtle' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.8rem;">ناعم (Subtle)</span>
                                                </div>
                                            </label>
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $statusBadgeStyle === 'solid' ? 'active' : ''; ?>" id="card-badge-solid">
                                                    <input type="radio" name="status_badge_style" value="solid" class="form-check-input preference-radio" <?php echo $statusBadgeStyle === 'solid' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.8rem;">مصمت (Solid)</span>
                                                </div>
                                            </label>
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $statusBadgeStyle === 'outline' ? 'active' : ''; ?>" id="card-badge-outline">
                                                    <input type="radio" name="status_badge_style" value="outline" class="form-check-input preference-radio" <?php echo $statusBadgeStyle === 'outline' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.8rem;">مفرغ (Outline)</span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- تصميم عنوان الصفحة الرئيسي (Page Title Style) -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h5 class="m-0 fw-bold text-dark"><i class="fas fa-heading me-2 text-primary"></i>نمط عرض عناوين الصفحات</h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="pref-card-group">
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $pageTitleStyle === 'simple' ? 'active' : ''; ?>" id="card-title-simple">
                                            <input type="radio" name="page_title_style" value="simple" class="form-check-input preference-radio" <?php echo $pageTitleStyle === 'simple' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-secondary-subtle text-secondary">
                                                <i class="fas fa-font"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">عنوان نصي بسيط (الافتراضي)</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                عرض العناوين بشكل نصي تقليدي وبسيط كما هو متبع في كافة الصفحات.
                                            </p>
                                        </div>
                                    </label>
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $pageTitleStyle === 'gradient' ? 'active' : ''; ?>" id="card-title-gradient">
                                            <input type="radio" name="page_title_style" value="gradient" class="form-check-input preference-radio" <?php echo $pageTitleStyle === 'gradient' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-primary-subtle text-primary">
                                                <i class="fas fa-wand-magic-sparkles"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">بانر بتدرج لوني خفيف خلف العنوان</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                يضيف خلفية ناعمة بتدرج لوني خفيف وحدود واضحة خلف العناوين لتأثير جمالي فخم.
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- التبويب الثالث: الخطوط والمؤثرات -->
                    <div class="tab-pane fade tab-pane-animated" id="tab-effects" role="tabpanel" aria-labelledby="effects-tab">
                        <!-- الكثافة والخطوط والأزرار والتفاعلات -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h5 class="m-0 fw-bold text-dark"><i class="fas fa-font me-2 text-primary"></i>كثافة وتأثيرات العناصر والخطوط</h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="row">
                                    <!-- كثافة التخطيط -->
                                    <div class="col-md-6 mb-4">
                                        <div class="preference-section-title"><i class="fas fa-compress text-secondary"></i>كثافة التخطيط والمساحات</div>
                                        <div class="pref-card-group" style="grid-template-columns: repeat(2, 1fr); margin-bottom:0;">
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $layoutDensity === 'cozy' ? 'active' : ''; ?>" id="card-density-cozy">
                                                    <input type="radio" name="layout_density" value="cozy" class="form-check-input preference-radio" <?php echo $layoutDensity === 'cozy' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.85rem;">مريح (Cozy)</span>
                                                </div>
                                            </label>
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $layoutDensity === 'compact' ? 'active' : ''; ?>" id="card-density-compact">
                                                    <input type="radio" name="layout_density" value="compact" class="form-check-input preference-radio" <?php echo $layoutDensity === 'compact' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.85rem;">مدمج (Compact)</span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <!-- نمط الأزرار -->
                                    <div class="col-md-6 mb-4">
                                        <div class="preference-section-title"><i class="fas fa-wand-magic-sparkles text-secondary"></i>تأثير الأزرار</div>
                                        <div class="pref-card-group" style="grid-template-columns: repeat(2, 1fr); margin-bottom:0;">
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $buttonStyle === 'solid' ? 'active' : ''; ?>" id="card-btn-solid">
                                                    <input type="radio" name="button_style" value="solid" class="form-check-input preference-radio" <?php echo $buttonStyle === 'solid' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.85rem;">كلاسيكي مصمت</span>
                                                </div>
                                            </label>
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $buttonStyle === 'glass' ? 'active' : ''; ?>" id="card-btn-glass">
                                                    <input type="radio" name="button_style" value="glass" class="form-check-input preference-radio" <?php echo $buttonStyle === 'glass' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.85rem;">شبه شفاف (Glass)</span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- حجم الخط -->
                                    <div class="col-md-6 mb-3 mb-md-0">
                                        <div class="preference-section-title"><i class="fas fa-text-height text-secondary"></i>حجم الخط الافتراضي</div>
                                        <div class="pref-card-group" style="grid-template-columns: repeat(3, 1fr); margin-bottom:0;">
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $fontSize === '100' ? 'active' : ''; ?>" id="card-size-100">
                                                    <input type="radio" name="font_size" value="100" class="form-check-input preference-radio" <?php echo $fontSize === '100' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.9rem;">عادي</span>
                                                </div>
                                            </label>
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $fontSize === '110' ? 'active' : ''; ?>" id="card-size-110">
                                                    <input type="radio" name="font_size" value="110" class="form-check-input preference-radio" <?php echo $fontSize === '110' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 1rem;">متوسط</span>
                                                </div>
                                            </label>
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $fontSize === '120' ? 'active' : ''; ?>" id="card-size-120">
                                                    <input type="radio" name="font_size" value="120" class="form-check-input preference-radio" <?php echo $fontSize === '120' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 1.1rem;">كبير</span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- تفاعلات التحويم الحركية -->
                                    <div class="col-md-6">
                                        <div class="preference-section-title"><i class="fas fa-bolt text-secondary"></i>تأثيرات التحويم والحركة (Interactions)</div>
                                        <div class="pref-card-group" style="grid-template-columns: repeat(2, 1fr); margin-bottom:0;">
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $microInteractions === 'active' ? 'active' : ''; ?>" id="card-interact-active">
                                                    <input type="radio" name="micro_interactions" value="active" class="form-check-input preference-radio" <?php echo $microInteractions === 'active' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.85rem;">حركة مرنة تفاعلية</span>
                                                </div>
                                            </label>
                                            <label class="m-0">
                                                <div class="preference-card text-center p-2 <?php echo $microInteractions === 'none' ? 'active' : ''; ?>" id="card-interact-none">
                                                    <input type="radio" name="micro_interactions" value="none" class="form-check-input preference-radio" <?php echo $microInteractions === 'none' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                                    <span class="fw-bold d-block mt-2" style="font-size: 0.85rem;">بدون حركة (سريع وثابت)</span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- تأثير العدادات المتحركة -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h5 class="m-0 fw-bold text-dark"><i class="fas fa-circle-play me-2 text-primary"></i>حركة أرقام الإحصائيات (Count-up Animation)</h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="pref-card-group">
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $counterAnimation === 'enabled' ? 'active' : ''; ?>" id="card-anim-enabled">
                                            <input type="radio" name="counter_animation" value="enabled" class="form-check-input preference-radio" <?php echo $counterAnimation === 'enabled' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-primary-subtle text-primary">
                                                <i class="fas fa-gauge-high"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">تفعيل الحركة التدريجية</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                تتحرك الأرقام وتتصاعد عند دخولك للصفحة لتعطي شعوراً حيوياً للوحة القيادة.
                                            </p>
                                        </div>
                                    </label>
                                    <label class="m-0">
                                        <div class="preference-card <?php echo $counterAnimation === 'disabled' ? 'active' : ''; ?>" id="card-anim-disabled">
                                            <input type="radio" name="counter_animation" value="disabled" class="form-check-input preference-radio" <?php echo $counterAnimation === 'disabled' ? 'checked' : ''; ?> onchange="updateFormPreview()">
                                            <div class="preview-icon-wrap bg-secondary-subtle text-secondary">
                                                <i class="fas fa-square-full"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark mt-1 pe-4">إيقاف الحركة وتثبيت الرقم فوراً</h6>
                                            <p class="text-muted small mb-0" style="font-size: 0.72rem;">
                                                عرض القيمة الحقيقية مباشرة دون حركة تصاعدية.
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>

                <!-- أزرار الإجراءات للنموذج -->
                <div class="d-flex justify-content-end gap-2 border-top pt-4 mt-2">
                    <a href="index.php" class="btn btn-secondary px-4">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-1"></i>حفظ التفضيلات
                    </button>
                </div>
            </form>
        </div>

        <!-- المعاينة الحية (Live Preview) -->
        <div class="col-lg-4 mt-4 mt-lg-0">
            <div class="preview-sticky-panel">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                        <h5 class="m-0 fw-bold text-dark"><i class="fas fa-eye me-2 text-primary"></i>معاينة حية فورية</h5>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">تفاعلي</span>
                    </div>
                    <div class="card-body p-3">
                        <p class="text-muted small mb-3">تتغير هذه اللوحة الحية فورياً وفقاً للتفضيلات التي تختارها بالأعلى:</p>
                        
                        <!-- مجسم لوحة القيادة -->
                        <div class="sidebar-mockup" id="sidebarMock">
                            <!-- رأس مجسم لوحة القيادة -->
                            <div class="sidebar-mockup-header" id="mockHeader">
                                <div class="d-flex align-items-center gap-1">
                                    <span style="width: 6px; height: 6px; border-radius: 50%; background: #ef4444; display:inline-block;"></span>
                                    <span style="width: 6px; height: 6px; border-radius: 50%; background: #f59e0b; display:inline-block;"></span>
                                    <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display:inline-block;"></span>
                                </div>
                                <div id="mockTitleBanner" style="width: 130px; height: 16px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.55rem; font-weight: bold; color: #475569;">
                                    عنوان الصفحة الرئيسية
                                </div>
                            </div>
                            
                            <div class="sidebar-mockup-body">
                                <!-- مجسم القائمة الجانبية -->
                                <div class="mockup-nav" id="mockNav">
                                    <div class="mockup-nav-item active"><i class="fas fa-home me-1"></i>لوحة التحكم</div>
                                    <div class="mockup-nav-item"><i class="fas fa-user-graduate me-1"></i>شؤون الطلاب</div>
                                    
                                    <!-- روابط شؤون الطلاب الديناميكية في المعاينة -->
                                    <div id="mockStudentSubmenu">
                                        <!-- سيتم حقنه بواسطة الجافا سكربت بناءً على نمط القائمة المختار -->
                                    </div>
                                    
                                    <div class="mockup-nav-item"><i class="fas fa-cogs me-1"></i>الإعدادات</div>
                                </div>
                                
                                <!-- مجسم المحتوى -->
                                <div class="mockup-content" id="mockContent">
                                    <div class="mockup-card" id="mockCard1">
                                        <div style="width: 80px; height: 8px; background: #cbd5e1; border-radius: 4px; margin-bottom: 6px;"></div>
                                        <!-- مجسم العداد -->
                                        <div class="fw-bold" style="font-size: 1.1rem; color: #1e293b;" id="mockCounter">1,250</div>
                                    </div>
                                    
                                    <!-- مجسم جدول تفاعلي -->
                                    <div class="mockup-card" id="mockCardTable">
                                        <table class="mockup-table" id="mockTable">
                                            <thead>
                                                <tr id="mockTableHeaderRow">
                                                    <th>اسم الطالب</th>
                                                    <th>الحالة</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>أحمد علي</td>
                                                    <td><span class="mockup-badge" id="mockBadge1">نشط</span></td>
                                                </tr>
                                                <tr>
                                                    <td>سارة محمد</td>
                                                    <td><span class="mockup-badge" id="mockBadge2">معلق</span></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="mockup-card" id="mockCard2">
                                        <button class="mockup-btn" id="mockButton">حفظ التفضيلات</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card bg-light border-0 rounded-3 p-3 mt-3">
                            <div class="d-flex gap-2 align-items-start">
                                <i class="fas fa-info-circle text-primary mt-1"></i>
                                <p class="small text-muted mb-0 leading-relaxed">
                                    تغيير التفضيلات يحسن تجربة استخدامك الشخصية فقط. لا يؤثر على مظهر النظام لدى بقية المعلمين أو الطلاب.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function selectAccentColor(element) {
    const color = element.getAttribute('data-color');
    document.getElementById('accentColorInput').value = color;
    
    // إزالة الصف النشط من بقية الألوان وإضافته للخيار الحالي
    document.querySelectorAll('.color-option').forEach(opt => {
        opt.classList.remove('active');
        const checkIcon = opt.querySelector('.fa-check');
        if (checkIcon) checkIcon.remove();
    });
    
    element.classList.add('active');
    element.innerHTML = '<i class="fas fa-check"></i>';
    
    updateFormPreview();
}

function updateFormPreview() {
    // الحصول على القيم المحددة حالياً
    const appTheme = document.querySelector('input[name="app_theme"]:checked').value;
    const layout = document.querySelector('input[name="student_sidebar_layout"]:checked').value;
    const theme = document.querySelector('input[name="sidebar_theme"]:checked').value;
    const accentColor = document.getElementById('accentColorInput').value;
    const fontSize = document.querySelector('input[name="font_size"]:checked').value;
    const btnStyle = document.querySelector('input[name="button_style"]:checked').value;
    const counterAnim = document.querySelector('input[name="counter_animation"]:checked').value;
    const density = document.querySelector('input[name="layout_density"]:checked').value;
    const interactions = document.querySelector('input[name="micro_interactions"]:checked').value;
    
    const tableStyle = document.querySelector('input[name="table_header_style"]:checked').value;
    const badgeStyle = document.querySelector('input[name="status_badge_style"]:checked').value;
    const titleStyle = document.querySelector('input[name="page_title_style"]:checked').value;

    // تحديث وتنشيط كلاسات البطاقات المختارة بصرياً
    // 0. سمة التطبيق العامة
    document.getElementById('card-apptheme-light').classList.toggle('active', appTheme === 'light');
    document.getElementById('card-apptheme-dark').classList.toggle('active', appTheme === 'dark');

    // 1. نمط القائمة
    document.getElementById('card-layout-flat').classList.toggle('active', layout === 'flat');
    document.getElementById('card-layout-nested').classList.toggle('active', layout === 'nested');
    
    // 2. السمة لشريط التنقل
    document.getElementById('card-theme-light').classList.toggle('active', theme === 'light');
    document.getElementById('card-theme-dark').classList.toggle('active', theme === 'dark');
    
    // 3. حجم الخط
    document.getElementById('card-size-100').classList.toggle('active', fontSize === '100');
    document.getElementById('card-size-110').classList.toggle('active', fontSize === '110');
    document.getElementById('card-size-120').classList.toggle('active', fontSize === '120');

    // 4. نمط الأزرار
    document.getElementById('card-btn-solid').classList.toggle('active', btnStyle === 'solid');
    document.getElementById('card-btn-glass').classList.toggle('active', btnStyle === 'glass');

    // 5. الحركة
    document.getElementById('card-anim-enabled').classList.toggle('active', counterAnim === 'enabled');
    document.getElementById('card-anim-disabled').classList.toggle('active', counterAnim === 'disabled');

    // 6. الكثافة والتفاعلات
    document.getElementById('card-density-cozy').classList.toggle('active', density === 'cozy');
    document.getElementById('card-density-compact').classList.toggle('active', density === 'compact');
    document.getElementById('card-interact-active').classList.toggle('active', interactions === 'active');
    document.getElementById('card-interact-none').classList.toggle('active', interactions === 'none');

    // 7. الجداول والشارات والعناوين
    document.getElementById('card-table-transparent').classList.toggle('active', tableStyle === 'transparent');
    document.getElementById('card-table-accent').classList.toggle('active', tableStyle === 'accent');
    document.getElementById('card-table-dark').classList.toggle('active', tableStyle === 'dark');

    document.getElementById('card-badge-subtle').classList.toggle('active', badgeStyle === 'subtle');
    document.getElementById('card-badge-solid').classList.toggle('active', badgeStyle === 'solid');
    document.getElementById('card-badge-outline').classList.toggle('active', badgeStyle === 'outline');

    document.getElementById('card-title-simple').classList.toggle('active', titleStyle === 'simple');
    document.getElementById('card-title-gradient').classList.toggle('active', titleStyle === 'gradient');

    // تطبيق قيم الألوان على متغيرات المعاينة الحية
    document.documentElement.style.setProperty('--ms-primary', accentColor);
    
    // تحديث المظهر البصري لبطاقة المعاينة
    const sidebarMock = document.getElementById('sidebarMock');
    const mockNav = document.getElementById('mockNav');
    const mockHeader = document.getElementById('mockHeader');
    const mockButton = document.getElementById('mockButton');
    const mockCounter = document.getElementById('mockCounter');
    const mockContent = document.getElementById('mockContent');
    const mockCard1 = document.getElementById('mockCard1');
    const mockCard2 = document.getElementById('mockCard2');
    const mockCardTable = document.getElementById('mockCardTable');
    
    const mockTableHeaderRow = document.getElementById('mockTableHeaderRow');
    const mockBadge1 = document.getElementById('mockBadge1');
    const mockBadge2 = document.getElementById('mockBadge2');
    const mockTitleBanner = document.getElementById('mockTitleBanner');
    
    // 0. سمة التطبيق العامة (محتوى المعاينة)
    if (appTheme === 'dark') {
        mockContent.style.background = '#0b0f19';
        mockCard1.style.background = '#111827';
        mockCard1.style.borderColor = '#1f2937';
        mockCard2.style.background = '#111827';
        mockCard2.style.borderColor = '#1f2937';
        mockCardTable.style.background = '#111827';
        mockCardTable.style.borderColor = '#1f2937';
        mockCardTable.style.color = '#cbd5e1';
        mockCounter.style.color = '#f8fafc';
    } else {
        mockContent.style.background = '#faf9f8';
        mockCard1.style.background = '#ffffff';
        mockCard1.style.borderColor = '#edebe9';
        mockCard2.style.background = '#ffffff';
        mockCard2.style.borderColor = '#edebe9';
        mockCardTable.style.background = '#ffffff';
        mockCardTable.style.borderColor = '#edebe9';
        mockCardTable.style.color = '#242424';
        mockCounter.style.color = '#1e293b';
    }

    // 1. سمة لوحة التنقل
    if (theme === 'dark') {
        mockNav.style.background = '#0f172a';
        mockNav.style.borderColor = '#1e293b';
        mockHeader.style.background = '#1e293b';
        mockHeader.style.borderColor = '#334155';
        
        document.querySelectorAll('.mockup-nav-item:not(.active)').forEach(el => {
            el.style.color = '#94a3b8';
        });
    } else {
        mockNav.style.background = '#ffffff';
        mockNav.style.borderColor = '#f1f5f9';
        mockHeader.style.background = '#ffffff';
        mockHeader.style.borderColor = '#f1f5f9';
        
        document.querySelectorAll('.mockup-nav-item:not(.active)').forEach(el => {
            el.style.color = '#475569';
        });
    }

    // 2. نمط الأزرار بالمعاينة
    if (btnStyle === 'glass') {
        mockButton.style.backdropFilter = 'blur(4px)';
        mockButton.style.background = accentColor + 'cc';
        mockButton.style.boxShadow = '0 2px 6px rgba(0,0,0,0.06)';
    } else {
        mockButton.style.backdropFilter = 'none';
        mockButton.style.background = accentColor;
        mockButton.style.boxShadow = 'none';
    }

    // 3. حجم الخط بالمعاينة
    sidebarMock.style.fontSize = (fontSize === '120' ? '16px' : (fontSize === '110' ? '15px' : '14px'));

    // 4. حقن روابط قائمة شؤون الطلاب
    const mockStudentSubmenu = document.getElementById('mockStudentSubmenu');
    if (layout === 'flat') {
        mockStudentSubmenu.innerHTML = `
            <div class="mockup-sub-title">بيانات الطلاب</div>
            <div class="mockup-sub-item" style="color: ` + accentColor + `; font-weight: bold;"><i class="fas fa-circle me-1" style="font-size: 4px;"></i>المقيدين</div>
            <div class="mockup-sub-item">منقولين</div>
            <div class="mockup-sub-title" style="border-top: 1px solid #f1f5f9; padding-top: 4px; margin-top: 4px;">مستخرجات</div>
            <div class="mockup-sub-item">ملف الطالب</div>
        `;
    } else {
        mockStudentSubmenu.innerHTML = `
            <div class="mockup-sub-nav">
                <div class="mockup-sub-item fw-bold"><i class="fas fa-folder-open me-1 text-warning"></i>بيانات الطلاب</div>
                <div class="mockup-sub-item fw-bold"><i class="fas fa-folder me-1 text-warning"></i>مستخرجات</div>
            </div>
        `;
    }

    // 5. محاكاة العدادات وحركتها
    if (counterAnim === 'disabled') {
        mockCounter.innerText = '1,250';
    } else {
        mockCounter.innerText = '0';
        setTimeout(() => {
            mockCounter.innerText = '1,250';
        }, 150);
    }

    // 6. كثافة التخطيط (حجم الحشو في المعاينة)
    if (density === 'compact') {
        mockCard1.style.padding = '4px 8px';
        mockCard2.style.padding = '4px 8px';
        mockCardTable.style.padding = '4px 8px';
        mockContent.style.padding = '8px';
    } else {
        mockCard1.style.padding = '8px 12px';
        mockCard2.style.padding = '8px 12px';
        mockCardTable.style.padding = '8px 12px';
        mockContent.style.padding = '15px';
    }

    // 7. تفاعلات تحويم العناصر
    if (interactions === 'active') {
        mockCard1.onmouseenter = () => {
            mockCard1.style.transform = 'translateY(-2px)';
            mockCard1.style.boxShadow = '0 6px 12px rgba(0,0,0,0.06)';
        };
        mockCard1.onmouseleave = () => {
            mockCard1.style.transform = 'none';
            mockCard1.style.boxShadow = '0 2px 4px rgba(0, 0, 0, 0.02)';
        };
    } else {
        mockCard1.onmouseenter = null;
        mockCard1.onmouseleave = null;
        mockCard1.style.transform = 'none';
    }

    // 8. نمط ترويسة الجداول بالمعاينة
    if (tableStyle === 'accent') {
        mockTableHeaderRow.style.background = accentColor;
        mockTableHeaderRow.style.color = '#ffffff';
    } else if (tableStyle === 'dark') {
        mockTableHeaderRow.style.background = '#1e293b';
        mockTableHeaderRow.style.color = '#ffffff';
    } else {
        mockTableHeaderRow.style.background = 'transparent';
        mockTableHeaderRow.style.color = (appTheme === 'dark' ? '#cbd5e1' : '#475569');
    }

    // 9. شكل شارات الحالات بالمعاينة
    if (badgeStyle === 'solid') {
        mockBadge1.style.background = '#10b981';
        mockBadge1.style.color = '#ffffff';
        mockBadge1.style.border = 'none';
        mockBadge2.style.background = '#f59e0b';
        mockBadge2.style.color = '#ffffff';
        mockBadge2.style.border = 'none';
    } else if (badgeStyle === 'outline') {
        mockBadge1.style.background = 'transparent';
        mockBadge1.style.color = '#10b981';
        mockBadge1.style.border = '1px solid #10b981';
        mockBadge2.style.background = 'transparent';
        mockBadge2.style.color = '#f59e0b';
        mockBadge2.style.border = '1px solid #f59e0b';
    } else { // subtle
        mockBadge1.style.background = 'rgba(16, 185, 129, 0.12)';
        mockBadge1.style.color = '#10b981';
        mockBadge1.style.border = '1.5px solid rgba(16, 185, 129, 0.2)';
        mockBadge2.style.background = 'rgba(245, 158, 11, 0.12)';
        mockBadge2.style.color = '#d97706';
        mockBadge2.style.border = '1.5px solid rgba(245, 158, 11, 0.2)';
    }

    // 10. تصميم عنوان الصفحة بالمعاينة
    if (titleStyle === 'gradient') {
        mockTitleBanner.style.background = 'linear-gradient(135deg,' + accentColor + '12, ' + accentColor + '03)';
        mockTitleBanner.style.border = '1px solid ' + accentColor + '22';
        mockTitleBanner.style.color = (appTheme === 'dark' ? '#f8fafc' : '#000000');
    } else {
        mockTitleBanner.style.background = 'transparent';
        mockTitleBanner.style.border = 'none';
        mockTitleBanner.style.color = (appTheme === 'dark' ? '#cbd5e1' : '#475569');
    }
}

// تهيئة المعاينة الحية فور تحميل الصفحة وإعادة التبويب النشط
document.addEventListener('DOMContentLoaded', function() {
    // إعادة التبويب النشط من localStorage
    const savedTab = localStorage.getItem('pref_active_tab');
    if (savedTab) {
        const tabBtn = document.querySelector('[data-bs-target="' + savedTab + '"]');
        if (tabBtn) {
            // إزالة الكلاس النشط من كل التبويبات
            document.querySelectorAll('#prefTabs .nav-link').forEach(t => {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            document.querySelectorAll('.tab-pane').forEach(p => {
                p.classList.remove('show', 'active');
            });
            // تفعيل التبويب المحفوظ
            tabBtn.classList.add('active');
            tabBtn.setAttribute('aria-selected', 'true');
            const targetPane = document.querySelector(savedTab);
            if (targetPane) {
                targetPane.classList.add('show', 'active');
            }
        }
    }

    // حفظ التبويب النشط عند التبديل
    document.querySelectorAll('#prefTabs .nav-link').forEach(function(tabBtn) {
        tabBtn.addEventListener('shown.bs.tab', function(e) {
            localStorage.setItem('pref_active_tab', e.target.getAttribute('data-bs-target'));
        });
    });

    updateFormPreview();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
