<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Set page title
$page_title = "تقييمات الطلاب";
$custom_page_title = true;

// Include database and necessary classes
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/evaluation.php';
require_once '../classes/evaluation_type.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ScopedStaffPortalContext.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();

function student_evaluations_class_scope(?array $allowedClassIds, string $column): string
{
    if ($allowedClassIds === null) {
        return '1 = 1';
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $allowedClassIds), static fn(int $id): bool => $id > 0)));
    return $ids === [] ? '1 = 0' : $column . ' IN (' . implode(',', $ids) . ')';
}

$enrollmentScopeSql = student_evaluations_class_scope($allowedClassIds, 'se.class_id');
$evaluationScopeSql = student_evaluations_class_scope($allowedClassIds, 'e.class_id');
$classScopeSql = student_evaluations_class_scope($allowedClassIds, 'c.id');

// Initialize objects
$user = new User($db);
$class = new ClassRoom($db);
$evaluation = new Evaluation($db);
$evaluation_type = new EvaluationType($db);

// Check filters applied
$filter_stage_id = isset($_GET['stage_id']) ? $_GET['stage_id'] : null;
$filter_grade_id = isset($_GET['grade_id']) ? $_GET['grade_id'] : null;
$filter_class_id = isset($_GET['class_id']) ? $_GET['class_id'] : null;

// Build SQL to fetch students with points, excluding graduated and deleted students
$query = "SELECT u.id, u.name, u.username, se.class_id, u.status, c.name as class_name,
                 sp.student_code,
                 COALESCE(SUM(
                   CASE 
                       WHEN e.custom_points IS NOT NULL THEN 
                           e.custom_points
                       ELSE 
                           CASE WHEN et.type = 'positive' THEN et.points ELSE -et.points END
                   END
                 ), 0) AS total_points
          FROM student_enrollments se
          JOIN users u ON u.id = se.student_id
          JOIN classes c ON se.class_id = c.id
          LEFT JOIN grades g ON c.grade_id = g.id
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN evaluations e ON u.id = e.student_id
              AND e.class_id = se.class_id
              AND (e.academic_year_id = :evaluation_year_id OR e.academic_year_id IS NULL)
          LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
          WHERE se.academic_year_id = :academic_year_id
            AND se.enrollment_status = 'enrolled'
            AND {$enrollmentScopeSql}
            AND u.role = 'student'
            AND u.deleted_at IS NULL 
            AND u.status != 'graduated'
            AND (sp.enrollment_status IS NULL OR sp.enrollment_status = 'enrolled')";

$params = [
    ':academic_year_id' => $currentAcademicYearId,
    ':evaluation_year_id' => $currentAcademicYearId,
];
if ($filter_stage_id) {
    $query .= " AND g.stage_id = :stage_id";
    $params[':stage_id'] = $filter_stage_id;
}
if ($filter_grade_id) {
    $query .= " AND c.grade_id = :grade_id";
    $params[':grade_id'] = $filter_grade_id;
}
if ($filter_class_id) {
    $portalContext->assertClassAllowed((int)$filter_class_id);
    $query .= " AND se.class_id = :class_id";
    $params[':class_id'] = $filter_class_id;
}

$query .= " GROUP BY u.id, u.name, u.username, se.class_id, u.status, c.name, sp.student_code";
$query .= " ORDER BY u.name";

$stmt = $db->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats for cards (Top 5 and Bottom 5)
$total_students = count($students);
$top_5 = [];
$bottom_5 = [];

if ($total_students > 0) {
    $top_students = $students;
    usort($top_students, function($a, $b) {
        return (int)($b['total_points'] ?? 0) <=> (int)($a['total_points'] ?? 0);
    });
    $top_5 = array_slice($top_students, 0, 5);

    $bottom_students = $students;
    usort($bottom_students, function($a, $b) {
        return (int)($a['total_points'] ?? 0) <=> (int)($b['total_points'] ?? 0);
    });
    $bottom_5 = array_slice($bottom_students, 0, 5);
}

// Get all stages for cascading filter
$grades_stmt = $db->prepare("SELECT DISTINCT g.id, g.grade_name, g.stage_id
    FROM grades g JOIN classes c ON c.grade_id = g.id
    WHERE c.academic_year_id = ? AND {$classScopeSql} ORDER BY g.stage_id, g.id");
$grades_stmt->execute([$currentAcademicYearId]);
$grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
$stages_stmt = $db->prepare("SELECT DISTINCT s.id, s.stage_name
    FROM stages s JOIN grades g ON g.stage_id = s.id JOIN classes c ON c.grade_id = g.id
    WHERE c.academic_year_id = ? AND {$classScopeSql} ORDER BY s.id");
$stages_stmt->execute([$currentAcademicYearId]);
$stages = $stages_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all active students for quick add dropdown
$quick_students_stmt = $db->prepare("SELECT u.id, u.name, se.class_id, c.name as class_name, c.grade_id, g.stage_id
    FROM student_enrollments se
    JOIN users u ON u.id = se.student_id
    JOIN classes c ON se.class_id = c.id
    LEFT JOIN grades g ON c.grade_id = g.id
    WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled' AND {$enrollmentScopeSql}
      AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
    ORDER BY u.name");
$quick_students_stmt->execute([$currentAcademicYearId]);
$quick_students = $quick_students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total evaluations
if ($filter_class_id) {
    $eval_count_query = "SELECT COUNT(*) FROM evaluations e
        WHERE e.class_id = :class_id AND {$evaluationScopeSql}
          AND (e.academic_year_id = :academic_year_id OR e.academic_year_id IS NULL)";
    $stmt = $db->prepare($eval_count_query);
    $stmt->execute([':class_id' => $filter_class_id, ':academic_year_id' => $currentAcademicYearId]);
    $total_evaluations = $stmt->fetchColumn();
} else {
    $eval_count_query = "SELECT COUNT(*) FROM evaluations e
        WHERE {$evaluationScopeSql} AND (e.academic_year_id = ? OR e.academic_year_id IS NULL)";
    $evalCountStmt = $db->prepare($eval_count_query);
    $evalCountStmt->execute([$currentAcademicYearId]);
    $total_evaluations = $evalCountStmt->fetchColumn();
}

// Get all classes for dropdown (with grade info for cascading filters)
$classes_stmt = $db->prepare("SELECT c.id, c.name, c.grade_id, g.grade_name, g.stage_id, s.stage_name
    FROM classes c
    LEFT JOIN grades g ON c.grade_id = g.id
    LEFT JOIN stages s ON g.stage_id = s.id
    WHERE c.academic_year_id = ? AND {$classScopeSql}
    ORDER BY s.id, g.id, c.name");
$classes_stmt->execute([$currentAcademicYearId]);
$classes = [];
while ($class_row = $classes_stmt->fetch(PDO::FETCH_ASSOC)) {
    $classes[] = $class_row;
}

// Include admin header
include_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-chart-line me-2 text-primary"></i>تقييمات الطلاب</h1>
    <div class="admin-top-actions">
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#quickAddEvaluationModal">
            <i class="fas fa-plus-circle me-1"></i>إضافة تقييم سريع
        </button>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($top_5) || !empty($bottom_5)): ?>
    <div class="small fw-bold text-dark mb-2"><i class="fas fa-trophy me-1 text-warning"></i> لوحة صدارة الطلاب (أعلى 5 طلاب وأقل 5 طلاب نقاطاً)</div>
    <div class="d-flex gap-2 mb-4 w-100 flex-wrap flex-xl-nowrap">
        <!-- Top 5 (Ranks 1-5) -->
        <?php 
        $rank = 1;
        foreach ($top_5 as $s): 
            $s_name_parts = explode(' ', trim($s['name']));
            $s_short_name = count($s_name_parts) > 2 ? $s_name_parts[0] . ' ' . $s_name_parts[1] : $s['name'];
            
            $rank_badge = '';
            if ($rank == 1) $rank_badge = '🥇 الأول';
            elseif ($rank == 2) $rank_badge = '🥈 الثاني';
            elseif ($rank == 3) $rank_badge = '🥉 الثالث';
            else $rank_badge = '#' . $rank;
            
            // Consistent success gradient from premium design system
            $gradient = 'var(--success-gradient)';
            $rank++;
        ?>
        <div style="flex: 1 1 9%; min-width: 105px;">
            <div class="stat-card" style="--card-gradient: <?php echo $gradient; ?>; padding: 0.65rem 0.75rem; min-height: 90px; border-radius: 10px;">
                <div class="stat-card-icon" style="font-size: 2.2rem; bottom: -5px; left: -5px; opacity: 0.12; transform: rotate(-15deg); position: absolute; background: transparent !important;"><i class="fas fa-crown"></i></div>
                <div class="stat-card-badge text-white" style="background: rgba(255, 255, 255, 0.22); left: 6px; top: 6px; padding: 1px 5px; font-size: 0.68rem; font-weight: bold; white-space: nowrap;"><?php echo $rank_badge; ?></div>
                <div class="stat-card-info" style="margin-top: 10px; text-align: right;">
                    <div class="stat-card-number text-white fw-extrabold mb-0" style="font-size: 1.35rem; line-height: 1.1; text-shadow: 1px 1px 2px rgba(0,0,0,0.15); <?php echo ((int)$s['total_points'] < 0) ? 'color: #dc2626 !important;' : ''; ?>"><?php echo (int)$s['total_points']; ?></div>
                    <div class="stat-card-label fw-bold text-white mb-0" style="font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;" title="<?php echo htmlspecialchars($s['name']); ?>">
                        <?php echo htmlspecialchars($s_short_name); ?>
                    </div>
                    <div class="stat-card-sub text-white" style="font-size: 0.88rem; margin-top: 2px; font-weight: bold;">
                        <i class="fas fa-school me-1" style="font-size: 0.75rem;"></i><?php echo htmlspecialchars($s['class_name'] ?? 'بدون فصل'); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Bottom 5 (Ranks 6-10, reversed so lowest is last on the left) -->
        <?php 
        $reversed_bottom = array_reverse($bottom_5);
        $rank = 6;
        foreach ($reversed_bottom as $s): 
            $s_name_parts = explode(' ', trim($s['name']));
            $s_short_name = count($s_name_parts) > 2 ? $s_name_parts[0] . ' ' . $s_name_parts[1] : $s['name'];
            
            $rank_badge = '';
            if ($rank == 10) $rank_badge = '⚠️ الأخير';
            
            // Consistent danger gradient from premium design system
            $gradient = 'var(--danger-gradient)';
            $rank++;
        ?>
        <div style="flex: 1 1 9%; min-width: 105px;">
            <div class="stat-card stat-card--danger" style="--card-gradient: <?php echo $gradient; ?>; padding: 0.65rem 0.75rem; min-height: 90px; border-radius: 10px;">
                <div class="stat-card-icon" style="font-size: 2.2rem; bottom: -5px; left: -5px; opacity: 0.12; transform: rotate(-15deg); position: absolute; background: transparent !important;"><i class="fas fa-arrow-trend-down"></i></div>
                <?php if ($rank_badge !== ''): ?>
                <div class="stat-card-badge text-white" style="background: rgba(255, 255, 255, 0.22); left: 6px; top: 6px; padding: 1px 5px; font-size: 0.68rem; font-weight: bold; white-space: nowrap;"><?php echo $rank_badge; ?></div>
                <?php endif; ?>
                <div class="stat-card-info" style="margin-top: 10px; text-align: right;">
                    <div class="stat-card-number text-white fw-extrabold mb-0" style="font-size: 1.35rem; line-height: 1.1; text-shadow: 1px 1px 2px rgba(0,0,0,0.15); <?php echo ((int)$s['total_points'] < 0) ? 'color: #dc2626 !important;' : ''; ?>"><?php echo (int)$s['total_points']; ?> <span style="font-size: 0.75rem; font-weight: normal; opacity: 0.95;">نقطة</span></div>
                    <div class="stat-card-label fw-bold text-white mb-0" style="font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;" title="<?php echo htmlspecialchars($s['name']); ?>">
                        <?php echo htmlspecialchars($s_short_name); ?>
                    </div>
                    <div class="stat-card-sub text-white" style="font-size: 0.88rem; margin-top: 2px; font-weight: bold;">
                        <i class="fas fa-school me-1" style="font-size: 0.75rem;"></i><?php echo htmlspecialchars($s['class_name'] ?? 'بدون فصل'); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Students Evaluations Table -->
<div class="admin-filter-bar">
    <div class="admin-filter-controls">
                    <!-- Stage Filter -->
                    <select class="form-select form-select-sm" id="stageFilter" onchange="filterChange()" style="width: auto; min-width: 120px;">
                        <option value="">جميع المراحل</option>
                        <?php foreach ($stages as $stage_item): ?>
                            <option value="<?php echo $stage_item['id']; ?>" <?php echo ($filter_stage_id == $stage_item['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($stage_item['stage_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- Grade Filter -->
                    <select class="form-select form-select-sm" id="gradeFilter" onchange="filterChange()" style="width: auto; min-width: 120px;">
                        <option value="">جميع الصفوف</option>
                        <?php foreach ($grades as $grade_item): ?>
                            <option value="<?php echo $grade_item['id']; ?>" data-stage="<?php echo $grade_item['stage_id']; ?>" <?php echo ($filter_grade_id == $grade_item['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($grade_item['grade_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- Class Filter -->
                    <select class="form-select form-select-sm" id="classFilter" onchange="filterChange()" style="width: auto; min-width: 120px;">
                        <option value="">جميع الفصول</option>
                        <?php foreach ($classes as $class_item): ?>
                            <option value="<?php echo $class_item['id']; ?>" data-grade="<?php echo $class_item['grade_id']; ?>" <?php echo ($filter_class_id == $class_item['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class_item['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- Status Filter -->
                    <select class="form-select form-select-sm" id="studentStatusFilter" style="width: auto; min-width: 120px;">
                        <option value="all">جميع الحالات</option>
                        <option value="active">النشطون فقط</option>
                        <option value="inactive">المعطلون فقط</option>
                    </select>
                    
    </div>
    <div class="admin-filter-actions">
                    <!-- Reset Button -->
                    <a href="student_evaluations.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    
                    <!-- Table settings button -->
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="إعدادات الجدول">
                        <i class="fas fa-cog me-1"></i>إعدادات الجدول
                    </button>
    </div>
</div>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped admin-data-table <?php echo !empty($students) ? 'datatable' : ''; ?>" id="studentsEvalTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الكود</th>
                        <th>الاسم</th>
                        <th>الفصل</th>
                        <th>النقاط الحالية</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (isset($students) && !empty($students)): 
                        $i = 1;
                        foreach ($students as $student): 
                    ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars($student['student_code'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                            <td>
                                <?php 
                                    $className = isset($student['class_name']) ? $student['class_name'] : null;
                                    if (!empty($className)) {
                                        echo htmlspecialchars($className);
                                    } else {
                                        echo '<span class="text-muted">غير مسند لفصل</span>';
                                    }
                                ?>
                            </td>
                            <td class="admin-table-actions">
                                <?php 
                                    $total_points = isset($student['total_points']) ? $student['total_points'] : 0;
                                    echo '<span class="badge bg-' . ($total_points >= 0 ? 'success' : 'danger') . '">' . $total_points . '</span>';
                                ?>
                            </td>
                            <td>
                                <?php 
                                    $status = isset($student['status']) ? $student['status'] : 'active';
                                    if ($status === 'active') {
                                        echo '<span class="badge bg-success">نشط</span>';
                                    } elseif ($status === 'graduated') {
                                        echo '<span class="badge bg-primary">خريج</span>';
                                    } else {
                                        echo '<span class="badge bg-danger">معطل</span>';
                                    }
                                ?>
                            </td>
                            <td class="actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-edit view-evaluations has-tooltip me-1" data-id="<?php echo $student['id']; ?>" data-name="<?php echo htmlspecialchars($student['name']); ?>" data-bs-toggle="modal" data-bs-target="#evaluationsModal" title="التقييمات">
                                    <i class="fas fa-chart-line"></i>
                                </button>
                            </td>
                        </tr>
                    <?php 
                        endforeach;
                    else: ?>
                        <tr><td colspan="7" class="text-center text-muted">لا يوجد طلاب</td></tr>
                    <?php endif; ?>
                </tbody>
        </table>
    </div>
</div>

<!-- Table Column Settings Modal -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>اختر الأعمدة التي تريد عرضها في الجدول:</p>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_code" checked>
                    <label class="form-check-label" for="col_code">الكود</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_name" checked>
                    <label class="form-check-label" for="col_name">الاسم</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_class" checked>
                    <label class="form-check-label" for="col_class">الفصل</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_points" checked>
                    <label class="form-check-label" for="col_points">النقاط الحالية</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_status" checked>
                    <label class="form-check-label" for="col_status">الحالة</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Add Evaluation Modal -->
<div class="modal fade" id="quickAddEvaluationModal" tabindex="-1" aria-labelledby="quickAddEvaluationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create border-0 shadow">
            <form id="quickAddEvaluationForm">
                <div class="modal-header border-0 py-3">
                    <h5 class="modal-title fw-bold" id="quickAddEvaluationModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>إضافة تقييم سريع للطلاب
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle shadow-sm" style="width: 56px; height: 56px;">
                            <i class="fas fa-award fs-3"></i>
                        </div>
                        <h6 class="mt-2 fw-bold text-dark">تسجيل تقييم سريع ومباشر</h6>
                        <p class="text-muted small">اختر الطالب ونوع التقييم وسيتم تحديث نقاطه فوراً</p>
                    </div>

                    <!-- Quick Add Filters -->
                    <div class="row g-2 mb-3 align-items-end">
                        <div class="col-md-4">
                            <label for="quick_stage_id" class="form-label fw-semibold text-secondary small mb-1">المرحلة</label>
                            <select class="form-select form-select-sm" id="quick_stage_id">
                                <option value="">كل المراحل</option>
                                <?php foreach ($stages as $stage_item): ?>
                                    <option value="<?php echo $stage_item['id']; ?>"><?php echo htmlspecialchars($stage_item['stage_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="quick_grade_id" class="form-label fw-semibold text-secondary small mb-1">الصف</label>
                            <select class="form-select form-select-sm" id="quick_grade_id">
                                <option value="">كل الصفوف</option>
                                <?php foreach ($grades as $grade_item): ?>
                                    <option value="<?php echo $grade_item['id']; ?>" data-stage="<?php echo $grade_item['stage_id']; ?>"><?php echo htmlspecialchars($grade_item['grade_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="quick_class_id" class="form-label fw-semibold text-secondary small mb-1">الفصل</label>
                            <select class="form-select form-select-sm" id="quick_class_id">
                                <option value="">كل الفصول</option>
                                <?php foreach ($classes as $class_item): ?>
                                    <option value="<?php echo $class_item['id']; ?>" data-grade="<?php echo $class_item['grade_id']; ?>"><?php echo htmlspecialchars($class_item['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="quick_student_id" class="form-label fw-semibold text-secondary small">الطالب المستهدف</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-user"></i></span>
                            <select class="form-select border-start-0 ps-0 text-start" id="quick_student_id" name="student_id" required>
                                <option value="">اختر الطالب...</option>
                                <?php foreach ($quick_students as $s_item): ?>
                                    <option value="<?php echo $s_item['id']; ?>" 
                                            data-stage="<?php echo $s_item['stage_id'] ?? ''; ?>" 
                                            data-grade="<?php echo $s_item['grade_id'] ?? ''; ?>" 
                                            data-class="<?php echo $s_item['class_id'] ?? ''; ?>">
                                        <?php echo htmlspecialchars($s_item['name']); ?> (<?php echo htmlspecialchars($s_item['class_name'] ?? 'بدون فصل'); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="quick_evaluation_type_id" class="form-label fw-semibold text-secondary small">نوع التقييم السلوكي</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-star"></i></span>
                            <select class="form-select border-start-0 ps-0 text-start" id="quick_evaluation_type_id" name="evaluation_type_id" required>
                                <option value="">اختر نوع التقييم...</option>
                                <?php 
                                $eval_types = $evaluation_type->readAll();
                                while ($type_row = $eval_types->fetch(PDO::FETCH_ASSOC)): ?>
                                    <option value="<?php echo $type_row['id']; ?>">
                                        <?php echo htmlspecialchars($type_row['name']); ?> 
                                        (<?php echo $type_row['type'] == 'positive' ? '+' : '-'; ?><?php echo $type_row['points']; ?> نقطة)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="quick_reason" class="form-label fw-semibold text-secondary small">ملاحظات / سبب التقييم (اختياري)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-comment-alt"></i></span>
                            <input type="text" class="form-control border-start-0 ps-0 text-start" id="quick_reason" name="reason" placeholder="مثال: تميز في النشاط الرياضي، الغياب المتكرر...">
                        </div>
                    </div>

                    <!-- Live Preview Alert Box -->
                    <div id="quick_eval_preview" class="mb-2 d-none"></div>
                </div>
                <div class="modal-footer border-top-0 pt-0 p-4">
                    <button type="button" class="btn px-4 btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success px-4">
                        <i class="fas fa-save me-1"></i>إضافة التقييم
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Student Evaluations Modal -->
<div class="modal fade" id="evaluationsModal" tabindex="-1" aria-labelledby="evaluationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="evaluationsModalLabel">
                    تقييمات الطالب: <span id="student_evaluations_name"></span>
                    <small class="text-muted">(<span id="student_class_name">جاري التحميل...</span>)</small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h5>إجمالي النقاط</h5>
                                <h2 id="total_points_display">0</h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h5>عدد التقييمات</h5>
                                <h2 id="total_evaluations_count">0</h2>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Add New Evaluation Form -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">إضافة تقييم جديد</h6>
                    </div>
                    <div class="card-body">
                        <form id="addEvaluationForm">
                            <input type="hidden" id="eval_student_id" name="student_id">
                            
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label for="evaluation_type_id" class="form-label">نوع التقييم</label>
                                    <select class="form-select" id="evaluation_type_id" name="evaluation_type_id" required>
                                        <option value="">-- اختر نوع التقييم --</option>
                                        <?php 
                                        $eval_types = $evaluation_type->readAll();
                                        while ($type_row = $eval_types->fetch(PDO::FETCH_ASSOC)): ?>
                                            <option value="<?php echo $type_row['id']; ?>" 
                                                data-points="<?php echo $type_row['points']; ?>" 
                                                data-type="<?php echo $type_row['type']; ?>">
                                                <?php echo htmlspecialchars($type_row['name']); ?> 
                                                <?php if ($type_row['points'] > 0): ?>
                                                    (<?php echo $type_row['type'] == 'positive' ? '+' : '-'; ?><?php echo $type_row['points']; ?> نقطة)
                                                <?php endif; ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- خيارات تعديل النقاط للأدمن فقط -->
                            <div class="row g-3 mt-2" id="admin-points-section">
                                <div class="col-md-12">
                                    <div class="card bg-light">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0 text-primary"><i class="fas fa-crown me-1"></i>تعديل النقاط (للأدمن فقط)</h6>
                                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>متاح دائماً</span>
                                        </div>
                                        <div class="card-body">
                                            <div class="alert alert-info mb-3" style="font-size: 0.9rem;">
                                                <i class="fas fa-info-circle me-1"></i>
                                                <strong>ملاحظة:</strong> يمكنك كأدمن إعطاء النقاط المخصصة في أي وقت حتى عندما يكون النظام معطلاً أو خارج المواعيد المسموح بها.
                                            </div>
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="enable_custom_points" name="enable_custom_points">
                                                <label class="form-check-label" for="enable_custom_points">
                                                    تفعيل تعديل النقاط يدوياً
                                                </label>
                                            </div>
                                            
                                            <div id="custom-points-controls" style="display: none;">
                                                <div class="row g-2">
                                                    <div class="col-md-6">
                                                        <label for="points_action" class="form-label">العملية</label>
                                                        <select class="form-select" id="points_action" name="points_action">
                                                            <option value="add">إضافة نقاط (+)</option>
                                                            <option value="subtract">خصم نقاط (-)</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label for="custom_points" class="form-label">عدد النقاط</label>
                                                        <input type="number" class="form-control" id="custom_points" name="custom_points" min="1" max="100">
                                                    </div>
                                                </div>
                                                <div class="mt-2">
                                                    <label for="points_reason" class="form-label">السبب</label>
                                                    <input type="text" class="form-control" id="points_reason" name="reason" placeholder="اكتب سبب تعديل النقاط...">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" class="btn btn-success">إضافة تقييم</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Evaluations List -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="evaluationsTable">
                        <thead>
                            <tr>
                                <th>نوع التقييم</th>
                                <th>النقاط</th>
                                <th>المعلم</th>
                                <th>التاريخ والوقت</th>
                                <th>السبب</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody id="evaluationsTableBody">
                            <!-- Data will be loaded via AJAX -->
                        </tbody>
                    </table>
                </div>
                
                <!-- Action buttons for evaluations -->
                <div class="mt-3">
                    <button type="button" class="btn btn-header-premium btn-export-soft" id="exportEvaluationsBtn">
                        <i class="fas fa-file-excel me-1"></i>
                        تصدير Excel
                    </button>
                    <button type="button" class="btn btn-danger btn-sm ms-2" id="deleteAllEvaluationsBtn">
                        <i class="fas fa-trash-alt me-1"></i>
                        حذف جميع التقييمات
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete All Evaluations Modal -->
<div class="modal fade" id="deleteAllEvaluationsModal" tabindex="-1" aria-labelledby="deleteAllEvaluationsModalLabel">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteAllEvaluationsModalLabel">
                    <i class="fas fa-trash-alt me-2"></i>حذف جميع التقييمات
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من حذف جميع تقييمات الطالب <span class="fw-bold text-primary" id="delete_all_student_name"></span>؟</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    سيتم حذف جميع التقييمات الإيجابية والسلبية لهذا الطالب.
                </div>
                <p class="text-danger text-center mb-0">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    هذا الإجراء لا يمكن التراجع عنه.
                </p>
            </div>
            <div class="modal-footer">
                <input type="hidden" id="delete_all_student_id" name="delete_all_student_id">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteAllEvaluations">حذف الجميع</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Single Evaluation Modal -->
<div class="modal fade" id="deleteSingleEvaluationModal" tabindex="-1" aria-labelledby="deleteSingleEvaluationModalLabel">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteSingleEvaluationModalLabel">
                    <i class="fas fa-trash-alt me-2"></i>حذف التقييم
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من حذف هذا التقييم؟</p>
                <p class="text-danger text-center mb-0">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    هذا الإجراء لا يمكن التراجع عنه.
                </p>
            </div>
            <div class="modal-footer">
                <input type="hidden" id="delete_single_evaluation_id" name="delete_single_evaluation_id">
                <input type="hidden" id="delete_single_student_id" name="delete_single_student_id">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteSingleEvaluation">حذف</button>
            </div>
        </div>
    </div>
</div>

<script>
// Global variable to store current student ID for export/delete functions
let currentEvaluationStudentId = null;

document.addEventListener('DOMContentLoaded', function() {
    // Toggle custom points controls visibility
    const enableCustomPoints = document.getElementById('enable_custom_points');
    if (enableCustomPoints) {
        enableCustomPoints.addEventListener('change', function() {
            document.getElementById('custom-points-controls').style.display = this.checked ? 'block' : 'none';
            if (!this.checked) {
                document.getElementById('custom_points').value = '';
                document.getElementById('points_reason').value = '';
            }
        });
    }

    // Handle evaluation button click (delegated)
    document.addEventListener('click', function(e) {
        const evalBtn = e.target.closest('.view-evaluations');
        if (evalBtn) {
            const studentId = evalBtn.getAttribute('data-id');
            const studentName = evalBtn.getAttribute('data-name') || '';
            
            currentEvaluationStudentId = studentId;
            
            const nameElement = document.getElementById('student_evaluations_name');
            const idElement = document.getElementById('eval_student_id');
            
            if (nameElement) nameElement.textContent = studentName;
            if (idElement) idElement.value = studentId;
            
            // Fetch class name
            const classNameElement = document.getElementById('student_class_name');
            if (classNameElement) {
                classNameElement.textContent = 'جاري التحميل...';
                fetch(`../includes/ajax_handlers.php?action=get_student_class&student_id=${studentId}`)
                    .then(response => response.json())
                    .then(data => {
                        classNameElement.textContent = (data.success && data.class_name) ? data.class_name : 'غير محدد';
                    })
                    .catch(() => { classNameElement.textContent = 'خطأ في التحميل'; });
            }
            
            loadStudentEvaluations(studentId);
        }
    });

    // Handle add evaluation form submission
    document.getElementById('addEvaluationForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'add_evaluation');
        formData.append('teacher_id', <?php echo $_SESSION['user_id']; ?>);
        
        const enableCustomPoints = document.getElementById('enable_custom_points');
        if (enableCustomPoints && enableCustomPoints.checked) {
            const customPoints = document.getElementById('custom_points').value;
            const pointsAction = document.getElementById('points_action').value;
            const reason = document.getElementById('points_reason').value;
            
            if (!customPoints || !reason) {
                showAlert('danger', 'يرجى ملء عدد النقاط والسبب عند تفعيل التعديل اليدوي');
                return;
            }
            
            formData.append('custom_points_enabled', '1');
            formData.append('custom_points', customPoints);
            formData.append('points_action', pointsAction);
            formData.append('reason', reason);
            formData.delete('evaluation_type_id');
        } else {
            if (!formData.get('evaluation_type_id')) {
                showAlert('danger', 'يرجى اختيار نوع التقييم');
                return;
            }
        }
        
        const submitButton = this.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> جاري الإضافة...';
        
        fetch('../includes/ajax_handlers.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok. Status: ' + response.status);
            return response.json();
        })
        .then(data => {
            submitButton.disabled = false;
            submitButton.innerHTML = 'إضافة تقييم';
            
            if (data.success) {
                const studentId = document.getElementById('eval_student_id').value;
                loadStudentEvaluations(studentId);
                this.reset();
                showAlert('success', data.message || 'تم إضافة التقييم بنجاح');
                if (data.total_points !== undefined) {
                    updateStudentPoints(studentId, data.total_points);
                }
            } else {
                showAlert('danger', data.message || 'حدث خطأ أثناء إضافة التقييم');
            }
        })
        .catch(error => {
            submitButton.disabled = false;
            submitButton.innerHTML = 'إضافة تقييم';
            handleAjaxError(error, 'حدث خطأ أثناء إضافة التقييم');
        });
    });

    // Status filter for DataTable
    const statusFilter = document.getElementById('studentStatusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            const value = this.value;
            const table = document.getElementById('studentsEvalTable');
            if (!table) return;
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                if (value === 'all') {
                    row.style.display = '';
                } else {
                    const statusCell = row.querySelector('td:nth-child(6)');
                    if (statusCell) {
                        const badge = statusCell.querySelector('.badge');
                        if (!badge) { row.style.display = ''; return; }
                        const text = badge.textContent.trim();
                        const match = (value === 'active' && text === 'نشط') ||
                                      (value === 'inactive' && text === 'معطل') ||
                                      (value === 'graduated' && text === 'خريج');
                        row.style.display = match ? '' : 'none';
                    }
                }
            });
        });
    }
});

// Export evaluations to Excel
document.addEventListener('click', function(e) {
    if (e.target && (e.target.id === 'exportEvaluationsBtn' || e.target.closest('#exportEvaluationsBtn'))) {
        const studentId = currentEvaluationStudentId;
        if (studentId) {
            window.open(`../includes/ajax_handlers.php?action=export_student_evaluations&student_id=${studentId}`, '_blank');
        }
    }
});

// Delete all evaluations - show confirmation modal
document.addEventListener('click', function(e) {
    if (e.target && (e.target.id === 'deleteAllEvaluationsBtn' || e.target.closest('#deleteAllEvaluationsBtn'))) {
        const studentId = currentEvaluationStudentId;
        const studentName = document.getElementById('student_evaluations_name').textContent;
        if (studentId) {
            document.getElementById('delete_all_student_id').value = studentId;
            document.getElementById('delete_all_student_name').textContent = studentName;
            new bootstrap.Modal(document.getElementById('deleteAllEvaluationsModal')).show();
        }
    }
});

// Confirm delete all evaluations
document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'confirmDeleteAllEvaluations') {
        const studentId = document.getElementById('delete_all_student_id').value;
        deleteAllStudentEvaluations(studentId);
        const modal = bootstrap.Modal.getInstance(document.getElementById('deleteAllEvaluationsModal'));
        if (modal) modal.hide();
    }
});

// Handle delete single evaluation click
document.addEventListener('click', function(e) {
    const deleteButton = e.target.closest('.delete-evaluation');
    if (deleteButton) {
        const evaluationId = deleteButton.getAttribute('data-id');
        const studentId = currentEvaluationStudentId;
        if (evaluationId && studentId) {
            document.getElementById('delete_single_evaluation_id').value = evaluationId;
            document.getElementById('delete_single_student_id').value = studentId;
            new bootstrap.Modal(document.getElementById('deleteSingleEvaluationModal')).show();
        }
    }
});

// Confirm delete single evaluation
document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'confirmDeleteSingleEvaluation') {
        const evaluationId = document.getElementById('delete_single_evaluation_id').value;
        const studentId = document.getElementById('delete_single_student_id').value;
        deleteSingleEvaluation(evaluationId, studentId);
        const modal = bootstrap.Modal.getInstance(document.getElementById('deleteSingleEvaluationModal'));
        if (modal) modal.hide();
    }
});

// Load student evaluations via AJAX
function loadStudentEvaluations(studentId) {
    const tableBody = document.getElementById('evaluationsTableBody');
    if (tableBody) {
        tableBody.innerHTML = '<tr><td colspan="6" class="text-center"><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</td></tr>';
    }
    
    document.getElementById('total_points_display').textContent = '0';
    document.getElementById('total_evaluations_count').textContent = '0';
    
    fetch('../includes/ajax_handlers.php?action=get_student_evaluations&student_id=' + studentId)
        .then(response => {
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            return response.json();
        })
        .then(data => {
            if (data.success) {
                document.getElementById('total_points_display').textContent = data.total_points || '0';
                document.getElementById('total_evaluations_count').textContent = data.evaluations ? data.evaluations.length : '0';
                
                updateStudentPoints(studentId, data.total_points || 0);
                
                const tableBody = document.getElementById('evaluationsTableBody');
                tableBody.innerHTML = '';
                
                if (!data.evaluations || data.evaluations.length === 0) {
                    const row = document.createElement('tr');
                    row.innerHTML = '<td colspan="6" class="text-center text-muted">لا توجد تقييمات لهذا الطالب</td>';
                    tableBody.appendChild(row);
                } else {
                    data.evaluations.forEach(function(evaluation) {
                        const row = document.createElement('tr');
                        const pointsClass = evaluation.display_points >= 0 ? 'text-success' : 'text-danger';
                        const pointsSign = evaluation.display_points >= 0 ? '+' : '';
                        
                        let formattedDate = evaluation.date_created;
                        let formattedTime = '';
                        try {
                            const date = new Date(evaluation.date_created);
                            formattedDate = date.toLocaleDateString('en-GB');
                            formattedTime = date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
                        } catch (e) {}
                        
                        row.innerHTML = `
                            <td>${evaluation.evaluation_name || 'غير محدد'}</td>
                            <td class="${pointsClass}">${pointsSign}${evaluation.display_points || 0}</td>
                            <td>${evaluation.teacher_name || 'غير محدد'}</td>
                            <td>${formattedDate}<br><small class="text-muted">${formattedTime}</small></td>
                            <td>${evaluation.reason || '<span class="text-muted">-</span>'}</td>
                            <td class="actions-column admin-table-actions"><button type="button" class="btn btn-action-pills btn-delete delete-evaluation" data-id="${evaluation.id}" title="حذف"><i class="fas fa-trash"></i></button></td>
                        `;
                        tableBody.appendChild(row);
                    });
                }
            } else {
                showAlert('danger', data.message || 'حدث خطأ في جلب التقييمات');
                const tableBody = document.getElementById('evaluationsTableBody');
                if (tableBody) {
                    tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">خطأ: ${data.message || ''}</td></tr>`;
                }
            }
        })
        .catch(error => {
            handleAjaxError(error, 'حدث خطأ أثناء جلب التقييمات');
            const tableBody = document.getElementById('evaluationsTableBody');
            if (tableBody) {
                tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">خطأ في الاتصال</td></tr>`;
            }
        });
}

// Update student points in the table
function updateStudentPoints(studentId, newPoints) {
    const table = document.getElementById('studentsEvalTable');
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const actionButtons = row.querySelectorAll('.btn');
        actionButtons.forEach(button => {
            if (button.getAttribute('data-id') === studentId) {
                const pointsCell = row.querySelector('td:nth-child(5)');
                if (pointsCell) {
                    const pointsClass = newPoints >= 0 ? 'bg-success' : 'bg-danger';
                    pointsCell.innerHTML = `<span class="badge ${pointsClass}">${newPoints}</span>`;
                }
            }
        });
    });
}

// Delete all evaluations for a student
function deleteAllStudentEvaluations(studentId) {
    fetch('../includes/ajax_handlers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_all_student_evaluations&student_id=${studentId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', 'تم حذف جميع التقييمات بنجاح.');
            loadStudentEvaluations(studentId);
            updateStudentPoints(studentId, 0);
        } else {
            showAlert('danger', data.message || 'فشل في حذف التقييمات.');
        }
    })
    .catch(error => {
        handleAjaxError(error, 'حدث خطأ أثناء حذف التقييمات.');
    });
}

// Delete single evaluation
function deleteSingleEvaluation(evaluationId, studentId) {
    fetch('../includes/ajax_handlers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_evaluation&evaluation_id=${evaluationId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', 'تم حذف التقييم بنجاح.');
            loadStudentEvaluations(studentId);
            if (data.new_total !== undefined) {
                updateStudentPoints(studentId, data.new_total);
            }
        } else {
            showAlert('danger', data.message || 'فشل في حذف التقييم.');
        }
    })
    .catch(error => {
        handleAjaxError(error, 'حدث خطأ أثناء حذف التقييم.');
    });
}

// Filter Change & Redirection Handler
let originalGrades = [];
let originalClasses = [];

document.addEventListener('DOMContentLoaded', function () {
    const stageFilter = document.getElementById('stageFilter');
    const gradeFilter = document.getElementById('gradeFilter');
    const classFilter = document.getElementById('classFilter');

    if (stageFilter && gradeFilter && classFilter) {
        originalGrades = Array.from(gradeFilter.querySelectorAll('option'));
        originalClasses = Array.from(classFilter.querySelectorAll('option'));

        updateFiltersCascading();

        stageFilter.addEventListener('change', updateFiltersCascading);
        gradeFilter.addEventListener('change', updateFiltersCascading);
    }
    
    // Initialize Table Column Settings
    if (typeof initializeTableColumnSettings === 'function') {
        initializeTableColumnSettings('studentsEvalTable', {
            col_code: 1,
            col_name: 2,
            col_class: 3,
            col_points: 4,
            col_status: 5
        }, 'student_evaluations_table_columns_v3');
    }
    
    // Quick Add Evaluation Form Submission
    const quickAddForm = document.getElementById('quickAddEvaluationForm');
    if (quickAddForm) {
        quickAddForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'add_evaluation');
            formData.append('teacher_id', <?php echo $_SESSION['user_id']; ?>);
            
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> جاري الإضافة...';
            
            fetch('../includes/ajax_handlers.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                submitButton.disabled = false;
                submitButton.innerHTML = '<i class="fas fa-save me-1"></i>إضافة التقييم';
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('quickAddEvaluationModal')).hide();
                    this.reset();
                    showAlert('success', data.message || 'تم إضافة التقييم بنجاح');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert('danger', data.message || 'حدث خطأ أثناء إضافة التقييم');
                }
            })
            .catch(error => {
                submitButton.disabled = false;
                submitButton.innerHTML = '<i class="fas fa-save me-1"></i>إضافة التقييم';
                handleAjaxError(error, 'حدث خطأ أثناء إضافة التقييم');
            });
        });
    }

    // Quick Add Live Preview & Cascading Filters Hook
    const quickStage = document.getElementById('quick_stage_id');
    const quickGrade = document.getElementById('quick_grade_id');
    const quickClass = document.getElementById('quick_class_id');
    const quickStudent = document.getElementById('quick_student_id');
    const quickEvalType = document.getElementById('quick_evaluation_type_id');
    const quickPreview = document.getElementById('quick_eval_preview');

    let originalQuickGrades = [];
    let originalQuickClasses = [];
    let originalQuickStudents = [];

    if (quickStage && quickGrade && quickClass && quickStudent) {
        originalQuickGrades = Array.from(quickGrade.querySelectorAll('option'));
        originalQuickClasses = Array.from(quickClass.querySelectorAll('option'));
        originalQuickStudents = Array.from(quickStudent.querySelectorAll('option'));

        function filterQuickOptions() {
            const selectedStage = quickStage.value;
            const selectedGrade = quickGrade.value;
            const selectedClass = quickClass.value;

            // 1. Filter Grades dropdown
            const currentGradeVal = quickGrade.value;
            quickGrade.innerHTML = '';
            originalQuickGrades.forEach(opt => {
                if (opt.value === '' || !selectedStage || opt.getAttribute('data-stage') === selectedStage) {
                    quickGrade.appendChild(opt.cloneNode(true));
                }
            });
            const gradeExists = Array.from(quickGrade.options).some(o => o.value === currentGradeVal);
            quickGrade.value = gradeExists ? currentGradeVal : '';

            // 2. Filter Classes dropdown
            const currentClassVal = quickClass.value;
            const activeGrade = quickGrade.value;
            quickClass.innerHTML = '';
            originalQuickClasses.forEach(opt => {
                const classGradeId = opt.getAttribute('data-grade');
                let matchStage = true;
                if (selectedStage && classGradeId) {
                    const gradeOpt = originalQuickGrades.find(g => g.value === classGradeId);
                    if (gradeOpt && gradeOpt.getAttribute('data-stage') !== selectedStage) {
                        matchStage = false;
                    }
                }
                if (opt.value === '' || (matchStage && (!activeGrade || classGradeId === activeGrade))) {
                    quickClass.appendChild(opt.cloneNode(true));
                }
            });
            const classExists = Array.from(quickClass.options).some(o => o.value === currentClassVal);
            quickClass.value = classExists ? currentClassVal : '';

            // 3. Filter Students dropdown
            const activeStage = quickStage.value;
            const activeGradeVal = quickGrade.value;
            const activeClassVal = quickClass.value;
            
            const currentStudentVal = quickStudent.value;
            quickStudent.innerHTML = '';
            originalQuickStudents.forEach(opt => {
                if (opt.value === '') {
                    quickStudent.appendChild(opt.cloneNode(true));
                    return;
                }
                const sStage = opt.getAttribute('data-stage');
                const sGrade = opt.getAttribute('data-grade');
                const sClass = opt.getAttribute('data-class');

                let match = true;
                if (activeStage && sStage !== activeStage) match = false;
                if (activeGradeVal && sGrade !== activeGradeVal) match = false;
                if (activeClassVal && sClass !== activeClassVal) match = false;

                if (match) {
                    quickStudent.appendChild(opt.cloneNode(true));
                }
            });
            const studentExists = Array.from(quickStudent.options).some(o => o.value === currentStudentVal);
            quickStudent.value = studentExists ? currentStudentVal : '';
            
            updateQuickPreview();
        }

        quickStage.addEventListener('change', function() {
            quickGrade.value = '';
            quickClass.value = '';
            filterQuickOptions();
        });
        quickGrade.addEventListener('change', function() {
            quickClass.value = '';
            filterQuickOptions();
        });
        quickClass.addEventListener('change', filterQuickOptions);
    }

    function updateQuickPreview() {
        if (quickStudent && quickEvalType && quickPreview) {
            const studentName = quickStudent.options[quickStudent.selectedIndex]?.text || '';
            const evalText = quickEvalType.options[quickEvalType.selectedIndex]?.text || '';
            
            if (quickStudent.value && quickEvalType.value) {
                quickPreview.classList.remove('d-none');
                quickPreview.innerHTML = `
                    <div class="alert alert-info d-flex align-items-center gap-2 mb-0 py-2 px-3 text-start" style="font-size: 0.88rem;">
                        <i class="fas fa-info-circle fs-5"></i>
                        <div>سيتم تسجيل التقييم <strong>${evalText}</strong> للطالب <strong>${studentName}</strong>.</div>
                    </div>
                `;
            } else {
                quickPreview.classList.add('d-none');
            }
        }
    }

    if (quickStudent && quickEvalType) {
        quickStudent.addEventListener('change', updateQuickPreview);
        quickEvalType.addEventListener('change', updateQuickPreview);
    }
});

function updateFiltersCascading() {
    const stageFilter = document.getElementById('stageFilter');
    const gradeFilter = document.getElementById('gradeFilter');
    const classFilter = document.getElementById('classFilter');

    if (!stageFilter || !gradeFilter || !classFilter) return;

    const selectedStage = stageFilter.value;

    // Filter grades based on selected stage
    const currentGradeVal = gradeFilter.value;
    gradeFilter.innerHTML = '';
    originalGrades.forEach(opt => {
        if (opt.value === '' || !selectedStage || opt.getAttribute('data-stage') === selectedStage) {
            gradeFilter.appendChild(opt.cloneNode(true));
        }
    });
    const gradeExists = Array.from(gradeFilter.options).some(o => o.value === currentGradeVal);
    gradeFilter.value = gradeExists ? currentGradeVal : '';

    // Filter classes based on selected grade and stage
    const currentClassVal = classFilter.value;
    const activeGrade = gradeFilter.value;
    classFilter.innerHTML = '';
    originalClasses.forEach(opt => {
        const classGradeId = opt.getAttribute('data-grade');
        let matchStage = true;
        
        if (selectedStage && classGradeId) {
            const gradeOpt = originalGrades.find(g => g.value === classGradeId);
            if (gradeOpt && gradeOpt.getAttribute('data-stage') !== selectedStage) {
                matchStage = false;
            }
        }

        if (opt.value === '' || (matchStage && (!activeGrade || classGradeId === activeGrade))) {
            classFilter.appendChild(opt.cloneNode(true));
        }
    });
    const classExists = Array.from(classFilter.options).some(o => o.value === currentClassVal);
    classFilter.value = classExists ? currentClassVal : '';
}

function filterChange() {
    const stageFilter = document.getElementById('stageFilter');
    const gradeFilter = document.getElementById('gradeFilter');
    const classFilter = document.getElementById('classFilter');

    let stageId = stageFilter ? stageFilter.value : '';
    let gradeId = gradeFilter ? gradeFilter.value : '';
    let classId = classFilter ? classFilter.value : '';

    // If stage changed, check if selected grade is still valid
    if (stageId) {
        const selectedGradeOpt = originalGrades.find(g => g.value === gradeId);
        if (selectedGradeOpt && selectedGradeOpt.getAttribute('data-stage') !== stageId) {
            gradeId = '';
            classId = '';
        }
    }

    // If grade changed, check if selected class is still valid
    if (gradeId) {
        const selectedClassOpt = originalClasses.find(c => c.value === classId);
        if (selectedClassOpt && selectedClassOpt.getAttribute('data-grade') !== gradeId) {
            classId = '';
        }
    }

    let params = [];
    if (stageId) params.push('stage_id=' + encodeURIComponent(stageId));
    if (gradeId) params.push('grade_id=' + encodeURIComponent(gradeId));
    if (classId) params.push('class_id=' + encodeURIComponent(classId));

    window.location.href = 'student_evaluations.php' + (params.length ? '?' + params.join('&') : '');
}

// Show alert messages
function showAlert(type, message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.role = 'alert';
    alertDiv.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check' : 'exclamation'}-circle me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    const container = document.querySelector('.container-fluid') || document.querySelector('main');
    if (container) {
        container.insertBefore(alertDiv, container.firstChild);
        setTimeout(() => { alertDiv.classList.remove('show'); setTimeout(() => alertDiv.remove(), 150); }, 5000);
    }
}

// Error handling
function handleAjaxError(error, message) {
    console.error('Error:', error);
    showAlert('danger', message || 'حدث خطأ في الاتصال بالخادم');
}
</script>



<script src="../assets/js/admin_table_actions.js"></script>
<?php require_once '../includes/admin_footer.php'; ?>
