<?php
/**
 * إدارة البرامج التدريبية - Admin Training Programs Management
 */
$page_title = "التدريب والتطوير المهني";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/Training.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/csrf.php';

// Auth validation before any processing
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

$training = new Training($db);
ActivityLog::setDb($db);

// Process form submissions
// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    try {
        $action = $_POST['form_action'] ?? '';

        switch ($action) {
            case 'add_program':
                $name = trim($_POST['name']);
                $programData = [
                    'name' => $name,
                    'name_en' => trim($_POST['name_en'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'description_en' => trim($_POST['description_en'] ?? ''),
                    'icon' => $_POST['icon'] ?? 'fa-graduation-cap',
                    'color' => $_POST['color'] ?? '#198754',
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'sort_order' => intval($_POST['sort_order'] ?? 0),
                    'created_by' => $_SESSION['user_id'] ?? null
                ];
                $training->createProgram($programData);
                $newProgramId = (int)$db->lastInsertId();
                ActivityLog::logCreate('training_program', $newProgramId, $name, [
                    'name_en' => $programData['name_en'],
                    'icon' => $programData['icon'],
                    'color' => $programData['color'],
                    'is_active' => $programData['is_active'],
                    'sort_order' => $programData['sort_order'],
                ]);
                $_SESSION['success_message'] = "تم إضافة البرنامج التدريبي بنجاح.";
                header("Location: training_programs.php");
                exit();

            case 'edit_program':
                $programId = (int)$_POST['id'];
                $oldProgram = $training->getProgram($programId);
                $name = trim($_POST['name']);
                $programData = [
                    'name' => $name,
                    'name_en' => trim($_POST['name_en'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'description_en' => trim($_POST['description_en'] ?? ''),
                    'icon' => $_POST['icon'] ?? 'fa-graduation-cap',
                    'color' => $_POST['color'] ?? '#198754',
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'sort_order' => intval($_POST['sort_order'] ?? 0)
                ];
                $training->updateProgram($programId, $programData);
                $changes = [];
                if ($oldProgram) {
                    foreach (['name', 'name_en', 'icon', 'color', 'is_active', 'sort_order'] as $f) {
                        $old = $oldProgram[$f] ?? '';
                        $new = $programData[$f] ?? '';
                        if ((string)$old !== (string)$new) {
                            $changes[$f] = ['from' => $old, 'to' => $new];
                        }
                    }
                }
                ActivityLog::logUpdate('training_program', $programId, $name, ['changes' => $changes]);
                $_SESSION['success_message'] = "تم تحديث البرنامج التدريبي بنجاح.";
                header("Location: training_programs.php");
                exit();

            case 'delete_program':
                $programId = (int)$_POST['id'];
                $program = $training->getProgram($programId);
                $training->deleteProgram($programId);
                ActivityLog::logDelete('training_program', $programId, $program['name'] ?? '', ['program_id' => $programId]);
                $_SESSION['success_message'] = "تم حذف البرنامج التدريبي بنجاح.";
                header("Location: training_programs.php");
                exit();

            case 'toggle_program':
                $programId = (int)$_POST['id'];
                $newStatus = (int)$_POST['new_status'];
                $training->toggleProgramStatus($programId, $newStatus);
                $program = $training->getProgram($programId);
                ActivityLog::logUpdate('training_program', $programId, $program['name'] ?? '', ['is_active' => $newStatus]);
                $_SESSION['success_message'] = "تم تغيير حالة البرنامج بنجاح.";
                header("Location: training_programs.php");
                exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        header("Location: training_programs.php");
        exit();
    }
}

$programs = $training->getPrograms();
$stats = $training->getAdminStats();

include_once '../includes/admin_header.php';
?>

<style>
/* Page-specific layout for program cards grid (button colors owned by buttons.css) */
.program-card-wrapper {
    transition: opacity 0.3s ease;
}
.program-card {
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s ease, border-color 0.3s ease !important;
    border: 1px solid rgba(0, 0, 0, 0.05) !important;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 16px !important;
    overflow: hidden;
}
.program-card:hover {
    transform: translateY(-6px) !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08), 0 6px 22px var(--program-accent-glow) !important;
    border-color: var(--program-accent-color) !important;
}
.card-header-gradient {
    position: relative;
    overflow: hidden;
}
.card-header-gradient::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.15) 0%, rgba(0, 0, 0, 0.1) 100%);
    pointer-events: none;
}
.search-input-group {
    background: #fff;
    border: 1px solid rgba(0, 0, 0, 0.08);
    transition: box-shadow 0.3s ease, border-color 0.3s ease;
}
.search-input-group:focus-within {
    box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.15) !important;
    border-color: rgba(37, 99, 235, 0.5);
}
.search-input-group input:focus {
    box-shadow: none !important;
    outline: none !important;
}

/* Premium Card Stats Pill Layout */
.program-stat-pill {
    flex: 1 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0.55rem 0.4rem !important;
    border-radius: 12px !important;
    font-size: 0.8rem !important;
    transition: all 0.2s ease !important;
    gap: 5px !important;
    font-weight: 500;
}
.stat-courses {
    background-color: rgba(59, 130, 246, 0.06) !important;
    color: #2563eb !important;
}
.stat-sort {
    background-color: rgba(75, 85, 99, 0.06) !important;
    color: #4b5563 !important;
}

/* Glassmorphic Pill Badges (Matching training_courses.php) */
.program-card .badge.bg-success {
    background-color: rgba(16, 185, 129, 0.08) !important;
    color: #10b981 !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.35rem 0.8rem !important;
}
.program-card .badge.bg-warning {
    background-color: rgba(245, 158, 11, 0.08) !important;
    color: #d97706 !important;
    border: 1px solid rgba(245, 158, 11, 0.15) !important;
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.35rem 0.8rem !important;
}
.program-card .program-badge {
    background-color: rgba(255, 255, 255, 0.95) !important;
    color: var(--prog-badge-color) !important;
    background: var(--prog-badge-color)12 !important;
    border: 1px solid var(--prog-badge-color)26 !important;
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.35rem 0.8rem !important;
}

/* Button Upgrades inside cards */
.program-card .btn-info {
    font-weight: 600 !important;
    background-color: #0ea5e9 !important;
    border-color: #0ea5e9 !important;
    transition: all 0.2s ease !important;
}
.program-card .btn-info:hover {
    background-color: #0284c7 !important;
    border-color: #0284c7 !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 10px rgba(14, 165, 233, 0.25) !important;
}
.program-card .btn {
    border-radius: 10px !important;
}

/* List View Overrides (Aligned with training_courses.php) */
@media (min-width: 768px) {
    #programsContainer.view-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    #programsContainer.view-list > .program-card-wrapper,
    #programsContainer.view-list > .col-lg-4 {
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
    }
    #programsContainer.view-list .card {
        flex-direction: row !important;
        align-items: center !important;
        padding: 0.75rem 1.25rem !important;
        height: auto !important;
        border-radius: 12px !important;
    }
    #programsContainer.view-list .card:hover {
        transform: translateY(-2px) !important;
    }
    #programsContainer.view-list .card-header {
        width: 240px !important;
        border-radius: 10px !important;
        padding: 0.75rem 1rem !important;
        margin-left: 1.5rem !important;
        margin-right: 0 !important;
        flex-shrink: 0 !important;
    }
    #programsContainer.view-list .card-body {
        padding: 0.25rem !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        flex-grow: 1 !important;
        margin-left: 1.5rem !important;
        margin-right: 0 !important;
        gap: 1rem;
    }
    #programsContainer.view-list .card-body p {
        margin-bottom: 0 !important;
        max-width: 320px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        padding-left: 1.5rem !important;
    }
    #programsContainer.view-list .program-stat-pill {
        flex: 0 0 auto !important;
        padding: 0.4rem 0.8rem !important;
        border-radius: 8px !important;
        min-width: 95px;
    }
    #programsContainer.view-list .card-body .d-flex.justify-content-between {
        margin-bottom: 0 !important;
        margin-top: 0 !important;
        display: flex !important;
        flex-direction: row !important;
        gap: 0.5rem !important;
        min-width: 220px !important;
        flex-shrink: 0 !important;
    }
    #programsContainer.view-list .card-footer {
        padding: 0.25rem !important;
        width: auto !important;
        flex-shrink: 0 !important;
        min-width: 250px;
    }
}
</style>

<div id="trainingProgramsPage" class="training-programs-page admin-unified-page">

<!-- Page Header -->
<div class="admin-page-heading mb-4">
    <div>
        <h1 class="h2"><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>التدريب والتطوير المهني</h1>
    </div>
    <div class="admin-top-actions no-print">
        <a href="training_courses.php" class="btn btn-header-premium btn-export-soft" data-bs-toggle="tooltip" title="عرض الدورات التدريبية">
            <i class="fas fa-book me-1"></i>الدورات
        </a>
        <a href="training_reports.php" class="btn btn-header-premium btn-import-soft" data-bs-toggle="tooltip" title="عرض التقارير والإحصائيات">
            <i class="fas fa-chart-bar me-1"></i>التقارير
        </a>
        <button class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#programModal" onclick="resetForm()" data-bs-toggle="tooltip" title="إضافة برنامج جديد">
            <i class="fas fa-plus-circle me-1"></i>إضافة برنامج
        </button>
    </div>
</div>

<!-- Stats Cards -->
<div class="dashboard-canvas sortable-dashboard mb-4">
    <div class="row row-cols-2 row-cols-md-4 g-3 sortable-dashboard" id="widget-program-stats">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-layer-group"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$stats['total_programs']; ?>">0</div>
                    <div class="stat-card-label">برنامج تدريبي</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-book"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$stats['total_courses']; ?>">0</div>
                    <div class="stat-card-label">دورة تدريبية</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$stats['active_teachers']; ?>">0</div>
                    <div class="stat-card-label">معلم مشارك</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="stat-card-icon"><i class="fas fa-certificate"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$stats['certificates_issued']; ?>">0</div>
                    <div class="stat-card-label">شهادة صادرة</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Control Bar / Admin Filter Bar -->
<div class="admin-filter-bar mb-4">
    <div class="admin-filter-controls flex-grow-1">
        <input type="text" id="searchPrograms" class="form-control form-control-sm admin-inline-select-sm" placeholder="ابحث عن برنامج تدريبي بالاسم أو الوصف..." style="min-width: 220px;" onchange="this.blur()">
        <select id="filterHasCourses" class="form-select form-select-sm admin-inline-select-sm" aria-label="محتوى الدورات" onchange="this.blur()">
            <option value="">جميع البرامج</option>
            <option value="has_courses">تحتوي على دورات</option>
            <option value="no_courses">بدون دورات (فارغة)</option>
        </select>
        <select id="filterStatus" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة الحالة" onchange="this.blur()">
            <option value="">جميع الحالات</option>
            <option value="1">نشط</option>
            <option value="0">غير نشط</option>
        </select>
        <select id="filterSort" class="form-select form-select-sm admin-inline-select-sm" aria-label="ترتيب الفرز" onchange="this.blur()">
            <option value="default">الترتيب الافتراضي</option>
            <option value="name_asc">الاسم (أ - ي)</option>
            <option value="courses_desc">الأكثر دورات</option>
        </select>
    </div>
    <div class="admin-filter-actions">
        <button type="button" class="btn btn-light btn-sm" id="btnResetProgramsFilter" onclick="resetProgramSearch()"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</button>
        <div class="btn-group shadow-sm rounded-3" role="group" aria-label="Layout view options">
            <button type="button" id="btnGridView" class="btn btn-layout-toggle" data-bs-toggle="tooltip" title="عرض شبكي">
                <i class="fas fa-th-large"></i>
                <span>شبكي</span>
            </button>
            <button type="button" id="btnListView" class="btn btn-layout-toggle" data-bs-toggle="tooltip" title="عرض طولي">
                <i class="fas fa-list"></i>
                <span>طولي</span>
            </button>
        </div>
    </div>
</div>

<!-- Programs Grid -->
<div class="row g-4" id="programsContainer">
    <?php if (empty($programs)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-graduation-cap fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">لا توجد برامج تدريبية بعد</h5>
                    <p class="text-muted">ابدأ بإنشاء أول برنامج تدريبي</p>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#programModal" onclick="resetForm()">
                        <i class="fas fa-plus me-1"></i> إضافة برنامج
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($programs as $program): ?>
            <div class="col-lg-4 col-md-6 program-card-wrapper" 
                 data-name="<?php echo htmlspecialchars($program['name'], ENT_QUOTES); ?>" 
                 data-description="<?php echo htmlspecialchars($program['description'] ?? '', ENT_QUOTES); ?>"
                 data-active="<?php echo (string)$program['is_active']; ?>"
                 data-courses="<?php echo (int)$program['course_count']; ?>"
                 data-sort="<?php echo (int)$program['sort_order']; ?>"
                 style="--program-accent-color: <?php echo htmlspecialchars($program['color']); ?>; --program-accent-glow: <?php echo htmlspecialchars($program['color']); ?>26;">
                <div class="card border-0 shadow-sm h-100 program-card <?php echo !$program['is_active'] ? 'opacity-50' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="badge program-badge" style="--prog-badge-color: <?php echo htmlspecialchars($program['color']); ?>;">
                                <i class="fas <?php echo htmlspecialchars($program['icon']); ?> me-1"></i>
                                برنامج تدريبي
                            </span>
                            <?php if (!$program['is_active']): ?>
                                <span class="badge bg-warning">معطل</span>
                            <?php else: ?>
                                <span class="badge bg-success">نشط</span>
                            <?php endif; ?>
                        </div>
                        <h5 class="card-title mt-2 fw-bold fs-5" style="color: <?php echo htmlspecialchars($program['color']); ?>;">
                            <?php echo htmlspecialchars($program['name']); ?>
                        </h5>
                        <p class="text-muted small mb-3"><?php echo htmlspecialchars(mb_substr($program['description'] ?? 'لا يوجد وصف', 0, 100)); ?></p>
                        <div class="d-flex justify-content-between mb-4 mt-2 gap-2">
                            <div class="program-stat-pill stat-courses">
                                <i class="fas fa-book me-1"></i>
                                <span><strong><?php echo $program['course_count']; ?></strong> دورة</span>
                            </div>
                            <div class="program-stat-pill stat-sort">
                                <i class="fas fa-sort me-1"></i>
                                <span>ترتيب: <strong><?php echo $program['sort_order']; ?></strong></span>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-0 pb-3 pt-0">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <a href="training_courses.php?program_id=<?php echo $program['id']; ?>" class="btn btn-sm btn-info text-white flex-grow-1 py-2 rounded-3 shadow-sm" data-bs-toggle="tooltip" title="عرض الدورات التدريبية">
                                <i class="fas fa-book me-1"></i> عرض الدورات
                            </a>
                            <div class="d-flex gap-1 admin-actions">
                                <button type="button" class="btn btn-action-pills btn-edit me-1" onclick='editProgram(<?php echo json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' data-bs-toggle="tooltip" title="تعديل البرنامج">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="form_action" value="toggle_program">
                                    <input type="hidden" name="id" value="<?php echo $program['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $program['is_active'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn btn-action-pills <?php echo $program['is_active'] ? 'btn-deactivate' : 'btn-activate'; ?> me-1" data-bs-toggle="tooltip" title="<?php echo $program['is_active'] ? 'تعطيل البرنامج' : 'تفعيل البرنامج'; ?>">
                                        <i class="fas <?php echo $program['is_active'] ? 'fa-ban' : 'fa-check'; ?>"></i>
                                    </button>
                                </form>
                                <?php if ($program['course_count'] == 0): ?>
                                    <button type="button" class="btn btn-action-pills btn-delete" onclick="deleteProgram(<?php echo $program['id']; ?>, '<?php echo htmlspecialchars($program['name'], ENT_QUOTES); ?>')" data-bs-toggle="tooltip" title="حذف البرنامج">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Program Modal -->
<div class="modal fade" id="programModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create" id="programModalContent">
            <form method="POST" id="programForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="form_action" id="formAction" value="add_program">
                <input type="hidden" name="id" id="programId">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">
                        <i class="fas fa-plus-circle me-2"></i>إضافة برنامج تدريبي
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">اسم البرنامج (عربي) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="progName" required maxlength="255" placeholder="مثال: التطوير التقني">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">الترتيب</label>
                            <input type="number" class="form-control" name="sort_order" id="progSort" value="0" min="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">اسم البرنامج (إنجليزي)</label>
                            <input type="text" class="form-control" name="name_en" id="progNameEn" maxlength="255" dir="ltr" placeholder="e.g. Technical Development">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الوصف (عربي)</label>
                            <textarea class="form-control" name="description" id="progDesc" rows="3" placeholder="وصف مختصر للبرنامج التدريبي..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الوصف (إنجليزي)</label>
                            <textarea class="form-control" name="description_en" id="progDescEn" rows="3" dir="ltr" placeholder="Brief description of the training program..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الأيقونة (Font Awesome)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-graduation-cap" id="iconPreview"></i></span>
                                <select class="form-select" name="icon" id="progIcon" onchange="updateIconPreview()">
                                    <option value="fa-graduation-cap">🎓 تعليم</option>
                                    <option value="fa-laptop-code">💻 تقنية</option>
                                    <option value="fa-chalkboard-teacher">👨‍🏫 تدريس</option>
                                    <option value="fa-users-cog">⚙️ إدارة</option>
                                    <option value="fa-brain">🧠 مهارات</option>
                                    <option value="fa-book-open">📖 قراءة</option>
                                    <option value="fa-lightbulb">💡 ابتكار</option>
                                    <option value="fa-hands-helping">🤝 تعاون</option>
                                    <option value="fa-chart-line">📈 تطوير</option>
                                    <option value="fa-shield-alt">🛡️ أمان</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">اللون</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color" name="color" id="progColor" value="#198754" style="min-width: 60px;">
                                <span class="input-group-text flex-fill" id="colorLabel">#198754</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="progActive" checked>
                                <label class="form-check-label" for="progActive">تفعيل البرنامج</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-save me-1"></i> حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form (hidden) -->
<form method="POST" id="deleteForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="form_action" value="delete_program">
    <input type="hidden" name="id" id="deleteId">
</form>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>حذف البرنامج</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من حذف البرنامج <span class="fw-bold text-primary" id="deleteProgramName"></span>؟</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    سيتم حذف البرنامج وجميع البيانات المرتبطة بشكل نهائي.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash me-1"></i>حذف
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('formAction').value = 'add_program';
    document.getElementById('programId').value = '';
    document.getElementById('progName').value = '';
    document.getElementById('progNameEn').value = '';
    document.getElementById('progDesc').value = '';
    document.getElementById('progDescEn').value = '';
    document.getElementById('progIcon').value = 'fa-graduation-cap';
    document.getElementById('progColor').value = '#198754';
    document.getElementById('colorLabel').textContent = '#198754';
    document.getElementById('progSort').value = '0';
    document.getElementById('progActive').checked = true;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة برنامج تدريبي';
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save me-1"></i> حفظ';
    updateIconPreview();
    
    var modalContent = document.getElementById('programModalContent');
    modalContent.classList.remove('admin-modal-edit');
    modalContent.classList.add('admin-modal-create');
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.className = 'btn btn-success';
}

function editProgram(program) {
    document.getElementById('formAction').value = 'edit_program';
    document.getElementById('programId').value = program.id;
    document.getElementById('progName').value = program.name;
    document.getElementById('progNameEn').value = program.name_en || '';
    document.getElementById('progDesc').value = program.description || '';
    document.getElementById('progDescEn').value = program.description_en || '';
    document.getElementById('progIcon').value = program.icon || 'fa-graduation-cap';
    document.getElementById('progColor').value = program.color || '#198754';
    document.getElementById('colorLabel').textContent = program.color || '#198754';
    document.getElementById('progSort').value = program.sort_order || 0;
    document.getElementById('progActive').checked = program.is_active == 1;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل البرنامج';
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save me-1"></i> تحديث';
    updateIconPreview();
    
    var modalContent = document.getElementById('programModalContent');
    modalContent.classList.remove('admin-modal-create');
    modalContent.classList.add('admin-modal-edit');
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.className = 'btn btn-primary';
    
    new bootstrap.Modal(document.getElementById('programModal')).show();
}

function deleteProgram(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteProgramName').textContent = '"' + name + '"';
    document.getElementById('confirmDeleteBtn').onclick = function() {
        document.getElementById('deleteForm').submit();
    };
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}

function updateIconPreview() {
    var icon = document.getElementById('progIcon').value;
    document.getElementById('iconPreview').className = 'fas ' + icon;
}

document.getElementById('progColor').addEventListener('input', function() {
    document.getElementById('colorLabel').textContent = this.value;
});

// Search & Filter & Layout Switcher Logic
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchPrograms');
    var statusSelect = document.getElementById('filterStatus');
    var hasCoursesSelect = document.getElementById('filterHasCourses');
    var sortSelect = document.getElementById('filterSort');
    var container = document.getElementById('programsContainer');
    var itemsCount = document.getElementById('itemsCount');
    
    if (container) {
        var cardsArray = Array.from(container.querySelectorAll('.program-card-wrapper'));
        
        function applyProgramFilters() {
            var query = searchInput ? searchInput.value.toLowerCase().trim() : '';
            var status = statusSelect ? statusSelect.value : '';
            var hasCourses = hasCoursesSelect ? hasCoursesSelect.value : '';
            var sortBy = sortSelect ? sortSelect.value : 'default';
            
            // Highlight active filters (blue subtle background) only when non-empty / non-default
            if (statusSelect) statusSelect.classList.toggle('active-filter', status !== '');
            if (hasCoursesSelect) hasCoursesSelect.classList.toggle('active-filter', hasCourses !== '');
            if (sortSelect) sortSelect.classList.toggle('active-filter', sortBy !== 'default');
            if (searchInput) searchInput.classList.toggle('active-filter', query !== '');
            
            var visibleCards = [];
            
            cardsArray.forEach(function(card) {
                var name = (card.getAttribute('data-name') || '').toLowerCase();
                var desc = (card.getAttribute('data-description') || '').toLowerCase();
                var cardActive = card.getAttribute('data-active') || '';
                var courseCount = parseInt(card.getAttribute('data-courses') || '0', 10);
                
                var matchesSearch = !query || name.includes(query) || desc.includes(query);
                var matchesStatus = !status || cardActive === status;
                var matchesHasCourses = !hasCourses || (hasCourses === 'has_courses' ? courseCount > 0 : courseCount === 0);
                
                if (matchesSearch && matchesStatus && matchesHasCourses) {
                    card.style.display = '';
                    card.style.opacity = '1';
                    visibleCards.push(card);
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Client-side Sorting
            if (visibleCards.length > 0) {
                visibleCards.sort(function(a, b) {
                    if (sortBy === 'name_asc') {
                        return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'), 'ar');
                    } else if (sortBy === 'courses_desc') {
                        return parseInt(b.getAttribute('data-courses') || '0', 10) - parseInt(a.getAttribute('data-courses') || '0', 10);
                    } else {
                        return parseInt(a.getAttribute('data-sort') || '0', 10) - parseInt(b.getAttribute('data-sort') || '0', 10);
                    }
                });
                
                visibleCards.forEach(function(card) {
                    container.appendChild(card);
                });
            }
            
            if (itemsCount) itemsCount.textContent = visibleCards.length;
        }
        
        if (searchInput) searchInput.addEventListener('input', applyProgramFilters);
        if (statusSelect) statusSelect.addEventListener('change', applyProgramFilters);
        if (hasCoursesSelect) hasCoursesSelect.addEventListener('change', applyProgramFilters);
        if (sortSelect) sortSelect.addEventListener('change', applyProgramFilters);
        
        window.applyProgramFilters = applyProgramFilters;
        applyProgramFilters();
        
        // Grid/List View Toggler
        var btnGrid = document.getElementById('btnGridView');
        var btnList = document.getElementById('btnListView');
        
        if (btnGrid && btnList) {
            var savedView = localStorage.getItem('programs_layout_view') || 'grid';
            setView(savedView);
            
            btnGrid.addEventListener('click', function() { setView('grid'); });
            btnList.addEventListener('click', function() { setView('list'); });
            
            function setView(view) {
                if (view === 'list') {
                    container.classList.add('view-list');
                    btnList.classList.add('active');
                    btnGrid.classList.remove('active');
                    localStorage.setItem('programs_layout_view', 'list');
                } else {
                    container.classList.remove('view-list');
                    btnGrid.classList.add('active');
                    btnList.classList.remove('active');
                    localStorage.setItem('programs_layout_view', 'grid');
                }
            }
        }
    }
});

function resetProgramSearch() {
    var searchInput = document.getElementById('searchPrograms');
    var statusSelect = document.getElementById('filterStatus');
    var hasCoursesSelect = document.getElementById('filterHasCourses');
    var sortSelect = document.getElementById('filterSort');
    
    if (searchInput) searchInput.value = '';
    if (statusSelect) statusSelect.value = '';
    if (hasCoursesSelect) hasCoursesSelect.value = '';
    if (sortSelect) sortSelect.value = 'default';
    
    if (typeof window.applyProgramFilters === 'function') {
        window.applyProgramFilters();
    }
}
</script>

</div><!-- /#trainingProgramsPage -->

<?php include_once '../includes/admin_footer.php'; ?>
