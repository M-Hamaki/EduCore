<?php
/**
 * سكربت اختبار دخان لمولّد الباوربوينت — يولّد ملف PPTX تجريبياً بمحتوى وهمي
 * للتحقق من صحة التوليد بصرياً وتقنياً قبل الإغلاق.
 *
 * الاستخدام:
 *   php tools/ppt_smoke_test.php [theme]
 *
 * حيث theme أحد: modern|colorful|formal|gradient|nature|tech|creative|minimal|islamic|scientific
 * (افتراضي: modern)
 *
 * الناتج: storage/exports/lessons/_smoke_test/smoke_<theme>_<timestamp>.pptx
 */

require_once __DIR__ . '/../vendor/autoload.php';

// الكلاس لا يستخدم namespace، لذا نحمّله يدوياً
require_once __DIR__ . '/../classes/LessonPowerPointGenerator.php';

$theme = $argv[1] ?? 'modern';
$validThemes = ['modern','colorful','formal','gradient','nature','tech','creative','minimal','islamic','scientific'];
if (!in_array($theme, $validThemes, true)) {
    fwrite(STDERR, "Invalid theme: $theme\nValid: " . implode(', ', $validThemes) . "\n");
    exit(1);
}

$lesson = [
    'title'    => 'اختبار بصري للمولّد — درس تجريبي',
    'subject'  => 'مادة تجريبية',
    'language' => 'ar',
    'slides'   => [
        [
            'type'   => 'objectives',
            'title'  => 'الأهداف التعليمية',
            'points' => [
                'بعد هذا الدرس سيكون الطالب قادراً على شرح المفاهيم الأساسية',
                'تحديد الفرق بين المفاهيم المتشابهة',
                'تطبيق ما تعلّمه على أمثلة عملية',
                '📚 مفاهيم أساسية:',
                'تحليل النتائج واستخلاص الاستنتاجات',
            ],
        ],
        [
            'type'   => 'content',
            'title'  => 'المحتوى الرئيسي للدرس',
            'points' => [
                'الفكرة الأولى: تقديم المفهوم الأساسي مع شرح مبسّط',
                'الفكرة الثانية: تطبيق المفهوم على مثال واقعي',
                'الفكرة الثالثة: ربط المفهوم بالخبرات السابقة للطالب',
                '💡 مثال تطبيقي:',
                'تطبيق إضافي على نفس المفهوم بسياق مختلف',
                'ملاحظة مهمة حول الاستثناءات والحالات الخاصة',
                'تلخيص سريع للأفكار المطروحة في هذه الشريحة',
            ],
        ],
        [
            'type'   => 'comparison',
            'title'  => 'مقارنة بين الطريقتين',
            'table'  => [
                'headers' => ['الوجه', 'الطريقة التقليدية', 'الطريقة الحديثة'],
                'rows'    => [
                    ['الأدوات', 'ورق وقلم', 'حاسوب وبرامج'],
                    ['السرعة', 'بطيئة', 'سريعة'],
                    ['الدقة', 'متوسطة', 'عالية'],
                    ['التكلفة', 'منخفضة', 'مرتفعة'],
                    ['المرونة', 'محدودة', 'واسعة'],
                ],
            ],
        ],
        [
            'type'   => 'chart',
            'title'  => 'توزيع الدرجات حسب الفئة',
            'chart'  => [
                'kind'       => 'bar',
                'categories' => ['ممتاز', 'جيد جداً', 'جيد', 'مقبول'],
                'series'     => [
                    ['name' => 'الفصل أ', 'values' => [12, 18, 9, 3]],
                    ['name' => 'الفصل ب', 'values' => [10, 15, 12, 5]],
                ],
            ],
        ],
        [
            'type'   => 'chart',
            'title'  => 'توزيع الأنشطة (دائري)',
            'chart'  => [
                'kind'       => 'pie',
                'categories' => ['شرح نظري', 'تطبيق عملي', 'مناقشة', 'تقييم'],
                'series'     => [
                    ['name' => 'الزمن', 'values' => [40, 30, 20, 10]],
                ],
            ],
        ],
        [
            'type'   => 'chart',
            'title'  => 'تطور الأداء عبر الأسابيع',
            'chart'  => [
                'kind'       => 'line',
                'categories' => ['الأسبوع 1', 'الأسبوع 2', 'الأسبوع 3', 'الأسبوع 4', 'الأسبوع 5'],
                'series'     => [
                    ['name' => 'المجموعة التجريبية', 'values' => [55, 62, 70, 78, 85]],
                    ['name' => 'المجموعة الضابطة', 'values' => [54, 58, 60, 63, 65]],
                ],
            ],
        ],
        [
            'type'   => 'questions',
            'title'  => 'أسئلة التقييم',
            'points' => [
                'س1: ما المفهوم الرئيسي الذي تعلّقته في هذا الدرس؟',
                'س2: هل يمكن تطبيق هذا المفهوم على موقف حياتي؟ اذكر مثالاً.',
                'س3: قارن بين المفهومين المطروحين من حيث أوجه التشابه والاختلاف.',
                'س4: طبّق ما تعلّمته على حل المسألة التالية.',
                'س5: حلّل النتائج واستخلص استنتاجاً مبرراً.',
            ],
        ],
    ],
];

$outputDir = __DIR__ . '/../storage/exports/lessons/_smoke_test';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create output directory: $outputDir\n");
    exit(1);
}

$outputPath = $outputDir . '/smoke_' . $theme . '_' . date('Ymd_His') . '.pptx';

echo "=== PPT Smoke Test ===\n";
echo "Theme:   $theme\n";
echo "Output:  $outputPath\n";
echo "Slides:  " . count($lesson['slides']) . " (+ cover + closing)\n\n";

try {
    $generator = new LessonPowerPointGenerator();
    $result = $generator->generateFromSlides($lesson, $outputPath, $theme);

    if (!is_file($result)) {
        fwrite(STDERR, "FAILED: output file not created\n");
        exit(2);
    }

    $size = filesize($result);
    if ($size < 1000) {
        fwrite(STDERR, "FAILED: output file too small ($size bytes)\n");
        exit(3);
    }

    echo "SUCCESS: generated " . basename($result) . " ($size bytes)\n";

    // التحقق من قابلية القراءة عبر إعادة تحميل الملف
    try {
        $reloaded = \PhpOffice\PhpPresentation\IOFactory::load($result);
        $slideCount = count($reloaded->getAllSlides());
        echo "Verification: reloaded OK, $slideCount slides present\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "WARNING: reload failed: " . $e->getMessage() . "\n");
    }

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(4);
}
