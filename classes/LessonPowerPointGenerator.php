<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\RichText\Paragraph;
use PhpOffice\PhpPresentation\Shape\Table;
use PhpOffice\PhpPresentation\Shape\Chart;
use PhpOffice\PhpPresentation\Shape\Chart\Series;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar as ChartBar;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Pie as ChartPie;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Line as ChartLine;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;

class LessonPowerPointGenerator
{
    private array $themes = [
        'modern' => ['bg'=>'F5F7FB','primary'=>'145DA0','accent'=>'14B8A6','text'=>'172033','muted'=>'5B6475'],
        'colorful' => ['bg'=>'FFF9F0','primary'=>'E94F64','accent'=>'F6B73C','text'=>'263238','muted'=>'59636A'],
        'formal' => ['bg'=>'F7F7F5','primary'=>'243B53','accent'=>'C59D5F','text'=>'1F2933','muted'=>'616E7C'],
        'gradient' => ['bg'=>'F1F5F9','primary'=>'4F46E5','accent'=>'EC4899','text'=>'0F172A','muted'=>'64748B'],
        'nature' => ['bg'=>'F0FDF4','primary'=>'166534','accent'=>'84CC16','text'=>'14532D','muted'=>'3F6212'],
        'tech' => ['bg'=>'F8FAFC','primary'=>'0F172A','accent'=>'06B6D4','text'=>'1E293B','muted'=>'64748B'],
        'creative' => ['bg'=>'FDF4FF','primary'=>'701A75','accent'=>'F59E0B','text'=>'4A044E','muted'=>'B45309'],
        'minimal' => ['bg'=>'FFFFFF','primary'=>'18181B','accent'=>'71717A','text'=>'27272A','muted'=>'A1A1AA'],
        'islamic' => ['bg'=>'FDFBF7','primary'=>'065F46','accent'=>'D97706','text'=>'064E3B','muted'=>'B45309'],
        'scientific' => ['bg'=>'F8FAFC','primary'=>'1E3A8A','accent'=>'0284C7','text'=>'0F172A','muted'=>'475569'],
    ];

    /**
     * الخط الأساسي.
     * يُضبط ديناميكياً حسب اللغة عبر applyFontForLanguage() لأن Calibri
     * لا يدعم الحروف العربية (لا يغطيها ولا يشكّلها)؛ فالنص العربي يظهر
     * بخط بديل مكسور. لذلك نستخدم Arial للـ RTL (يدعم العربية ومتوفر عالمياً)
     * و Calibri للغات اللاتينية.
     */
    private string $fontName = 'Arial';

    /** اللغة الحالية */
    private string $language = 'ar';

    /**
     * ضبط الخط المناسب للغة الحالية.
     * يُستدعى بعد ضبط $this->language في كل مسار توليد.
     */
    private function applyFontForLanguage(): void
    {
        $isRTL = in_array($this->language, ['ar', 'he', 'fa', 'ur'], true);
        // Arial يدعم العربية واللاتينية ومتوفر على كل أجهزة PowerPoint تقريباً.
        // Calibri أداءً أفضل للاتينية لكنه لا يغطي العربية.
        $this->fontName = $isRTL ? 'Arial' : 'Calibri';
    }

    /** أيقونات افتراضية لكل نوع شريحة */
    private array $typeIcons = [
        'objectives'  => '🎯',
        'intro'       => '📖',
        'content'     => '📌',
        'definition'  => '📚',
        'example'     => '💡',
        'comparison'  => '⚖️',
        'steps'       => '🔢',
        'chart'       => '📊',
        'summary'     => '📋',
        'activity'    => '📝',
        'questions'   => '❓',
    ];

    /**
     * توليد باوربوينت من قائمة شرائح جاهزة (البنية الجديدة)
     * كل شريحة: ['type'=>..., 'title'=>..., 'points'=>[...]]
     */
    public function generateFromSlides(array $lesson, string $outputPath, string $theme = 'modern'): string
    {
        $palette  = $this->themes[$theme] ?? $this->themes['modern'];
        $this->language = $lesson['language'] ?? 'ar';
        $this->applyFontForLanguage();
        $isRTL    = in_array($this->language, ['ar', 'he', 'fa', 'ur']);
        $title    = trim((string)($lesson['title'] ?? 'عرض الدرس'));
        $slides   = $lesson['slides'] ?? [];

        // ── محاولة دمج قالب Canva (اختياري) ──────────────────────────
        $canvaTemplatePath = $lesson['canva_template_path'] ?? null;
        if ($canvaTemplatePath && is_file($canvaTemplatePath)) {
            $merged = $this->tryMergeWithCanvaTemplate(
                $title, $slides, $outputPath, $palette, $isRTL, $canvaTemplatePath,
                (string)($lesson['subject'] ?? '')
            );
            if ($merged !== null) {
                return $merged;
            }
            // فشل الدمج → متابعة التوليد الاعتيادي
            error_log('Canva merge failed — falling back to standard generation');
        }

        $presentation = new PhpPresentation();
        $presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $presentation->getDocumentProperties()
            ->setTitle($title)
            ->setCreator('EduCore');
        $presentation->removeSlideByIndex(0);

        // شريحة الغلاف
        $this->addCover($presentation, $title, (string)($lesson['subject'] ?? ''), $palette, $isRTL);

        // شرائح المحتوى
        $slideIndex = 0;
        foreach ($slides as $slideData) {
            // شرائح comparison/chart قد تستخدم table/chart فقط بدون points — نسمح بأحدهما
            $hasPoints = !empty($slideData['points']);
            $hasTable  = ($slideData['type'] ?? '') === 'comparison' && $this->isValidTable($slideData['table'] ?? null);
            $hasChart  = ($slideData['type'] ?? '') === 'chart' && $this->isValidChart($slideData['chart'] ?? null);
            if (empty($slideData['title']) || (!$hasPoints && !$hasTable && !$hasChart)) continue;

            $type    = $slideData['type'] ?? 'content';
            $icon    = $this->typeIcons[$type] ?? '📌';
            $bullets = array_values(array_filter((array)($slideData['points'] ?? []), fn($p) => trim((string)$p) !== ''));

            if ($type === 'questions') {
                // شريحة الأسئلة لا تُقسَّم — تُعرض كاملةً بتنسيق مرقَّم
                $this->addQuestionsSlide($presentation, $slideData['title'], $bullets, $palette, $slideIndex, $isRTL);
                $slideIndex++;
            } elseif ($type === 'comparison' && $hasTable) {
                // شريحة مقارنة بجدول حقيقي — لا تُقسَّم
                $this->addComparisonSlide($presentation, $slideData['title'], $slideData['table'], $palette, $slideIndex, $isRTL, $icon);
                $slideIndex++;
            } elseif ($type === 'chart' && $hasChart) {
                // شريحة رسم بياني حقيقي — لا تُقسَّم
                $this->addChartSlide($presentation, $slideData['title'], $slideData['chart'], $palette, $slideIndex, $isRTL, $icon);
                $slideIndex++;
            } else {
                // الشرائح الطويلة تُقسَّم تلقائياً إلى 7 نقاط لكل شريحة
                $chunks = array_chunk($bullets, 7);
                foreach ($chunks as $ci => $chunk) {
                    $chunkTitle = count($chunks) > 1
                        ? $slideData['title'] . ' (' . ($ci + 1) . '/' . count($chunks) . ')'
                        : $slideData['title'];
                    $this->addContentSlide($presentation, $chunkTitle, $chunk, $palette, $slideIndex, $isRTL, $icon);
                    $slideIndex++;
                }
            }
        }

        // شريحة الختام
        $this->addClosingSlide($presentation, $palette, $isRTL);

        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('تعذر إنشاء مجلد العروض');
        }
        IOFactory::createWriter($presentation, 'PowerPoint2007')->save($outputPath);
        if (!is_file($outputPath) || filesize($outputPath) < 1000) {
            throw new \RuntimeException('لم يتم إنشاء ملف PowerPoint بصورة صحيحة');
        }
        return $outputPath;
    }

    // =========================================================
    // Canva Template Merger (private)
    // =========================================================

    /**
     * يحاول دمج قالب Canva (PPTX) مع الشرائح المُولَّدة.
     *
     * الاستراتيجية:
     *   • يُبقي الشريحة الأولى (الغلاف) من قالب Canva
     *   • يُضيف جميع شرائح المحتوى المُولَّدة في المنتصف
     *   • يُبقي الشريحة الأخيرة (الختام) من قالب Canva (إذا كان > 1 شريحة)
     *   • يُحدّث نص العنوان في الغلاف (أفضل محاولة)
     *
     * @return string|null المسار عند النجاح، أو null عند الفشل
     */
    private function tryMergeWithCanvaTemplate(
        string $title,
        array  $slides,
        string $outputPath,
        array  $palette,
        bool   $isRTL,
        string $templatePath,
        string $subject = ''
    ): ?string {
        try {
            // 1. تحميل القالب
            $template    = IOFactory::load($templatePath);
            $allSlides   = $template->getAllSlides();
            $slideCount  = count($allSlides);

            if ($slideCount < 1) {
                error_log('Canva template has no slides');
                return null;
            }

            // 2. حفظ الشريحة الأخيرة (الختام) قبل الحذف
            $closingSlide = $slideCount > 1 ? $allSlides[$slideCount - 1] : null;

            // 3. حذف كل الشرائح باستثناء الأولى (الغلاف)
            for ($i = $slideCount - 1; $i >= 1; $i--) {
                $template->removeSlideByIndex($i);
            }

            // 4. محاولة تحديث العنوان في شريحة الغلاف (best-effort)
            $this->updateSlideTitleBestEffort($allSlides[0], $title);

            // 5. إضافة شرائح المحتوى المُولَّدة
            $slideIndex = 0;
            foreach ($slides as $slideData) {
                // شريحة comparison قد تستخدم table فقط بدون points — نسمح بأحدهما
                $hasPoints = !empty($slideData['points']);
                $hasTable  = ($slideData['type'] ?? '') === 'comparison' && $this->isValidTable($slideData['table'] ?? null);
                if (empty($slideData['title']) || (!$hasPoints && !$hasTable)) continue;

                $type    = $slideData['type'] ?? 'content';
                $icon    = $this->typeIcons[$type] ?? '📌';
                $bullets = array_values(
                    array_filter((array)($slideData['points'] ?? []), fn($p) => trim((string)$p) !== '')
                );

                if ($type === 'questions') {
                    $this->addQuestionsSlide($template, $slideData['title'], $bullets, $palette, $slideIndex, $isRTL);
                    $slideIndex++;
                } elseif ($type === 'comparison' && $hasTable) {
                    $this->addComparisonSlide($template, $slideData['title'], $slideData['table'], $palette, $slideIndex, $isRTL, $icon);
                    $slideIndex++;
                } else {
                    $chunks = array_chunk($bullets, 7);
                    foreach ($chunks as $ci => $chunk) {
                        $chunkTitle = count($chunks) > 1
                            ? $slideData['title'] . ' (' . ($ci + 1) . '/' . count($chunks) . ')'
                            : $slideData['title'];
                        $this->addContentSlide($template, $chunkTitle, $chunk, $palette, $slideIndex, $isRTL, $icon);
                        $slideIndex++;
                    }
                }
            }

            // 6. إعادة إضافة شريحة الختام من Canva
            if ($closingSlide !== null) {
                $template->addSlide($closingSlide);
            } else {
                $this->addClosingSlide($template, $palette, $isRTL);
            }

            // 7. الحفظ
            $directory = dirname($outputPath);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('تعذر إنشاء مجلد العروض');
            }

            IOFactory::createWriter($template, 'PowerPoint2007')->save($outputPath);

            if (!is_file($outputPath) || filesize($outputPath) < 1000) {
                throw new \RuntimeException('الملف الناتج صغير جداً');
            }

            return $outputPath;

        } catch (\Throwable $e) {
            error_log('Canva merge exception: ' . $e->getMessage());
            // حذف الملف الجزئي إن وُجد
            if (is_file($outputPath) && filesize($outputPath) < 1000) {
                @unlink($outputPath);
            }
            return null;
        }
    }

    /**
     * يحاول تحديث نص العنوان في شريحة Canva (best-effort).
     * يبحث عن أكبر text box وأطول نص ويستبدله.
     */
    private function updateSlideTitleBestEffort(\PhpOffice\PhpPresentation\Slide $slide, string $newTitle): void
    {
        $bestShape    = null;
        $bestLen      = 0;

        foreach ($slide->getShapeCollection() as $shape) {
            if (!($shape instanceof RichText)) continue;

            $shapeText = '';
            foreach ($shape->getParagraphs() as $para) {
                foreach ($para->getRichTextElements() as $el) {
                    $shapeText .= $el->getText();
                }
            }

            $len = mb_strlen(trim($shapeText));
            if ($len > $bestLen && $len < 300) { // تجاهل الفقرات الطويلة جداً (محتوى)
                $bestLen   = $len;
                $bestShape = $shape;
            }
        }

        if ($bestShape === null) return;

        // استبدال محتوى أول فقرة بالعنوان الجديد
        $paragraphs = $bestShape->getParagraphs();
        if (empty($paragraphs)) return;

        $firstPara = $paragraphs[0];
        $elements  = $firstPara->getRichTextElements();

        if (!empty($elements)) {
            // حفظ نمط الخط الأصلي
            try {
                $elements[0]->setText(mb_substr($newTitle, 0, 120));
                // حذف العناصر الإضافية
                for ($i = count($elements) - 1; $i >= 1; $i--) {
                    $firstPara->removeElement($elements[$i]);
                }
            } catch (\Throwable $e) {
                // تجاهل أخطاء التحديث
            }
        }

        // حذف الفقرات الإضافية
        try {
            for ($i = count($paragraphs) - 1; $i >= 1; $i--) {
                $bestShape->removeParagraph($i);
            }
        } catch (\Throwable $e) {
            // تجاهل
        }
    }

    // =========================================================

    public function generate(array $lesson, string $outputPath, string $theme = 'modern', int $maxSlides = 12): string
    {
        $palette = $this->themes[$theme] ?? $this->themes['modern'];
        $maxSlides = max(6, min(30, $maxSlides));
        $this->language = $lesson['language'] ?? 'ar';
        $this->applyFontForLanguage();
        $isRTL = in_array($this->language, ['ar', 'he', 'fa', 'ur']);

        $presentation = new PhpPresentation();
        $presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $presentation->getDocumentProperties()
            ->setTitle((string)($lesson['title'] ?? 'عرض الدرس'))
            ->setCreator('EduCore');
        $presentation->removeSlideByIndex(0);

        $title = trim((string)($lesson['title'] ?? 'عرض الدرس'));
        $plan = $lesson['lesson_plan'] ?? [];

        // === شريحة الغلاف ===
        $this->addCover($presentation, $title, (string)($lesson['subject'] ?? ''), $palette, $isRTL);

        // === بناء الشرائح من محتوى الدرس الفعلي ===
        $contentSlides = $this->buildLessonSlides($plan, $isRTL);

        // إضافة شرائح المحتوى مع مراعاة الحد الأقصى
        $slideIndex = 0;
        foreach (array_slice($contentSlides, 0, $maxSlides - 2) as $slideData) {
            $this->addContentSlide(
                $presentation,
                $slideData['title'],
                $slideData['bullets'],
                $palette,
                $slideIndex,
                $isRTL,
                $slideData['icon'] ?? null
            );
            $slideIndex++;
        }

        // (حُذفت شريحة "النشاط الصفي" — بيانات تحضير معلم، ليست محتوى عرض للطالب)

        // === شريحة الختام ===
        $this->addClosingSlide($presentation, $palette, $isRTL);

        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('تعذر إنشاء مجلد العروض');
        }
        IOFactory::createWriter($presentation, 'PowerPoint2007')->save($outputPath);
        if (!is_file($outputPath) || filesize($outputPath) < 1000) {
            throw new RuntimeException('لم يتم إنشاء ملف PowerPoint بصورة صحيحة');
        }
        return $outputPath;
    }

    /**
     * بناء شرائح من محتوى الدرس الفعلي بدلاً من مجرد عرض المفاتيح
     */
    private function buildLessonSlides(array $plan, bool $isRTL): array
    {
        $slides = [];

        // 1. شريحة الأهداف التعليمية (موجَّهة للطالب — لا تصنيف معرفي/وجداني/مهاري)
        if (isset($plan['objectives'])) {
            $objectiveBullets = [];
            $objectives = $plan['objectives'];
            if (is_array($objectives)) {
                // اجمع كل الأهداف من أي تصنيف (cognitive/affective/psychomotor) في قائمة مسطّحة واحدة
                // موجَّهة للطالب، دون عرض التصنيف نفسه (فهو بيانات تحضير معلم).
                foreach (['cognitive', 'affective', 'psychomotor'] as $bucket) {
                    if (!empty($objectives[$bucket]) && is_array($objectives[$bucket])) {
                        foreach ($objectives[$bucket] as $obj) {
                            $obj = trim(strip_tags((string)$obj));
                            if ($obj !== '') {
                                $objectiveBullets[] = $obj;
                            }
                        }
                    }
                }
            }
            // لو لم تُوجَد تصنيفات، نحاول تفريد المصفوفة كاملة.
            if (empty($objectiveBullets)) {
                $objectiveBullets = $this->flatten($objectives);
            }
            if ($objectiveBullets) {
                // مقدمة تلقائية موجَّهة للطالب + حد أقصى 6 أهداف.
                $intro = $isRTL ? 'بعد هذا الدرس سيكون الطالب قادراً على:' : 'By the end of this lesson, students will be able to:';
                $slides[] = [
                    'title' => $isRTL ? 'الأهداف التعليمية' : 'Learning Objectives',
                    'bullets' => array_merge([$intro], array_slice($objectiveBullets, 0, 6)),
                    'icon' => '🎯'
                ];
            }
        }

        // 2. شريحة التمهيد / المقدمة
        if (isset($plan['introduction'])) {
            $introBullets = $this->flatten($plan['introduction']);
            if ($introBullets) {
                $slides[] = [
                    'title' => $isRTL ? 'التمهيد والمقدمة' : 'Introduction',
                    'bullets' => $introBullets,
                    'icon' => '📖'
                ];
            }
        }

        // 3. شرائح مراحل الدرس - كل مرحلة = شريحة أو أكثر
        if (isset($plan['lesson_phases']) && is_array($plan['lesson_phases'])) {
            foreach ($plan['lesson_phases'] as $phase) {
                if (!is_array($phase)) continue;

                $phaseTitle = trim((string)($phase['phase_title'] ?? $phase['title'] ?? $phase['name'] ?? ''));
                $phaseDuration = $phase['duration_minutes'] ?? $phase['duration'] ?? '';

                if ($phaseTitle === '' && isset($phase[0]) && is_string($phase[0])) {
                    $phaseTitle = $phase[0];
                }
                if ($phaseTitle === '') continue;

                // إضافة المدة الزمنية للعنوان
                if ($phaseDuration && $phaseDuration !== 'auto') {
                    $phaseTitle .= ($isRTL ? ' (' . $phaseDuration . ' د)' : ' (' . $phaseDuration . ' min)');
                }

                $phaseBullets = [];

                // المحتوى / الوصف (محتوى الدرس الموجَّه للطالب فقط)
                $content = $phase['content'] ?? $phase['description'] ?? $phase['activities'] ?? null;
                if ($content) {
                    $contentItems = $this->flatten($content);
                    $phaseBullets = array_merge($phaseBullets, $contentItems);
                }

                // ملاحظة: لا نُضمّن "دور المعلم/دور المتعلم" — فهي بيانات تحضير معلم تخصّ سير الحصة
                // وليست محتوى يُعرض على الطالب. الشرائح هنا لشرح الدرس للطالب فقط.

                // إذا لا توجد نقاط محددة، نحاول تفريد المرحلة بالكامل
                if (empty($phaseBullets)) {
                    $phaseBullets = $this->flatten($phase);
                    // إزالة عنوان المرحلة من النقاط لتجنب التكرار
                    $phaseBullets = array_filter($phaseBullets, function($b) use ($phaseTitle) {
                        return mb_strpos($b, $phaseTitle) === false;
                    });
                    $phaseBullets = array_values($phaseBullets);
                }

                if ($phaseBullets) {
                    // إذا كانت النقاط كثيرة، نقسمها على شريحتين
                    if (count($phaseBullets) > 7) {
                        $chunks = array_chunk($phaseBullets, 6);
                        foreach ($chunks as $i => $chunk) {
                            $slideTitle = count($chunks) > 1
                                ? $phaseTitle . ' (' . ($i + 1) . '/' . count($chunks) . ')'
                                : $phaseTitle;
                            $slides[] = [
                                'title' => $slideTitle,
                                'bullets' => $chunk,
                                'icon' => '📌'
                            ];
                        }
                    } else {
                        $slides[] = [
                            'title' => $phaseTitle,
                            'bullets' => $phaseBullets,
                            'icon' => '📌'
                        ];
                    }
                }
            }
        }

        // (حُذفت شريحة "استراتيجيات التدريس" — بيانات تحضير معلم، ليست محتوى عرض للطالب)

        // 5. أسئلة للتفكير (نقاط نقاش للطالب — كانت "التقويم" الخاص بالمعلم)
        if (isset($plan['evaluation']) || isset($plan['assessment'])) {
            $evalData = $plan['evaluation'] ?? $plan['assessment'];
            $evalBullets = $this->flatten($evalData);
            if ($evalBullets) {
                $slides[] = [
                    'title' => $isRTL ? 'أسئلة للتفكير' : 'Points to Think About',
                    'bullets' => array_slice($evalBullets, 0, 6),
                    'icon' => '🤔'
                ];
            }
        }

        // 6. خلاصة الدرس (مفيدة للطالب كخلاصة تعليمية)
        if (isset($plan['closure_summary'])) {
            $closureBullets = $this->flatten($plan['closure_summary']);
            if ($closureBullets) {
                $slides[] = [
                    'title' => $isRTL ? 'خلاصة الدرس' : 'Lesson Summary',
                    'bullets' => array_slice($closureBullets, 0, 6),
                    'icon' => '📋'
                ];
            }
        }

        // (حُذفت شريحتا "الوسائل والمصادر التعليمية" و "الواجب المنزلي" — بيانات تحضير معلم،
        // ليست محتوى عرض للطالب)

        // إن لم تُنتج أي شرائح، نعود لشريحة افتراضية
        if (empty($slides)) {
            $fallbackBullets = $this->flatten($plan);
            if (!$fallbackBullets) {
                $fallbackBullets = [$isRTL ? 'راجع محتوى الدرس مع الطلاب وناقش الأفكار الرئيسية.' : 'Review the lesson content and discuss key ideas.'];
            }
            $slides[] = [
                'title' => $isRTL ? 'محتوى الدرس' : 'Lesson Content',
                'bullets' => $fallbackBullets,
                'icon' => '📖'
            ];
        }

        return $slides;
    }

    /**
     * شريحة الغلاف
     */
    private function addCover(PhpPresentation $ppt, string $title, string $subject, array $p, bool $isRTL): void
    {
        $slide = $ppt->createSlide();
        $this->background($slide, $p['primary'], $this->gradientFor($p, 'cover'));

        // شريط جانبي ملون
        $barX = $isRTL ? 886 : 60;
        $bar = $slide->createRichTextShape()->setOffsetX($barX)->setOffsetY(70)->setWidth(14)->setHeight(375);
        $bar->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($p['accent']));

        $align = $isRTL ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT;
        $titleX = $isRTL ? 110 : 110;

        $this->text($slide, $title, $titleX, 135, 735, 120, 36, 'FFFFFF', true, $align, $isRTL);

        $subtitle = $subject ?: ($isRTL ? 'عرض تعليمي تفاعلي' : 'Interactive Educational Presentation');
        $this->text($slide, $subtitle, $titleX, 275, 735, 50, 22, 'E8EEF6', false, $align, $isRTL);

        $this->text($slide, 'EduCore', $titleX, 455, 735, 30, 13, 'D5DFEA', false, $align, $isRTL);
    }

    /**
     * شريحة محتوى مع دعم RTL
     */
    private function addContentSlide(PhpPresentation $ppt, string $title, array $bullets, array $p, int $index, bool $isRTL, ?string $icon = null): void
    {
        $slide = $ppt->createSlide();
        $this->background($slide, $p['bg'], $this->gradientFor($p, 'content-bg'));

        // شريط علوي ملون
        $accent = $slide->createRichTextShape()->setOffsetX(0)->setOffsetY(0)->setWidth(960)->setHeight(14);
        $accent->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($p['accent']));

        // عنوان الشريحة مع أيقونة
        $align = $isRTL ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT;
        $displayTitle = ($icon ? $icon . ' ' : '') . $title;
        $this->text($slide, $displayTitle, 60, 30, 840, 55, 28, $p['primary'], true, $align, $isRTL);

        // خط فاصل تحت العنوان
        $separator = $slide->createRichTextShape()->setOffsetX(60)->setOffsetY(88)->setWidth(840)->setHeight(3);
        $separator->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($p['accent']));

        // منطقة المحتوى
        $body = $slide->createRichTextShape()->setOffsetX(60)->setOffsetY(105)->setWidth(840)->setHeight(380);
        $body->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color('FFFFFF'));
        $body->getShadow()->setVisible(true)->setDistance(3)->setBlurRadius(4)->setColor($this->color('D9E1EA'));

        // رسم النقاط
        $filteredBullets = array_values(array_filter($bullets, function($b) {
            return trim(strip_tags((string)$b)) !== '';
        }));

        foreach (array_slice($filteredBullets, 0, 8) as $bulletIndex => $bullet) {
            $para = $body->createParagraph();
            $para->getAlignment()
                ->setHorizontal($align)
                ->setIsRTL($isRTL)
                ->setMarginLeft($isRTL ? 0 : 18)
                ->setMarginRight($isRTL ? 18 : 0);

            // إضافة مسافة بين النقاط
            if ($bulletIndex > 0) {
                $para->setLineSpacing(130);
            }

            $bulletText = trim(strip_tags((string)$bullet));

            // تحديد إذا كان عنواناً فرعياً (يبدأ بإيموجي أو ينتهي بنقطتين)
            $isSubheading = preg_match('/^[\x{1F300}-\x{1FAD6}]/u', $bulletText) && mb_substr($bulletText, -1) === ':';

            if ($isSubheading) {
                // عنوان فرعي بخط عريض ولون مميز — بدون نقاط
                $run = $para->createTextRun($bulletText);
                $run->getFont()
                    ->setName($this->fontName)
                    ->setSize(18)
                    ->setBold(true)
                    ->setColor($this->color($p['primary']));
                $para->getBulletStyle()->setBulletType(Bullet::TYPE_NONE);
            } else {
                // نقطة عادية — تنسيق Bullet أصلي في PowerPoint (قابل للتحرير)
                $run = $para->createTextRun(mb_strimwidth($bulletText, 0, 200, '…', 'UTF-8'));
                $run->getFont()
                    ->setName($this->fontName)
                    ->setSize(17)
                    ->setColor($this->color($p['text']));
                $para->getBulletStyle()
                    ->setBulletType(Bullet::TYPE_BULLET)
                    ->setBulletChar('•')
                    ->setBulletColor($this->color($p['accent']));
            }
        }

        // رقم الشريحة
        $pageAlign = $isRTL ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_RIGHT;
        $pageX = $isRTL ? 45 : 875;
        $this->text($slide, (string)($index + 2), $pageX, 490, 40, 25, 12, $p['muted'], false, Alignment::HORIZONTAL_CENTER, false);

        // تذييل: علامة EduCore في الجهة المقابلة لرقم الشريحة
        $footerX = $isRTL ? 820 : 60;
        $footerAlign = $isRTL ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT;
        $this->text($slide, 'EduCore', $footerX, 505, 80, 18, 9, $p['muted'], false, $footerAlign, $isRTL);
    }

    /**
     * شريحة مقارنة بجدول حقيقي — تدعم RTL والصفوف المتناوبة الألوان.
     * تُستخدم فقط عند وجود $slideData['table'] صحيح من نوع comparison.
     */
    private function addComparisonSlide(
        PhpPresentation $ppt,
        string $title,
        array $tableData,
        array $p,
        int $index,
        bool $isRTL,
        ?string $icon = null
    ): void {
        $slide = $ppt->createSlide();
        $this->background($slide, $p['bg'], $this->gradientFor($p, 'content-bg'));

        $this->drawSlideHeader($slide, $title, $p, $isRTL, $icon, 'accent');

        // إعداد بيانات الجدول (مع حدود قصوى لتفادي الازدحام)
        $headers    = array_values((array)$tableData['headers']);
        $rows       = array_values((array)$tableData['rows']);
        $numCols    = max(2, min(5, count($headers)));       // 2–5 أعمدة
        $headers    = array_slice($headers, 0, $numCols);
        $rows       = array_slice($rows, 0, 10);               // حد أقصى 10 صفوف
        $cellAlign  = $isRTL ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT;

        // الجدول
        $table = $slide->createTableShape($numCols)
            ->setOffsetX(60)
            ->setOffsetY(110)
            ->setWidth(840)
            ->setHeight(360);

        // صف الترويسة — خلفية primary، نص أبيض عريض
        $headerRow = $table->createRow();
        $headerRow->setHeight(40);
        foreach ($headers as $h) {
            $cell    = $headerRow->nextCell();
            $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($p['primary']));
            $cell->getActiveParagraph()
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setIsRTL($isRTL);
            $run = $cell->getActiveParagraph()->createTextRun((string)$h);
            $run->getFont()->setName($this->fontName)->setSize(15)->setBold(true)->setColor($this->color('FFFFFF'));
        }

        // صفوف البيانات — تباين zebra (أبيض / bg فاتح)
        foreach ($rows as $ri => $row) {
            $rowObj  = $table->createRow();
            $rowObj->setHeight(34);
            $rowVals = array_values((array)$row);
            $bgColor = ($ri % 2 === 0) ? 'FFFFFF' : $p['bg'];

            for ($ci = 0; $ci < $numCols; $ci++) {
                $cell = $rowObj->nextCell();
                $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($bgColor));
                $cell->getBorders()->getBottom()->setColor($this->color('E5E7EB'));

                $para = $cell->getActiveParagraph();
                $para->getAlignment()
                    ->setHorizontal($ci === 0 ? $cellAlign : Alignment::HORIZONTAL_CENTER)
                    ->setIsRTL($isRTL);

                $val = isset($rowVals[$ci]) ? trim((string)$rowVals[$ci]) : '';
                $run = $para->createTextRun(mb_strimwidth($val, 0, 80, '…', 'UTF-8'));
                $run->getFont()
                    ->setName($this->fontName)
                    ->setSize(13)
                    ->setColor($this->color($p['text']));
            }
        }

        // رقم الشريحة + التذييل
        $this->drawSlideFooter($slide, $index, $p, $isRTL);
    }

    /**
     * يتحقق من صحة بنية بيانات الجدول المُمرَّرة من الـ AI.
     * يقبل: ["headers" => [...], "rows" => [[...], ...]] مع 2–5 أعمدة و1–10 صفوف.
     */
    private function isValidTable($t): bool
    {
        if (!is_array($t) || !isset($t['headers'], $t['rows'])) return false;
        if (!is_array($t['headers']) || !is_array($t['rows'])) return false;

        $numCols = count($t['headers']);
        if ($numCols < 2 || $numCols > 5) return false;

        $rows = array_slice(array_values($t['rows']), 0, 10);
        if (count($rows) < 1) return false;

        foreach ($rows as $row) {
            if (!is_array($row)) return false;
            // كل صف يجب أن يحوي على الأقل قيمتين (لا نتحقق من تطابق تام مع numCols)
            if (count(array_filter($row, fn($v) => is_scalar($v) || is_null($v))) < 1) return false;
        }
        return true;
    }

    /**
     * يتحقق من صحة بنية بيانات الرسم البياني المُمرَّرة من الـ AI.
     * يقبل: ["kind" => "bar|pie|line", "categories" => [...], "series" => [{"name","values":[...]}]]
     * مع 1–12 فئة و1–5 سلاسل وكل قيمة numeric.
     */
    private function isValidChart($c): bool
    {
        if (!is_array($c)) return false;
        $kind = $c['kind'] ?? 'bar';
        if (!in_array($kind, ['bar', 'pie', 'line'], true)) return false;

        if (!isset($c['categories']) || !is_array($c['categories'])) return false;
        $categories = array_slice(array_values($c['categories']), 0, 12);
        if (count($categories) < 2) return false;

        if (!isset($c['series']) || !is_array($c['series'])) return false;
        $seriesList = array_slice(array_values($c['series']), 0, 5);
        if (count($seriesList) < 1) return false;

        foreach ($seriesList as $s) {
            if (!is_array($s) || !isset($s['values']) || !is_array($s['values'])) return false;
            foreach ($s['values'] as $v) {
                if (!is_numeric($v) && $v !== null) return false;
            }
            // Pie يقبل سلسلة واحدة فقط — نتحقق لاحقاً عند البناء
            if ($kind === 'pie') break;
        }
        return true;
    }

    /**
     * شريحة رسم بياني حقيقي (Bar/Pie/Line) — تدعم RTL.
     * تُستخدم فقط عند وجود $slideData['chart'] صحيح من نوع chart.
     */
    private function addChartSlide(
        PhpPresentation $ppt,
        string $title,
        array $chartData,
        array $p,
        int $index,
        bool $isRTL,
        ?string $icon = null
    ): void {
        $slide = $ppt->createSlide();
        $this->background($slide, $p['bg'], $this->gradientFor($p, 'content-bg'));

        $this->drawSlideHeader($slide, $title, $p, $isRTL, $icon, 'accent');

        // بناء بيانات السلاسل (مع الحدود)
        $kind         = in_array($chartData['kind'] ?? '', ['bar', 'pie', 'line'], true) ? $chartData['kind'] : 'bar';
        $categories   = array_slice(array_map('strval', (array)$chartData['categories']), 0, 12);
        $rawSeries    = array_slice(array_values((array)$chartData['series']), 0, 5);

        $seriesList = [];
        foreach ($rawSeries as $s) {
            if (!is_array($s) || !isset($s['values'])) continue;
            $rawVals = array_slice((array)$s['values'], 0, count($categories));
            // بناء مصفوفة associative: المفاتيح = أسماء الفئات، القيم = الأرقام.
            // PHPPresentation يقرأ الفئات من array_keys($series->getValues()) في الـ Writer.
            $values = [];
            for ($i = 0; $i < count($categories); $i++) {
                $values[$categories[$i]] = isset($rawVals[$i]) && is_numeric($rawVals[$i]) ? (float)$rawVals[$i] : 0.0;
            }

            $name   = trim((string)($s['name'] ?? ''));
            $series = new Series($name !== '' ? $name : ' ', $values);
            $series->setShowValue(true);
            $seriesList[] = $series;

            if ($kind === 'pie') break; // Pie: سلسلة واحدة فقط
        }

        if (empty($seriesList)) {
            // وقاية نهائية: لا توجد بيانات صالحة — نسقط لشريحة محتوى فارغة
            error_log('addChartSlide: no valid series — rendering empty content slide');
            $this->drawSlideFooter($slide, $index, $p, $isRTL);
            return;
        }

        // إنشاء الـ Chart shape
        $chart = $slide->createChartShape()
            ->setOffsetX(70)
            ->setOffsetY(105)
            ->setWidth(820)
            ->setHeight(370);

        // لا نُكرّر العنوان داخل الـ Chart (العنوان موجود في ترويسة الشريحة)
        $chart->getTitle()->setVisible(false);

        // Legend: ظاهرة فقط عند وجود أكثر من سلسلة
        $legend = $chart->getLegend();
        $legend->setVisible(count($seriesList) > 1);
        if (count($seriesList) > 1) {
            $legend->setPosition($isRTL ? \PhpOffice\PhpPresentation\Shape\Chart\Legend::POSITION_LEFT : \PhpOffice\PhpPresentation\Shape\Chart\Legend::POSITION_RIGHT);
        }

        // نوع الرسم البياني
        if ($kind === 'pie') {
            $type = new ChartPie();
            $type->addSeries($seriesList[0]);
        } elseif ($kind === 'line') {
            $type = new ChartLine();
            foreach ($seriesList as $s) $type->addSeries($s);
        } else {
            $type = new ChartBar();
            $type->setBarDirection(ChartBar::DIRECTION_VERTICAL);
            $type->setBarGrouping(ChartBar::GROUPING_CLUSTERED);
            foreach ($seriesList as $s) $type->addSeries($s);
        }

        $chart->getPlotArea()->setType($type);

        // رقم الشريحة + التذييل
        $this->drawSlideFooter($slide, $index, $p, $isRTL);
    }

    /**
     * يرسم ترويسة شريحة موحّدة: شريط accent العلوي + العنوان (مع أيقونة) + خط فاصل.
     * يُستخدم من addContentSlide / addComparisonSlide / addChartSlide.
     */
    private function drawSlideHeader($slide, string $title, array $p, bool $isRTL, ?string $icon = null, string $barColorKey = 'accent'): void
    {
        $barColor = $p[$barColorKey] ?? $p['accent'];

        // شريط علوي ملون
        $topBar = $slide->createRichTextShape()->setOffsetX(0)->setOffsetY(0)->setWidth(960)->setHeight(14);
        $topBar->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($barColor));

        // عنوان الشريحة مع أيقونة
        $align        = $isRTL ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT;
        $displayTitle = ($icon ? $icon . ' ' : '') . $title;
        $this->text($slide, $displayTitle, 60, 30, 840, 55, 28, $p['primary'], true, $align, $isRTL);

        // خط فاصل تحت العنوان
        $separator = $slide->createRichTextShape()->setOffsetX(60)->setOffsetY(88)->setWidth(840)->setHeight(3);
        $separator->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($barColor));
    }

    /**
     * يرسم تذييل شريحة موحّد: رقم الشريحة + علامة EduCore.
     */
    private function drawSlideFooter($slide, int $index, array $p, bool $isRTL): void
    {
        $pageX = $isRTL ? 45 : 875;
        $this->text($slide, (string)($index + 2), $pageX, 490, 40, 25, 12, $p['muted'], false, Alignment::HORIZONTAL_CENTER, false);

        $footerX     = $isRTL ? 820 : 60;
        $footerAlign = $isRTL ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT;
        $this->text($slide, 'EduCore', $footerX, 505, 80, 18, 9, $p['muted'], false, $footerAlign, $isRTL);
    }

    /**
     * شريحة أسئلة التقييم — تنسيق مرقَّم بخلفية مميزة
     */
    private function addQuestionsSlide(PhpPresentation $ppt, string $title, array $questions, array $p, int $index, bool $isRTL): void
    {
        $slide = $ppt->createSlide();
        $this->background($slide, $p['bg'], $this->gradientFor($p, 'content-bg'));

        // شريط علوي بلون مختلف (primary بدلاً من accent) للتمييز
        $topBar = $slide->createRichTextShape()->setOffsetX(0)->setOffsetY(0)->setWidth(960)->setHeight(14);
        $topBar->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($p['primary']));

        // عنوان الشريحة
        $align = $isRTL ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT;
        $this->text($slide, '❓ ' . $title, 60, 30, 840, 55, 26, $p['primary'], true, $align, $isRTL);

        // خط فاصل
        $sep = $slide->createRichTextShape()->setOffsetX(60)->setOffsetY(88)->setWidth(840)->setHeight(3);
        $sep->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($p['primary']));

        // منطقة الأسئلة بخلفية فاتحة خاصة
        $body = $slide->createRichTextShape()->setOffsetX(60)->setOffsetY(105)->setWidth(840)->setHeight(390);
        $body->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->color('F0F4FF'));
        $body->getShadow()->setVisible(true)->setDistance(3)->setBlurRadius(4)->setColor($this->color('C7D2E8'));

        $filteredQ = array_values(array_filter($questions, fn($q) => trim((string)$q) !== ''));
        foreach (array_slice($filteredQ, 0, 7) as $qi => $question) {
            $para = $body->createParagraph();
            $para->getAlignment()
                ->setHorizontal($align)
                ->setIsRTL($isRTL)
                ->setMarginLeft($isRTL ? 0 : 18)
                ->setMarginRight($isRTL ? 18 : 0);
            if ($qi > 0) { $para->setLineSpacing(140); }

            $qText = trim(strip_tags((string)$question));
            // إزالة بادئة الترقيم اليدوي إن وُجدت (س1: / Q1. / 1) / ١.)
            // لأن الترقيم الأصلي سيُولَّد بواسطة PowerPoint تلقائياً
            $qText = preg_replace('/^\s*(?:[سقSQFsqf]\s*)?[\d٠١٢٣٤٥٦٧٨٩]+\s*[:\.\)\-]\s*/u', '', $qText);
            $qText = trim($qText);

            $run = $para->createTextRun(mb_strimwidth($qText, 0, 220, '…', 'UTF-8'));
            $run->getFont()
                ->setName($this->fontName)
                ->setSize(15)
                ->setColor($this->color($p['text']));
            // ترقيم أصلي في PowerPoint بدل البادئة النصية اليدوية
            $para->getBulletStyle()
                ->setBulletType(Bullet::TYPE_NUMERIC)
                ->setBulletNumericStartAt(1)
                ->setBulletColor($this->color($p['primary']));
        }

        // رقم الشريحة
        $this->text($slide, (string)($index + 2), $isRTL ? 45 : 875, 490, 40, 25, 12, $p['muted'], false, Alignment::HORIZONTAL_CENTER, false);
    }

    /**
     * شريحة الختام
     */
    private function addClosingSlide(PhpPresentation $ppt, array $p, bool $isRTL): void
    {
        $slide = $ppt->createSlide();
        $this->background($slide, $p['primary'], $this->gradientFor($p, 'closing'));

        $closingTitle = $isRTL ? 'ماذا تعلمنا اليوم؟' : 'What Did We Learn Today?';
        $closingText = $isRTL
            ? 'لخّص الفكرة الرئيسية في جملة، ثم اذكر تطبيقًا واحدًا لها.'
            : 'Summarize the main idea in one sentence, then give one application.';

        $this->text($slide, $closingTitle, 90, 140, 780, 75, 36, 'FFFFFF', true, Alignment::HORIZONTAL_CENTER, $isRTL);
        $this->text($slide, $closingText, 135, 250, 690, 80, 22, 'E8EEF6', false, Alignment::HORIZONTAL_CENTER, $isRTL);
    }

    /**
     * إضافة خلفية للشريحة — لون مصمت أو تدرج خطي اختياري.
     * تمرير $gradient = null يحافظ على السلوك القديم (FILL_SOLID).
     */
    private function background($slide, string $color, ?array $gradient = null): void
    {
        $bg = $slide->createRichTextShape()->setOffsetX(0)->setOffsetY(0)->setWidth(960)->setHeight(540);
        $fill = $bg->getFill();
        if ($gradient !== null && isset($gradient['start'], $gradient['end'])) {
            $fill->setFillType(Fill::FILL_GRADIENT_LINEAR)
                ->setStartColor($this->color($gradient['start']))
                ->setEndColor($this->color($gradient['end']))
                ->setRotation($gradient['rotation'] ?? 0);
        } else {
            $fill->setFillType(Fill::FILL_SOLID)->setStartColor($this->color($color));
        }
    }

    /**
     * إضافة نص مع دعم RTL كامل
     */
    private function text($slide, string $text, int $x, int $y, int $w, int $h, int $size, string $color, bool $bold, string $align, bool $isRTL = false): void
    {
        $shape = $slide->createRichTextShape()->setOffsetX($x)->setOffsetY($y)->setWidth($w)->setHeight($h);
        $shape->getActiveParagraph()->getAlignment()
            ->setHorizontal($align)
            ->setIsRTL($isRTL);
        $run = $shape->createTextRun($text);
        $run->getFont()
            ->setName($this->fontName)
            ->setSize($size)
            ->setBold($bold)
            ->setColor($this->color($color));
    }

    /**
     * إنشاء كائن لون
     */
    private function color(string $hex): Color
    {
        return new Color(strlen($hex) === 6 ? 'FF' . $hex : $hex);
    }

    /**
     * بناء مواصفات تدرّج لوني لطيفة لكل نوع شريحة انطلاقاً من بالتة الثيم.
     * يعيد null للأنواع غير المعروفة (فيبقى السلوك الافتراضي: لون مصمت).
     */
    private function gradientFor(array $p, string $kind): ?array
    {
        switch ($kind) {
            case 'cover':
                // تدرج قطري من primary إلى لون أغمق بـ 25% — عمق بصري للغلاف
                return [
                    'start'    => $p['primary'],
                    'end'      => $this->darken($p['primary'], 25),
                    'rotation' => 135,
                ];
            case 'content-bg':
                // تدرج رأسي خفيف من bg إلى لون أفتح بـ 8% — إحساس بالعمق دون تشتيت
                return [
                    'start'    => $p['bg'],
                    'end'      => $this->lighten($p['bg'], 8),
                    'rotation' => 90,
                ];
            case 'closing':
                // تدرج قطري من primary إلى accent — إنهاء أنيق بصري
                return [
                    'start'    => $p['primary'],
                    'end'      => $this->accentEndForClosing($p),
                    'rotation' => 135,
                ];
        }
        return null;
    }

    /**
     * لون نهاية تدرج الختام: accent إن وُجد وله تباين كافٍ، وإلا لون أغمق من primary.
     */
    private function accentEndForClosing(array $p): string
    {
        $accent = $p['accent'] ?? '';
        $primary = $p['primary'] ?? '000000';
        // إذا كان accent شديد القرب من primary بصرياً، نستخدم لوناً أفتح من primary بدلاً منه
        // لتجنّب تدرج رتيب بين لونين متشابهين.
        if ($accent === '' || $this->colorDistance($accent, $primary) < 40) {
            return $this->lighten($primary, 20);
        }
        return $accent;
    }

    /**
     * مسافة لونية تقريبية بين لونين hex (0 = متطابقان).
     */
    private function colorDistance(string $a, string $b): float
    {
        $a = strlen($a) === 8 ? substr($a, 2) : $a;
        $b = strlen($b) === 8 ? substr($b, 2) : $b;
        $ar = hexdec(substr($a, 0, 2)); $ag = hexdec(substr($a, 2, 2)); $ab = hexdec(substr($a, 4, 2));
        $br = hexdec(substr($b, 0, 2)); $bg = hexdec(substr($b, 2, 2)); $bb = hexdec(substr($b, 4, 2));
        return sqrt(pow($ar - $br, 2) + pow($ag - $bg, 2) + pow($ab - $bb, 2));
    }

    /**
     * تغميق لون hex بنسبة مئوية (0-100).
     */
    private function darken(string $hex, float $percent): string
    {
        $hex = strlen($hex) === 8 ? substr($hex, 2) : $hex;
        $factor = 1 - max(0.0, min(100.0, $percent)) / 100;
        $r = (int)round(hexdec(substr($hex, 0, 2)) * $factor);
        $g = (int)round(hexdec(substr($hex, 2, 2)) * $factor);
        $b = (int)round(hexdec(substr($hex, 4, 2)) * $factor);
        return $this->padHex($r, $g, $b);
    }

    /**
     * تفتيح لون hex بنسبة مئوية (0-100).
     */
    private function lighten(string $hex, float $percent): string
    {
        $hex = strlen($hex) === 8 ? substr($hex, 2) : $hex;
        $factor = max(0.0, min(100.0, $percent)) / 100;
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $r = (int)round($r + (255 - $r) * $factor);
        $g = (int)round($g + (255 - $g) * $factor);
        $b = (int)round($b + (255 - $b) * $factor);
        return $this->padHex($r, $g, $b);
    }

    /**
     * تحويل قيم RGB إلى سلسلة hex من 6 خانات.
     */
    private function padHex(int $r, int $g, int $b): string
    {
        return str_pad(dechex(max(0, min(255, $r))), 2, '0', STR_PAD_LEFT)
             . str_pad(dechex(max(0, min(255, $g))), 2, '0', STR_PAD_LEFT)
             . str_pad(dechex(max(0, min(255, $b))), 2, '0', STR_PAD_LEFT);
    }

    /**
     * تحويل قيمة متداخلة إلى مصفوفة نصوص مسطحة
     */
    private function flatten($value): array
    {
        $out = [];
        if (is_scalar($value) && trim((string)$value) !== '') {
            return [trim((string)$value)];
        }
        if (!is_array($value)) return [];

        foreach ($value as $k => $v) {
            if (is_scalar($v) && trim((string)$v) !== '') {
                if (is_string($k) && !is_numeric($k)) {
                    $out[] = $this->label($k) . ': ' . trim((string)$v);
                } else {
                    $out[] = trim((string)$v);
                }
            } else {
                $out = array_merge($out, $this->flatten($v));
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * تسميات عربية للمفاتيح
     */
    private function label(string $key): string
    {
        $labels = [
            'objectives' => 'الأهداف التعليمية',
            'cognitive' => 'معرفي',
            'affective' => 'وجداني',
            'psychomotor' => 'مهاري',
            'introduction' => 'التمهيد',
            'strategies' => 'استراتيجيات التدريس',
            'teaching_strategies' => 'استراتيجيات التدريس',
            'active_learning' => 'التعلم النشط',
            'lesson_phases' => 'مراحل الدرس',
            'phase_title' => 'المرحلة',
            'duration_minutes' => 'المدة',
            'teacher_role' => 'دور المعلم',
            'student_role' => 'دور المتعلم',
            'content' => 'المحتوى',
            'resources' => 'المصادر والوسائل',
            'resources_needed' => 'الموارد المطلوبة',
            'evaluation' => 'التقويم',
            'assessment' => 'التقويم',
            'homework' => 'الواجب المنزلي',
            'presentation' => 'عرض الدرس',
            'main_content' => 'المحتوى الرئيسي',
            'closure_summary' => 'الخلاصة',
            'differentiation' => 'التمايز',
        ];
        return $labels[$key] ?? trim(str_replace('_', ' ', $key));
    }
}
