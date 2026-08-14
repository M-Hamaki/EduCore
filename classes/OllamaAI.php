<?php
/**
 * كلاس الاتصال بـ Ollama API (نماذج AI المحلية)
 * OllamaAI Class — Local AI Provider via Ollama
 * 
 * يوفر نفس الواجهة الأساسية لـ GeminiAI للتوافق مع النظام
 * يعمل بدون إنترنت وبدون تكلفة API
 */

class OllamaAI {
    private $baseUrl;
    private $model;
    private $db;
    private $lastError;
    private $lastResponseTime;
    private $lastTokensUsed;
    private $timeout;
    
    /**
     * Constructor
     * @param PDO|null $db اتصال قاعدة البيانات
     * @param string|null $model اسم النموذج (افتراضي من الإعدادات)
     */
    public function __construct($db = null, $model = null) {
        $this->db = $db;
        $this->baseUrl = $this->getBaseUrl();
        $this->model = $model ?? $this->getDefaultModel();
        $this->timeout = defined('OLLAMA_TIMEOUT') ? OLLAMA_TIMEOUT : 120;
        $this->lastError = null;
        $this->lastResponseTime = 0;
        $this->lastTokensUsed = 0;
    }
    
    /**
     * الحصول على رابط API الأساسي
     */
    private function getBaseUrl() {
        if (defined('OLLAMA_BASE_URL') && !empty(OLLAMA_BASE_URL)) {
            return rtrim(OLLAMA_BASE_URL, '/');
        }
        return 'http://localhost:11434';
    }
    
    /**
     * الحصول على النموذج الافتراضي
     */
    private function getDefaultModel() {
        // أولاً: من قاعدة البيانات
        if ($this->db) {
            try {
                $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ollama_model'");
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result && !empty($result['setting_value'])) {
                    return $result['setting_value'];
                }
            } catch (PDOException $e) {
                // تجاهل — استخدم الثابت
            }
        }
        // ثانياً: من الثوابت
        if (defined('OLLAMA_MODEL') && !empty(OLLAMA_MODEL)) {
            return OLLAMA_MODEL;
        }
        return 'gemma3:4b';
    }
    
    /**
     * تعيين النموذج
     */
    public function setModel($model) {
        $this->model = $model;
    }
    
    /**
     * الحصول على آخر خطأ
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * الحصول على وقت الاستجابة (بالمللي ثانية)
     */
    public function getLastResponseTime() {
        return $this->lastResponseTime;
    }
    
    /**
     * الحصول على عدد الرموز المستخدمة
     */
    public function getLastTokensUsed() {
        return $this->lastTokensUsed;
    }
    
    /**
     * التحقق من أن خدمة Ollama تعمل
     * @return bool
     */
    public function isAvailable() {
        $ch = curl_init($this->baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    }
    
    /**
     * الحصول على قائمة النماذج المثبتة
     * @return array
     */
    public function listModels() {
        $ch = curl_init($this->baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->lastError = 'فشل الاتصال بخدمة Ollama';
            return [];
        }
        
        $data = json_decode($response, true);
        return $data['models'] ?? [];
    }
    
    /**
     * التحقق من صحة الإعداد (بديل لـ validateApiKey في GeminiAI)
     */
    public function validateApiKey() {
        return $this->isAvailable();
    }
    
    /**
     * إرسال طلب نصي إلى Ollama
     * متوافق مع واجهة GeminiAI::generateContent()
     * 
     * @param string $prompt النص المطلوب
     * @param array $options خيارات إضافية (temperature, maxTokens, system)
     * @return string|null النص المولّد أو null
     */
    public function generateContent($prompt, $options = []) {
        $startTime = microtime(true);
        $this->lastError = null;
        
        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'top_p' => $options['topP'] ?? 0.95,
                'top_k' => $options['topK'] ?? 40,
            ]
        ];
        
        if (isset($options['maxTokens'])) {
            $data['options']['num_predict'] = $options['maxTokens'];
        }
        
        // System prompt اختياري
        if (isset($options['system'])) {
            $data['system'] = $options['system'];
        }
        
        $result = $this->sendRequest('/api/generate', $data);
        
        $this->lastResponseTime = round((microtime(true) - $startTime) * 1000);
        
        if ($result === null) {
            return null;
        }
        
        // حساب الرموز المستخدمة
        $this->lastTokensUsed = ($result['prompt_eval_count'] ?? 0) + ($result['eval_count'] ?? 0);
        
        return $result['response'] ?? null;
    }
    
    /**
     * محادثة (Chat) — يدعم سياق محادثة كامل
     * 
     * @param array $messages مصفوفة الرسائل [['role' => 'user|assistant|system', 'content' => '...']]
     * @param array $options خيارات إضافية
     * @return string|null النص المولّد
     */
    public function chat($messages, $options = []) {
        $startTime = microtime(true);
        $this->lastError = null;
        
        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'top_p' => $options['topP'] ?? 0.95,
                'top_k' => $options['topK'] ?? 40,
            ]
        ];
        
        if (isset($options['maxTokens'])) {
            $data['options']['num_predict'] = $options['maxTokens'];
        }
        
        $result = $this->sendRequest('/api/chat', $data);
        
        $this->lastResponseTime = round((microtime(true) - $startTime) * 1000);
        
        if ($result === null) {
            return null;
        }
        
        $this->lastTokensUsed = ($result['prompt_eval_count'] ?? 0) + ($result['eval_count'] ?? 0);
        
        return $result['message']['content'] ?? null;
    }
    
    /**
     * تحليل صورة مع نص (Vision — يتطلب نموذج يدعم الصور مثل gemma3)
     * متوافق مع واجهة GeminiAI::analyzeImage()
     * 
     * @param string $prompt النص
     * @param string $imagePath مسار الصورة
     * @param string|null $mimeType نوع الملف (لا يُستخدم في Ollama لكن للتوافق)
     * @return string|null
     */
    public function analyzeImage($prompt, $imagePath, $mimeType = null) {
        if (!file_exists($imagePath)) {
            $this->lastError = 'ملف الصورة غير موجود';
            return null;
        }
        
        $imageData = base64_encode(file_get_contents($imagePath));
        
        $startTime = microtime(true);
        $this->lastError = null;
        
        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'images' => [$imageData],
            'stream' => false,
            'options' => [
                'temperature' => 0.7,
            ]
        ];
        
        $result = $this->sendRequest('/api/generate', $data);
        
        $this->lastResponseTime = round((microtime(true) - $startTime) * 1000);
        
        if ($result === null) {
            return null;
        }
        
        $this->lastTokensUsed = ($result['prompt_eval_count'] ?? 0) + ($result['eval_count'] ?? 0);
        
        return $result['response'] ?? null;
    }
    
    /**
     * تحليل PDF — يستخرج النص أولاً ثم يرسله كنص
     * ملاحظة: Ollama لا يدعم PDF مباشرة، لذا نحول إلى نص
     */
    public function analyzePDF($prompt, $pdfPath) {
        $this->lastError = 'تحليل PDF غير مدعوم مباشرة في النموذج المحلي. يرجى استخدام Gemini لهذه الميزة.';
        return null;
    }
    
    /**
     * توليد صور — غير مدعوم في Ollama
     */
    public function generateImage($prompt, $savePath = null) {
        $this->lastError = 'توليد الصور غير مدعوم في النموذج المحلي. يرجى استخدام Gemini لهذه الميزة.';
        return null;
    }
    
    /**
     * إنشاء محتوى متعدد الأجزاء — نسخة مبسطة
     */
    public function generateMultipartContent($parts) {
        // استخراج النصوص فقط من الأجزاء
        $textParts = [];
        $hasImages = false;
        $imagePath = null;
        
        foreach ($parts as $part) {
            if ($part['type'] === 'text') {
                $textParts[] = $part['content'];
            } elseif ($part['type'] === 'image') {
                $hasImages = true;
                $imagePath = $part['path'];
            }
        }
        
        $prompt = implode("\n\n", $textParts);
        
        // إذا كان هناك صورة، استخدم analyzeImage
        if ($hasImages && $imagePath) {
            return $this->analyzeImage($prompt, $imagePath);
        }
        
        return $this->generateContent($prompt);
    }
    
    /**
     * إرسال الطلب إلى Ollama API
     * 
     * @param string $endpoint نقطة النهاية (مثل /api/generate)
     * @param array $data البيانات
     * @return array|null الاستجابة المحللة
     */
    private function sendRequest($endpoint, $data) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // خطأ اتصال
        if ($curlError) {
            if (strpos($curlError, 'Connection refused') !== false || strpos($curlError, 'couldn\'t connect') !== false) {
                $this->lastError = 'خدمة Ollama غير متاحة. تأكد من تشغيل Ollama على جهازك.';
            } else {
                $this->lastError = 'خطأ في الاتصال بـ Ollama: ' . $curlError;
            }
            return null;
        }
        
        // خطأ HTTP
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['error'] ?? "HTTP $httpCode";
            
            if (strpos($errorMsg, 'model') !== false && strpos($errorMsg, 'not found') !== false) {
                $this->lastError = 'النموذج "' . $this->model . '" غير مثبت. قم بتنزيله عبر: ollama pull ' . $this->model;
            } else {
                $this->lastError = 'خطأ Ollama: ' . $errorMsg;
            }
            return null;
        }
        
        // تحليل الاستجابة
        $result = json_decode($response, true);
        if (!$result) {
            $this->lastError = 'فشل في تحليل استجابة Ollama';
            return null;
        }
        
        return $result;
    }
    
    /**
     * الحصول على معلومات النموذج
     * @return array|null
     */
    public function getModelInfo() {
        $ch = curl_init($this->baseUrl . '/api/show');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['name' => $this->model]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * سجّل استخدام API (للتوافق مع النظام)
     */
    public function logUsage($teacherId, $lessonId, $requestType, $status = 'success', $errorMessage = null) {
        if (!$this->db) return;
        
        try {
            $stmt = $this->db->prepare("INSERT INTO ai_api_logs 
                (teacher_id, lesson_id, api_type, request_type, tokens_used, response_time_ms, status, error_message)
                VALUES (?, ?, 'ollama', ?, ?, ?, ?, ?)");
            $stmt->execute([
                $teacherId,
                $lessonId,
                $requestType,
                $this->lastTokensUsed,
                $this->lastResponseTime,
                $status,
                $errorMessage
            ]);
        } catch (PDOException $e) {
            error_log("OllamaAI log error: " . $e->getMessage());
        }
    }
}
