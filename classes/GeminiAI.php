<?php
/**
 * كلاس الاتصال بـ Google Gemini API
 * GeminiAI Class for Lesson Preparation System
 */

require_once __DIR__ . '/../config/ai_config.php';

class GeminiAI {
    private $apiKey;
    private $model;
    private $db;
    private $lastError;
    private $lastResponseTime;
    private $lastTokensUsed;
    private $fallbackKeys = [];
    private $currentKeyIndex = 0;
    private $allKeys = [];
    
    /**
     * Constructor
     */
    public function __construct($db = null) {
        $this->db = $db;
        $this->apiKey = getGeminiApiKey($db);
        $this->model = GEMINI_MODEL;
        $this->lastError = null;
        $this->lastResponseTime = 0;
        $this->lastTokensUsed = 0;
        
        // تحميل المفاتيح الاحتياطية
        $this->allKeys = [$this->apiKey];
        if (defined('GEMINI_API_KEYS_FALLBACK')) {
            $fallback = json_decode(GEMINI_API_KEYS_FALLBACK, true);
            if (is_array($fallback)) {
                $this->allKeys = array_merge($this->allKeys, $fallback);
            }
        }
    }
    
    /**
     * تعيين مفتاح API
     */
    public function setApiKey($apiKey) {
        $this->apiKey = $apiKey;
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
     * الحصول على وقت الاستجابة
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
     * التحقق من صحة مفتاح API
     */
    public function validateApiKey() {
        if (empty($this->apiKey)) {
            $this->lastError = 'مفتاح API غير موجود. يرجى إعداده في لوحة التحكم.';
            return false;
        }
        return true;
    }
    
    /**
     * إرسال طلب نصي إلى Gemini
     */
    public function generateContent($prompt, $options = []) {
        if (!$this->validateApiKey()) {
            return null;
        }

        // السماح بتجاوز النموذج لكل طلب (model tiering) دون تغيير النموذج الافتراضي للمزوّد.
        $effectiveModel = !empty($options['model']) ? $options['model'] : $this->model;

        $url = GEMINI_API_URL . $effectiveModel . ':generateContent?key=' . $this->apiKey;

        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? GEMINI_TEMPERATURE,
                'topP' => $options['topP'] ?? GEMINI_TOP_P,
                'topK' => $options['topK'] ?? GEMINI_TOP_K,
                'maxOutputTokens' => $options['maxTokens'] ?? GEMINI_MAX_TOKENS,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
            ]
        ];
        
        return $this->sendRequest($url, $data);
    }
    
    /**
     * إرسال طلب مع صورة إلى Gemini Vision
     */
    public function analyzeImage($prompt, $imagePath, $mimeType = null) {
        if (!$this->validateApiKey()) {
            return null;
        }
        
        if (!file_exists($imagePath)) {
            $this->lastError = 'ملف الصورة غير موجود';
            return null;
        }
        
        $fileSize = filesize($imagePath);
        if ($fileSize > GEMINI_MAX_IMAGE_SIZE) {
            $this->lastError = 'حجم الصورة أكبر من الحد المسموح (' . (GEMINI_MAX_IMAGE_SIZE / 1024 / 1024) . ' ميجابايت)';
            return null;
        }
        
        // تحديد نوع الملف
        if (!$mimeType) {
            $mimeType = mime_content_type($imagePath);
        }
        
        if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
            $this->lastError = 'نوع الصورة غير مدعوم';
            return null;
        }
        
        // تحويل الصورة إلى Base64
        $imageData = base64_encode(file_get_contents($imagePath));
        
        $url = GEMINI_API_URL . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $imageData
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => GEMINI_TEMPERATURE,
                'topP' => GEMINI_TOP_P,
                'topK' => GEMINI_TOP_K,
                'maxOutputTokens' => GEMINI_MAX_TOKENS,
            ]
        ];
        
        return $this->sendRequest($url, $data);
    }
    
    /**
     * تحليل ملف PDF باستخدام Gemini
     */
    public function analyzePDF($prompt, $pdfPath) {
        if (!$this->validateApiKey()) {
            return null;
        }
        
        if (!file_exists($pdfPath)) {
            $this->lastError = 'ملف PDF غير موجود';
            return null;
        }
        
        $fileSize = filesize($pdfPath);
        if ($fileSize > GEMINI_MAX_PDF_SIZE) {
            $this->lastError = 'حجم الملف أكبر من الحد المسموح (' . (GEMINI_MAX_PDF_SIZE / 1024 / 1024) . ' ميجابايت)';
            return null;
        }
        
        // تحويل PDF إلى Base64
        $pdfData = base64_encode(file_get_contents($pdfPath));
        
        $url = GEMINI_API_URL . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => 'application/pdf',
                                'data' => $pdfData
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => GEMINI_TEMPERATURE,
                'topP' => GEMINI_TOP_P,
                'topK' => GEMINI_TOP_K,
                'maxOutputTokens' => GEMINI_MAX_TOKENS,
            ]
        ];
        
        return $this->sendRequest($url, $data);
    }
    
    /**
     * إرسال الطلب إلى API مع آلية إعادة المحاولة
     * @param string $url رابط API
     * @param array $data البيانات المرسلة
     * @param int $maxRetries عدد محاولات إعادة الاتصال (افتراضي 3)
     */
    /**
     * التبديل إلى المفتاح الاحتياطي التالي
     * @return bool هل تم التبديل بنجاح
     */
    private function switchToNextKey() {
        $this->currentKeyIndex++;
        if ($this->currentKeyIndex < count($this->allKeys)) {
            $this->apiKey = $this->allKeys[$this->currentKeyIndex];
            return true;
        }
        return false;
    }
    
    private function sendRequest($url, $data, $maxRetries = 3) {
        $startTime = microtime(true);
        $retries = 0;
        $lastError = '';
        
        // إعادة تعيين فهرس المفتاح للبدء من الأول
        $this->currentKeyIndex = 0;
        $this->apiKey = $this->allKeys[0];
        // تحديث الرابط بالمفتاح الحالي
        $url = preg_replace('/key=[^&]+/', 'key=' . $this->apiKey, $url);
        
        while ($retries <= $maxRetries) {
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => GEMINI_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CONNECTTIMEOUT => 30,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            curl_close($ch);
            
            // إذا نجح الطلب أو كان خطأ غير قابل للاستعادة، توقف
            if ($httpCode === 200 || in_array($httpCode, [400, 401, 403])) {
                break;
            }
            
            // عند تجاوز حد الاستخدام (429) - جرّب المفتاح الاحتياطي أولاً
            if ($httpCode === 429 && $this->switchToNextKey()) {
                // تحديث الرابط بالمفتاح الجديد
                $url = preg_replace('/key=[^&]+/', 'key=' . $this->apiKey, $url);
                // لا نزيد عداد المحاولات - نعطي المفتاح الجديد فرصة كاملة
                sleep(1);
                continue;
            }
            
            // أخطاء قابلة لإعادة المحاولة: timeout, 429 (rate limit), 500, 503
            if ($curlError || in_array($httpCode, [0, 429, 500, 502, 503, 504])) {
                $retries++;
                $lastError = $curlError ?: "HTTP $httpCode";
                
                if ($retries <= $maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s
                    $waitTime = pow(2, $retries - 1);
                    sleep($waitTime);
                    continue;
                }
            }
            
            break;
        }
        
        $this->lastResponseTime = round((microtime(true) - $startTime) * 1000);
        
        // معالجة أخطاء cURL
        if ($curlError) {
            $retryInfo = $retries > 0 ? " (بعد $retries محاولات)" : '';
            $this->lastError = 'خطأ في الاتصال: ' . $curlError . $retryInfo;
            return null;
        }
        
        // معالجة رموز HTTP
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? 'خطأ غير معروف';
            
            $retryInfo = $retries > 0 ? " (بعد $retries محاولات)" : '';
            
            switch ($httpCode) {
                case 400:
                    $this->lastError = 'طلب غير صالح: ' . $errorMessage;
                    break;
                case 401:
                    $this->lastError = 'مفتاح API غير صالح';
                    break;
                case 403:
                    $this->lastError = 'ليس لديك صلاحية للوصول إلى هذه الخدمة';
                    break;
                case 429:
                    $this->lastError = 'تم تجاوز حد الاستخدام. يرجى المحاولة لاحقاً' . $retryInfo;
                    break;
                case 500:
                case 502:
                case 503:
                case 504:
                    $this->lastError = 'خطأ في خادم Gemini. يرجى المحاولة لاحقاً' . $retryInfo;
                    break;
                default:
                    $this->lastError = 'خطأ HTTP ' . $httpCode . ': ' . $errorMessage;
            }
            
            return null;
        }
        
        // تحليل الاستجابة
        $responseData = json_decode($response, true);
        
        if (!$responseData) {
            $this->lastError = 'فشل في تحليل استجابة API';
            return null;
        }
        
        // استخراج النص من الاستجابة
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            $text = $responseData['candidates'][0]['content']['parts'][0]['text'];
            
            // حساب الرموز المستخدمة (تقريبي)
            if (isset($responseData['usageMetadata'])) {
                $this->lastTokensUsed = 
                    ($responseData['usageMetadata']['promptTokenCount'] ?? 0) +
                    ($responseData['usageMetadata']['candidatesTokenCount'] ?? 0);
            }
            
            return $text;
        }
        
        // التحقق من حالات الحظر
        if (isset($responseData['candidates'][0]['finishReason'])) {
            $finishReason = $responseData['candidates'][0]['finishReason'];
            if ($finishReason === 'SAFETY') {
                $this->lastError = 'تم حظر المحتوى لأسباب تتعلق بالسلامة';
            } else if ($finishReason === 'RECITATION') {
                $this->lastError = 'تم حظر المحتوى لأسباب تتعلق بحقوق النشر';
            } else {
                $this->lastError = 'لم يتم إنشاء محتوى: ' . $finishReason;
            }
        } else {
            $this->lastError = 'استجابة غير متوقعة من API';
        }
        
        return null;
    }
    
    /**
     * إنشاء محتوى متعدد الأجزاء
     */
    public function generateMultipartContent($parts) {
        if (!$this->validateApiKey()) {
            return null;
        }
        
        $url = GEMINI_API_URL . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $contentParts = [];
        
        foreach ($parts as $part) {
            if ($part['type'] === 'text') {
                $contentParts[] = ['text' => $part['content']];
            } elseif ($part['type'] === 'image') {
                $imageData = base64_encode(file_get_contents($part['path']));
                $contentParts[] = [
                    'inline_data' => [
                        'mime_type' => $part['mimeType'] ?? mime_content_type($part['path']),
                        'data' => $imageData
                    ]
                ];
            } elseif ($part['type'] === 'pdf') {
                $pdfData = base64_encode(file_get_contents($part['path']));
                $contentParts[] = [
                    'inline_data' => [
                        'mime_type' => 'application/pdf',
                        'data' => $pdfData
                    ]
                ];
            }
        }
        
        $data = [
            'contents' => [
                ['parts' => $contentParts]
            ],
            'generationConfig' => [
                'temperature' => GEMINI_TEMPERATURE,
                'topP' => GEMINI_TOP_P,
                'topK' => GEMINI_TOP_K,
                'maxOutputTokens' => GEMINI_MAX_TOKENS,
            ]
        ];
        
        return $this->sendRequest($url, $data);
    }
    
    /**
     * توليد صورة تعليمية باستخدام Gemini Image Generation
     * @param string $prompt وصف الصورة المطلوبة
     * @param string $savePath مسار حفظ الصورة
     * @return array|null ['path' => مسار الملف, 'url' => رابط الصورة] أو null في حالة الفشل
     */
    public function generateImage($prompt, $savePath = null) {
        if (!$this->validateApiKey()) {
            return null;
        }
        
        $imageModel = defined('GEMINI_IMAGE_MODEL') ? GEMINI_IMAGE_MODEL : 'gemini-3.1-flash-image';
        $url = GEMINI_API_URL . $imageModel . ':generateContent?key=' . $this->apiKey;
        
        // بناء prompt تعليمي محسّن لتوليد صورة
        $enhancedPrompt = "Generate a clean, professional educational illustration image. "
            . "The image should be suitable for classroom use, with clear visuals, "
            . "bright colors, and simple design. No text in the image unless absolutely necessary. "
            . "Style: flat design, educational infographic style.\n\n"
            . "Description: " . $prompt;
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $enhancedPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
                'temperature' => 0.4,
            ]
        ];
        
        $startTime = microtime(true);
        
        // إرسال الطلب مع محاولة المفاتيح الاحتياطية
        $this->currentKeyIndex = 0;
        $this->apiKey = $this->allKeys[0];
        $url = preg_replace('/key=[^&]+/', 'key=' . $this->apiKey, $url);
        
        $maxRetries = 2;
        $retries = 0;
        $response = null;
        $httpCode = 0;
        
        while ($retries <= $maxRetries) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 180, // وقت أطول لتوليد الصور
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CONNECTTIMEOUT => 30,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 200) break;
            
            if ($httpCode === 429 && $this->switchToNextKey()) {
                $url = preg_replace('/key=[^&]+/', 'key=' . $this->apiKey, $url);
                sleep(1);
                continue;
            }
            
            $retries++;
            if ($retries <= $maxRetries) {
                sleep(pow(2, $retries - 1));
            }
        }
        
        $this->lastResponseTime = round((microtime(true) - $startTime) * 1000);
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $this->lastError = 'فشل في توليد الصورة: ' . ($errorData['error']['message'] ?? "HTTP $httpCode");
            return null;
        }
        
        $responseData = json_decode($response, true);
        
        if (!$responseData || !isset($responseData['candidates'][0]['content']['parts'])) {
            $this->lastError = 'استجابة غير متوقعة من API';
            return null;
        }
        
        // البحث عن بيانات الصورة في الاستجابة
        $imageData = null;
        $mimeType = 'image/png';
        $textResponse = '';
        
        foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['inlineData'])) {
                $imageData = $part['inlineData']['data'];
                $mimeType = $part['inlineData']['mimeType'] ?? 'image/png';
            } elseif (isset($part['inline_data'])) {
                $imageData = $part['inline_data']['data'];
                $mimeType = $part['inline_data']['mimeType'] ?? $part['inline_data']['mime_type'] ?? 'image/png';
            } elseif (isset($part['text'])) {
                $textResponse = $part['text'];
            }
        }
        
        // حساب الرموز المستخدمة
        if (isset($responseData['usageMetadata'])) {
            $this->lastTokensUsed = 
                ($responseData['usageMetadata']['promptTokenCount'] ?? 0) +
                ($responseData['usageMetadata']['candidatesTokenCount'] ?? 0);
        }
        
        if (!$imageData) {
            $this->lastError = 'لم يتم توليد صورة. ' . ($textResponse ? 'الرد: ' . mb_substr($textResponse, 0, 200) : 'لا يوجد رد');
            return null;
        }
        
        // فك تشفير البيانات
        $binaryData = base64_decode($imageData);
        if (!$binaryData) {
            $this->lastError = 'فشل في فك تشفير بيانات الصورة';
            return null;
        }
        
        // تحديد الامتداد
        $ext = 'png';
        if (strpos($mimeType, 'jpeg') !== false || strpos($mimeType, 'jpg') !== false) {
            $ext = 'jpg';
        } elseif (strpos($mimeType, 'webp') !== false) {
            $ext = 'webp';
        }
        
        // إعداد مسار الحفظ
        if (!$savePath) {
            $dir = defined('GENERATED_IMAGES_PATH') ? GENERATED_IMAGES_PATH : __DIR__ . '/../uploads/generated_images/';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = 'edu_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $savePath = $dir . $filename;
        }
        
        // حفظ الصورة
        if (file_put_contents($savePath, $binaryData) === false) {
            $this->lastError = 'فشل في حفظ الصورة';
            return null;
        }
        
        // حساب الرابط النسبي
        $relativePath = str_replace('\\', '/', $savePath);
        $uploadsPos = strpos($relativePath, 'uploads/');
        $relativeUrl = $uploadsPos !== false ? '../' . substr($relativePath, $uploadsPos) : $savePath;
        
        return [
            'path' => $savePath,
            'url' => $relativeUrl,
            'filename' => basename($savePath),
            'mime_type' => $mimeType,
            'size' => strlen($binaryData),
            'text_response' => $textResponse
        ];
    }
}
