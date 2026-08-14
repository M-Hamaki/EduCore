<?php
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
require_once __DIR__ . '/ExamTemplateRenderer.php';
/**
 * كلاس توليد الامتحانات الإلكترونية
 * Exam Generator Class for creating secure electronic exams
 */

class ExamGenerator {
    private $questions;
    private $language;
    private $duration;
    private $passingPercentage;
    private $modelsCount;
    private $lastError;
    private $actualQuestionCount;
    private $antiCheatEnabled;
    private $studentInfoEnabled;
    private $mcCount;
    private $tfCount;
    private $essayCount;
    private $modelType; // 'shuffle' or 'different'
    private $theme; // exam visual theme
    private $preparedModels;
    private ExamTemplateRenderer $templateRenderer;
    
    /** Available exam themes */
    public static $availableThemes = [
        'classic' => ['name_ar' => 'كلاسيكي', 'name_en' => 'Classic', 'icon' => '🎓', 'colors' => ['#667eea', '#764ba2']],
        'ocean'   => ['name_ar' => 'المحيط', 'name_en' => 'Ocean', 'icon' => '🌊', 'colors' => ['#0077b6', '#023e8a']],
        'nature'  => ['name_ar' => 'طبيعي', 'name_en' => 'Nature', 'icon' => '🌿', 'colors' => ['#2d6a4f', '#52b788']],
        'sunset'  => ['name_ar' => 'الغروب', 'name_en' => 'Sunset', 'icon' => '🌅', 'colors' => ['#e76f51', '#f4a261']],
        'rose'    => ['name_ar' => 'وردي', 'name_en' => 'Rose', 'icon' => '🌸', 'colors' => ['#be185d', '#ec4899']],
        'dark'    => ['name_ar' => 'داكن', 'name_en' => 'Dark', 'icon' => '🌙', 'colors' => ['#1e1e2e', '#2d2d44']],
        'royal'   => ['name_ar' => 'ملكي', 'name_en' => 'Royal', 'icon' => '💎', 'colors' => ['#7e22ce', '#a855f7']],
    ];
    
    /**
     * Constructor
     */
    public function __construct($language = 'ar') {
        $this->language = $language;
        $this->duration = 20; // دقيقة
        $this->passingPercentage = 50;
        $this->modelsCount = 3;
        $this->questions = [];
        $this->lastError = null;
        $this->actualQuestionCount = 0;
        $this->antiCheatEnabled = true;
        $this->studentInfoEnabled = true;
        $this->mcCount = 10;
        $this->tfCount = 10;
        $this->essayCount = 0;
        $this->modelType = 'shuffle';
        $this->theme = 'classic';
        $this->preparedModels = [];
        $this->templateRenderer = new ExamTemplateRenderer();
    }
    
    /**
     * الحصول على آخر خطأ
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * الحصول على عدد الأسئلة الفعلي
     */
    public function getActualQuestionCount() {
        return $this->actualQuestionCount;
    }
    
    /**
     * تعيين الأسئلة
     */
    public function setQuestions($questionBank) {
        $this->questions = $questionBank;
    }

    /**
     * استخدام نماذج سبق توليدها لضمان أن ملفات الإجابة تطابق الامتحان المحفوظ حرفياً.
     */
    public function setPreparedModels(array $models) {
        $validated = [];
        foreach (['A', 'B', 'C', 'D'] as $letter) {
            if (isset($models[$letter]) && is_array($models[$letter])) {
                $validated[$letter] = array_values($models[$letter]);
            }
        }
        $this->preparedModels = $validated;
        if ($validated) {
            $this->modelsCount = count($validated);
        }
    }

    /**
     * استخراج بيانات النماذج المضمّنة في ملف الامتحان الذي ولّده النظام.
     */
    public static function extractPreparedModels(string $examHtml): array {
        $marker = 'const MODELS =';
        $start = strpos($examHtml, $marker);
        if ($start === false) return [];

        $jsonStart = $start + strlen($marker);
        $nextMarker = strpos($examHtml, 'const SINGLE_MODEL', $jsonStart);
        if ($nextMarker === false) return [];

        $assignment = substr($examHtml, $jsonStart, $nextMarker - $jsonStart);
        $delimiter = strrpos($assignment, ';');
        if ($delimiter === false) return [];

        $models = json_decode(trim(substr($assignment, 0, $delimiter)), true);
        if (!is_array($models)) return [];

        $validated = [];
        foreach (['A', 'B', 'C', 'D'] as $letter) {
            if (isset($models[$letter]) && is_array($models[$letter])) {
                $validated[$letter] = array_values($models[$letter]);
            }
        }
        return $validated;
    }

    /**
     * إنشاء نسخة من الامتحان المحفوظ تحتوي النموذج المطلوب فقط دون إعادة خلط الأسئلة.
     */
    public static function filterExamHtmlToModel(string $examHtml, string $modelLetter): ?string {
        $modelLetter = strtoupper(trim($modelLetter));
        $models = self::extractPreparedModels($examHtml);
        if (!isset($models[$modelLetter])) return null;

        $marker = 'const MODELS =';
        $start = strpos($examHtml, $marker);
        $jsonStart = $start + strlen($marker);
        $nextMarker = strpos($examHtml, 'const SINGLE_MODEL', $jsonStart);
        if ($start === false || $nextMarker === false) return null;

        $safeJson = json_encode(
            [$modelLetter => $models[$modelLetter]],
            JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        if ($safeJson === false) return null;

        $filtered = substr($examHtml, 0, $start)
            . $marker . ' ' . $safeJson . ";\n        "
            . substr($examHtml, $nextMarker);

        return preg_replace(
            '/const\s+SINGLE_MODEL\s*=\s*(?:true|false)\s*;/',
            'const SINGLE_MODEL = true;',
            $filtered,
            1
        ) ?: null;
    }
    
    /**
     * تعيين مدة الامتحان
     * القيمة 0 تعني وقت مفتوح بدون حد زمني
     */
    public function setDuration($minutes) {
        $minutes = intval($minutes);
        $this->duration = ($minutes === 0) ? 0 : max(1, intval($minutes));
    }
    
    /**
     * تعيين عدد النماذج
     */
    public function setModelsCount($count) {
        $this->modelsCount = max(1, min(4, intval($count)));
    }
    
    /**
     * تعيين نسبة النجاح
     */
    public function setPassingPercentage($percentage) {
        $this->passingPercentage = max(0, min(100, intval($percentage)));
    }
    
    /**
     * تفعيل/إلغاء نظام منع الغش
     */
    public function setAntiCheatEnabled($enabled) {
        $this->antiCheatEnabled = (bool)$enabled;
    }
    
    /**
     * تفعيل/إلغاء طلب بيانات الطالب
     */
    public function setStudentInfoEnabled($enabled) {
        $this->studentInfoEnabled = (bool)$enabled;
    }
    
    /**
     * تعيين عدد أسئلة الاختيار من متعدد
     */
    public function setMCCount($count) {
        $this->mcCount = max(0, min(20, intval($count)));
    }
    
    /**
     * تعيين عدد أسئلة صح/خطأ
     */
    public function setTFCount($count) {
        $this->tfCount = max(0, min(20, intval($count)));
    }
    
    /**
     * تعيين عدد الأسئلة المقالية
     */
    public function setEssayCount($count) {
        $this->essayCount = max(0, min(10, intval($count)));
    }
    
    /**
     * تعيين نوع النماذج (shuffle أو different)
     */
    public function setModelType($type) {
        $this->modelType = in_array($type, ['shuffle', 'different']) ? $type : 'shuffle';
    }
    
    /**
     * تعيين ثيم الامتحان
     */
    public function setTheme($theme) {
        $this->theme = array_key_exists($theme, self::$availableThemes) ? $theme : 'classic';
    }
    
    /**
     * الحصول على الثيم الحالي
     */
    public function getTheme() {
        return $this->theme;
    }
    
    /**
     * الحصول على CSS الثيمات
     */
    private function getThemeCSS() {
        return <<<'THEMECSS'
        /* === Exam Themes === */
        [data-theme="classic"], :root {
            --exam-bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --exam-header: linear-gradient(135deg, #1e3a8a, #3b82f6);
            --exam-primary: #3b82f6;
            --exam-primary-dark: #1e3a8a;
            --exam-primary-light: #dbeafe;
            --exam-primary-hover: #eff6ff;
            --exam-badge: linear-gradient(135deg, #3b82f6, #1e3a8a);
            --exam-submit: linear-gradient(135deg, #10b981, #059669);
            --exam-submit-hover: rgba(16, 185, 129, 0.4);
            --exam-heading: #1e3a8a;
            --exam-card-bg: #f8fafc;
            --exam-body-bg: #ffffff;
            --exam-container-bg: #ffffff;
            --exam-text: #1e293b;
            --exam-text-secondary: #64748b;
            --exam-text-option: #334155;
            --exam-border: #e2e8f0;
            --exam-input-border: #94a3b8;
            --exam-shadow: rgba(0,0,0,0.3);
            --exam-submit-bg: #f1f5f9;
            --exam-modal-bg: #ffffff;
            --exam-modal-text: #1e293b;
            --exam-modal-secondary: #64748b;
            --exam-overlay-bg: rgba(0,0,0,0.8);
            --exam-info-bg: #ffffff;
            --exam-info-title: #1e3a8a;
            --exam-info-label: #334155;
            --exam-info-input-border: #e2e8f0;
            --exam-info-input-focus: rgba(59, 130, 246, 0.1);
            --exam-info-btn: linear-gradient(135deg, #10b981, #059669);
            --exam-progress: linear-gradient(90deg, #10b981, #34d399);
            --exam-dropdown-bg: #ffffff;
            --exam-dropdown-text: #334155;
            --exam-dropdown-hover: #f1f5f9;
            --exam-dropdown-active: #3b82f6;
        }
        [data-theme="ocean"] {
            --exam-bg: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            --exam-header: linear-gradient(135deg, #023e8a, #0096c7);
            --exam-primary: #0096c7;
            --exam-primary-dark: #023e8a;
            --exam-primary-light: #caf0f8;
            --exam-primary-hover: #e0f7fa;
            --exam-badge: linear-gradient(135deg, #0096c7, #0077b6);
            --exam-submit: linear-gradient(135deg, #00b4d8, #0077b6);
            --exam-submit-hover: rgba(0, 180, 216, 0.4);
            --exam-heading: #023e8a;
            --exam-dropdown-active: #0096c7;
        }
        [data-theme="nature"] {
            --exam-bg: linear-gradient(135deg, #2d6a4f 0%, #52b788 100%);
            --exam-header: linear-gradient(135deg, #1b4332, #2d6a4f);
            --exam-primary: #40916c;
            --exam-primary-dark: #1b4332;
            --exam-primary-light: #d8f3dc;
            --exam-primary-hover: #e8f5e9;
            --exam-badge: linear-gradient(135deg, #2d6a4f, #1b4332);
            --exam-submit: linear-gradient(135deg, #40916c, #2d6a4f);
            --exam-submit-hover: rgba(64, 145, 108, 0.4);
            --exam-heading: #1b4332;
            --exam-dropdown-active: #40916c;
        }
        [data-theme="sunset"] {
            --exam-bg: linear-gradient(135deg, #e76f51 0%, #f4a261 100%);
            --exam-header: linear-gradient(135deg, #9c2c10, #e76f51);
            --exam-primary: #e76f51;
            --exam-primary-dark: #9c2c10;
            --exam-primary-light: #fce4d6;
            --exam-primary-hover: #fff3e0;
            --exam-badge: linear-gradient(135deg, #e76f51, #9c2c10);
            --exam-submit: linear-gradient(135deg, #f4a261, #e76f51);
            --exam-submit-hover: rgba(231, 111, 81, 0.4);
            --exam-heading: #9c2c10;
            --exam-dropdown-active: #e76f51;
        }
        [data-theme="rose"] {
            --exam-bg: linear-gradient(135deg, #be185d 0%, #ec4899 100%);
            --exam-header: linear-gradient(135deg, #831843, #be185d);
            --exam-primary: #ec4899;
            --exam-primary-dark: #831843;
            --exam-primary-light: #fce7f3;
            --exam-primary-hover: #fdf2f8;
            --exam-badge: linear-gradient(135deg, #ec4899, #be185d);
            --exam-submit: linear-gradient(135deg, #f472b6, #be185d);
            --exam-submit-hover: rgba(236, 72, 153, 0.4);
            --exam-heading: #831843;
            --exam-dropdown-active: #ec4899;
        }
        [data-theme="dark"] {
            --exam-bg: linear-gradient(135deg, #1e1e2e 0%, #2d2d44 100%);
            --exam-header: linear-gradient(135deg, #0f0f1a, #1e1e2e);
            --exam-primary: #818cf8;
            --exam-primary-dark: #4f46e5;
            --exam-primary-light: rgba(129, 140, 248, 0.15);
            --exam-primary-hover: rgba(129, 140, 248, 0.1);
            --exam-badge: linear-gradient(135deg, #818cf8, #6366f1);
            --exam-submit: linear-gradient(135deg, #818cf8, #6366f1);
            --exam-submit-hover: rgba(129, 140, 248, 0.4);
            --exam-heading: #a5b4fc;
            --exam-card-bg: #2d2d44;
            --exam-body-bg: #1e1e2e;
            --exam-container-bg: #252540;
            --exam-text: #e2e8f0;
            --exam-text-secondary: #94a3b8;
            --exam-text-option: #cbd5e1;
            --exam-border: #3d3d5c;
            --exam-input-border: #4a4a6a;
            --exam-shadow: rgba(0,0,0,0.5);
            --exam-submit-bg: #1e1e2e;
            --exam-modal-bg: #252540;
            --exam-modal-text: #e2e8f0;
            --exam-modal-secondary: #94a3b8;
            --exam-overlay-bg: rgba(0,0,0,0.85);
            --exam-info-bg: #252540;
            --exam-info-title: #a5b4fc;
            --exam-info-label: #cbd5e1;
            --exam-info-input-border: #3d3d5c;
            --exam-info-input-focus: rgba(129, 140, 248, 0.15);
            --exam-info-btn: linear-gradient(135deg, #818cf8, #6366f1);
            --exam-progress: linear-gradient(90deg, #34d399, #6ee7b7);
            --exam-dropdown-bg: #252540;
            --exam-dropdown-text: #cbd5e1;
            --exam-dropdown-hover: #2d2d44;
            --exam-dropdown-active: #818cf8;
        }
        [data-theme="royal"] {
            --exam-bg: linear-gradient(135deg, #7e22ce 0%, #a855f7 100%);
            --exam-header: linear-gradient(135deg, #581c87, #7e22ce);
            --exam-primary: #7e22ce;
            --exam-primary-dark: #581c87;
            --exam-primary-light: #f3e8ff;
            --exam-primary-hover: #faf5ff;
            --exam-badge: linear-gradient(135deg, #a855f7, #7e22ce);
            --exam-submit: linear-gradient(135deg, #9333ea, #6b21a8);
            --exam-submit-hover: rgba(147, 51, 234, 0.4);
            --exam-heading: #581c87;
            --exam-dropdown-active: #7e22ce;
        }
THEMECSS;
    }
    
    /**
     * تحضير أسئلة الامتحان (مرن - يقبل أي عدد متاح من الأسئلة)
     * @param int $maxMC الحد الأقصى لأسئلة الاختيار من متعدد (افتراضي 10)
     * @param int $maxTF الحد الأقصى لأسئلة صح/خطأ (افتراضي 10)
     */
    private function prepareExamQuestions($maxMC = 10, $maxTF = 10) {
        $examQuestions = [];
        
        // استخدام العدد المحدد من المستخدم
        $maxMC = $this->mcCount;
        $maxTF = $this->tfCount;
        
        // اختيار أسئلة الاختيار من متعدد (حتى الحد الأقصى المتاح)
        if ($maxMC > 0 && isset($this->questions['multiple_choice']) && !empty($this->questions['multiple_choice'])) {
            $mcCount = min(count($this->questions['multiple_choice']), $maxMC);
            $mcQuestions = array_slice($this->questions['multiple_choice'], 0, $mcCount);
            foreach ($mcQuestions as $q) {
                // التحقق من وجود البيانات المطلوبة
                if (!isset($q['question']) || !isset($q['options']) || !isset($q['correct_answer'])) {
                    continue;
                }
                $examQuestions[] = [
                    'type' => 'multiple_choice',
                    'question' => $q['question'],
                    'options' => $q['options'],
                    'correct' => $q['correct_answer']
                ];
            }
        }
        
        // اختيار أسئلة صح/خطأ (حتى الحد الأقصى المتاح)
        if ($maxTF > 0 && isset($this->questions['true_false']) && !empty($this->questions['true_false'])) {
            $tfCount = min(count($this->questions['true_false']), $maxTF);
            $tfQuestions = array_slice($this->questions['true_false'], 0, $tfCount);
            foreach ($tfQuestions as $q) {
                // التحقق من وجود البيانات المطلوبة
                if (!isset($q['statement'])) {
                    continue;
                }
                $examQuestions[] = [
                    'type' => 'true_false',
                    'question' => $q['statement'],
                    'correct' => isset($q['correct_answer']) ? ($q['correct_answer'] ? 1 : 0) : 0
                ];
            }
        }
        
        // اختيار الأسئلة المقالية
        if ($this->essayCount > 0 && isset($this->questions['graduated']) && !empty($this->questions['graduated'])) {
            $essayMax = min(count($this->questions['graduated']), $this->essayCount);
            $essayQuestions = array_slice($this->questions['graduated'], 0, $essayMax);
            foreach ($essayQuestions as $q) {
                if (!isset($q['question'])) {
                    continue;
                }
                $examQuestions[] = [
                    'type' => 'essay',
                    'question' => $q['question'],
                    'model_answer' => $q['model_answer'] ?? '',
                    'difficulty' => $q['difficulty'] ?? 'medium',
                    'cognitive_level' => $q['cognitive_level'] ?? ''
                ];
            }
        }
        
        return $examQuestions;
    }
    
    /**
     * تحضير أسئلة لنموذج مختلف (سحب أسئلة مختلفة من البنك)
     */
    private function prepareExamQuestionsForDifferentModel($modelIndex) {
        $examQuestions = [];
        $maxMC = $this->mcCount;
        $maxTF = $this->tfCount;
        
        // حساب بداية السحب بناءً على رقم النموذج
        if ($maxMC > 0 && isset($this->questions['multiple_choice']) && !empty($this->questions['multiple_choice'])) {
            $totalMC = count($this->questions['multiple_choice']);
            $offset = ($modelIndex * $maxMC) % $totalMC;
            
            // سحب الأسئلة مع الالتفاف حول المصفوفة
            $allMC = $this->questions['multiple_choice'];
            shuffle($allMC); // خلط عشوائي ثم سحب
            $mcQuestions = array_slice($allMC, 0, min($maxMC, $totalMC));
            
            foreach ($mcQuestions as $q) {
                if (!isset($q['question']) || !isset($q['options']) || !isset($q['correct_answer'])) {
                    continue;
                }
                $examQuestions[] = [
                    'type' => 'multiple_choice',
                    'question' => $q['question'],
                    'options' => $q['options'],
                    'correct' => $q['correct_answer']
                ];
            }
        }
        
        if ($maxTF > 0 && isset($this->questions['true_false']) && !empty($this->questions['true_false'])) {
            $totalTF = count($this->questions['true_false']);
            $allTF = $this->questions['true_false'];
            shuffle($allTF);
            $tfQuestions = array_slice($allTF, 0, min($maxTF, $totalTF));
            
            foreach ($tfQuestions as $q) {
                if (!isset($q['statement'])) {
                    continue;
                }
                $examQuestions[] = [
                    'type' => 'true_false',
                    'question' => $q['statement'],
                    'correct' => isset($q['correct_answer']) ? ($q['correct_answer'] ? 1 : 0) : 0
                ];
            }
        }
        
        if ($this->essayCount > 0 && isset($this->questions['graduated']) && !empty($this->questions['graduated'])) {
            $allEssay = $this->questions['graduated'];
            shuffle($allEssay);
            $essayQuestions = array_slice($allEssay, 0, min($this->essayCount, count($allEssay)));
            
            foreach ($essayQuestions as $q) {
                if (!isset($q['question'])) continue;
                $examQuestions[] = [
                    'type' => 'essay',
                    'question' => $q['question'],
                    'model_answer' => $q['model_answer'] ?? '',
                    'difficulty' => $q['difficulty'] ?? 'medium',
                    'cognitive_level' => $q['cognitive_level'] ?? ''
                ];
            }
        }
        
        return $examQuestions;
    }
    
    /**
     * إنشاء نموذج امتحان مع ترتيب مختلف
     */
    private function createModel($questions, $modelLetter) {
        $shuffled = $questions;
        
        // خلط الأسئلة بناءً على النموذج
        switch ($modelLetter) {
            case 'A':
                // النموذج الأصلي
                break;
            case 'B':
                // عكس الترتيب
                $shuffled = array_reverse($shuffled);
                break;
            case 'C':
                // خلط عشوائي
                shuffle($shuffled);
                break;
        }
        
        // خلط خيارات أسئلة الاختيار من متعدد (تجاهل المقالية)
        foreach ($shuffled as &$q) {
            if ($q['type'] === 'multiple_choice' && isset($q['options'])) {
                $correctAnswer = $q['options'][$q['correct']];
                shuffle($q['options']);
                $q['correct'] = array_search($correctAnswer, $q['options']);
            }
        }
        
        return $shuffled;
    }
    
    /**
     * توليد ملف HTML للامتحان
     * @param string $title عنوان الامتحان
     * @param int $minQuestions الحد الأدنى المطلوب من الأسئلة (افتراضي 4)
     */
    public function generateExamHTML($title = 'امتحان إلكتروني', $minQuestions = 1) {
        if (empty($this->questions)) {
            $this->lastError = 'لا توجد أسئلة لإنشاء الامتحان';
            return null;
        }
        
        $examQuestions = $this->prepareExamQuestions();
        
        if (count($examQuestions) < $minQuestions) {
            $this->lastError = 'عدد الأسئلة غير كافٍ (المتاح: ' . count($examQuestions) . ' - المطلوب: ' . $minQuestions . ' على الأقل)';
            return null;
        }
        
        // إضافة معلومات عن عدد الأسئلة الفعلي
        $this->actualQuestionCount = count($examQuestions);
        
        // إنشاء النماذج بناءً على العدد المحدد ونوع النموذج
        $modelLetters = ['A', 'B', 'C', 'D'];
        $models = [];
        for ($i = 0; $i < $this->modelsCount; $i++) {
            $letter = $modelLetters[$i];
            if ($this->modelType === 'different' && $i > 0) {
                // نماذج مختلفة: سحب أسئلة مختلفة لكل نموذج
                $differentQuestions = $this->prepareExamQuestionsForDifferentModel($i);
                $models[$letter] = $differentQuestions;
            } else {
                $models[$letter] = $this->createModel($examQuestions, $letter);
            }
        }
        
        $modelsJson = json_encode(
            $models,
            JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        $isArabic = $this->language === 'ar';
        $dir = $isArabic ? 'rtl' : 'ltr';
        
        // النصوص حسب اللغة
        $texts = $this->getLanguageTexts();
        
        $html = $this->getExamTemplate($title, $dir, $modelsJson, $texts);
        
        return $html;
    }
    
    /**
     * الحصول على النماذج المتاحة
     * @return array قائمة بحروف النماذج المتاحة
     */
    public function getAvailableModels() {
        $modelLetters = ['A', 'B', 'C', 'D'];
        return array_slice($modelLetters, 0, $this->modelsCount);
    }
    
    /**
     * توليد ملف HTML لنموذج واحد فقط
     * @param string $modelLetter حرف النموذج (A, B, C, D)
     * @param string $title عنوان الامتحان
     * @param int $minQuestions الحد الأدنى المطلوب من الأسئلة
     */
    public function generateSingleModelHTML($modelLetter, $title = 'امتحان إلكتروني', $minQuestions = 1) {
        $modelLetter = strtoupper($modelLetter);
        $validModels = ['A', 'B', 'C', 'D'];
        
        if (!in_array($modelLetter, $validModels)) {
            $this->lastError = 'نموذج غير صالح: ' . $modelLetter;
            return null;
        }
        
        if (empty($this->questions) && empty($this->preparedModels[$modelLetter])) {
            $this->lastError = 'لا توجد أسئلة لإنشاء الامتحان';
            return null;
        }

        if (!empty($this->preparedModels[$modelLetter])) {
            $model = $this->preparedModels[$modelLetter];
        } else {
            $examQuestions = $this->prepareExamQuestions();
            if (count($examQuestions) < $minQuestions) {
                $this->lastError = 'عدد الأسئلة غير كافٍ';
                return null;
            }
            $modelIndex = array_search($modelLetter, $validModels, true);
            $model = $this->modelType === 'different' && $modelIndex > 0
                ? $this->prepareExamQuestionsForDifferentModel($modelIndex)
                : $this->createModel($examQuestions, $modelLetter);
        }

        $this->actualQuestionCount = count($model);
        $models = [$modelLetter => $model];

        $modelsJson = json_encode(
            $models,
            JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        $isArabic = $this->language === 'ar';
        $dir = $isArabic ? 'rtl' : 'ltr';
        
        $texts = $this->getLanguageTexts();
        
        // تعديل العنوان ليشمل حرف النموذج
        $titleWithModel = $title . ' - ' . ($isArabic ? 'النموذج ' : 'Model ') . $modelLetter;
        
        $html = $this->getExamTemplate($titleWithModel, $dir, $modelsJson, $texts, true);
        
        return $html;
    }
    
    /**
     * الحصول على النصوص حسب اللغة
     */
    private function getLanguageTexts() {
        if ($this->language === 'ar') {
            return [
                'title' => 'امتحان إلكتروني',
                'model' => 'النموذج',
                'time_remaining' => 'الوقت المتبقي',
                'minutes' => 'دقيقة',
                'seconds' => 'ثانية',
                'progress' => 'تقدم الاختبار',
                'answered' => 'تمت الإجابة عن',
                'of' => 'من',
                'questions' => 'سؤال',
                'question' => 'السؤال',
                'true' => 'صح',
                'false' => 'خطأ',
                'submit' => 'إنهاء الامتحان',
                'confirm_submit' => 'هل أنت متأكد من إنهاء الامتحان؟',
                'unanswered_warning' => 'يوجد أسئلة غير مجاب عنها:',
                'unanswered_count' => 'سؤال',
                'force_submit' => 'إرسال على أي حال',
                'go_back' => 'العودة للإجابة',
                'result' => 'النتيجة',
                'score' => 'الدرجة',
                'percentage' => 'النسبة المئوية',
                'status' => 'الحالة',
                'passed' => 'ناجح',
                'failed' => 'راسب',
                'show_answers' => 'عرض الإجابات الصحيحة',
                'correct_answer' => 'الإجابة الصحيحة',
                'your_answer' => 'إجابتك',
                'warning' => 'تحذير',
                'cheating_warning' => 'تم رصد محاولة غش!',
                'cheating_count' => 'عدد المخالفات',
                'exam_ended' => 'تم إنهاء الامتحان',
                'time_up' => 'انتهى الوقت!',
                'cheating_limit' => 'تم إنهاء الامتحان بسبب تجاوز حد المخالفات',
                'exam_already_taken' => 'لقد أنهيت هذا الامتحان مسبقاً',
                'multiple_choice' => 'أسئلة الاختيار من متعدد',
                'true_false' => 'أسئلة صح أو خطأ',
                'essay_questions' => 'أسئلة مقالية',
                'write_answer' => 'اكتب إجابتك هنا...',
                'essay_note' => 'ملاحظة: الأسئلة المقالية يتم تصحيحها يدوياً من قبل المعلم',
                'export_excel' => 'تصدير الدرجة إلى Excel',
                'export_warning' => 'يجب تصدير الدرجة أولاً قبل إغلاق الصفحة',
                'student_name_label' => 'اسم الطالب',
                'student_class_label' => 'الفصل',
                'score_obtained_label' => 'الدرجة المحصلة',
                'total_score_label' => 'الدرجة النهائية',
                'grade_label' => 'الدرجة',
                'percentage_label' => 'النسبة المئوية',
                'status_label' => 'الحالة',
                'model_label' => 'النموذج',
                'date_label' => 'التاريخ',
                'exported_success' => 'تم تصدير الدرجة بنجاح! يمكنك إغلاق الصفحة الآن.',
                'change_model_warning' => 'تحذير: سيتم مسح إجاباتك الحالية. هل تريد المتابعة؟'
            ];
        } elseif ($this->language === 'fr') {
            return [
                'title' => 'Examen Électronique',
                'model' => 'Modèle',
                'time_remaining' => 'Temps Restant',
                'minutes' => 'min',
                'seconds' => 'sec',
                'progress' => 'Progression',
                'answered' => 'Répondu',
                'of' => 'sur',
                'questions' => 'questions',
                'question' => 'Question',
                'true' => 'Vrai',
                'false' => 'Faux',
                'submit' => 'Soumettre l\'examen',
                'confirm_submit' => 'Êtes-vous sûr de vouloir soumettre?',
                'unanswered_warning' => 'Il y a des questions sans réponse:',
                'unanswered_count' => 'question(s)',
                'force_submit' => 'Soumettre quand même',
                'go_back' => 'Retour',
                'result' => 'Résultat',
                'score' => 'Score',
                'percentage' => 'Pourcentage',
                'status' => 'Statut',
                'passed' => 'Réussi',
                'failed' => 'Échoué',
                'show_answers' => 'Afficher les réponses correctes',
                'correct_answer' => 'Réponse correcte',
                'your_answer' => 'Votre réponse',
                'warning' => 'Avertissement',
                'cheating_warning' => 'Tentative de triche détectée!',
                'cheating_count' => 'Violations',
                'exam_ended' => 'Examen Terminé',
                'time_up' => 'Le temps est écoulé!',
                'cheating_limit' => 'Examen terminé pour dépassement de violations',
                'exam_already_taken' => 'Vous avez déjà passé cet examen',
                'multiple_choice' => 'Questions à Choix Multiples',
                'true_false' => 'Questions Vrai ou Faux',
                'essay_questions' => 'Questions à Rédaction',
                'write_answer' => 'Écrivez votre réponse ici...',
                'essay_note' => 'Note: Les questions à rédaction sont corrigées manuellement par l\'enseignant',
                'export_excel' => 'Exporter la note en Excel',
                'export_warning' => 'Vous devez exporter la note avant de fermer la page',
                'student_name_label' => 'Nom de l\'élève',
                'student_class_label' => 'Classe',
                'grade_label' => 'Note',
                'percentage_label' => 'Pourcentage',
                'status_label' => 'Statut',
                'model_label' => 'Modèle',
                'date_label' => 'Date',
                'exported_success' => 'Note exportée avec succès! Vous pouvez fermer la page.',
                'change_model_warning' => 'Attention: Vos réponses actuelles seront effacées. Voulez-vous continuer?'
            ];
        } else {
            return [
                'title' => 'Electronic Exam',
                'model' => 'Model',
                'time_remaining' => 'Time Remaining',
                'minutes' => 'min',
                'seconds' => 'sec',
                'progress' => 'Test Progress',
                'answered' => 'Answered',
                'of' => 'of',
                'questions' => 'questions',
                'question' => 'Question',
                'true' => 'True',
                'false' => 'False',
                'submit' => 'Submit Exam',
                'confirm_submit' => 'Are you sure you want to submit?',
                'unanswered_warning' => 'There are unanswered questions:',
                'unanswered_count' => 'question(s)',
                'force_submit' => 'Submit Anyway',
                'go_back' => 'Go Back',
                'result' => 'Result',
                'score' => 'Score',
                'percentage' => 'Percentage',
                'status' => 'Status',
                'passed' => 'Passed',
                'failed' => 'Failed',
                'show_answers' => 'Show Correct Answers',
                'correct_answer' => 'Correct Answer',
                'your_answer' => 'Your Answer',
                'warning' => 'Warning',
                'cheating_warning' => 'Cheating attempt detected!',
                'cheating_count' => 'Violations',
                'exam_ended' => 'Exam Ended',
                'time_up' => 'Time is up!',
                'cheating_limit' => 'Exam ended due to exceeding violation limit',
                'exam_already_taken' => 'You have already taken this exam',
                'multiple_choice' => 'Multiple Choice Questions',
                'true_false' => 'True or False Questions',
                'essay_questions' => 'Essay Questions',
                'write_answer' => 'Write your answer here...',
                'essay_note' => 'Note: Essay questions are graded manually by the teacher',
                'export_excel' => 'Export Grade to Excel',
                'export_warning' => 'You must export the grade before closing the page',
                'student_name_label' => 'Student Name',
                'student_class_label' => 'Class',
                'grade_label' => 'Grade',
                'percentage_label' => 'Percentage',
                'status_label' => 'Status',
                'model_label' => 'Model',
                'date_label' => 'Date',
                'exported_success' => 'Grade exported successfully! You can close the page now.',
                'change_model_warning' => 'Warning: Your current answers will be cleared. Do you want to continue?'
            ];
        }
    }
    
    /**
     * قالب HTML الكامل للامتحان
     * @param bool $singleModel إذا كان نموذج واحد فقط
     */
    private function getExamTemplate($title, $dir, $modelsJson, $texts, $singleModel = false) {
        return $this->templateRenderer->render(
            $title,
            $dir,
            $modelsJson,
            $texts,
            $singleModel,
            $this->duration,
            $this->passingPercentage,
            $this->antiCheatEnabled,
            $this->studentInfoEnabled,
            $this->language,
            $this->theme,
            $this->getThemeCSS()
        );
    }
    
    /**
     * توليد نموذج إجابة HTML لنموذج معين
     * @param string $modelLetter حرف النموذج
     * @param string $title عنوان الامتحان
     * @return string|null HTML نموذج الإجابة
     */
    public function generateAnswerKeyHTML($modelLetter = 'A', $title = 'نموذج إجابة') {
        $modelLetter = strtoupper(trim((string) $modelLetter));
        if (!in_array($modelLetter, ['A', 'B', 'C', 'D'], true)) {
            $this->lastError = 'نموذج غير صالح';
            return null;
        }

        if (empty($this->questions) && empty($this->preparedModels[$modelLetter])) {
            $this->lastError = 'لا توجد أسئلة';
            return null;
        }

        if (!empty($this->preparedModels[$modelLetter])) {
            $model = $this->preparedModels[$modelLetter];
        } else {
            $examQuestions = $this->prepareExamQuestions();
            if (empty($examQuestions)) {
                $this->lastError = 'لا توجد أسئلة كافية';
                return null;
            }
            $modelIndex = array_search($modelLetter, ['A', 'B', 'C', 'D'], true);
            $model = $this->modelType === 'different' && $modelIndex > 0
                ? $this->prepareExamQuestionsForDifferentModel($modelIndex)
                : $this->createModel($examQuestions, $modelLetter);
        }

        $isArabic = $this->language === 'ar';
        $dir = $isArabic ? 'rtl' : 'ltr';
        
        $texts = $isArabic ? [
            'answer_key' => 'نموذج الإجابة',
            'model' => 'النموذج',
            'mc' => 'أسئلة الاختيار من متعدد',
            'tf' => 'أسئلة صح أو خطأ',
            'essay' => 'أسئلة مقالية',
            'question' => 'السؤال',
            'correct_answer' => 'الإجابة الصحيحة',
            'model_answer' => 'الإجابة النموذجية',
            'true' => 'صح',
            'false' => 'خطأ',
            'page' => 'صفحة',
            'difficulty' => 'المستوى',
            'print' => 'طباعة'
        ] : [
            'answer_key' => 'Answer Key',
            'model' => 'Model',
            'mc' => 'Multiple Choice Questions',
            'tf' => 'True or False Questions',
            'essay' => 'Essay Questions',
            'question' => 'Question',
            'correct_answer' => 'Correct Answer',
            'model_answer' => 'Model Answer',
            'true' => 'True',
            'false' => 'False',
            'page' => 'Page',
            'difficulty' => 'Difficulty',
            'print' => 'Print'
        ];
        
        $mcQuestions = array_values(array_filter($model, fn($q) => $q['type'] === 'multiple_choice'));
        $tfQuestions = array_values(array_filter($model, fn($q) => $q['type'] === 'true_false'));
        $essayQuestions = array_values(array_filter($model, fn($q) => $q['type'] === 'essay'));
        
        $mcHtml = '';
        if (!empty($mcQuestions)) {
            $mcHtml = '<h2 style="color:#2563eb;border-bottom:2px solid #2563eb;padding-bottom:8px;margin-top:30px;">' . $texts['mc'] . '</h2>';
            $mcHtml .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            $mcHtml .= '<thead><tr style="background:#2563eb;color:#fff;">';
            $mcHtml .= '<th style="padding:10px;border:1px solid #ddd;width:40px;">#</th>';
            $mcHtml .= '<th style="padding:10px;border:1px solid #ddd;">' . $texts['question'] . '</th>';
            $mcHtml .= '<th style="padding:10px;border:1px solid #ddd;width:200px;">' . $texts['correct_answer'] . '</th>';
            $mcHtml .= '</tr></thead><tbody>';
            foreach ($mcQuestions as $i => $q) {
                $bg = $i % 2 === 0 ? '#f8fafc' : '#ffffff';
                $correctText = $q['options'][$q['correct']] ?? '';
                $mcHtml .= '<tr style="background:' . $bg . ';">';
                $mcHtml .= '<td style="padding:10px;border:1px solid #eee;text-align:center;font-weight:bold;">' . ($i + 1) . '</td>';
                $mcHtml .= '<td style="padding:10px;border:1px solid #eee;">' . htmlspecialchars($q['question']) . '</td>';
                $mcHtml .= '<td style="padding:10px;border:1px solid #eee;color:#16a34a;font-weight:bold;">' . htmlspecialchars($correctText) . '</td>';
                $mcHtml .= '</tr>';
            }
            $mcHtml .= '</tbody></table>';
        }
        
        $tfHtml = '';
        if (!empty($tfQuestions)) {
            $tfHtml = '<h2 style="color:#059669;border-bottom:2px solid #059669;padding-bottom:8px;margin-top:30px;">' . $texts['tf'] . '</h2>';
            $tfHtml .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            $tfHtml .= '<thead><tr style="background:#059669;color:#fff;">';
            $tfHtml .= '<th style="padding:10px;border:1px solid #ddd;width:40px;">#</th>';
            $tfHtml .= '<th style="padding:10px;border:1px solid #ddd;">' . $texts['question'] . '</th>';
            $tfHtml .= '<th style="padding:10px;border:1px solid #ddd;width:120px;">' . $texts['correct_answer'] . '</th>';
            $tfHtml .= '</tr></thead><tbody>';
            foreach ($tfQuestions as $i => $q) {
                $bg = $i % 2 === 0 ? '#f0fdf4' : '#ffffff';
                $correctText = $q['correct'] ? $texts['true'] : $texts['false'];
                $tfHtml .= '<tr style="background:' . $bg . ';">';
                $tfHtml .= '<td style="padding:10px;border:1px solid #eee;text-align:center;font-weight:bold;">' . ($i + 1) . '</td>';
                $tfHtml .= '<td style="padding:10px;border:1px solid #eee;">' . htmlspecialchars($q['question']) . '</td>';
                $tfHtml .= '<td style="padding:10px;border:1px solid #eee;text-align:center;font-weight:bold;color:' . ($q['correct'] ? '#16a34a' : '#dc2626') . ';">' . $correctText . '</td>';
                $tfHtml .= '</tr>';
            }
            $tfHtml .= '</tbody></table>';
        }
        
        $essayHtml = '';
        if (!empty($essayQuestions)) {
            $essayHtml = '<h2 style="color:#7c3aed;border-bottom:2px solid #7c3aed;padding-bottom:8px;margin-top:30px;">' . $texts['essay'] . '</h2>';
            foreach ($essayQuestions as $i => $q) {
                $essayHtml .= '<div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:10px;padding:15px 20px;margin-bottom:15px;">';
                $essayHtml .= '<p style="font-weight:bold;color:#7c3aed;margin:0 0 10px;">' . ($i + 1) . '. ' . htmlspecialchars($q['question']) . '</p>';
                if (!empty($q['model_answer'])) {
                    $essayHtml .= '<div style="background:#fff;border-right:4px solid #7c3aed;padding:10px 15px;border-radius:5px;">';
                    $essayHtml .= '<strong style="color:#7c3aed;">' . $texts['model_answer'] . ':</strong><br>';
                    $essayHtml .= '<span>' . htmlspecialchars($q['model_answer']) . '</span>';
                    $essayHtml .= '</div>';
                }
                if (!empty($q['difficulty'])) {
                    $essayHtml .= '<p style="margin:8px 0 0;font-size:13px;color:#888;">' . $texts['difficulty'] . ': ' . htmlspecialchars($q['difficulty']) . '</p>';
                }
                $essayHtml .= '</div>';
            }
        }
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="{$this->language}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$texts['answer_key']} - {$texts['model']} {$modelLetter}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            direction: {$dir};
            padding: 20px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #1e3a5f, #2563eb);
            color: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            text-align: center;
        }
        .header h1 { font-size: 24px; margin-bottom: 8px; }
        .header .subtitle { font-size: 16px; opacity: 0.9; }
        .model-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 5px 20px;
            border-radius: 20px;
            font-size: 18px;
            font-weight: bold;
            margin-top: 10px;
        }
        .content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .print-btn {
            display: inline-block;
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            margin: 15px auto;
        }
        .print-btn:hover { background: #1d4ed8; }
        .no-print { text-align: center; margin-bottom: 20px; }
        @media print {
            body { background: white; padding: 10px; }
            .no-print { display: none; }
            .header { border-radius: 0; }
            .content { box-shadow: none; border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$title}</h1>
            <div class="subtitle">{$texts['answer_key']}</div>
            <div class="model-badge">{$texts['model']} {$modelLetter}</div>
        </div>
        
        <div class="no-print">
            <button class="print-btn" onclick="window.print()">🖨️ {$texts['print']}</button>
        </div>
        
        <div class="content">
            {$mcHtml}
            {$tfHtml}
            {$essayHtml}
        </div>
    </div>
</body>
</html>
HTML;
        
        return $html;
    }
    
    /**
     * توليد ملف HTML واحد يحتوي على جميع نماذج الإجابة مع أزرار تبديل
     * @param string $title عنوان الامتحان
     * @return string|null HTML جميع نماذج الإجابة
     */
    public function generateAllAnswerKeysHTML($title = 'نموذج إجابة') {
        if (empty($this->questions) && empty($this->preparedModels)) {
            $this->lastError = 'لا توجد أسئلة';
            return null;
        }

        $examQuestions = [];
        if (empty($this->preparedModels)) {
            $examQuestions = $this->prepareExamQuestions();
            if (empty($examQuestions)) {
                $this->lastError = 'لا توجد أسئلة كافية';
                return null;
            }
        }
        
        $isArabic = $this->language === 'ar';
        $isFrench = $this->language === 'fr';
        $dir = $isArabic ? 'rtl' : 'ltr';
        
        $texts = $isArabic ? [
            'answer_key' => 'نماذج الإجابة',
            'model' => 'النموذج',
            'mc' => 'أسئلة الاختيار من متعدد',
            'tf' => 'أسئلة صح أو خطأ',
            'essay' => 'أسئلة مقالية',
            'question' => 'السؤال',
            'correct_answer' => 'الإجابة الصحيحة',
            'model_answer' => 'الإجابة النموذجية',
            'true' => 'صح',
            'false' => 'خطأ',
            'difficulty' => 'المستوى',
            'print' => 'طباعة',
            'all_keys' => 'جميع نماذج الإجابة'
        ] : ($isFrench ? [
            'answer_key' => 'Corrigés',
            'model' => 'Modèle',
            'mc' => 'Questions à choix multiples',
            'tf' => 'Questions vrai ou faux',
            'essay' => 'Questions rédactionnelles',
            'question' => 'Question',
            'correct_answer' => 'Bonne réponse',
            'model_answer' => 'Réponse modèle',
            'true' => 'Vrai',
            'false' => 'Faux',
            'difficulty' => 'Niveau',
            'print' => 'Imprimer',
            'all_keys' => 'Tous les corrigés'
        ] : [
            'answer_key' => 'Answer Keys',
            'model' => 'Model',
            'mc' => 'Multiple Choice Questions',
            'tf' => 'True or False Questions',
            'essay' => 'Essay Questions',
            'question' => 'Question',
            'correct_answer' => 'Correct Answer',
            'model_answer' => 'Model Answer',
            'true' => 'True',
            'false' => 'False',
            'difficulty' => 'Difficulty',
            'print' => 'Print',
            'all_keys' => 'All Answer Keys'
        ]);
        
        // توليد محتوى كل نموذج
        $modelLetters = ['A', 'B', 'C', 'D'];
        $modelsContent = [];
        $modelBtnColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444'];
        
        for ($m = 0; $m < $this->modelsCount && $m < 4; $m++) {
            $letter = $modelLetters[$m];
            if (!empty($this->preparedModels[$letter])) {
                $model = $this->preparedModels[$letter];
            } elseif ($this->modelType === 'different' && $m > 0) {
                $model = $this->prepareExamQuestionsForDifferentModel($m);
            } else {
                $model = $this->createModel($examQuestions, $letter);
            }
            
            $mcQuestions = array_values(array_filter($model, fn($q) => $q['type'] === 'multiple_choice'));
            $tfQuestions = array_values(array_filter($model, fn($q) => $q['type'] === 'true_false'));
            $essayQuestions = array_values(array_filter($model, fn($q) => $q['type'] === 'essay'));
            
            $content = '';
            
            if (!empty($mcQuestions)) {
                $content .= '<h2 style="color:#2563eb;border-bottom:2px solid #2563eb;padding-bottom:8px;margin-top:30px;">' . $texts['mc'] . '</h2>';
                $content .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
                $content .= '<thead><tr style="background:#2563eb;color:#fff;">';
                $content .= '<th style="padding:10px;border:1px solid #ddd;width:40px;">#</th>';
                $content .= '<th style="padding:10px;border:1px solid #ddd;">' . $texts['question'] . '</th>';
                $content .= '<th style="padding:10px;border:1px solid #ddd;width:200px;">' . $texts['correct_answer'] . '</th>';
                $content .= '</tr></thead><tbody>';
                foreach ($mcQuestions as $i => $q) {
                    $bg = $i % 2 === 0 ? '#f8fafc' : '#ffffff';
                    $correctText = $q['options'][$q['correct']] ?? '';
                    $content .= '<tr style="background:' . $bg . ';">';
                    $content .= '<td style="padding:10px;border:1px solid #eee;text-align:center;font-weight:bold;">' . ($i + 1) . '</td>';
                    $content .= '<td style="padding:10px;border:1px solid #eee;">' . htmlspecialchars($q['question']) . '</td>';
                    $content .= '<td style="padding:10px;border:1px solid #eee;color:#16a34a;font-weight:bold;">' . htmlspecialchars($correctText) . '</td>';
                    $content .= '</tr>';
                }
                $content .= '</tbody></table>';
            }
            
            if (!empty($tfQuestions)) {
                $content .= '<h2 style="color:#059669;border-bottom:2px solid #059669;padding-bottom:8px;margin-top:30px;">' . $texts['tf'] . '</h2>';
                $content .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
                $content .= '<thead><tr style="background:#059669;color:#fff;">';
                $content .= '<th style="padding:10px;border:1px solid #ddd;width:40px;">#</th>';
                $content .= '<th style="padding:10px;border:1px solid #ddd;">' . $texts['question'] . '</th>';
                $content .= '<th style="padding:10px;border:1px solid #ddd;width:120px;">' . $texts['correct_answer'] . '</th>';
                $content .= '</tr></thead><tbody>';
                foreach ($tfQuestions as $i => $q) {
                    $bg = $i % 2 === 0 ? '#f0fdf4' : '#ffffff';
                    $correctText = $q['correct'] ? $texts['true'] : $texts['false'];
                    $content .= '<tr style="background:' . $bg . ';">';
                    $content .= '<td style="padding:10px;border:1px solid #eee;text-align:center;font-weight:bold;">' . ($i + 1) . '</td>';
                    $content .= '<td style="padding:10px;border:1px solid #eee;">' . htmlspecialchars($q['question']) . '</td>';
                    $content .= '<td style="padding:10px;border:1px solid #eee;text-align:center;font-weight:bold;color:' . ($q['correct'] ? '#16a34a' : '#dc2626') . ';">' . $correctText . '</td>';
                    $content .= '</tr>';
                }
                $content .= '</tbody></table>';
            }
            
            if (!empty($essayQuestions)) {
                $content .= '<h2 style="color:#7c3aed;border-bottom:2px solid #7c3aed;padding-bottom:8px;margin-top:30px;">' . $texts['essay'] . '</h2>';
                foreach ($essayQuestions as $i => $q) {
                    $content .= '<div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:10px;padding:15px 20px;margin-bottom:15px;">';
                    $content .= '<p style="font-weight:bold;color:#7c3aed;margin:0 0 10px;">' . ($i + 1) . '. ' . htmlspecialchars($q['question']) . '</p>';
                    if (!empty($q['model_answer'])) {
                        $content .= '<div style="background:#fff;border-' . ($isArabic ? 'right' : 'left') . ':4px solid #7c3aed;padding:10px 15px;border-radius:5px;">';
                        $content .= '<strong style="color:#7c3aed;">' . $texts['model_answer'] . ':</strong><br>';
                        $content .= '<span>' . htmlspecialchars($q['model_answer']) . '</span>';
                        $content .= '</div>';
                    }
                    if (!empty($q['difficulty'])) {
                        $content .= '<p style="margin:8px 0 0;font-size:13px;color:#888;">' . $texts['difficulty'] . ': ' . htmlspecialchars($q['difficulty']) . '</p>';
                    }
                    $content .= '</div>';
                }
            }
            
            $modelsContent[$letter] = $content;
        }
        
        // بناء أزرار التبديل
        $buttonsHtml = '';
        foreach ($modelsContent as $idx => $letter) {
            // $idx here is actually the letter key
        }
        $buttonsHtml = '';
        $panelsHtml = '';
        $modelIndex = 0;
        foreach ($modelsContent as $letter => $content) {
            $isFirst = $modelIndex === 0;
            $color = $modelBtnColors[$modelIndex % 4];
            $activeClass = $isFirst ? 'active' : '';
            $displayStyle = $isFirst ? 'block' : 'none';
            
            $buttonsHtml .= '<button class="model-tab-btn ' . $activeClass . '" onclick="switchAnswerKeyModel(\'' . $letter . '\')" data-model="' . $letter . '" style="background: ' . ($isFirst ? $color : '#e2e8f0') . '; color: ' . ($isFirst ? '#fff' : '#475569') . '; border: none; padding: 12px 30px; border-radius: 10px; font-weight: 700; font-size: 1.1rem; cursor: pointer; transition: all 0.3s;">' . $texts['model'] . ' ' . $letter . '</button>';
            
            $panelsHtml .= '<div class="model-panel" id="panel-' . $letter . '" style="display: ' . $displayStyle . ';">' . $content . '</div>';
            $modelIndex++;
        }
        
        // بناء JSON للألوان
        $colorsJson = json_encode(array_combine(
            array_slice($modelLetters, 0, $this->modelsCount),
            array_slice($modelBtnColors, 0, $this->modelsCount)
        ));
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="{$this->language}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$texts['all_keys']} - {$title}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            direction: {$dir};
            padding: 20px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #1e3a5f, #2563eb);
            color: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            text-align: center;
        }
        .header h1 { font-size: 24px; margin-bottom: 8px; }
        .header .subtitle { font-size: 16px; opacity: 0.9; }
        .model-tabs {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin: 20px 0;
        }
        .model-tab-btn {
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .model-tab-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        .content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .print-btn {
            display: inline-block;
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            margin: 15px auto;
        }
        .print-btn:hover { background: #1d4ed8; }
        .no-print { text-align: center; margin-bottom: 20px; }
        @media print {
            body { background: white; padding: 10px; }
            .no-print { display: none; }
            .model-tabs { display: none; }
            .header { border-radius: 0; }
            .content { box-shadow: none; border-radius: 0; }
            .model-panel { display: block !important; page-break-before: always; }
            .model-panel:first-child { page-break-before: auto; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$title}</h1>
            <div class="subtitle">{$texts['answer_key']}</div>
        </div>
        
        <div class="no-print">
            <div class="model-tabs">
                {$buttonsHtml}
            </div>
            <button class="print-btn" onclick="window.print()">🖨️ {$texts['print']}</button>
        </div>
        
        <div class="content">
            {$panelsHtml}
        </div>
    </div>
    
    <script>
        const MODEL_COLORS = {$colorsJson};
        
        function switchAnswerKeyModel(modelLetter) {
            // إخفاء جميع اللوحات
            document.querySelectorAll('.model-panel').forEach(p => p.style.display = 'none');
            // إظهار اللوحة المطلوبة
            document.getElementById('panel-' + modelLetter).style.display = 'block';
            
            // تحديث أزرار التبديل
            document.querySelectorAll('.model-tab-btn').forEach(btn => {
                const btnModel = btn.getAttribute('data-model');
                if (btnModel === modelLetter) {
                    btn.style.background = MODEL_COLORS[modelLetter] || '#3b82f6';
                    btn.style.color = '#fff';
                    btn.classList.add('active');
                } else {
                    btn.style.background = '#e2e8f0';
                    btn.style.color = '#475569';
                    btn.classList.remove('active');
                }
            });
        }
    </script>
</body>
</html>
HTML;
        
        return $html;
    }
    
    /**
     * حفظ الامتحان في قاعدة البيانات
     */
    public function saveQuiz($db, $lessonId, $title = null) {
        if (empty($this->questions)) {
            $this->lastError = 'لا توجد أسئلة للحفظ';
            return false;
        }
        
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            // إنشاء النماذج
            $examQuestions = [];
            if (isset($this->questions['multiple_choice'])) {
                foreach (array_slice($this->questions['multiple_choice'], 0, 10) as $q) {
                    $examQuestions[] = [
                        'type' => 'multiple_choice',
                        'question' => $q['question'],
                        'options' => $q['options'],
                        'correct' => $q['correct_answer']
                    ];
                }
            }
            if (isset($this->questions['true_false'])) {
                foreach (array_slice($this->questions['true_false'], 0, 10) as $q) {
                    $examQuestions[] = [
                        'type' => 'true_false',
                        'question' => $q['statement'],
                        'correct' => $q['correct_answer'] ? 1 : 0
                    ];
                }
            }
            
            $stmt = $db->prepare("
                INSERT INTO ai_quizzes 
                (lesson_id, quiz_title, questions_json, total_questions, duration_minutes, passing_percentage)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $lessonId,
                $title ?: 'امتحان إلكتروني',
                json_encode($examQuestions, JSON_UNESCAPED_UNICODE),
                count($examQuestions),
                $this->duration,
                $this->passingPercentage
            ]);
            
            $quizId = (int) $db->lastInsertId();
            $encodedQuestions = json_encode($examQuestions, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'ai_quiz_saved',
                'ai_quiz',
                $quizId,
                (string) ($title ?: 'امتحان إلكتروني'),
                [
                    'lesson_id' => (int) $lessonId,
                    'question_count' => count($examQuestions),
                    'questions_sha256' => hash('sha256', $encodedQuestions),
                    'questions_bytes' => strlen($encodedQuestions),
                    'duration_minutes' => (int) $this->duration,
                    'passing_percentage' => (int) $this->passingPercentage,
                    'direct_undo' => false,
                    'reason' => 'generated_assessment_content',
                ]
            );
            if ($ownsTransaction) $db->commit();
            return $quizId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            $this->lastError = 'تعذر حفظ الامتحان بأمان';
            error_log('ExamGenerator::saveQuiz error: ' . $e->getMessage());
            return false;
        }
    }
}
