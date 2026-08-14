<?php
/**
 * البحث عن الصور من الإنترنت - متعدد المصادر
 * Web Image Search using Multiple Free APIs (Pixabay, Unsplash, Pexels)
 * 
 * يبحث عن صور تعليمية مناسبة من الإنترنت لدعم المواد البصرية
 * يدعم مصادر متعددة مع آلية التبديل التلقائي (fallback)
 * 
 * الميزات:
 * - بحث من مصادر متعددة مع دمج النتائج للتنوع
 * - إعادة محاولة تلقائية بكلمات أبسط عند عدم وجود نتائج
 * - فلترة الصور بناءً على مطابقة الكلمات المفتاحية
 * - تخزين مؤقت (Cache) لتسريع البحث وتقليل استهلاك API
 * - قاموس ترجمة عربي-إنجليزي للمصطلحات التعليمية
 * - كشف تلقائي لأفضل فئة بحث
 */

// Polyfill for mbstring functions if not available
if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0) {
        return strpos($haystack, $needle, $offset);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($str) {
        return strlen($str);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($str, $start, $length = null) {
        return $length === null ? substr($str, $start) : substr($str, $start, $length);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($str) {
        return strtolower($str);
    }
}

class WebImageSearch {
    
    private $pixabayKey;
    private $unsplashKey;
    private $pexelsKey;
    private $perPage;
    private $lastError = '';
    private $availableSources = [];
    private $sourceIndex = 0;
    
    /** @var array تخزين مؤقت للنتائج خلال نفس الطلب */
    private $searchCache = [];
    
    /** @var string سياق المادة الدراسية */
    private $subjectContext = '';
    
    /**
     * المُنشئ - يكتشف المصادر المتاحة تلقائياً
     */
    public function __construct($apiKey = null, $perPage = null) {
        $this->pixabayKey = $apiKey ?: (defined('PIXABAY_API_KEY') ? PIXABAY_API_KEY : '');
        $this->unsplashKey = defined('UNSPLASH_ACCESS_KEY') ? UNSPLASH_ACCESS_KEY : '';
        $this->pexelsKey = defined('PEXELS_API_KEY') ? PEXELS_API_KEY : '';
        $this->perPage = $perPage ?: (defined('PIXABAY_IMAGES_PER_SEARCH') ? PIXABAY_IMAGES_PER_SEARCH : 3);
        
        // اكتشاف المصادر المتاحة
        if (!empty($this->pixabayKey)) $this->availableSources[] = 'pixabay';
        if (!empty($this->unsplashKey)) $this->availableSources[] = 'unsplash';
        if (!empty($this->pexelsKey))   $this->availableSources[] = 'pexels';
    }
    
    /**
     * تعيين سياق المادة الدراسية لتحسين البحث
     * @param string $subject اسم المادة (مثل: علوم، رياضيات، فيزياء...)
     */
    public function setSubjectContext($subject) {
        $this->subjectContext = trim($subject);
    }
    
    /**
     * البحث عن صور - يدمج نتائج من مصادر متعددة للتنوع
     * مع إعادة محاولة بكلمات أبسط عند عدم وجود نتائج
     */
    public function search($query, $category = '', $count = null, $lessonContext = '') {
        if (empty($this->availableSources)) {
            $this->lastError = 'لا توجد مفاتيح API متاحة لأي مصدر صور';
            return [];
        }
        
        if (empty(trim($query))) {
            $this->lastError = 'كلمة البحث فارغة';
            return [];
        }
        
        $count = $count ?: $this->perPage;
        $optimizedQuery = $this->optimizeSearchQuery($query, $lessonContext);
        
        // فحص التخزين المؤقت
        $cacheKey = md5($optimizedQuery . '|' . $category . '|' . $count);
        if (isset($this->searchCache[$cacheKey])) {
            return $this->searchCache[$cacheKey];
        }
        
        // البحث المدمج من مصادر متعددة (إذا طُلب أكثر من صورة واحدة)
        $results = [];
        if ($count > 1 && count($this->availableSources) > 1) {
            $results = $this->searchCombined($optimizedQuery, $category, $count);
        } else {
            $results = $this->searchWithFallback($optimizedQuery, $category, $count);
        }
        
        // إعادة المحاولة بكلمات أبسط إذا لم نجد نتائج
        if (empty($results)) {
            $simplifiedQuery = $this->simplifyQuery($optimizedQuery);
            if ($simplifiedQuery && $simplifiedQuery !== $optimizedQuery) {
                $results = $this->searchWithFallback($simplifiedQuery, $category, $count);
            }
        }
        
        // إعادة محاولة ثالثة: بأول كلمتين فقط + إزالة الفئة
        if (empty($results)) {
            $words = explode(' ', $optimizedQuery);
            if (count($words) > 2) {
                $shortQuery = implode(' ', array_slice($words, 0, 2));
                $results = $this->searchWithFallback($shortQuery, '', $count);
            }
        }
        
        // فلترة النتائج لتحسين الملاءمة
        if (!empty($results) && count($results) > 1) {
            $results = $this->filterByRelevance($results, $optimizedQuery);
        }
        
        // حفظ في التخزين المؤقت
        $this->searchCache[$cacheKey] = $results;
        
        return $results;
    }
    
    /**
     * البحث مع fallback (مصدر واحد تلو الآخر)
     */
    private function searchWithFallback($query, $category, $count) {
        $sourceOrder = $this->getSourceOrder();
        
        foreach ($sourceOrder as $source) {
            $results = $this->searchSource($source, $query, $category, $count);
            if (!empty($results)) {
                $this->sourceIndex++;
                return $results;
            }
        }
        
        return [];
    }
    
    /**
     * البحث المدمج - يجلب صوراً من كل المصادر المتاحة ويدمجها
     * للحصول على تنوع أكبر في النتائج
     */
    private function searchCombined($query, $category, $count) {
        $allResults = [];
        $perSource = max(1, ceil($count / count($this->availableSources)));
        
        foreach ($this->availableSources as $source) {
            $sourceResults = $this->searchSource($source, $query, $category, $perSource);
            if (!empty($sourceResults)) {
                $allResults = array_merge($allResults, $sourceResults);
            }
            usleep(100000); // 100ms بين المصادر لتجنب الحمل الزائد
        }
        
        // خلط النتائج للتنويع
        if (count($allResults) > $count) {
            // توزيع متساوي بين المصادر
            $distributed = $this->distributeResults($allResults, $count);
            return $distributed;
        }
        
        $this->sourceIndex++;
        return $allResults;
    }
    
    /**
     * توزيع النتائج بالتساوي بين المصادر
     */
    private function distributeResults($results, $count) {
        $bySource = [];
        foreach ($results as $result) {
            $source = $result['source'] ?? 'unknown';
            $bySource[$source][] = $result;
        }
        
        $distributed = [];
        $sourceCount = count($bySource);
        $perSource = max(1, ceil($count / $sourceCount));
        
        foreach ($bySource as $source => $items) {
            $distributed = array_merge($distributed, array_slice($items, 0, $perSource));
        }
        
        return array_slice($distributed, 0, $count);
    }
    
    /**
     * البحث في مصدر واحد محدد
     */
    private function searchSource($source, $query, $category, $count) {
        switch ($source) {
            case 'pixabay':
                return $this->searchPixabay($query, $category, $count);
            case 'unsplash':
                return $this->searchUnsplash($query, $count);
            case 'pexels':
                return $this->searchPexels($query, $count);
            default:
                return [];
        }
    }
    
    /**
     * تبسيط الاستعلام لإعادة المحاولة
     * يزيل الكلمات الأقل أهمية ويبقي الجوهرية
     */
    private function simplifyQuery($query) {
        $words = explode(' ', $query);
        
        // إزالة الكلمات الشائعة/العامة التي لا تفيد البحث عن الصور
        $stopWords = [
            'the', 'a', 'an', 'and', 'or', 'in', 'on', 'at', 'to', 'for', 'of', 'with',
            'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'education', 'learning', 'lesson', 'teaching', 'study', 'school',
            'concept', 'process', 'system', 'method', 'type', 'types',
            'diagram', 'illustration', 'educational', 'classroom',
            'math', 'biology', 'chemistry', 'physics', 'geography', 'history',
            'anatomy', 'geometry', // تبقى فقط إذا كانت الوحيدة
        ];
        
        $filtered = array_filter($words, function($word) use ($stopWords) {
            return !in_array(strtolower($word), $stopWords) && strlen($word) > 2;
        });
        
        // إذا بقيت كلمات كافية
        if (count($filtered) >= 2) {
            return implode(' ', $filtered);
        }
        
        // إذا أصبح قصيراً جداً، أرجع أول 3 كلمات من الأصل
        return implode(' ', array_slice($words, 0, 3));
    }
    
    /**
     * فلترة النتائج لتحسين الملاءمة
     * يقارن tags/alt الصورة بكلمات البحث ويرتب حسب التطابق
     */
    private function filterByRelevance($results, $query) {
        $queryWords = array_map('strtolower', explode(' ', $query));
        // إزالة الكلمات القصيرة جداً
        $queryWords = array_filter($queryWords, function($w) { return strlen($w) > 2; });
        
        if (empty($queryWords)) return $results;
        
        // حساب درجة الملاءمة لكل صورة
        foreach ($results as &$result) {
            $tags = strtolower($result['tags'] ?? '');
            $score = 0;
            
            foreach ($queryWords as $word) {
                if (strpos($tags, $word) !== false) {
                    $score += 2; // تطابق كامل
                } elseif (strlen($word) > 4) {
                    // تطابق جزئي (أول 4 أحرف)
                    $partial = substr($word, 0, 4);
                    if (strpos($tags, $partial) !== false) {
                        $score += 1;
                    }
                }
            }
            
            // مكافأة للصور ذات الأبعاد المناسبة (landscape)
            if (($result['width'] ?? 0) > ($result['height'] ?? 0)) {
                $score += 0.5;
            }
            
            $result['_relevance_score'] = $score;
        }
        unset($result);
        
        // ترتيب حسب درجة الملاءمة (الأعلى أولاً)
        usort($results, function($a, $b) {
            return ($b['_relevance_score'] ?? 0) <=> ($a['_relevance_score'] ?? 0);
        });
        
        // إزالة درجة الملاءمة من النتائج النهائية
        foreach ($results as &$result) {
            unset($result['_relevance_score']);
        }
        unset($result);
        
        return $results;
    }
    
    /**
     * تحسين كلمات البحث لضمان ملاءمة الصور للمحتوى التعليمي
     * @param string $query كلمات البحث
     * @param string $lessonContext سياق الدرس (عنوان أو كلمات مفتاحية إضافية)
     */
    private function optimizeSearchQuery($query, $lessonContext = '') {
        $query = trim($query);
        
        // إزالة الأحرف الخاصة مع الحفاظ على الحروف والأرقام
        $query = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $query);
        $query = preg_replace('/\s+/', ' ', trim($query));
        
        // قاموس المصطلحات التعليمية الشائعة (عربي → إنجليزي)
        $arabicToEnglish = $this->getArabicToEnglishDictionary();
        
        // تحقق هل الاستعلام عربي بالكامل
        $hasArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $query);
        
        if ($hasArabic) {
            // أولاً: استخراج الكلمات الإنجليزية الموجودة
            $englishWords = preg_replace('/[\x{0600}-\x{06FF}]+/u', '', $query);
            $englishWords = trim(preg_replace('/\s+/', ' ', $englishWords));
            
            // ثانياً: ترجمة المصطلحات العربية المعروفة
            $translatedTerms = [];
            foreach ($arabicToEnglish as $arabic => $english) {
                if (mb_strpos($query, $arabic) !== false) {
                    $translatedTerms[] = $english;
                }
            }
            
            if (!empty($translatedTerms)) {
                // دمج الترجمة مع أي كلمات إنجليزية موجودة
                $combined = implode(' ', $translatedTerms);
                if (!empty($englishWords) && strlen($englishWords) > 3) {
                    $combined = $englishWords . ' ' . $combined;
                }
                $query = $combined;
            } elseif (!empty($englishWords) && strlen($englishWords) > 3) {
                // لا توجد ترجمة، لكن توجد كلمات إنجليزية
                $query = $englishWords;
            }
            // إذا لم يوجد ترجمة ولا كلمات إنجليزية، نبقي النص كما هو
        }
        
        // إضافة سياق المادة الدراسية إذا كان متاحاً
        if (!empty($this->subjectContext)) {
            $subjectInEnglish = $this->translateSubject($this->subjectContext);
            if ($subjectInEnglish && mb_strlen($query) < 30) {
                // إضافة سياق المادة فقط إذا كان الاستعلام قصيراً
                $query = $query . ' ' . $subjectInEnglish;
            }
        }
        
        // إضافة سياق الدرس إذا كان الاستعلام قصيراً جداً
        if (!empty($lessonContext) && mb_strlen($query) < 15) {
            $lessonContext = $this->optimizeSearchQuery($lessonContext);
            if (!empty($lessonContext) && $lessonContext !== $query) {
                $query = $query . ' ' . $lessonContext;
            }
        }
        
        // إزالة الكلمات المكررة
        $words = explode(' ', $query);
        $words = array_unique(array_filter($words));
        $query = implode(' ', $words);
        
        // تحديد طول البحث
        if (mb_strlen($query) > 100) {
            $query = mb_substr($query, 0, 100);
        }
        
        return $query;
    }
    
    /**
     * ترجمة اسم المادة الدراسية إلى مصطلح إنجليزي مفيد للبحث
     */
    private function translateSubject($subject) {
        $subjectMap = [
            'علوم' => 'science',
            'العلوم' => 'science',
            'رياضيات' => 'mathematics',
            'الرياضيات' => 'mathematics',
            'فيزياء' => 'physics',
            'الفيزياء' => 'physics',
            'كيمياء' => 'chemistry',
            'الكيمياء' => 'chemistry',
            'أحياء' => 'biology',
            'الأحياء' => 'biology',
            'جغرافيا' => 'geography',
            'الجغرافيا' => 'geography',
            'تاريخ' => 'history',
            'التاريخ' => 'history',
            'لغة عربية' => '',
            'اللغة العربية' => '',
            'لغة إنجليزية' => '',
            'اللغة الإنجليزية' => '',
            'لغة فرنسية' => '',
            'تربية إسلامية' => 'islamic',
            'التربية الإسلامية' => 'islamic',
            'تربية فنية' => 'art',
            'حاسب آلي' => 'computer technology',
            'الحاسب الآلي' => 'computer technology',
            'تقنية' => 'technology',
            'اجتماعيات' => 'social studies',
            'الاجتماعيات' => 'social studies',
        ];
        
        foreach ($subjectMap as $ar => $en) {
            if (mb_strpos($subject, $ar) !== false) {
                return $en;
            }
        }
        
        // إذا كان الاسم بالإنجليزية أصلاً
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $subject)) {
            return strtolower($subject);
        }
        
        return '';
    }
    
    /**
     * قاموس المصطلحات التعليمية (عربي → إنجليزي)
     */
    private function getArabicToEnglishDictionary() {
        return [
            // العلوم
            'التمثيل الضوئي' => 'photosynthesis',
            'البناء الضوئي' => 'photosynthesis',
            'الخلية' => 'cell biology',
            'الخلايا' => 'cells biology',
            'الجهاز الهضمي' => 'digestive system',
            'الجهاز التنفسي' => 'respiratory system',
            'الجهاز العصبي' => 'nervous system',
            'الجهاز الدوري' => 'circulatory system',
            'القلب' => 'heart anatomy',
            'الدم' => 'blood cells',
            'العظام' => 'bones skeleton',
            'العضلات' => 'muscles anatomy',
            'النباتات' => 'plants botany',
            'الحيوانات' => 'animals wildlife',
            'الكائنات الحية' => 'living organisms',
            'البيئة' => 'environment ecosystem',
            'التلوث' => 'pollution environment',
            'المناخ' => 'climate weather',
            'الطقس' => 'weather forecast',
            'الماء' => 'water cycle',
            'دورة الماء' => 'water cycle nature',
            'التربة' => 'soil earth',
            'الصخور' => 'rocks geology',
            'البراكين' => 'volcanoes eruption',
            'الزلازل' => 'earthquakes seismic',
            'الفضاء' => 'space universe',
            'الكواكب' => 'planets solar system',
            'النجوم' => 'stars astronomy',
            'القمر' => 'moon lunar',
            'الشمس' => 'sun solar',
            'المجموعة الشمسية' => 'solar system planets',
            'الطاقة' => 'energy power',
            'الكهرباء' => 'electricity circuit',
            'المغناطيس' => 'magnet magnetic field',
            'الضوء' => 'light optics',
            'الصوت' => 'sound waves',
            'الحرارة' => 'heat temperature',
            'المادة' => 'matter states',
            'الذرة' => 'atom atomic structure',
            'العناصر' => 'chemical elements',
            'التفاعلات الكيميائية' => 'chemical reactions',
            'المحاليل' => 'solutions chemistry',
            'الأحماض' => 'acids chemistry',
            'القواعد' => 'bases alkali',
            'التكاثر' => 'reproduction biology',
            'الوراثة' => 'genetics heredity DNA',
            'التطور' => 'evolution natural selection',
            'الجاذبية' => 'gravity gravitational force',
            'الضغط' => 'pressure atmospheric',
            'الموجات' => 'waves frequency',
            'المرايا' => 'mirrors reflection light',
            'العدسات' => 'lenses refraction optics',
            'الكثافة' => 'density mass volume',
            'التبخر' => 'evaporation water vapor',
            'التكثف' => 'condensation water droplets',
            'الانصهار' => 'melting solid liquid',
            'التجمد' => 'freezing ice crystal',
            'الفلزات' => 'metals metallic elements',
            'اللافلزات' => 'nonmetals elements',
            'الجدول الدوري' => 'periodic table elements',
            // الرياضيات
            'الكسور' => 'fractions math',
            'الأعداد' => 'numbers mathematics',
            'الهندسة' => 'geometry shapes',
            'الجبر' => 'algebra equations',
            'القياس' => 'measurement units',
            'المساحة' => 'area geometry',
            'الحجم' => 'volume geometry 3d',
            'المحيط' => 'perimeter geometry',
            'الدائرة' => 'circle geometry',
            'المثلث' => 'triangle geometry',
            'المربع' => 'square rectangle',
            'الزوايا' => 'angles geometry',
            'النسبة' => 'ratio proportion',
            'النسبة المئوية' => 'percentage math',
            'الإحصاء' => 'statistics data',
            'الاحتمالات' => 'probability statistics',
            'المتتاليات' => 'sequences patterns',
            'المعادلات' => 'equations solving',
            'الدوال' => 'functions graph',
            'التناسب' => 'proportion ratio',
            // الجغرافيا
            'القارات' => 'continents world map',
            'المحيطات' => 'oceans world',
            'الأنهار' => 'rivers geography',
            'الجبال' => 'mountains landscape',
            'الصحراء' => 'desert landscape',
            'الغابات' => 'forests trees',
            'الخريطة' => 'map geography',
            'السكان' => 'population demographics',
            'الموارد الطبيعية' => 'natural resources',
            // التاريخ
            'الحضارات' => 'ancient civilizations',
            'التاريخ الإسلامي' => 'islamic history civilization',
            'الحضارة المصرية' => 'ancient egypt pyramids',
            'الحضارة الرومانية' => 'roman civilization ancient',
            'الحضارة اليونانية' => 'greek civilization ancient',
            // اللغة العربية
            'النحو' => 'arabic grammar',
            'الصرف' => 'arabic morphology',
            'القراءة' => 'reading literacy',
            'الكتابة' => 'writing handwriting',
            'الإملاء' => 'spelling writing',
            // عام
            'التعليم' => 'education learning',
            'المدرسة' => 'school classroom',
            'الدرس' => 'lesson education',
            'التجربة' => 'experiment science',
            'المختبر' => 'laboratory science',
        ];
    }
    
    /**
     * الحصول على ترتيب المصادر مع التناوب
     */
    private function getSourceOrder() {
        $count = count($this->availableSources);
        if ($count === 0) return [];
        
        $order = [];
        for ($i = 0; $i < $count; $i++) {
            $order[] = $this->availableSources[($this->sourceIndex + $i) % $count];
        }
        return $order;
    }
    
    /**
     * البحث في Pixabay
     */
    private function searchPixabay($query, $category = '', $count = 3) {
        $params = [
            'key' => $this->pixabayKey,
            'q' => $query,
            'image_type' => 'photo',
            'per_page' => min($count * 3, 20),
            'safesearch' => 'true',
            'order' => 'popular',
            'lang' => 'en',
            'min_width' => 400,
            'min_height' => 300
        ];
        
        if (!empty($category)) {
            $validCategories = [
                'backgrounds', 'fashion', 'nature', 'science', 'education',
                'feelings', 'health', 'people', 'religion', 'places',
                'animals', 'industry', 'computer', 'food', 'sports',
                'transportation', 'travel', 'buildings', 'business', 'music'
            ];
            if (in_array($category, $validCategories)) {
                $params['category'] = $category;
            }
        }
        
        $url = 'https://pixabay.com/api/?' . http_build_query($params);
        $response = $this->httpRequest($url);
        
        if (!$response) return [];
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['hits'])) {
            if (is_string($response) && stripos($response, 'Invalid API key') !== false) {
                $this->lastError = 'مفتاح Pixabay API غير صالح';
                error_log("WebImageSearch: Invalid Pixabay API key");
            }
            return [];
        }
        
        return $this->formatPixabayResults($data['hits'], $count);
    }
    
    /**
     * البحث في Unsplash
     */
    private function searchUnsplash($query, $count = 3) {
        $params = [
            'query' => $query,
            'per_page' => min($count * 2, 15),
            'content_filter' => 'high',
            'orientation' => 'landscape'
        ];
        
        $url = 'https://api.unsplash.com/search/photos?' . http_build_query($params);
        $headers = [
            'Authorization: Client-ID ' . $this->unsplashKey
        ];
        
        $response = $this->httpRequest($url, $headers);
        if (!$response) return [];
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['results'])) {
            if (is_string($response) && stripos($response, 'Unauthorized') !== false) {
                $this->lastError = 'مفتاح Unsplash API غير صالح';
                error_log("WebImageSearch: Invalid Unsplash API key");
            }
            return [];
        }
        
        return $this->formatUnsplashResults($data['results'], $count);
    }
    
    /**
     * البحث في Pexels
     */
    private function searchPexels($query, $count = 3) {
        $params = [
            'query' => $query,
            'per_page' => min($count * 2, 15),
            'size' => 'medium',
            'orientation' => 'landscape'
        ];
        
        $url = 'https://api.pexels.com/v1/search?' . http_build_query($params);
        $headers = [
            'Authorization: ' . $this->pexelsKey
        ];
        
        $response = $this->httpRequest($url, $headers);
        if (!$response) return [];
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['photos'])) {
            if (is_string($response) && stripos($response, 'Unauthorized') !== false) {
                $this->lastError = 'مفتاح Pexels API غير صالح';
                error_log("WebImageSearch: Invalid Pexels API key");
            }
            return [];
        }
        
        return $this->formatPexelsResults($data['photos'], $count);
    }
    
    /**
     * البحث عن صور متعددة بمواضيع مختلفة
     */
    public function searchMultiple($queries) {
        $results = [];
        
        foreach ($queries as $key => $query) {
            $searchTerm = is_array($query) ? ($query['keywords'] ?? $query['query'] ?? '') : $query;
            $category = is_array($query) ? ($query['category'] ?? '') : '';
            
            if (!empty($searchTerm)) {
                $images = $this->search($searchTerm, $category, 2);
                if (!empty($images)) {
                    $results[$key] = $images;
                }
                usleep(200000);
            }
        }
        
        return $results;
    }
    
    /**
     * إثراء المواد البصرية بصور من الإنترنت
     * مع تحسين الملاءمة: يبحث بكلمات مفتاحية دقيقة مرتبطة بالمحتوى
     * ويضيف سياق الدرس لتحسين نتائج البحث
     */
    public function enrichVisualMaterials($visualMaterials, $lessonTitle = '', $language = 'ar') {
        if (empty($this->availableSources)) {
            return $visualMaterials;
        }
        
        // تحضير سياق الدرس من العنوان (للاستخدام كسياق إضافي)
        $lessonContext = $this->optimizeSearchQuery($lessonTitle);
        
        // تحديد الفئة المناسبة بناءً على محتوى الدرس
        $bestCategory = $this->detectBestCategory($lessonTitle, $visualMaterials);
        
        // إثراء البطاقات التعليمية
        if (isset($visualMaterials['flash_cards']) && is_array($visualMaterials['flash_cards'])) {
            foreach ($visualMaterials['flash_cards'] as $idx => &$card) {
                $searchTerms = !empty($card['search_keywords']) ? $card['search_keywords'] : ($card['term'] ?? '');
                if (!empty($searchTerms)) {
                    $images = $this->search($searchTerms, $bestCategory ?: 'education', 1, $lessonContext);
                    if (!empty($images)) {
                        $card['web_image'] = $images[0];
                    }
                    usleep(300000);
                }
            }
            unset($card);
        }
        
        // إثراء الصور التعليمية
        if (isset($visualMaterials['educational_images']) && is_array($visualMaterials['educational_images'])) {
            foreach ($visualMaterials['educational_images'] as $idx => &$img) {
                $searchTerms = !empty($img['search_keywords']) ? $img['search_keywords'] : ($img['title'] ?? '');
                if (!empty($searchTerms)) {
                    $images = $this->search($searchTerms, $bestCategory, 2, $lessonContext);
                    if (!empty($images)) {
                        $img['web_images'] = $images;
                    }
                    usleep(300000);
                }
            }
            unset($img);
        }
        
        // إثراء الصور التسلسلية
        if (isset($visualMaterials['sequential_images']) && is_array($visualMaterials['sequential_images'])) {
            foreach ($visualMaterials['sequential_images'] as $idx => &$seq) {
                $searchTerms = !empty($seq['search_keywords']) ? $seq['search_keywords'] : ($seq['title'] ?? '');
                if (!empty($searchTerms)) {
                    $images = $this->search($searchTerms, $bestCategory, 2, $lessonContext);
                    if (!empty($images)) {
                        $seq['web_images'] = $images;
                    }
                    usleep(300000);
                }
            }
            unset($seq);
        }
        
        // بحث عام بعنوان الدرس
        if (!empty($lessonTitle)) {
            $generalImages = $this->search($lessonTitle, $bestCategory ?: 'education', 3);
            if (!empty($generalImages)) {
                $visualMaterials['lesson_images'] = $generalImages;
            }
        }
        
        // إضافة معلومات المصادر المتاحة
        $visualMaterials['image_sources'] = $this->getAvailableSourceNames();
        
        return $visualMaterials;
    }
    
    /**
     * كشف أفضل فئة بحث بناءً على محتوى الدرس
     * (يُستخدم فقط مع Pixabay التي تدعم الفئات)
     */
    private function detectBestCategory($lessonTitle, $visualMaterials = []) {
        $text = mb_strtolower($lessonTitle);
        
        // جمع النصوص من المواد البصرية لتحليل أفضل
        if (isset($visualMaterials['flash_cards'])) {
            foreach ($visualMaterials['flash_cards'] as $card) {
                $text .= ' ' . mb_strtolower($card['term'] ?? '') . ' ' . mb_strtolower($card['search_keywords'] ?? '');
            }
        }
        
        // خرائط الفئات
        $categoryMap = [
            'science' => ['علوم', 'أحياء', 'كيمياء', 'فيزياء', 'تجربة', 'مختبر', 'خلية', 'ذرة', 'طاقة', 
                          'science', 'biology', 'chemistry', 'physics', 'cell', 'atom', 'energy', 'experiment',
                          'plant', 'animal', 'photosynthesis', 'molecule', 'organ', 'ecosystem'],
            'nature'  => ['بيئة', 'طبيعة', 'نبات', 'حيوان', 'غابة', 'بحر', 'محيط', 'جبل',
                          'nature', 'environment', 'forest', 'ocean', 'mountain', 'river', 'tree', 'flower',
                          'water', 'rain', 'cloud', 'earth', 'volcano', 'earthquake'],
            'health'  => ['صحة', 'جسم', 'غذاء', 'رياضة', 'قلب', 'دم', 'عظام',
                          'health', 'body', 'heart', 'nutrition', 'food', 'exercise', 'anatomy',
                          'digestive', 'respiratory', 'nervous', 'muscle', 'skeleton'],
            'computer'=> ['حاسب', 'تقنية', 'برمجة', 'إنترنت', 'حاسوب',
                          'computer', 'technology', 'programming', 'internet', 'digital', 'software', 'coding'],
            'transportation' => ['مواصلات', 'سيارة', 'طائرة', 'قطار', 'سفينة',
                                  'transport', 'car', 'airplane', 'train', 'ship', 'vehicle'],
            'buildings' => ['عمارة', 'مبنى', 'مسجد', 'كنيسة', 'قلعة', 'بناء',
                            'building', 'architecture', 'mosque', 'castle', 'construction', 'house'],
            'food'    => ['طعام', 'غذاء', 'فواكه', 'خضروات', 'طبخ',
                          'food', 'fruit', 'vegetable', 'cooking', 'nutrition'],
            'industry'=> ['صناعة', 'مصنع', 'إنتاج', 'آلة',
                          'industry', 'factory', 'machine', 'manufacturing'],
            'sports'  => ['رياضة', 'كرة', 'سباق', 'لعبة',
                          'sport', 'football', 'basketball', 'swimming', 'athletics'],
            'music'   => ['موسيقى', 'آلة موسيقية', 'غناء',
                          'music', 'instrument', 'singing'],
        ];
        
        $bestCategory = '';
        $maxMatches = 0;
        
        foreach ($categoryMap as $category => $keywords) {
            $matches = 0;
            foreach ($keywords as $keyword) {
                if (mb_strpos($text, $keyword) !== false) {
                    $matches++;
                }
            }
            if ($matches > $maxMatches) {
                $maxMatches = $matches;
                $bestCategory = $category;
            }
        }
        
        return $bestCategory ?: 'education';
    }
    
    /**
     * الحصول على أسماء المصادر المتاحة
     */
    public function getAvailableSourceNames() {
        $names = [];
        foreach ($this->availableSources as $src) {
            $names[] = ucfirst($src);
        }
        return $names;
    }
    
    // =========================================================================
    // دوال تنسيق النتائج لكل مصدر
    // =========================================================================
    
    private function formatPixabayResults($hits, $count) {
        $results = [];
        foreach ($hits as $hit) {
            if (count($results) >= $count) break;
            $results[] = [
                'id' => $hit['id'] ?? 0,
                'url' => $hit['webformatURL'] ?? '',
                'preview_url' => $hit['previewURL'] ?? '',
                'large_url' => $hit['largeImageURL'] ?? '',
                'width' => $hit['webformatWidth'] ?? 0,
                'height' => $hit['webformatHeight'] ?? 0,
                'tags' => $hit['tags'] ?? '',
                'source' => 'pixabay',
                'source_icon' => 'fas fa-image',
                'page_url' => $hit['pageURL'] ?? '',
                'user' => $hit['user'] ?? 'Pixabay'
            ];
        }
        return $results;
    }
    
    private function formatUnsplashResults($photos, $count) {
        $results = [];
        foreach ($photos as $photo) {
            if (count($results) >= $count) break;
            $results[] = [
                'id' => $photo['id'] ?? '',
                'url' => $photo['urls']['regular'] ?? $photo['urls']['small'] ?? '',
                'preview_url' => $photo['urls']['thumb'] ?? $photo['urls']['small'] ?? '',
                'large_url' => $photo['urls']['full'] ?? $photo['urls']['regular'] ?? '',
                'width' => $photo['width'] ?? 0,
                'height' => $photo['height'] ?? 0,
                'tags' => implode(', ', array_column($photo['tags'] ?? [], 'title')),
                'source' => 'unsplash',
                'source_icon' => 'fas fa-camera',
                'page_url' => $photo['links']['html'] ?? '',
                'user' => ($photo['user']['name'] ?? 'Unsplash') . ' / Unsplash'
            ];
        }
        return $results;
    }
    
    private function formatPexelsResults($photos, $count) {
        $results = [];
        foreach ($photos as $photo) {
            if (count($results) >= $count) break;
            $results[] = [
                'id' => $photo['id'] ?? 0,
                'url' => $photo['src']['medium'] ?? $photo['src']['original'] ?? '',
                'preview_url' => $photo['src']['small'] ?? $photo['src']['tiny'] ?? '',
                'large_url' => $photo['src']['large2x'] ?? $photo['src']['large'] ?? '',
                'width' => $photo['width'] ?? 0,
                'height' => $photo['height'] ?? 0,
                'tags' => $photo['alt'] ?? '',
                'source' => 'pexels',
                'source_icon' => 'fas fa-photo-video',
                'page_url' => $photo['url'] ?? '',
                'user' => ($photo['photographer'] ?? 'Pexels') . ' / Pexels'
            ];
        }
        return $results;
    }
    
    // =========================================================================
    // دوال HTTP
    // =========================================================================
    
    /**
     * طلب HTTP مع دعم الرؤوس المخصصة
     */
    private function httpRequest($url, $headers = []) {
        $defaultHeaders = ['Accept: application/json'];
        $allHeaders = array_merge($defaultHeaders, $headers);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => implode("\r\n", $allHeaders) . "\r\n"
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            return $response;
        }
        
        // محاولة بديلة باستخدام cURL
        return $this->curlRequest($url, $headers);
    }
    
    /**
     * محاولة بديلة باستخدام cURL
     */
    private function curlRequest($url, $headers = []) {
        if (!function_exists('curl_init')) {
            $this->lastError = 'cURL غير متاح';
            return null;
        }
        
        $defaultHeaders = ['Accept: application/json'];
        $allHeaders = array_merge($defaultHeaders, $headers);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->lastError = "HTTP Error: $httpCode" . ($error ? " - $error" : '');
            error_log("WebImageSearch cURL error: HTTP $httpCode - $error - URL: " . substr($url, 0, 100));
            return null;
        }
        
        return $response;
    }
    
    /**
     * الحصول على آخر خطأ
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * هل المكتبة متاحة (هل يوجد مصدر واحد على الأقل)
     */
    public function isAvailable() {
        return !empty($this->availableSources);
    }
}
