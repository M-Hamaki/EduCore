<?php
/**
 * تدريباتي - Teacher My Training & Certificates
 * صفحة تتبع التقدم والشهادات
 * تصميم موحد مع بوابة المعلم
 */
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/utilities.php';
require_once '../classes/Training.php';
require_once '../includes/template_helper.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

Utilities::validateSession('teacher');

$training = new Training($db);
$teacherId = $_SESSION['user_id'];
$teacherName = isset($_SESSION['name']) ? $_SESSION['name'] : 'المعلم';

$view = $_GET['view'] ?? 'courses';
$stats = $training->getTeacherStats($teacherId);
$enrollments = $training->getTeacherEnrollments($teacherId);
$certificates = $training->getTeacherCertificates($teacherId);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $view === 'courses' ? 'دوراتي' : 'شهاداتي'; ?> - التطوير المهني</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/training-common.css">
    <style>
        /* ===== training_my.php — Page-Specific Styles ===== */

        /* Container override */
        .content-container { max-width: 1100px; }
        .page-content { padding-bottom: 2rem; }

        /* Page-specific icon colors */
        .course-info-pill .fa-layer-group { color: #6366f1; }
        .course-info-pill .fa-check-circle { color: #10b981; }
        .course-info-pill .fa-spinner { color: #f59e0b; }
        .card-date-pill .fa-calendar-alt { color: #6366f1; }
        .card-date-pill .fa-check-circle { color: #059669; }
        /* Dark mode icon colors */
        body.dark-mode .course-info-pill .fa-layer-group { color: #818cf8; }
        body.dark-mode .course-info-pill .fa-check-circle { color: #34d399; }
        body.dark-mode .course-info-pill .fa-spinner { color: #fbbf24; }
        body.dark-mode .card-date-pill .fa-calendar-alt { color: #818cf8; }
        body.dark-mode .card-date-pill .fa-check-circle { color: #34d399; }

        /* ===== Mobile Responsive ===== */
        @media (max-width: 768px) {
            .d-flex.justify-content-between.align-items-start { flex-wrap: wrap; gap: 6px; }
            .cert-download-btns { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; }
            .cert-download-btns .btn { flex: 1; min-width: 120px; }
            .cert-download-btns .btn.ms-2 { margin-left: 0 !important; }
        }
        @media (max-width: 480px) {
            .content-container { max-width: 100%; }
            .card-body.p-4 { padding: 0.9rem !important; }
            .cert-download-btns .btn { font-size: 0.78rem; padding: 6px 10px; }
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
        <?php if ($view === 'courses'): ?>
        <h1>📖 دوراتي</h1>
        <p class="page-subtitle">تتبع تقدمك في الدورات التدريبية المسجلة</p>
        <?php else: ?>
        <h1>🏅 شهاداتي</h1>
        <p class="page-subtitle">شهاداتك المهنية وإنجازاتك التدريبية</p>
        <?php endif; ?>

        <!-- Stats Pills -->
        <div class="stats-bar">
            <div class="stat-pill"><i class="fas fa-book-open"></i><span><?php echo $stats['enrolled_courses']; ?> مسجل</span></div>
            <div class="stat-pill"><i class="fas fa-check-circle"></i><span><?php echo $stats['completed_courses']; ?> مكتمل</span></div>
            <div class="stat-pill"><i class="fas fa-certificate"></i><span><?php echo $stats['certificates']; ?> شهادة</span></div>
        </div>
        </div>
    </div>

    <div class="content-container">
        <!-- Tab Pills -->
        <div class="quick-nav">
            <a href="training.php">🎓 التدريبات</a>
            <a href="training_my.php" class="<?php echo $view === 'courses' ? 'active' : ''; ?>">📖 دوراتي (<?php echo count($enrollments); ?>)</a>
            <a href="training_my.php?view=certificates" class="<?php echo $view === 'certificates' ? 'active' : ''; ?>">🏅 شهاداتي (<?php echo count($certificates); ?>)</a>
        </div>

        <div class="page-content">
        <?php if ($view === 'courses'): ?>
        <!-- ===== MY COURSES ===== -->
        <?php if (empty($enrollments)): ?>
            <div class="empty-state">
                <i class="fas fa-book fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">لم تسجل في أي دورة بعد</h5>
                <a href="training.php" class="btn btn-primary mt-2">
                    <i class="fas fa-th-large me-1"></i> تصفح الدورات المتاحة
                </a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($enrollments as $enr): 
                    $progressColor = $enr['progress_percent'] >= 100 ? 'success' : ($enr['progress_percent'] >= 50 ? 'info' : 'warning');
                    $eLang = $enr['display_language'] ?? 'ar';
                    $eDir = Training::getDirection($eLang);
                    $eTitle = Training::getLocalizedValue($enr, 'course_title', $eLang);
                    $eDesc = Training::getLocalizedValue($enr, 'course_description', $eLang);
                ?>
                    <div class="col-lg-6">
                        <div class="portal-card h-100">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <span class="badge" style="background-color: <?php echo $enr['program_color']; ?>">
                                        <i class="fas <?php echo $enr['program_icon']; ?> me-1"></i>
                                        <?php echo htmlspecialchars($enr['program_name']); ?>
                                    </span>
                                    <div class="d-flex gap-1">
                                        <?php echo Training::getLanguageBadge($eLang); ?>
                                        <span class="badge <?php echo Training::getStatusBadge($enr['status']); ?>">
                                            <?php echo Training::getStatusLabel($enr['status'], $eLang); ?>
                                        </span>
                                    </div>
                                </div>
                                <h5 class="mb-2" dir="<?php echo $eDir; ?>"><?php echo htmlspecialchars($eTitle); ?></h5>
                                <p class="text-muted small mb-3" dir="<?php echo $eDir; ?>"><?php echo htmlspecialchars(mb_substr($eDesc, 0, 100)); ?></p>
                                
                                <div class="course-info-row">
                                    <span class="course-info-pill"><i class="fas fa-layer-group"></i> <?php echo $enr['completed_units']; ?>/<?php echo $enr['total_units']; ?> وحدة</span>
                                    <span class="course-info-pill">
                                        <?php if ($enr['progress_percent'] >= 100): ?>
                                            <i class="fas fa-check-circle" style="color: #059669"></i>
                                            <span style="color: #059669">مكتمل</span>
                                        <?php else: ?>
                                            <i class="fas fa-spinner" style="color: #f59e0b"></i>
                                            <span style="color: #f59e0b">جاري</span>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <div class="card-progress-wrapper">
                                    <div class="card-progress-text">
                                        <span><i class="fas fa-chart-line me-1"></i>التقدم</span>
                                        <span><?php echo round($enr['progress_percent']); ?>%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: <?php echo round($enr['progress_percent']); ?>%; transition: width 0.6s;"></div>
                                    </div>
                                </div>
                                
                                <div class="card-date-row">
                                    <span class="card-date-pill"><i class="fas fa-calendar-alt"></i> تسجيل: <?php echo date('Y/m/d', strtotime($enr['enrolled_at'])); ?></span>
                                    <?php if ($enr['completed_at']): ?>
                                        <span class="card-date-pill" style="color: #059669; border-color: #d1fae5;"><i class="fas fa-check-circle"></i> إكمال: <?php echo date('Y/m/d', strtotime($enr['completed_at'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-footer">
                                <a href="training_course.php?id=<?php echo $enr['course_id']; ?>" class="btn btn-primary w-100">
                                    <i class="fas fa-<?php echo $enr['status'] === 'completed' ? 'eye' : 'play'; ?> me-1"></i>
                                    <?php echo $enr['status'] === 'completed' ? 'مراجعة الدورة' : 'متابعة التدريب'; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php elseif ($view === 'certificates'): ?>
        <!-- ===== MY CERTIFICATES ===== -->
        <?php if (empty($certificates)): ?>
            <div class="empty-state">
                <i class="fas fa-certificate fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">لم تحصل على أي شهادة بعد</h5>
                <p class="text-muted">أكمل الدورات التدريبية بنجاح للحصول على الشهادات</p>
                <a href="training.php" class="btn btn-primary mt-2">
                    <i class="fas fa-th-large me-1"></i> تصفح الدورات
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($certificates as $cert): 
                $cLang = $cert['display_language'] ?? 'ar';
                $cDir = Training::getDirection($cLang);
                $cTitle = Training::getLocalizedValue($cert, 'course_title', $cLang);
            ?>
                <div class="cert-wrapper">
                    <div class="cert-card" id="cert-card-<?php echo $cert['id']; ?>">
                        <div class="cert-inner">
                            <div class="cert-corner tl"></div>
                            <div class="cert-corner tr"></div>
                            <div class="cert-corner bl"></div>
                            <div class="cert-corner br"></div>
                            <img src="<?php echo get_school_logo('../'); ?>" alt="شعار المدرسة" class="cert-logo">
                            <div class="cert-title-row">
                                <i class="fas fa-award"></i>
                                <div class="cert-title">شهادة إتمام</div>
                                <i class="fas fa-award"></i>
                            </div>
                            <div class="cert-divider"></div>
                            <div class="cert-school"><i class="fas fa-university me-2"></i>تشهد مدرسة الدلتا الحديثة للغات</div>
                            <div class="cert-body-text">
                                أن المعلم / <span class="cert-teacher-name"><?php echo htmlspecialchars($teacherName); ?></span>
                            </div>
                            <div class="cert-body-text">قد اجتاز بنجاح دورة</div>
                            <div class="cert-course-title" dir="<?php echo $cDir; ?>"><?php echo htmlspecialchars($cTitle); ?></div>

                            <div class="cert-signatures">
                                <div class="cert-sig">
                                    <div class="cert-sig-title">وحدة التدريب والجودة</div>
                                    <div class="cert-sig-line"></div>
                                </div>
                                <div class="cert-sig">
                                    <div class="cert-sig-title">مديرة المرحلة</div>
                                    <div class="cert-sig-line"></div>
                                </div>
                                <div class="cert-sig">
                                    <div class="cert-sig-title">مدير المدرسة</div>
                                    <div class="cert-sig-line"></div>
                                </div>
                            </div>
                            <div class="cert-footer-info">
                                <div class="cert-number"><i class="fas fa-shield-alt me-1" style="color: #0d6efd;"></i>رقم التحقق: <?php echo htmlspecialchars($cert['certificate_number']); ?></div>
                                <div class="cert-date"><i class="fas fa-calendar me-1" style="color: #0d6efd;"></i>تاريخ الإصدار: <?php echo date('Y/m/d', strtotime($cert['issued_at'])); ?></div>
                                <div class="cert-verify-link"><i class="fas fa-link me-1" style="color: #3d8bfd;"></i>التحقق: <?php echo (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://' . $_SERVER['HTTP_HOST'] . '/EduCore') . '/verify_certificate.php'; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="cert-download-btns">
                        <button class="btn btn-primary btn-sm" onclick="downloadCertImage(<?php echo $cert['id']; ?>)">
                            <i class="fas fa-image me-1"></i> تحميل صورة
                        </button>
                        <button class="btn btn-danger btn-sm ms-2" onclick="downloadCertPDF(<?php echo $cert['id']; ?>)">
                            <i class="fas fa-file-pdf me-1"></i> تحميل PDF
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <footer class="portal-footer" style="margin-top: 4rem;">
        <div class="container text-center">
            <p style="margin: 0.5rem 0; line-height: 1.6;">
                <strong>جميع الحقوق محفوظة © <?php echo date('Y'); ?></strong>
            </p>
            <p style="margin: 0.5rem 0; line-height: 1.6;">
                EduCore<br>
                Computer Department
            </p>
            
            <!-- Social Media Icons in Footer -->
            <div class="social-media-footer">
                <a href="https://github.com/M-Hamaki/EduCore" target="_blank" class="social-footer-icon github" title="مستودع المشروع">
                    <i class="fab fa-github"></i>
                </a>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
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
                // Cert functions are defined globally at page bottom
                // Just ensure scripts are loaded
                certScriptsLoaded = true;
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

            // Auto-load cert scripts if already on certificates view
            if (window.location.search.indexOf('certificates') !== -1) loadCertScripts();
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

        // Certificate download functions — capture exactly as displayed on screen
        function captureA4Certificate(certId, callback) {
            var card = document.getElementById('cert-card-' + certId);
            if (!card || typeof html2canvas === 'undefined') {
                alert('جاري تحميل المكتبات، حاول مرة أخرى');
                return;
            }

            // Get the cert-inner element (the actual certificate visual)
            var inner = card.querySelector('.cert-inner');
            if (!inner) { alert('خطأ في بنية الشهادة'); return; }

            // Capture exactly as it appears on screen, with high scale for quality
            html2canvas(inner, {
                scale: 3,
                backgroundColor: '#ffffff',
                useCORS: true,
                allowTaint: true,
                logging: false,
                // Let html2canvas use the element's actual rendered dimensions
                windowWidth: document.documentElement.scrollWidth
            }).then(function(canvas) {
                callback(canvas);
            }).catch(function(err) {
                console.error('Certificate export error:', err);
                alert('حدث خطأ أثناء التحميل');
            });
        }

        function downloadCertImage(certId) {
            captureA4Certificate(certId, function(canvas) {
                var link = document.createElement('a');
                link.download = 'certificate-' + certId + '.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            });
        }

        function downloadCertPDF(certId) {
            captureA4Certificate(certId, function(canvas) {
                var jsPDF = window.jspdf.jsPDF;
                var imgData = canvas.toDataURL('image/png');
                var pdf = new jsPDF('l', 'mm', 'a4');
                var pw = pdf.internal.pageSize.getWidth();
                var ph = pdf.internal.pageSize.getHeight();
                // Scale image to fill A4 page while keeping aspect ratio
                var imgRatio = canvas.width / canvas.height;
                var pageRatio = pw / ph;
                var w, h, x, y;
                if (imgRatio > pageRatio) {
                    w = pw;
                    h = pw / imgRatio;
                    x = 0;
                    y = (ph - h) / 2;
                } else {
                    h = ph;
                    w = ph * imgRatio;
                    x = (pw - w) / 2;
                    y = 0;
                }
                pdf.addImage(imgData, 'PNG', x, y, w, h);

                // Add clickable verification link at the bottom of the certificate
                var certCard = document.getElementById('cert-card-' + certId);
                var verifyEl = certCard ? certCard.querySelector('.cert-verify-link') : null;
                if (verifyEl) {
                    var verifyText = verifyEl.textContent.trim();
                    var urlMatch = verifyText.match(/https?:\/\/[^\s]+/);
                    if (urlMatch) {
                        var linkUrl = urlMatch[0];
                        // Place clickable link area at the bottom center of the certificate
                        var linkW = 80, linkH = 8;
                        var linkX = (pw - linkW) / 2;
                        var linkY = y + h - 12;
                        pdf.link(linkX, linkY, linkW, linkH, {url: linkUrl});
                    }
                }

                pdf.save('certificate-' + certId + '.pdf');
            });
        }
    </script>
</body>
</html>
