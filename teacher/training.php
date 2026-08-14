<?php
/**
 * كتالوج التدريب - Teacher Training Catalog
 * استعراض البرامج والدورات المتاحة والتسجيل فيها
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

// Handle enrollment — PRG pattern (MANDATORY per AGENTS.md)
// نُخزّل الرسائل في $_SESSION ثم نُوجّه لتفادي إعادة إرسال POST عند التحديث.
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_course'])) {
    try {
        $courseId = intval($_POST['course_id']);
        $course = $training->getCourse($courseId);
        if (!$course || !$course['is_active']) {
            throw new Exception('الدورة غير متاحة');
        }
        $existing = $training->getEnrollment($teacherId, $courseId);
        if ($existing) {
            $_SESSION['error_message'] = "أنت مسجل بالفعل في هذه الدورة.";
        } else {
            $training->enrollTeacher($teacherId, $courseId);
            $_SESSION['success_message'] = "تم التسجيل في الدورة بنجاح! يمكنك البدء الآن.";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
    }
    // إعادة التوجيه لتفادي إعادة الإرسال (PRG). نحافظ على فلتر البرنامج إن وُجد.
    $redirectUrl = 'training.php';
    if (!empty($_GET['program'])) {
        $redirectUrl .= '?program=' . intval($_GET['program']);
    }
    header('Location: ' . $redirectUrl);
    exit();
}

$programs = $training->getPrograms(true);
$stats = $training->getTeacherStats($teacherId);
$selectedProgram = isset($_GET['program']) ? intval($_GET['program']) : null;

$courses = $training->getCourses($selectedProgram, true);
$enrollments = $training->getTeacherEnrollments($teacherId);
$enrolledIds = array_column($enrollments, 'course_id');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التدريب والتطوير المهني</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/training-common.css">
    <style>
        /* ===== training.php — Page-Specific Styles ===== */

        /* Quick Nav */
        /* Program Filter — Responsive Grid */
        .program-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            padding: 10px 4px;
            margin-bottom: 2rem;
            animation: fadeInUp 0.6s ease 0.15s backwards;
        }
        .program-filter-card {
            background: rgba(255,255,255,0.95); border-radius: 18px; padding: 20px 18px;
            text-align: center; text-decoration: none;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 18px rgba(0,0,0,0.05);
            border: 2px solid transparent;
            backdrop-filter: blur(10px);
        }
        .program-filter-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .program-filter-card.active {
            border-color: #0d6efd;
            background: linear-gradient(135deg, rgba(13,110,253,0.05), rgba(255,255,255,0.98));
            box-shadow: 0 10px 30px rgba(13,110,253,0.2);
        }
        .program-filter-card i { font-size: 1.7rem; margin-bottom: 10px; display: block; transition: transform 0.3s; }
        .program-filter-card:hover i { transform: scale(1.15); }
        .program-filter-card .pf-name { font-weight: 700; font-size: 0.85rem; color: #1e293b; display: block; line-height: 1.5; }
        .program-filter-card .pf-count { font-size: 0.75rem; color: #94a3b8; margin-top: 3px; display: block; }
        body.dark-mode .program-filter-card { background: rgba(30,41,59,0.9); border-color: #334155; }
        body.dark-mode .program-filter-card .pf-name { color: #f1f5f9; }
        body.dark-mode .program-filter-card.active { border-color: #60a5fa; background: rgba(96,165,250,0.08); }
        body.dark-mode .program-filter-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.3); }

        /* Section Title */
        .section-title {
            color: #0d6efd; font-weight: 800; font-size: 1.35rem; margin-bottom: 1.5rem;
            padding: 12px 28px; border-radius: 50px;
            display: inline-flex; align-items: center; gap: 10px;
            background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 2px solid rgba(13,110,253,0.08);
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            animation: fadeIn 0.5s ease; transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .section-title:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 25px rgba(13,110,253,0.2);
            border-color: #0d6efd; background: white;
        }
        .section-title i { font-size: 1.1rem; }
        body.dark-mode .section-title {
            color: #93c5fd; background: rgba(30,41,59,0.9);
            border-color: rgba(71,85,105,0.5);
        }
        body.dark-mode .section-title:hover {
            background: rgba(30,41,59,1); border-color: #60a5fa;
            box-shadow: 0 8px 25px rgba(96,165,250,0.2);
        }

        /* Page-specific icon colors */
        .section-title .fa-layer-group { color: #f59e0b; }
        .section-title .fa-book { color: #0d6efd; }
        .course-info-pill .fa-list { color: #8b5cf6; }
        .course-info-pill .fa-clock { color: #f97316; }
        .course-info-pill .fa-check-circle { color: #10b981; }
        /* Dark mode icon colors */
        body.dark-mode .section-title .fa-layer-group { color: #fbbf24; }
        body.dark-mode .section-title .fa-book { color: #60a5fa; }
        body.dark-mode .course-info-pill .fa-list { color: #a78bfa; }
        body.dark-mode .course-info-pill .fa-clock { color: #fb923c; }
        body.dark-mode .course-info-pill .fa-check-circle { color: #34d399; }

        @media (max-width: 768px) {
            .section-title { font-size: 1.15rem; padding: 10px 18px; }
            .program-filter-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 10px;
            }
            .program-filter-card { padding: 15px 12px; }
        }
        @media (max-width: 576px) {
            .section-title {
                font-size: 1.05rem;
                padding: 9px 16px;
                border-radius: 14px;
                display: flex;
                width: fit-content;
            }
            .section-title i { font-size: 0.95rem; }
            .program-filter-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
                margin-bottom: 1.5rem;
                padding: 8px 2px;
            }
            .program-filter-card {
                padding: 12px 8px;
                border-radius: 14px;
            }
            .program-filter-card i { font-size: 1.4rem; margin-bottom: 8px; }
            .program-filter-card .pf-name { font-size: 0.76rem; }
            .program-filter-card .pf-count { font-size: 0.7rem; }
        }
        @media (max-width: 480px) {
            .section-title {
                font-size: 0.95rem;
                padding: 8px 14px;
                gap: 6px;
                border-radius: 12px;
                margin-bottom: 1rem;
            }
            .section-title i { font-size: 0.85rem; }
            .program-filter-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 6px;
                margin-bottom: 1rem;
                padding: 6px 0;
            }
            .program-filter-card {
                padding: 10px 6px;
                border-radius: 12px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            }
            .program-filter-card i { font-size: 1.2rem; margin-bottom: 5px; }
            .program-filter-card .pf-name { font-size: 0.72rem; }
            .program-filter-card .pf-count { font-size: 0.65rem; }
            .program-filter-card:hover { transform: none; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
        }
    </style>
</head>
<body>
    <div id="particles-js"></div>

    <!-- Back Button - Top Left -->
    <div class="back-button-container">
        <a href="portal.php" class="back-button-top">
            <i class="fas fa-arrow-right"></i>
            <span>العودة للبوابة</span>
        </a>
    </div>

    <!-- Theme Toggle - Top Right -->
    <div class="theme-toggle-container">
        <button class="theme-toggle" id="themeToggle" title="تبديل الوضع">
            <i class="fas fa-moon"></i>
        </button>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <div class="header-card">
            <h1>🎓 التدريب والتطوير المهني</h1>
            <p class="page-subtitle">طوّر مهاراتك المهنية من خلال برامجنا التدريبية المتنوعة</p>
            <!-- Stats Pills -->
            <div class="stats-bar">
                <div class="stat-pill"><i class="fas fa-book-open"></i><span><?php echo $stats['enrolled_courses']; ?> مسجل</span></div>
                <div class="stat-pill"><i class="fas fa-check-circle"></i><span><?php echo $stats['completed_courses']; ?> مكتمل</span></div>
                <div class="stat-pill"><i class="fas fa-certificate"></i><span><?php echo $stats['certificates']; ?> شهادة</span></div>
            </div>
        </div>
    </div>

    <div class="training-container">

        <!-- Quick Nav -->
        <div class="quick-nav">
            <a href="training.php" class="active">🎓 التدريبات</a>
            <a href="training_my.php">📖 دوراتي (<?php echo $stats['enrolled_courses']; ?>)</a>
            <a href="training_my.php?view=certificates">🏅 شهاداتي (<?php echo $stats['certificates']; ?>)</a>
        </div>

        <div class="page-content">
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

        <!-- Programs Filter -->
        <h5 class="section-title"><i class="fas fa-layer-group"></i> البرامج التدريبية</h5>
        <div class="program-filter-grid">
            <a href="training.php" class="program-filter-card <?php echo !$selectedProgram ? 'active' : ''; ?>">
                <i class="fas fa-th-large text-primary"></i>
                <span class="pf-name">جميع الدورات</span>
            </a>
            <?php foreach ($programs as $prog): ?>
                <a href="training.php?program=<?php echo $prog['id']; ?>" class="program-filter-card <?php echo $selectedProgram == $prog['id'] ? 'active' : ''; ?>">
                    <i class="fas <?php echo htmlspecialchars($prog['icon']); ?>" style="color: <?php echo $prog['color']; ?>"></i>
                    <span class="pf-name"><?php echo htmlspecialchars($prog['name']); ?></span>
                    <span class="pf-count"><?php echo $prog['course_count']; ?> دورة</span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Courses Grid -->
        <h5 class="section-title"><i class="fas fa-book"></i> الدورات المتاحة</h5>
        <div class="row g-4 mb-4">
            <?php if (empty($courses)): ?>
                <div class="col-12">
                    <div class="course-card card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-book fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">لا توجد دورات متاحة حاليًا</h5>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($courses as $course): 
                    $isEnrolled = in_array($course['id'], $enrolledIds);
                    $enrollment = null;
                    if ($isEnrolled) {
                        foreach ($enrollments as $e) {
                            if ($e['course_id'] == $course['id']) { $enrollment = $e; break; }
                        }
                    }
                    $lang = $course['display_language'] ?? 'ar';
                    $courseTitle = Training::getLocalizedValue($course, 'title', $lang);
                    $courseDesc = Training::getLocalizedValue($course, 'description', $lang);
                    $dir = Training::getDirection($lang);
                    $textAlign = Training::getTextAlign($lang);
                ?>
                    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-6">
                        <div class="card course-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <span class="badge" style="background-color: <?php echo $course['program_color']; ?>">
                                        <i class="fas <?php echo $course['program_icon']; ?> me-1"></i>
                                        <?php echo htmlspecialchars($course['program_name']); ?>
                                    </span>
                                    <div class="d-flex gap-1">
                                        <?php echo Training::getLanguageBadge($lang); ?>
                                        <span class="badge <?php echo Training::getDifficultyBadge($course['difficulty']); ?>">
                                            <?php echo Training::getDifficultyLabel($course['difficulty'], $lang); ?>
                                        </span>
                                    </div>
                                </div>
                                <h5 class="card-title" dir="<?php echo $dir; ?>" style="text-align: <?php echo $textAlign; ?>"><?php echo htmlspecialchars($courseTitle); ?></h5>
                                <p class="text-muted small mb-3" dir="<?php echo $dir; ?>" style="text-align: <?php echo $textAlign; ?>"><?php echo htmlspecialchars(mb_substr($courseDesc, 0, 120)); ?></p>
                                
                                <div class="course-info-row">
                                    <span class="course-info-pill"><i class="fas fa-list"></i> <?php echo $course['unit_count']; ?> وحدة</span>
                                    <span class="course-info-pill"><i class="fas fa-clock"></i> <?php echo $course['estimated_hours']; ?> ساعة</span>
                                    <span class="course-info-pill"><i class="fas fa-check-circle"></i> <?php echo $course['passing_score']; ?>%</span>
                                </div>
                                
                                <?php if ($course['is_mandatory']): ?>
                                    <span class="mandatory-badge"><i class="fas fa-exclamation-circle"></i> إلزامي</span>
                                <?php endif; ?>
                                
                                <?php if ($isEnrolled && $enrollment): ?>
                                <div class="card-progress-wrapper">
                                    <div class="card-progress-text">
                                        <span><i class="fas fa-chart-line me-1"></i>التقدم</span>
                                        <span><?php echo round($enrollment['progress_percent']); ?>%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: <?php echo round($enrollment['progress_percent']); ?>%"></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer">
                                <?php if ($isEnrolled): ?>
                                    <a href="training_course.php?id=<?php echo $course['id']; ?>" class="btn btn-primary w-100">
                                        <i class="fas fa-play me-1"></i>
                                        <?php echo ($enrollment && $enrollment['status'] === 'completed') ? 'مراجعة' : 'متابعة التدريب'; ?>
                                    </a>
                                <?php else: ?>
<form method="POST" class="d-inline w-100">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="enroll_course" value="1">
                                        <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="fas fa-user-plus me-1"></i> التسجيل في الدورة
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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
        // ===== SPA Tab Navigation =====
        (function() {
            function spaNavigate(url) {
                var content = document.querySelector('.page-content');
                var headerCard = document.querySelector('.header-card');
                var quickNav = document.querySelector('.quick-nav');
                if (!content) { window.location.href = url; return; }

                // Fade out
                content.classList.add('tab-exit');
                content.classList.remove('tab-loading');

                setTimeout(function() {
                    content.classList.remove('tab-exit');
                    content.classList.add('tab-loading');

                    fetch(url, { credentials: 'same-origin' })
                        .then(function(r) { return r.text(); })
                        .then(function(html) {
                            var parser = new DOMParser();
                            var doc = parser.parseFromString(html, 'text/html');

                            // Update title
                            document.title = doc.title;

                            // Swap page-specific styles
                            var oldSpaStyle = document.getElementById('spa-page-style');
                            if (oldSpaStyle) oldSpaStyle.remove();
                            var newStyles = doc.querySelectorAll('head style');
                            if (newStyles.length > 0) {
                                var spaStyle = document.createElement('style');
                                spaStyle.id = 'spa-page-style';
                                spaStyle.textContent = newStyles[newStyles.length - 1].textContent;
                                document.head.appendChild(spaStyle);
                            }

                            // Update header card
                            var newHeader = doc.querySelector('.header-card');
                            if (newHeader && headerCard) headerCard.innerHTML = newHeader.innerHTML;

                            // Update quick-nav active states
                            var newNav = doc.querySelector('.quick-nav');
                            if (newNav && quickNav) quickNav.innerHTML = newNav.innerHTML;

                            // Update content
                            var newContent = doc.querySelector('.page-content');
                            if (newContent) content.innerHTML = newContent.innerHTML;

                            // Update container class (training-container vs content-container)
                            var mainContainer = content.closest('.training-container, .content-container');
                            var newContainer = doc.querySelector('.training-container, .content-container');
                            if (mainContainer && newContainer && mainContainer.className !== newContainer.className) {
                                mainContainer.className = newContainer.className;
                            }

                            // Fade in
                            content.classList.remove('tab-loading');
                            content.style.animation = 'none';
                            content.offsetHeight; // force reflow
                            content.style.animation = '';
                            content.classList.add('page-content');

                            // Re-bind tab clicks
                            bindTabClicks();

                            // Load cert scripts if needed
                            if (url.indexOf('certificates') !== -1) loadCertScripts();

                            // Execute inline scripts from new content
                            var scripts = doc.querySelectorAll('.page-content script');
                            scripts.forEach(function(s) {
                                var ns = document.createElement('script');
                                ns.textContent = s.textContent;
                                document.body.appendChild(ns);
                                document.body.removeChild(ns);
                            });

                            // Update URL
                            history.pushState({ spaUrl: url }, '', url);
                        })
                        .catch(function() { window.location.href = url; });
                }, 220);
            }

            function bindTabClicks() {
                document.querySelectorAll('.quick-nav a:not(.active)').forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        spaNavigate(this.href);
                    });
                });
            }

            // Lazy load certificate scripts
            var certScriptsLoaded = false;
            function loadCertScripts() {
                if (certScriptsLoaded) return;
                certScriptsLoaded = true;
                ['https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js',
                 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js'].forEach(function(src) {
                    if (!document.querySelector('script[src="' + src + '"]')) {
                        var s = document.createElement('script');
                        s.src = src;
                        document.head.appendChild(s);
                    }
                });
                // Define cert functions
                if (typeof downloadCertImage === 'undefined') {
                    window.downloadCertImage = function(certId) {
                        var card = document.getElementById('cert-card-' + certId);
                        if (!card || typeof html2canvas === 'undefined') {
                            alert('جاري تحميل المكتبات، حاول مرة أخرى');
                            return;
                        }
                        var inner = card.querySelector('.cert-inner');
                        if (!inner) { alert('خطأ في بنية الشهادة'); return; }
                        html2canvas(inner, { scale: 3, backgroundColor: '#ffffff', useCORS: true, allowTaint: true, logging: false, windowWidth: document.documentElement.scrollWidth }).then(function(canvas) {
                            var link = document.createElement('a');
                            link.download = 'certificate-' + certId + '.png';
                            link.href = canvas.toDataURL('image/png');
                            link.click();
                        }).catch(function() { alert('حدث خطأ أثناء تحميل الصورة'); });
                    };
                    window.downloadCertPDF = function(certId) {
                        var card = document.getElementById('cert-card-' + certId);
                        if (!card || typeof html2canvas === 'undefined') {
                            alert('جاري تحميل المكتبات، حاول مرة أخرى');
                            return;
                        }
                        var inner = card.querySelector('.cert-inner');
                        if (!inner) { alert('خطأ في بنية الشهادة'); return; }
                        html2canvas(inner, { scale: 3, backgroundColor: '#ffffff', useCORS: true, allowTaint: true, logging: false, windowWidth: document.documentElement.scrollWidth }).then(function(canvas) {
                            var jsPDF = window.jspdf.jsPDF;
                            var imgData = canvas.toDataURL('image/png');
                            var pdf = new jsPDF('l', 'mm', 'a4');
                            var pw = pdf.internal.pageSize.getWidth();
                            var ph = pdf.internal.pageSize.getHeight();
                            var imgRatio = canvas.width / canvas.height;
                            var pageRatio = pw / ph;
                            var w, h, x, y;
                            if (imgRatio > pageRatio) { w = pw; h = pw / imgRatio; x = 0; y = (ph - h) / 2; }
                            else { h = ph; w = ph * imgRatio; x = (pw - w) / 2; y = 0; }
                            pdf.addImage(imgData, 'PNG', x, y, w, h);
                            var verifyEl = card.querySelector('.cert-verify-link');
                            if (verifyEl) {
                                var verifyText = verifyEl.textContent.trim();
                                var urlMatch = verifyText.match(/https?:\/\/[^\s]+/);
                                if (urlMatch) {
                                    var linkW = 80, linkH = 8;
                                    var linkX = (pw - linkW) / 2;
                                    var linkY = y + h - 12;
                                    pdf.link(linkX, linkY, linkW, linkH, {url: urlMatch[0]});
                                }
                            }
                            pdf.save('certificate-' + certId + '.pdf');
                        }).catch(function() { alert('حدث خطأ أثناء تحميل PDF'); });
                    };
                }
            }

            // Handle browser back/forward
            window.addEventListener('popstate', function(e) {
                if (e.state && e.state.spaUrl) {
                    spaNavigate(e.state.spaUrl);
                } else {
                    window.location.reload();
                }
            });

            // Save initial state
            history.replaceState({ spaUrl: window.location.href }, '', window.location.href);

            // Initial bind
            bindTabClicks();
        })();

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
