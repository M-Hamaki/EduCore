<?php
/**
 * معالج AJAX لتوليد بنك أسئلة مستقل بدون درس
 * Standalone Question Bank Generation - generates question bank from content without lesson plan
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../config/database.php';
require_once '../../config/ai_config.php';
require_once '../../classes/LessonGenerator.php';
require_once '../../classes/FileUploadGuard.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
    exit;
}

requireCsrfPost();

$temporaryUploadPaths = [];
register_shutdown_function(static function () use (&$temporaryUploadPaths): void {
    foreach ($temporaryUploadPaths as $temporaryPath) {
        if (is_string($temporaryPath) && is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
});

try {
    $database = new Database();
    $db = $database->getConnection();

    $teacherId = $_SESSION['user_id'];

    // إغلاق الجلسة فوراً لمنع تعليق الطلبات الأخرى (Session Locking)
    session_write_close();

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
    $qbTitle = isset($_POST['qb_title']) ? trim($_POST['qb_title']) : '';
    $qbContent = isset($_POST['qb_content']) ? trim($_POST['qb_content']) : '';
    // بنك الأسئلة يتولّد تلقائياً بجميع الأنواع — لا حاجة لأعداد مسبقة

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

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($pdfFile['tmp_name']);

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

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($imageFile['tmp_name']);

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

    // دمج المحتوى
    $fullContent = $qbContent . $uploadedContent;

    if (empty(trim($fullContent))) {
        echo json_encode(['success' => false, 'message' => 'يرجى إدخال محتوى تعليمي لتوليد بنك الأسئلة']);
        exit;
    }

    // إنشاء المولد - نحتاج فقط بنك الأسئلة
    $generator = new LessonGenerator($db, $teacherId);
    $generator->setLanguage($language);
    $generator->setSelectedElements([]);
    $generator->setSelectedSections(['question_bank']);

    // إنشاء سجل في قاعدة البيانات
    $gradeLevel = isset($_POST['grade_level']) && trim($_POST['grade_level']) !== '' ? mb_substr(trim($_POST['grade_level']), 0, 50) : null;
    $lessonTitle = $qbTitle ?: 'بنك أسئلة مستقل - ' . date('Y-m-d H:i');
    $lessonId = $generator->createLesson('[بنك أسئلة مستقل] ' . $lessonTitle, $fullContent, 0, $language, $gradeLevel);

    if (!$lessonId) {
        echo json_encode(['success' => false, 'message' => 'فشل في إنشاء سجل بنك الأسئلة: ' . $generator->getLastError()]);
        exit;
    }

    // توليد بنك الأسئلة فقط
    $questionBank = $generator->generateQuestionBank($fullContent);

    if (!$questionBank || !is_array($questionBank) || count($questionBank) === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'فشل في توليد الأسئلة. قد يكون المحتوى غير كافٍ. حاول إضافة المزيد من المحتوى التعليمي.'
        ]);
        exit;
    }

    // حساب الأعداد الفعلية لجميع أنواع الأسئلة
    $actualMC = isset($questionBank['multiple_choice']) ? count($questionBank['multiple_choice']) : 0;
    $actualTF = isset($questionBank['true_false']) ? count($questionBank['true_false']) : 0;
    $actualEssay = isset($questionBank['graduated']) ? count($questionBank['graduated']) : 0;
    $actualSA = isset($questionBank['short_answer']) ? count($questionBank['short_answer']) : 0;
    $actualFB = isset($questionBank['fill_blank']) ? count($questionBank['fill_blank']) : 0;
    $actualOrd = isset($questionBank['ordering']) ? count($questionBank['ordering']) : 0;
    $actualMatch = isset($questionBank['matching']) ? count($questionBank['matching']) : 0;
    $totalQuestions = $actualMC + $actualTF + $actualEssay + $actualSA + $actualFB + $actualOrd + $actualMatch;

    // حفظ النتائج
    $saved = $generator->saveResults(
        lessonId: $lessonId,
        lessonPlan: null,
        questionBank: $questionBank,
        visualMaterials: null,
        examHtml: null,
        examDuration: 0,
        examModels: 0,
        classActivities: null,
        educationalStories: null,
        mindMaps: null,
        lessonSummary: null,
        customContent: null
    );
    if (!$saved) {
        throw new RuntimeException($generator->getLastError() ?: 'تعذر حفظ بنك الأسئلة بعد توليده');
    }

    // إرجاع النتائج
    echo json_encode([
        'success' => true,
        'lesson_id' => $lessonId,
        'data' => [
            'question_bank' => $questionBank
        ],
        'counts' => [
            'mc' => $actualMC,
            'tf' => $actualTF,
            'essay' => $actualEssay,
            'short_answer' => $actualSA,
            'fill_blank' => $actualFB,
            'ordering' => $actualOrd,
            'matching' => $actualMatch,
            'total' => $totalQuestions
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Standalone Question Bank Generation Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء التوليد: ' . $e->getMessage()
    ]);
}
