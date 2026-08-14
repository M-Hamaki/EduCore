<?php
/**
 * تحميل ملفات الدروس (PowerPoint / امتحان HTML / JSON)
 * Lesson Download Handler
 */

// صفحة التحميل "للقراءة فقط" من منظور الجلسة: لا يجب أن تُجدِّد معرّف الجلسة.
// session_regenerate_id(true) في session_config.php (كل 30 دقيقة) يُبطل الـ cookie القديم،
// مما يجعل المتصفح — الذي لا يزال يحمل الـ cookie القديم — يفقد الجلسة عند نقر رابط التحميل،
// فيُعاد توجيهه إلى صفحة تسجيل الدخول بدلاً من تنزيل الملف. تعطيل التجديد لهذه الصفحة فقط.
$_ENV['SESSION_REGENERATE_INTERVAL'] = (string)(PHP_INT_MAX);
putenv('SESSION_REGENERATE_INTERVAL=' . PHP_INT_MAX);

// منع no_cache.php من إرسال headers تعارض headers التحميل (خاصة Vary: * و Cache-Control: no-store
// التي تُفسد تنزيل الملفات في بعض المتصفحات وتُظهر "Couldn't Download - Network issue").
define('EDUCORE_SUPPRESS_NO_CACHE_HEADERS', true);

require_once '../includes/session_config.php';

// التأكد من عدم وجود أي output buffer متبقٍ قبل إرسال الـ binary stream.
while (ob_get_level() > 0) {
    ob_end_clean();
}

$isAdminView = false;
if (isset($_SESSION['user_id']) && in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    $isAdminView = true;
} elseif (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    // عند انتهاء الجلسة:
    // - طلبات AJAX/fetch نتُرجع JSON خطأ واضح (401) ليُعالجه الـ frontend.
    // - طلبات المتصفح العادية (نقر <a href download>) نُعيد توجيهها لصفحة عرض الدرس
    //   مع علامة error=session_expired لتُظهر رسالة واضحة، بدل تنزيل HTML/JSON كـ pptx
    //   (الذي يُظهر "Couldn't Download - Network issue" في Chrome).
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    $redirectUrl = '../index.php?timeout=1';
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        // العودة لصفحة الدرس لعرض رسالة واضحة بدل صفحة تسجيل الدخول.
        $redirectUrl = 'lesson_view.php?id=' . intval($_GET['id']) . '&error=session_expired';
    }
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => false,
            'error' => 'session_expired',
            'message' => 'انتهت جلستك. يرجى تسجيل الدخول من جديد ثم إعادة تحميل الملف.',
            'redirect' => $redirectUrl,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: ' . $redirectUrl);
    }
    exit;
}

require_once '../config/database.php';
require_once '../classes/LessonGenerator.php';

$database = new Database();
$db = $database->getConnection();

if ($isAdminView && isset($_GET['teacher_id']) && is_numeric($_GET['teacher_id'])) {
    $teacherId = intval($_GET['teacher_id']);
} else {
    $teacherId = $_SESSION['user_id'];
}
$generator = new LessonGenerator($db, $teacherId);

// التحقق من المعاملات
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: lesson_archive.php');
    exit;
}

$lessonId = intval($_GET['id']);
$type = isset($_GET['type']) ? $_GET['type'] : 'exam';

$lesson = $generator->getLesson($lessonId);

if (!$lesson) {
    header('Location: lesson_archive.php?error=not_found');
    exit;
}

/**
 * تحويل عنوان الدرس إلى اسم ملف صالح: يحذف الرموز غير المسموحة في أنظمة الملفات
 * (Windows/macOS/Linux)، يستبدل المسافات المتعددة بواحدة، ويقتطع الطول إلى 80 حرفاً.
 * إذا كان العنوان فارغاً أو مكوّناً بالكامل من رموز غير صالحة، يُرجع اسم افتراضي
 * يحوي معرّف الدرس لضمان تفرد الملف.
 *
 * @param string $title عنوان الدرس الخام
 * @param int    $lessonId معرّف الدرس (للاسم الاحتياطي)
 * @return string اسم ملف نظيف بدون امتداد
 */
function buildLessonFilename(string $title, int $lessonId): string
{
    // استبدال الرموز المحظورة في أنظمة الملفات بمسافة، وإزالة رموز التحكم.
    // الرموز المحظورة: \ / : * ? " < > | ومحرّك السطر والجدولة.
    // نستخدم ~ كمحدّد للـ regex بدلاً من / لتجنب هروب الشرطة المائلة داخل فئة الأحرف.
    $clean = preg_replace('~[\\\\/:*?"<>|\x00-\x1F\x7F]~u', ' ', $title);
    // طي المسافات المتعددة وحوافها.
    $clean = trim(preg_replace('~\s+~u', ' ', $clean));
    // اقتطاع UTF-8 آمن إلى 80 حرفاً.
    if (function_exists('mb_substr') && mb_strlen($clean, 'UTF-8') > 80) {
        $clean = mb_substr($clean, 0, 80, 'UTF-8');
        $clean = rtrim($clean, ' ');
    }
    if ($clean === '') {
        $clean = 'lesson_' . $lessonId;
    }
    return $clean;
}

$lessonTitle = isset($lesson['title']) ? (string)$lesson['title'] : '';
// ملاحظة: قد يبدأ عنوان درس PowerPoint ببادئة "[PowerPoint] " — نزيلها من اسم الملف
// لأن الامتداد .pptx يكفي للدلالة على النوع، فلا داعي لتكراره في الاسم.
if (stripos($lessonTitle, '[PowerPoint] ') === 0) {
    $lessonTitle = substr($lessonTitle, strlen('[PowerPoint] '));
}
$baseFilename = buildLessonFilename($lessonTitle, $lessonId);

switch ($type) {
    case 'powerpoint':
        $relativePath = $lesson['powerpoint_path'] ?? '';
        $projectRoot = realpath(dirname(__DIR__));
        $absolutePath = $relativePath
            ? realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim((string) $relativePath, '/\\'))
            : false;
        $insideProject = $projectRoot !== false
            && $absolutePath !== false
            && str_starts_with($absolutePath, $projectRoot . DIRECTORY_SEPARATOR);
        if (!$insideProject || !is_file($absolutePath)) {
            // إعادة توجيه لطيفة بدل تنزيل HTML كـ pptx.
            header('Location: lesson_view.php?id=' . $lessonId . '&error=no_powerpoint');
            exit;
        }

        // تنظيف أي output buffer متبقٍ قبل الإرسال الثنائي.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filename = $baseFilename . '.pptx';
        $fileSize = filesize($absolutePath);

        // headers تحميل ثنائي نظيفة — بدون Vary: * أو Cache-Control: no-store
        // التي قد تُظهر "Couldn't Download - Network issue" في بعض المتصفحات.
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: private, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        if ($fileSize !== false) {
            header('Content-Length: ' . $fileSize);
        }
        header('X-Content-Type-Options: nosniff');

        // readfile مع التحقق من النجاح؛ الفشل قد يُظهر "Network issue" في المتصفح.
        $bytesRead = @readfile($absolutePath);
        if ($bytesRead === false) {
            // تعذّر قراءة الملف بعد إرسال headers — لا يمكن إصلاح الاستجابة، فقط سجّل.
            error_log('lesson_download.php: readfile failed for lesson ' . $lessonId . ' path=' . $absolutePath);
        }
        exit;

    case 'exam':
        if (empty($lesson['exam_html'])) {
            header('Location: lesson_view.php?id=' . $lessonId . '&error=no_exam');
            exit;
        }
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        $filename = $baseFilename . '.html';

        header('Content-Description: File Transfer');
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: private, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Length: ' . strlen($lesson['exam_html']));
        
        echo $lesson['exam_html'];
        exit;
        
    case 'lesson':
        if (empty($lesson['generated_prep'])) {
            header('Location: lesson_view.php?id=' . $lessonId . '&error=no_prep');
            exit;
        }
        
        $prepData = json_decode($lesson['generated_prep'], true);
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        $filename = $baseFilename . '.json';

        header('Content-Description: File Transfer');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: private, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo json_encode($prepData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;

    case 'questions':
        if (empty($lesson['question_bank'])) {
            header('Location: lesson_view.php?id=' . $lessonId . '&error=no_questions');
            exit;
        }

        $qData = json_decode($lesson['question_bank'], true);

        if (ob_get_level()) {
            ob_end_clean();
        }

        $filename = $baseFilename . '_questions.json';
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: private, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo json_encode($qData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
        
    default:
        header('Location: lesson_view.php?id=' . $lessonId);
        exit;
}
?>
