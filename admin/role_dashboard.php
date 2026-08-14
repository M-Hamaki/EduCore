<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AdminRolePageCatalog.php';
require_once '../classes/StaffRoleCapabilityResolver.php';
require_once '../classes/StaffAcademicScopeService.php';
require_once '../includes/session_config.php';

Utilities::validateSession('admin');

// POST Ajax Handler for Role Dashboard Todo List
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $activeRole = trim((string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? 'role'));
    $storageDir = dirname(__DIR__) . '/storage/private';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    
    $safeRole = preg_replace('/[^a-z0-9_]/i', '', $activeRole);
    $todoFile = $storageDir . '/todos_' . $userId . '_' . $safeRole . '.json';
    
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
$activeRole = trim((string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? ''));
$roleResolver = new StaffRoleCapabilityResolver($db);
$roleFamily = $roleResolver->family($activeRole);

$dashboardDefinitions = [
    AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER => [
        'title' => 'مسؤول شؤون الطلاب',
        'heading' => 'لوحة شؤون الطلاب',
        'theme' => 'student-affairs',
        'icon' => 'fa-user-graduate',
        'eyebrow' => 'مساحتك لإدارة رحلة الطالب',
        'description' => 'تابع بيانات الطلاب وحضورهم وملفاتهم وخدماتهم المدرسية من مكان واحد.',
        'quote' => 'كل معلومة دقيقة عن الطالب تساعد المدرسة على تقديم رعاية أفضل وقرار أسرع.',
    ],
    AdminRolePageCatalog::TRANSPORT_MANAGER => [
        'title' => 'مسؤول الحركة والتنقلات',
        'heading' => 'لوحة الحركة والتنقلات',
        'theme' => 'transport',
        'icon' => 'fa-bus-simple',
        'eyebrow' => 'متابعة آمنة ومنظمة للحركة اليومية',
        'description' => 'أدر الحافلات والطاقم والمناطق وتعيينات الطلاب وتقارير النقل من لوحة موحدة.',
        'quote' => 'الرحلة المدرسية الآمنة تبدأ من بيانات محدثة وتخطيط واضح ومتابعة مستمرة.',
    ],
    AdminRolePageCatalog::LIBRARIAN => [
        'title' => 'مسؤول مكتبة',
        'heading' => 'لوحة المكتبة',
        'theme' => 'library',
        'icon' => 'fa-book-open-reader',
        'eyebrow' => 'المعرفة في متناول مجتمع المدرسة',
        'description' => 'أدر الكتب والإعارات وحركة المكتبة ضمن الصفوف والفصول المسندة لك في العام الحالي.',
        'quote' => 'المكتبة مساحة تصنع الفضول، وكل كتاب يصل إلى قارئه يفتح نافذة جديدة للتعلم.',
    ],
    AdminRolePageCatalog::DOCTOR => [
        'title' => 'طبيب',
        'heading' => 'لوحة العيادة المدرسية',
        'theme' => 'clinic',
        'icon' => 'fa-user-doctor',
        'eyebrow' => 'رعاية صحية أقرب وأكثر وضوحًا',
        'description' => 'تابع العيادة والسجلات الصحية والزيارات ضمن الصفوف والفصول المسندة لك في العام الحالي.',
        'quote' => 'الرعاية المبكرة والمتابعة الدقيقة تصنعان بيئة مدرسية أكثر صحة وطمأنينة.',
    ],
    AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER => [
        'title' => 'مسؤول الأدوار والصلاحيات',
        'heading' => 'لوحة الحسابات والصلاحيات',
        'theme' => 'permissions',
        'icon' => 'fa-shield-halved',
        'eyebrow' => 'وصول منظم ومسؤوليات واضحة',
        'description' => 'تابع حسابات المدرسة والطلاب والعاملين والأدوار المسموح لك بإدارتها.',
        'quote' => 'الصلاحية الدقيقة تمنح كل مستخدم ما يحتاجه للعمل وتحافظ على وضوح المسؤولية.',
    ],
];

if (!isset($dashboardDefinitions[$roleFamily])) {
    $fallback = $roleFamily === AdminRolePageCatalog::SPECIALIST ? 'specialist_dashboard.php' : 'index.php';
    header('Location: ' . $fallback);
    exit;
}

$definition = $dashboardDefinitions[$roleFamily];
$definition['pages'] = AdminRolePageCatalog::customizablePages($roleFamily);
$allowedPages = Utilities::getAllowedAdminPagesForRole($activeRole) ?? [];
$academicYear = AcademicYear::getCurrent($db);
$academicYearId = (int)($academicYear['id'] ?? 0);

$roleNameStmt = $db->prepare("SELECT role_name FROM staff_roles WHERE role_key = ? AND status = 'active' LIMIT 1");
$roleNameStmt->execute([$activeRole]);
$roleName = trim((string)($roleNameStmt->fetchColumn() ?: $definition['title']));

$serviceCatalog = [
    'students.php' => ['fa-address-card', 'بيانات الطلاب', 'عرض بيانات الطلاب وإدارة الإجراءات المسموحة.', 'blue'],
    'student_operations.php' => ['fa-clock-rotate-left', 'سجل عمليات الطلاب', 'مراجعة عمليات شؤون الطلاب والتراجع عن العمليات المؤهلة.', 'blue'],
    'pending_operations.php' => ['fa-hourglass-half', 'العمليات المعلقة', 'متابعة طلبات التعديل التي تنتظر قرار الإدارة.', 'amber'],
    'new_students.php' => ['fa-user-plus', 'منقول إلى المدرسة', 'متابعة الطلاب المنقولين حديثًا إلى المدرسة.', 'green'],
    'transferred_students.php' => ['fa-user-minus', 'منقول من المدرسة', 'عرض سجلات الطلاب المنقولين من المدرسة.', 'orange'],
    'graduate_students.php' => ['fa-user-graduate', 'الخريجون', 'متابعة سجلات الطلاب الخريجين.', 'purple'],
    'student_archive.php' => ['fa-box-archive', 'أرشيف الطلاب', 'الوصول إلى السجلات المؤرشفة المسموح بها.', 'slate'],
    'student_data_completeness.php' => ['fa-list-check', 'اكتمال البيانات', 'قياس اكتمال ملفات الطلاب وتحديد البيانات الناقصة.', 'violet'],
    'class_lists.php' => ['fa-table-list', 'قوائم الفصول', 'عرض قوائم الطلاب وتنظيم بيانات الفصول.', 'cyan'],
    'siblings.php' => ['fa-people-roof', 'صلات القرابة', 'مراجعة الروابط العائلية بين الطلاب.', 'rose'],
    'attendance.php' => ['fa-calendar-check', 'الحضور والغياب', 'تسجيل ومراجعة حضور الطلاب وغيابهم.', 'green'],
    'statements.php' => ['fa-file-signature', 'مستخرجات رسمية', 'إنشاء الإفادات والمستخرجات والتقارير الرسمية.', 'blue'],
    'student_file.php' => ['fa-folder-open', 'ملف الطالب', 'الوصول إلى ملف الطالب وخدماته وتقاريره.', 'amber'],
    'student_numbers_reports.php' => ['fa-chart-pie', 'ميزانية المدرسة', 'متابعة بيانات الميزانية المدرسية المسموح بها.', 'purple'],
    'student_id_cards.php' => ['fa-id-card', 'كروت الطلاب', 'إنشاء وعرض بطاقات تعريف الطلاب.', 'cyan'],
    'export_students.php' => ['fa-file-export', 'تصدير البيانات', 'تصدير بيانات الطلاب وفق الصلاحيات الحالية.', 'green'],
    'student_statistics.php' => ['fa-chart-column', 'إحصائيات الطلاب', 'قراءة مؤشرات وإحصائيات الطلاب.', 'violet'],
    'calculation_tools.php' => ['fa-calculator', 'أدوات الحساب', 'استخدام أدوات الحساب والتحليل المساعدة.', 'slate'],
    'locations.php' => ['fa-map-location-dot', 'المناطق الجغرافية', 'إدارة مناطق وخطوط خدمة النقل المدرسي.', 'blue'],
    'bus_staff.php' => ['fa-users-gear', 'طاقم الحافلات', 'إدارة السائقين والمشرفين وطاقم النقل.', 'green'],
    'buses.php' => ['fa-bus', 'إدارة الحافلات', 'متابعة بيانات الحافلات وحالتها التشغيلية.', 'amber'],
    'student_buses.php' => ['fa-people-arrows', 'تعيين الطلاب للحافلات', 'ربط الطلاب بالحافلات والمسارات المناسبة.', 'violet'],
    'bus_lists.php' => ['fa-clipboard-list', 'قوائم الحافلات', 'عرض قوائم الركاب وتوزيعهم على الحافلات.', 'cyan'],
    'bus_report.php' => ['fa-file-lines', 'تقارير الحركة والتنقلات', 'عرض تقارير النقل والحركة المدرسية.', 'orange'],
    'transport_statistics.php' => ['fa-chart-line', 'إحصائيات النقل', 'متابعة مؤشرات الحافلات والطلاب والخطوط.', 'purple'],
    'library.php' => ['fa-book-open', 'إدارة المكتبة', 'إدارة الكتب والإعارات والسجلات المكتبية.', 'amber'],
    'student_clinic.php' => ['fa-house-medical', 'العيادة المدرسية', 'إدارة الزيارات والسجلات والخدمات الصحية.', 'rose'],
    'school_settings.php' => ['fa-school-lock', 'حسابات المدرسة', 'إدارة إعدادات وحسابات المدرسة المسموح بها.', 'blue'],
    'student_accounts.php' => ['fa-user-graduate', 'حسابات الطلاب', 'إدارة بيانات دخول حسابات الطلاب.', 'green'],
    'staff_accounts.php' => ['fa-users-gear', 'حسابات العاملين والأدوار', 'إدارة حسابات العاملين والأدوار والصلاحيات.', 'violet'],
];

$services = [];
foreach ($definition['pages'] as $page) {
    if (!in_array($page, $allowedPages, true) || !isset($serviceCatalog[$page])) {
        continue;
    }
    [$icon, $title, $description, $accent] = $serviceCatalog[$page];
    $services[] = compact('page', 'icon', 'title', 'description', 'accent');
}

$requiresScope = $roleResolver->requiresAcademicScope($activeRole);
$scopeSummary = ['grades' => 0, 'classes' => 0];
if ($requiresScope && $academicYearId > 0) {
    $scope = (new StaffAcademicScopeService($db))->scope(
        (int)($_SESSION['user_id'] ?? 0),
        $academicYearId,
        $activeRole
    );
    $scopeSummary = [
        'grades' => count($scope['grade_ids']),
        'classes' => count($scope['class_ids']),
    ];
}

$page_title = (string)$definition['heading'];
$custom_page_title = true;
$adminAssetOptions = [
    'datatables' => false,
    'sweetalert' => false,
    'sortable' => false,
    'instant_attachment_upload' => false,
    'dashboard_sortable' => false,
];
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$welcomePrefix = $roleFamily === AdminRolePageCatalog::DOCTOR ? 'د.' : 'أ.';

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading role-dashboard-heading">
    <div>
        <h1 class="h2 mb-1"><i class="fas <?php echo $escape($definition['icon']); ?> me-2 text-primary"></i><?php echo $escape($definition['heading']); ?></h1>
        <p class="text-muted mb-0">وصول سريع إلى الخدمات المتاحة لدورك النشط.</p>
    </div>
</div>

<section class="role-welcome-hero role-welcome--<?php echo $escape($definition['theme']); ?> mb-4" aria-labelledby="roleWelcomeTitle">
    <div class="role-welcome-orb role-welcome-orb--one" aria-hidden="true"></div>
    <div class="role-welcome-orb role-welcome-orb--two" aria-hidden="true"></div>
    <i class="fas <?php echo $escape($definition['icon']); ?> role-welcome-watermark" aria-hidden="true"></i>

    <!-- Top Centered Quote Banner -->
    <div class="role-welcome-quote mb-4 text-center">
        <i class="fas fa-quote-right me-2 text-warning fs-5"></i>
        <span>"<?php echo $escape($definition['quote']); ?>"</span>
        <i class="fas fa-quote-left ms-2 text-warning fs-5"></i>
    </div>

    <div class="role-welcome-content">
        <div class="role-welcome-message">
            <span class="role-welcome-eyebrow"><i class="fas fa-wand-magic-sparkles"></i><?php echo $escape($definition['eyebrow']); ?></span>
            <h2 id="roleWelcomeTitle">مرحبًا بك <?php echo $escape($welcomePrefix); ?> <span><?php echo $escape($_SESSION['name'] ?? ''); ?></span> 👋</h2>
            <p><?php echo $escape($definition['description']); ?></p>
            <div class="role-welcome-role"><i class="fas fa-id-badge"></i>الدور النشط: <strong><?php echo $escape($roleName); ?></strong></div>
        </div>
        <div class="role-welcome-metrics">
            <div class="role-welcome-metric">
                <i class="fas fa-calendar-days"></i>
                <div><span>العام الدراسي الحالي</span><strong dir="ltr"><?php echo $escape($academicYear['name'] ?? '—'); ?></strong></div>
            </div>
            <div class="role-welcome-metric">
                <i class="fas fa-th-large"></i>
                <div><span>الخدمات المتاحة</span><strong><span class="counter" data-target="<?php echo count($services); ?>">0</span> خدمة</strong></div>
            </div>
            <?php if ($requiresScope): ?>
                <div class="role-welcome-metric">
                    <i class="fas fa-school"></i>
                    <div><span>نطاقك في العام الحالي</span><strong><?php echo $scopeSummary['grades']; ?> صفوف / <?php echo $scopeSummary['classes']; ?> فصول</strong></div>
                </div>
            <?php else: ?>
                <div class="role-welcome-metric">
                    <i class="fas fa-shield-alt"></i>
                    <div><span>نطاق الوصول</span><strong>حسب صفحات الدور</strong></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($requiresScope && $scopeSummary['grades'] === 0 && $scopeSummary['classes'] === 0): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-triangle-exclamation me-2"></i>
        لم يُحدد لك نطاق صفوف أو فصول في العام الدراسي الحالي؛ تواصل مع مدير النظام لتعيين نطاق العمل.
    </div>
<?php endif; ?>

<section class="role-services-section" aria-labelledby="roleServicesTitle">
    <div class="role-services-heading">
        <div>
            <h2 class="h4 mb-1" id="roleServicesTitle"><i class="fas fa-grip me-2 text-primary"></i>الخدمات المتاحة لك</h2>
            <p class="text-muted mb-0">تظهر هنا الصفحات التي منحها مدير النظام لدورك الحالي فقط.</p>
        </div>
        <span class="role-services-count"><?php echo count($services); ?> خدمة</span>
    </div>

    <?php if ($services): ?>
        <div class="role-services-grid">
            <?php foreach ($services as $service): ?>
                <a class="role-service-card role-service--<?php echo $escape($service['accent']); ?>" href="<?php echo $escape($service['page']); ?>">
                    <span class="role-service-icon"><i class="fas <?php echo $escape($service['icon']); ?>"></i></span>
                    <span class="role-service-copy">
                        <strong><?php echo $escape($service['title']); ?></strong>
                        <small><?php echo $escape($service['description']); ?></small>
                    </span>
                    <i class="fas fa-arrow-left role-service-arrow" aria-hidden="true"></i>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="admin-empty-state">
            <i class="fas fa-lock"></i>
            <h3 class="h5">لا توجد خدمات مفعلة حاليًا</h3>
            <p class="text-muted mb-0">يمكن لمدير النظام الأعلى تحديث الصفحات الممنوحة لهذا الدور.</p>
        </div>
    <?php endif; ?>
</section>

<section class="role-todo-section mt-5 mb-4" aria-labelledby="roleTodoTitle">
    <div class="d-flex justify-content-between flex-wrap align-items-center gap-2 mb-3">
        <div>
            <h2 class="h4 mb-1 text-dark" id="roleTodoTitle"><i class="fas fa-clipboard-list me-2 text-primary"></i>مفكرة المتابعة والمهام اليومية</h2>
            <p class="text-muted mb-0">سجل وتابع المهام والملاحظات الميدانية الخاصة بدورك.</p>
        </div>
        <span class="badge px-3 py-2 fw-bold" style="background: rgba(249, 115, 22, 0.12) !important; color: #ea580c !important; border: 1px solid rgba(249, 115, 22, 0.2) !important;"><span id="todo-pending-badge">0</span> مهام</span>
    </div>
    
    <div class="card todo-notebook-card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-4">
            <div class="input-group todo-input-group mb-3 shadow-sm rounded-pill overflow-hidden" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                <span class="input-group-text border-0 bg-transparent ps-3 text-muted"><i class="fas fa-pen-nib"></i></span>
                <input type="text" id="todoInput" class="form-control border-0 bg-transparent ps-2" placeholder="أضف مهمة أو ملاحظة جديدة..." style="box-shadow: none; font-size: 0.9rem; font-weight: 500;">
                <button class="btn btn-primary border-0 px-4 fw-bold" id="addTodoBtn" style="background: linear-gradient(135deg, #2563eb, #1d4ed8); transition: all 0.3s ease;">
                    <i class="fas fa-plus me-1"></i>إضافة
                </button>
            </div>
            
            <div class="todo-progress-container mb-3" id="todoProgressContainer" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small fw-bold text-muted" style="font-size: 0.78rem;">نسبة إنجاز المهام اليومية</span>
                    <span class="small fw-bold text-primary" id="todo-progress-percent" style="font-size: 0.78rem;">0%</span>
                </div>
                <div class="progress" style="height: 6px; border-radius: 10px; background-color: #f1f5f9; overflow: hidden;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="todo-progress-bar" role="progressbar" style="width: 0%; border-radius: 10px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);"></div>
                </div>
            </div>

            <div class="overflow-auto" style="max-height: 400px;">
                <ul id="todoList" class="list-group list-group-flush p-0" style="padding-right: 0;">
                    <!-- JS generated items -->
                </ul>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    let todos = [];
    
    const todoInput = $('#todoInput');
    const addTodoBtn = $('#addTodoBtn');
    const todoList = $('#todoList');
    const pendingBadge = $('#todo-pending-badge');
    const progressContainer = $('#todoProgressContainer');
    const progressBar = $('#todo-progress-bar');
    const progressPercent = $('#todo-progress-percent');
    
    function renderTodos() {
        todoList.empty();
        let pendingCount = 0;
        let completedCount = 0;
        
        if (!todos || todos.length === 0) {
            todoList.append(`
                <li class="list-group-item border-0 text-center py-4 text-muted bg-transparent">
                    <i class="fas fa-clipboard-check mb-2 d-block text-muted" style="font-size: 2rem; opacity: 0.5;"></i>
                    <span style="font-size: 0.88rem;">لا توجد مهام مسجلة حالياً. أضف مهامك اليومية بسهولة من الأعلى!</span>
                </li>
            `);
            pendingBadge.text(0);
            progressContainer.hide();
            return;
        }
        
        todos.forEach(function(todo, index) {
            if (todo.completed) {
                completedCount++;
            } else {
                pendingCount++;
            }
            
            const itemHtml = `
                <li class="list-group-item d-flex align-items-center justify-content-between py-2.5 px-3 mb-2 rounded-3 border-0" style="background: ${todo.completed ? '#f8fafc' : '#ffffff'}; border: 1px solid ${todo.completed ? '#e2e8f0' : '#cbd5e1'} !important; transition: all 0.2s ease;">
                    <div class="d-flex align-items-center gap-3 flex-grow-1 overflow-hidden me-2">
                        <input type="checkbox" class="form-check-input todo-checkbox mt-0 cursor-pointer" data-index="${index}" ${todo.completed ? 'checked' : ''} style="width: 18px; height: 18px; cursor: pointer;">
                        <span class="todo-text ${todo.completed ? 'text-decoration-line-through text-muted' : 'text-dark fw-medium'}" style="font-size: 0.9rem; word-break: break-word;">${escapeHtml(todo.text)}</span>
                    </div>
                    <button type="button" class="btn btn-link text-danger delete-todo p-1 border-0" data-index="${index}" title="حذف المهمة" style="opacity: 0.7; transition: opacity 0.2s;">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </li>
            `;
            todoList.append(itemHtml);
        });
        
        pendingBadge.text(pendingCount);
        
        const total = todos.length;
        if (total > 0) {
            const percent = Math.round((completedCount / total) * 100);
            progressPercent.text(percent + '%');
            progressBar.css('width', percent + '%');
            progressContainer.show();
        } else {
            progressContainer.hide();
        }
    }
    
    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    
    function loadTodosFromServer() {
        $.post('role_dashboard.php', {
            action: 'get_todos',
            csrf_token: csrfToken
        }, function(response) {
            if (response.success) {
                todos = response.todos || [];
                renderTodos();
            }
        }, 'json');
    }
    
    function saveTodos() {
        $.post('role_dashboard.php', {
            action: 'save_todos',
            todos: JSON.stringify(todos),
            csrf_token: csrfToken
        }, function(response) {
            if (response.success) {
                renderTodos();
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
    
    loadTodosFromServer();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
