<?php
/**
 * معالج AJAX لتوليد تحضير الدروس
 * AJAX Handler for AI Lesson Generation
 */

// منع عرض أخطاء PHP كـ HTML - تُسجل في الـ log فقط
ini_set('display_errors', 0);
error_reporting(E_ALL);

// زيادة وقت التنفيذ لأن التوليد يتضمن عدة طلبات AI متتالية
set_time_limit(600);
ini_set('max_execution_time', 600);

header('Content-Type: application/json; charset=utf-8');

// معالج الأخطاء المخصص لإرجاع JSON بدلاً من HTML
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true; // لا تعرض الخطأ
});

// تحميل الملفات المطلوبة
require_once '../../includes/session_config.php';
require_once '../../config/database.php';
require_once '../../config/ai_config.php';
require_once '../../classes/LessonGenerator.php';
require_once '../../classes/ExamGenerator.php';
require_once '../../classes/LessonPowerPointGenerator.php';
require_once '../../classes/CanvaIntegration.php';
require_once '../../classes/LessonPptTemplateLibrary.php';
require_once '../../classes/SchemaReadinessGuard.php';
require_once '../../classes/FileUploadGuard.php';
require_once '../../src/Modules/LearningContent/AiLessonLifecycleService.php';
require_once '../../includes/csrf.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !$csrf || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(419);
    echo json_encode(['success'=>false,'message'=>'انتهت صلاحية رمز الأمان. أعد تحميل الصفحة وحاول مرة أخرى.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// إغلاق الجلسة فوراً لمنع تعليق الطلبات الأخرى (Session Locking)
session_write_close();

// التحقق من نوع الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
    exit;
}

$temporaryUploadPaths = [];
register_shutdown_function(static function () use (&$temporaryUploadPaths): void {
    foreach ($temporaryUploadPaths as $temporaryPath) {
        if (is_string($temporaryPath) && is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
});

try {
    // الاتصال بقاعدة البيانات
    $database = new Database();
    $db = $database->getConnection();

    (new SchemaReadinessGuard($db))->assertColumns('ai_lessons', [
        'class_activities',
        'educational_stories',
        'mind_maps',
        'lesson_summary',
        'custom_content',
    ]);
    $teacherId = $_SESSION['user_id'];

    // التحقق من حدود الاستخدام اليومي
    if (!checkDailyLimit($db, $teacherId)) {
        echo json_encode([
            'success' => false,
            'message' => 'لقد تجاوزت الحد اليومي المسموح (' . GEMINI_DAILY_LIMIT . ' طلب). يرجى المحاولة غداً.'
        ]);
        exit;
    }

    // الحصول على البيانات
    $language = isset($_POST['language']) ? $_POST['language'] : 'ar';
    $duration = max(10, min(180, intval($_POST['duration'] ?? 45)));
    $title = mb_substr(trim($_POST['title'] ?? ''), 0, 250);
    $content = mb_substr(trim($_POST['content'] ?? ''), 0, 100000);
    $generatePowerPoint = ($_POST['generate_powerpoint'] ?? '0') === '1';
    $powerPointTheme = in_array($_POST['powerpoint_theme'] ?? '', ['modern', 'colorful', 'formal', 'gradient', 'nature', 'tech', 'creative', 'minimal', 'islamic', 'scientific'], true) ? $_POST['powerpoint_theme'] : 'modern';
    $powerPointSlides = max(6, min(18, intval($_POST['powerpoint_slides'] ?? 12)));

    $examDuration = max(5, min(180, intval($_POST['exam_duration'] ?? 20)));
    $examModels = max(1, min(4, intval($_POST['exam_models'] ?? 3)));
    $antiCheat = isset($_POST['anti_cheat']) && $_POST['anti_cheat'] === '1';
    $studentInfo = isset($_POST['student_info']) && $_POST['student_info'] === '1';

    // إعدادات أنواع الأسئلة
    $mcCount = max(0, min(50, intval($_POST['mc_count'] ?? 10)));
    $tfCount = max(0, min(50, intval($_POST['tf_count'] ?? 10)));
    $essayCount = max(0, min(20, intval($_POST['essay_count'] ?? 0)));
    $modelType = in_array($_POST['model_type'] ?? '', ['shuffle', 'different'], true) ? $_POST['model_type'] : 'shuffle';
    $answerKeyEnabled = isset($_POST['answer_key']) && $_POST['answer_key'] === '1';

    // بنك الأسئلة يتولّد تلقائياً بجميع الأنواع — لا حاجة لأعداد مسبقة

    // إعدادات العناصر والأقسام المختارة
    $selectedElements = isset($_POST['elements']) ? json_decode($_POST['elements'], true) : null;
    $sectionsProvided = array_key_exists('sections', $_POST);
    $selectedSections = $sectionsProvided ? json_decode((string) $_POST['sections'], true) : null;
    $selectedPhases = isset($_POST['phases']) ? json_decode($_POST['phases'], true) : null;
    $customPrompts = isset($_POST['custom_prompts']) ? json_decode($_POST['custom_prompts'], true) : null;

    // عمر الطلاب المستهدف للقصة التربوية (يُطبَّع على مستوى اللغة وعمق المفاهيم).
    // يُقبل نصاً أو رقماً، ويُطبَّع داخل LessonGenerator إلى النطاق 4-25 أو null.
    $studentAge = isset($_POST['student_age']) && $_POST['student_age'] !== '' ? $_POST['student_age'] : null;
    $gradeLevel = isset($_POST['grade_level']) && trim($_POST['grade_level']) !== '' ? mb_substr(trim($_POST['grade_level']), 0, 50) : null;

    // Default elements if none specified
    if (!$selectedElements || !is_array($selectedElements)) {
        $selectedElements = ['objectives', 'strategies', 'lesson_phases', 'resources'];
    }
    $allowedElements = ['objectives','strategies','lesson_phases','resources','introduction','evaluation','homework','differentiation','closure_summary'];
    $allowedSections = ['question_bank','visual_materials','class_activities','educational_stories','mind_maps','lesson_summary'];
    $selectedElements = array_values(array_intersect($selectedElements, $allowedElements));
    // الطلب القديم الذي لا يرسل sections يحصل على الإعدادات الافتراضية؛ أما [] الصريحة فتعني تحضيراً بلا أقسام إضافية.
    if (!is_array($selectedSections)) {
        $selectedSections = ['question_bank', 'visual_materials', 'class_activities', 'educational_stories', 'mind_maps', 'lesson_summary'];
    } else {
        $selectedSections = array_values(array_intersect(
            array_filter($selectedSections, 'is_string'),
            $allowedSections
        ));
    }
    $estimatedCalls = 1 + count($selectedSections) + (empty($customPrompts) ? 0 : 1);
    if (!checkDailyLimit($db, $teacherId, $estimatedCalls)) {
        echo json_encode(['success'=>false,'message'=>'لا تكفي الحصة اليومية لإكمال الأقسام المحددة. قلل الأقسام الاختيارية أو حاول غداً.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Validate custom prompts
    if ($customPrompts && is_array($customPrompts)) {
        $customPrompts = array_slice(array_values(array_filter(array_map(
            static fn($prompt): string => is_string($prompt) ? trim($prompt) : '',
            $customPrompts
        ))), 0, 6);
    } else {
        $customPrompts = [];
    }

    // معالجة الملفات المرفوعة
    $uploadedContent = '';

    // معالجة PDF
    if (isset($_FILES['pdf']) && (int) $_FILES['pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
        $pdfFile = $_FILES['pdf'];
        FileUploadGuard::validate($pdfFile, ['pdf' => ALLOWED_PDF_TYPES], GEMINI_MAX_PDF_SIZE);
        FileUploadGuard::assertSafeOriginalName((string)$pdfFile['name'], ['pdf']);

        // التحقق من الحجم
        if ($pdfFile['size'] > GEMINI_MAX_PDF_SIZE) {
            echo json_encode(['success' => false, 'message' => 'حجم ملف PDF يتجاوز الحد المسموح']);
            exit;
        }

        // التحقق من النوع
        $mimeType = detectMimeType($pdfFile['tmp_name']);

        if (!in_array($mimeType, ALLOWED_PDF_TYPES)) {
            echo json_encode(['success' => false, 'message' => 'نوع الملف غير مدعوم']);
            exit;
        }

        // حفظ الملف
        $pdfFileName = FileUploadGuard::randomFileName('pdf', 'pdf');
        $pdfPath = UPLOADS_PDF_PATH . $pdfFileName;

        if (!move_uploaded_file($pdfFile['tmp_name'], $pdfPath)) {
            echo json_encode(['success' => false, 'message' => 'فشل في حفظ ملف PDF']);
            exit;
        }
        $temporaryUploadPaths[] = $pdfPath;

        // استخراج المحتوى باستخدام Gemini
        $generator = new LessonGenerator($db, $teacherId);
        $generator->setLanguage($language);
        $extractedText = $generator->processPDF($pdfPath);

        if ($extractedText) {
            $uploadedContent .= "\n\n[محتوى من ملف PDF]:\n" . $extractedText;
            @unlink($pdfPath);
        }
        else {
            // إذا فشل استخراج المحتوى، أظهر الخطأ
            $pdfError = $generator->getLastError();
            echo json_encode([
                'success' => false,
                'message' => 'فشل في استخراج المحتوى من ملف PDF: ' . ($pdfError ?: 'خطأ غير معروف')
            ]);
            exit;
        }

    // حذف الملف بعد المعالجة (اختياري)
    // unlink($pdfPath);
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
        FileUploadGuard::assertSafeOriginalName((string)$imageFile['name'], ['jpg', 'jpeg', 'png', 'webp', 'gif']);

        // التحقق من الحجم
        if ($imageFile['size'] > GEMINI_MAX_IMAGE_SIZE) {
            echo json_encode(['success' => false, 'message' => 'حجم الصورة يتجاوز الحد المسموح']);
            exit;
        }

        // التحقق من النوع
        $mimeType = detectMimeType($imageFile['tmp_name']);

        if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
            echo json_encode(['success' => false, 'message' => 'نوع الصورة غير مدعوم']);
            exit;
        }

        // حفظ الصورة
        $safeExtensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $ext = $safeExtensions[$mimeType] ?? null;
        if (!$ext) {
            echo json_encode(['success' => false, 'message' => 'امتداد الصورة غير آمن']);
            exit;
        }
        $imageFileName = FileUploadGuard::randomFileName('img', $ext);
        $imagePath = UPLOADS_IMAGE_PATH . $imageFileName;

        if (!move_uploaded_file($imageFile['tmp_name'], $imagePath)) {
            echo json_encode(['success' => false, 'message' => 'فشل في حفظ الصورة']);
            exit;
        }
        $temporaryUploadPaths[] = $imagePath;

        // استخراج المحتوى باستخدام Gemini Vision
        $generator = new LessonGenerator($db, $teacherId);
        $generator->setLanguage($language);
        $extractedText = $generator->processImage($imagePath);

        if ($extractedText) {
            $uploadedContent .= "\n\n[محتوى من الصورة]:\n" . $extractedText;
            @unlink($imagePath);
        }
        else {
            // إذا فشل استخراج المحتوى، أظهر الخطأ
            $imageError = $generator->getLastError();
            echo json_encode([
                'success' => false,
                'message' => 'فشل في استخراج المحتوى من الصورة: ' . ($imageError ?: 'خطأ غير معروف')
            ]);
            exit;
        }

    // حذف الصورة بعد المعالجة (اختياري)
    // unlink($imagePath);
    }

    // دمج المحتوى
    $fullContent = $content . $uploadedContent;

    if (empty(trim($fullContent))) {
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على محتوى للمعالجة']);
        exit;
    }

    // إنشاء المولد
    $generator = new LessonGenerator($db, $teacherId);
    $generator->setLanguage($language);
    $generator->setSelectedElements($selectedElements);
    $generator->setSelectedSections($selectedSections);
    if ($selectedPhases && is_array($selectedPhases)) {
        $generator->setSelectedPhases($selectedPhases);
    }
    // عمر الطلاب للقصة التربوية (إن أُرسل) — يُطبَّع داخل الـ setter.
    if ($studentAge !== null) {
        $generator->setStudentAge($studentAge);
    }

    // إنشاء سجل الدرس
    $lessonTitle = $title ?: 'درس جديد - ' . date('Y-m-d H:i');
    $lessonId = $generator->createLesson($lessonTitle, $fullContent, $duration, $language, $gradeLevel);

    if (!$lessonId) {
        echo json_encode(['success' => false, 'message' => 'فشل في إنشاء سجل الدرس: ' . $generator->getLastError()]);
        exit;
    }

    // توليد جميع المحتويات
    $results = $generator->generateAll($fullContent, $duration);

    // توليد المحتوى المخصص إذا تم تحديد عناصر مخصصة
    $customContent = [];
    $customContentError = null;
    if (!empty($customPrompts)) {
        // تأخير بسيط لتجنب تجاوز حد الاستخدام بعد الطلبات المتعددة
        usleep(500000); // 0.5 ثانية
        
        $customContent = $generator->generateCustomContent($fullContent, $customPrompts);
        
        // إذا فشل التوليد، حاول مرة أخرى
        if (empty($customContent)) {
            $customContentError = $generator->getLastError();
            error_log('Custom content generation failed (attempt 1): ' . $customContentError);
            
            // إعادة المحاولة بعد تأخير أطول
            sleep(2);
            $customContent = $generator->generateCustomContent($fullContent, $customPrompts);
            
            if (empty($customContent)) {
                $customContentError = $generator->getLastError();
                error_log('Custom content generation failed (attempt 2): ' . $customContentError);
            } else {
                $customContentError = null; // نجح في المحاولة الثانية
            }
        }
    }

    // التحقق من نجاح توليد تحضير الدرس على الأقل
    if (!$results['lesson_plan'] && !empty($results['errors'])) {
        (new \EduCore\Modules\LearningContent\AiLessonLifecycleService($db))->update(
            (int) $lessonId,
            (int) $teacherId,
            ['status' => 'failed', 'generation_error' => mb_substr(implode(' | ', $results['errors']), 0, 1000)],
            'ai_lesson_generation_failed',
            ['generation_stage' => 'lesson_plan']
        );
        echo json_encode([
            'success' => false,
            'message' => 'فشل في التوليد: ' . implode(' | ', $results['errors'])
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // توليد الامتحان الإلكتروني
    $examHtml = null;
    $examWarning = null;
    $qbWarnings = [];

    if ($results['question_bank'] && is_array($results['question_bank']) && count($results['question_bank']) > 0) {
        // حساب الأعداد الفعلية المولّدة
        $qb = $results['question_bank'];
        $actualMc = isset($qb['multiple_choice']) ? count($qb['multiple_choice']) : 0;
        $actualTf = isset($qb['true_false']) ? count($qb['true_false']) : 0;
        $actualGrad = isset($qb['graduated']) ? count($qb['graduated']) : 0;

        $examGenerator = new ExamGenerator($language);
        $examGenerator->setQuestions($results['question_bank']);
        $examGenerator->setDuration($examDuration);
        $examGenerator->setModelsCount($examModels);
        $examGenerator->setPassingPercentage(50);
        $examGenerator->setAntiCheatEnabled($antiCheat);
        $examGenerator->setStudentInfoEnabled($studentInfo);
        $examGenerator->setMCCount($mcCount);
        $examGenerator->setTFCount($tfCount);
        $examGenerator->setEssayCount($essayCount);
        $examGenerator->setModelType($modelType);

        // تطبيق ثيم الامتحان
        $examTheme = isset($_POST['exam_theme']) ? $_POST['exam_theme'] : 'classic';
        $examGenerator->setTheme($examTheme);

        $examTitle = $language === 'ar' ? 'امتحان: ' . $lessonTitle : 'Exam: ' . $lessonTitle;
        $examHtml = $examGenerator->generateExamHTML($examTitle);
    }
    else {
        $examWarning = 'لم يتم توليد الامتحان: محتوى الدرس غير كافٍ لتوليد أسئلة. أضف المزيد من المحتوى التعليمي.';
    }

    // حفظ النتائج
    $saveResult = $generator->saveResults(
        $lessonId,
        $results['lesson_plan'],
        $results['question_bank'],
        $results['visual_materials'],
        $examHtml,
        $examDuration,
        $examModels,
        $results['class_activities'] ?? null,
        $results['educational_stories'] ?? null,
        $results['mind_maps'] ?? null,
        $results['lesson_summary'] ?? null,
        $customContent,
        $mcCount,
        $tfCount,
        $essayCount
    );
    
    if (!$saveResult) {
        error_log("generate_lesson: saveResults failed for lesson {$lessonId}: " . $generator->getLastError());
        (new \EduCore\Modules\LearningContent\AiLessonLifecycleService($db))->update(
            (int) $lessonId,
            (int) $teacherId,
            ['status' => 'failed', 'generation_error' => mb_substr($generator->getLastError(), 0, 1000)],
            'ai_lesson_generation_failed',
            ['generation_stage' => 'result_persistence']
        );
        echo json_encode(['success'=>false,'message'=>'تم توليد المحتوى لكن تعذر حفظه بأمان. حاول مرة أخرى.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $powerPointUrl = null;
    $powerPointError = null;
    if ($generatePowerPoint && $saveResult) {
        try {
            // تحديد قالب Canva للتحضير الكامل
            $canvaTemplatePath = null;
            $selectedInternalTemplateId = (int)($_POST['internal_ppt_template_id'] ?? 0);
            try {
                $canvaInt2 = new CanvaIntegration($db);
                $canvaSelectedId = (int)($_POST['canva_template_id'] ?? 0);
                if ($selectedInternalTemplateId > 0) {
                    $canvaSelectedId = 0;
                }
                if ($selectedInternalTemplateId > 0 && $canvaSelectedId <= 0) {
                    $templateLibrary = new LessonPptTemplateLibrary($db);
                    $internalTemplate = $templateLibrary->find($selectedInternalTemplateId);
                    if ($internalTemplate && !empty($internalTemplate['file_path'])) {
                        $internalFull = dirname(__DIR__, 2) . '/' . ltrim($internalTemplate['file_path'], '/\\');
                        if (is_file($internalFull)) {
                            $canvaTemplatePath = $internalFull;
                        }
                    }
                    $cTpl = null;
                } elseif ($canvaSelectedId > 0) {
                    $cStmt = $db->prepare('SELECT * FROM canva_templates WHERE id = ? LIMIT 1');
                    $cStmt->execute([$canvaSelectedId]);
                    $cTpl = $cStmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $cTpl = $canvaInt2->getActiveTemplate();
                }
                $canvaSlides = [];
                $lessonPlan = is_array($results['lesson_plan'] ?? null) ? $results['lesson_plan'] : [];
                foreach ($lessonPlan as $key => $value) {
                    if (is_array($value)) {
                        $points = [];
                        foreach ($value as $item) {
                            if (is_scalar($item)) {
                                $points[] = (string)$item;
                            }
                        }
                        $canvaSlides[] = [
                            'title' => is_string($key) ? $key : 'شريحة',
                            'points' => $points,
                        ];
                    } elseif (is_scalar($value)) {
                        $canvaSlides[] = [
                            'title' => is_string($key) ? $key : 'شريحة',
                            'points' => [(string)$value],
                        ];
                    }
                }

                $summaryText = '';
                if (!empty($results['lesson_summary'])) {
                    $summaryText = is_array($results['lesson_summary'])
                        ? implode("\n", array_map('strval', $results['lesson_summary']))
                        : (string)$results['lesson_summary'];
                }

                if ($cTpl && ($cTpl['template_type'] ?? 'design') === 'brand_template') {
                    $canvaResult = $canvaInt2->autofillBrandTemplateAsPptx($cTpl['design_id'], '[Lesson] ' . $lessonTitle, [
                        'title' => $lessonTitle,
                        'language' => $language,
                        'summary' => $summaryText,
                        'slides' => $canvaSlides,
                    ]);

                    if (!empty($canvaResult['success']) && !empty($canvaResult['path'])) {
                        (new \EduCore\Modules\LearningContent\AiLessonLifecycleService($db))->update(
                            (int) $lessonId,
                            (int) $teacherId,
                            ['powerpoint_path' => $canvaResult['path'], 'powerpoint_theme' => 'canva_autofill', 'powerpoint_status' => 'completed'],
                            'ai_lesson_powerpoint_completed',
                            ['generator' => 'canva_autofill']
                        );
                        $powerPointUrl = 'lesson_download.php?id=' . $lessonId . '&type=powerpoint';
                        throw new RuntimeException('__CANVA_POWERPOINT_DONE__');
                    }

                    error_log('Canva Autofill fallback in generate_lesson: ' . ($canvaResult['error'] ?? 'unknown error'));
                }
                if ($cTpl && !empty($cTpl['pptx_local_path'])) {
                    $cFull = dirname(__DIR__, 2) . '/' . $cTpl['pptx_local_path'];
                    if (is_file($cFull)) $canvaTemplatePath = $cFull;
                }

                if (!$canvaTemplatePath && $canvaSelectedId <= 0) {
                    $templateLibrary = new LessonPptTemplateLibrary($db);
                    $internalTemplate = $selectedInternalTemplateId > 0
                        ? $templateLibrary->find($selectedInternalTemplateId)
                        : $templateLibrary->chooseBestTemplate([
                            'title' => $lessonTitle,
                            'language' => $language,
                            'summary' => $summaryText,
                            'slides' => $canvaSlides,
                        ]);
                    if ($internalTemplate && !empty($internalTemplate['file_path'])) {
                        $internalFull = dirname(__DIR__, 2) . '/' . ltrim($internalTemplate['file_path'], '/\\');
                        if (is_file($internalFull)) {
                            $canvaTemplatePath = $internalFull;
                        }
                    }
                }

                if (!$canvaTemplatePath && $canvaSelectedId <= 0) {
                    $smartCanva = $canvaInt2->findAndExportSmartDesignTemplate([
                        'title' => $lessonTitle,
                        'language' => $language,
                        'summary' => $summaryText ?? '',
                        'slides' => $canvaSlides ?? [],
                    ]);

                    if (!empty($smartCanva['success']) && !empty($smartCanva['path'])) {
                        $smartFull = dirname(__DIR__, 2) . '/' . $smartCanva['path'];
                        if (is_file($smartFull)) {
                            $canvaTemplatePath = $smartFull;
                        }
                    } elseif (!empty($smartCanva['error'])) {
                        error_log('Smart Canva design fallback in generate_lesson: ' . $smartCanva['error']);
                    }
                }
            } catch (\Throwable $ce) {
                if ($ce->getMessage() === '__CANVA_POWERPOINT_DONE__') {
                    throw $ce;
                }
                error_log('Canva check in generate_lesson: ' . $ce->getMessage());
            }

            if (!$canvaTemplatePath && (int)($_POST['canva_template_id'] ?? 0) <= 0) {
                try {
                    $templateLibrary = new LessonPptTemplateLibrary($db);
                    $internalTemplate = $selectedInternalTemplateId > 0
                        ? $templateLibrary->find($selectedInternalTemplateId)
                        : $templateLibrary->chooseBestTemplate([
                            'title' => $lessonTitle,
                            'language' => $language,
                            'summary' => $summaryText ?? '',
                            'slides' => $canvaSlides ?? [],
                        ]);
                    if ($internalTemplate && !empty($internalTemplate['file_path'])) {
                        $internalFull = dirname(__DIR__, 2) . '/' . ltrim($internalTemplate['file_path'], '/\\');
                        if (is_file($internalFull)) {
                            $canvaTemplatePath = $internalFull;
                        }
                    }
                } catch (Throwable $tplError) {
                    error_log('Internal PPT template fallback in generate_lesson failed: ' . $tplError->getMessage());
                }
            }

            $relativePath = 'storage/exports/lessons/' . $teacherId . '/lesson_' . $lessonId . '.pptx';
            $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;

            // توليد محتوى الشرائح عبر الـ prompt المخصَّص (generatePowerPointSlides) —
            // نفس المسار الجيد المستخدَم في generate_powerpoint_only.php.
            // ينتج شرائح موجهة للطلاب (لا بيانات تحضير المعلم) وبنية JSON صارمة.
            // لو فشل التوليد المخصَّص، نرجع للمسار القديم (lesson_plan) كـ fallback آمن.
            $pptBuiltFromDedicatedSlides = false;
            try {
                // generatePowerPointSlides يُرجع مصفوفة الشرائح مباشرة (ليست مغلّفة بمفتاح 'slides').
                // لذا نفحصها مباشرة ونعيد استخدامها كقيمة 'slides' لـ generateFromSlides.
                $dedicatedSlides = $generator->generatePowerPointSlides($fullContent, $powerPointSlides);
                if (!empty($dedicatedSlides) && is_array($dedicatedSlides)) {
                    (new LessonPowerPointGenerator())->generateFromSlides([
                        'title'               => $lessonTitle,
                        'slides'              => $dedicatedSlides,
                        'language'            => $language,
                        'canva_template_path' => $canvaTemplatePath,
                    ], $absolutePath, $powerPointTheme);
                    $pptBuiltFromDedicatedSlides = true;
                }
            } catch (\Throwable $dedicatedErr) {
                error_log('Dedicated PowerPoint slides generation failed in generate_lesson, falling back to lesson_plan path: ' . $dedicatedErr->getMessage());
            }

            // Fallback: المسار القديم (من lesson_plan) لو فشل المسار المخصَّص.
            if (!$pptBuiltFromDedicatedSlides) {
                (new LessonPowerPointGenerator())->generate([
                    'title'               => $lessonTitle,
                    'lesson_plan'         => $results['lesson_plan'] ?: [],
                    'class_activities'    => $results['class_activities'] ?? [],
                    'language'            => $language,
                    'canva_template_path' => $canvaTemplatePath,
                ], $absolutePath, $powerPointTheme, $powerPointSlides);
            }
            (new \EduCore\Modules\LearningContent\AiLessonLifecycleService($db))->update(
                (int) $lessonId,
                (int) $teacherId,
                ['powerpoint_path' => $relativePath, 'powerpoint_theme' => $powerPointTheme, 'powerpoint_status' => 'completed'],
                'ai_lesson_powerpoint_completed',
                ['generator' => 'local']
            );
            $powerPointUrl = 'lesson_download.php?id=' . $lessonId . '&type=powerpoint';
        } catch (Throwable $pptError) {
            if ($pptError->getMessage() !== '__CANVA_POWERPOINT_DONE__') {
                $powerPointError = 'تم إنشاء الدرس، لكن تعذر إنشاء PowerPoint';
                error_log('PowerPoint generation failed: '.$pptError->getMessage());
                (new \EduCore\Modules\LearningContent\AiLessonLifecycleService($db))->update(
                    (int) $lessonId,
                    (int) $teacherId,
                    ['powerpoint_theme' => $powerPointTheme, 'powerpoint_status' => 'failed'],
                    'ai_lesson_powerpoint_failed',
                    ['generator' => 'local']
                );
            }
        }
    }

    // إرجاع النتائج
    $response = [
        'success' => true,
        'lesson_id' => $lessonId,
        'data' => [
            'lesson_plan' => $results['lesson_plan'],
            'question_bank' => $results['question_bank'],
            'visual_materials' => $results['visual_materials'],
            'class_activities' => $results['class_activities'] ?? null,
            'educational_stories' => $results['educational_stories'] ?? null,
            'mind_maps' => $results['mind_maps'] ?? null,
            'lesson_summary' => $results['lesson_summary'] ?? null,
            'custom_content' => !empty($customContent) ? $customContent : null
        ],
        'exam_html' => $examHtml,
        'exam_warning' => $examWarning,
        'custom_content_error' => $customContentError,
        'qb_warnings' => $qbWarnings,
        'answer_key_enabled' => $answerKeyEnabled,
        'exam_models_count' => $examModels,
        'model_type' => $modelType,
        'errors' => $results['errors']
        ,'powerpoint_url' => $powerPointUrl
        ,'powerpoint_error' => $powerPointError
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);


}
catch (Throwable $e) {
    error_log("Lesson Generation Error: " . $e->getMessage());
    if (!empty($lessonId) && isset($db, $teacherId)) {
        try {
            (new \EduCore\Modules\LearningContent\AiLessonLifecycleService($db))->update(
                (int) $lessonId,
                (int) $teacherId,
                ['status' => 'failed', 'generation_error' => mb_substr($e->getMessage(), 0, 1000)],
                'ai_lesson_generation_failed',
                ['generation_stage' => 'unhandled']
            );
        } catch (Throwable $auditError) {
            error_log('Lesson failure state audit error: ' . $auditError->getMessage());
        }
    }
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ غير متوقع'
    ]);
}
