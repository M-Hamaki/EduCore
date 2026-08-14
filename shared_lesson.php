<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Modules/LearningContent/LessonShareService.php';

use EduCore\Modules\LearningContent\LessonShareService;

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
$scriptNonce = base64_encode(random_bytes(18));
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'nonce-{$scriptNonce}'; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
    . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
    . "img-src 'self' data: https:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'"
);

$token = isset($_GET['token']) ? strtolower(trim((string) $_GET['token'])) : '';
$lesson = null;

if (LessonShareService::isValidToken($token)) {
    try {
        $db = (new Database())->getConnection();
        if ($db instanceof PDO) {
            $lesson = (new LessonShareService($db))->findPublicLesson($token);
        }
    } catch (Throwable $e) {
        error_log('Shared lesson lookup failed: ' . $e->getMessage());
    }
}

if (!$lesson) {
    http_response_code(404);
}

$decode = static function (?string $json): ?array {
    if ($json === null || trim($json) === '') {
        return null;
    }
    $value = json_decode($json, true);
    return is_array($value) ? $value : null;
};

$lessonPlan = $lesson ? $decode($lesson['generated_prep'] ?? null) : null;
$questionBank = $lesson ? $decode($lesson['question_bank'] ?? null) : null;
$visualMaterials = $lesson ? $decode($lesson['visual_materials'] ?? null) : null;
$classActivities = $lesson ? $decode($lesson['class_activities'] ?? null) : null;
$educationalStories = $lesson ? $decode($lesson['educational_stories'] ?? null) : null;
$mindMaps = $lesson ? $decode($lesson['mind_maps'] ?? null) : null;
$lessonSummary = $lesson ? $decode($lesson['lesson_summary'] ?? null) : null;
$customContent = $lesson ? $decode($lesson['custom_content'] ?? null) : null;
$hasExam = $lesson && !empty($lesson['exam_html']);
$hasPowerPoint = $lesson && !empty($lesson['powerpoint_path']);
$firstTabId = null;
foreach ([
    'lesson-plan' => (bool) $lessonPlan,
    'visual-materials' => (bool) $visualMaterials,
    'mind-maps' => (bool) $mindMaps,
    'question-bank' => (bool) $questionBank,
    'class-activities' => (bool) $classActivities,
    'educational-stories' => (bool) $educationalStories,
    'lesson-summary' => (bool) $lessonSummary,
    'custom-content' => (bool) $customContent,
    'exam-preview' => (bool) $hasExam,
    'powerpoint-preview' => (bool) $hasPowerPoint,
] as $tabId => $available) {
    if ($available) {
        $firstTabId = $tabId;
        break;
    }
}
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$renderTabExportButtons = static function (string $target, string $label): void {
    $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="shared-tab-export no-print" data-export-target="<?php echo $safeTarget; ?>">
        <div class="shared-tab-export__label">
            <i class="fas fa-download"></i>
            <span>تحميل <?php echo $safeLabel; ?> فقط</span>
        </div>
        <div class="shared-tab-export__actions">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-export-format="html">
                <i class="fas fa-code me-1"></i>HTML
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" data-export-format="pdf">
                <i class="fas fa-file-pdf me-1"></i>PDF
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm" data-export-format="word">
                <i class="fas fa-file-word me-1"></i>Word
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-export-format="print">
                <i class="fas fa-print me-1"></i>طباعة
            </button>
        </div>
    </div>
    <?php
};
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?php echo $lesson ? htmlspecialchars((string) $lesson['title'], ENT_QUOTES, 'UTF-8') : 'رابط درس غير صالح'; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/eduvisual.css?v=4.1">
    <link rel="stylesheet" href="assets/css/lesson-view.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link rel="stylesheet" href="assets/css/shared-lesson.css?v=2">
</head>
<body>
<?php if (!$lesson): ?>
    <main class="shared-lesson-empty">
        <i class="fas fa-link-slash"></i>
        <h1 class="h3">رابط الدرس غير متاح</h1>
        <p class="text-muted mb-0">قد يكون الرابط غير صحيح أو أوقف المعلم مشاركته.</p>
    </main>
<?php else: ?>
    <main class="main-container">
        <div class="shared-lesson-banner" role="status">
            <div class="shared-lesson-banner__text">
                <span class="shared-lesson-banner__icon"><i class="fas fa-share-nodes"></i></span>
                <div>
                    <strong>نسخة عامة للعرض</strong>
                    <div>يمكن مشاهدة محتوى هذا الدرس دون تسجيل دخول لأن المعلم فعّل مشاركة الرابط.</div>
                </div>
            </div>
            <button type="button" class="btn btn-outline-primary" id="printSharedLesson">
                <i class="fas fa-print me-1"></i>طباعة الصفحة
            </button>
        </div>

        <header class="page-header">
            <div class="header-title">
                <div class="header-icon">
                    <div class="header-icon-ring">
                        <div class="header-icon-inner"><i class="fas fa-book-open"></i></div>
                    </div>
                </div>
                <div class="header-text">
                    <h1><?php echo htmlspecialchars((string) $lesson['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>أُعدّ في <?php echo htmlspecialchars(date('Y/m/d', strtotime((string) $lesson['created_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
            <div class="shared-lesson-actions">
                <?php if ($hasExam): ?>
                    <a class="btn btn-outline-primary" href="shared_lesson_download.php?token=<?php echo rawurlencode($token); ?>&amp;type=exam">
                        <i class="fas fa-file-code me-1"></i>تحميل الامتحان
                    </a>
                <?php endif; ?>
                <?php if ($hasPowerPoint): ?>
                    <a class="btn btn-outline-primary" href="shared_lesson_download.php?token=<?php echo rawurlencode($token); ?>&amp;type=powerpoint">
                        <i class="fas fa-file-powerpoint me-1"></i>تحميل PowerPoint
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <div class="lesson-meta">
            <div class="meta-item"><i class="fas fa-language"></i><span><?php echo ($lesson['language'] ?? 'ar') === 'ar' ? 'العربية' : htmlspecialchars((string) $lesson['language'], ENT_QUOTES, 'UTF-8'); ?></span></div>
            <div class="meta-item"><i class="fas fa-clock"></i><span><?php echo (int) ($lesson['duration_minutes'] ?? 0); ?> دقيقة</span></div>
            <div class="meta-item"><i class="fas fa-circle-check"></i><span>درس مكتمل</span></div>
        </div>

        <section class="shared-lesson-export-panel no-print" aria-labelledby="sharedLessonExportTitle">
            <div>
                <h2 id="sharedLessonExportTitle"><i class="fas fa-box-archive me-2"></i>تحميل الدرس الكامل</h2>
                <p>تجمع هذه الأزرار جميع التبويبات المتاحة مرة واحدة من دون تكرار المحتوى.</p>
            </div>
            <div class="shared-lesson-export-panel__actions">
                <button type="button" class="btn btn-outline-secondary" data-export-all-format="html">
                    <i class="fas fa-code me-1"></i>HTML كامل
                </button>
                <button type="button" class="btn btn-outline-danger" data-export-all-format="pdf">
                    <i class="fas fa-file-pdf me-1"></i>PDF كامل
                </button>
                <button type="button" class="btn btn-outline-primary" data-export-all-format="word">
                    <i class="fas fa-file-word me-1"></i>Word كامل
                </button>
                <button type="button" class="btn btn-outline-secondary" data-export-all-format="print">
                    <i class="fas fa-print me-1"></i>طباعة الكل
                </button>
            </div>
            <p class="shared-lesson-export-status mb-0" id="sharedLessonExportStatus" role="status" aria-live="polite"></p>
        </section>

        <div class="tabs-container">
            <div class="tabs-header">
                <?php if ($lessonPlan): ?><button class="tab-btn<?php echo $firstTabId === 'lesson-plan' ? ' active' : ''; ?>" data-tab="lesson-plan"><i class="fas fa-clipboard-list"></i> تحضير الدرس</button><?php endif; ?>
                <?php if ($visualMaterials): ?><button class="tab-btn<?php echo $firstTabId === 'visual-materials' ? ' active' : ''; ?>" data-tab="visual-materials"><i class="fas fa-images"></i> المواد البصرية</button><?php endif; ?>
                <?php if ($mindMaps): ?><button class="tab-btn<?php echo $firstTabId === 'mind-maps' ? ' active' : ''; ?>" data-tab="mind-maps"><i class="fas fa-project-diagram"></i> الخرائط الذهنية</button><?php endif; ?>
                <?php if ($questionBank): ?><button class="tab-btn<?php echo $firstTabId === 'question-bank' ? ' active' : ''; ?>" data-tab="question-bank"><i class="fas fa-question-circle"></i> بنك الأسئلة</button><?php endif; ?>
                <?php if ($classActivities): ?><button class="tab-btn<?php echo $firstTabId === 'class-activities' ? ' active' : ''; ?>" data-tab="class-activities"><i class="fas fa-puzzle-piece"></i> الأنشطة الصفية</button><?php endif; ?>
                <?php if ($educationalStories): ?><button class="tab-btn<?php echo $firstTabId === 'educational-stories' ? ' active' : ''; ?>" data-tab="educational-stories"><i class="fas fa-book-open"></i> القصة التربوية</button><?php endif; ?>
                <?php if ($lessonSummary): ?><button class="tab-btn<?php echo $firstTabId === 'lesson-summary' ? ' active' : ''; ?>" data-tab="lesson-summary"><i class="fas fa-file-lines"></i> ملخص الدرس</button><?php endif; ?>
                <?php if ($customContent): ?><button class="tab-btn<?php echo $firstTabId === 'custom-content' ? ' active' : ''; ?>" data-tab="custom-content"><i class="fas fa-wand-magic-sparkles"></i> محتوى مخصص</button><?php endif; ?>
                <?php if ($hasExam): ?><button class="tab-btn<?php echo $firstTabId === 'exam-preview' ? ' active' : ''; ?>" data-tab="exam-preview"><i class="fas fa-file-alt"></i> الامتحان</button><?php endif; ?>
                <?php if ($hasPowerPoint): ?><button class="tab-btn<?php echo $firstTabId === 'powerpoint-preview' ? ' active' : ''; ?>" data-tab="powerpoint-preview"><i class="fas fa-file-powerpoint"></i> العرض التقديمي</button><?php endif; ?>
            </div>

            <?php if ($lessonPlan): ?><section class="tab-content<?php echo $firstTabId === 'lesson-plan' ? ' active' : ''; ?>" id="lesson-plan"><?php $renderTabExportButtons('lessonPlanContent', 'تحضير الدرس'); ?><div id="lessonPlanContent"></div></section><?php endif; ?>
            <?php if ($visualMaterials): ?><section class="tab-content<?php echo $firstTabId === 'visual-materials' ? ' active' : ''; ?>" id="visual-materials"><?php $renderTabExportButtons('visualMaterialsContent', 'المواد البصرية'); ?><div id="visualMaterialsContent"></div></section><?php endif; ?>
            <?php if ($mindMaps): ?><section class="tab-content<?php echo $firstTabId === 'mind-maps' ? ' active' : ''; ?>" id="mind-maps"><?php $renderTabExportButtons('eduvisual-root', 'الخرائط الذهنية'); ?><div id="eduvisual-root"></div></section><?php endif; ?>
            <?php if ($questionBank): ?><section class="tab-content<?php echo $firstTabId === 'question-bank' ? ' active' : ''; ?>" id="question-bank"><?php $renderTabExportButtons('questionBankContent', 'بنك الأسئلة'); ?><div id="questionBankContent"></div></section><?php endif; ?>
            <?php if ($classActivities): ?><section class="tab-content<?php echo $firstTabId === 'class-activities' ? ' active' : ''; ?>" id="class-activities"><?php $renderTabExportButtons('classActivitiesContent', 'الأنشطة الصفية'); ?><div id="classActivitiesContent"></div></section><?php endif; ?>
            <?php if ($educationalStories): ?><section class="tab-content<?php echo $firstTabId === 'educational-stories' ? ' active' : ''; ?>" id="educational-stories"><?php $renderTabExportButtons('educationalStoriesContent', 'القصة التربوية'); ?><div id="educationalStoriesContent"></div></section><?php endif; ?>
            <?php if ($lessonSummary): ?><section class="tab-content<?php echo $firstTabId === 'lesson-summary' ? ' active' : ''; ?>" id="lesson-summary"><?php $renderTabExportButtons('lessonSummaryContent', 'ملخص الدرس'); ?><div id="lessonSummaryContent"></div></section><?php endif; ?>
            <?php if ($customContent): ?><section class="tab-content<?php echo $firstTabId === 'custom-content' ? ' active' : ''; ?>" id="custom-content"><?php $renderTabExportButtons('customContentArea', 'المحتوى المخصص'); ?><div id="customContentArea"></div></section><?php endif; ?>
            <?php if ($hasExam): ?>
                <section class="tab-content<?php echo $firstTabId === 'exam-preview' ? ' active' : ''; ?>" id="exam-preview">
                    <?php $renderTabExportButtons('exam-preview', 'الامتحان'); ?>
                    <iframe
                        class="shared-lesson-exam-frame"
                        title="معاينة الامتحان"
                        sandbox="allow-scripts"
                        srcdoc="<?php echo htmlspecialchars((string) $lesson['exam_html'], ENT_QUOTES, 'UTF-8'); ?>"
                    ></iframe>
                </section>
            <?php endif; ?>
            <?php if ($hasPowerPoint): ?>
                <section class="tab-content<?php echo $firstTabId === 'powerpoint-preview' ? ' active' : ''; ?>" id="powerpoint-preview">
                    <div class="shared-lesson-download-card">
                        <i class="fas fa-file-powerpoint fa-4x text-warning mb-3"></i>
                        <h2 class="h4">العرض التقديمي جاهز للتحميل</h2>
                        <p class="text-muted">يمكن فتح الملف في PowerPoint أو Google Slides.</p>
                        <a class="btn btn-outline-primary" href="shared_lesson_download.php?token=<?php echo rawurlencode($token); ?>&amp;type=powerpoint">
                            <i class="fas fa-download me-1"></i>تحميل ملف PPTX
                        </a>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/lesson_display.js?v=1.2"></script>
    <script src="assets/js/eduvisual.js?v=4.1"></script>
    <script nonce="<?php echo htmlspecialchars($scriptNonce, ENT_QUOTES, 'UTF-8'); ?>">
        window.isArchiveView = true;
        window.currentLessonId = 0;
        window.LessonExportConfig = <?php echo json_encode([
            'endpoint' => 'shared_lesson_export.php',
            'publicToken' => $token,
        ], $jsonFlags); ?>;
        window.generatedData = <?php echo json_encode([
            'lesson_plan' => $lessonPlan,
            'question_bank' => $questionBank,
            'visual_materials' => $visualMaterials,
            'class_activities' => $classActivities,
            'educational_stories' => $educationalStories,
            'mind_maps' => $mindMaps,
            'lesson_summary' => $lessonSummary,
            'custom_content' => $customContent,
            'exam_html' => $lesson['exam_html'] ?? null,
        ], $jsonFlags); ?>;

        initLessonDisplay();

        document.querySelectorAll('.tab-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                document.querySelectorAll('.tab-btn').forEach(function (item) { item.classList.remove('active'); });
                document.querySelectorAll('.tab-content').forEach(function (item) { item.classList.remove('active'); });
                button.classList.add('active');
                var panel = document.getElementById(button.dataset.tab);
                if (panel) panel.classList.add('active');
            });
        });

        var printButton = document.getElementById('printSharedLesson');
        if (printButton) {
            printButton.addEventListener('click', function () {
                window.print();
            });
        }

        if (window.EduVisual && window.generatedData.mind_maps) {
            EduVisual.renderAll('eduvisual-root', window.generatedData.mind_maps, {
                theme: 'modern',
                animate: true,
                interactive: false
            });
        }

        document.addEventListener('click', async function (event) {
            var button = event.target.closest('[data-export-format], [data-export-all-format]');
            if (!button) return;

            var perTabFormat = button.dataset.exportFormat || '';
            var allFormat = button.dataset.exportAllFormat || '';
            var format = perTabFormat || allFormat;
            var actionNames = perTabFormat
                ? { html: 'exportTabToHtml', pdf: 'exportTabToPdf', word: 'exportTabToWord', print: 'exportTabToPrint' }
                : { html: 'exportAllToHtml', pdf: 'exportAllToPdf', word: 'exportAllToWord', print: 'exportAllToPrint' };
            var action = window[actionNames[format]];
            var status = document.getElementById('sharedLessonExportStatus');
            var targetContainer = button.closest('[data-export-target]');
            var target = targetContainer ? targetContainer.dataset.exportTarget : '';

            if (typeof action !== 'function' || (perTabFormat && !target)) {
                if (status) status.textContent = 'تعذر بدء التصدير. حدّث الصفحة وحاول مرة أخرى.';
                return;
            }

            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            if (status) status.textContent = 'جارٍ تجهيز ملف التصدير...';
            try {
                var succeeded = await action(target);
                if (status) {
                    status.textContent = succeeded
                        ? (format === 'print' ? 'تم فتح نسخة الطباعة.' : 'تم تجهيز ملف التنزيل.')
                        : 'تعذر تجهيز التصدير. راجع الرسالة الظاهرة وحاول مرة أخرى.';
                }
            } finally {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        });
    </script>
    <script src="assets/js/lesson-export.js?v=2"></script>
<?php endif; ?>
</body>
</html>
