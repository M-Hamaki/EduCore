<?php
/**
 * إعدادات الذكاء الاصطناعي - Google Gemini API
 * AI Configuration for Lesson Preparation System
 */

// تحميل متغيرات البيئة (قد تكون محملة مسبقاً من database.php)
require_once __DIR__ . '/env_loader.php';

// =====================================================
// إعدادات Google Gemini API
// =====================================================

// مفتاح API - يُقرأ من ملف .env
define('GEMINI_API_KEY', env('GEMINI_API_KEY', ''));

// مفاتيح API احتياطية (من مشاريع مختلفة) - تُستخدم تلقائياً عند تجاوز حد الاستخدام
$fallbackKeys = env('GEMINI_API_KEYS_FALLBACK', '');
define('GEMINI_API_KEYS_FALLBACK', json_encode(
    $fallbackKeys ? array_map('trim', explode(',', $fallbackKeys)) : []
));

// النموذج الافتراضي (يُستخدم كـ fallback فقط؛ الطبقات الفعلية في GEMINI_TIER_MODELS أدناه).
// gemini-3.1-flash-lite: GA stable، الجيل 3.x، متاح للمستخدمين الجدد (بديل 2.5-flash-lite الذي يُوقف أكتوبر 2026).
// gemini-2.5-flash/2.5-flash-lite: ممنوعان للمستخدمين الجدد / مُتوقَّف إيقافهما قريباً — تجنّبهما.
define('GEMINI_MODEL', 'gemini-3.1-flash-lite');

// =====================================================
// توزيع النماذج حسب الثقل (Model Tiering)
// =====================================================
// المهام الثقيلة (JSON معقّد، تفكير عميق) → heavy tier
// المهام الخفيفة (نص وصفي بسيط)         → light tier
// الهدف: استثمار الحصة اليومية بذكاء وتحسين الجودة حيثما يلزم.
//
// 'model' => اسم النموذج في Gemini API
// 'maxTokens' => سقف الرموز الافتراضي لهذه الطبقة (يُمكن تجاوزه من options)
//
// لتغيير النموذج الأساسي: عدّل GEMINI_MODEL أعلاه؛ أما هنا فتُوزَّع الطبقات.
// ملاحظة: نستخدم قيمة 8192 الافتراضية مباشرة هنا لأن GEMINI_MAX_TOKENS يُعرَّف لاحقاً في هذا الملف.
//
// اختيار النماذج (يوليو 2026): جوجل تمنع المستخدمين الجدد من نماذج 2.x على الطبقة المجانية
// (خطأ HTTP 404 "no longer available to new users"). لذلك نعتمد الجيل 3.x المؤكَّد توافره:
//   - heavy: gemini-3.1-flash-lite (GA stable، الأسرع — 2.5s مقابل 15-28s لـ 3.5-flash)
//   - light: gemini-3.1-flash-lite (نفس النموذج — توحيد للسرعة)
// ملاحظة الأداء: gemini-3.5-flash هو "نموذج تفكير" يستخدم thoughts tokens (يصل لـ 476+ tokens
// للتفكير قبل الإجابة)، فزمنه متغيّر 4-120 ثانية. بينما 3.1-flash-lite لا يفكّر (0 thoughts)
// فيستقر عند ~2.5 ثانية حتى للطلبات الثقيلة. لذلك نوحّد عليه لتسريع التوليد 6-11×.
// يمكن العودة لـ 3.5-flash لاحقاً عبر GEMINI_MODEL_HEAVY إن لزمت الجودة القصوى.
$geminiTierModels = [
    'heavy' => [
        'model'      => env('GEMINI_MODEL_HEAVY', 'gemini-3.1-flash-lite'),  // الأسرع: الدرس/القصة/بنك الأسئلة/PowerPoint
        'maxTokens'  => (int) env('GEMINI_MAX_TOKENS_HEAVY', 16384),
    ],
    'light' => [
        'model'      => env('GEMINI_MODEL_LIGHT', 'gemini-3.1-flash-lite'), // الأخف: الأنشطة/الخرائط/المواد البصرية/الملخص
        'maxTokens'  => (int) env('GEMINI_MAX_TOKENS_LIGHT', 8192),
    ],
];
// ثابت قابل للاستخدام في الكود: مصفوفة الطبقات.
define('GEMINI_TIER_MODELS', json_encode($geminiTierModels));

/**
 * إرجاع إعدادات طبقة نموذج حسب المفتاح.
 *
 * @param string $tier 'heavy' أو 'light'
 * @return array ['model' => string, 'maxTokens' => int]
 */
function getTierModel($tier = 'light') {
    static $tiers = null;
    if ($tiers === null) {
        $decoded = json_decode(defined('GEMINI_TIER_MODELS') ? GEMINI_TIER_MODELS : '[]', true);
        $tiers = is_array($decoded) ? $decoded : [];
    }
    if (!isset($tiers[$tier])) {
        $tier = 'light';
    }
    // قيم افتراضية آمنة لو لم تُحمَّل الثوابت لأي سبب (جيل 3.x متاح للمستخدمين الجدد).
    $fallbackModel = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.1-flash-lite';
    $fallbackMax   = defined('GEMINI_MAX_TOKENS') ? GEMINI_MAX_TOKENS : 8192;
    $entry = $tiers[$tier] ?? ['model' => $fallbackModel, 'maxTokens' => $fallbackMax];
    return [
        'model'     => $entry['model'] ?? $fallbackModel,
        'maxTokens' => isset($entry['maxTokens']) ? (int) $entry['maxTokens'] : $fallbackMax,
    ];
}

// نموذج توليد الصور
// gemini-3.1-flash-image: يدعم توليد الصور التعليمية
define('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image');

// =====================================================
// إعدادات البحث عن الصور من الإنترنت (Multi-Source)
// =====================================================
// Pixabay API - يُقرأ من ملف .env
define('PIXABAY_API_KEY', env('PIXABAY_API_KEY', ''));
// Unsplash API - يُقرأ من ملف .env
define('UNSPLASH_ACCESS_KEY', env('UNSPLASH_ACCESS_KEY', ''));
// Pexels API - يُقرأ من ملف .env
define('PEXELS_API_KEY', env('PEXELS_API_KEY', ''));
// عدد الصور لكل بحث
define('PIXABAY_IMAGES_PER_SEARCH', 3);
// تفعيل البحث عن الصور أثناء توليد الدرس
define('AUTO_IMAGE_SEARCH_ENABLED', true);

// رابط API الأساسي
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/');

// مسار حفظ الصور المولدة
define('GENERATED_IMAGES_PATH', __DIR__ . '/../uploads/generated_images/');

// =====================================================
// حدود الاستخدام
// =====================================================

// الحد الأقصى للرموز في الطلب الواحد
define('GEMINI_MAX_TOKENS', 8192);

// الحد الأقصى لحجم الصورة (5 ميجابايت)
define('GEMINI_MAX_IMAGE_SIZE', 5 * 1024 * 1024);

// الحد الأقصى لحجم PDF (10 ميجابايت)
define('GEMINI_MAX_PDF_SIZE', 10 * 1024 * 1024);

// الحد اليومي للطلبات لكل معلم
define('GEMINI_DAILY_LIMIT', 100);

// الحد اليومي لتوليد الصور لكل معلم
define('GEMINI_IMAGE_DAILY_LIMIT', 30);

// مهلة الاتصال بالثواني
// 60 ثانية كافية لنموذج flash-lite (~2.5s للطلب الواحد)؛ الـ retries الداخلية تتعامل مع الفشل.
// كان 120 لكن ذلك أطال الانتظار عند الضغط (HTTP 503) دون فائدة.
define('GEMINI_TIMEOUT', 60);

// =====================================================
// إعدادات توليد المحتوى
// =====================================================

// درجة الحرارة (creativity) - 0.0 إلى 1.0
// قيمة أقل = إجابات أكثر تحديداً
// قيمة أعلى = إجابات أكثر إبداعاً
define('GEMINI_TEMPERATURE', 0.7);

// Top P (nucleus sampling)
define('GEMINI_TOP_P', 0.95);

// Top K
define('GEMINI_TOP_K', 40);

// =====================================================
// أنواع الملفات المدعومة
// =====================================================

define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_PDF_TYPES', ['application/pdf']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf']);

// =====================================================
// مسارات التخزين
// =====================================================

define('UPLOADS_PATH', __DIR__ . '/../uploads/lessons/');
define('UPLOADS_PDF_PATH', UPLOADS_PATH . 'pdfs/');
define('UPLOADS_IMAGE_PATH', UPLOADS_PATH . 'images/');
define('EXPORTS_PATH', __DIR__ . '/../uploads/exports/');

// =====================================================
// إعدادات Ollama (نماذج AI المحلية)
// =====================================================

// رابط خدمة Ollama المحلية
define('OLLAMA_BASE_URL', env('OLLAMA_BASE_URL', 'http://localhost:11434'));

// النموذج المحلي الافتراضي
define('OLLAMA_MODEL', env('OLLAMA_MODEL', 'gemma3:4b'));

// مهلة الاتصال بالثواني (النموذج المحلي أبطأ)
define('OLLAMA_TIMEOUT', intval(env('OLLAMA_TIMEOUT', 120)));

// =====================================================
// إعدادات المزوّد الموحد (AIProvider)
// =====================================================

// المزوّد الافتراضي: 'gemini', 'ollama', 'auto'
// auto = يستخدم Gemini أولاً، ويتحول لـ Ollama عند الفشل
define('AI_DEFAULT_PROVIDER', env('AI_DEFAULT_PROVIDER', 'auto'));

// =====================================================
// دالة للحصول على مفتاح API من قاعدة البيانات
// =====================================================

function getGeminiApiKey($db = null)
{
    // أولاً: تحقق من الثابت المحدد
    if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
        return GEMINI_API_KEY;
    }

    // ثانياً: حاول الحصول من قاعدة البيانات
    if ($db) {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'gemini_api_key'");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && !empty($result['setting_value'])) {
                return $result['setting_value'];
            }
        }
        catch (PDOException $e) {
            error_log("Error fetching Gemini API key: " . $e->getMessage());
        }
    }

    return '';
}

// =====================================================
// دالة للتحقق من حدود الاستخدام اليومي
// =====================================================

function checkDailyLimit($db, $teacherId, $requestedCalls = 1)
{
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM ai_api_logs 
            WHERE teacher_id = ? 
            AND DATE(created_at) = CURDATE()
            AND status = 'success'
        ");
        $stmt->execute([$teacherId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return ((int)$result['count'] + max(1, (int)$requestedCalls)) <= GEMINI_DAILY_LIMIT;
    }
    catch (PDOException $e) {
        error_log("Error checking daily limit: " . $e->getMessage());
        return true; // السماح في حالة الخطأ
    }
}

// =====================================================
// دالة لتسجيل استخدام API
// =====================================================

function logApiUsage($db, $teacherId, $lessonId, $requestType, $status, $tokensUsed = 0, $responseTime = 0, $errorMessage = null)
{
    try {
        $apiType = 'gemini';
        $providerStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='ai_provider' LIMIT 1");
        $providerStmt->execute();
        $configuredProvider = strtolower((string)$providerStmt->fetchColumn());
        if (in_array($configuredProvider, ['gemini', 'ollama'], true)) $apiType = $configuredProvider;
        $stmt = $db->prepare("
            INSERT INTO ai_api_logs 
            (teacher_id, lesson_id, api_type, request_type, tokens_used, response_time_ms, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $teacherId,
            $lessonId,
            $apiType,
            $requestType,
            $tokensUsed,
            $responseTime,
            $status,
            $errorMessage
        ]);
        return true;
    }
    catch (PDOException $e) {
        error_log("Error logging API usage: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// إنشاء المجلدات المطلوبة
// =====================================================

function ensureDirectoriesExist()
{
    $directories = [
        UPLOADS_PATH,
        UPLOADS_PDF_PATH,
        UPLOADS_IMAGE_PATH,
        EXPORTS_PATH
    ];

    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

// تأكد من وجود المجلدات عند تحميل الملف
ensureDirectoriesExist();

// إنشاء مجلد الصور المولدة
if (defined('GENERATED_IMAGES_PATH') && !is_dir(GENERATED_IMAGES_PATH)) {
    mkdir(GENERATED_IMAGES_PATH, 0755, true);
}

// =====================================================
// تحديد MIME type بدون اشتراط fileinfo extension
// متوافق مع PHP 7.4+
// =====================================================
if (!function_exists('detectMimeType')) {
    function detectMimeType(string $path): string {
        if (class_exists('finfo', false)) {
            $fi   = new finfo(FILEINFO_MIME_TYPE);
            $mime = $fi->file($path);
            if ($mime) return $mime;
        }
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if ($mime) return $mime;
        }
        // Fallback: فحص أول بايتات الملف (magic bytes)
        $handle = fopen($path, 'rb');
        $bytes  = fread($handle, 8);
        fclose($handle);
        if (substr($bytes, 0, 4) === '%PDF')              return 'application/pdf';
        if (substr($bytes, 0, 2) === "\xFF\xD8")          return 'image/jpeg';
        if (substr($bytes, 0, 4) === "\x89PNG")           return 'image/png';
        if (substr($bytes, 0, 4) === 'GIF8')              return 'image/gif';
        if (substr($bytes, 0, 4) === 'RIFF')              return 'image/webp';
        return 'application/octet-stream';
    }
}
