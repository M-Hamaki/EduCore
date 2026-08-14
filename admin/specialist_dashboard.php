<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../classes/AcademicYear.php';
require_once '../src/Modules/Staff/SpecialistDashboardQuery.php';

Utilities::validateSession('admin');

if (!Utilities::isActingAsSpecialist()) {
    header('Location: index.php');
    exit;
}

// POST Ajax Handler for Specialist Todo List
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $storageDir = dirname(__DIR__) . '/storage/private';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    
    $todoFile = $storageDir . '/todos_' . $userId . '.json';
    
    if ($_POST['action'] === 'get_todos') {
        $todos = [];
        if (file_exists($todoFile)) {
            $todos = json_decode((string) @file_get_contents($todoFile), true) ?: [];
        }
        echo json_encode(['success' => true, 'todos' => $todos]);
        exit;
    }
    
    if ($_POST['action'] === 'save_todos') {
        $todosJson = $_POST['todos'] ?? '[]';
        $todos = json_decode($todosJson, true);
        if (!is_array($todos)) {
            echo json_encode(['success' => false, 'error' => 'Invalid todos data']);
            exit;
        }
        file_put_contents($todoFile, json_encode($todos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$db = (new Database())->getConnection();
$academicYear = AcademicYear::getCurrent($db);
$academicYearId = (int) ($academicYear['id'] ?? 0);
$activeSpecialistRole = (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? 'specialist');
$allowedPages = Utilities::getAllowedAdminPagesForRole($activeSpecialistRole) ?? [];

$dashboardData = (new \EduCore\Modules\Staff\SpecialistDashboardQuery($db))->data(
    (int) ($_SESSION['user_id'] ?? 0),
    $academicYearId,
    $activeSpecialistRole
);
$scopeRows = $dashboardData['scope_rows'];
$studentCount = $dashboardData['student_count'];
$pendingCount = $dashboardData['pending_count'];

$gradeNames = [];
$groupedScope = [];
foreach ($scopeRows as $scopeRow) {
    $gradeId = (int) $scopeRow['grade_id'];
    $gradeName = (string) $scopeRow['grade_name'];
    $className = (string) $scopeRow['class_name'];
    $gradeNames[$gradeId] = $gradeName;
    if (!isset($groupedScope[$gradeName])) {
        $groupedScope[$gradeName] = [];
    }
    $groupedScope[$gradeName][] = $className;
}


$services = [
    ['students.php', 'fa-user-graduate', 'الطلاب', 'عرض ملفات الطلاب وتقديم تعديلاتهم للمراجعة.', 'primary'],
    ['specialist_requests.php', 'fa-paper-plane', 'طلباتي', 'متابعة تعديلاتك ومعرفة قرار الإدارة وسبب الرفض أو التعارض.', 'warning'],
    ['class_lists.php', 'fa-list-ol', 'قوائم الفصول', 'متابعة طلاب الفصول وطلبات نقلهم بين الفصول.', 'info'],
    ['attendance.php', 'fa-calendar-check', 'الحضور والغياب', 'تسجيل ومراجعة حضور طلاب الفصول المسندة.', 'success'],
    ['student_file.php', 'fa-folder-open', 'ملفات الطلاب', 'الوصول إلى ملفات الطلاب وتقاريرهم المتاحة.', 'warning'],
    ['student_id_cards.php', 'fa-id-card', 'بطاقات الطلاب', 'إنشاء وعرض بطاقات طلاب الصفوف والفصول المسندة.', 'purple'],
    ['export_students.php', 'fa-file-export', 'تصدير الطلاب', 'تصدير بيانات الطلاب ضمن نطاقك الأكاديمي.', 'success'],
    ['student_statistics.php', 'fa-chart-pie', 'إحصائيات الطلاب', 'قراءة مؤشرات وإحصائيات طلابك.', 'info'],
    ['calculation_tools.php', 'fa-calculator', 'أدوات الحساب', 'استخدام الأدوات الحسابية على بيانات نطاقك.', 'secondary'],
    ['student_evaluations.php', 'fa-star-half-alt', 'تقييمات الطلاب', 'إدارة تقييمات الطلاب في فصولك.', 'warning'],
    ['teacher_evaluations.php', 'fa-chalkboard-teacher', 'تقييمات المعلمين', 'متابعة المعلمين المرتبطين بفصولك.', 'primary'],
    ['evaluation_analytics.php', 'fa-chart-line', 'تحليلات التقييم', 'تحليل نتائج التقييمات المرتبطة بنطاقك.', 'success'],
    ['evaluation_reports.php', 'fa-file-alt', 'تقارير التقييم', 'عرض تقارير التقييم التفصيلية لفصولك.', 'info'],
    ['student_clinic.php', 'fa-clinic-medical', 'زيارات العيادة', 'عرض سجل زيارات العيادة لطلاب فصولك.', 'danger'],
];

$services = array_values(array_filter(
    $services,
    static fn(array $service): bool => in_array($service[0], $allowedPages, true)
));
$services[] = ['../staff_hr_portal.php', 'fa-people-roof', 'خدمات شؤون العاملين', 'الأذونات والإجازات والاعتمادات ومنصة ارتق بهوية العامل.', 'primary'];

$page_title = 'لوحة تحكم الأخصائي';
$custom_page_title = true;
$adminAssetOptions = [
    'datatables' => false,
    'sweetalert' => false,
    'sortable' => false,
    'instant_attachment_upload' => false,
    'dashboard_sortable' => false,
];
require_once '../includes/admin_header.php';
?>

<style>
/* Specialist Dashboard Premium Enhancements */
.premium-card {
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1) !important;
    border-radius: 16px !important;
    border: 1px solid #e2e8f0 !important;
    background: #ffffff !important;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.04), 0 2px 4px -1px rgba(0, 0, 0, 0.02) !important;
}
.premium-card:hover {
    transform: translateY(-4px) !important;
    box-shadow: 0 12px 24px -4px rgba(0, 0, 0, 0.08), 0 8px 16px -6px rgba(0, 0, 0, 0.06) !important;
    border-color: #cbd5e1 !important;
}
.premium-card .fa-arrow-left {
    transition: transform 0.25s ease;
}
.premium-card:hover .fa-arrow-left {
    transform: translateX(-5px);
}
/* Glassmorphic interactive badges & scrollable container */
.badge-glass {
    background: rgba(255, 255, 255, 0.12) !important;
    border: 1px solid rgba(255, 255, 255, 0.18) !important;
    backdrop-filter: blur(5px) !important;
    -webkit-backdrop-filter: blur(5px) !important;
    transition: all 0.25s ease !important;
    font-size: 0.8rem !important;
    font-weight: 600 !important;
    color: #ffffff !important;
}
.badge-glass:hover {
    background: rgba(255, 255, 255, 0.22) !important;
    border-color: rgba(255, 255, 255, 0.35) !important;
    transform: translateY(-2px) !important;
    color: #fbbf24 !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
}
.assigned-classes-scroll {
    max-height: 115px;
    overflow-y: auto;
    padding-inline-end: 4px;
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.3) transparent;
}
.assigned-classes-scroll::-webkit-scrollbar {
    width: 5px;
}
.assigned-classes-scroll::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 10px;
}
.assigned-classes-scroll::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.25);
    border-radius: 10px;
}
.assigned-classes-scroll::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.4);
}
@media (min-width: 992px) {
    .divider-left-panel {
        border-inline-start: 1px solid rgba(255, 255, 255, 0.15) !important;
        padding-inline-start: 1.75rem !important;
    }
}

/* Color themes for services card on hover */
.service-card-primary:hover {
    border-color: #3b82f6 !important;
    box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.15) !important;
}
.service-card-warning:hover {
    border-color: #f59e0b !important;
    box-shadow: 0 10px 25px -5px rgba(245, 158, 11, 0.15) !important;
}
.service-card-info:hover {
    border-color: #0ea5e9 !important;
    box-shadow: 0 10px 25px -5px rgba(14, 165, 233, 0.15) !important;
}
.service-card-success:hover {
    border-color: #10b981 !important;
    box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.15) !important;
}
.service-card-purple:hover {
    border-color: #8b5cf6 !important;
    box-shadow: 0 10px 25px -5px rgba(139, 92, 246, 0.15) !important;
}
.service-card-danger:hover {
    border-color: #ef4444 !important;
    box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.15) !important;
}
.service-card-secondary:hover {
    border-color: #64748b !important;
    box-shadow: 0 10px 25px -5px rgba(100, 116, 139, 0.15) !important;
}

/* Service Card Icon rotation/scale effect */
.premium-card:hover .service-icon-wrapper i {
    transform: scale(1.15) rotate(-5deg);
}
.service-icon-wrapper i {
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Todo custom styles */
.todo-item {
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
    border-radius: 12px !important;
    border: 1px solid #e2e8f0 !important;
}
.todo-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px -2px rgba(50, 50, 93, 0.08), 0 3px 7px -3px rgba(0, 0, 0, 0.05) !important;
}
.custom-checkbox-wrapper {
    position: relative;
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}
.todo-checkbox-custom {
    width: 20px;
    height: 20px;
    border: 2px solid #cbd5e1;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-inline-end: 10px;
    transition: all 0.2s ease;
    background: #ffffff;
    color: transparent;
    font-size: 0.75rem;
}
.todo-checkbox:checked + .todo-checkbox-custom {
    background: #2563eb;
    border-color: #2563eb;
    color: #ffffff;
}
.todo-checkbox {
    position: absolute;
    opacity: 0;
    cursor: pointer;
    height: 0;
    width: 0;
}
@keyframes float-circle-1 {
    0% { transform: translateY(0px) rotate(0deg); }
    50% { transform: translateY(-15px) rotate(180deg); }
    100% { transform: translateY(0px) rotate(360deg); }
}
@keyframes float-circle-2 {
    0% { transform: translateY(0px) scale(1); }
    50% { transform: translateY(12px) scale(1.06); }
    100% { transform: translateY(0px) scale(1); }
}
.circle-glow-container {
    position: absolute;
    pointer-events: none;
    z-index: 0;
    transition: all 1.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
.floating-glowing-circle {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    transition: all 1.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
.badge-glass-glow {
    background: rgba(255, 255, 255, 0.07) !important;
    border: 1.5px solid rgba(251, 191, 36, 0.4) !important;
    backdrop-filter: blur(12px) !important;
    -webkit-backdrop-filter: blur(12px) !important;
    transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2) !important;
}
.badge-glass-glow:hover {
    background: rgba(251, 191, 36, 0.18) !important;
    border-color: rgba(251, 191, 36, 0.7) !important;
    transform: translateY(-3px) scale(1.03) !important;
    box-shadow: 0 10px 25px rgba(251, 191, 36, 0.3) !important;
    color: #ffffff !important;
}
.metric-pod-enterprise {
    background: rgba(255, 255, 255, 0.035) !important;
    border: 1px solid rgba(255, 255, 255, 0.08) !important;
    backdrop-filter: blur(12px) !important;
    -webkit-backdrop-filter: blur(12px) !important;
    border-radius: 20px !important;
    transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.metric-pod-enterprise:hover {
    background: rgba(255, 255, 255, 0.075) !important;
    border-color: rgba(255, 255, 255, 0.22) !important;
    transform: translateY(-4px) !important;
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.28) !important;
}
.metric-pod {
    transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
.welcome-parent-card:hover .metric-pod {
    background: rgba(255, 255, 255, 0.055) !important;
    border-color: rgba(255, 255, 255, 0.12) !important;
}
.metric-pod:hover {
    background: rgba(255, 255, 255, 0.09) !important;
    border-color: rgba(255, 255, 255, 0.22) !important;
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

/* Notebook Paper Decoration */
.todo-notebook-card {
    background-color: #ffffff !important;
    position: relative;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.06) !important;
    border-radius: 20px !important;
    overflow: hidden;
}
.todo-notebook-card::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    right: 32px;
    width: 2px;
    background: rgba(239, 68, 68, 0.18);
    pointer-events: none;
    z-index: 10;
}
.todo-notebook-body {
    background-size: 24px 24px;
    background-image: linear-gradient(to right, rgba(37, 99, 235, 0.015) 1px, transparent 1px), linear-gradient(to bottom, rgba(37, 99, 235, 0.015) 1px, transparent 1px);
    padding-right: 48px !important; /* spacing for the red margin line */
}
.todo-input-group {
    border: 1px solid #cbd5e1 !important;
    background: #ffffff !important;
    transition: all 0.3s ease !important;
}
.todo-input-group:focus-within {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15) !important;
}
.todo-item {
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
.todo-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 12px rgba(0, 0, 0, 0.06) !important;
}
</style>

<div class="admin-page-heading animate-up">
    <h1 class="h2"><i class="fas fa-user-shield me-2 text-primary"></i>لوحة تحكم الأخصائي</h1>
</div>

<div class="card welcome-parent-card border-0 shadow-lg text-white overflow-hidden mb-5 animate-up" style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%); border-radius: 28px; position: relative; border: 1px solid rgba(255, 255, 255, 0.1) !important; box-shadow: 0 20px 45px -10px rgba(15, 23, 42, 0.45) !important;">
    <!-- Premium glowing aura orbs -->
    <div style="position: absolute; top: -100px; right: -100px; width: 320px; height: 320px; background: radial-gradient(circle, rgba(99, 102, 241, 0.25) 0%, rgba(99, 102, 241, 0) 70%); filter: blur(45px); pointer-events: none;"></div>
    <div style="position: absolute; bottom: -100px; left: -100px; width: 320px; height: 320px; background: radial-gradient(circle, rgba(251, 191, 36, 0.2) 0%, rgba(251, 191, 36, 0) 70%); filter: blur(45px); pointer-events: none;"></div>
    
    <!-- Background Watermark Icon -->
    <div class="watermark-bg-icon" style="position: absolute; left: 4%; bottom: -30px; font-size: 16rem; color: rgba(255, 255, 255, 0.025); transform: rotate(-15deg); pointer-events: none; z-index: 0; user-select: none;">
        <i class="fas fa-user-shield"></i>
    </div>
    
    <div class="card-body p-4 p-lg-5" style="position: relative; z-index: 1;">
        <!-- Top Sleek Inspirational Quote Line -->
        <div class="p-3.5 px-4 mb-4 text-center" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 18px; backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);">
            <div class="d-flex align-items-center justify-content-center gap-3 text-center">
                <span style="color: #fbbf24; font-size: 1.35rem; filter: drop-shadow(0 0 8px rgba(251, 191, 36, 0.5));"><i class="fas fa-quote-right"></i></span>
                <p class="mb-0 fw-bold text-white fs-5" style="letter-spacing: 0.3px; line-height: 1.6; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.4);">
                    "إن التربية والتعليم رسالةٌ سامية وأمانةٌ عظيمة، والطلاب هم أبناؤنا وبناة الغد؛ فاجعل أثرك فيهم جميلاً."
                </p>
                <span style="color: #fbbf24; font-size: 1.35rem; filter: drop-shadow(0 0 8px rgba(251, 191, 36, 0.5));"><i class="fas fa-quote-left"></i></span>
            </div>
        </div>

        <div class="row g-4 align-items-stretch text-white">
            <!-- Right Panel: Greetings & Scope Button -->
            <div class="col-lg-5">
                <div class="metric-pod-enterprise p-4 d-flex flex-column justify-content-between h-100">
                    <div>
                        <div class="d-inline-flex align-items-center gap-2 rounded-pill px-3 py-1.5 mb-3" style="background: rgba(251, 191, 36, 0.12); border: 1px solid rgba(251, 191, 36, 0.3); color: #fbbf24; font-weight: 700; font-size: 0.8rem;">
                            <i class="fas fa-sparkles"></i>
                            <span>مساحتك المخصصة للمتابعة</span>
                        </div>
                        <h1 class="display-6 fw-bold mb-2 text-white" style="letter-spacing: -0.5px; font-size: 1.95rem; line-height: 1.35;">
                            مرحبًا بك أ. <span style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; text-shadow: 0 4px 15px rgba(251, 191, 36, 0.25); font-weight: 800;"><?php echo htmlspecialchars((string) ($_SESSION['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span> 👋
                        </h1>
                        <p class="text-white-50 mb-4" style="font-size: 0.925rem; font-weight: 400; line-height: 1.6;">تظهر لك في هذه اللوحة الإحصائيات الفورية والبيانات الخاصة بالصفوف والفصول المسندة إليك فقط للعام الحالي.</p>
                    </div>
                    
                    <?php if ($groupedScope !== []): ?>
                        <div>
                            <button type="button" class="btn badge-glass-glow rounded-pill px-4 py-2.5 text-white d-inline-flex align-items-center justify-content-between w-100 gap-2" data-bs-toggle="modal" data-bs-target="#assignedScopeModal" style="font-size: 0.85rem; font-weight: 700;">
                                <span class="d-flex align-items-center gap-2">
                                    <i class="fas fa-layer-group" style="color: #fbbf24;"></i>
                                    <span>نطاق الفصول المسندة (<?php echo count($groupedScope); ?> صفوف / <?php echo count($scopeRows); ?> فصل)</span>
                                </span>
                                <i class="fas fa-chevron-left text-warning" style="font-size: 0.8rem;"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Left Panel: Live Dashboard Stats Tiles -->
            <div class="col-lg-7 divider-left-panel">
                <div class="row g-3 g-md-4">
                    <!-- Metric 1: Academic Year -->
                    <div class="col-sm-6">
                        <div class="metric-pod-enterprise p-3.5 p-md-4 d-flex align-items-center gap-3 h-100">
                            <div class="rounded-4 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 52px; height: 52px; background: linear-gradient(135deg, rgba(59, 130, 246, 0.25), rgba(37, 99, 235, 0.1)); border: 1px solid rgba(59, 130, 246, 0.35); box-shadow: 0 4px 15px rgba(59, 130, 246, 0.15);">
                                <i class="fas fa-calendar-days fa-lg text-info"></i>
                            </div>
                            <div>
                                <div class="small text-white-50 mb-1" style="font-size: 0.75rem; font-weight: 600;">العام الدراسي الحالي</div>
                                <div class="fw-bold text-white mb-0" dir="ltr" style="font-size: 1.15rem; letter-spacing: 0.5px;"><?php echo htmlspecialchars((string) ($academicYear['name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-white-50 mt-1" style="font-size: 0.7rem;">(<?php echo count($gradeNames); ?> صفوف / <?php echo count($scopeRows); ?> فصول)</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Metric 2: Students in Scope -->
                    <div class="col-sm-6">
                        <div class="metric-pod-enterprise p-3.5 p-md-4 d-flex align-items-center gap-3 h-100">
                            <div class="rounded-4 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 52px; height: 52px; background: linear-gradient(135deg, rgba(16, 185, 129, 0.25), rgba(5, 150, 105, 0.1)); border: 1px solid rgba(16, 185, 129, 0.35); box-shadow: 0 4px 15px rgba(16, 185, 129, 0.15);">
                                <i class="fas fa-user-graduate fa-lg text-success"></i>
                            </div>
                            <div>
                                <div class="small text-white-50 mb-1" style="font-size: 0.75rem; font-weight: 600;">الطلاب في نطاقك</div>
                                <div class="h4 fw-bold text-white mb-0"><span class="counter" data-target="<?php echo $studentCount; ?>">0</span></div>
                                <div class="text-white-50 mt-1" style="font-size: 0.7rem;">مسجلون بالدراسة</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Metric 3: Pending Tasks -->
                    <div class="col-sm-6">
                        <div class="metric-pod-enterprise p-3.5 p-md-4 d-flex align-items-center gap-3 h-100">
                            <div class="rounded-4 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 52px; height: 52px; background: linear-gradient(135deg, rgba(245, 158, 11, 0.25), rgba(217, 119, 6, 0.1)); border: 1px solid rgba(245, 158, 11, 0.35); box-shadow: 0 4px 15px rgba(245, 158, 11, 0.15);">
                                <i class="fas fa-clipboard-list fa-lg text-warning"></i>
                            </div>
                            <div>
                                <div class="small text-white-50 mb-1" style="font-size: 0.75rem; font-weight: 600;">المهام المعلقة اليوم</div>
                                <div class="h4 fw-bold text-white mb-0"><span id="todo-pending-counter">0</span></div>
                                <div class="text-white-50 mt-1" style="font-size: 0.7rem;">بانتظار المتابعة</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Metric 4: Pending Edits -->
                    <div class="col-sm-6">
                        <div class="metric-pod-enterprise p-3.5 p-md-4 d-flex align-items-center gap-3 h-100">
                            <div class="rounded-4 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 52px; height: 52px; background: linear-gradient(135deg, rgba(168, 85, 247, 0.25), rgba(126, 34, 206, 0.1)); border: 1px solid rgba(168, 85, 247, 0.35); box-shadow: 0 4px 15px rgba(168, 85, 247, 0.15);">
                                <i class="fas fa-hourglass-half fa-lg text-purple" style="color: #c084fc !important;"></i>
                            </div>
                            <div>
                                <div class="small text-white-50 mb-1" style="font-size: 0.75rem; font-weight: 600;">طلبات تعديل معلقة</div>
                                <div class="h4 fw-bold text-white mb-0"><span class="counter" data-target="<?php echo $pendingCount; ?>">0</span></div>
                                <div class="text-white-50 mt-1" style="font-size: 0.7rem;">قيد مراجعة الإدارة</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-5 pb-3">
    <!-- Right Column: Services Grid (الخدمات المتاحة) -->
    <div class="col-lg-6 col-xl-7 animate-up delay-2">
        <div class="d-flex justify-content-between flex-wrap align-items-center gap-2 mb-3">
            <div>
                <h2 class="h4 mb-1 text-dark"><i class="fas fa-grip me-2 text-primary"></i>الخدمات المتاحة لك</h2>
                <p class="text-muted mb-0">اختر الخدمة التي تريد البدء بها.</p>
            </div>
            <span class="badge px-3 py-2 fw-bold" style="background: rgba(37, 99, 235, 0.12) !important; color: #2563eb !important; border: 1px solid rgba(37, 99, 235, 0.2) !important;"><?php echo count($services); ?> خدمة</span>
        </div>
        
        <div class="row row-cols-1 row-cols-sm-2 row-cols-xxl-3 g-3">
            <?php foreach ($services as [$href, $icon, $title, $description, $color]): ?>
                <div class="col">
                    <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" class="card premium-card service-card-<?php echo $color; ?> h-100 text-decoration-none text-body">
                        <div class="card-body d-flex align-items-center gap-2 gap-sm-3 p-2 p-sm-3">
                            <div class="service-icon-wrapper rounded-3 bg-<?php echo $color; ?>-subtle text-<?php echo $color; ?> d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px; min-width: 42px;">
                                <i class="fas <?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                            </div>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <h3 class="h6 fw-bold mb-1 text-dark text-truncate" style="font-size: 0.88rem;"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p class="small text-muted mb-0" style="font-size: 0.75rem; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <i class="fas fa-arrow-left text-<?php echo $color; ?> flex-shrink-0 transition-transform" style="font-size: 0.85rem;"></i>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Left Column: Daily Todo Checklist (المهام اليومية) -->
    <div class="col-lg-6 col-xl-5 animate-up delay-3">
        <div class="d-flex justify-content-between flex-wrap align-items-center gap-2 mb-3">
            <div>
                <h2 class="h4 mb-1 text-dark"><i class="fas fa-clipboard-list me-2 text-primary"></i>مفكرة المتابعة اليومية للأخصائي</h2>
                <p class="text-muted mb-0">سجل وتابع مهام المتابعة والحالات اليومية.</p>
            </div>
            <span class="badge px-3 py-2 fw-bold" style="background: rgba(249, 115, 22, 0.12) !important; color: #ea580c !important; border: 1px solid rgba(249, 115, 22, 0.2) !important;"><span id="todo-pending-badge">0</span> مهام</span>
        </div>
        
        <div class="card todo-notebook-card h-100 mb-0 d-flex flex-column">
            <div class="card-body todo-notebook-body d-flex flex-column" style="min-height: 380px; padding: 1.25rem;">
                
                <div class="input-group todo-input-group mb-3 shadow-sm rounded-pill overflow-hidden" style="background: #ffffff;">
                    <span class="input-group-text border-0 bg-white ps-3 text-muted"><i class="fas fa-pen-nib"></i></span>
                    <input type="text" id="todoInput" class="form-control border-0 ps-2" placeholder="أضف مهام المتابعة أو الحالات اليومية..." style="box-shadow: none; font-size: 0.9rem; font-weight: 500;">
                    <button class="btn btn-primary border-0 px-4 fw-bold" id="addTodoBtn" style="background: linear-gradient(135deg, #2563eb, #1d4ed8); transition: all 0.3s ease;">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                
                <!-- Sleek Gamified Progress Bar -->
                <div class="todo-progress-container mb-3" id="todoProgressContainer" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-bold text-muted" style="font-size: 0.78rem;">نسبة إنجاز المهام اليومية</span>
                        <span class="small fw-bold text-primary" id="todo-progress-percent" style="font-size: 0.78rem;">0%</span>
                    </div>
                    <div class="progress" style="height: 6px; border-radius: 10px; background-color: #f1f5f9; overflow: hidden;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="todo-progress-bar" role="progressbar" style="width: 0%; border-radius: 10px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);"></div>
                    </div>
                </div>

                <div class="flex-grow-1 overflow-auto" style="max-height: 480px;">
                    <ul id="todoList" class="list-group list-group-flush pr-0" style="padding-right: 0;">
                        <!-- JS generated items -->
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const todoList = $('#todoList');
    const todoInput = $('#todoInput');
    const addTodoBtn = $('#addTodoBtn');
    const csrfToken = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    
    let todos = [];
    
    function loadTodosFromServer() {
        $.post('specialist_dashboard.php', {
            action: 'get_todos',
            csrf_token: csrfToken
        }, function(response) {
            if (response.success) {
                todos = response.todos || [];
                renderTodos();
            } else {
                console.error('Failed to load todos:', response.error);
            }
        }, 'json');
    }
    
    function renderTodos() {
        todoList.empty();
        
        const activeTodosCount = todos.filter(t => !t.completed).length;
        const totalTodosCount = todos.length;
        const completedTodosCount = totalTodosCount - activeTodosCount;
        
        $('#todo-pending-counter').text(activeTodosCount);
        $('#todo-pending-badge').text(activeTodosCount);
        
        // Progress Bar Calculation
        if (totalTodosCount > 0) {
            const percent = Math.round((completedTodosCount / totalTodosCount) * 100);
            $('#todo-progress-percent').text(percent + '%');
            $('#todo-progress-bar').css('width', percent + '%');
            $('#todoProgressContainer').show();
        } else {
            $('#todoProgressContainer').hide();
        }
        
        if (totalTodosCount === 0) {
            todoList.append(`
                <div class="text-center py-5 px-3 rounded-4 mt-2" style="background: rgba(248, 250, 252, 0.6); border: 1px dashed #e2e8f0;">
                    <div class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success rounded-circle mb-3" style="width: 60px; height: 60px; box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.1);">
                        <i class="fas fa-check-double fa-lg"></i>
                    </div>
                    <h4 class="h6 fw-bold text-dark mb-1">كل شيء جاهز ومكتمل!</h4>
                    <p class="small text-muted mb-0">لا توجد مهام متابعة معلقة لهذا اليوم.</p>
                </div>
            `);
            return;
        }
        
        todos.forEach((todo, index) => {
            const checkedClass = todo.completed ? 'text-decoration-line-through text-muted opacity-75' : 'text-dark';
            const checkedAttr = todo.completed ? 'checked' : '';
            const borderStyle = todo.completed 
                ? 'border-color: #e2e8f0 !important; background: #f8fafc !important; opacity: 0.75;' 
                : 'border-inline-start: 4px solid #2563eb !important; border-color: #e2e8f0 !important; background: #ffffff !important; box-shadow: 0 4px 10px rgba(0,0,0,0.02) !important;';
            todoList.append(`
                <li class="todo-item list-group-item d-flex justify-content-between align-items-center border px-3 py-2.5 mb-2 rounded-3" style="${borderStyle}">
                    <div class="d-flex align-items-center gap-1">
                        <label class="custom-checkbox-wrapper">
                            <input type="checkbox" class="todo-checkbox" data-index="${index}" ${checkedAttr}>
                            <span class="todo-checkbox-custom">
                                <i class="fas fa-check"></i>
                            </span>
                        </label>
                        <span class="${checkedClass}" style="font-size: 0.88rem; font-weight: 600;">${todo.text}</span>
                    </div>
                    <button class="btn btn-action-pills btn-delete btn-sm delete-todo" data-index="${index}" title="حذف">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </li>
            `);
        });
    }
    
    function saveTodos() {
        $.post('specialist_dashboard.php', {
            action: 'save_todos',
            todos: JSON.stringify(todos),
            csrf_token: csrfToken
        }, function(response) {
            if (response.success) {
                renderTodos();
            } else {
                console.error('Failed to save todos:', response.error);
            }
        }, 'json');
    }
    
    addTodoBtn.on('click', function() {
        const text = todoInput.val().trim();
        if (text) {
            todos.push({ text: text, completed: false });
            todoInput.val('');
            saveTodos();
        }
    });
    
    todoInput.on('keypress', function(e) {
        if (e.which === 13) {
            addTodoBtn.click();
        }
    });
    
    todoList.on('change', '.todo-checkbox', function() {
        const index = $(this).data('index');
        todos[index].completed = $(this).is(':checked');
        saveTodos();
    });
    
    todoList.on('click', '.delete-todo', function() {
        const index = $(this).data('index');
        todos.splice(index, 1);
        saveTodos();
    });
    
    // Initial Load
    loadTodosFromServer();
});
</script>

<?php if ($groupedScope !== []): ?>
<!-- Modal for Full Scope View -->
<div class="modal fade" id="assignedScopeModal" tabindex="-1" aria-labelledby="assignedScopeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-confirm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="assignedScopeModalLabel">
                    <i class="fas fa-layer-group" style="color: #fbbf24;"></i>
                    <span>كافة الصفوف والفصول المسندة إليك (<?php echo count($groupedScope); ?> صفوف / <?php echo count($scopeRows); ?> فصل)</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                <div class="row row-cols-1 row-cols-md-2 g-3">
                    <?php foreach ($groupedScope as $gradeName => $classes): ?>
                        <div class="col">
                            <div class="card border rounded-3 h-100 shadow-sm p-3 bg-light">
                                <div class="fw-bold text-primary mb-2 d-flex align-items-center justify-content-between">
                                    <span><i class="fas fa-graduation-cap me-2 text-warning"></i><?php echo htmlspecialchars($gradeName, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="badge bg-primary rounded-pill px-2.5 py-1"><?php echo count($classes); ?> فصول</span>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($classes as $c): ?>
                                        <span class="badge bg-white text-dark border px-2.5 py-1.5 rounded-2 font-monospace" style="font-size: 0.8rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                                            <i class="fas fa-school text-muted me-1"></i><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary px-4 rounded-pill" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
