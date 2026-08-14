<?php
/**
 * أداة تحضير الدروس بالذكاء الاصطناعي
 * AI Lesson Preparation Tool
 */

// تحميل إعدادات الجلسة
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/utilities.php';

// التحقق من تسجيل الدخول (يسمح للمعلمين الداخليين والخارجيين)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    header('Location: ../index.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/ai_config.php';
require_once '../classes/LessonPrepPageContext.php';

$database = new Database();
$db = $database->getConnection();

// الحصول على اسم المعلم
$teacher_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'المعلم';
$teacher_id = $_SESSION['user_id'];

$pageContext = LessonPrepPageContext::load(
    $db,
    static function (PDO $connection): string {
        return (string) getGeminiApiKey($connection);
    },
    static function (PDO $connection): array {
        // جلب قوالب Canva المتاحة للمعلم
        require_once '../classes/CanvaIntegration.php';
        $integration = new CanvaIntegration($connection);
        return $integration->isConnected() ? $integration->getAllTemplates() : [];
    },
    static function (PDO $connection): array {
        // جلب مكتبة قوالب PowerPoint الداخلية
        require_once '../classes/LessonPptTemplateLibrary.php';
        return (new LessonPptTemplateLibrary($connection))->active();
    }
);
$hasApiKey = $pageContext['has_api_key'];
$canvaTemplates = $pageContext['canva_templates'];
$internalPptTemplates = $pageContext['internal_ppt_templates'];

// جلب الصفوف الدراسية النشطة للقائمة المنسدلة (استبعاد الفصول والصفوف التجريبية)
$allGrades = [];
try {
    $gradeStmt = $db->prepare("
        SELECT id, grade_name 
        FROM grades 
        WHERE status = 'active' 
          AND (is_experimental = 0 OR is_experimental IS NULL)
          AND grade_code NOT LIKE '%test%' 
          AND grade_code NOT LIKE '%qa%' 
          AND LOWER(grade_name) NOT LIKE '%test%' 
          AND grade_name NOT LIKE '%تجريب%' 
        ORDER BY grade_order ASC
    ");
    $gradeStmt->execute();
    $allGrades = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // الجدول قد لا يكون موجوداً
    error_log('lesson_prep: grades query failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo csrfToken(); ?>">
    <title>أداة تحضير الدروس - EduCore</title>

    <!-- Prevent caching issues -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/eduvisual.css?v=4.1">
    <link rel="stylesheet" href="styles.css?v=1.3">
    <!-- html2canvas for card capture -->
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

    <link rel="stylesheet" href="../assets/css/lesson-prep.css?v=3.5">
    <link rel="stylesheet" href="../assets/css/lesson-sharing.css?v=1">
    <link rel="stylesheet" href="../assets/css/buttons.css">
    <link rel="stylesheet" href="../assets/css/admin-unified.css?v=2.0">

    <style>
        /* Modern Premium Main Page Tabs */
        .main-page-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            padding: 6px;
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 16px !important;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04) !important;
            position: relative;
        }

        .main-page-tabs::before {
            display: none !important;
        }

        .main-tab-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 18px;
            border: 1px solid transparent !important;
            border-radius: 12px !important;
            font-family: 'Cairo', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            color: #64748b !important;
            background: transparent !important;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            white-space: nowrap;
        }

        .main-tab-btn:hover {
            color: #1e293b !important;
            background: #f8fafc !important;
            border-color: #cbd5e1 !important;
        }

        .main-tab-btn.active {
            color: #ffffff !important;
            background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
            border-color: transparent !important;
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.35) !important;
        }

        .main-tab-btn .tab-icon {
            font-size: 1.15rem;
            color: #475569;
            transition: transform 0.25s ease, color 0.25s ease;
        }

        .main-tab-btn:hover .tab-icon {
            color: #2563eb;
        }

        .main-tab-btn.active .tab-icon {
            color: #ffffff !important;
            transform: scale(1.15);
        }

        .main-tab-btn small {
            font-size: 0.72rem;
            font-weight: 500;
            opacity: 0.85;
            color: #64748b;
        }

        .main-tab-btn.active small {
            color: rgba(255, 255, 255, 0.92) !important;
        }

        /* Modern Settings Interface System */
        .modern-settings-container {
            padding: 4px 0;
        }

        /* Unified Global Form Controls System */
        .form-control,
        .form-select {
            background-color: #ffffff !important;
            border: 1.5px solid #cbd5e1 !important;
            border-radius: 8px !important;
            color: #1e293b !important;
            font-weight: 600 !important;
            font-size: 0.95rem !important;
            padding: 8px 12px !important;
            box-shadow: none !important;
            transition: all 0.2s ease !important;
        }

        .form-select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%232563eb' stroke-linecap='round' stroke-linejoin='round' stroke-width='2.5' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
            background-position: left 0.75rem center !important;
            background-repeat: no-repeat !important;
            background-size: 14px 12px !important;
            padding-left: 2.2rem !important;
            padding-right: 0.75rem !important;
            cursor: pointer !important;
        }

        .form-control:hover,
        .form-select:hover,
        .form-control:focus,
        .form-select:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12) !important;
            background-color: #ffffff !important;
        }

        .form-control:disabled,
        .form-select:disabled {
            background-color: #f1f5f9 !important;
            opacity: 0.65 !important;
            cursor: not-allowed !important;
        }

        /* Refined Balanced Typography System */
        .card-title {
            font-weight: 600 !important;
            font-size: 1.2rem !important;
            color: #1e293b !important;
            letter-spacing: -0.01em;
        }

        .subcard-title {
            font-size: 0.92rem !important;
            font-weight: 600 !important;
            color: #334155 !important;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label {
            font-size: 0.88rem !important;
            font-weight: 600 !important;
            color: #334155 !important;
            margin-bottom: 6px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }

        .form-label i {
            font-size: 0.95rem !important;
        }

        .settings-subcard {
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 12px !important;
            padding: 16px 18px !important;
        }

        /* High-Density Ultra-Premium Export Tab System */
        .lesson-share-card {
            margin: 0 0 12px 0 !important;
            padding: 12px 16px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 10px !important;
            background: #ffffff !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04) !important;
            text-align: right;
        }

        .lesson-share-card__header {
            margin-bottom: 8px !important;
        }

        .lesson-share-card__title {
            font-size: 0.95rem !important;
            font-weight: 700 !important;
            color: #1e293b !important;
            gap: 0.4rem !important;
        }

        .lesson-share-card__title i {
            width: 28px !important;
            height: 28px !important;
            font-size: 0.85rem !important;
            background: #2563eb !important;
        }

        .lesson-share-card__description {
            font-size: 0.82rem !important;
            line-height: 1.4 !important;
            color: #64748b !important;
        }

        .lesson-share-card__link {
            margin-top: 8px !important;
            padding-top: 8px !important;
            border-top: 1px dashed #cbd5e1 !important;
        }

        .lesson-share-card__input {
            padding: 6px 10px !important;
            font-size: 0.85rem !important;
            margin-bottom: 8px !important;
            height: auto !important;
        }

        .lesson-share-card__notice {
            margin-top: 6px !important;
            font-size: 0.78rem !important;
        }

        .export-element-checkbox-label {
            padding: 6px 10px !important;
            font-size: 0.82rem !important;
            font-weight: 600 !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            background: #ffffff !important;
            color: #334155 !important;
            transition: all 0.2s ease !important;
            cursor: pointer !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-start !important;
            gap: 6px !important;
            height: 52px !important;
            min-height: 52px !important;
            max-height: 52px !important;
            box-sizing: border-box !important;
            line-height: 1.3 !important;
        }

        .export-element-checkbox-label:hover {
            border-color: #2563eb !important;
            background: #eff6ff !important;
            color: #1d4ed8 !important;
        }

        .subcard-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .qcount-stepper {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s ease;
        }

        .qcount-stepper:hover {
            border-color: #94a3b8;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }

        .qcount-stepper.mc { border-right: 4px solid #3b82f6 !important; }
        .qcount-stepper.tf { border-right: 4px solid #10b981 !important; }
        .qcount-stepper.essay { border-right: 4px solid #8b5cf6 !important; }

        .qcount-info {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #334155;
        }

        .qcount-input {
            width: 78px !important;
            height: 36px !important;
            text-align: center !important;
            font-weight: 700 !important;
            font-size: 0.95rem !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            padding: 2px 4px !important;
        }

        .feature-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .feature-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
        }

        .feature-desc {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .feature-name {
            font-size: 0.86rem;
            font-weight: 700;
            color: #1e293b;
            white-space: nowrap;
        }

        .feature-hint {
            font-size: 0.74rem;
            color: #64748b;
            display: block;
        }

        .theme-palette-grid {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .theme-palette-item input {
            display: none;
        }

        .theme-palette-card {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 24px;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .theme-palette-card:hover {
            border-color: #3b82f6;
            transform: translateY(-1px);
        }

        .swatch {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            display: inline-block;
        }

        .classic-swatch { background: linear-gradient(135deg, #667eea, #764ba2); }
        .ocean-swatch { background: linear-gradient(135deg, #0077b6, #023e8a); }
        .nature-swatch { background: linear-gradient(135deg, #2d6a4f, #52b788); }
        .sunset-swatch { background: linear-gradient(135deg, #e76f51, #f4a261); }
        .rose-swatch { background: linear-gradient(135deg, #be185d, #ec4899); }
        .dark-swatch { background: linear-gradient(135deg, #1e1e2e, #2d2d44); }
        .royal-swatch { background: linear-gradient(135deg, #7e22ce, #a855f7); }

        .theme-name {
            font-size: 0.82rem;
            font-weight: 700;
            color: #475569;
        }

        .theme-palette-item input:checked + .theme-palette-card {
            border-color: #2563eb;
            background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .theme-palette-item input:checked + .theme-palette-card .theme-name {
            color: #1d4ed8;
        }
    </style>

    <script>
        // File handling - defined early so onchange works
        let pdfFile = null;
        let imageFile = null;
        let forceDuplicate = false;

        function handlePdfSelect(input) {
            if (input.files && input.files.length > 0) {
                var file = input.files[0];
                if (file.size > 10 * 1024 * 1024) {
                    alert('حجم الملف يتجاوز الحد المسموح (10 ميجابايت)');
                    input.value = '';
                    pdfFile = null;
                    return;
                }
                pdfFile = file;
                document.getElementById('pdfFileName').textContent = file.name;
                document.getElementById('pdfPreview').style.display = 'block';
                console.log('PDF selected:', file.name);
            }
        }

        function handleImageSelect(input) {
            if (input.files && input.files.length > 0) {
                var file = input.files[0];
                if (file.size > 5 * 1024 * 1024) {
                    alert('حجم الصورة يتجاوز الحد المسموح (5 ميجابايت)');
                    input.value = '';
                    imageFile = null;
                    return;
                }
                imageFile = file;
                document.getElementById('imageFileName').textContent = file.name;
                document.getElementById('imagePreview').style.display = 'block';
                console.log('Image selected:', file.name);

                // Show image thumbnail preview
                var thumbEl = document.getElementById('imageThumbnail');
                if (thumbEl && file.type.startsWith('image/')) {
                    var reader = new FileReader();
                    reader.onload = function (ev) {
                        thumbEl.src = ev.target.result;
                        thumbEl.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                }
            }
        }

        function removeFile(type) {
            if (type === 'pdf') {
                pdfFile = null;
                document.getElementById('pdfInput').value = '';
                document.getElementById('pdfPreview').style.display = 'none';
            } else {
                imageFile = null;
                document.getElementById('imageInput').value = '';
                document.getElementById('imagePreview').style.display = 'none';
                var thumbEl = document.getElementById('imageThumbnail');
                if (thumbEl) { thumbEl.src = ''; thumbEl.style.display = 'none'; }
            }
        }
    </script>
</head>

<body>
    <div class="modal fade" id="lessonDialogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
                <form id="lessonDialogForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="lessonDialogTitle"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-3" id="lessonDialogIconContainer" style="display:none;"><i id="lessonDialogIcon" style="font-size:3rem"></i></div>
                        <div id="lessonDialogBody"></div>
                    </div>
                    <div class="modal-footer justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary d-none" id="lessonDialogCancel" data-bs-dismiss="modal">إلغاء</button>
                        <button type="button" class="btn btn-primary d-none" id="lessonDialogDeny">تغيير العنوان</button>
                        <button type="submit" class="btn btn-success" id="lessonDialogConfirm">حسنًا</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/lesson-dialog.js"></script>

    <?php if (!$hasApiKey): ?>
        <!-- API Key Warning -->
        <div class="main-container">
            <div class="content-card">
                <div class="api-warning">
                    <div class="api-warning-icon">⚠️</div>
                    <h3>مفتاح API غير مُعدّ</h3>
                    <p>لاستخدام أداة تحضير الدروس بالذكاء الاصطناعي، يرجى إعداد مفتاح Google Gemini API.</p>
                    <p style="margin-top: 15px;">
                        <a href="https://aistudio.google.com/app/apikey" target="_blank">
                            <i class="fas fa-external-link-alt"></i> الحصول على مفتاح API مجاني
                        </a>
                    </p>
                    <p style="margin-top: 10px; font-size: 0.9rem;">
                        بعد الحصول على المفتاح، أضفه في ملف <code>config/ai_config.php</code> أو تواصل مع مدير النظام.
                    </p>

                </div>
            </div>
        </div>
        <?php
    else: ?>
<?php require __DIR__ . '/../classes/Presentation/LessonPrep/form_part_one.php'; ?>
<?php require __DIR__ . '/../classes/Presentation/LessonPrep/form_part_two.php'; ?>
<?php require __DIR__ . '/../classes/Presentation/LessonPrep/scripts_part_one.php'; ?>
<?php require __DIR__ . '/../classes/Presentation/LessonPrep/scripts_part_two.php'; ?>
            <?php
    endif; ?>

    </div><!-- end main-container -->

    <script>
        // Loading tips rotation
        let loadingTipsInterval = null;
        const loadingTipsList = [
            'للعلم: كلما كان المحتوى أكثر تفصيلاً، كانت النتائج أفضل ✨',
            'يتم تحليل المحتوى واستخراج الأهداف التعليمية... 📚',
            'جاري بناء خطة الدرس والاستراتيجيات... 🎯',
            'يمكنك تصدير النتائج بصيغة Word أو PDF بعد الانتهاء 📄',
            'جاري توليد بنك الأسئلة المتنوعة... ❓',
            'يتم إعداد الامتحان الإلكتروني التفاعلي... 💻',
            'جاري تصميم الأنشطة الصفية التفاعلية... 🎮',
            'شكراً لصبرك، الذكاء الاصطناعي يعمل بجد! 🤖'
        ];

        function startLoadingTips() {
            stopLoadingTips(); // Prevent overlapping intervals
            let tipIndex = 0;
            const tipsEl = document.getElementById('loadingTips');
            if (!tipsEl) return;
            tipsEl.textContent = loadingTipsList[0];
            loadingTipsInterval = setInterval(() => {
                tipIndex = (tipIndex + 1) % loadingTipsList.length;
                tipsEl.style.opacity = '0';
                setTimeout(() => {
                    tipsEl.textContent = loadingTipsList[tipIndex];
                    tipsEl.style.opacity = '1';
                }, 300);
            }, 3500);
        }

        function stopLoadingTips() {
            if (loadingTipsInterval) {
                clearInterval(loadingTipsInterval);
                loadingTipsInterval = null;
            }
        }

        // Stop tips when loading overlay is hidden
        const loadingObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.target.id === 'loadingOverlay' && !mutation.target.classList.contains('show')) {
                    stopLoadingTips();
                }
            });
        });
        const overlayEl = document.getElementById('loadingOverlay');
        if (overlayEl) loadingObserver.observe(overlayEl, { attributes: true, attributeFilter: ['class'] });
    </script>
    <script src="script.js?v=1.2"></script>
    <script>
        // Theme Toggle
        (function () {
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme') || 'light';

            if (themeToggle) {
                themeToggle.addEventListener('click', () => {
                    // Theme toggle handled by main class switcher
                });
            }
        })();
    </script>
    <script src="../assets/js/ai_lesson_csrf.js"></script>
    <script src="../assets/js/lesson_display.js?v=1.2"></script>
    <script src="../assets/js/eduvisual.js?v=4.1"></script>
    <script src="../assets/js/lesson-sharing.js?v=3"></script>
    <script src="../assets/js/lesson-export.js?v=2"></script>
    <script>
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
            new bootstrap.Tooltip(el);
        });
    </script>
</body>

</html>
