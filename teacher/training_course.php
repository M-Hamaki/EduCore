<?php
/**
 * عارض الدورة التدريبية - Teacher Training Course Viewer
 * عرض وحدات الدورة، المحتوى، الاختبارات
 * تصميم موحد مع بوابة المعلم
 */
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/utilities.php';
require_once '../classes/Training.php';
require_once '../includes/template_helper.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('teacher');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

$training = new Training($db);
$teacherId = $_SESSION['user_id'];
$courseId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$activeUnit = isset($_GET['unit']) ? intval($_GET['unit']) : null;
$takeQuiz = isset($_GET['quiz']) && $_GET['quiz'] == '1';

// PRG pattern (MANDATORY per AGENTS.md): نقرأ الرسائل من الجلسة ثم نمسحها.
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Get course
$course = $training->getCourse($courseId);
if (!$course) {
    header('Location: training.php');
    exit;
}

// Check enrollment
$enrollment = $training->getEnrollment($teacherId, $courseId);
if (!$enrollment) {
    header('Location: training.php');
    exit;
}

// Get units
$units = $training->getUnits($courseId, true);
$lang = $course['display_language'] ?? 'ar';
$dir = Training::getDirection($lang);
$textAlign = Training::getTextAlign($lang);
$courseTitle = Training::getLocalizedValue($course, 'title', $lang);
$courseDesc = Training::getLocalizedValue($course, 'description', $lang);

// Get current unit
$currentUnit = null;
if ($activeUnit) {
    $currentUnit = $training->getUnit($activeUnit);
}

// Handle quiz submission — PRG pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    try {
        $quizUnitId = intval($_POST['unit_id']);
        $answers = $_POST['answers'] ?? [];

        $attemptId = $training->createAttempt($teacherId, $quizUnitId);
        $result = $training->submitAttempt($attemptId, $answers, $quizUnitId);

        if ($result['passed']) {
            $training->markUnitCompleted($teacherId, $quizUnitId);
            $training->updateEnrollmentProgress($teacherId, $courseId);

            $enrollment = $training->getEnrollment($teacherId, $courseId);
            if ($enrollment['status'] === 'completed') {
                $existCert = $training->getCertificate($teacherId, $courseId);
                if (!$existCert) {
                    // score=null يقرأ الدرجة الفعلية من training_enrollments.score
                    // (التي حُسبت في updateEnrollmentProgress) بدل تمرير قيمة ثابتة خادعة.
                    $training->issueCertificate($teacherId, $courseId, null);
                }
            }
            $_SESSION['success_message'] = "تهانينا! اجتزت الاختبار بنجاح بنتيجة " . round($result['score']) . "%";
        } else {
            $_SESSION['error_message'] = "لم تجتز الاختبار. النتيجة: " . round($result['score']) . "%. المطلوب: " . $course['passing_score'] . "%. يمكنك المحاولة مرة أخرى.";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
    }
    header('Location: training_course.php?id=' . $courseId . ($quizUnitId ? '&unit=' . $quizUnitId : ''));
    exit();
}

// Handle mark as complete — PRG pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_complete'])) {
    $unitIdToMark = intval($_POST['unit_id']);
    $training->markUnitCompleted($teacherId, $unitIdToMark);
    $training->updateEnrollmentProgress($teacherId, $courseId);
    $enrollment = $training->getEnrollment($teacherId, $courseId);

    if ($enrollment['status'] === 'completed') {
        $existCert = $training->getCertificate($teacherId, $courseId);
        if (!$existCert) {
            // score=null بدل 100 الثابتة — تقرأ الدرجة الفعلية أو null للدورات بدون اختبارات.
            $training->issueCertificate($teacherId, $courseId, null);
        }
        $_SESSION['success_message'] = "تهانينا! أكملت الدورة بنجاح! 🎉";
    } else {
        $_SESSION['success_message'] = "تم تسجيل إكمال الوحدة بنجاح.";
    }
    header('Location: training_course.php?id=' . $courseId . '&unit=' . $unitIdToMark);
    exit();
}

// Mark unit as started when viewing — فقط إذا لم يبدأ أو يكتمل بعد (تحسين الأداء، المشكلة #12).
if ($currentUnit) {
    $existingProgress = $training->getUnitProgress($teacherId, $activeUnit);
    if (!$existingProgress || !in_array($existingProgress['status'], ['in_progress', 'completed'], true)) {
        $training->markUnitStarted($teacherId, $activeUnit);
    }
    // حدّث تقدّم التسجيل فقط عند تغيّر حالة الوحدة (لا في كل مشاهدة) لتقليل الكتابات.
    $training->updateEnrollmentProgress($teacherId, $courseId);
}

$enrollment = $training->getEnrollment($teacherId, $courseId);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($courseTitle); ?> - التدريب المهني</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/training-common.css">
    <style>
        /* ===== training_course.php — Page-Specific Styles ===== */

        /* Course Header Overrides */
        .course-header .course-meta {
            display: flex; justify-content: center; gap: 0.8rem;
            flex-wrap: wrap; margin-top: 0.8rem;
        }
        .course-header .meta-badge {
            background: linear-gradient(135deg, rgba(13,110,253,0.07), rgba(13,110,253,0.02));
            border: 1px solid rgba(13,110,253,0.12);
            color: #1e293b; padding: 6px 18px; border-radius: 50px;
            font-weight: 600; font-size: 0.9rem; transition: all 0.3s;
        }
        .course-header .meta-badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(13,110,253,0.1);
        }
        body.dark-mode .course-header .meta-badge {
            background: linear-gradient(135deg, rgba(96,165,250,0.12), rgba(96,165,250,0.04));
            border-color: rgba(96,165,250,0.2); color: #e2e8f0;
        }

        /* Course Progress Bar */
        .course-progress-bar { max-width: 400px; margin: 1rem auto 0; }
        .course-progress-bar .progress { height: 12px; border-radius: 10px; background: #e2e8f0; }
        body.dark-mode .course-progress-bar .progress { background: #475569; }
        .course-progress-bar .progress-bar {
            background: linear-gradient(90deg, #0d6efd, #3d8bfd);
            border-radius: 10px; transition: width 0.5s ease;
        }
        .course-progress-bar small { color: #64748b; font-weight: 600; font-size: 0.9rem; }
        body.dark-mode .course-progress-bar small { color: #94a3b8; }

        /* Portal Card animation (page-specific) */
        .portal-card { animation: fadeInUp 0.5s ease; }

        /* Unit Sidebar */
        .unit-sidebar { position: sticky; top: 20px; animation: fadeInUp 0.5s ease 0.1s backwards; }
        .unit-list-item {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-right: 3px solid transparent !important;
            border-left: none !important; border-top: none !important;
            border-bottom: 1px solid rgba(0,0,0,0.04) !important;
            background: transparent; padding: 12px 16px;
        }
        body.dark-mode .unit-list-item { border-bottom-color: #334155 !important; color: #e2e8f0; }
        .unit-list-item:hover { background-color: rgba(13,110,253,0.04); }
        body.dark-mode .unit-list-item:hover { background-color: rgba(96,165,250,0.08); }
        .unit-list-item.active { border-right-color: #0d6efd !important; background-color: rgba(13,110,253,0.06); }
        body.dark-mode .unit-list-item.active { background-color: rgba(13,110,253,0.12); }
        .unit-list-item.completed { border-right-color: #198754 !important; }
        .unit-list-item .fa-check-circle { transition: transform 0.3s; }
        .unit-list-item:hover .fa-check-circle { transform: scale(1.15); }

        /* Quiz */
        .quiz-option {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer;
            border: 2px solid #e9ecef !important; border-radius: 14px;
        }
        body.dark-mode .quiz-option { border-color: #475569 !important; }
        .quiz-option:hover {
            border-color: #0d6efd !important;
            background-color: rgba(13,110,253,0.05); transform: translateY(-1px);
        }
        body.dark-mode .quiz-option:hover { border-color: #60a5fa !important; background-color: rgba(96,165,250,0.1); }
        .quiz-option:has(input:checked) {
            border-color: #0d6efd !important;
            background-color: rgba(13,110,253,0.08);
            box-shadow: 0 2px 8px rgba(13,110,253,0.1);
        }
        body.dark-mode .quiz-option:has(input:checked) { border-color: #60a5fa !important; background-color: rgba(96,165,250,0.15); }

        /* Content Area */
        .content-area { min-height: 400px; }
        .content-area h3, .content-area h4 { color: #333; margin-top: 1.5rem; margin-bottom: 1rem; }
        body.dark-mode .content-area h3, body.dark-mode .content-area h4 { color: #e2e8f0; }
        .content-area ul, .content-area ol { padding-right: 1.5rem; }
        .content-area li { margin-bottom: 0.5rem; }
        .video-container {
            position: relative; padding-bottom: 56.25%; height: 0;
            overflow: hidden; border-radius: 0.5rem; margin-bottom: 1.5rem;
        }
        .video-container iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none; }

        /* Overview Stats */
        .overview-stat {
            border: 2px solid rgba(13,110,253,0.1); border-radius: 18px; padding: 1.4rem;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            text-align: center; background: rgba(13,110,253,0.02);
        }
        body.dark-mode .overview-stat { border-color: rgba(96,165,250,0.15); background: rgba(96,165,250,0.04); }
        .overview-stat:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(13,110,253,0.1);
            border-color: rgba(13,110,253,0.2);
        }
        .overview-stat i { transition: transform 0.3s; }
        .overview-stat:hover i { transform: scale(1.15); }
        body.dark-mode .overview-stat h5 { color: #f1f5f9; }

        /* Overview stat icon colors */
        .overview-stat .fa-list { color: #8b5cf6; }
        .overview-stat .fa-clock { color: #f97316; }
        .overview-stat .fa-check-circle { color: #10b981; }
        .overview-stat .fa-signal { color: #0ea5e9; }
        body.dark-mode .overview-stat .fa-list { color: #a78bfa; }
        body.dark-mode .overview-stat .fa-clock { color: #fb923c; }
        body.dark-mode .overview-stat .fa-check-circle { color: #34d399; }
        body.dark-mode .overview-stat .fa-signal { color: #38bdf8; }

        /* Page-specific icon colors */
        .breadcrumb-nav .fa-home { color: #f97316; }
        .breadcrumb-nav .fa-graduation-cap { color: #8b5cf6; }
        .breadcrumb-nav .fa-book-reader { color: #6366f1; }
        .meta-badge .fa-folder { color: #8b5cf6; }
        .meta-badge .fa-clock { color: #f97316; }
        .meta-badge .fa-check { color: #10b981; }
        .unit-list-item .fa-check-circle { color: #10b981; }
        .fa-question-circle { color: #f59e0b; }
        .fa-play { color: #10b981; }
        .fa-external-link-alt { color: #0ea5e9; }
        .fa-file-download { color: #8b5cf6; }
        /* Dark mode icon colors */
        body.dark-mode .breadcrumb-nav .fa-home { color: #fb923c; }
        body.dark-mode .breadcrumb-nav .fa-graduation-cap { color: #a78bfa; }
        body.dark-mode .breadcrumb-nav .fa-book-reader { color: #818cf8; }
        body.dark-mode .meta-badge .fa-folder { color: #a78bfa; }
        body.dark-mode .meta-badge .fa-clock { color: #fb923c; }
        body.dark-mode .meta-badge .fa-check { color: #34d399; }
        body.dark-mode .unit-list-item .fa-check-circle { color: #34d399; }
        body.dark-mode .fa-question-circle { color: #fbbf24; }
        body.dark-mode .fa-play { color: #34d399; }
        body.dark-mode .fa-external-link-alt { color: #38bdf8; }
        body.dark-mode .fa-file-download { color: #a78bfa; }

        /* Breadcrumb Nav */
        .breadcrumb-nav {
            display: flex; justify-content: center; gap: 10px;
            flex-wrap: wrap; margin-bottom: 1.5rem; animation: slideDown 0.4s ease;
        }
        .breadcrumb-nav a {
            color: #0d6efd; text-decoration: none;
            padding: 7px 18px; border-radius: 50px;
            background: rgba(13,110,253,0.08); font-weight: 600;
            font-size: 0.88rem; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(13,110,253,0.12);
        }
        .breadcrumb-nav a:hover {
            background: rgba(13,110,253,0.15); color: #0b5ed7;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(13,110,253,0.1);
        }
        body.dark-mode .breadcrumb-nav a {
            color: #93c5fd; background: rgba(96,165,250,0.1);
            border-color: rgba(96,165,250,0.2);
        }
        body.dark-mode .breadcrumb-nav a:hover { background: rgba(96,165,250,0.18); }

        /* Dark mode – content html */
        body.dark-mode .content-html { color: #e2e8f0; }
        body.dark-mode .content-html h1, body.dark-mode .content-html h2,
        body.dark-mode .content-html h3, body.dark-mode .content-html h4,
        body.dark-mode .content-html h5, body.dark-mode .content-html h6 { color: #f1f5f9; }

        @media (max-width: 768px) {
            .overview-stat { padding: 1rem; }
            .breadcrumb-nav a { padding: 5px 14px; font-size: 0.82rem; }
            .unit-sidebar {
                position: static !important;
                order: 2;
            }
            .unit-sidebar .list-group {
                max-height: 200px !important;
            }
            .unit-sidebar .portal-card {
                margin-bottom: 15px;
            }
            .col-lg-8.col-xl-9 {
                order: 1;
            }
            .course-header .meta-badge {
                padding: 5px 12px;
                font-size: 0.8rem;
            }
            .card-header .d-flex.justify-content-between {
                flex-wrap: wrap;
                gap: 8px;
            }
            .d-flex.justify-content-between:not(.card-progress-text) {
                flex-wrap: wrap;
                gap: 6px;
            }
            .content-html {
                overflow-x: auto;
                word-break: break-word;
            }
            .content-html img {
                max-width: 100% !important;
                height: auto !important;
            }
            .content-html table {
                display: block;
                overflow-x: auto;
                max-width: 100%;
            }
            .content-html pre {
                overflow-x: auto;
                max-width: 100%;
                white-space: pre-wrap;
                word-break: break-word;
            }
            .quiz-option { padding: 8px !important; }
            .quiz-option .form-check { padding: 6px 8px; }
            .quiz-option .form-check-label { font-size: 0.9rem; }
            .btn-lg { padding: 10px 20px !important; font-size: 0.95rem !important; }
        }

        @media (max-width: 480px) {
            .overview-stat { 
                padding: 0.8rem; 
                font-size: 0.85rem;
            }
            .overview-stat h5 {
                font-size: 0.9rem !important;
            }
            .breadcrumb-nav { 
                gap: 6px; 
            }
            .breadcrumb-nav a { 
                padding: 6px 12px; 
                font-size: 0.78rem;
                min-height: 36px;
                display: inline-flex;
                align-items: center;
            }
            .unit-sidebar .list-group {
                max-height: 150px !important;
            }
            .content-html {
                font-size: 0.92rem;
            }
            .portal-card .card-body {
                padding: 12px !important;
            }
            .course-header .meta-badge {
                padding: 4px 10px;
                font-size: 0.75rem;
            }
            .card-header {
                padding: 10px !important;
            }
            .card-header h5 {
                font-size: 0.95rem !important;
            }
            .card-footer {
                padding: 10px !important;
            }
            .d-flex.gap-2 {
                flex-wrap: wrap;
            }
            .btn-lg { padding: 8px 16px !important; font-size: 0.88rem !important; }
            .overview-stat:hover { transform: none; }
        }
    </style>
</head>
<body>
    <div id="particles-js"></div>

    <!-- Back Button - Top Left -->
    <div class="back-button-container">
        <a href="training.php" class="back-button-top">
            <i class="fas fa-arrow-right"></i>
            <span>العودة للتدريب</span>
        </a>
    </div>

    <!-- Theme Toggle - Top Right -->
    <div class="theme-toggle-container">
        <button class="theme-toggle" id="themeToggle" title="تبديل الوضع">
            <i class="fas fa-moon"></i>
        </button>
    </div>

    <!-- Course Header -->
    <div class="course-header">
        <div class="header-card">
        <div class="breadcrumb-nav">
            <a href="portal.php"><i class="fas fa-home me-1"></i> البوابة</a>
            <a href="training.php"><i class="fas fa-graduation-cap me-1"></i> التدريب</a>
            <a href="training_my.php">📖 دوراتي</a>
        </div>
        <h1 dir="<?php echo $dir; ?>">
            <i class="fas <?php echo $course['program_icon']; ?> me-2"></i>
            <?php echo htmlspecialchars($courseTitle); ?>
        </h1>
        <div class="course-meta">
            <span class="meta-badge"><i class="fas fa-folder me-1"></i><?php echo htmlspecialchars($course['program_name']); ?></span>
            <span class="meta-badge"><?php echo Training::getDifficultyLabel($course['difficulty'], $lang); ?></span>
            <span class="meta-badge"><i class="fas fa-clock me-1"></i><?php echo $course['estimated_hours']; ?> ساعة</span>
            <?php echo Training::getLanguageBadge($lang); ?>
            <?php if ($enrollment['status'] === 'completed'): ?>
                <span class="meta-badge" style="background: rgba(25,135,84,0.15); color: #059669; border-color: rgba(25,135,84,0.3);"><i class="fas fa-check me-1"></i>مكتمل</span>
            <?php endif; ?>
        </div>
        <div class="course-progress-bar">
            <div class="progress mt-3">
                <div class="progress-bar" style="width: <?php echo round($enrollment['progress_percent']); ?>%"></div>
            </div>
            <small>التقدم: <?php echo round($enrollment['progress_percent']); ?>%</small>
        </div>
        </div>
    </div>

    <div class="course-container">
        <!-- Alerts -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <!-- Units Sidebar -->
            <div class="col-lg-4 col-xl-3">
                <div class="unit-sidebar">
                    <div class="portal-card">
                        <div class="card-header bg-transparent p-3">
                            <h6 class="mb-0"><i class="fas fa-list me-2"></i>وحدات الدورة (<?php echo count($units); ?>)</h6>
                        </div>
                        <div class="list-group list-group-flush" style="max-height: 500px; overflow-y: auto;">
                            <?php foreach ($units as $i => $unit): 
                                $progress = $training->getUnitProgress($teacherId, $unit['id']);
                                $isCompleted = ($progress && $progress['status'] === 'completed');
                                $isActive = ($activeUnit == $unit['id']);
                            ?>
                                <a href="training_course.php?id=<?php echo $courseId; ?>&unit=<?php echo $unit['id']; ?>" 
                                   class="list-group-item list-group-item-action unit-list-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $isCompleted ? 'completed' : ''; ?>">
                                    <div class="d-flex align-items-center">
                                        <div class="me-2">
                                            <?php if ($isCompleted): ?>
                                                <i class="fas fa-check-circle text-success"></i>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark rounded-circle" style="width:24px;height:24px;line-height:16px;"><?php echo $i + 1; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold small" dir="<?php echo $dir; ?>"><?php echo htmlspecialchars(Training::getLocalizedValue($unit, 'title', $lang)); ?></div>
                                            <small class="text-muted">
                                                <i class="fas <?php echo Training::getUnitTypeIcon($unit['unit_type']); ?> me-1"></i>
                                                <?php echo Training::getUnitTypeLabel($unit['unit_type'], $lang); ?>
                                                · <?php echo $unit['duration_minutes']; ?> دقيقة
                                                <?php if ($unit['has_assessment']): ?>
                                                    · <i class="fas fa-question-circle"></i>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Content Area -->
            <div class="col-lg-8 col-xl-9">
                <?php if (!$currentUnit): ?>
                    <!-- Course Overview -->
                    <div class="portal-card">
                        <div class="card-body content-area py-4 px-4">
                            <h4><i class="fas fa-info-circle me-2 text-primary"></i><?php echo $lang === 'en' ? 'Course Overview' : 'نظرة عامة على الدورة'; ?></h4>
                            <p class="text-muted" dir="<?php echo $dir; ?>"><?php echo nl2br(htmlspecialchars($courseDesc ?: ($lang === 'en' ? 'No description available' : 'لا يوجد وصف للدورة'))); ?></p>
                            
                            <hr>
                            
                            <div class="row g-3 text-center mb-4">
                                <div class="col-md-3 col-6">
                                    <div class="overview-stat">
                                        <i class="fas fa-list fa-lg mb-2 d-block"></i>
                                        <h5 class="mb-0"><?php echo count($units); ?></h5>
                                        <small class="text-muted"><?php echo $lang === 'en' ? 'Units' : 'وحدة تدريبية'; ?></small>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6">
                                    <div class="overview-stat">
                                        <i class="fas fa-clock fa-lg mb-2 d-block"></i>
                                        <h5 class="mb-0"><?php echo $course['estimated_hours']; ?></h5>
                                        <small class="text-muted"><?php echo $lang === 'en' ? 'Est. Hours' : 'ساعة تقديرية'; ?></small>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6">
                                    <div class="overview-stat">
                                        <i class="fas fa-check-circle fa-lg mb-2 d-block"></i>
                                        <h5 class="mb-0"><?php echo $course['passing_score']; ?>%</h5>
                                        <small class="text-muted"><?php echo $lang === 'en' ? 'Passing Score' : 'درجة النجاح'; ?></small>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6">
                                    <div class="overview-stat">
                                        <i class="fas fa-signal fa-lg mb-2 d-block"></i>
                                        <h5 class="mb-0"><?php echo Training::getDifficultyLabel($course['difficulty'], $lang); ?></h5>
                                        <small class="text-muted"><?php echo $lang === 'en' ? 'Level' : 'المستوى'; ?></small>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($units)): ?>
                                <a href="training_course.php?id=<?php echo $courseId; ?>&unit=<?php echo $units[0]['id']; ?>" class="btn btn-primary btn-lg">
                                    <i class="fas fa-play me-2"></i>ابدأ التدريب
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                <?php elseif ($takeQuiz && $currentUnit['has_assessment']): ?>
                    <!-- Quiz Mode -->
                    <?php 
                    $questions = $training->getQuestions($activeUnit);
                    $bestAttempt = $training->getBestAttempt($teacherId, $activeUnit);
                    ?>
                    <div class="portal-card">
                        <div class="card-header bg-transparent p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0" dir="<?php echo $dir; ?>"><i class="fas fa-question-circle me-2 text-primary"></i><?php echo $lang === 'en' ? 'Quiz: ' : 'اختبار: '; ?><?php echo htmlspecialchars(Training::getLocalizedValue($currentUnit, 'title', $lang)); ?></h5>
                                <span class="badge bg-info"><?php echo count($questions); ?> <?php echo $lang === 'en' ? 'questions' : 'سؤال'; ?></span>
                            </div>
                            <?php if ($bestAttempt): ?>
                                <small class="text-muted">أفضل محاولة سابقة: <?php echo round($bestAttempt['score']); ?>%
                                    <?php echo $bestAttempt['passed'] ? '✅' : '❌'; ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-4">
<form method="POST">
    <?php echo csrfField(); ?>
                                <input type="hidden" name="submit_quiz" value="1">
                                <input type="hidden" name="unit_id" value="<?php echo $activeUnit; ?>">
                                
                                <?php foreach ($questions as $qi => $q): ?>
                                    <div class="mb-4 p-3 border rounded-3">
                                        <h6 class="mb-3" dir="<?php echo $dir; ?>">
                                            <span class="badge bg-primary rounded-circle me-2"><?php echo $qi + 1; ?></span>
                                            <?php echo htmlspecialchars(Training::getLocalizedValue($q, 'question_text', $lang)); ?>
                                        </h6>
                                        <div class="row g-2">
                                            <?php foreach (['a', 'b', 'c', 'd'] as $opt):
                                                $optKey = 'option_' . $opt;
                                                $optValue = Training::getLocalizedValue($q, $optKey, $lang);
                                                if (empty($optValue)) continue;
                                            ?>
                                                <div class="col-md-6">
                                                    <div class="quiz-option p-2">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" 
                                                                   name="answers[<?php echo $q['id']; ?>]" 
                                                                   value="<?php echo $opt; ?>" 
                                                                   id="q<?php echo $q['id']; ?>_<?php echo $opt; ?>" required>
                                                            <label class="form-check-label w-100" for="q<?php echo $q['id']; ?>_<?php echo $opt; ?>">
                                                                <strong><?php echo strtoupper($opt); ?>.</strong> 
                                                                <?php echo htmlspecialchars($optValue); ?>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <div class="d-flex justify-content-between">
                                    <a href="training_course.php?id=<?php echo $courseId; ?>&unit=<?php echo $activeUnit; ?>" class="btn btn-secondary">
                                        <i class="fas fa-arrow-right me-1"></i> العودة للمحتوى
                                    </a>
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-paper-plane me-1"></i> إرسال الإجابات
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- Unit Content -->
                    <div class="portal-card">
                        <div class="card-header bg-transparent p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="badge bg-light text-dark me-2">
                                        <i class="fas <?php echo Training::getUnitTypeIcon($currentUnit['unit_type']); ?> me-1"></i>
                                        <?php echo Training::getUnitTypeLabel($currentUnit['unit_type'], $lang); ?>
                                    </span>
                                    <h5 class="d-inline mb-0" dir="<?php echo $dir; ?>"><?php echo htmlspecialchars(Training::getLocalizedValue($currentUnit, 'title', $lang)); ?></h5>
                                </div>
                                <span class="badge bg-light text-muted"><i class="fas fa-clock me-1"></i><?php echo $currentUnit['duration_minutes']; ?> دقيقة</span>
                            </div>
                        </div>
                        <div class="card-body content-area p-4" dir="<?php echo $dir; ?>" style="text-align: <?php echo $textAlign; ?>">
                            <?php 
                            $unitDesc = Training::getLocalizedValue($currentUnit, 'description', $lang);
                            if ($unitDesc): ?>
                                <p class="text-muted border-bottom pb-3 mb-3"><?php echo htmlspecialchars($unitDesc); ?></p>
                            <?php endif; ?>
                            
                            <?php if (!empty($currentUnit['video_url'])): 
                                $videoUrl = $currentUnit['video_url'];
                                if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $videoUrl, $matches)) {
                                    $embedUrl = 'https://www.youtube.com/embed/' . $matches[1] . '?rel=0&modestbranding=1';
                                } elseif (preg_match('/vimeo\.com\/(\d+)/', $videoUrl, $matches)) {
                                    $embedUrl = 'https://player.vimeo.com/video/' . $matches[1];
                                } else {
                                    $embedUrl = $videoUrl;
                                }
                            ?>
                                <div class="video-container mb-4">
                                    <iframe src="<?php echo htmlspecialchars($embedUrl); ?>" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($currentUnit['unit_type'] === 'link' && $currentUnit['external_link']): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-external-link-alt me-2"></i>
                                    <a href="<?php echo htmlspecialchars($currentUnit['external_link']); ?>" target="_blank" class="alert-link">انقر هنا لفتح الرابط الخارجي</a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($currentUnit['unit_type'] === 'file' && $currentUnit['file_path']): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-file-download me-2"></i>
                                    <a href="<?php echo htmlspecialchars($currentUnit['file_path']); ?>" target="_blank" class="alert-link">تحميل الملف المرفق</a>
                                </div>
                            <?php endif; ?>
                            
                            <?php 
                            $unitContent = Training::getLocalizedValue($currentUnit, 'content', $lang);
                            if ($unitContent): ?>
                                <div class="content-html"><?php echo $unitContent; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-transparent p-3">
                            <?php $unitProgress = $training->getUnitProgress($teacherId, $activeUnit); ?>
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <?php if ($unitProgress && $unitProgress['status'] === 'completed'): ?>
                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>مكتملة</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php if ($currentUnit['has_assessment']): ?>
                                        <a href="training_course.php?id=<?php echo $courseId; ?>&unit=<?php echo $activeUnit; ?>&quiz=1" class="btn btn-primary">
                                            <i class="fas fa-question-circle me-1"></i> بدء الاختبار
                                        </a>
                                    <?php else: ?>
                                        <?php if (!$unitProgress || $unitProgress['status'] !== 'completed'): ?>
<form method="POST" class="d-inline">
    <?php echo csrfField(); ?>
                                                <input type="hidden" name="mark_complete" value="1">
                                                <input type="hidden" name="unit_id" value="<?php echo $activeUnit; ?>">
                                                <button type="submit" class="btn btn-success">
                                                    <i class="fas fa-check me-1"></i> تم الانتهاء
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $nextUnit = null;
                                    foreach ($units as $ui => $u) {
                                        if ($u['id'] == $activeUnit && isset($units[$ui + 1])) {
                                            $nextUnit = $units[$ui + 1];
                                            break;
                                        }
                                    }
                                    if ($nextUnit): ?>
                                        <a href="training_course.php?id=<?php echo $courseId; ?>&unit=<?php echo $nextUnit['id']; ?>" class="btn btn-primary">
                                            الوحدة التالية <i class="fas fa-arrow-left ms-1"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="portal-footer">
        <div class="container text-center">
            <p style="margin: 0.5rem 0; line-height: 1.6;">
                <strong>جميع الحقوق محفوظة © <?php echo date('Y'); ?></strong>
            </p>
            <p style="margin: 0.5rem 0; line-height: 1.6;">
                Delta Modern Language Schools<br>
                Computer Department
            </p>
            
            <!-- Social Media Icons in Footer -->
            <div class="social-media-footer">
                <a href="https://www.facebook.com/DELTA.MLS" target="_blank" class="social-footer-icon facebook" title="صفحتنا على الفيسبوك">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <a href="https://wa.me/201289999818" target="_blank" class="social-footer-icon whatsapp" title="الدعم الفني - واتساب">
                    <i class="fab fa-whatsapp"></i>
                </a>
                <a href="https://www.instagram.com/delta.mls" target="_blank" class="social-footer-icon instagram" title="حسابنا على انستجرام">
                    <i class="fab fa-instagram"></i>
                </a>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="script.js?v=1.2"></script>
    <script>
        (function() {
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                document.body.classList.remove('light-mode');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                document.body.classList.remove('dark-mode');
                document.body.classList.add('light-mode');
                themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            }
            if (typeof updateParticlesTheme === 'function') updateParticlesTheme(savedTheme || 'light');
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                if (document.body.classList.contains('dark-mode')) {
                    document.body.classList.remove('light-mode');
                    localStorage.setItem('theme', 'dark');
                    themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                    if (typeof updateParticlesTheme === 'function') updateParticlesTheme('dark');
                } else {
                    document.body.classList.add('light-mode');
                    localStorage.setItem('theme', 'light');
                    themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                    if (typeof updateParticlesTheme === 'function') updateParticlesTheme('light');
                }
            });
        })();
    </script>
</body>
</html>
