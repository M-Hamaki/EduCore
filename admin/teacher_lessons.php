<?php
/**
 * أرشيف دروس المعلم - عرض الأدمن
 * Teacher Lesson Archive - Admin View
 */
$page_title = "أرشيف دروس المعلم";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/user.php';
require_once '../classes/LessonGenerator.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

Utilities::validateSession('admin');

// Validate teacher_id parameter
if (!isset($_GET['teacher_id']) || !is_numeric($_GET['teacher_id'])) {
    header('Location: ai_lessons_monitor.php');
    exit;
}

$teacherId = intval($_GET['teacher_id']);

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

// Get teacher info
$teacherStmt = $db->prepare("SELECT u.id, u.name, u.username FROM users u WHERE u.id = ?
    AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active')");
$teacherStmt->execute([$teacherId]);
$teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);

if (!$teacher) {
    header('Location: ai_lessons_monitor.php');
    exit;
}

// Get assigned classes
$classesStmt = $db->prepare("SELECT c.name FROM teacher_classes tc INNER JOIN classes c ON tc.class_id = c.id WHERE tc.teacher_id = ? ORDER BY c.name");
$classesStmt->execute([$teacherId]);
$assignedClasses = $classesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get teacher lessons
$generator = new LessonGenerator($db, $teacherId);
$lessons = $generator->getTeacherLessons(500);

// Get published online exams
$publishedExams = [];
try {
    $stmt = $db->prepare("SELECT id, lesson_id, exam_code FROM ai_online_exams WHERE teacher_id = ? AND is_active = 1");
    $stmt->execute([$teacherId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $publishedExams[$row['lesson_id']] = $row;
    }
} catch (Exception $e) {}

// Stats
$totalLessons = count($lessons);
$completedCount = 0;
$draftCount = 0;
$currentMonth = date('Y-m');
$thisMonthCount = 0;

foreach ($lessons as $lesson) {
    if ($lesson['status'] === 'completed') $completedCount++;
    if ($lesson['status'] === 'draft') $draftCount++;
    if (substr($lesson['created_at'], 0, 7) === $currentMonth) $thisMonthCount++;
}

$statusLabels = [
    'draft' => 'مسودة',
    'generating' => 'قيد التوليد',
    'completed' => 'مكتمل',
    'error' => 'خطأ'
];

$langLabels = [
    'ar' => 'عربي',
    'en' => 'English',
    'fr' => 'Français'
];

include_once '../includes/admin_header.php';
?>

<div class="admin-page-heading mb-4">
    <div>
        <h1 class="h2"><i class="fas fa-folder-open text-primary me-2"></i>أرشيف دروس المعلم: <?php echo htmlspecialchars($teacher['name']); ?></h1>
        <?php if (!empty($assignedClasses)): ?>
            <div class="mt-2">
                <span class="text-muted small me-2"><i class="fas fa-chalkboard me-1"></i>الفصول المسندة:</span>
                <?php foreach ($assignedClasses as $cls): ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1 px-3 py-2"><?php echo htmlspecialchars($cls); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="admin-top-actions">
        <a href="ai_lessons_monitor.php" class="btn btn-header-premium btn-print-soft">
            <i class="fas fa-arrow-right"></i>العودة للمتابعة
        </a>
    </div>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #6366f1, #4f46e5);">
            <div class="stat-card-icon"><i class="fas fa-book-open"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalLessons; ?>">0</div>
                <div class="stat-card-label">إجمالي الدروس</div>
                <div class="stat-card-sub"><i class="fas fa-list"></i> جميع الدروس المحضرة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $completedCount; ?>">0</div>
                <div class="stat-card-label">دروس مكتملة</div>
                <div class="stat-card-sub"><i class="fas fa-check"></i> جاهزة للعرض</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #6b7280, #4b5563);">
            <div class="stat-card-icon"><i class="fas fa-pen"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $draftCount; ?>">0</div>
                <div class="stat-card-label">مسودات</div>
                <div class="stat-card-sub"><i class="fas fa-clock"></i> قيد التحضير</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $thisMonthCount; ?>">0</div>
                <div class="stat-card-label">هذا الشهر</div>
                <div class="stat-card-sub"><i class="fas fa-calendar-alt"></i> خطة الشهر الحالي</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-file-alt"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo count($publishedExams); ?>">0</div>
                <div class="stat-card-label">اختبارات منشورة</div>
                <div class="stat-card-sub"><i class="fas fa-laptop-code"></i> اختبارات أونلاين</div>
            </div>
        </div>
    </div>
</div>

<!-- Lessons Table -->
<div class="admin-list-surface mb-4">
    <?php if (empty($lessons)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-inbox fa-3x mb-3"></i>
            <p class="h5">لا توجد دروس محضرة لهذا المعلم</p>
        </div>
    <?php else: ?>
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table" id="lessonsTable">
                <thead>
                    <tr>
                        <th width="45" class="text-center">#</th>
                        <th>عنوان الدرس</th>
                        <th>المادة</th>
                        <th>اللغة</th>
                        <th>المدة</th>
                        <th>الحالة</th>
                        <th>تاريخ الإنشاء</th>
                        <th width="120" class="text-center actions-column">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lessons as $i => $lesson): ?>
                    <tr>
                        <td class="fw-bold text-center align-middle"><?php echo $i + 1; ?></td>
                        <td class="align-middle fw-bold text-primary"><?php echo htmlspecialchars($lesson['title']); ?></td>
                        <td class="align-middle">
                            <?php if (!empty($lesson['subject'])): ?>
                                <span class="badge bg-light text-dark border px-2 py-1"><?php echo htmlspecialchars($lesson['subject']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle">
                            <span class="badge bg-light text-dark border px-2 py-1"><?php echo $langLabels[$lesson['language'] ?? 'ar'] ?? $lesson['language']; ?></span>
                        </td>
                        <td class="align-middle small">
                            <i class="far fa-clock me-1 text-muted"></i><?php echo (int)$lesson['duration_minutes']; ?> د
                        </td>
                        <td class="align-middle">
                            <?php
                            $sSubtle = 'info';
                            switch($lesson['status']) {
                                case 'completed': $sSubtle = 'success'; break;
                                case 'draft': $sSubtle = 'secondary'; break;
                                case 'error': $sSubtle = 'danger'; break;
                                case 'generating': $sSubtle = 'info'; break;
                            }
                            ?>
                            <span class="badge bg-<?php echo $sSubtle; ?>-subtle text-<?php echo $sSubtle; ?> rounded-pill px-3 py-1"><?php echo $statusLabels[$lesson['status']] ?? $lesson['status']; ?></span>
                        </td>
                        <td class="align-middle small">
                            <span><?php echo date('Y/m/d', strtotime($lesson['created_at'])); ?></span>
                            <br><span class="text-muted" dir="ltr"><?php echo date('h:i A', strtotime($lesson['created_at'])); ?></span>
                        </td>
                        <td class="align-middle text-center actions-column admin-table-actions">
                            <a href="../teacher/lesson_view.php?id=<?php echo (int)$lesson['id']; ?>&teacher_id=<?php echo $teacherId; ?>" class="btn btn-action-pills btn-view has-tooltip me-1" title="عرض الدرس">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (isset($publishedExams[$lesson['id']])): ?>
                                <span class="badge bg-success-subtle text-success ms-1 has-tooltip" title="اختبار منشور - كود: <?php echo htmlspecialchars($publishedExams[$lesson['id']]['exam_code']); ?>">
                                    <i class="fas fa-laptop-code me-1"></i><?php echo htmlspecialchars($publishedExams[$lesson['id']]['exam_code']); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>



<?php include_once '../includes/admin_footer.php'; ?>
