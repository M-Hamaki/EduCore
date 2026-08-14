<?php
/**
 * صفحة عرض تفاصيل الدرس المحضر
 * Lesson View Page
 */

require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/utilities.php';

$isAdminView = false;
if (isset($_SESSION['user_id']) && in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    // Admin viewing a teacher's lesson
    $isAdminView = true;
    if (!isset($_GET['teacher_id']) || !is_numeric($_GET['teacher_id'])) {
        header('Location: ../admin/ai_lessons_monitor.php');
        exit;
    }
    $teacherId = intval($_GET['teacher_id']);
} elseif (isset($_SESSION['user_id']) && in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    $teacherId = $_SESSION['user_id'];
} else {
    // عند انتهاء الجلسة، نمرر علامة الخطأ (إن وُجدت) لصفحة تسجيل الدخول
    // ليتمكن المستخدم من فهم سبب إعادة التوجيه بدل أن تظهر له صفحة فارغة.
    $loginError = isset($_GET['error']) ? '?error=' . urlencode($_GET['error']) : '';
    header('Location: ../index.php' . $loginError);
    exit;
}

require_once '../config/database.php';
require_once '../classes/LessonGenerator.php';

$database = new Database();
$db = $database->getConnection();

$generator = new LessonGenerator($db, $teacherId);

// التحقق من معرف الدرس
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ' . ($isAdminView ? '../admin/ai_lessons_monitor.php' : 'lesson_archive.php'));
    exit;
}

$lessonId = intval($_GET['id']);
$lesson = $generator->getLesson($lessonId);

if (!$lesson) {
    header('Location: ' . ($isAdminView ? '../admin/teacher_lessons.php?teacher_id=' . $teacherId : 'lesson_archive.php'));
    exit;
}

// تحليل البيانات
$lessonPlan = !empty($lesson['generated_prep']) ? json_decode($lesson['generated_prep'], true) : null;
$questionBank = !empty($lesson['question_bank']) ? json_decode($lesson['question_bank'], true) : null;
$visualMaterials = !empty($lesson['visual_materials']) ? json_decode($lesson['visual_materials'], true) : null;
$classActivities = !empty($lesson['class_activities']) ? json_decode($lesson['class_activities'], true) : null;
$mindMaps = !empty($lesson['mind_maps']) ? json_decode($lesson['mind_maps'], true) : null;
$lessonSummary = !empty($lesson['lesson_summary']) ? json_decode($lesson['lesson_summary'], true) : null;
$customContent = !empty($lesson['custom_content']) ? json_decode($lesson['custom_content'], true) : null;
$educationalStories = !empty($lesson['educational_stories']) ? json_decode($lesson['educational_stories'], true) : null;
$hasExam = !empty($lesson['exam_html']);
$examModelsCount = isset($lesson['exam_models_count']) ? intval($lesson['exam_models_count']) : 3;
// عرض PowerPoint محفوظ؟ (العمود powerpoint_path موجود ومكتوب عبر AiLessonLifecycleService).
$hasPowerPoint = !empty($lesson['powerpoint_path']);

if ($isAdminView) {
    $tStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $tStmt->execute([$teacherId]);
    $teacher_name = $tStmt->fetchColumn() ?: 'المعلم';
    $backUrl = '../admin/teacher_lessons.php?teacher_id=' . $teacherId;
} elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'external_teacher') {
    $teacher_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'المعلم';
    $backUrl = '../external/index.php';
} else {
    $teacher_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'المعلم';
    $backUrl = 'lesson_archive.php';
}

// PHP render functions removed - now using shared JS rendering via assets/js/lesson_display.js
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo csrfToken(); ?>">
    <title>عرض الدرس - <?php echo htmlspecialchars($lesson['title']); ?></title>

    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/eduvisual.css?v=4.1">
    <link rel="stylesheet" href="styles.css?v=1.3">
    <!-- html2canvas for card capture -->
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

    <link rel="stylesheet" href="../assets/css/lesson-prep-studio.css?v=1.1">
    <link rel="stylesheet" href="../assets/css/lesson-view.css?v=2.0">
    <link rel="stylesheet" href="../assets/css/lesson-sharing.css?v=1">
    <link rel="stylesheet" href="../assets/css/buttons.css">
    <link rel="stylesheet" href="../assets/css/admin-unified.css?v=2.0">
</head>

<body>
    <div class="main-container">
        <?php
        // عرض رسائل الخطأ من lesson_download.php (مثل: no_powerpoint / session_expired).
        $errorCode = isset($_GET['error']) ? $_GET['error'] : '';
        $errorMessages = [
            'no_powerpoint' => 'لا يوجد ملف عرض تقديمي لهذا الدرس. يرجى توليد العرض التقديمي أولاً.',
            'no_exam' => 'لا يوجد امتحان لهذا الدرس.',
            'no_prep' => 'لا توجد بيانات تحضير لهذا الدرس.',
            'no_questions' => 'لا يوجد بنك أسئلة لهذا الدرس.',
            'session_expired' => 'انتهت جلستك أثناء محاولة التحميل. يرجى إعادة تحميل الصفحة والمحاولة مرة أخرى.',
        ];
        if (isset($errorMessages[$errorCode])):
        ?>
        <div id="downloadErrorAlert" class="alert alert-warning d-flex align-items-center gap-2 p-3 rounded-3 border mb-3 shadow-sm" role="alert">
            <i class="fas fa-exclamation-triangle"></i>
            <span class="flex-grow-1"><?php echo htmlspecialchars($errorMessages[$errorCode], ENT_QUOTES, 'UTF-8'); ?></span>
            <button type="button" class="btn-close" onclick="document.getElementById('downloadErrorAlert').remove()" aria-label="إغلاق"></button>
        </div>
        <?php endif; ?>

        <!-- Unified Top Page Heading Bar -->
        <div class="admin-page-heading mb-4">
            <h1 class="h2"><i class="fas fa-book-open me-2 text-primary"></i>تفاصيل الدرس</h1>
            <div class="admin-top-actions no-print">
                <a href="lesson_prep.php" class="btn btn-header-premium btn-success shadow-sm">
                    <i class="fas fa-plus-circle me-1"></i>تحضير درس جديد
                </a>
                <a href="<?php echo $backUrl; ?>" class="btn btn-header-premium btn-secondary shadow-sm">
                    <i class="fas fa-archive me-1"></i><?php echo $isAdminView ? 'العودة للمراقبة' : 'أرشيف الدروس'; ?>
                </a>
                <a href="<?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'external_teacher') ? '../external/index.php' : 'portal.php'; ?>" class="btn btn-header-premium btn-import-soft">
                    <i class="fas fa-arrow-right me-1"></i>العودة للبوابة
                </a>
            </div>
        </div>

        <!-- Lesson Summary Card -->
        <div class="page-header shadow-sm mb-4">
            <div class="header-title">
                <div class="header-icon">
                    <div class="header-icon-ring">
                        <div class="header-icon-inner">
                            <i class="fas fa-brain"></i>
                        </div>
                    </div>
                    <div class="header-icon-sparkles">
                        <div class="hsparkle"></div>
                        <div class="hsparkle"></div>
                        <div class="hsparkle"></div>
                    </div>
                </div>
                <div class="header-text">
                    <h1 class="h3 mb-2 text-dark"><?php echo htmlspecialchars($lesson['title']); ?></h1>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                        <?php if (!empty($lesson['subject'])): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fw-semibold">
                                <i class="fas fa-book me-1"></i><?php echo htmlspecialchars($lesson['subject']); ?>
                            </span>
                        <?php endif; ?>
                        <?php 
                        $displayGrade = !empty($lesson['grade_level']) ? $lesson['grade_level'] : (!empty($lesson['grade']) ? $lesson['grade'] : '');
                        if (!empty($displayGrade)): 
                        ?>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2 fw-semibold">
                                <i class="fas fa-graduation-cap me-1"></i><?php echo htmlspecialchars($displayGrade); ?>
                            </span>
                        <?php endif; ?>
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-3 py-2 fw-semibold">
                            <i class="fas fa-clock me-1"></i><?php echo (int) $lesson['duration_minutes']; ?> دقيقة
                        </span>
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-3 py-2 fw-semibold">
                            <i class="fas fa-language me-1"></i><?php echo $lesson['language'] === 'ar' ? 'عربي' : 'English'; ?>
                        </span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 fw-semibold">
                            <i class="fas fa-circle-check me-1"></i><?php echo ['draft' => 'مسودة', 'generating' => 'قيد التوليد', 'completed' => 'مكتمل', 'error' => 'خطأ'][$lesson['status']] ?? $lesson['status']; ?>
                        </span>
                        <span class="badge bg-light text-muted border px-3 py-2 fw-normal">
                            <i class="fas fa-calendar-alt me-1"></i><?php echo date('Y/m/d H:i', strtotime($lesson['created_at'])); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="header-actions d-flex flex-wrap align-items-center gap-2">
                <button onclick="window.print()" class="btn btn-outline-secondary shadow-sm px-3 py-2">
                    <i class="fas fa-print me-1"></i>طباعة
                </button>
                <?php if (!$isAdminView): ?>
                <button onclick="switchToExportTab()" class="btn btn-outline-info shadow-sm px-3 py-2">
                    <i class="fas fa-share-nodes me-1"></i>مشاركة الدرس
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== Modern Tab Navigation ===== -->
        <div class="main-page-tabs mb-4">
            <button type="button" class="main-tab-btn active" data-tab="lesson-plan">
                <i class="fas fa-clipboard-list tab-icon"></i>
                <span>تحضير الدرس</span>
            </button>
            <button type="button" class="main-tab-btn" data-tab="visual-materials">
                <i class="fas fa-images tab-icon"></i>
                <span>المواد البصرية</span>
            </button>
            <button type="button" class="main-tab-btn" data-tab="mind-maps">
                <i class="fas fa-project-diagram tab-icon"></i>
                <span>الخرائط الذهنية</span>
            </button>
            <button type="button" class="main-tab-btn" data-tab="question-bank">
                <i class="fas fa-question-circle tab-icon"></i>
                <span>بنك الأسئلة</span>
            </button>
            <button type="button" class="main-tab-btn" data-tab="class-activities">
                <i class="fas fa-puzzle-piece tab-icon"></i>
                <span>أنشطة صفية</span>
            </button>
            <button type="button" class="main-tab-btn" data-tab="educational-stories">
                <i class="fas fa-book-open tab-icon"></i>
                <span>القصة التربوية</span>
            </button>
            <?php if ($customContent): ?>
            <button type="button" class="main-tab-btn" data-tab="custom-content">
                <i class="fas fa-magic tab-icon"></i>
                <span>محتوى مخصص</span>
            </button>
            <?php endif; ?>
            <?php if ($hasExam): ?>
            <button type="button" class="main-tab-btn" data-tab="exam-preview">
                <i class="fas fa-file-alt tab-icon"></i>
                <span>الامتحان الإلكتروني</span>
            </button>
            <?php endif; ?>
            <?php if ($hasPowerPoint): ?>
            <button type="button" class="main-tab-btn" data-tab="powerpoint-preview">
                <i class="fas fa-file-powerpoint tab-icon"></i>
                <span>العرض التقديمي</span>
            </button>
            <?php endif; ?>
            <button type="button" class="main-tab-btn" data-tab="export-lesson">
                <i class="fas fa-file-export tab-icon"></i>
                <span>مشاركة وتصدير</span>
            </button>
        </div>

            <!-- Tab 1: Lesson Plan (Dynamic Renderer) -->
            <div class="tab-content active" id="lesson-plan">
                <div id="lessonPlanContent">
                    <!-- JS will populate via displayLessonPlan() -->
                </div>
            </div>

            <!-- Tab 2: Question Bank -->
            <div class="tab-content" id="question-bank">
                <div id="questionBankContent">
                    <!-- JS will populate via displayQuestionBank() -->
                </div>
            </div>

            <!-- Tab 3: Class Activities -->
            <div class="tab-content" id="class-activities">
                <div id="classActivitiesContent">
                    <!-- JS will populate via displayClassActivities() -->
                </div>
            </div>

            <!-- Tab: Educational Stories (مطابقة لتبويب القصة التربوية في واجهة التوليد) -->
            <div class="tab-content" id="educational-stories">
                <div id="educationalStoriesContent">
                    <!-- JS will populate via displayEducationalStories() -->
                </div>
            </div>

            <!-- Tab 4: Visual Materials -->
            <div class="tab-content" id="visual-materials">
                <div id="visualMaterialsContent">
                    <!-- JS will populate via displayVisualMaterials() -->
                </div>
            </div>

            <!-- Tab 5: Mind Maps (EduVisual) -->
            <div class="tab-content" id="mind-maps">
                <?php if ($mindMaps): ?>
                    <div class="section-header-actions mb-2">
                        <h3 class="section-title" style="margin-bottom:0;border-bottom:none;padding-bottom:0;"><i class="fas fa-project-diagram"></i> الخرائط الذهنية التفاعلية</h3>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <?php if (!$isAdminView): ?>
                            <button class="btn-inline-edit" id="saveMindMapsBtn" onclick="saveMindMapsToServer()" title="حفظ تعديلات الخريطة الذهنية" style="display:none;">
                                <i class="fas fa-save"></i> حفظ التعديلات
                            </button>
                            <?php endif; ?>
                            <button class="btn-quick-copy" onclick="exportMindMapsJSON()" title="تصدير بيانات الخرائط كملف JSON"><i class="fas fa-download"></i> تصدير JSON</button>
                            <?php if (!$isAdminView): ?>
                            <button class="btn-quick-copy" onclick="importMindMapsJSON()" title="استيراد بيانات الخرائط من ملف JSON"><i class="fas fa-upload"></i> استيراد JSON</button>
                            <?php endif; ?>
                            <button class="btn-quick-copy" onclick="quickCopySection('mind-maps')" title="نسخ سريع"><i class="fas fa-copy"></i> نسخ</button>
                        </div>
                    </div>
                    <div class="alert alert-info d-flex align-items-center gap-2 py-2 px-3 mb-3 rounded-3" style="font-size: 0.88rem; background: #f0fdf4; border-color: #bbf7d0; color: #166534;">
                        <i class="fas fa-info-circle fs-5" style="color: #10b981;"></i>
                        <div>
                            <strong>خرائط تفاعلية:</strong> انقر نقراً مزدوجاً (Double Click) على أي عنصر لتعديل نصوصه وألوانه، وتجد أزرار تحميل كل خريطة منفصلة (<strong>PNG / SVG</strong>) في الشريط أسفل كل خريطة.
                        </div>
                    </div>
                    <div id="eduvisual-root"></div>
                <?php
else: ?>
                    <div class="empty-tab">
                        <i class="fas fa-project-diagram"></i>
                        <p>لم يتم توليد خرائط ذهنية بعد</p>
                        <small style="color: #94a3b8;">الخرائط الذهنية متاحة للدروس الجديدة فقط</small>
                    </div>
                <?php
endif; ?>
            </div>

            <!-- Lesson Summary Tab -->
            <div class="tab-content" id="lesson-summary">
                <div id="lessonSummaryContent">
                    <!-- JS will populate via displayLessonSummary() -->
                </div>
            </div>

            <!-- Custom Content Tab -->
            <?php if ($customContent): ?>
            <div class="tab-content" id="custom-content">
                <div id="customContentArea">
                    <!-- JS will populate via displayCustomContent() -->
                </div>
            </div>
            <?php endif; ?>

            <!-- Tab 6: Exam Preview -->
            <?php if ($hasExam): ?>
            <div class="tab-content" id="exam-preview">
                <div style="text-align: center; padding: 20px 0;">
                    <i class="fas fa-file-code" style="font-size: 4rem; color: #8b5cf6; margin-bottom: 20px;"></i>
                    <h3 style="color: #1e293b; margin-bottom: 15px;">الامتحان الإلكتروني جاهز</h3>
                    <p style="color: #64748b; margin-bottom: 25px;">ملف HTML مستقل يعمل بدون إنترنت ويحتوي على جميع مميزات منع الغش</p>
                    
                    <!-- قسم النماذج المتاحة -->
                    <div style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); padding: 25px; border-radius: 15px; margin-bottom: 25px; border: 2px solid #e2e8f0;">
                        <h4 style="color: #475569; margin-bottom: 20px; font-size: 1.1rem;">
                            <i class="fas fa-copy" style="color: #8b5cf6;"></i> النماذج المتاحة للتحميل
                        </h4>
                        <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">
                            كل نموذج يحتوي على نفس الأسئلة ولكن بترتيب مختلف لمنع الغش
                        </p>
                        <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; margin-bottom: 15px;">
                            <?php
    $modelLetters = ['A', 'B', 'C', 'D'];
    $modelColors = [
        'linear-gradient(135deg, #3b82f6, #1d4ed8)',
        'linear-gradient(135deg, #10b981, #059669)',
        'linear-gradient(135deg, #f59e0b, #d97706)',
        'linear-gradient(135deg, #ef4444, #dc2626)'
    ];
    for ($i = 0; $i < $examModelsCount && $i < 4; $i++):
?>
                            <button onclick="downloadSingleModel('<?php echo $modelLetters[$i]; ?>')" class="btn-export" style="background: <?php echo $modelColors[$i]; ?>; color: white; min-width: 140px;">
                                <i class="fas fa-download"></i> النموذج <?php echo $modelLetters[$i]; ?>
                            </button>
                            <?php
    endfor; ?>
                        </div>
                    </div>
                    
                    <!-- أزرار تحميل نماذج الإجابة -->
                    <div style="background: linear-gradient(135deg, #fefce8, #fef9c3); padding: 25px; border-radius: 15px; margin-bottom: 25px; border: 2px solid #eab308;">
                        <h4 style="color: #854d0e; margin-bottom: 15px; font-size: 1.1rem;">
                            <i class="fas fa-key" style="color: #eab308;"></i> نماذج الإجابة
                        </h4>
                        <p style="color: #92400e; font-size: 0.9rem; margin-bottom: 15px;">تحميل نموذج إجابة يحتوي على جميع الأسئلة والإجابات الصحيحة</p>
                        <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                            <?php
    $answerKeyColors = [
        'linear-gradient(135deg, #854d0e, #a16207)',
        'linear-gradient(135deg, #065f46, #047857)',
        'linear-gradient(135deg, #9a3412, #c2410c)',
        'linear-gradient(135deg, #7f1d1d, #991b1b)'
    ];
    for ($i = 0; $i < $examModelsCount && $i < 4; $i++):
?>
                            <button onclick="downloadAnswerKey('<?php echo $modelLetters[$i]; ?>')" class="btn-export" style="background: <?php echo $answerKeyColors[$i]; ?>; color: white; min-width: 160px;">
                                <i class="fas fa-key"></i> إجابة النموذج <?php echo $modelLetters[$i]; ?>
                            </button>
                            <?php
    endfor; ?>
                        </div>
                    </div>



                    <!-- قسم رابط الامتحان الأونلاين -->
                    <div id="onlineExamLink" style="display: none; background: linear-gradient(135deg, #f0fdf4, #dcfce7); padding: 25px; border-radius: 15px; border: 2px solid #22c55e; margin-top: 20px; text-align: center;">
                        <h4 style="color: #15803d; margin-bottom: 15px;">
                            <i class="fas fa-check-circle"></i> تم نشر الامتحان بنجاح!
                        </h4>
                        <p style="color: #166534; margin-bottom: 15px;">شارك هذا الرابط مع طلابك:</p>
                        <div style="display: flex; gap: 10px; align-items: center; justify-content: center; flex-wrap: wrap;">
                            <input type="text" id="examLinkInput" readonly 
                                   style="flex: 1; min-width: 250px; padding: 12px 15px; border: 2px solid #22c55e; border-radius: 10px; font-size: 0.95rem; background: white; direction: ltr; text-align: center;">
                            <button onclick="copyExamLink(event)" class="btn-export" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; padding: 12px 20px;">
                                <i class="fas fa-copy"></i> نسخ
                            </button>
                            <a id="viewResultsLink" href="#" target="_blank" class="btn-export" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 12px 20px; text-decoration: none;">
                                <i class="fas fa-chart-bar"></i> النتائج
                            </a>
                        </div>
                        <p style="color: #166534; font-size: 0.85rem; margin-top: 15px;">
                            <i class="fas fa-info-circle"></i> يمكن للطلاب فتح الرابط وإدخال بياناتهم لبدء الامتحان
                        </p>
                        <!-- QR Code Container -->
                        <div id="examQRCodeContainer" style="margin-top: 20px; text-align: center;">
                            <h5 style="color: #15803d; margin-bottom: 10px;"><i class="fas fa-qrcode"></i> رمز QR للامتحان</h5>
                            <div id="examQRCode" style="display: inline-block; background: white; padding: 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);"></div>
                            <p style="color: #166534; font-size: 0.8rem; margin-top: 8px;"><i class="fas fa-mobile-alt"></i> يمكن للطلاب مسح الرمز بالجوال لفتح الامتحان</p>
                        </div>
                    </div>
                    
                    <!-- معاينة الامتحان -->
                    <div style="border: 2px solid #e2e8f0; border-radius: 12px; overflow: hidden; height: 500px; margin-top: 25px;">
                        <iframe srcdoc="<?php echo htmlspecialchars($lesson['exam_html']); ?>" style="width: 100%; height: 100%; border: none;"></iframe>
                    </div>
                </div>
            </div>
            <?php
endif; ?>

            <!-- Tab: PowerPoint Preview -->
            <?php if ($hasPowerPoint): ?>
            <div class="tab-content" id="powerpoint-preview">
                <div style="text-align: center; padding: 20px 0;">
                    <i class="fas fa-file-powerpoint" style="font-size: 4rem; color: #d97706; margin-bottom: 20px;"></i>
                    <h3 style="color: #1e293b; margin-bottom: 10px;">العرض التقديمي جاهز</h3>
                    <p style="color: #64748b; margin-bottom: 25px;">
                        ملف PowerPoint (PPTX) قابل للتعديل محلياً على جهازك.
                    </p>
                    <div style="background: linear-gradient(135deg, #fff7ed, #ffedd5); padding: 25px; border-radius: 15px; border: 2px solid #fed7aa; margin-bottom: 25px;">
                        <h4 style="color: #9a3412; margin-bottom: 15px; font-size: 1.1rem;">
                            <i class="fas fa-download" style="color: #d97706;"></i> تحميل العرض التقديمي
                        </h4>
                        <p style="color: #7c2d12; font-size: 0.9rem; margin-bottom: 20px;">
                            اضغط على الزر أدناه لتنزيل ملف PPTX. يمكنك فتحه وتعديله في PowerPoint أو Google Slides.
                        </p>
                        <a href="lesson_download.php?id=<?php echo $lessonId; ?>&type=powerpoint<?php echo $isAdminView ? '&teacher_id=' . $teacherId : ''; ?>"
                           download="lesson_<?php echo $lessonId; ?>.pptx"
                           class="btn-export"
                           style="background: linear-gradient(135deg, #d97706, #b45309); color: white; min-width: 200px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; justify-content: center; padding: 12px 24px;">
                            <i class="fas fa-download"></i> تحميل ملف PowerPoint (PPTX)
                        </a>
                    </div>
                </div>
            </div>
            <?php
endif; ?>

            <!-- Tab 7: Export Lesson -->
            <div class="tab-content" id="export-lesson">
                <div style="padding: 10px 0;">
                    <?php if (!$isAdminView): ?>
                        <?php
                        // lessonSharePanel
                        $lessonShareLessonId = $lessonId;
                        require __DIR__ . '/../classes/Presentation/LessonPrep/share_panel.php';
                        ?>
                    <?php endif; ?>

                    <!-- اختيار العناصر والتصدير المباشر -->
                    <div class="settings-subcard text-start mb-3" style="background: #ffffff !important; border: 1px solid #cbd5e1 !important; border-radius: 12px !important; padding: 18px 20px !important;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <h4 style="color: #1e293b; margin: 0; font-size: 0.95rem; font-weight: 700;">
                                <i class="fas fa-check-double text-primary me-1"></i> اختيار عناصر التصدير
                            </h4>
                            <button type="button" class="btn btn-outline-primary btn-sm px-3 fw-semibold shadow-sm" onclick="toggleAllExportElements()" id="exportToggleAllBtn" style="font-size: 0.8rem; border-radius: 6px;">
                                <i class="fas fa-times-circle me-1"></i> إلغاء تحديد الكل
                            </button>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; margin-bottom: 16px;">
                            <label class="export-element-checkbox-label">
                                <input type="checkbox" name="export_elements[]" value="lesson-plan" checked class="export-element-checkbox">
                                <i class="fas fa-clipboard-list" style="color: #10b981;"></i>
                                <span>تحضير الدرس</span>
                            </label>
                            <label class="export-element-checkbox-label<?php echo !$questionBank ? ' export-element-disabled' : ''; ?>">
                                <input type="checkbox" name="export_elements[]" value="question-bank" <?php echo $questionBank ? 'checked' : 'disabled'; ?> class="export-element-checkbox">
                                <i class="fas fa-question-circle" style="color: #f59e0b;"></i>
                                <span>بنك الأسئلة<?php echo !$questionBank ? ' (غير متوفر)' : ''; ?></span>
                            </label>
                            <label class="export-element-checkbox-label<?php echo !$visualMaterials ? ' export-element-disabled' : ''; ?>">
                                <input type="checkbox" name="export_elements[]" value="visual-materials" <?php echo $visualMaterials ? 'checked' : 'disabled'; ?> class="export-element-checkbox">
                                <i class="fas fa-images" style="color: #8b5cf6;"></i>
                                <span>المواد البصرية<?php echo !$visualMaterials ? ' (غير متوفر)' : ''; ?></span>
                            </label>
                            <label class="export-element-checkbox-label<?php echo !$classActivities ? ' export-element-disabled' : ''; ?>">
                                <input type="checkbox" name="export_elements[]" value="class-activities" <?php echo $classActivities ? 'checked' : 'disabled'; ?> class="export-element-checkbox">
                                <i class="fas fa-puzzle-piece" style="color: #ef4444;"></i>
                                <span>الأنشطة الصفية<?php echo !$classActivities ? ' (غير متوفر)' : ''; ?></span>
                            </label>
                            <label class="export-element-checkbox-label<?php echo !$educationalStories ? ' export-element-disabled' : ''; ?>">
                                <input type="checkbox" name="export_elements[]" value="educational-stories" <?php echo $educationalStories ? 'checked' : 'disabled'; ?> class="export-element-checkbox">
                                <i class="fas fa-book-open" style="color: #ec4899;"></i>
                                <span>القصة التربوية<?php echo !$educationalStories ? ' (غير متوفر)' : ''; ?></span>
                            </label>
                            <label class="export-element-checkbox-label<?php echo !$mindMaps ? ' export-element-disabled' : ''; ?>">
                                <input type="checkbox" name="export_elements[]" value="mind-maps" <?php echo $mindMaps ? 'checked' : 'disabled'; ?> class="export-element-checkbox">
                                <i class="fas fa-project-diagram" style="color: #06b6d4;"></i>
                                <span>الخرائط الذهنية<?php echo !$mindMaps ? ' (غير متوفر)' : ''; ?></span>
                            </label>
                            <label class="export-element-checkbox-label<?php echo !$lessonSummary ? ' export-element-disabled' : ''; ?>">
                                <input type="checkbox" name="export_elements[]" value="lesson-summary" <?php echo $lessonSummary ? 'checked' : 'disabled'; ?> class="export-element-checkbox">
                                <i class="fas fa-file-lines" style="color: #8b5cf6;"></i>
                                <span>ملخص الدرس<?php echo !$lessonSummary ? ' (غير متوفر)' : ''; ?></span>
                            </label>
                            <label class="export-element-checkbox-label<?php echo !$customContent ? ' export-element-disabled' : ''; ?>">
                                <input type="checkbox" name="export_elements[]" value="custom-content" <?php echo $customContent ? 'checked' : 'disabled'; ?> class="export-element-checkbox">
                                <i class="fas fa-wand-magic-sparkles" style="color: #10b981;"></i>
                                <span>المحتوى المخصص<?php echo !$customContent ? ' (غير متوفر)' : ''; ?></span>
                            </label>
                            <label class="export-element-checkbox-label<?php echo !$hasExam ? ' export-element-disabled' : ''; ?>">
                                <input type="checkbox" name="export_elements[]" value="exam" <?php echo $hasExam ? 'checked' : 'disabled'; ?> class="export-element-checkbox">
                                <i class="fas fa-file-alt" style="color: #8b5cf6;"></i>
                                <span>الامتحان<?php echo !$hasExam ? ' (غير متوفر)' : ''; ?></span>
                            </label>
                        </div>

                        <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                            <button type="button" class="btn-export btn-export-html" onclick="exportContent('html')">
                                <i class="fas fa-code me-1"></i> تصدير HTML
                            </button>
                            <button type="button" class="btn-export btn-export-pdf" onclick="exportContent('pdf')">
                                <i class="fas fa-file-pdf me-1"></i> تصدير PDF
                            </button>
                            <button type="button" class="btn-export btn-export-word" onclick="exportContent('word')">
                                <i class="fas fa-file-word me-1"></i> تصدير Word
                            </button>
                            <button type="button" class="btn-export btn-export-print" onclick="exportContent('print')">
                                <i class="fas fa-print me-1"></i> طباعة المحدد
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export Section (Bottom bar) -->
            <div class="export-section" id="dynamicExportSection">
                <button type="button" class="btn-export btn-export-html" id="exportHtmlBtn" onclick="exportAllToHtml()">
                    <i class="fas fa-code me-1"></i> تصدير HTML
                </button>
                <button type="button" class="btn-export btn-export-pdf" id="exportPdfBtn" onclick="exportAllToPdf()">
                    <i class="fas fa-file-pdf me-1"></i> تصدير PDF
                </button>
                <button type="button" class="btn-export btn-export-word" id="exportWordBtn" onclick="exportAllToWord()">
                    <i class="fas fa-file-word me-1"></i> تصدير Word
                </button>
            </div>
    </div><!-- end main-container -->

    <script src="../assets/js/ai_lesson_csrf.js"></script>
    <script src="../assets/js/lesson_display.js?v=1.2"></script>
    <script src="../assets/js/eduvisual.js?v=4.1"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!$isAdminView): ?>
    <script src="../assets/js/lesson-sharing.js?v=3"></script>
    <?php endif; ?>
    <script>
        // ===== إعداد البيانات للعرض الديناميكي =====
        window.isArchiveView = true;
        // زر التعديل المباشر يظهر فقط لصاحب الدرس (المعلم)، وليس للأدمن الذي يطّلع على درس غيره.
        // _buildSectionActions في lesson_display.js يتطلب وجود window.currentLessonId (غير صفري) لعرض زر التعديل.
        window.currentLessonId = <?php echo $isAdminView ? '0' : (int)$lessonId; ?>;
        window.generatedData = {
            lesson_plan: <?php echo json_encode($lessonPlan, JSON_UNESCAPED_UNICODE); ?>,
            question_bank: <?php echo json_encode($questionBank, JSON_UNESCAPED_UNICODE); ?>,
            visual_materials: <?php echo json_encode($visualMaterials, JSON_UNESCAPED_UNICODE); ?>,
            class_activities: <?php echo json_encode($classActivities, JSON_UNESCAPED_UNICODE); ?>,
            mind_maps: <?php echo json_encode($mindMaps, JSON_UNESCAPED_UNICODE); ?>,
            lesson_summary: <?php echo json_encode($lessonSummary, JSON_UNESCAPED_UNICODE); ?>,
            custom_content: <?php echo json_encode($customContent, JSON_UNESCAPED_UNICODE); ?>,
            educational_stories: <?php echo json_encode($educationalStories, JSON_UNESCAPED_UNICODE); ?>
        };
        // تشغيل العرض الديناميكي
        initLessonDisplay();
        if (typeof updateDynamicExportButtons === 'function') {
            updateDynamicExportButtons('lesson-plan');
        }
    </script>
    <script>
        // تحميل البطاقة التعليمية كصورة PNG
        function downloadFlashCard(linkEl) {
            const wrapper = linkEl.closest('.fc-card-wrapper');
            if (!wrapper) return;
            
            const wasFlipped = wrapper.classList.contains('fc-flipped');
            if (!wasFlipped) wrapper.classList.add('fc-flipped');
            
            const cardBack = wrapper.querySelector('.fc-card-back');
            if (!cardBack) return;
            
            const clone = cardBack.cloneNode(true);
            clone.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:320px;min-height:400px;background:white;border-radius:16px;padding:20px;box-shadow:0 4px 20px rgba(0,0,0,0.15);transform:none;backface-visibility:visible;direction:rtl;font-family:Cairo,sans-serif;z-index:-1;';
            document.body.appendChild(clone);
            
            const num = wrapper.querySelector('.fc-back-num')?.textContent?.replace('#','').trim() || '1';
            
            if (typeof html2canvas !== 'undefined') {
                html2canvas(clone, { scale: 2, useCORS: true, backgroundColor: '#ffffff', logging: false, allowTaint: true }).then(canvas => {
                    const link = document.createElement('a');
                    link.download = 'flashcard_' + num + '.png';
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                    clone.remove();
                    if (!wasFlipped) wrapper.classList.remove('fc-flipped');
                }).catch(err => {
                    console.error('Flash card capture error:', err);
                    clone.remove();
                    if (!wasFlipped) wrapper.classList.remove('fc-flipped');
                });
            } else {
                clone.remove();
                if (!wasFlipped) wrapper.classList.remove('fc-flipped');
            }
        }

        // Tabs
        document.querySelectorAll('.tab-btn, .main-tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn, .main-tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                const target = document.getElementById(btn.dataset.tab);
                if (target) {
                    target.classList.add('active');
                }
                updateDynamicExportButtons(btn.dataset.tab);
            });
        });

        // دالة تحديث أزرار التصدير الديناميكية حسب التبويب النشط
        function updateDynamicExportButtons(activeTab) {
            const section = document.getElementById('dynamicExportSection');
            if (!section) return;
            let html = '';
            const tabExports = {
                'lesson-plan': ['lessonPlanContent', 'التحضير'],
                'question-bank': ['questionBankContent', 'بنك الأسئلة'],
                'visual-materials': ['visualMaterialsContent', 'المواد البصرية'],
                'mind-maps': ['mindMapsContent', 'الخرائط الذهنية'],
                'class-activities': ['classActivitiesContent', 'الأنشطة الصفية'],
                'lesson-summary': ['lessonSummaryContent', 'ملخص الدرس'],
                'educational-stories': ['educationalStoriesContent', 'القصة التربوية'],
                'custom-content': ['customContentArea', 'المحتوى المخصص']
            };

            if (tabExports[activeTab]) {
                const [containerId, label] = tabExports[activeTab];
                html = `
                    <button type="button" class="btn-export btn-export-html" onclick="exportTabToHtml('${containerId}')"><i class="fas fa-code me-1"></i> ${label} HTML</button>
                    <button type="button" class="btn-export btn-export-pdf" onclick="exportTabToPdf('${containerId}')"><i class="fas fa-file-pdf me-1"></i> ${label} PDF</button>
                    <button type="button" class="btn-export btn-export-word" onclick="exportTabToWord('${containerId}')"><i class="fas fa-file-word me-1"></i> ${label} Word</button>
                    <button type="button" class="btn-export btn-export-print" onclick="exportTabToPrint('${containerId}')"><i class="fas fa-print me-1"></i> طباعة ${label}</button>
                `;
            } else {
                html = '';
            }

            section.innerHTML = html;
            section.style.display = html ? 'flex' : 'none';
        }

        // الانتقال السريع لتبويب المشاركة والتصدير
        function switchToExportTab() {
            const exportBtn = document.querySelector('[data-tab="export-lesson"]');
            if (exportBtn) {
                exportBtn.click();
                exportBtn.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Sub-tab switching - provided by lesson_display.js
        
        // Export element selection functions
        function getSelectedExportElements() {
            const checkboxes = document.querySelectorAll('.export-element-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }

        function toggleAllExportElements() {
            const checkboxes = document.querySelectorAll('.export-element-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => cb.checked = !allChecked);
            updateExportToggleBtn();
        }

        function updateExportToggleBtn() {
            const checkboxes = document.querySelectorAll('.export-element-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            const btn = document.getElementById('exportToggleAllBtn');
            if (btn) {
                btn.innerHTML = allChecked 
                    ? '<i class="fas fa-times-circle"></i> إلغاء تحديد الكل' 
                    : '<i class="fas fa-check-circle"></i> تحديد الكل';
            }
        }

        // Attach change listeners to update toggle button
        document.querySelectorAll('.export-element-checkbox').forEach(cb => {
            cb.addEventListener('change', updateExportToggleBtn);
        });

        // Export full lesson as PDF (all sections)
        function exportFullLessonPdf() {
            var lessonTitle = <?php echo json_encode($lesson['title'], JSON_UNESCAPED_UNICODE); ?>;
            var sections = [];
            var tabIds = ['lessonPlanContent','questionBankContent','visualMaterialsContent','mindMapsContent','classActivitiesContent','lessonSummaryContent'];
            var tabLabels = ['تحضير الدرس','بنك الأسئلة','المواد البصرية','الخرائط الذهنية','الأنشطة الصفية','ملخص الدرس'];
            var tabColors = ['#10b981','#3b82f6','#8b5cf6','#f59e0b','#ef4444','#06b6d4'];
            for (var i = 0; i < tabIds.length; i++) {
                var el = document.getElementById(tabIds[i]);
                if (el && el.innerHTML.trim()) {
                    sections.push('<h1 style="color:' + tabColors[i] + ';border-bottom:3px solid ' + tabColors[i] + ';padding-bottom:10px;">' + tabLabels[i] + '</h1>' + el.innerHTML);
                }
            }
            if (sections.length === 0) { alert('لا يوجد محتوى للتصدير'); return; }
            var html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>' + lessonTitle + '</title>' +
                '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">' +
                '<style>body{font-family:Cairo,sans-serif;padding:40px;direction:rtl;color:#1e293b}' +
                'table{width:100%;border-collapse:collapse;margin:20px 0;page-break-inside:auto}' +
                'th,td{border:1px solid #d1d5db;padding:12px;text-align:right}' +
                'th{background:#10b981!important;color:white!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
                '.sub-tabs-container,.btn-regenerate-section,.btn-inline-edit,.section-actions{display:none!important}' +
                '@media print{body{padding:20px}}' +
                '</style></head><body>' +
                '<div style="text-align:center;margin-bottom:30px;padding:20px;background:#f0fdf4;border-radius:12px;">' +
                '<h1 style="margin:0;color:#166534;border:none;">' + lessonTitle + '</h1></div>' +
                sections.join('<div style="page-break-before:always;"></div>') +
                '<script>setTimeout(function(){window.print();},500);<\/script></body></html>';
            var win = window.open('', '_blank');
            win.document.write(html);
            win.document.close();
        }

        // Export functions
        function exportContent(format) {
            const lessonTitle = <?php echo json_encode($lesson['title'], JSON_UNESCAPED_UNICODE); ?>;
            
            // جمع المحتوى حسب العناصر المحددة
            const selectedElements = getSelectedExportElements();
            if (selectedElements.length === 0) {
                alert('يرجى اختيار عنصر واحد على الأقل للتصدير');
                return;
            }
            
            let content = '';
            selectedElements.forEach(tabId => {
                const el = document.getElementById(tabId);
                if (el) content += el.innerHTML;
            });
            
            if (format === 'html') {
                const html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>' + lessonTitle + '</title><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet"><style>body{font-family:Cairo,sans-serif;padding:30px;direction:rtl;} .question-item,.plan-item,.visual-item{background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:15px;} .section-title{font-size:1.4rem;font-weight:700;margin:25px 0 15px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;} .sub-tabs-container{display:none;}</style></head><body>' + content + '</body></html>';
                downloadFile(html, lessonTitle + '.html', 'text/html');
            } else if (format === 'pdf') {
                const printWindow = window.open('', '_blank');
                printWindow.document.write('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>' + lessonTitle + '</title><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet"><style>body{font-family:Cairo,sans-serif;padding:30px;direction:rtl;} .question-item,.plan-item,.visual-item{background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:15px;} .section-title{font-size:1.4rem;font-weight:700;margin:25px 0 15px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;} .sub-tabs-container{display:none;}</style></head><body>' + content + '</body></html>');
                printWindow.document.close();
                setTimeout(() => { printWindow.print(); }, 500);
            } else if (format === 'word') {
                const html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="UTF-8"><style>body{font-family:Cairo,sans-serif;direction:rtl;} .sub-tabs-container{display:none;}</style></head><body>' + content + '</body></html>';
                downloadFile(html, lessonTitle + '.doc', 'application/msword');
            } else if (format === 'print') {
                const printWindow = window.open('', '_blank');
                printWindow.document.write('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>' + lessonTitle + '</title><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet"><style>body{font-family:Cairo,sans-serif;padding:30px;direction:rtl;} .question-item,.plan-item,.visual-item{background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:15px;} .section-title{font-size:1.4rem;font-weight:700;margin:25px 0 15px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;} .sub-tabs-container{display:none;}</style></head><body>' + content + '</body></html>');
                printWindow.document.close();
                setTimeout(() => { printWindow.print(); }, 500);
            }
        }
        
        function downloadFile(content, filename, mimeType) {
            const blob = new Blob([content], { type: mimeType + ';charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // Exam model download functions
        const viewLessonId = <?php echo json_encode($lessonId); ?>;
        const viewModelsCount = <?php echo (int)$examModelsCount; ?>;

        async function downloadSingleModel(modelLetter) {
            try {
                const response = await fetch('ajax/generate_single_model.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                        lesson_id: viewLessonId,
                        model: modelLetter,
                        exam_duration: <?php echo (int)($lesson['exam_duration'] ?? 60); ?>
                    })
                });
                const result = await response.json();
                if (result.success) {
                    downloadFile(result.exam_html, `exam_${viewLessonId}_model_${modelLetter}.html`, 'text/html');
                } else {
                    alert('خطأ: ' + result.message);
                }
            } catch (error) {
                alert('حدث خطأ في الاتصال: ' + error.message);
            }
        }

        async function downloadAllModels() {
            try {
                const response = await fetch('ajax/generate_all_models.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                        lesson_id: viewLessonId,
                        exam_duration: <?php echo (int)($lesson['exam_duration'] ?? 60); ?>
                    })
                });
                const result = await response.json();
                if (result.success) {
                    downloadFile(result.exam_html, `exam_${viewLessonId}_all_models.html`, 'text/html');
                } else {
                    alert('خطأ: ' + result.message);
                }
            } catch (error) {
                alert('حدث خطأ في الاتصال: ' + error.message);
            }
        }

        async function downloadAnswerKey(modelLetter) {
            try {
                const response = await fetch('ajax/generate_answer_key.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                        lesson_id: viewLessonId,
                        model: modelLetter
                    })
                });
                const result = await response.json();
                if (result.success) {
                    downloadFile(result.answer_key_html, `answer_key_${viewLessonId}_model_${modelLetter}.html`, 'text/html');
                } else {
                    alert('خطأ: ' + result.message);
                }
            } catch (error) {
                alert('حدث خطأ في الاتصال: ' + error.message);
            }
        }

        async function downloadAllAnswerKeys() {
            try {
                const response = await fetch('ajax/generate_all_answer_keys.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                        lesson_id: viewLessonId
                    })
                });
                const result = await response.json();
                if (result.success) {
                    downloadFile(result.answer_key_html, `answer_keys_${viewLessonId}_all_models.html`, 'text/html');
                } else {
                    alert('خطأ: ' + result.message);
                }
            } catch (error) {
                alert('حدث خطأ في الاتصال: ' + error.message);
            }
        }

        // نشر الامتحان أونلاين
        async function publishExamOnline() {
            const publishBtns = document.querySelectorAll('[onclick*="publishExamOnline"], #publishOnlineBtn');
            const originalTexts = [];
            publishBtns.forEach((btn, i) => {
                originalTexts[i] = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري النشر...';
                btn.disabled = true;
            });

            try {
                const response = await fetch('ajax/publish_exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                        lesson_id: viewLessonId,
                        exam_duration: <?php echo (int)($lesson['exam_duration'] ?? 60); ?>,
                        exam_models: viewModelsCount
                    })
                });

                const result = await response.json();

                if (result.success) {
                    const basePath = window.location.pathname.replace(/\/teacher\/.*$/, '/');
                    const baseUrl = window.location.origin + basePath;
                    const examLink = baseUrl + 'take_exam.php?code=' + result.exam_code;
                    
                    document.getElementById('examLinkInput').value = examLink;
                    document.getElementById('viewResultsLink').href = 'exam_results.php?exam_id=' + result.exam_id;
                    document.getElementById('onlineExamLink').style.display = 'block';
                    
                    // Generate QR Code
                    generateQRCode(examLink, 'examQRCode');
                    
                    publishBtns.forEach(btn => {
                        btn.innerHTML = '<i class="fas fa-check"></i> تم النشر';
                        btn.style.background = 'linear-gradient(135deg, #22c55e, #16a34a)';
                    });
                } else {
                    alert('حدث خطأ: ' + result.message);
                    publishBtns.forEach((btn, i) => {
                        btn.innerHTML = originalTexts[i];
                        btn.disabled = false;
                    });
                }
            } catch (error) {
                console.error('Error:', error);
                alert('حدث خطأ في الاتصال بالخادم');
                publishBtns.forEach((btn, i) => {
                    btn.innerHTML = originalTexts[i];
                    btn.disabled = false;
                });
            }
        }

        // نسخ رابط الامتحان
        function copyExamLink(event) {
            const input = document.getElementById('examLinkInput');
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            document.execCommand('copy');
            
            const btn = event.target.closest('button');
            if (!btn) return;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> تم النسخ!';
            btn.style.background = 'linear-gradient(135deg, #22c55e, #16a34a)';
            
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.style.background = 'linear-gradient(135deg, #3b82f6, #2563eb)';
            }, 2000);
        }

        // توليد QR Code
        function generateQRCode(text, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = '';
            
            // Simple QR code using Google Charts API
            const img = document.createElement('img');
            img.src = 'https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl=' + encodeURIComponent(text);
            img.alt = 'QR Code';
            img.style.borderRadius = '8px';
            container.appendChild(img);
        }

        // EduVisual - تهيئة الخرائط الذهنية التفاعلية
        <?php if ($mindMaps): ?>
        (function() {
            const canSave = !!window.currentLessonId;
            let initialized = false;
            let saveTimer = null;

            // saveMindMapsToServer: دالة عامة لإرسال generatedData.mind_maps إلى update_section.php.
            window.saveMindMapsToServer = function() {
                var lessonId = window.currentLessonId;
                if (!lessonId) return;
                var data = window.generatedData ? window.generatedData.mind_maps : null;
                if (!data) return;
                var btn = document.getElementById('saveMindMapsBtn');
                if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...'; }
                var formData = new FormData();
                formData.append('lesson_id', lessonId);
                formData.append('section_type', 'mind_maps');
                formData.append('section_data', JSON.stringify(data));
                formData.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
                fetch('ajax/update_section.php', { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        if (btn) {
                            if (result.success) {
                                btn.innerHTML = '<i class="fas fa-check"></i> تم الحفظ!';
                                setTimeout(function() { btn.style.display = 'none'; btn.innerHTML = '<i class="fas fa-save"></i> حفظ الخريطة'; btn.disabled = false; }, 2000);
                            } else {
                                btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> فشل الحفظ';
                                btn.disabled = false;
                                alert('خطأ في حفظ الخريطة الذهنية: ' + (result.message || 'خطأ غير معروف'));
                            }
                        }
                    })
                    .catch(function(err) {
                        if (btn) { btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> فشل الحفظ'; btn.disabled = false; }
                        alert('خطأ في الاتصال: ' + err.message);
                    });
            };

            function requestSave() {
                if (!canSave) return;
                var btn = document.getElementById('saveMindMapsBtn');
                if (btn) { btn.style.display = ''; btn.innerHTML = '<i class="fas fa-save"></i> حفظ التعديلات'; btn.disabled = false; }
                if (saveTimer) clearTimeout(saveTimer);
                saveTimer = setTimeout(function() { saveTimer = null; window.saveMindMapsToServer(); }, 800);
            }

            window.exportMindMapsJSON = function() {
                var data = window.generatedData ? window.generatedData.mind_maps : null;
                if (!data) {
                    if (window.EduVisual) EduVisual.showToast('لا توجد خرائط للتصدير', 'warning');
                    return;
                }
                if (window.EduVisual) EduVisual.exportJSON(data, 'mindmaps-' + (window.currentLessonId || 'lesson') + '.json');
            };

            window.importMindMapsJSON = function() {
                if (!canSave) return;
                if (window.EduVisual) {
                    EduVisual.importJSON(function(data) {
                        if (data && typeof data === 'object') {
                            window.generatedData.mind_maps = data;
                            var root = document.getElementById('eduvisual-root');
                            if (root) {
                                EduVisual.renderAll('eduvisual-root', data, {
                                    theme: localStorage.getItem('theme') === 'dark' ? 'dark' : 'modern',
                                    animate: true,
                                    interactive: true,
                                    onSave: canSave ? requestSave : undefined
                                });
                            }
                            requestSave();
                            EduVisual.showToast('تم استيراد الخرائط بنجاح');
                        }
                    });
                }
            };

            window.initMindMapsVisuals = function() {
                if (initialized) return;
                const mindMapsData = (window.generatedData && window.generatedData.mind_maps) ? window.generatedData.mind_maps : null;
                const root = document.getElementById('eduvisual-root');
                if (!root) return;
                if (window.EduVisual && mindMapsData) {
                    try {
                        initialized = true;
                        EduVisual.renderAll('eduvisual-root', mindMapsData, {
                            theme: localStorage.getItem('theme') === 'dark' ? 'dark' : 'modern',
                            animate: true,
                            interactive: true,
                            onSave: canSave ? requestSave : undefined
                        });
                    } catch (err) {
                        console.error('EduVisual render error in lesson_view:', err);
                        initialized = false;
                    }
                }
            };

            // التهيئة عند النقر على التبويب
            document.querySelectorAll('.tab-btn, .main-tab-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    if (btn.getAttribute('data-tab') === 'mind-maps') {
                        setTimeout(window.initMindMapsVisuals, 50);
                    }
                });
            });

            // إذا كان التبويب مفتوحاً بالفعل عند تحميل الصفحة
            const mindTab = document.getElementById('mind-maps');
            if (mindTab && mindTab.classList.contains('active')) {
                setTimeout(window.initMindMapsVisuals, 100);
            }
        })();
        <?php endif; ?>


    </script>
    <script src="../assets/js/lesson-export.js?v=2"></script>
</body>
</html>
