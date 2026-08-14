<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(300);
header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../config/database.php';
require_once '../../config/ai_config.php';
require_once '../../classes/LessonGenerator.php';
require_once '../../classes/LessonPowerPointGenerator.php';
require_once '../../classes/CanvaIntegration.php';
require_once '../../classes/LessonPptTemplateLibrary.php';
require_once '../../classes/FileUploadGuard.php';
require_once '../../src/Modules/LearningContent/AiLessonLifecycleService.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'غير مصرح لك بالوصول'], JSON_UNESCAPED_UNICODE); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'طريقة طلب غير صالحة'], JSON_UNESCAPED_UNICODE); exit;
}
requireCsrfPost();

$teacherId = (int)$_SESSION['user_id'];
session_write_close();
$db = (new Database())->getConnection();
$lessonId = null;
$temporaryUploadPaths = [];
register_shutdown_function(static function () use (&$temporaryUploadPaths): void {
    foreach ($temporaryUploadPaths as $temporaryPath) {
        if (is_string($temporaryPath) && is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
});

try {
    if (!checkDailyLimit($db, $teacherId, 1)) throw new RuntimeException('تم بلوغ الحد اليومي لاستخدام الذكاء الاصطناعي');
    $title = mb_substr(trim($_POST['title'] ?? ''), 0, 250);
    $content = mb_substr(trim($_POST['content'] ?? ''), 0, 100000);
    $language = in_array($_POST['language'] ?? '', ['ar','en','fr','de'], true) ? $_POST['language'] : 'ar';
    $theme = in_array($_POST['theme'] ?? '', ['modern','colorful','formal','gradient','nature','tech','creative','minimal','islamic','scientific'], true) ? $_POST['theme'] : 'modern';
    $slides = max(8, min(30, (int)($_POST['slides'] ?? 20)));

    // معالجة الملفات المرفوعة
    $uploadedContent = '';

    // معالجة PDF
    if (isset($_FILES['pdf']) && (int) $_FILES['pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
        $pdfFile = $_FILES['pdf'];
        FileUploadGuard::validate($pdfFile, ['pdf' => ALLOWED_PDF_TYPES], GEMINI_MAX_PDF_SIZE);
        FileUploadGuard::assertSafeOriginalName((string)$pdfFile['name'], ['pdf']);

        if ($pdfFile['size'] > GEMINI_MAX_PDF_SIZE) {
            echo json_encode(['success' => false, 'message' => 'حجم ملف PDF يتجاوز الحد المسموح']);
            exit;
        }

        $mimeType = detectMimeType($pdfFile['tmp_name']);

        if (!in_array($mimeType, ALLOWED_PDF_TYPES)) {
            echo json_encode(['success' => false, 'message' => 'نوع الملف غير مدعوم']);
            exit;
        }

        $pdfFileName = FileUploadGuard::randomFileName('pdf', 'pdf');
        $pdfPath = UPLOADS_PDF_PATH . $pdfFileName;

        if (!move_uploaded_file($pdfFile['tmp_name'], $pdfPath)) {
            echo json_encode(['success' => false, 'message' => 'فشل في حفظ ملف PDF']);
            exit;
        }
        $temporaryUploadPaths[] = $pdfPath;

        $generator = new LessonGenerator($db, $teacherId);
        $generator->setLanguage($language);
        $extractedText = $generator->processPDF($pdfPath);

        if ($extractedText) {
            $uploadedContent .= "\n\n[محتوى من ملف PDF]:\n" . $extractedText;
        } else {
            $pdfError = $generator->getLastError();
            echo json_encode([
                'success' => false,
                'message' => 'فشل في استخراج المحتوى من ملف PDF: ' . ($pdfError ?: 'خطأ غير معروف')
            ]);
            exit;
        }
    }

    // معالجة الصورة
    if (isset($_FILES['image']) && (int) $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $imageFile = $_FILES['image'];
        FileUploadGuard::validate($imageFile, [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
        ], GEMINI_MAX_IMAGE_SIZE);
        $safeImageExtension = FileUploadGuard::assertSafeOriginalName((string)$imageFile['name'], ['jpg', 'jpeg', 'png', 'webp', 'gif']);

        if ($imageFile['size'] > GEMINI_MAX_IMAGE_SIZE) {
            echo json_encode(['success' => false, 'message' => 'حجم الصورة يتجاوز الحد المسموح']);
            exit;
        }

        $mimeType = detectMimeType($imageFile['tmp_name']);

        if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
            echo json_encode(['success' => false, 'message' => 'نوع الصورة غير مدعوم']);
            exit;
        }

        $imageFileName = FileUploadGuard::randomFileName('img', $safeImageExtension);
        $imagePath = UPLOADS_IMAGE_PATH . $imageFileName;

        if (!move_uploaded_file($imageFile['tmp_name'], $imagePath)) {
            echo json_encode(['success' => false, 'message' => 'فشل في حفظ الصورة']);
            exit;
        }
        $temporaryUploadPaths[] = $imagePath;

        $generator = new LessonGenerator($db, $teacherId);
        $generator->setLanguage($language);
        $extractedText = $generator->processImage($imagePath);

        if ($extractedText) {
            $uploadedContent .= "\n\n[محتوى من الصورة]:\n" . $extractedText;
        } else {
            $imageError = $generator->getLastError();
            echo json_encode([
                'success' => false,
                'message' => 'فشل في استخراج المحتوى من الصورة: ' . ($imageError ?: 'خطأ غير معروف')
            ]);
            exit;
        }
    }

    $fullContent = $content . $uploadedContent;
    $contentLength = mb_strlen(trim($fullContent));

    // --- طبقة 1: رفض صارم (محتوى لا يكفي لتوليد شيء حقيقي) ---
    if ($title === '') {
        throw new InvalidArgumentException('يرجى إدخال عنوان الدرس');
    }
    if ($contentLength < 80) {
        throw new InvalidArgumentException(
            'المحتوى التعليمي قصير جداً (' . $contentLength . ' حرف). ' .
            'أدخل على الأقل 80 حرفاً لضمان توليد شرائح ذات محتوى حقيقي.'
        );
    }

    // --- طبقة 2: تحذير (محتوى محدود — التوليد يعمل لكن الشرائح ستكون أقل) ---
    $contentWarning = null;
    if ($contentLength < 400) {
        $contentWarning = 'المحتوى المدخل محدود (' . $contentLength . ' حرف). ' .
            'تم توليد عدد شرائح مخفّض بناءً على ما هو متاح فعلاً. ' .
            'لنتيجة أفضل أدخل محتوى أوفر.';
        // تخفيض عدد الشرائح المستهدف تناسبياً مع حجم المحتوى
        $slides = min($slides, max(5, (int)round($contentLength / 60)));
    }

    $generator = new LessonGenerator($db, $teacherId);
    $generator->setLanguage($language);
    $generator->setSelectedSections([]);

    // إنشاء سجل الدرس
    $gradeLevel = isset($_POST['grade_level']) && trim($_POST['grade_level']) !== '' ? mb_substr(trim($_POST['grade_level']), 0, 50) : null;
    $lessonId = $generator->createLesson('[PowerPoint] ' . $title, $fullContent, 45, $language, $gradeLevel);
    if (!$lessonId) throw new RuntimeException($generator->getLastError() ?: 'تعذر إنشاء سجل العرض');

    // توليد شرائح المحتوى الفعلي للدرس عبر الـ prompt المخصص للباوربوينت
    $slidesList = $generator->generatePowerPointSlides($fullContent, $slides);
    if (!$slidesList) throw new RuntimeException($generator->getLastError() ?: 'تعذر توليد شرائح العرض التقديمي');

    // حفظ الشرائح في حقل generated_prep كـ JSON مبسّط
    $planForDb = ['powerpoint_slides' => $slidesList, 'lesson_title' => $title];
    if (!$generator->saveResults($lessonId, $planForDb, null, null)) {
        throw new RuntimeException($generator->getLastError() ?: 'تعذر حفظ بيانات العرض');
    }

    // فحص وجود قالب Canva — يُستخدم المحدد من المعلم أولاً، ثم النشط كاحتياطي
    $canvaTemplatePath = null;
    $activeTpl = null;
    $selectedInternalTemplateId = (int)($_POST['internal_ppt_template_id'] ?? 0);
    $usedInternalTemplateId = null;
    try {
        $canvaInt  = new CanvaIntegration($db);
        $selectedId = (int)($_POST['canva_template_id'] ?? 0);
        if ($selectedInternalTemplateId > 0) {
            $selectedId = 0;
        }

        if ($selectedInternalTemplateId > 0 && $selectedId <= 0) {
            $templateLibrary = new LessonPptTemplateLibrary($db);
            $internalTemplate = $templateLibrary->find($selectedInternalTemplateId);
            if ($internalTemplate && !empty($internalTemplate['file_path'])) {
                $internalFull = dirname(__DIR__, 2) . '/' . ltrim($internalTemplate['file_path'], '/\\');
                if (is_file($internalFull)) {
                    $canvaTemplatePath = $internalFull;
                    $usedInternalTemplateId = (int)$internalTemplate['id'];
                }
            }
        }

        if (!$canvaTemplatePath && $selectedId > 0) {
            // استخدام القالب الذي اختاره المعلم بالتحديد
            $stmt = $db->prepare('SELECT * FROM canva_templates WHERE id = ? LIMIT 1');
            $stmt->execute([$selectedId]);
            $activeTpl = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!$canvaTemplatePath) {
            // لم يختر المعلم قالباً → استخدام النشط الافتراضي إن وُجد
            $activeTpl = $canvaInt->getActiveTemplate();
        }

        if ($activeTpl && ($activeTpl['template_type'] ?? 'design') === 'brand_template') {
            $canvaTitle = '[PowerPoint] ' . $title;
            $canvaResult = $canvaInt->autofillBrandTemplateAsPptx($activeTpl['design_id'], $canvaTitle, [
                'title' => $title,
                'language' => $language,
                'summary' => mb_substr($fullContent, 0, 1200),
                'slides' => $slidesList,
            ]);

            if (!empty($canvaResult['success']) && !empty($canvaResult['path'])) {
                (new \EduCore\Modules\LearningContent\AiLessonLifecycleService($db))->update(
                    (int) $lessonId,
                    $teacherId,
                    ['powerpoint_path' => $canvaResult['path'], 'powerpoint_theme' => 'canva_autofill', 'powerpoint_status' => 'completed', 'status' => 'completed'],
                    'ai_lesson_powerpoint_completed',
                    ['generator' => 'canva_autofill']
                );

                echo json_encode([
                    'success'      => true,
                    'lesson_id'    => $lessonId,
                    'download_url' => 'lesson_download.php?id=' . $lessonId . '&type=powerpoint',
                    'warning'      => $contentWarning,
                    'canva_used'   => true,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            error_log('Canva Autofill fallback: ' . ($canvaResult['error'] ?? 'unknown error'));
        }

        if ($activeTpl && !empty($activeTpl['pptx_local_path'])) {
            $tplFull = dirname(__DIR__, 2) . '/' . $activeTpl['pptx_local_path'];
            if (is_file($tplFull)) {
                $canvaTemplatePath = $tplFull;
            }
        }

        if (!$canvaTemplatePath && $selectedId <= 0) {
            $templateLibrary = new LessonPptTemplateLibrary($db);
            $internalTemplate = $selectedInternalTemplateId > 0
                ? $templateLibrary->find($selectedInternalTemplateId)
                : $templateLibrary->chooseBestTemplate([
                    'title' => $title,
                    'language' => $language,
                    'summary' => mb_substr($fullContent, 0, 1200),
                    'slides' => $slidesList,
                ]);
            if ($internalTemplate && !empty($internalTemplate['file_path'])) {
                $internalFull = dirname(__DIR__, 2) . '/' . ltrim($internalTemplate['file_path'], '/\\');
                if (is_file($internalFull)) {
                    $canvaTemplatePath = $internalFull;
                    $usedInternalTemplateId = (int)$internalTemplate['id'];
                }
            }
        }

        if (!$canvaTemplatePath && $selectedId <= 0) {
            $smartCanva = $canvaInt->findAndExportSmartDesignTemplate([
                'title' => $title,
                'language' => $language,
                'summary' => mb_substr($fullContent, 0, 1200),
                'slides' => $slidesList,
            ]);

            if (!empty($smartCanva['success']) && !empty($smartCanva['path'])) {
                $smartFull = dirname(__DIR__, 2) . '/' . $smartCanva['path'];
                if (is_file($smartFull)) {
                    $canvaTemplatePath = $smartFull;
                }
            } elseif (!empty($smartCanva['error'])) {
                error_log('Smart Canva design fallback: ' . $smartCanva['error']);
            }
        }
    } catch (Throwable $ce) {
        error_log('Canva template check failed: ' . $ce->getMessage());
    }

    if (!$canvaTemplatePath && (int)($_POST['canva_template_id'] ?? 0) <= 0) {
        try {
            $templateLibrary = new LessonPptTemplateLibrary($db);
            $internalTemplate = $selectedInternalTemplateId > 0
                ? $templateLibrary->find($selectedInternalTemplateId)
                : $templateLibrary->chooseBestTemplate([
                    'title' => $title,
                    'language' => $language,
                    'summary' => mb_substr($fullContent, 0, 1200),
                    'slides' => $slidesList,
                ]);
            if ($internalTemplate && !empty($internalTemplate['file_path'])) {
                $internalFull = dirname(__DIR__, 2) . '/' . ltrim($internalTemplate['file_path'], '/\\');
                if (is_file($internalFull)) {
                    $canvaTemplatePath = $internalFull;
                    $usedInternalTemplateId = (int)$internalTemplate['id'];
                }
            }
        } catch (Throwable $tplError) {
            error_log('Internal PPT template fallback failed: ' . $tplError->getMessage());
        }
    }

    // توليد ملف PPTX من بنية الشرائح الجديدة
    $relative = 'storage/exports/lessons/' . $teacherId . '/lesson_' . $lessonId . '.pptx';
    (new LessonPowerPointGenerator())->generateFromSlides(
        [
            'title'                => $title,
            'language'             => $language,
            'slides'               => $slidesList,
            'canva_template_path'  => $canvaTemplatePath,
        ],
        dirname(__DIR__, 2) . '/' . $relative,
        $theme
    );

    (new \EduCore\Modules\LearningContent\AiLessonLifecycleService($db))->update(
        (int) $lessonId,
        $teacherId,
        ['powerpoint_path' => $relative, 'powerpoint_theme' => $theme, 'powerpoint_status' => 'completed', 'status' => 'completed'],
        'ai_lesson_powerpoint_completed',
        ['generator' => 'local']
    );

    echo json_encode([
        'success'      => true,
        'lesson_id'    => $lessonId,
        'download_url' => 'lesson_download.php?id=' . $lessonId . '&type=powerpoint',
        'warning'      => $contentWarning,
        'internal_template_id' => $usedInternalTemplateId,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('generate_powerpoint_only error: [' . get_class($e) . '] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if ($lessonId) {
        try {
            (new \EduCore\Modules\LearningContent\AiLessonLifecycleService($db))->update(
                (int) $lessonId,
                $teacherId,
                ['status' => 'failed', 'powerpoint_status' => 'failed', 'generation_error' => mb_substr($e->getMessage(), 0, 1000)],
                'ai_lesson_powerpoint_failed',
                ['generator' => 'powerpoint_only']
            );
        } catch (\Throwable $dbErr) { /* ignore */ }
    }
    $userMsg = $e instanceof InvalidArgumentException
        ? $e->getMessage()
        : 'تعذر إنشاء العرض التقديمي. راجع المحتوى وحاول مرة أخرى.';
    http_response_code($e instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $userMsg], JSON_UNESCAPED_UNICODE);
}
