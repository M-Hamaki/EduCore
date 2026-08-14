<?php
/**
 * كلاس توليد تحضير الدروس
 * Lesson Generator Class for AI-powered lesson preparation
 */

require_once __DIR__ . '/AIProvider.php';
require_once __DIR__ . '/../config/ai_prompts.php';
require_once __DIR__ . '/WebImageSearch.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';

class LessonGenerator {
    private $gemini;
    private $db;
    private $teacherId;
    private $lessonId;
    private $lessonTitle;
    private $language;
    private $lastError;
    private $selectedElements;
    private $selectedSections;
    private $selectedPhases;
    /** @var int|null عمر الطلاب المستهدف للقصة التربوية (4-25) أو null */
    private $studentAge = null;
    
    /**
     * Constructor
     */
    public function __construct($db, $teacherId) {
        $this->db = $db;
        $this->teacherId = $teacherId;
        $this->gemini = new AIProvider($db);
        $this->language = 'ar';
        $this->lastError = null;
        $this->selectedElements = ['objectives', 'strategies', 'lesson_phases', 'resources'];
        $this->selectedSections = ['question_bank', 'visual_materials', 'class_activities', 'educational_stories', 'mind_maps', 'lesson_summary'];
        $this->selectedPhases = null; // null = use default distribution
    }
    
    /**
     * تعيين اللغة
     */
    public function setLanguage($language) {
        $this->language = in_array($language, ['ar', 'en', 'fr', 'de']) ? $language : 'ar';
    }
    
    /**
     * تعيين العناصر المختارة
     */
    public function setSelectedElements($elements) {
        if (is_array($elements) && !empty($elements)) {
            $this->selectedElements = $elements;
        }
    }
    
    /**
     * تعيين الأقسام المختارة
     */
    public function setSelectedSections($sections) {
        if (is_array($sections)) {
            $this->selectedSections = $sections;
        }
    }
    
    /**
     * تعيين المراحل المختارة مع توزيع الوقت
     */
    public function setSelectedPhases($phases) {
        if (is_array($phases) && !empty($phases)) {
            $this->selectedPhases = $phases;
        }
    }

    /**
     * تعيين عمر الطلاب المستهدف للقصة التربوية المنظَّمة.
     * يُطبَّع على مستوى اللغة وعمق المفاهيم وطول القصة ونمط الشخصيات داخل الـ prompt.
     *
     * @param int|string|null $age عمر بين 4 و 25، أو null لإلغاء التحديد
     */
    public function setStudentAge($age) {
        if ($age === null || $age === '') {
            $this->studentAge = null;
            return;
        }
        $age = (int)$age;
        // تطبيع النطاق (clamp) إلى نطاق صحيح منطقياً.
        $this->studentAge = ($age >= 4 && $age <= 25) ? $age : null;
    }

    /**
     * بناء خيارات الطلب (options) لطبقة نموذج محدَّدة.
     * يدمج نموذج الطبقة (model) مع سقف الرموز الخاص بالطلب (overrideMaxTokens)
     * إن وُجد؛ وإلا يستخدم سقف الطبقة الافتراضي.
     *
     * @param string   $tier              'heavy' أو 'light'
     * @param int|null $overrideMaxTokens سقف رموز خاص لهذا الطلب (يفضل سقف الطبقة)
     * @return array ['model' => ..., 'maxTokens' => ...]
     */
    private function tierOptions($tier = 'light', $overrideMaxTokens = null) {
        if (!function_exists('getTierModel')) {
            // حماية احتياطية: لو لم تُحمَّل دوال الإعداد لأي سبب.
            return $overrideMaxTokens !== null ? ['maxTokens' => $overrideMaxTokens] : [];
        }
        $tierConf = getTierModel($tier);
        $maxTokens = $overrideMaxTokens !== null ? $overrideMaxTokens : $tierConf['maxTokens'];
        return [
            'model'     => $tierConf['model'],
            'maxTokens' => $maxTokens,
        ];
    }
    
    /**
     * تعيين إعدادات بنك الأسئلة
     */
    // setQuestionBankCounts removed — AI now generates max possible automatically
    
    /**
     * الحصول على آخر خطأ
     */
    public function getLastError() {
        return $this->lastError ?: $this->gemini->getLastError();
    }
    
    /**
     * إنشاء درس جديد في قاعدة البيانات
     */
    public function createLesson($title, $content, $duration, $language = 'ar', $gradeLevel = null) {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                INSERT INTO ai_lessons 
                (teacher_id, title, language, original_content, duration_minutes, grade_level, status)
                VALUES (?, ?, ?, ?, ?, ?, 'generating')
            ");
            $stmt->execute([
                $this->teacherId,
                $title,
                $language,
                $content,
                $duration,
                $gradeLevel
            ]);
            
            $this->lessonId = $this->db->lastInsertId();
            $this->language = $language;
            $this->lessonTitle = $title;

            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                'insert',
                'ai_lesson',
                (int) $this->lessonId,
                (string) $title,
                [
                    'status' => 'generating',
                    'language' => $language,
                    'grade_level' => $gradeLevel,
                    'duration_minutes' => (int) $duration,
                    'content_length' => mb_strlen((string) $content),
                    'content_sha256' => hash('sha256', (string) $content),
                    'direct_undo' => false,
                    'reason' => 'generated_content_lifecycle',
                ]
            );
            if ($ownsTransaction) $this->db->commit();
            return $this->lessonId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            $this->lastError = 'تعذر إنشاء سجل الدرس بأمان';
            error_log("LessonGenerator::createLesson Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * توليد تحضير الدرس
     */
    public function generateLessonPlan($content, $duration) {
        // الحصول على prompt تحضير الدرس مع العناصر المختارة
        $promptTemplate = AIPrompts::getLessonPrepPrompt($this->language, $duration, $this->selectedElements, $this->selectedPhases);
        $fullPrompt = AIPrompts::buildFullPrompt($promptTemplate, $content);

        // إرسال الطلب إلى Gemini (طبقة heavy: النموذج الأقوى لتحضير الدرس)
        $response = $this->gemini->generateContent($fullPrompt, $this->tierOptions('heavy'));
        
        if (!$response) {
            $this->lastError = $this->gemini->getLastError();
            return null;
        }
        
        // تنظيف وتحليل JSON
        $jsonData = $this->extractJSON($response);
        
        if (!$jsonData) {
            $this->lastError = 'فشل في تحليل استجابة الذكاء الاصطناعي';
            return null;
        }
        
        // تسجيل الاستخدام
        logApiUsage(
            $this->db,
            $this->teacherId,
            $this->lessonId,
            'lesson_prep',
            'success',
            $this->gemini->getLastTokensUsed(),
            $this->gemini->getLastResponseTime()
        );
        
        return $jsonData;
    }
    
    /**
     * توليد بنك الأسئلة مع 3 محاولات بأعداد متناقصة عند فشل JSON
     * المستوى 0: كامل (~46 سؤال) → المستوى 1: مخفض (~23) → المستوى 2: أدنى (~14)
     */
    public function generateQuestionBank($content) {
        $levelLabels = ['success', 'success_reduced', 'success_minimal'];
        
        for ($level = 0; $level <= 2; $level++) {
            if ($level > 0) {
                error_log("QB generation: Attempt " . $level . " failed, retrying with level " . ($level + 1) . "...");
                usleep(500000);
            }
            
            $promptTemplate = AIPrompts::getQuestionBankPrompt($this->language, $level);
            $fullPrompt = AIPrompts::buildFullPrompt($promptTemplate, $content);

            // طبقة heavy: النموذج الأقوى لبنك الأسئلة (تفكير عميق في صياغة الأسئلة)
            $response = $this->gemini->generateContent($fullPrompt, $this->tierOptions('heavy'));
            
            if ($response) {
                $jsonData = $this->extractJSON($response);
                if ($jsonData) {
                    logApiUsage($this->db, $this->teacherId, $this->lessonId, 'questions', $levelLabels[$level],
                        $this->gemini->getLastTokensUsed(), $this->gemini->getLastResponseTime());
                    return $jsonData;
                }
            }
        }
        
        $this->lastError = 'فشل في تحليل بنك الأسئلة بعد 3 محاولات';
        return null;
    }
    
    /**
     * توليد الأنشطة الصفية
     */
    public function generateClassActivities($content) {
        // الحصول على prompt الأنشطة الصفية
        $promptTemplate = AIPrompts::getClassActivitiesPrompt($this->language);
        $fullPrompt = AIPrompts::buildFullPrompt($promptTemplate, $content);

        // طبقة light: النموذج الأخف للأنشطة الصفية (JSON وصفي بسيط)
        $response = $this->gemini->generateContent($fullPrompt, $this->tierOptions('light'));
        
        if (!$response) {
            $this->lastError = $this->gemini->getLastError();
            return null;
        }
        
        // تحليل JSON
        $jsonData = $this->extractJSON($response);
        
        if (!$jsonData) {
            $this->lastError = 'فشل في تحليل الأنشطة الصفية';
            return null;
        }
        
        // تسجيل الاستخدام
        logApiUsage(
            $this->db,
            $this->teacherId,
            $this->lessonId,
            'activities',
            'success',
            $this->gemini->getLastTokensUsed(),
            $this->gemini->getLastResponseTime()
        );
        
        return $jsonData;
    }
    
    /**
     * توليد القصة التربوية المنظَّمة
     *
     * المخرج JSON غني (13 حقلاً مع مشاهد متداخلة وأسئلة تقويم)، لذلك:
     * - نرفع سقف الرموز (maxTokens) إلى 16384 لتفادي اقتطاع الاستجابة.
     * - نعيد المحاولة مرة واحدة عند فشل استخراج JSON (قَطع النموذج أحياناً الإخراج).
     */
    public function generateEducationalStories($content) {
        $promptTemplate = AIPrompts::getEducationalStoriesPrompt($this->language, $this->studentAge);
        $fullPrompt = AIPrompts::buildFullPrompt($promptTemplate, $content);

        // محاولتان: الأولى كاملة، الثانية عند فشل تحليل JSON (اقتطاع غالباً).
        // طبقة heavy: النموذج الأقوى للقصة التربوية (JSON غني بـ13 مخرجاً).
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if ($attempt > 1) {
                usleep(500000); // تأخير بسيط قبل إعادة المحاولة.
            }

            $response = $this->gemini->generateContent($fullPrompt, $this->tierOptions('heavy', 16384));

            if (!$response) {
                $this->lastError = $this->gemini->getLastError();
                continue; // أعِد المحاولة قبل الاستسلام.
            }

            $jsonData = $this->extractJSON($response);

            if ($jsonData) {
                logApiUsage(
                    $this->db,
                    $this->teacherId,
                    $this->lessonId,
                    'stories',
                    $attempt === 1 ? 'success' : 'success_retry',
                    $this->gemini->getLastTokensUsed(),
                    $this->gemini->getLastResponseTime()
                );
                return $jsonData;
            }
        }

        $this->lastError = 'فشل في تحليل القصة التربوية بعد محاولتين';
        return null;
    }

    /**
     * توليد المواد البصرية
     */
    public function generateVisualMaterials($content, $lessonTitle = '') {
        // الحصول على prompt المواد البصرية
        $promptTemplate = AIPrompts::getVisualMaterialsPrompt($this->language);
        $fullPrompt = AIPrompts::buildFullPrompt($promptTemplate, $content);

        // طبقة light: النموذج الأخف للمواد البصرية (نص وصفي)
        $response = $this->gemini->generateContent($fullPrompt, $this->tierOptions('light'));
        
        if (!$response) {
            $this->lastError = $this->gemini->getLastError();
            return null;
        }
        
        // تحليل JSON
        $jsonData = $this->extractJSON($response);
        
        if (!$jsonData) {
            $this->lastError = 'فشل في تحليل المواد البصرية';
            return null;
        }
        
        // البحث عن صور من الإنترنت وإثراء المواد البصرية
        if (defined('AUTO_IMAGE_SEARCH_ENABLED') && AUTO_IMAGE_SEARCH_ENABLED) {
            try {
                $imageSearch = new WebImageSearch();
                if ($imageSearch->isAvailable()) {
                    // محاولة استخراج اسم المادة من عنوان الدرس أو المحتوى
                    $subjectHint = $this->detectSubjectFromTitle($lessonTitle);
                    if (!empty($subjectHint)) {
                        $imageSearch->setSubjectContext($subjectHint);
                    }
                    $jsonData = $imageSearch->enrichVisualMaterials($jsonData, $lessonTitle, $this->language);
                }
            } catch (Exception $e) {
                error_log("Image search failed (non-critical): " . $e->getMessage());
                // لا نوقف العملية - الصور اختيارية
            }
        }
        
        // البحث في يوتيوب عن فيديوهات حقيقية لإثراء الاقتراحات
        try {
            require_once __DIR__ . '/YouTubeSearch.php';
            $ytSearch = new YouTubeSearch();
            if ($ytSearch->isAvailable()) {
                $jsonData = $ytSearch->enrichYouTubeVideos($jsonData, $this->language);
            }
        } catch (Exception $e) {
            error_log("YouTube search failed (non-critical): " . $e->getMessage());
            // لا نوقف العملية - فيديوهات يوتيوب اختيارية
        }
        
        // تسجيل الاستخدام
        logApiUsage(
            $this->db,
            $this->teacherId,
            $this->lessonId,
            'visual',
            'success',
            $this->gemini->getLastTokensUsed(),
            $this->gemini->getLastResponseTime()
        );
        
        return $jsonData;
    }
    
    /**
     * استخراج اسم المادة الدراسية من عنوان الدرس
     * @param string $title عنوان الدرس
     * @return string اسم المادة أو فارغ
     */
    private function detectSubjectFromTitle($title) {
        $title = mb_strtolower($title);
        
        $subjectPatterns = [
            'علوم' => 'علوم',
            'العلوم' => 'علوم',
            'رياضيات' => 'رياضيات',
            'الرياضيات' => 'رياضيات',
            'فيزياء' => 'فيزياء',
            'الفيزياء' => 'فيزياء',
            'كيمياء' => 'كيمياء',
            'الكيمياء' => 'كيمياء',
            'أحياء' => 'أحياء',
            'الأحياء' => 'أحياء',
            'جغرافيا' => 'جغرافيا',
            'الجغرافيا' => 'جغرافيا',
            'تاريخ' => 'تاريخ',
            'التاريخ' => 'تاريخ',
            'لغة عربية' => 'لغة عربية',
            'اللغة العربية' => 'لغة عربية',
            'لغة إنجليزية' => 'لغة إنجليزية',
            'اللغة الإنجليزية' => 'لغة إنجليزية',
            'حاسب' => 'حاسب آلي',
            'الحاسب' => 'حاسب آلي',
            'اجتماعيات' => 'اجتماعيات',
            'الاجتماعيات' => 'اجتماعيات',
            'تربية إسلامية' => 'تربية إسلامية',
            'دين' => 'تربية إسلامية',
            'تربية فنية' => 'تربية فنية',
            'science' => 'علوم',
            'math' => 'رياضيات',
            'physics' => 'فيزياء',
            'chemistry' => 'كيمياء',
            'biology' => 'أحياء',
            'geography' => 'جغرافيا',
            'history' => 'تاريخ',
        ];
        
        foreach ($subjectPatterns as $pattern => $subject) {
            if (mb_strpos($title, $pattern) !== false) {
                return $subject;
            }
        }
        
        return '';
    }
    
    /**
     * توليد الخرائط الذهنية
     */
    public function generateMindMaps($content) {
        // الحصول على prompt الخرائط الذهنية
        $promptTemplate = AIPrompts::getMindMapPrompt($this->language);
        $fullPrompt = AIPrompts::buildFullPrompt($promptTemplate, $content);

        // طبقة light: النموذج الأخف للخرائط الذهنية (هيكل شجري بسيط)
        $response = $this->gemini->generateContent($fullPrompt, $this->tierOptions('light'));

        if (!$response) {
            $this->lastError = $this->gemini->getLastError();
            return null;
        }
        
        // تحليل JSON
        $jsonData = $this->extractJSON($response);
        
        if (!$jsonData) {
            $this->lastError = 'فشل في تحليل الخرائط الذهنية';
            return null;
        }
        
        // تسجيل الاستخدام
        logApiUsage(
            $this->db,
            $this->teacherId,
            $this->lessonId,
            'mind_maps',
            'success',
            $this->gemini->getLastTokensUsed(),
            $this->gemini->getLastResponseTime()
        );
        
        return $jsonData;
    }
    
    /**
     * توليد ملخص الدرس ثنائي اللغة
     */
    public function generateLessonSummary($content) {
        $promptTemplate = AIPrompts::getLessonSummaryPrompt($this->language);
        $fullPrompt = AIPrompts::buildFullPrompt($promptTemplate, $content);

        // طبقة light: النموذج الأخف لملخص الدرس (نص قصير بسيط)
        $response = $this->gemini->generateContent($fullPrompt, $this->tierOptions('light'));
        
        if (!$response) {
            $this->lastError = $this->gemini->getLastError();
            return null;
        }
        
        $jsonData = $this->extractJSON($response);
        
        if (!$jsonData) {
            $this->lastError = 'فشل في تحليل ملخص الدرس';
            return null;
        }
        
        logApiUsage(
            $this->db,
            $this->teacherId,
            $this->lessonId,
            'lesson_summary',
            'success',
            $this->gemini->getLastTokensUsed(),
            $this->gemini->getLastResponseTime()
        );
        
        return $jsonData;
    }
    
    /**
     * توليد محتوى مخصص بناءً على عناصر يحددها المستخدم
     */
    public function generateCustomContent($content, $customPrompts) {
        if (empty($customPrompts) || !is_array($customPrompts)) {
            return [];
        }
        
        $promptTemplate = AIPrompts::getCustomContentPrompt($this->language, $customPrompts);
        $fullPrompt = AIPrompts::buildFullPrompt($promptTemplate, $content);

        // طبقة heavy: النموذج الأقوى للمحتوى المخصص (HTML تفصيلي) مع سقف رموز مرتفع.
        $response = $this->gemini->generateContent($fullPrompt, $this->tierOptions('heavy', 16384));
        
        if (!$response) {
            $this->lastError = $this->gemini->getLastError();
            error_log('Custom content API call failed: ' . $this->lastError);
            return [];
        }
        
        $jsonData = $this->extractJSON($response);
        
        if (!$jsonData) {
            $this->lastError = 'فشل في تحليل المحتوى المخصص - الاستجابة: ' . mb_substr($response, 0, 500);
            error_log('Custom content JSON parse failed. Response preview: ' . mb_substr($response, 0, 1000));
            return [];
        }
        
        // تسجيل الاستخدام
        logApiUsage(
            $this->db,
            $this->teacherId,
            $this->lessonId,
            'custom_content',
            'success',
            $this->gemini->getLastTokensUsed(),
            $this->gemini->getLastResponseTime()
        );
        
        // التأكد من أن النتيجة مصفوفة من العناصر
        if (isset($jsonData['items'])) {
            return $jsonData['items'];
        }
        
        // إذا كانت النتيجة مصفوفة مباشرة
        if (isset($jsonData[0])) {
            return $jsonData;
        }
        
        return [$jsonData];
    }
    
    /**
     * توليد شرائح الباوربوينت من المحتوى التعليمي الفعلي
     * يولّد بنية شرائح جاهزة للعرض التقديمي بدلاً من خطوات عمل المعلم
     *
     * @param string $content    المحتوى التعليمي الخام
     * @param int    $maxSlides  الحد الأقصى لعدد شرائح المحتوى
     * @return array|null        مصفوفة slides أو null عند الفشل
     */
    public function generatePowerPointSlides(string $content, int $maxSlides = 12): ?array
    {        // تعديل عدد الشرائح المستهدف تلقائياً بناءً على حجم المحتوى
        $contentLength = mb_strlen(trim($content));
        if ($contentLength < 400) {
            // محتوى محدود → شرائح أقل تعتمد على ما هو متاح فعلاً
            $maxSlides = min($maxSlides, max(5, (int)round($contentLength / 60)));
        } elseif ($contentLength < 1000) {
            // محتوى متوسط → لا نطلب أكثر من 12 شريحة
            $maxSlides = min($maxSlides, 12);
        }
        // محتوى وفير (> 1000 حرف) → نستخدم $maxSlides كما هو
        $prompt = AIPrompts::getPowerPointSlidesPrompt($this->language, $maxSlides);
        $fullPrompt = AIPrompts::buildFullPrompt($prompt, $content);

        // طبقة heavy: النموذج الأقوى لتوليد الشرائح المنظّمة (تفكير في التقسيم والتسلسل).
        $response = $this->gemini->generateContent($fullPrompt, $this->tierOptions('heavy'));

        if (!$response) {
            $this->lastError = $this->gemini->getLastError();
            return null;
        }

        $jsonData = $this->extractJSON($response);

        if (!$jsonData) {
            // محاولة ثانية بعدد شرائح مخفَّض
            error_log('LessonGenerator::generatePowerPointSlides — JSON parse failed, retrying with reduced slides');
            usleep(500000);
            $reducedMax = max(4, intval($maxSlides * 0.6));
            $retryPrompt = AIPrompts::getPowerPointSlidesPrompt($this->language, $reducedMax);
            $retryFull   = AIPrompts::buildFullPrompt($retryPrompt, $content);
            $retryResp   = $this->gemini->generateContent($retryFull, $this->tierOptions('heavy'));
            if ($retryResp) {
                $jsonData = $this->extractJSON($retryResp);
            }
        }

        if (!$jsonData || empty($jsonData['slides'])) {
            $this->lastError = 'فشل في توليد شرائح العرض التقديمي';
            return null;
        }

        logApiUsage(
            $this->db,
            $this->teacherId,
            $this->lessonId,
            'powerpoint_slides',
            'success',
            $this->gemini->getLastTokensUsed(),
            $this->gemini->getLastResponseTime()
        );

        return $jsonData['slides'];
    }

    /**
     * معالجة صورة واستخراج المحتوى
     */
    public function processImage($imagePath) {
        $prompt = AIPrompts::getImageExtractionPrompt($this->language);
        
        $response = $this->gemini->analyzeImage($prompt, $imagePath);
        
        if (!$response) {
            $this->lastError = $this->gemini->getLastError();
            return null;
        }
        
        return $response;
    }
    
    /**
     * معالجة PDF واستخراج المحتوى
     */
    public function processPDF($pdfPath) {
        $prompt = AIPrompts::getPDFExtractionPrompt($this->language);
        
        $response = $this->gemini->analyzePDF($prompt, $pdfPath);
        
        if (!$response) {
            $this->lastError = $this->gemini->getLastError();
            return null;
        }
        
        return $response;
    }
    
    /**
     * توليد كل المحتوى مرة واحدة
     */
    public function generateAll($content, $duration) {
        $results = [
            'lesson_plan' => null,
            'question_bank' => null,
            'visual_materials' => null,
            'class_activities' => null,
            'educational_stories' => null,
            'mind_maps' => null,
            'lesson_summary' => null,
            'errors' => []
        ];
        
        $callCount = 0; // عداد طلبات API لإدارة التأخير
        
        // توليد تحضير الدرس (دائماً)
        $lessonPlan = $this->generateLessonPlan($content, $duration);
        $callCount++;
        if ($lessonPlan) {
            $results['lesson_plan'] = $lessonPlan;
        } else {
            $results['errors'][] = 'تحضير الدرس: ' . $this->getLastError();
        }
        
        // توليد بنك الأسئلة (حسب الاختيار)
        if (in_array('question_bank', $this->selectedSections)) {
            // لا تأخير: نموذج flash-lite سريع (~2.5s) ولا يلامس rate-limit الدقيقي.
            $questionBank = $this->generateQuestionBank($content);
            $callCount++;
            if ($questionBank) {
                $results['question_bank'] = $questionBank;
            } else {
                $results['errors'][] = 'بنك الأسئلة: ' . $this->getLastError();
            }
        }

        // توليد المواد البصرية (حسب الاختيار)
        if (in_array('visual_materials', $this->selectedSections)) {
            $visualMaterials = $this->generateVisualMaterials($content, $this->lessonTitle ?? '');
            $callCount++;
            if ($visualMaterials) {
                $results['visual_materials'] = $visualMaterials;
            } else {
                $results['errors'][] = 'المواد البصرية: ' . $this->getLastError();
            }
        }

        // توليد الأنشطة الصفية (حسب الاختيار)
        if (in_array('class_activities', $this->selectedSections)) {
            $classActivities = $this->generateClassActivities($content);
            $callCount++;
            if ($classActivities) {
                $results['class_activities'] = $classActivities;
            } else {
                $results['errors'][] = 'الأنشطة الصفية: ' . $this->getLastError();
            }
        }

        // توليد القصص التعليمية (حسب الاختيار)
        if (in_array('educational_stories', $this->selectedSections)) {
            $educationalStories = $this->generateEducationalStories($content);
            $callCount++;
            if ($educationalStories) {
                $results['educational_stories'] = $educationalStories;
            } else {
                $results['errors'][] = 'القصص التعليمية: ' . $this->getLastError();
            }
        }

        // توليد الخرائط الذهنية (حسب الاختيار)
        if (in_array('mind_maps', $this->selectedSections)) {
            $mindMaps = $this->generateMindMaps($content);
            $callCount++;
            if ($mindMaps) {
                $results['mind_maps'] = $mindMaps;
            } else {
                $results['errors'][] = 'الخرائط الذهنية: ' . $this->getLastError();
            }
        }

        // توليد ملخص الدرس (حسب الاختيار)
        if (in_array('lesson_summary', $this->selectedSections)) {
            $lessonSummary = $this->generateLessonSummary($content);
            $callCount++;
            if ($lessonSummary) {
                $results['lesson_summary'] = $lessonSummary;
            } else {
                $results['errors'][] = 'ملخص الدرس: ' . $this->getLastError();
            }
        }
        
        return $results;
    }
    
    /**
     * حفظ النتائج في قاعدة البيانات
     */
    public function saveResults($lessonId, $lessonPlan, $questionBank, $visualMaterials, $examHtml = null, $examDuration = 20, $examModels = 3, $classActivities = null, $educationalStories = null, $mindMaps = null, $lessonSummary = null, $customContent = null, $examMcCount = null, $examTfCount = null, $examEssayCount = null) {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $lockStmt = $this->db->prepare('SELECT status FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE');
            $lockStmt->execute([$lessonId, $this->teacherId]);
            $beforeStatus = $lockStmt->fetchColumn();
            if ($beforeStatus === false) throw new RuntimeException('Lesson not found for result persistence.');

            // التحقق من وجود الأعمدة
            $hasClassActivitiesColumn = $this->columnExists('ai_lessons', 'class_activities');
            $hasEducationalStoriesColumn = $this->columnExists('ai_lessons', 'educational_stories');
            $hasMindMapsColumn = $this->columnExists('ai_lessons', 'mind_maps');
            $hasLessonSummaryColumn = $this->columnExists('ai_lessons', 'lesson_summary');
            $hasCustomContentColumn = $this->columnExists('ai_lessons', 'custom_content');
            
            // بناء الاستعلام ديناميكياً
            $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
            
            $setClauses = [
                'generated_prep = ?',
                'question_bank = ?',
                'visual_materials = ?'
            ];
            
            $encodedPlan = $lessonPlan ? json_encode($lessonPlan, $jsonFlags) : null;
            $encodedQB = $questionBank ? json_encode($questionBank, $jsonFlags) : null;
            $encodedVisual = $visualMaterials ? json_encode($visualMaterials, $jsonFlags) : null;
            
            // التحقق من نجاح json_encode - منع فقدان البيانات الصامت
            if ($questionBank && $encodedQB === false) {
                error_log("saveResults: json_encode failed for question_bank (lesson {$lessonId}): " . json_last_error_msg());
                // إعادة المحاولة مع تحويل الترميز
                $encodedQB = json_encode($questionBank, $jsonFlags | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            
            $params = [
                $encodedPlan ?: null,
                $encodedQB ?: null,
                $encodedVisual ?: null
            ];
            
            if ($hasClassActivitiesColumn) {
                $setClauses[] = 'class_activities = ?';
                $params[] = $classActivities ? (json_encode($classActivities, $jsonFlags) ?: null) : null;
            }
            if ($hasEducationalStoriesColumn) {
                $setClauses[] = 'educational_stories = ?';
                $params[] = $educationalStories ? (json_encode($educationalStories, $jsonFlags) ?: null) : null;
            }
            if ($hasMindMapsColumn) {
                $setClauses[] = 'mind_maps = ?';
                $params[] = $mindMaps ? (json_encode($mindMaps, $jsonFlags) ?: null) : null;
            }
            if ($hasLessonSummaryColumn) {
                $setClauses[] = 'lesson_summary = ?';
                $params[] = $lessonSummary ? (json_encode($lessonSummary, $jsonFlags) ?: null) : null;
            }
            if ($hasCustomContentColumn) {
                $setClauses[] = 'custom_content = ?';
                $params[] = $customContent ? (json_encode($customContent, $jsonFlags) ?: null) : null;
            }
            
            $setClauses[] = 'exam_html = ?';
            $setClauses[] = 'exam_duration = ?';
            $setClauses[] = 'exam_models_count = ?';
            $params[] = $examHtml;
            $params[] = $examDuration;
            $params[] = $examModels;
            
            // حفظ إعدادات عدد الأسئلة
            if ($examMcCount !== null) {
                $setClauses[] = 'exam_mc_count = ?';
                $params[] = intval($examMcCount);
            }
            if ($examTfCount !== null) {
                $setClauses[] = 'exam_tf_count = ?';
                $params[] = intval($examTfCount);
            }
            if ($examEssayCount !== null) {
                $setClauses[] = 'exam_essay_count = ?';
                $params[] = intval($examEssayCount);
            }
            
            $setClauses[] = "status = 'completed'";
            $setClauses[] = 'updated_at = NOW()';
            
            $params[] = $lessonId;
            $params[] = $this->teacherId;
            
            $sql = "UPDATE ai_lessons SET " . implode(', ', $setClauses) . " WHERE id = ? AND teacher_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $fingerprintParts = array_map(
                static fn($value): string => hash('sha256', (string) ($value ?? '')),
                array_slice($params, 0, -2)
            );
            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                'ai_lesson_results_saved',
                'ai_lesson',
                (int) $lessonId,
                (string) ($this->lessonTitle ?? ''),
                [
                    'status_before' => $beforeStatus,
                    'status_after' => 'completed',
                    'result_field_count' => count($setClauses),
                    'results_sha256' => hash('sha256', implode('|', $fingerprintParts)),
                    'exam_duration' => (int) $examDuration,
                    'exam_models_count' => (int) $examModels,
                    'direct_undo' => false,
                    'reason' => 'generated_content_restore_not_enabled',
                ]
            );
            if ($ownsTransaction) $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            $this->lastError = 'تعذر حفظ نتائج الدرس بأمان';
            error_log('LessonGenerator::saveResults Error: ' . $e->getMessage());
            
            // تحديث حالة الخطأ
            try {
                $failureOwnsTransaction = !$this->db->inTransaction();
                if ($failureOwnsTransaction) $this->db->beginTransaction();
                $stmt = $this->db->prepare("
                    UPDATE ai_lessons 
                    SET status = 'error', error_message = ?
                    WHERE id = ? AND teacher_id = ?
                ");
                $stmt->execute([$this->lastError, $lessonId, $this->teacherId]);
                (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                    'ai_lesson_results_failed',
                    'ai_lesson',
                    (int) $lessonId,
                    (string) ($this->lessonTitle ?? ''),
                    ['status_after' => 'error'],
                    ['outcome' => 'failure']
                );
                if ($failureOwnsTransaction) $this->db->commit();
            } catch (Throwable $e2) {
                if (isset($failureOwnsTransaction) && $failureOwnsTransaction && $this->db->inTransaction()) $this->db->rollBack();
                error_log("Error updating lesson status: " . $e2->getMessage());
            }
            
            return false;
        }
    }
    
    /**
     * التحقق من وجود عمود في جدول
     */
    private function columnExists($table, $column) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($result && $result['cnt'] > 0);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * استخراج JSON من النص - محسّن
     */
    private function extractJSON($text) {
        if (empty($text)) {
            return null;
        }
        
        // البحث عن JSON في النص
        $text = trim($text);
        
        // إزالة BOM إن وجد
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        
        // إزالة علامات markdown إن وجدت (متعددة الأنماط)
        $text = preg_replace('/^```(?:json)?\s*/im', '', $text);
        $text = preg_replace('/\s*```\s*$/im', '', $text);
        
        // إزالة أي نص قبل أول { أو [
        $text = preg_replace('/^[^{\[]*/', '', $text);
        
        // إزالة أي نص بعد آخر } أو ]
        $text = preg_replace('/[^}\]]*$/', '', $text);
        
        // تنظيف الأحرف غير المرئية والتحكم
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
        
        // إصلاح الفواصل الزائدة في JSON
        $text = preg_replace('/,\s*(\}|\])/', '$1', $text);
        
        // محاولة تحليل JSON مباشرة
        $data = json_decode($text, true);
        
        if ($data !== null && json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
        
        // محاولة إصلاح JSON المكسور - إزالة التعليقات
        $cleanText = preg_replace('/\/\/[^\n]*/', '', $text);
        $cleanText = preg_replace('/\/\*.*?\*\//s', '', $cleanText);
        
        $data = json_decode($cleanText, true);
        if ($data !== null && json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
        
        // البحث عن أول { وآخر } (للكائنات)
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        
        if ($start !== false && $end !== false && $end > $start) {
            $jsonStr = substr($text, $start, $end - $start + 1);
            
            // محاولة إصلاح الاقتباسات المفردة
            $jsonStr = preg_replace("/(?<!\\\\)'([^']*)'/", '"$1"', $jsonStr);
            
            $data = json_decode($jsonStr, true);
            
            if ($data !== null && json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
        
        // البحث عن أول [ وآخر ] (للمصفوفات)
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        
        if ($start !== false && $end !== false && $end > $start) {
            $jsonStr = substr($text, $start, $end - $start + 1);
            $data = json_decode($jsonStr, true);
            
            if ($data !== null && json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
        
        // تسجيل خطأ JSON للتشخيص
        $this->lastError = 'فشل في تحليل JSON: ' . json_last_error_msg();
        
        return null;
    }
    
    /**
     * الحصول على درس من قاعدة البيانات
     */
    public function getLesson($lessonId) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM ai_lessons 
                WHERE id = ? AND teacher_id = ?
            ");
            $stmt->execute([$lessonId, $this->teacherId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->lastError = 'خطأ في جلب الدرس: ' . $e->getMessage();
            return null;
        }
    }
    
    /**
     * الحصول على جميع دروس المعلم
     */
    public function getTeacherLessons($limit = 50, $offset = 0) {
        try {
            $limit = intval($limit);
            $offset = intval($offset);
            $stmt = $this->db->prepare("
                SELECT id, title, subject, grade_level, language, duration_minutes, status, created_at
                FROM ai_lessons 
                WHERE teacher_id = ?
                ORDER BY created_at DESC
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute([$this->teacherId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->lastError = 'خطأ في جلب الدروس: ' . $e->getMessage();
            return [];
        }
    }
    
    /**
     * حذف درس
     */
    public function deleteLesson($lessonId) {
        $ownsTransaction = !$this->db->inTransaction();
        $powerPointPath = null;
        try {
            if ($ownsTransaction) $this->db->beginTransaction();

            $lockStmt = $this->db->prepare(
                'SELECT * FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE'
            );
            $lockStmt->execute([(int) $lessonId, (int) $this->teacherId]);
            $lesson = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lesson) {
                if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
                $this->lastError = 'الدرس غير موجود أو لا تملك صلاحية حذفه';
                return false;
            }

            $powerPointPath = isset($lesson['powerpoint_path'])
                ? trim((string) $lesson['powerpoint_path'])
                : null;

            $deleteStmt = $this->db->prepare(
                'DELETE FROM ai_lessons WHERE id = ? AND teacher_id = ?'
            );
            $deleteStmt->execute([(int) $lessonId, (int) $this->teacherId]);
            if ($deleteStmt->rowCount() !== 1) {
                throw new RuntimeException('AI lesson delete did not affect exactly one owned row.');
            }

            $contentFingerprints = [];
            foreach ([
                'original_content',
                'generated_prep',
                'question_bank',
                'visual_materials',
                'class_activities',
                'educational_stories',
                'mind_maps',
                'lesson_summary',
                'custom_content',
                'exam_html',
            ] as $field) {
                if (isset($lesson[$field]) && $lesson[$field] !== '') {
                    $contentFingerprints[$field] = hash('sha256', (string) $lesson[$field]);
                }
            }

            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                'delete',
                'ai_lesson',
                (int) $lessonId,
                (string) ($lesson['title'] ?? ''),
                [
                    'status_before' => (string) ($lesson['status'] ?? ''),
                    'content_fingerprints' => $contentFingerprints,
                    'powerpoint_path_sha256' => $powerPointPath
                        ? hash('sha256', $powerPointPath)
                        : null,
                    'direct_undo' => false,
                    'reason' => 'generated_content_non_restorable',
                ]
            );

            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            $this->lastError = 'تعذر حذف الدرس بأمان';
            error_log('LessonGenerator::deleteLesson Error: ' . $e->getMessage());
            return false;
        }

        // حذف الملف بعد نجاح معاملة قاعدة البيانات فقط حتى لا تفقد الإشارة إليه عند التراجع.
        if ($ownsTransaction && $powerPointPath) {
            $this->deletePowerPointArtifact($powerPointPath);
        }

        return true;
    }

    /**
     * حذف ملف العرض المرتبط بالدرس ضمن مجلدات التخزين المعتمدة فقط.
     */
    private function deletePowerPointArtifact(string $relativePath): void {
        $normalized = str_replace('\\', '/', trim($relativePath));
        if (
            $normalized === ''
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized)
            || preg_match('#(^|/)\.\.(/|$)#', $normalized)
        ) {
            error_log('LessonGenerator::deletePowerPointArtifact rejected an unsafe stored path.');
            return;
        }

        $allowedPrefixes = [
            'storage/exports/lessons/',
            'storage/canva_templates/',
        ];
        $matchedPrefix = null;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $matchedPrefix = $prefix;
                break;
            }
        }
        if ($matchedPrefix === null) {
            error_log('LessonGenerator::deletePowerPointArtifact rejected an unowned storage path.');
            return;
        }

        try {
            $referenceStmt = $this->db->prepare(
                'SELECT COUNT(*) FROM ai_lessons WHERE powerpoint_path = ?'
            );
            $referenceStmt->execute([$normalized]);
            if ((int) $referenceStmt->fetchColumn() > 0) return;

            if ($matchedPrefix === 'storage/canva_templates/') {
                $tableStmt = $this->db->query(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'canva_templates'"
                );
                if ((int) $tableStmt->fetchColumn() > 0) {
                    $templateStmt = $this->db->prepare(
                        'SELECT COUNT(*) FROM canva_templates WHERE pptx_local_path = ?'
                    );
                    $templateStmt->execute([$normalized]);
                    if ((int) $templateStmt->fetchColumn() > 0) return;
                }
            }
        } catch (Throwable $e) {
            error_log('LessonGenerator::deletePowerPointArtifact reference check failed: ' . $e->getMessage());
            return;
        }

        $projectRoot = realpath(dirname(__DIR__));
        $storageRoot = realpath(
            dirname(__DIR__) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, rtrim($matchedPrefix, '/'))
        );
        $absolutePath = $projectRoot
            ? realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized))
            : false;

        if (
            !$projectRoot
            || !$storageRoot
            || !$absolutePath
            || !is_file($absolutePath)
            || !str_starts_with($absolutePath, $storageRoot . DIRECTORY_SEPARATOR)
        ) {
            return;
        }

        if (!@unlink($absolutePath)) {
            error_log('LessonGenerator::deletePowerPointArtifact failed to remove a lesson artifact.');
        }
    }
}
