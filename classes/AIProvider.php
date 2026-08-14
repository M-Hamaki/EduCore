<?php
/**
 * مزوّد الذكاء الاصطناعي الموحد
 * AIProvider — Unified AI Provider (Gemini + Ollama)
 * 
 * واجهة موحدة تختار تلقائياً بين:
 * - Gemini (سحابي — أقوى، يحتاج إنترنت ومفتاح API)
 * - Ollama (محلي — مجاني، يعمل بدون إنترنت)
 * 
 * يدعم: الاختيار اليدوي، التبديل التلقائي (fallback)، تسجيل الاستخدام
 */

require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/GeminiAI.php';
require_once __DIR__ . '/OllamaAI.php';

class AIProvider {
    
    /** @var string المزوّد الحالي: 'gemini' أو 'ollama' */
    private $provider;
    
    /** @var GeminiAI|OllamaAI الكائن النشط */
    private $engine;
    
    /** @var PDO|null */
    private $db;
    
    /** @var bool هل يستخدم fallback تلقائي */
    private $autoFallback;
    
    /** @var string|null اسم المزوّد الأصلي قبل التبديل */
    private $originalProvider;
    
    /** @var string|null آخر خطأ من المزوّد */
    private $lastError;
    
    // ثوابت المزوّدين
    const PROVIDER_GEMINI = 'gemini';
    const PROVIDER_OLLAMA = 'ollama';
    const PROVIDER_AUTO = 'auto';
    
    /**
     * Constructor
     * 
     * @param PDO|null $db اتصال قاعدة البيانات
     * @param string|null $provider اختيار المزوّد ('gemini', 'ollama', 'auto', null=من الإعدادات)
     */
    public function __construct($db = null, $provider = null) {
        $this->db = $db;
        $this->lastError = null;
        $this->originalProvider = null;
        
        // تحديد المزوّد
        if ($provider === null) {
            $provider = $this->getConfiguredProvider();
        }
        
        $this->autoFallback = ($provider === self::PROVIDER_AUTO);
        
        if ($provider === self::PROVIDER_AUTO) {
            // Auto: اختر Gemini أولاً، ثم Ollama كاحتياطي
            $provider = self::PROVIDER_GEMINI;
        }
        
        $this->provider = $provider;
        $this->engine = $this->createEngine($provider);
    }
    
    /**
     * الحصول على المزوّد المُعد في النظام
     */
    private function getConfiguredProvider() {
        if ($this->db) {
            try {
                $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ai_provider'");
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result && !empty($result['setting_value'])) {
                    return $result['setting_value'];
                }
            } catch (PDOException $e) {
                // تجاهل
            }
        }
        
        if (defined('AI_DEFAULT_PROVIDER') && !empty(AI_DEFAULT_PROVIDER)) {
            return AI_DEFAULT_PROVIDER;
        }
        
        return self::PROVIDER_GEMINI;
    }
    
    /**
     * إنشاء كائن المحرك المناسب
     */
    private function createEngine($provider) {
        if ($provider === self::PROVIDER_OLLAMA) {
            return new OllamaAI($this->db);
        }
        return new GeminiAI($this->db);
    }
    
    /**
     * الحصول على اسم المزوّد الحالي
     */
    public function getProvider() {
        return $this->provider;
    }
    
    /**
     * هل تم التبديل إلى المزوّد الاحتياطي؟
     */
    public function didFallback() {
        return $this->originalProvider !== null;
    }
    
    /**
     * الحصول على آخر خطأ
     */
    public function getLastError() {
        return $this->lastError ?? $this->engine->getLastError();
    }
    
    /**
     * الحصول على وقت الاستجابة
     */
    public function getLastResponseTime() {
        return $this->engine->getLastResponseTime();
    }
    
    /**
     * الحصول على عدد الرموز المستخدمة
     */
    public function getLastTokensUsed() {
        return $this->engine->getLastTokensUsed();
    }
    
    /**
     * الحصول على كائن المحرك المباشر (للميزات الخاصة بكل مزوّد)
     * @return GeminiAI|OllamaAI
     */
    public function getEngine() {
        return $this->engine;
    }
    
    /**
     * التبديل إلى المزوّد الاحتياطي
     * @return bool هل نجح التبديل
     */
    private function switchToFallback() {
        if (!$this->autoFallback || $this->originalProvider !== null) {
            return false; // لا fallback أو سبق التبديل
        }
        
        $fallbackProvider = ($this->provider === self::PROVIDER_GEMINI) 
            ? self::PROVIDER_OLLAMA 
            : self::PROVIDER_GEMINI;
        
        // تحقق أن الاحتياطي متاح
        if ($fallbackProvider === self::PROVIDER_OLLAMA) {
            $testOllama = new OllamaAI($this->db);
            if (!$testOllama->isAvailable()) {
                return false;
            }
        }
        
        $this->originalProvider = $this->provider;
        $this->provider = $fallbackProvider;
        $this->engine = $this->createEngine($fallbackProvider);
        
        return true;
    }
    
    /**
     * توليد محتوى نصي
     * يدعم fallback تلقائي عند الفشل
     *
     * @param string $prompt النص المطلوب
     * @param array $options خيارات (temperature, maxTokens, system, topP, topK, model)
     *                      ملاحظة: 'model' تتجاوز النموذج الافتراضي لهذا الطلب فقط
     *                      (تُستخدم لتوزيع المهام حسب الثقل عبر getTierModel()).
     * @return string|null
     */
    public function generateContent($prompt, $options = []) {
        $result = $this->engine->generateContent($prompt, $options);

        // Fallback تلقائي
        if ($result === null && $this->switchToFallback()) {
            $this->lastError = null;
            $result = $this->engine->generateContent($prompt, $options);
        }

        return $result;
    }
    
    /**
     * تحليل صورة
     */
    public function analyzeImage($prompt, $imagePath, $mimeType = null) {
        $result = $this->engine->analyzeImage($prompt, $imagePath, $mimeType);
        
        if ($result === null && $this->switchToFallback()) {
            $this->lastError = null;
            $result = $this->engine->analyzeImage($prompt, $imagePath, $mimeType);
        }
        
        return $result;
    }
    
    /**
     * تحليل PDF
     */
    public function analyzePDF($prompt, $pdfPath) {
        return $this->engine->analyzePDF($prompt, $pdfPath);
    }
    
    /**
     * توليد صورة
     */
    public function generateImage($prompt, $savePath = null) {
        // الصور فقط عبر Gemini
        if ($this->provider === self::PROVIDER_OLLAMA) {
            $gemini = new GeminiAI($this->db);
            return $gemini->generateImage($prompt, $savePath);
        }
        return $this->engine->generateImage($prompt, $savePath);
    }
    
    /**
     * محتوى متعدد الأجزاء
     */
    public function generateMultipartContent($parts) {
        $result = $this->engine->generateMultipartContent($parts);
        
        if ($result === null && $this->switchToFallback()) {
            $this->lastError = null;
            $result = $this->engine->generateMultipartContent($parts);
        }
        
        return $result;
    }
    
    /**
     * محادثة (Chat) — متاح فقط عبر Ollama
     * يتحول تلقائياً لـ Ollama عند استخدامه
     */
    public function chat($messages, $options = []) {
        if ($this->provider === self::PROVIDER_OLLAMA) {
            return $this->engine->chat($messages, $options);
        }
        
        // حول لـ Ollama للشات
        $ollama = new OllamaAI($this->db);
        if (!$ollama->isAvailable()) {
            $this->lastError = 'خدمة المحادثة تتطلب Ollama المحلي وهو غير متاح حالياً';
            return null;
        }
        return $ollama->chat($messages, $options);
    }
    
    // =========================================================
    // دوال مساعدة (Static) لسهولة الاستخدام
    // =========================================================
    
    /**
     * فحص حالة جميع المزوّدين
     * @param PDO|null $db
     * @return array
     */
    public static function checkStatus($db = null) {
        $status = [
            'gemini' => [
                'available' => false,
                'hasKey' => false,
                'model' => defined('GEMINI_MODEL') ? GEMINI_MODEL : 'N/A',
            ],
            'ollama' => [
                'available' => false,
                'models' => [],
                'model' => defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'gemma3:4b',
            ],
        ];
        
        // فحص Gemini
        $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        if ($db) {
            $geminiKey = getGeminiApiKey($db);
        }
        $status['gemini']['hasKey'] = !empty($geminiKey);
        $status['gemini']['available'] = !empty($geminiKey);
        
        // فحص Ollama
        $ollama = new OllamaAI($db);
        $status['ollama']['available'] = $ollama->isAvailable();
        if ($status['ollama']['available']) {
            $models = $ollama->listModels();
            $status['ollama']['models'] = array_map(function($m) {
                return [
                    'name' => $m['name'] ?? '',
                    'size' => isset($m['size']) ? round($m['size'] / 1073741824, 1) . ' GB' : 'N/A',
                    'modified' => $m['modified_at'] ?? '',
                ];
            }, $models);
        }
        
        return $status;
    }
    
    /**
     * اختبار سريع للمزوّد
     * @param PDO|null $db
     * @param string $provider
     * @return array ['success' => bool, 'response' => string, 'time_ms' => int, 'error' => string]
     */
    public static function testProvider($db, $provider) {
        $ai = new self($db, $provider);
        $testPrompt = "أجب في جملة واحدة فقط: ما هي عاصمة مصر؟";
        
        $startTime = microtime(true);
        $response = $ai->generateContent($testPrompt, ['maxTokens' => 100, 'temperature' => 0.1]);
        $timeMs = round((microtime(true) - $startTime) * 1000);
        
        return [
            'success' => $response !== null,
            'response' => $response ?? '',
            'time_ms' => $timeMs,
            'tokens' => $ai->getLastTokensUsed(),
            'error' => $response === null ? $ai->getLastError() : '',
            'provider' => $ai->getProvider(),
            'did_fallback' => $ai->didFallback(),
        ];
    }
}
