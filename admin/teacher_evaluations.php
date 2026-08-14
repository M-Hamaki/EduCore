<?php
/**
 * تقييمات المعلمين
 * Teacher Evaluations — admin overview of evaluation activity per staff member.
 *
 * تعرض هذه الصفحة جدولاً بكل العاملين (معلمين/أخصائيين) مع عدد التقييمات
 * التي منحها كل واحد، وفلاتر ديناميكية (المرحلة→الصف→الفصل)، ومودال لإدارة
 * تقييمات أي معلم (عرض/حذف/تعديل/تصدير).
 */
$page_title = "تقييمات المعلمين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/user.php';
require_once '../classes/evaluation.php';
require_once '../classes/evaluation_type.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../classes/StaffEmploymentLifecycleService.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();
$normalizedAllowedClassIds = $allowedClassIds === null ? [] : array_values(array_unique(array_filter(
    array_map('intval', $allowedClassIds),
    static fn(int $id): bool => $id > 0
)));
$classScopeSql = $allowedClassIds === null
    ? '1 = 1'
    : ($normalizedAllowedClassIds === [] ? '1 = 0' : 'c.id IN (' . implode(',', $normalizedAllowedClassIds) . ')');
$evaluationScopeSql = $allowedClassIds === null
    ? '1 = 1'
    : ($normalizedAllowedClassIds === [] ? '1 = 0' : 'e.class_id IN (' . implode(',', $normalizedAllowedClassIds) . ')');
$staffScopeSql = $allowedClassIds === null
    ? ''
    : " AND EXISTS (
            SELECT 1 FROM user_role_assignments scoped_role
            WHERE scoped_role.user_id = u.id AND scoped_role.role_key = 'teacher' AND scoped_role.status = 'active'
        ) AND EXISTS (
            SELECT 1 FROM user_class_access scoped_uca
            WHERE scoped_uca.user_id = u.id AND "
        . ($normalizedAllowedClassIds === []
            ? '1 = 0'
            : 'scoped_uca.class_id IN (' . implode(',', $normalizedAllowedClassIds) . ')')
        . ')';
$evaluationYearSql = $currentAcademicYearId > 0
    ? " AND (e.academic_year_id = {$currentAcademicYearId} OR e.academic_year_id IS NULL)"
    : '';

// PRG: messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message   = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$user = new User($db);

// ─────────────────────────────────────────────────────────────────────────────
// الاستعلام الرئيسي: كل العاملين + عدّ التقييمات (إيجابي/سلبي) + أسماء الفصول
// N+1 prevention: استعلام واحد مع subqueries على أعمدة مفهرسة.
// ─────────────────────────────────────────────────────────────────────────────
$staffQuery = "SELECT u.id, u.name, u.role, u.status, u.is_supervisor,
                      sp.employee_code, sp.job_title, sp.phone_mobile, sp.department,
                      (SELECT COUNT(*) FROM evaluations e WHERE e.teacher_id = u.id
                          AND {$evaluationScopeSql} {$evaluationYearSql}) AS eval_count,
                      (SELECT COUNT(*) FROM evaluations e
                          JOIN evaluation_types et ON et.id = e.evaluation_type_id
                          WHERE e.teacher_id = u.id
                            AND {$evaluationScopeSql} {$evaluationYearSql}
                            AND (et.type = 'positive'
                                 OR (e.custom_points IS NOT NULL AND e.custom_points > 0))
                      ) AS positive_count,
                      (SELECT COUNT(*) FROM evaluations e
                          JOIN evaluation_types et ON et.id = e.evaluation_type_id
                          WHERE e.teacher_id = u.id
                            AND {$evaluationScopeSql} {$evaluationYearSql}
                            AND (et.type = 'negative'
                                 OR (e.custom_points IS NOT NULL AND e.custom_points < 0))
                      ) AS negative_count,
                      GROUP_CONCAT(DISTINCT
                          CASE WHEN u.role = 'specialist' THEN sc_c.name ELSE uca_c.name END
                          ORDER BY CASE WHEN u.role = 'specialist' THEN sc_c.name ELSE uca_c.name END
                          SEPARATOR ', '
                      ) AS class_names
               FROM users u
               LEFT JOIN staff_profiles sp ON u.id = sp.user_id
               LEFT JOIN user_class_access uca ON u.id = uca.user_id AND EXISTS (
                   SELECT 1 FROM user_role_assignments teacher_role
                   WHERE teacher_role.user_id = u.id AND teacher_role.role_key = 'teacher' AND teacher_role.status = 'active'
               )
               LEFT JOIN classes uca_c ON uca.class_id = uca_c.id
               LEFT JOIN specialist_active_classes sc ON u.id = sc.specialist_id AND u.role = 'specialist'
               LEFT JOIN classes sc_c ON sc.class_id = sc_c.id
               WHERE EXISTS (
                   SELECT 1 FROM user_role_assignments listed_role
                   LEFT JOIN staff_roles listed_definition ON listed_definition.role_key = listed_role.role_key
                   WHERE listed_role.user_id = u.id AND listed_role.status = 'active'
                     AND (listed_role.role_key IN ('teacher', 'specialist') OR listed_definition.base_role_key = 'specialist')
               )
                 {$staffScopeSql}
               GROUP BY u.id
               ORDER BY eval_count DESC, u.name";
$staffRows = $db->query($staffQuery)->fetchAll(PDO::FETCH_ASSOC);
foreach ($staffRows as &$staffRow) {
    $staffRow['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($staffRow['job_title'] ?? null);
}
unset($staffRow);

// ─────────────────────────────────────────────────────────────────────────────
// تحميل خرائط الفلاتر: المراحل، الصفوف، الفصول — لاستخدامها في الفلاتر
// المتسلسلة (cascading) على جانب العميل.
// ─────────────────────────────────────────────────────────────────────────────
$classesStmt = $db->prepare("SELECT c.id, c.name, c.grade_id FROM classes c
    WHERE c.academic_year_id = ? AND {$classScopeSql} ORDER BY c.grade_id, c.name");
$classesStmt->execute([$currentAcademicYearId]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);
$gradesStmt = $db->prepare("SELECT DISTINCT g.id, g.grade_name, g.stage_id
    FROM grades g JOIN classes c ON c.grade_id = g.id
    WHERE c.academic_year_id = ? AND {$classScopeSql} ORDER BY g.stage_id, g.id");
$gradesStmt->execute([$currentAcademicYearId]);
$grades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);
$stagesStmt = $db->prepare("SELECT DISTINCT s.id, s.stage_name
    FROM stages s JOIN grades g ON g.stage_id = s.id JOIN classes c ON c.grade_id = g.id
    WHERE c.academic_year_id = ? AND {$classScopeSql} ORDER BY s.stage_order, s.id");
$stagesStmt->execute([$currentAcademicYearId]);
$stages = $stagesStmt->fetchAll(PDO::FETCH_ASSOC);

// خريطة: لكل موظف، ما هي الفصول (class_id) التي يصل إليها — لتمكين الفلترة
// حسب المرحلة/الصف/الفصل. نقرأها مرة واحدة (batch) بدل استعلام لكل صف.
$staffClassIds = []; // [user_id => [class_id, ...]]
$accessSql = "SELECT uca.user_id, uca.class_id FROM user_class_access uca JOIN classes c ON c.id = uca.class_id
    WHERE c.academic_year_id = ? AND {$classScopeSql}";
$accessStmt = $db->prepare($accessSql);
$accessStmt->execute([$currentAcademicYearId]);
while ($r = $accessStmt->fetch(PDO::FETCH_ASSOC)) {
    $staffClassIds[$r['user_id']][] = (int)$r['class_id'];
}
$specSql = "SELECT sac.specialist_id AS user_id, sac.class_id
    FROM specialist_active_classes sac JOIN classes c ON c.id = sac.class_id
    WHERE c.academic_year_id = ? AND {$classScopeSql}";
$specStmt = $db->prepare($specSql);
$specStmt->execute([$currentAcademicYearId]);
while ($r = $specStmt->fetch(PDO::FETCH_ASSOC)) {
    $staffClassIds[$r['user_id']][] = (int)$r['class_id'];
}

// خريطة: class_id => [stage_id, grade_id] (للربط مع المراحل/الصفوف)
$classMap = []; // [class_id => ['grade_id'=>x]]
foreach ($classes as $c) {
    $classMap[(int)$c['id']] = ['grade_id' => (int)$c['grade_id']];
}
$gradeMap = []; // [grade_id => stage_id]
foreach ($grades as $g) {
    $gradeMap[(int)$g['id']] = (int)$g['stage_id'];
}

// أنواع التقييمات للاستخدام في مودال التعديل
$evalTypeObj = new EvaluationType($db);
$evaluationTypes = $evalTypeObj->readAll(true)->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات Stat Cards (مجموع على الصفوف)
$totalStaff     = count($staffRows);
$totalEvals     = 0;
$totalPositive  = 0;
$totalNegative  = 0;
foreach ($staffRows as $r) {
    $totalEvals    += (int)($r['eval_count'] ?? 0);
    $totalPositive += (int)($r['positive_count'] ?? 0);
    $totalNegative += (int)($r['negative_count'] ?? 0);
}

require_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>تقييمات المعلمين</h1>
        <small class="text-muted">متابعة وإدارة التقييمات التي يمنحها كل عامل للطلاب</small>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="evaluation_analytics.php" class="btn btn-outline-primary shadow-sm px-3 py-2">
            <i class="fas fa-chart-pie me-2"></i>الإحصائيات
        </a>
        <a href="evaluation_reports.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-file-alt me-2"></i>التقارير التفصيلية
        </a>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-users-cog"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$totalStaff; ?>">0</div>
                <div class="stat-card-label">إجمالي العاملين</div>
                <div class="stat-card-sub"><i class="fas fa-user-tie"></i> معلمون وأخصائيون</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-star"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$totalEvals; ?>">0</div>
                <div class="stat-card-label">إجمالي التقييمات</div>
                <div class="stat-card-sub"><i class="fas fa-clipboard-list"></i> جميع التقييمات</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-thumbs-up"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$totalPositive; ?>">0</div>
                <div class="stat-card-label">تقييمات إيجابية</div>
                <div class="stat-card-sub"><i class="fas fa-plus-circle"></i> نقاط مكتسبة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-thumbs-down"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$totalNegative; ?>">0</div>
                <div class="stat-card-label">تقييمات سلبية</div>
                <div class="stat-card-sub"><i class="fas fa-minus-circle"></i> خصومات</div>
            </div>
        </div>
    </div>
</div>

<!-- Table Card with Inline Dynamic (cascading) Filters -->
    <div class="admin-filter-bar">
        <div class="admin-filter-controls">
            <!-- فلتر المرحلة -->
            <select class="form-select form-select-sm" id="stageFilter" style="width:auto; min-width:130px;">
                <option value="">كل المراحل</option>
                <?php foreach ($stages as $st): ?>
                    <option value="<?php echo (int)$st['id']; ?>"><?php echo htmlspecialchars($st['stage_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <!-- فلتر الصف (cascading: data-stage) -->
            <select class="form-select form-select-sm" id="gradeFilter" style="width:auto; min-width:130px;">
                <option value="">كل الصفوف</option>
                <?php foreach ($grades as $gd): ?>
                    <option value="<?php echo (int)$gd['id']; ?>" data-stage="<?php echo (int)$gd['stage_id']; ?>"><?php echo htmlspecialchars($gd['grade_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <!-- فلتر الفصل (cascading: data-grade) -->
            <select class="form-select form-select-sm" id="classFilter" style="width:auto; min-width:130px;">
                <option value="">كل الفصول</option>
                <?php foreach ($classes as $cl): ?>
                    <option value="<?php echo (int)$cl['id']; ?>" data-grade="<?php echo (int)$cl['grade_id']; ?>"><?php echo htmlspecialchars($cl['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <!-- فلتر الدور -->
            <select class="form-select form-select-sm" id="roleFilter" style="width:auto; min-width:120px;">
                <option value="">كل الأدوار</option>
                <option value="teacher">معلم</option>
                <option value="specialist">أخصائي</option>
            </select>
        </div>
        <div class="admin-filter-actions">
            <!-- إعادة تعيين -->
            <button type="button" class="btn btn-light btn-sm" id="resetFilters" title="إعادة تعيين الفلاتر">
                <i class="fas fa-undo me-1"></i>إعادة تعيين
            </button>
            <!-- إعدادات الجدول -->
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
                <i class="fas fa-cog me-1"></i>إعدادات الجدول
            </button>
        </div>
    </div>

    <div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table" id="teacherEvalsTable">
                <thead>
                    <tr>
                        <th width="50">#</th>
                        <th class="col-code">كود الموظف</th>
                        <th>الاسم</th>
                        <th class="col-role">الدور</th>
                        <th class="col-job">المسمى الوظيفي</th>
                        <th class="col-classes d-none">الفصول</th>
                        <th class="col-mobile d-none">الموبايل</th>
                        <th class="col-eval-count">عدد التقييمات</th>
                        <th class="col-breakdown">إيجابي / سلبي</th>
                        <th class="col-status">الحالة</th>
                        <th width="120">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $counter = 1;
                    foreach ($staffRows as $row):
                        $uid = (int)$row['id'];
                        // حساب الفصول/المراحل/الصفوف التي يصل إليها هذا الموظف لإسناد data-* للفلترة
                        $sIds = []; $gIds = []; $cIds = [];
                        foreach ($staffClassIds[$uid] ?? [] as $cid) {
                            $cIds[$cid] = true;
                            $gid = $classMap[$cid]['grade_id'] ?? null;
                            if ($gid) {
                                $gIds[$gid] = true;
                                $sid = $gradeMap[$gid] ?? null;
                                if ($sid) { $sIds[$sid] = true; }
                            }
                        }
                        $dataStages = htmlspecialchars(implode(',', array_keys($sIds)), ENT_QUOTES, 'UTF-8');
                        $dataGrades = htmlspecialchars(implode(',', array_keys($gIds)), ENT_QUOTES, 'UTF-8');
                        $dataClasses = htmlspecialchars(implode(',', array_keys($cIds)), ENT_QUOTES, 'UTF-8');

                        $roleLabel = ['teacher' => 'معلم', 'specialist' => 'أخصائي'][$row['role']] ?? htmlspecialchars($row['role']);
                        // معلم بصلاحية مشرف (is_supervisor) → تمييز إضافي
                        $isSupervisorTeacher = ($row['role'] === 'teacher' && !empty($row['is_supervisor']));
                        $evalCount = (int)($row['eval_count'] ?? 0);
                        $posCount  = (int)($row['positive_count'] ?? 0);
                        $negCount  = (int)($row['negative_count'] ?? 0);
                        $isActive  = ($row['status'] ?? '') === 'active';
                    ?>
                        <tr data-stage="<?php echo $dataStages; ?>"
                            data-grade="<?php echo $dataGrades; ?>"
                            data-class="<?php echo $dataClasses; ?>"
                            data-role="<?php echo htmlspecialchars($row['role']); ?>"
                            data-eval-count="<?php echo $evalCount; ?>">
                            <td><?php echo $counter++; ?></td>
                            <td class="col-code"><small class="text-muted" dir="ltr"><?php echo htmlspecialchars($row['employee_code'] ?? '-'); ?></small></td>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td class="col-role">
                                <span class="badge bg-<?php echo $row['role'] === 'teacher' ? 'info' : ($row['role'] === 'specialist' ? 'success' : 'secondary'); ?> text-<?php echo $row['role'] === 'teacher' ? 'dark' : 'white'; ?>"><?php echo $roleLabel; ?></span>
                                <?php if ($isSupervisorTeacher): ?>
                                    <span class="badge bg-warning text-dark" title="معلم بصلاحية إشراف"><i class="fas fa-user-shield"></i> مشرف</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-job"><?php echo htmlspecialchars($row['job_title'] ?? '-'); ?></td>
                            <td class="col-classes d-none"><small><?php echo htmlspecialchars($row['class_names'] ?? '-'); ?></small></td>
                            <td class="col-mobile d-none"><small dir="ltr"><?php echo htmlspecialchars($row['phone_mobile'] ?? '-'); ?></small></td>
                            <td class="col-eval-count">
                                <?php if ($evalCount > 0): ?>
                                    <span class="badge bg-primary"><?php echo $evalCount; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-breakdown">
                                <?php if ($evalCount > 0): ?>
                                    <span class="badge bg-success me-1"><i class="fas fa-thumbs-up"></i> <?php echo $posCount; ?></span>
                                    <span class="badge bg-danger"><i class="fas fa-thumbs-down"></i> <?php echo $negCount; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-status">
                                <?php if ($isActive): ?>
                                    <span class="badge bg-success">نشط</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">معطّل</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center actions-column admin-table-actions">
                                <button type="button"
                                        class="btn btn-action-pills btn-edit manage-teacher-evals has-tooltip"
                                        data-teacher-id="<?php echo $uid; ?>"
                                        data-teacher-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#teacherEvalsModal"
                                        title="إدارة التقييمات">
                                    <i class="fas fa-list"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- ─────────────────────────────────────────────────────────────────────── -->
<!-- مودال إدارة تقييمات المعلم                                                -->
<!-- ─────────────────────────────────────────────────────────────────────── -->
<div class="modal fade" id="teacherEvalsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-clipboard-list me-2"></i>تقييمات: <span id="modalTeacherName">—</span>
                </h5>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-light me-2" id="exportTeacherEvalsBtn" title="تصدير تقييمات هذا المعلم إلى CSV">
                        <i class="fas fa-file-csv me-1"></i>تصدير CSV
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="text-muted">عدد التقييمات:</span>
                        <span class="badge bg-primary ms-1" id="modalEvalCount">0</span>
                    </div>
                    <div class="text-muted small">مرتبة من الأحدث إلى الأقدم</div>
                </div>
                <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                    <table class="table table-hover table-striped align-middle" id="modalTeacherEvalsTable">
                        <thead class="sticky-top">
                            <tr>
                                <th>الطالب</th>
                                <th>الفصل</th>
                                <th>التقييم</th>
                                <th>النوع</th>
                                <th>النقاط</th>
                                <th>التاريخ</th>
                                <th width="110" class="text-center actions-column">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody id="modalTeacherEvalsBody">
                            <tr><td colspan="7" class="text-center text-muted py-4">
                                <div class="spinner-border spinner-border-sm text-primary me-2"></div>جارٍ التحميل...
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────────── -->
<!-- مودال تعديل التقييم                                                       -->
<!-- ─────────────────────────────────────────────────────────────────────── -->
<div class="modal fade" id="editEvaluationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form id="editEvaluationForm">
                <input type="hidden" id="editEvalId" name="evaluation_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل التقييم</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">نوع التقييم <span class="text-danger">*</span></label>
                        <select class="form-select" id="editEvalType" name="evaluation_type_id" required>
                            <option value="">— اختر النوع —</option>
                            <?php foreach ($evaluationTypes as $et): ?>
                                <option value="<?php echo (int)$et['id']; ?>" data-type="<?php echo htmlspecialchars($et['type']); ?>">
                                    <?php echo htmlspecialchars($et['name']); ?>
                                    (<?php echo $et['type'] === 'positive' ? '+' . (int)$et['points'] : '-' . (int)$et['points']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">اختر نوع التقييم من القائمة.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">نقاط مخصصة (اختياري)</label>
                        <input type="number" class="form-control" id="editCustomPoints" name="custom_points"
                               placeholder="اتركه فارغاً لاستخدام نقاط النوع"
                               inputmode="numeric">
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            إذا حدّدت قيمة هنا ستُستخدم بدلاً من نقاط النوع. استخدم إشارة سالبة (مثل ‎-5‎) لخصم نقاط.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">السبب / ملاحظة</label>
                        <textarea class="form-control" id="editReason" name="reason" rows="2"
                                  placeholder="سبب اختياري للتقييم"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────────── -->
<!-- مودال تأكيد الحذف                                                         -->
<!-- ─────────────────────────────────────────────────────────────────────── -->
<div class="modal fade" id="deleteEvalConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>حذف التقييم</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من حذف هذا التقييم؟</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    سيتم إعادة حساب مجموع نقاط الطالب تلقائياً. هذا الإجراء لا يمكن التراجع عنه.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteEvalBtn">
                    <i class="fas fa-trash-alt me-1"></i>تأكيد الحذف
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────────── -->
<!-- مودال إعدادات أعمدة الجدول                                                -->
<!-- ─────────────────────────────────────────────────────────────────────── -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">اختر الأعمدة التي ترغب في عرضها في الجدول:</p>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_code" data-column="col-code" checked>
                            <label class="form-check-label" for="chk_code">كود الموظف</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_role" data-column="col-role" checked>
                            <label class="form-check-label" for="chk_role">الدور</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_job" data-column="col-job" checked>
                            <label class="form-check-label" for="chk_job">المسمى الوظيفي</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_classes" data-column="col-classes">
                            <label class="form-check-label" for="chk_classes">الفصول</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_mobile" data-column="col-mobile">
                            <label class="form-check-label" for="chk_mobile">الموبايل</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_breakdown" data-column="col-breakdown" checked>
                            <label class="form-check-label" for="chk_breakdown">إيجابي / سلبي</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_status" data-column="col-status" checked>
                            <label class="form-check-label" for="chk_status">الحالة</label>
                        </div>
                    </div>
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

<!-- ─────────────────────────────────────────────────────────────────────── -->
<!-- Toast Container                                                           -->
<!-- ─────────────────────────────────────────────────────────────────────── -->
<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 1090;" id="toastContainer"></div>

<script src="../assets/js/admin_table_actions.js"></script>
<script>
(function () {
    'use strict';

    var ajaxUrl = '../includes/ajax_handlers.php';
    var currentTeacherId = null;
    var pendingDeleteEvalId = null;
    var cachedTeacherData = [];

    // ───────────────────────────────────────────────────────────────────
    // 1) تهيئة DataTable على الجدول الرئيسي
    // ───────────────────────────────────────────────────────────────────
    var teacherEvalsTable = null;
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        teacherEvalsTable = $('#teacherEvalsTable').DataTable({
            pageLength: 50,
            lengthMenu: [[10, 25, 50, 100, 200, 500, -1], [10, 25, 50, 100, 200, 500, 'الكل']],
            order: [[0, 'asc']],
            responsive: true,
            dom: '<"row dt-toolbar-top"<"col-sm-6"l><"col-sm-6"f>>' +
                 '<"row dt-table-row"<"col-sm-12"tr>>' +
                 '<"dt-footer-bar"ip>',
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json',
                search: "البحث:",
                lengthMenu: "عرض _MENU_ سجل",
                info: "عرض _START_ إلى _END_ من _TOTAL_ سجل",
                paginate: { first: "الأول", last: "الأخير", next: "التالي", previous: "السابق" }
            }
        });
    }

    // ───────────────────────────────────────────────────────────────────
    // 2) الفلاتر الديناميكية (Cascading) — على جانب العميل
    // ───────────────────────────────────────────────────────────────────
    var stageFilter  = document.getElementById('stageFilter');
    var gradeFilter  = document.getElementById('gradeFilter');
    var classFilter  = document.getElementById('classFilter');
    var roleFilter   = document.getElementById('roleFilter');
    var resetBtn     = document.getElementById('resetFilters');
    var visibleBadge = document.getElementById('staffVisibleCount');

    // تطبيق الفلترة المتسلسلة على القوائم المنسدلة
    function applyCascadingDropdowns() {
        var stageId = stageFilter.value;
        var gradeId = gradeFilter.value;

        // إظهار/إخفاء خيارات الصفوف حسب المرحلة
        gradeFilter.querySelectorAll('option[data-stage]').forEach(function (opt) {
            opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
        });
        // إعادة ضبط الصف إذا لم يعد ضمن المرحلة
        if (gradeId && stageId) {
            var activeGrade = gradeFilter.querySelector('option[value="' + gradeId + '"]');
            if (!activeGrade || activeGrade.getAttribute('data-stage') !== stageId) {
                gradeFilter.value = '';
                gradeId = '';
            }
        }

        // إظهار/إخفاء خيارات الفصول حسب الصف
        classFilter.querySelectorAll('option[data-grade]').forEach(function (opt) {
            opt.style.display = (!gradeId || opt.getAttribute('data-grade') === gradeId) ? '' : 'none';
        });
        // إعادة ضبط الفصل إذا لم يعد ضمن الصف
        var classId = classFilter.value;
        if (classId && gradeId) {
            var activeClass = classFilter.querySelector('option[value="' + classId + '"]');
            if (!activeClass || activeClass.getAttribute('data-grade') !== gradeId) {
                classFilter.value = '';
            }
        }
    }

    // فلترة صفوف الجدول بناءً على قيم data-stage/data-grade/data-class/data-role
    function applyRowFilters() {
        var stageId = stageFilter.value;
        var gradeId = gradeFilter.value;
        var classId = classFilter.value;
        var roleId  = roleFilter.value;
        var visible = 0;

        $('#teacherEvalsTable tbody tr').each(function () {
            var row = $(this);
            var rStages = (row.attr('data-stage') || '').split(',').filter(Boolean);
            var rGrades = (row.attr('data-grade') || '').split(',').filter(Boolean);
            var rClasses = (row.attr('data-class') || '').split(',').filter(Boolean);
            var rRole = row.attr('data-role') || '';

            var match = true;
            if (stageId && rStages.indexOf(stageId) === -1) match = false;
            if (match && gradeId && rGrades.indexOf(gradeId) === -1) match = false;
            if (match && classId && rClasses.indexOf(classId) === -1) match = false;
            if (match && roleId && rRole !== roleId) match = false;

            if (match) { row.show(); visible++; } else { row.hide(); }
        });

        if (visibleBadge) visibleBadge.textContent = visible;
        if (teacherEvalsTable) {
            // أعد رسم DataTables لتعكس الصفوف المخفية (اختياري)
            try { teacherEvalsTable.draw(false); } catch (e) {}
        }
    }

    stageFilter.addEventListener('change', function () {
        applyCascadingDropdowns();
        applyRowFilters();
    });
    gradeFilter.addEventListener('change', function () {
        applyCascadingDropdowns();
        applyRowFilters();
    });
    classFilter.addEventListener('change', applyRowFilters);
    roleFilter.addEventListener('change', applyRowFilters);

    resetBtn.addEventListener('click', function () {
        stageFilter.value = '';
        gradeFilter.value = '';
        classFilter.value = '';
        roleFilter.value = '';
        applyCascadingDropdowns();
        applyRowFilters();
    });

    // ───────────────────────────────────────────────────────────────────
    // 3) إعدادات أعمدة الجدول (تطبيق فوري عبر class + localStorage)
    // ───────────────────────────────────────────────────────────────────
    function applyColumnVisibility(colClass, isVisible) {
        document.querySelectorAll('.' + colClass).forEach(function (el) {
            if (isVisible) { el.classList.remove('d-none'); }
            else { el.classList.add('d-none'); }
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        var checkboxes = document.querySelectorAll('#tableSettingsModal .col-toggle-checkbox');
        var storageKey = 'teacher_evals_table_columns';
        var prefs = {};
        try { prefs = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (e) { prefs = {}; }
        checkboxes.forEach(function (cb) {
            var colClass = cb.getAttribute('data-column');
            if (!colClass) return;
            var isVisible = prefs.hasOwnProperty(colClass) ? prefs[colClass] : cb.checked;
            cb.checked = isVisible;
            applyColumnVisibility(colClass, isVisible);
            cb.addEventListener('change', function () {
                applyColumnVisibility(colClass, this.checked);
                prefs[colClass] = this.checked;
                localStorage.setItem(storageKey, JSON.stringify(prefs));
            });
        });

        // Initialize tooltips
        document.querySelectorAll('.has-tooltip').forEach(el => { new bootstrap.Tooltip(el); });
    });

    // ───────────────────────────────────────────────────────────────────
    // 4) Toast Helper
    // ───────────────────────────────────────────────────────────────────
    function showToast(message, type) {
        type = type || 'success';
        var bg = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : 'bg-primary');
        var icon = type === 'success' ? 'fa-check-circle' : (type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle');
        var html = '<div class="toast align-items-center text-white ' + bg + ' border-0" role="alert">' +
                   '<div class="d-flex"><div class="toast-body"><i class="fas ' + icon + ' me-2"></i>' + escapeHtml(message) + '</div>' +
                   '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>';
        var container = document.getElementById('toastContainer');
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        var toastEl = wrapper.firstChild;
        container.appendChild(toastEl);
        var toast = new bootstrap.Toast(toastEl, { delay: 3500 });
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', function () { toastEl.remove(); });
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ───────────────────────────────────────────────────────────────────
    // 5) مودال إدارة تقييمات المعلم: تحميل + عرض + حذف + تعديل
    // ───────────────────────────────────────────────────────────────────
    var teacherEvalsModalEl = document.getElementById('teacherEvalsModal');
    teacherEvalsModalEl.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        currentTeacherId = btn.getAttribute('data-teacher-id');
        var teacherName = btn.getAttribute('data-teacher-name');
        document.getElementById('modalTeacherName').textContent = teacherName;
        loadTeacherEvaluations(currentTeacherId);
    });

    function loadTeacherEvaluations(teacherId) {
        var body = document.getElementById('modalTeacherEvalsBody');
        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm text-primary me-2"></div>جارٍ التحميل...</td></tr>';
        document.getElementById('modalEvalCount').textContent = '0';

        $.post(ajaxUrl, { action: 'get_teacher_evaluations_for_admin', teacher_id: teacherId }, function (resp) {
            if (resp && resp.success && Array.isArray(resp.data)) {
                cachedTeacherData = resp.data;
                renderTeacherEvals(resp.data);
            } else {
                body.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4"><i class="fas fa-exclamation-circle me-2"></i>' + escapeHtml((resp && resp.message) || 'فشل تحميل التقييمات') + '</td></tr>';
                cachedTeacherData = [];
            }
        }, 'json').fail(function () {
            body.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4"><i class="fas fa-exclamation-circle me-2"></i>خطأ في الاتصال بالخادم</td></tr>';
            cachedTeacherData = [];
        });
    }

    function renderTeacherEvals(rows) {
        var body = document.getElementById('modalTeacherEvalsBody');
        document.getElementById('modalEvalCount').textContent = rows.length;
        if (rows.length === 0) {
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>لا توجد تقييمات لهذا المعلم</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            var isPositive = r.display_type === 'positive';
            var typeBadge = isPositive
                ? '<span class="badge bg-success-subtle text-success">إيجابي</span>'
                : '<span class="badge bg-danger-subtle text-danger">سلبي</span>';
            var pointsBadge = isPositive
                ? '<span class="badge bg-success-subtle text-success">+' + r.display_points + '</span>'
                : '<span class="badge bg-danger-subtle text-danger">-' + r.display_points + '</span>';
            var evalCell = escapeHtml(r.type_name);
            if (r.reason) { evalCell += '<br><small class="text-muted">السبب: ' + escapeHtml(r.reason) + '</small>'; }

            html += '<tr data-eval-id="' + r.id + '">' +
                '<td class="fw-bold">' + escapeHtml(r.student_name) + '</td>' +
                '<td><small>' + escapeHtml(r.class_name) + '</small></td>' +
                '<td>' + evalCell + '</td>' +
                '<td>' + typeBadge + '</td>' +
                '<td>' + pointsBadge + '</td>' +
                '<td><small dir="ltr">' + escapeHtml(r.date_created) + '</small></td>' +
                '<td class="text-center actions-column admin-table-actions">' +
                    '<button type="button" class="btn btn-action-pills btn-edit edit-eval-btn me-1" data-eval-id="' + r.id + '" data-bs-toggle="tooltip" title="تعديل"><i class="fas fa-edit"></i></button>' +
                    '<button type="button" class="btn btn-action-pills btn-delete delete-eval-btn" data-eval-id="' + r.id + '" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>' +
                '</td>' +
            '</tr>';
        });
        body.innerHTML = html;
        body.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
            new bootstrap.Tooltip(el);
        });
    }

    // تفويض أحداث الحذف والتعديل داخل المودال
    document.getElementById('modalTeacherEvalsBody').addEventListener('click', function (e) {
        var delBtn = e.target.closest('.delete-eval-btn');
        var editBtn = e.target.closest('.edit-eval-btn');
        if (delBtn) {
            pendingDeleteEvalId = delBtn.getAttribute('data-eval-id');
            new bootstrap.Modal(document.getElementById('deleteEvalConfirmModal')).show();
        } else if (editBtn) {
            openEditModal(editBtn.getAttribute('data-eval-id'));
        }
    });

    // ───────────────────────────────────────────────────────────────────
    // 6) الحذف (إعادة استخدام delete_evaluation الموجود)
    // ───────────────────────────────────────────────────────────────────
    document.getElementById('confirmDeleteEvalBtn').addEventListener('click', function () {
        if (!pendingDeleteEvalId) return;
        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جارٍ الحذف...';
        $.post(ajaxUrl, { action: 'delete_evaluation', evaluation_id: pendingDeleteEvalId }, function (resp) {
            if (resp && resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('deleteEvalConfirmModal')).hide();
                showToast(resp.message || 'تم حذف التقييم بنجاح', 'success');
                // إزالة الصف من المودال ديناميكياً + تحديث العداد
                var row = document.querySelector('#modalTeacherEvalsBody tr[data-eval-id="' + pendingDeleteEvalId + '"]');
                if (row) {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(function () { row.remove(); }, 300);
                }
                var count = parseInt(document.getElementById('modalEvalCount').textContent, 10) || 0;
                document.getElementById('modalEvalCount').textContent = Math.max(0, count - 1);
                // تحديث العداد الرئيسي في الجدول (إذا أمكن)
                if (currentTeacherId) {
                    var mainRow = document.querySelector('#teacherEvalsTable tr[data-eval-count] ');
                    // نعيد تحميل الجدول الرئيسي بعد قليل لتعكس العدّ الجديد
                    setTimeout(function () {
                        var mainBtn = document.querySelector('.manage-teacher-evals[data-teacher-id="' + currentTeacherId + '"]');
                        if (mainBtn) {
                            var mainTr = mainBtn.closest('tr');
                            var badge = mainTr.querySelector('.col-eval-count .badge');
                            if (badge) {
                                var n = parseInt(badge.textContent, 10) || 0;
                                badge.textContent = Math.max(0, n - 1);
                            }
                        }
                    }, 400);
                }
            } else {
                showToast((resp && resp.message) || 'فشل في حذف التقييم', 'danger');
            }
        }, 'json').fail(function () {
            showToast('خطأ في الاتصال بالخادم', 'danger');
        }).always(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt me-1"></i>تأكيد الحذف';
            pendingDeleteEvalId = null;
        });
    });

    // ───────────────────────────────────────────────────────────────────
    // 7) التعديل
    // ───────────────────────────────────────────────────────────────────
    function openEditModal(evalId) {
        var evalData = cachedTeacherData.find(function (r) { return String(r.id) === String(evalId); });
        if (!evalData) { showToast('تعذّر العثور على بيانات التقييم', 'danger'); return; }

        document.getElementById('editEvalId').value = evalId;
        document.getElementById('editEvalType').value = evalData.evaluation_type_id;
        document.getElementById('editCustomPoints').value = (evalData.custom_points !== null && evalData.custom_points !== undefined) ? evalData.custom_points : '';
        document.getElementById('editReason').value = evalData.reason || '';
        new bootstrap.Modal(document.getElementById('editEvaluationModal')).show();
    }

    document.getElementById('editEvaluationForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var evalId = document.getElementById('editEvalId').value;
        var typeId = document.getElementById('editEvalType').value;
        var customPoints = document.getElementById('editCustomPoints').value;
        var reason = document.getElementById('editReason').value;
        var submitBtn = this.querySelector('button[type="submit"]');

        if (!typeId) { showToast('يرجى اختيار نوع التقييم', 'danger'); return; }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جارٍ الحفظ...';

        $.post(ajaxUrl, {
            action: 'update_teacher_evaluation',
            evaluation_id: evalId,
            evaluation_type_id: typeId,
            custom_points: customPoints,
            reason: reason
        }, function (resp) {
            if (resp && resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('editEvaluationModal')).hide();
                showToast(resp.message || 'تم تعديل التقييم بنجاح', 'success');
                // إعادة تحميل تقييمات المعلم لإظهار التغييرات
                if (currentTeacherId) loadTeacherEvaluations(currentTeacherId);
            } else {
                showToast((resp && resp.message) || 'فشل في تعديل التقييم', 'danger');
            }
        }, 'json').fail(function () {
            showToast('خطأ في الاتصال بالخادم', 'danger');
        }).always(function () {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save me-1"></i>حفظ التعديلات';
        });
    });

    // ───────────────────────────────────────────────────────────────────
    // 8) تصدير CSV لتقييمات المعلم الحالي (client-side)
    // ───────────────────────────────────────────────────────────────────
    document.getElementById('exportTeacherEvalsBtn').addEventListener('click', function () {
        if (!cachedTeacherData.length) {
            showToast('لا توجد تقييمات للتصدير', 'danger');
            return;
        }
        var teacherName = document.getElementById('modalTeacherName').textContent.trim() || 'teacher';
        var rows = [['#', 'الطالب', 'الفصل', 'التقييم', 'النوع', 'النقاط', 'السبب', 'التاريخ']];
        cachedTeacherData.forEach(function (r, i) {
            rows.push([
                i + 1,
                r.student_name,
                r.class_name,
                r.type_name,
                r.display_type === 'positive' ? 'إيجابي' : 'سلبي',
                (r.display_type === 'positive' ? '+' : '-') + r.display_points,
                (r.reason || '').replace(/[\r\n]+/g, ' '),
                r.date_created
            ]);
        });
        // BOM لدعم العربية في Excel
        var csvContent = '\uFEFF' + rows.map(function (row) {
            return row.map(function (cell) {
                var s = String(cell == null ? '' : cell);
                if (s.indexOf(',') !== -1 || s.indexOf('"') !== -1) {
                    s = '"' + s.replace(/"/g, '""') + '"';
                }
                return s;
            }).join(',');
        }).join('\r\n');

        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        var url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'teacher_evaluations_' + currentTeacherId + '_' + teacherName + '.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        showToast('تم تصدير ' + cachedTeacherData.length + ' تقييم', 'success');
    });

    // ───────────────────────────────────────────────────────────────────
    // 9) تطبيق الفلاتر المتسلسلة عند تحميل الصفحة
    // ───────────────────────────────────────────────────────────────────
    applyCascadingDropdowns();
})();
</script>

<?php require_once '../includes/admin_footer.php'; ?>
