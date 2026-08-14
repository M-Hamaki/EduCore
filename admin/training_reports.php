<?php
/**
 * تقارير التدريب - Admin Training Reports
 */
$page_title = "تقارير التدريب والتطوير";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/Training.php';

// Auth validation before any processing
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

$training = new Training($db);

// AJAX Action Handler for viewing teacher certificates
if (isset($_GET['action']) && $_GET['action'] === 'get_teacher_certificates') {
    header('Content-Type: application/json; charset=utf-8');
    $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
    $certs = $training->getTeacherCertificates($teacherId);
    echo json_encode(['success' => true, 'certificates' => $certs], JSON_UNESCAPED_UNICODE);
    exit;
}

$stats = $training->getAdminStats();
$teacherEnrollments = $training->getEnrollmentsByTeacher();

// Course-level stats
$courseStats = $db->query("SELECT c.id, c.title, p.name as program_name, p.color,
    COUNT(DISTINCT e.teacher_id) as enrolled,
    SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) as completed,
    COALESCE(AVG(e.progress_percent), 0) as avg_progress,
    COALESCE(AVG(CASE WHEN e.score IS NOT NULL THEN e.score END), 0) as avg_score
    FROM training_courses c
    JOIN training_programs p ON c.program_id = p.id
    LEFT JOIN training_enrollments e ON c.id = e.course_id
    WHERE c.is_active = 1
    GROUP BY c.id, c.title, p.name, p.color
    ORDER BY enrolled DESC")->fetchAll(PDO::FETCH_ASSOC);

// Stages list for filtering
$stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC);

// Grades list for filtering
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY grade_order, id")->fetchAll(PDO::FETCH_ASSOC);

// Programs list for filtering
$programs = $db->query("SELECT id, name FROM training_programs WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Teacher class/stage/grade access mappings
$teacherAccess = [];
$accessRows = $db->query("SELECT uca.user_id, s.id as stage_id, s.stage_name, g.id as grade_id, g.grade_name
    FROM user_class_access uca
    JOIN classes c ON uca.class_id = c.id
    JOIN grades g ON c.grade_id = g.id
    JOIN stages s ON g.stage_id = s.id
    WHERE s.status = 'active' AND g.status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

foreach ($accessRows as $row) {
    $uid = (int)$row['user_id'];
    if (!isset($teacherAccess[$uid])) {
        $teacherAccess[$uid] = ['stage_ids' => [], 'stage_names' => [], 'grade_ids' => [], 'grade_names' => []];
    }
    if (!in_array((string)$row['stage_id'], $teacherAccess[$uid]['stage_ids'], true)) {
        $teacherAccess[$uid]['stage_ids'][] = (string)$row['stage_id'];
        $teacherAccess[$uid]['stage_names'][] = $row['stage_name'];
    }
    if (!in_array((string)$row['grade_id'], $teacherAccess[$uid]['grade_ids'], true)) {
        $teacherAccess[$uid]['grade_ids'][] = (string)$row['grade_id'];
        $teacherAccess[$uid]['grade_names'][] = $row['grade_name'];
    }
}

include_once '../includes/admin_header.php';
?>

<div id="trainingReportsPage" class="training-reports-page">
    <!-- Page Header -->
    <div class="admin-page-heading mb-4">
        <div>
            <h1 class="h2"><i class="fas fa-chart-bar me-2 text-primary"></i>تقارير التدريب والتطوير المهني</h1>
        </div>
        <div class="admin-top-actions no-print">
            <a href="training_programs.php" class="btn btn-header-premium btn-print-soft" data-bs-toggle="tooltip" title="العودة للبرامج التدريبية">
                <i class="fas fa-arrow-right me-1"></i>العودة للبرامج
            </a>
        </div>
    </div>

    <!-- Overview Stats -->
    <div class="dashboard-canvas sortable-dashboard">
        <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4 sortable-dashboard" id="widget-training-stats" aria-label="كروت إحصائيات تقارير التدريب">
            <div class="col" id="stat-active-teachers">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-card-badge">معلمين نشطين</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo (int)$stats['active_teachers']; ?>">0</div>
                        <div class="stat-card-label">معلم مشارك</div>
                    </div>
                </div>
            </div>
            <div class="col" id="stat-completed-enrollments">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-card-badge">دورات مكتملة</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo (int)$stats['completed_enrollments']; ?>">0</div>
                        <div class="stat-card-label">إكمال دورة</div>
                    </div>
                </div>
            </div>
            <div class="col" id="stat-in-progress">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                    <div class="stat-card-icon"><i class="fas fa-spinner"></i></div>
                    <div class="stat-card-badge">دورات جارية</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo (int)$stats['in_progress']; ?>">0</div>
                        <div class="stat-card-label">قيد التنفيذ</div>
                    </div>
                </div>
            </div>
            <div class="col" id="stat-average-score">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                    <div class="stat-card-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-card-badge">معدل الأداء</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number"><span class="counter" data-target="<?php echo (int)$stats['average_score']; ?>">0</span>%</div>
                        <div class="stat-card-label">متوسط الدرجات</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-3 border-bottom admin-tabs no-print" id="trainingReportsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold active" id="teachers-tab" data-bs-toggle="tab" data-bs-target="#teachers-tab-pane" type="button" role="tab" aria-controls="teachers-tab-pane" aria-selected="true">
                <i class="fas fa-users me-2 text-primary"></i>أداء المعلمين <span class="badge bg-primary ms-1"><?php echo count($teacherEnrollments); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold" id="courses-tab" data-bs-toggle="tab" data-bs-target="#courses-tab-pane" type="button" role="tab" aria-controls="courses-tab-pane" aria-selected="false">
                <i class="fas fa-book me-2 text-success"></i>إحصائيات الدورات <span class="badge bg-secondary ms-1"><?php echo count($courseStats); ?></span>
            </button>
        </li>
    </ul>

    <!-- Filter Bar (ui_preview.php standard) -->
    <form id="trainingReportsFilterForm" class="admin-filter-bar mb-4" novalidate>
        <div class="admin-filter-controls">
            <select id="filterStage" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة المرحلة">
                <option value="">كل المراحل</option>
                <?php foreach ($stages as $stg): ?>
                    <option value="<?php echo (int)$stg['id']; ?>"><?php echo htmlspecialchars($stg['stage_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterGrade" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة الصف">
                <option value="">كل الصفوف</option>
                <?php foreach ($grades as $grd): ?>
                    <option value="<?php echo (int)$grd['id']; ?>" data-stage-id="<?php echo (int)$grd['stage_id']; ?>"><?php echo htmlspecialchars($grd['grade_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterProgram" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة البرنامج التدريبي">
                <option value="">كل البرامج التدريبية</option>
                <?php foreach ($programs as $prog): ?>
                    <option value="<?php echo htmlspecialchars($prog['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($prog['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterStatus" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة حالة الإنجاز">
                <option value="">جميع حالات الإنجاز</option>
                <option value="completed">مكتمل بنسبة 100%</option>
                <option value="in_progress">قيد التقدم (أقل من 100%)</option>
                <option value="has_certificates">حاصل على شهادات</option>
            </select>
        </div>
        <div class="admin-filter-actions">
            <button type="button" class="btn btn-light btn-sm" id="btnResetFilters"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</button>
        </div>
    </form>

    <div class="tab-content" id="trainingReportsTabContent">
        <!-- Tab 1: أداء المعلمين -->
        <div class="tab-pane fade show active" id="teachers-tab-pane" role="tabpanel" aria-labelledby="teachers-tab" tabindex="0">
            <div class="admin-list-surface">
                <div class="p-3">
                    <?php if (empty($teacherEnrollments)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-info-circle fa-2x mb-2"></i>
                            <p>لا توجد بيانات تسجيل بعد</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive admin-table-wrap">
                            <table class="table table-hover table-striped admin-data-table" id="teachersTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>اسم المعلم</th>
                                        <th>المرحلة</th>
                                        <th>الصف</th>
                                        <th>إجمالي الدورات</th>
                                        <th>الدورات المكتملة</th>
                                        <th>متوسط التقدم</th>
                                        <th>الشهادات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    foreach ($teacherEnrollments as $index => $te): 
                                        $tId = (int)$te['id'];
                                        $stgIds = implode(',', $teacherAccess[$tId]['stage_ids'] ?? []);
                                        $grdIds = implode(',', $teacherAccess[$tId]['grade_ids'] ?? []);
                                        $stgNamesArr = $teacherAccess[$tId]['stage_names'] ?? [];
                                        $grdNamesArr = $teacherAccess[$tId]['grade_names'] ?? [];
                                        $stgFullStr = implode('، ', $stgNamesArr);
                                        $grdFullStr = implode('، ', $grdNamesArr);

                                        $stgCount = count($stgNamesArr);
                                         if ($stgCount === 0) {
                                             $stgDisplay = '<span class="text-muted">-</span>';
                                         } else {
                                             $firstName = $stgNamesArr[0];
                                             $stgLower = mb_strtolower($firstName);
                                             if (str_contains($stgLower, 'ابتدائي') || str_contains($stgLower, 'الابتدائية')) {
                                                 $stgClass = 'bg-soft-success text-success border-success-subtle';
                                                 $stgPlusBg = 'bg-success';
                                             } elseif (str_contains($stgLower, 'إعدادي') || str_contains($stgLower, 'متوسط') || str_contains($stgLower, 'الإعدادية') || str_contains($stgLower, 'المتوسطة')) {
                                                 $stgClass = 'bg-soft-primary text-primary border-primary-subtle';
                                                 $stgPlusBg = 'bg-primary';
                                             } elseif (str_contains($stgLower, 'ثانوي') || str_contains($stgLower, 'الثانوية')) {
                                                 $stgClass = 'bg-soft-purple text-purple border-purple-subtle';
                                                 $stgPlusBg = 'bg-purple';
                                             } elseif (str_contains($stgLower, 'روضة') || str_contains($stgLower, 'أطفال') || str_contains($stgLower, 'الروضة')) {
                                                 $stgClass = 'bg-soft-warning text-warning border-warning-subtle';
                                                 $stgPlusBg = 'bg-warning';
                                             } else {
                                                 $stgClass = 'bg-soft-info text-info border-info-subtle';
                                                 $stgPlusBg = 'bg-info';
                                             }

                                             if ($stgCount === 1) {
                                                 $stgDisplay = '<span class="badge ' . $stgClass . ' px-2.5 py-1.5 rounded-pill fw-bold">' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '</span>';
                                             } else {
                                                 $stgDisplay = '<span class="badge ' . $stgClass . ' px-2.5 py-1.5 rounded-pill fw-bold" data-bs-toggle="tooltip" title="' . htmlspecialchars($stgFullStr, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ' <span class="badge ' . $stgPlusBg . ' ms-1">+' . ($stgCount - 1) . '</span></span>';
                                             }
                                         }

                                         $grdCount = count($grdNamesArr);
                                         if ($grdCount === 0) {
                                             $grdDisplay = '<span class="text-muted">-</span>';
                                         } elseif ($grdCount === 1) {
                                             $grdDisplay = '<span class="badge bg-soft-secondary text-dark px-2.5 py-1.5 rounded-pill fw-bold">' . htmlspecialchars($grdNamesArr[0], ENT_QUOTES, 'UTF-8') . '</span>';
                                         } else {
                                             $grdDisplay = '<span class="badge bg-soft-secondary text-dark px-2.5 py-1.5 rounded-pill fw-bold" data-bs-toggle="tooltip" title="' . htmlspecialchars($grdFullStr, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($grdNamesArr[0], ENT_QUOTES, 'UTF-8') . ' <span class="badge bg-secondary ms-1">+' . ($grdCount - 1) . '</span></span>';
                                         }

                                         $certCount = (int)$te['certificates'];
                                         $avgProg = (int)round($te['avg_progress']);
                                         if ($avgProg >= 100) {
                                             $fillClass = 'fill-completed';
                                             $labelClass = 'text-completed';
                                         } elseif ($avgProg >= 50) {
                                             $fillClass = 'fill-high';
                                             $labelClass = 'text-high';
                                         } elseif ($avgProg > 0) {
                                             $fillClass = 'fill-mid';
                                             $labelClass = 'text-mid';
                                         } else {
                                             $fillClass = 'fill-empty';
                                             $labelClass = 'text-empty';
                                         }
                                    ?>
                                        <tr data-stage-ids="<?php echo htmlspecialchars($stgIds, ENT_QUOTES, 'UTF-8'); ?>" data-grade-ids="<?php echo htmlspecialchars($grdIds, ENT_QUOTES, 'UTF-8'); ?>">
                                            <td><?php echo $index + 1; ?></td>
                                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($te['teacher_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo $stgDisplay; ?></td>
                                            <td><?php echo $grdDisplay; ?></td>
                                            <td><span class="badge bg-soft-primary text-primary px-3 py-2 rounded-pill fw-bold"><?php echo (int)$te['total_courses']; ?></span></td>
                                            <td><span class="badge bg-soft-success text-success px-3 py-2 rounded-pill fw-bold"><?php echo (int)$te['completed']; ?></span></td>
                                            <td>
                                                 <div class="admin-progress-wrap" data-bs-toggle="tooltip" title="متوسط التقدم: <?php echo $avgProg; ?>%">
                                                     <div class="admin-progress-track">
                                                         <div class="admin-progress-fill <?php echo $fillClass; ?>" style="width: <?php echo $avgProg; ?>%;"></div>
                                                     </div>
                                                     <span class="admin-progress-label <?php echo $labelClass; ?>"><?php echo $avgProg; ?>%</span>
                                                 </div>
                                            </td>
                                            <td>
                                                <?php if ($certCount > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-warning text-dark border-warning fw-bold px-3 py-1 rounded-pill btn-view-certs" 
                                                            data-teacher-id="<?php echo $tId; ?>" 
                                                            data-teacher-name="<?php echo htmlspecialchars($te['teacher_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-bs-toggle="tooltip" title="عرض شهادات المعلم (<?php echo $certCount; ?>)">
                                                        <i class="fas fa-award text-warning me-1"></i><?php echo $certCount; ?> شهادة
                                                    </button>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted px-2 py-1">لا توجد</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Tab 2: إحصائيات الدورات -->
        <div class="tab-pane fade" id="courses-tab-pane" role="tabpanel" aria-labelledby="courses-tab" tabindex="0">
            <div class="admin-list-surface">
                <div class="p-3">
                    <?php if (empty($courseStats)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-info-circle fa-2x mb-2"></i>
                            <p>لا توجد دورات بعد</p>
                        </div>
                    <?php else: ?>
                        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                            <?php foreach ($courseStats as $cs): ?>
                                <div class="col course-card-col" data-program-name="<?php echo htmlspecialchars($cs['program_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="border rounded-3 p-3 bg-white shadow-sm h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <strong class="text-dark fs-6"><?php echo htmlspecialchars($cs['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <br><small class="text-muted"><i class="fas fa-folder me-1 text-primary"></i><?php echo htmlspecialchars($cs['program_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            </div>
                                            <span class="badge bg-soft-primary text-primary px-3 py-2 rounded-pill"><?php echo (int)$cs['enrolled']; ?> مسجل</span>
                                        </div>
                                        <div class="progress mb-2" style="height: 8px; border-radius: 6px;">
                                            <div class="progress-bar" style="width: <?php echo round($cs['avg_progress']); ?>%; background-color: <?php echo htmlspecialchars($cs['color'], ENT_QUOTES, 'UTF-8'); ?>; border-radius: 6px;"></div>
                                        </div>
                                        <div class="d-flex justify-content-between text-muted fw-bold small">
                                            <span><i class="fas fa-tasks me-1 text-info"></i>تقدم: <?php echo round($cs['avg_progress']); ?>%</span>
                                            <span><i class="fas fa-check-double me-1 text-success"></i>أكملوا: <?php echo (int)$cs['completed']; ?></span>
                                            <?php if ($cs['avg_score'] > 0): ?>
                                                <span><i class="fas fa-star me-1 text-warning"></i>متوسط: <?php echo round($cs['avg_score']); ?>%</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Viewing Teacher Certificates -->
<div class="modal fade" id="teacherCertificatesModal" tabindex="-1" aria-labelledby="teacherCertificatesModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium">
            <div class="modal-header">
                <h5 class="modal-title" id="teacherCertificatesModalTitle">
                    <i class="fas fa-award text-warning me-2"></i>شهادات المعلم: <span id="modalTeacherName" class="text-primary fw-bold"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-3" id="modalCertificatesContainer">
                <div class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                    <p class="mt-2 text-muted">جاري تحميل الشهادات...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    var table = null;
    if ($('#teachersTable').length && $('#teachersTable tbody tr').length > 0) {
        if (!$.fn.DataTable.isDataTable('#teachersTable')) {
            table = $('#teachersTable').DataTable({
                language: {
                    search: "البحث:",
                    lengthMenu: "عرض _MENU_ سجلات",
                    info: "عرض _START_ إلى _END_ من أصل _TOTAL_ معلم",
                    infoEmpty: "عرض 0 إلى 0 من أصل 0 معلم",
                    infoFiltered: "(منقح من _MAX_ سجل إجمالي)",
                    loadingRecords: "جاري التحميل...",
                    zeroRecords: "لم يتم العثور على أي سجلات مطابقة",
                    emptyTable: "لا توجد بيانات متاحة في الجدول",
                    paginate: {
                        first: "الأول",
                        previous: "السابق",
                        next: "التالي",
                        last: "الأخير"
                    }
                },
                pageLength: 50,
                order: [[5, 'desc']]
            });
        } else {
            table = $('#teachersTable').DataTable();
        }
    }

    // Custom filtering for DataTables
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (!settings || settings.nTable.id !== 'teachersTable') return true;

        var selectedStage = $('#filterStage').val();
        var selectedGrade = $('#filterGrade').val();
        var statusVal = $('#filterStatus').val();

        var rowNode = settings.aoData[dataIndex].nTr;
        var rowStageIds = ($(rowNode).attr('data-stage-ids') || '').split(',');
        var rowGradeIds = ($(rowNode).attr('data-grade-ids') || '').split(',');

        if (selectedStage && $.inArray(selectedStage, rowStageIds) === -1) {
            return false;
        }

        if (selectedGrade && $.inArray(selectedGrade, rowGradeIds) === -1) {
            return false;
        }

        var progressText = data[6] || '';
        var progressVal = parseInt(progressText.replace(/[^0-9]/g, '')) || 0;
        var certificatesText = data[7] || '';

        if (statusVal === 'completed' && progressVal < 100) return false;
        if (statusVal === 'in_progress' && progressVal >= 100) return false;
        // زر الشهادة الفعلي يستخدم fa-award (انظر السطر ~266)، لا fa-certificate.
        if (statusVal === 'has_certificates' && (certificatesText.trim() === '-' || certificatesText.indexOf('fa-award') === -1 || certificatesText.indexOf('لا توجد') !== -1)) return false;

        return true;
    });

    $('#filterStage').on('change', function() {
        var stageId = $(this).val();
        $('#filterGrade option').each(function() {
            var gStage = $(this).attr('data-stage-id');
            if (!stageId || !gStage || gStage === stageId) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
        if (stageId && $('#filterGrade option:selected').is(':hidden')) {
            $('#filterGrade').val('');
        }
        if (table) table.draw();
    });

    $('#filterGrade, #filterStatus').on('change', function() {
        if (table) table.draw();
    });

    $('#filterProgram').on('change', function() {
        var progVal = $(this).val();
        
        $('.course-card-col').each(function() {
            var progName = $(this).attr('data-program-name') || '';
            if (!progVal || progName === progVal) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    $('#btnResetFilters').on('click', function() {
        $('#filterStage').val('');
        $('#filterGrade').val('').find('option').show();
        $('#filterProgram').val('');
        $('#filterStatus').val('');
        $('.course-card-col').show();
        if (table) {
            table.search('').columns().search('').draw();
        }
    });

    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        if ($.fn.DataTable.isDataTable('#teachersTable')) {
            $('#teachersTable').DataTable().columns.adjust();
        }
    });

    // Handle AJAX Teacher Certificates Modal
    $(document).on('click', '.btn-view-certs', function() {
        var teacherId = $(this).data('teacher-id');
        var teacherName = $(this).data('teacher-name');

        $('#modalTeacherName').text(teacherName);
        $('#modalCertificatesContainer').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2 text-muted">جاري تحميل الشهادات...</p></div>');
        
        var modalEl = new bootstrap.Modal(document.getElementById('teacherCertificatesModal'));
        modalEl.show();

        $.ajax({
            url: 'training_reports.php?action=get_teacher_certificates&teacher_id=' + teacherId,
            type: 'GET',
            dataType: 'json',
            success: function(res) {
                if (res.success && res.certificates && res.certificates.length > 0) {
                    var html = '<div class="row row-cols-1 g-4">';
                    $.each(res.certificates, function(i, cert) {
                        var certNum = cert.certificate_number || '';
                        var dateStr = cert.issued_at ? cert.issued_at.substring(0, 10).replace(/-/g, '/') : '-';
                        var score = cert.score ? Math.round(cert.score) : null;
                        var cTitle = cert.course_title || '';

                        html += '<div class="col">';
                        html += '  <div class="cert-wrapper mb-3">';
                        html += '    <div class="cert-card mb-2" id="cert-card-' + cert.id + '">';
                        html += '      <div class="cert-inner">';
                        html += '        <div class="cert-corner tl"></div>';
                        html += '        <div class="cert-corner tr"></div>';
                        html += '        <div class="cert-corner bl"></div>';
                        html += '        <div class="cert-corner br"></div>';
                        html += '        <img src="../assets/images/logo.png" alt="شعار المدرسة" class="cert-logo">';
                        html += '        <div class="cert-title-row"><i class="fas fa-award"></i><div class="cert-title">شهادة إتمام وتأهيل</div><i class="fas fa-award"></i></div>';
                        html += '        <div class="cert-divider"></div>';
                        html += '        <div class="cert-school"><i class="fas fa-university me-2"></i>تشهد مدرسة الدلتا الحديثة للغات</div>';
                        html += '        <div class="cert-body-text">أن المعلم / المعلمة: <span class="cert-teacher-name">' + escapeHtml(teacherName) + '</span></div>';
                        html += '        <div class="cert-body-text">قد اجتاز بنجاح الدورة التدريبية</div>';
                        html += '        <div class="cert-course-title">« ' + escapeHtml(cTitle) + ' »</div>';
                        if (score !== null) {
                            html += '        <div class="text-center my-1"><span class="badge bg-soft-success text-success border border-success-subtle px-3 py-1 fw-bold">بنسبة نجاح ' + score + '%</span></div>';
                        }
                        html += '        <div class="cert-signatures">';
                        html += '          <div class="cert-sig"><div class="cert-sig-title">وحدة التدريب والجودة</div><div class="cert-sig-line"></div></div>';
                        html += '          <div class="cert-sig"><div class="cert-sig-title">مديرة المرحلة</div><div class="cert-sig-line"></div></div>';
                        html += '          <div class="cert-sig"><div class="cert-sig-title">مدير المدرسة</div><div class="cert-sig-line"></div></div>';
                        html += '        </div>';
                        html += '        <div class="cert-footer-info">';
                        html += '          <div class="cert-number"><i class="fas fa-shield-alt me-1" style="color: #0d6efd;"></i>رقم التحقق: ' + escapeHtml(certNum) + '</div>';
                        html += '          <div class="cert-date"><i class="fas fa-calendar me-1" style="color: #0d6efd;"></i>تاريخ الإصدار: ' + dateStr + '</div>';
                        html += '        </div>';
                        html += '      </div>';
                        html += '    </div>';
                        html += '    <div class="text-end mt-2 pt-2 border-top">';
                        html += '      <a href="../verify_certificate.php?cert=' + encodeURIComponent(certNum) + '" target="_blank" class="btn btn-sm btn-primary shadow-sm"><i class="fas fa-external-link-alt me-1"></i>معاينة الطباعة المعتمدة</a>';
                        html += '    </div>';
                        html += '  </div>';
                        html += '</div>';
                    });
                    html += '</div>';
                    $('#modalCertificatesContainer').html(html);
                } else {
                    $('#modalCertificatesContainer').html('<div class="text-center py-4 text-muted"><i class="fas fa-info-circle fa-2x mb-2"></i><p>لا توجد شهادات صادرة لهذا المعلم حتى الآن</p></div>');
                }
            },
            error: function() {
                $('#modalCertificatesContainer').html('<div class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><p>حدث خطأ أثناء تحميل الشهادات</p></div>');
            }
        });
    });

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
});
</script>

<?php include_once '../includes/admin_footer.php'; ?>
