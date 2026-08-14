<?php

declare(strict_types=1);

trait AIPromptsContentTrait
{
    public static function getLessonSummaryPrompt($language) {
        if ($language === 'ar') {
            return <<<PROMPT
أنت خبير تربوي متخصص في تلخيص الدروس بشكل احترافي وشامل.

المطلوب: إنشاء ملخص شامل ثنائي اللغة (إنجليزي + عربي) للدرس بناءً على المحتوى المقدم.

⚠️ تعليمات مهمة:
1. الملخص يجب أن يكون شاملاً ويغطي جميع النقاط الرئيسية
2. كل نقطة يجب أن تكون باللغتين: الإنجليزية والعربية
3. استخدم لغة واضحة وبسيطة مناسبة للطلاب
4. رتّب النقاط بشكل منطقي ومتسلسل
5. أضف مصطلحات رئيسية مع ترجمتها

📋 أرجع الإجابة بصيغة JSON التالية:

{
    "summary_title_en": "Lesson Summary: [Title]",
    "summary_title_ar": "ملخص الدرس: [العنوان]",
    "introduction": {
        "en": "Brief introduction paragraph in English about the lesson topic",
        "ar": "فقرة مقدمة مختصرة بالعربية عن موضوع الدرس"
    },
    "key_points": [
        {
            "id": 1,
            "title_en": "Key point title in English",
            "title_ar": "عنوان النقطة الرئيسية بالعربية",
            "explanation_en": "Detailed explanation in English",
            "explanation_ar": "شرح تفصيلي بالعربية",
            "emoji": "📌"
        }
    ],
    "key_terms": [
        {
            "term_en": "English Term",
            "term_ar": "المصطلح بالعربية",
            "definition_en": "Definition in English",
            "definition_ar": "التعريف بالعربية"
        }
    ],
    "important_formulas": [
        {
            "formula": "Formula or rule text",
            "description_en": "Explanation in English",
            "description_ar": "الشرح بالعربية"
        }
    ],
    "conclusion": {
        "en": "Concluding summary paragraph in English",
        "ar": "فقرة خاتمة ملخصة بالعربية"
    },
    "study_tips": [
        {
            "tip_en": "Study tip in English",
            "tip_ar": "نصيحة دراسية بالعربية"
        }
    ]
}

ملاحظات:
- أنشئ 5-10 نقاط رئيسية على الأقل
- أنشئ 5-8 مصطلحات رئيسية على الأقل
- إذا لم يكن هناك معادلات أو صيغ، أرجع مصفوفة فارغة لـ important_formulas
- أنشئ 3-5 نصائح دراسية

أرجع JSON فقط بدون أي نص إضافي.
PROMPT;
        } elseif ($language === 'fr') {
            return <<<PROMPT
Vous êtes un expert pédagogique spécialisé dans la synthèse professionnelle et complète des leçons.

Tâche : Créer un résumé bilingue complet (anglais + arabe) de la leçon basé sur le contenu fourni.

⚠️ Instructions importantes :
1. Le résumé doit être complet et couvrir tous les points principaux
2. Chaque point doit être en deux langues : anglais et arabe
3. Utilisez un langage clair et simple adapté aux élèves
4. Organisez les points de manière logique et séquentielle
5. Ajoutez les termes clés avec leur traduction

📋 Retournez la réponse au format JSON suivant :

{
    "summary_title_en": "Lesson Summary: [Title]",
    "summary_title_ar": "ملخص الدرس: [العنوان]",
    "introduction": {
        "en": "Brief introduction paragraph in English",
        "ar": "فقرة مقدمة مختصرة بالعربية"
    },
    "key_points": [
        {
            "id": 1,
            "title_en": "Key point title in English",
            "title_ar": "عنوان النقطة الرئيسية بالعربية",
            "explanation_en": "Detailed explanation in English",
            "explanation_ar": "شرح تفصيلي بالعربية",
            "emoji": "📌"
        }
    ],
    "key_terms": [
        {
            "term_en": "English Term",
            "term_ar": "المصطلح بالعربية",
            "definition_en": "Definition in English",
            "definition_ar": "التعريف بالعربية"
        }
    ],
    "important_formulas": [
        {
            "formula": "Formula or rule text",
            "description_en": "Explanation in English",
            "description_ar": "الشرح بالعربية"
        }
    ],
    "conclusion": {
        "en": "Concluding summary paragraph in English",
        "ar": "فقرة خاتمة ملخصة بالعربية"
    },
    "study_tips": [
        {
            "tip_en": "Study tip in English",
            "tip_ar": "نصيحة دراسية بالعربية"
        }
    ]
}

Notes :
- Créez au moins 5-10 points clés
- Créez au moins 5-8 termes clés
- S'il n'y a pas de formules, retournez un tableau vide pour important_formulas
- Créez 3-5 conseils d'étude

Retournez uniquement le JSON sans texte supplémentaire.
PROMPT;
        } elseif ($language === 'de') {
            return <<<PROMPT
Sie sind ein pädagogischer Experte, spezialisiert auf professionelle und umfassende Unterrichtszusammenfassungen.

Aufgabe: Erstellen Sie eine umfassende zweisprachige Zusammenfassung (Englisch + Arabisch) der Lektion basierend auf dem bereitgestellten Inhalt.

⚠️ Wichtige Anweisungen:
1. Die Zusammenfassung muss umfassend sein und alle Hauptpunkte abdecken
2. Jeder Punkt muss in zwei Sprachen sein: Englisch und Arabisch
3. Verwenden Sie eine klare und einfache Sprache, die für Schüler geeignet ist
4. Ordnen Sie die Punkte logisch und sequentiell
5. Fügen Sie Schlüsselbegriffe mit ihrer Übersetzung hinzu

📋 Geben Sie die Antwort im folgenden JSON-Format zurück:

{
    "summary_title_en": "Lesson Summary: [Title]",
    "summary_title_ar": "ملخص الدرس: [العنوان]",
    "introduction": {
        "en": "Brief introduction paragraph in English",
        "ar": "فقرة مقدمة مختصرة بالعربية"
    },
    "key_points": [
        {
            "id": 1,
            "title_en": "Key point title in English",
            "title_ar": "عنوان النقطة الرئيسية بالعربية",
            "explanation_en": "Detailed explanation in English",
            "explanation_ar": "شرح تفصيلي بالعربية",
            "emoji": "📌"
        }
    ],
    "key_terms": [
        {
            "term_en": "English Term",
            "term_ar": "المصطلح بالعربية",
            "definition_en": "Definition in English",
            "definition_ar": "التعريف بالعربية"
        }
    ],
    "important_formulas": [
        {
            "formula": "Formula or rule text",
            "description_en": "Explanation in English",
            "description_ar": "الشرح بالعربية"
        }
    ],
    "conclusion": {
        "en": "Concluding summary paragraph in English",
        "ar": "فقرة خاتمة ملخصة بالعربية"
    },
    "study_tips": [
        {
            "tip_en": "Study tip in English",
            "tip_ar": "نصيحة دراسية بالعربية"
        }
    ]
}

Hinweise:
- Erstellen Sie mindestens 5-10 Hauptpunkte
- Erstellen Sie mindestens 5-8 Schlüsselbegriffe
- Wenn keine Formeln vorhanden sind, geben Sie ein leeres Array für important_formulas zurück
- Erstellen Sie 3-5 Lerntipps

Geben Sie nur JSON ohne zusätzlichen Text zurück.
PROMPT;
        } else {
            return <<<PROMPT
You are an expert educator specializing in professional and comprehensive lesson summarization.

Task: Create a comprehensive bilingual summary (English + Arabic) of the lesson based on the provided content.

⚠️ Important Instructions:
1. The summary must be comprehensive and cover all main points
2. Each point must be in two languages: English and Arabic
3. Use clear and simple language suitable for students
4. Organize points logically and sequentially
5. Add key terms with their translations

📋 Return the response in the following JSON format:

{
    "summary_title_en": "Lesson Summary: [Title]",
    "summary_title_ar": "ملخص الدرس: [العنوان]",
    "introduction": {
        "en": "Brief introduction paragraph in English about the lesson topic",
        "ar": "فقرة مقدمة مختصرة بالعربية عن موضوع الدرس"
    },
    "key_points": [
        {
            "id": 1,
            "title_en": "Key point title in English",
            "title_ar": "عنوان النقطة الرئيسية بالعربية",
            "explanation_en": "Detailed explanation in English",
            "explanation_ar": "شرح تفصيلي بالعربية",
            "emoji": "📌"
        }
    ],
    "key_terms": [
        {
            "term_en": "English Term",
            "term_ar": "المصطلح بالعربية",
            "definition_en": "Definition in English",
            "definition_ar": "التعريف بالعربية"
        }
    ],
    "important_formulas": [
        {
            "formula": "Formula or rule text",
            "description_en": "Explanation in English",
            "description_ar": "الشرح بالعربية"
        }
    ],
    "conclusion": {
        "en": "Concluding summary paragraph in English",
        "ar": "فقرة خاتمة ملخصة بالعربية"
    },
    "study_tips": [
        {
            "tip_en": "Study tip in English",
            "tip_ar": "نصيحة دراسية بالعربية"
        }
    ]
}

Notes:
- Create at least 5-10 key points
- Create at least 5-8 key terms
- If there are no formulas or equations, return an empty array for important_formulas
- Create 3-5 study tips

Return JSON only without any additional text.
PROMPT;
        }
    }

    /**
     * بناء الـ prompt الكامل مع المحتوى
     */
    public static function buildFullPrompt($promptTemplate, $content) {
        return $promptTemplate . "\n\n---\n\nالمحتوى التعليمي:\n\n" . $content;
    }

    /**
     * الحصول على prompt المحتوى المخصص
     */
    public static function getCustomContentPrompt($language = 'ar', $customItems = []) {
        $itemsList = implode("\n", array_map(function($item, $index) {
            return ($index + 1) . ". " . $item;
        }, $customItems, array_keys($customItems)));

        $langInstructions = ($language === 'ar')
            ? 'اكتب كل المحتوى باللغة العربية الفصحى.'
            : (($language === 'en')
                ? 'Write all content in English.'
                : (($language === 'fr')
                    ? 'Rédigez tout le contenu en français.'
                    : 'Schreiben Sie alle Inhalte auf Deutsch.'));

        $icons = [
            'fa-file-alt', 'fa-clipboard-list', 'fa-lightbulb', 'fa-puzzle-piece',
            'fa-chart-bar', 'fa-users', 'fa-star', 'fa-book-open',
            'fa-pen-fancy', 'fa-tasks', 'fa-brain', 'fa-chalkboard-teacher'
        ];
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899', '#14b8a6'];

        return <<<PROMPT
أنت معلم خبير ومصمم محتوى تعليمي محترف. المطلوب منك إنشاء محتوى تعليمي مخصص للعناصر التالية بناءً على المحتوى التعليمي المُقدم.

{$langInstructions}

العناصر المطلوبة:
{$itemsList}

لكل عنصر، أنشئ محتوى تعليمي غني ومفصل بتنسيق HTML جاهز للعرض. استخدم:
- عناوين فرعية واضحة
- قوائم منظمة (مرقمة أو نقطية)
- جداول عند الحاجة
- تنسيق احترافي مع ألوان وخلفيات
- محتوى عملي قابل للتطبيق في الفصل الدراسي

أعد النتيجة بصيغة JSON فقط بالشكل التالي:
{
    "items": [
        {
            "title": "عنوان العنصر",
            "content_html": "<div>محتوى HTML هنا</div>",
            "icon": "fa-file-alt",
            "color": "#3b82f6"
        }
    ]
}

قواعد JSON الصارمة (مهم جداً):
- أعد JSON صالح فقط بدون أي نص قبله أو بعده
- لا تستخدم علامات markdown مثل ```json
- استخدم علامات الاقتباس المزدوجة " فقط في JSON
- اهرب (escape) أي علامات اقتباس مزدوجة داخل content_html باستخدام \"
- لا تضع أسطر جديدة حقيقية داخل قيم JSON - استخدم \n بدلاً منها
- تأكد من أن JSON صالح 100% وقابل للتحليل

ملاحظات المحتوى:
- أنشئ عنصراً واحداً لكل عنصر مطلوب في القائمة أعلاه
- المحتوى يجب أن يكون مرتبطاً بالمادة التعليمية المقدمة
- استخدم أيقونات FontAwesome مناسبة من: fa-file-alt, fa-clipboard-list, fa-lightbulb, fa-puzzle-piece, fa-chart-bar, fa-users, fa-star, fa-book-open, fa-pen-fancy, fa-tasks, fa-brain, fa-chalkboard-teacher
- استخدم ألواناً مختلفة لكل عنصر من: #3b82f6, #10b981, #f59e0b, #8b5cf6, #ef4444, #06b6d4, #ec4899, #14b8a6
- content_html يجب أن يكون HTML صالح بدون وسوم html/body/head
- اجعل المحتوى عملياً ومفيداً للمعلم
PROMPT;
    }

    /**
     * الحصول على prompt القصة التربوية المنظَّمة
     * يحوّل محتوى الدرس إلى قصة تعليمية واحدة مهيكلة بمشاهد وأسئلة تقويم،
     * موجّهة بعمر الطلاب. يدعم ar (الأساسي) + en/fr/de.
     *
     * @param string      $language    كود اللغة (ar/en/fr/de)
     * @param int|null    $studentAge  عمر الطلاب المستهدف (4-25) أو null
     */
    public static function getEducationalStoriesPrompt($language, $studentAge = null) {
        // تحديد وصف الفئة العمرية (يُطبَّع على اللغة، مستوى المفاهيم، طول القصة، نمط الشخصيات).
        $ageGuidance = self::buildAgeGuidance($language, $studentAge);

        if ($language === 'ar') {
            return <<<PROMPT
أنت خبيرٌ تربويٌّ متمكّن في كتابة القصص التعليمية الهادفة التي تحوّل مفاهيم الدرس إلى رحلة اكتشاف جذّابة.

المهمة: تحويل محتوى الدرس المقدَّم إلى **قصة تربوية واحدة** جذّابة ومناسبة لعمر الطلاب، بحيث تكون مفاهيم الدرس جزءاً أساسياً من أحداث القصة، ويحتاج أبطال القصة إلى فهم هذه المفاهيم أو استخدامها لحلّ المشكلة.

{$ageGuidance}

⚠️ القواعد الإلزامية (اتبعها حرفياً):
1. اجعل بطل القصة قريباً من عمر الطلاب واهتماماتهم، أو استخدم شخصية مناسبة للمادة (طالب، مخترع، مستكشف، مبرمج، عالم، روبوت، محقق، أو شخصية خيالية بسيطة).
2. ابدأ القصة بموقفٍ مثيرٍ أو مشكلةٍ واضحة — ولا تبدأ بتعريفاتٍ مباشرة أو شرحٍ نظري.
3. يجب أن تكون المشكلة مرتبطةً مباشرةً بمفهوم الدرس، وألا يمكن حلّها إلا من خلال اكتشاف الطلاب لمحتوى الدرس أو تطبيقه.
4. قسّم القصة إلى مشاهد قصيرة ومتسلسلة، بحيث يشرح كل مشهد مفهوماً واحداً أو خطوةً تعليمية واحدة.
5. أدرج محاولاتٍ خاطئة أو قراراتٍ غير صحيحة داخل القصة، ثم وضّح نتائجها، حتى يكتشف الطلاب الخطأ ويقترحوا التصحيح.
6. أوقف القصة في نقاطٍ مناسبة، وأضف أسئلةً تفاعلية يطرحها المعلم على الطلاب (مثل: ماذا تتوقعون أن يحدث؟ / ما الخطأ الذي ارتكبه البطل؟ / أي اختيارٍ هو الأفضل ولماذا؟ / كيف يمكن حلّ المشكلة؟ / ماذا سيحدث إذا تغيّر هذا الشرط؟).
7. لا تعطِ التعريف العلمي في بداية القصة — اجعل الطلاب يكتشفون الفكرة أولاً من خلال الأحداث، ثم قدّم التعريف أو القاعدة العلمية بصياغةٍ واضحة بعد الاكتشاف.
8. استخدم لغةً سهلةً ومناسبةً لعمر الطلاب، مع الحفاظ على الدقة العلمية وعدم تبسيط المفاهيم بصورةٍ خاطئة.
9. اجعل أحداث القصة واقعيةً أو منطقيةً داخل عالمها، وتجنّب التفاصيل الطويلة التي لا تخدم هدف الدرس.
10. اربط كل جزءٍ من القصة بهدفٍ تعليميٍّ واضح، ولا تضف شخصياتٍ أو أحداثاً لا تساعد على فهم محتوى الدرس.
11. استخدم المصطلحات الجديدة داخل سياق القصة، ثم وضّح معناها بطريقةٍ مبسّطة.
12. اختم القصة بمشكلةٍ أو تحدٍّ جديد يطبّق فيه الطلاب المفهوم بأنفسهم، للتأكد من انتقال التعلم إلى موقفٍ مختلف.
13. لا تجعل القصة مجرد تلخيصٍ للدرس بصيغةٍ سردية، بل اجعلها تتضمن: مشكلة، ومحاولة، وخطأ، واكتشافاً، وحلاً، وتطبيقاً.
14. لا تضف معلوماتٍ غير موجودةٍ في محتوى الدرس إلا إذا كانت ضروريةً لفهم القصة، ويجب أن تكون صحيحةً علمياً.
15. لا تستخدم مواقف مخيفة أو عنيفة أو غير مناسبة لعمر الطلاب.
16. اجعل مدة القصة والأنشطة متناسبةً مع مدة الحصة، وحافظ على تسلسلٍ منطقيٍّ واضحٍ بين المشكلة والمفهوم والحل. (إذا كان محتوى الدرس كبيراً، ركّز على المفاهيم الأساسية في قصةٍ واحدة متماسكة بدل حشد كل شيء.)

📝 تنسيق المخرجات المطلوب (JSON صارم):
أرجع **قصةً واحدة فقط** بالبنية التالية، وكل النصوص باللغة العربية الفصحى المناسبة للعمر:

{
  "title": "عنوان قصير وجذّاب ومرتبط بالدرس",
  "learning_goal": "هدفٌ تعليميٌّ واحد في جملة: ما الذي سيفهمه الطلاب من خلال القصة",
  "characters": "الشخصيات مع وصفٍ قصير لدور كل شخصية (سردٌ نصي)",
  "setting": "وصفٌ مختصر لمكان وزمان القصة (البيئة التي تدور فيها الأحداث)",
  "opening": "الموقف الافتتاحي: بدايةٌ مشوّقة تثير فضول الطلاب وتعرض المشكلة الرئيسية (نص سردي)",
  "scenes": [
    {
      "number": 1,
      "title": "عنوان المشهد",
      "narrative": "سرد أحداث المشهد بأسلوبٍ بسيطٍ وجذّاب (نص)",
      "concept": "المفهوم التعليمي الذي يقدّمه هذا المشهد (نص قصير)",
      "questions": ["سؤالٌ تفاعليٌّ واحد أو أكثر لتشجيع التوقع والتفكير والمناقشة"],
      "expected_answers": ["أبرز الإجابات التي قد يقدّمها الطلاب لأسئلة المشهد"],
      "teacher_guidance": "كيف يدير المعلم المناقشة دون إعطاء الحل مباشرة (نص)",
      "transition": "جملة قصيرة تربط هذا المشهد بالمشهد التالي"
    }
  ],
  "discovery_moment": "لحظة اكتشاف المفهوم: كيف توصّل أبطال القصة والطلاب إلى المفهوم الأساسي (نص سردي)",
  "scientific_explanation": "الشرح العلمي بعد القصة: التعريفات والقواعد والمعلومات الأساسية بصورةٍ منظّمةٍ ودقيقةٍ ومناسبة للصف",
  "lesson_connection": "الربط بين القصة والدرس: كيف مثّل كل عنصرٍ في القصة أحد مفاهيم الدرس",
  "practical_activity": "نشاطٌ تطبيقيٌّ قصير ينفّذه الطلاب فردياً أو في مجموعات ويستخدمون فيه المفهوم لحلّ مشكلةٍ مشابهة",
  "final_challenge": "تحديّ نهاية القصة: موقفٌ جديد يحتاج إلى استخدام المفهوم نفسه ولكن في سياقٍ مختلف",
  "evaluation": {
    "recall": "سؤال تذكّر واحد",
    "understanding": "سؤال فهم واحد",
    "application": "سؤال تطبيق واحد",
    "analysis": "سؤال تفكيرٍ وتحليل واحد"
  },
  "summary": "خلاصةٌ قصيرة يردّدها المعلم أو الطلاب في نهاية الحصة وتتضمّن الفكرة الرئيسية للدرس",
  "assumptions": "أيّ افتراضاتٍ تربويةٍ استخدمتها (إن لم تكن بعض بيانات الدرس متاحة)، أو نصٌّ فارغ"
}

ملاحظات JSON:
- يجب أن تكون مشاهد scenes من 3 إلى 6 مشاهد (لا تقل ولا تُكثر).
- يجب أن تكون كل القيم نصوصاً أو مصفوفاتٍ نصية (لا أرقام داخل نصوص مختلطة).
- إن لم تتوفّر بعض بيانات الدرس، استخدم أفضل افتراضٍ تربويٍّ مناسب واذكره في حقل assumptions.

أرجع JSON فقط بدون أيّ نصٍ إضافي أو شرحٍ خارجه.
PROMPT;
        } elseif ($language === 'fr') {
            return <<<PROMPT
Vous êtes un expert pédagogique dans la rédaction de récits éducatifs porteurs de sens, qui transforment les concepts d'une leçon en un parcours de découverte captivant.

Tâche : Transformer le contenu de la leçon fourni en **une seule histoire éducative** captivante et adaptée à l'âge des élèves, de sorte que les concepts de la leçon soient une partie essentielle de l'intrigue, et que les héros doivent comprendre ces concepts ou les utiliser pour résoudre le problème.

{$ageGuidance}

⚠️ Règles obligatoires (suivez-les à la lettre) :
1. Faites que le héros soit proche de l'âge et des centres d'intérêt des élèves, ou utilisez un personnage adapté à la matière (élève, inventeur, explorateur, programmeur, scientifique, robot, détective, ou personnage de fiction simple).
2. Commencez l'histoire par une situation excitante ou un problème clair — ne commencez pas par des définitions directes ou une théorie.
3. Le problème doit être directement lié au concept de la leçon et ne pouvoir être résolu que par la découverte ou l'application du contenu par les élèves.
4. Divisez l'histoire en scènes courtes et séquentielles ; chaque scène explique un seul concept ou une seule étape d'apprentissage.
5. Incluez des tentatives erronées ou de mauvaises décisions, puis montrez leurs conséquences, afin que les élèves découvrent l'erreur et proposent une correction.
6. Suspendez le récit à des moments opportuns et ajoutez des questions interactives posées par l'enseignant (Que va-t-il se passer ? / Quelle erreur le héros a-t-il commise ? / Quel choix est le meilleur et pourquoi ? / Comment résoudre le problème ? / Que se passerait-il si cette condition changeait ?).
7. Ne donnez pas la définition scientifique au début — laissez les élèves découvrir l'idée à travers les événements, puis présentez la définition ou la règle scientifique clairement après la découverte.
8. Utilisez un langage simple et adapté à l'âge, tout en conservant la rigueur scientifique sans simplifier à tort.
9. Rendez les événements réalistes ou logiques dans leur univers ; évitez les détails longs qui ne servent pas l'objectif de la leçon.
10. reliez chaque partie à un objectif pédagogique clair ; n'ajoutez pas de personnages ou d'événements qui n'aident pas à comprendre le contenu.
11. Utilisez les nouveaux termes dans le contexte du récit, puis expliquez leur sens de façon simple.
12. Terminez par un nouveau problème ou défi où les élèves appliquent eux-mêmes le concept, pour vérifier le transfert d'apprentissage.
13. Ne faites pas de l'histoire un simple résumé narratif de la leçon ; elle doit comporter : problème, tentative, erreur, découverte, solution, application.
14. N'ajoutez pas d'informations absentes du contenu de la leçon sauf si elles sont nécessaires et scientifiquement correctes.
15. N'utilisez pas de situations effrayantes, violentes ou inappropriées pour l'âge.
16. Adaptez la durée de l'histoire et des activités à la durée du cours et conservez un enchaînement logique clair entre problème, concept et solution. (Si le contenu est vaste, concentrez-vous sur les concepts essentiels dans une seule histoire cohérente.)

📝 Format de sortie requis (JSON strict) :
Retournez **une seule histoire** avec cette structure, tout le texte dans la langue de réponse :

{
  "title": "Titre court et accrocheur lié à la leçon",
  "learning_goal": "Un objectif pédagogique en une phrase : ce que les élèves comprendront grâce à l'histoire",
  "characters": "Les personnages avec une brève description du rôle de chacun (texte)",
  "setting": "Brève description du lieu et de l'époque (l'environnement de l'histoire)",
  "opening": "La situation d'ouverture : un début captivant qui éveille la curiosité et expose le problème principal (récit)",
  "scenes": [
    {
      "number": 1,
      "title": "Titre de la scène",
      "narrative": "Récit des événements de la scène, style simple et captivant (texte)",
      "concept": "Le concept pédagogique présenté par cette scène (texte court)",
      "questions": ["Une ou plusieurs questions interactives pour encourager la prédiction, la réflexion et la discussion"],
      "expected_answers": ["Les principales réponses que les élèves pourraient donner aux questions de la scène"],
      "teacher_guidance": "Comment l'enseignant anime la discussion sans donner directement la solution (texte)",
      "transition": "Une courte phrase reliant cette scène à la suivante"
    }
  ],
  "discovery_moment": "Le moment de découverte du concept : comment les héros et les élèves sont parvenus au concept clé (récit)",
  "scientific_explanation": "L'explication scientifique après l'histoire : définitions, règles et informations essentielles organisées et précises, adaptées au niveau",
  "lesson_connection": "Le lien entre l'histoire et la leçon : comment chaque élément de l'histoire illustre un concept de la leçon",
  "practical_activity": "Une courte activité pratique réalisée individuellement ou en groupes, utilisant le concept pour résoudre un problème similaire",
  "final_challenge": "Le défi final : une nouvelle situation nécessitant le même concept mais dans un contexte différent",
  "evaluation": {
    "recall": "Une question de mémorisation",
    "understanding": "Une question de compréhension",
    "application": "Une question d'application",
    "analysis": "Une question de réflexion et d'analyse"
  },
  "summary": "Un court résumé que l'enseignant ou les élèves récitent à la fin du cours et qui contient l'idée principale de la leçon",
  "assumptions": "Toute hypothèse pédagogique utilisée (si certaines données de la leçon manquaient), ou texte vide"
}

Notes JSON :
- Le tableau scenes doit contenir entre 3 et 6 scènes.
- Toutes les valeurs doivent être des chaînes ou des tableaux de chaînes.
- Si certaines données manquent, utilisez la meilleure hypothèse pédagogique et mentionnez-la dans assumptions.

Retournez uniquement le JSON, sans aucun texte supplémentaire.
PROMPT;
        } elseif ($language === 'de') {
            return <<<PROMPT
Sie sind ein pädagogischer Experte im Schreiben sinnvoller Bildungs geschichten, die die Konzepte einer Lektion in eine fesselnde Entdeckungsreise verwandeln.

Aufgabe: Wandeln Sie den bereitgestellten Lektionsinhalt in **eine einzige pädagogische Geschichte** um, die fesselnd und altersgerecht ist, sodass die Konzepte der Lektion ein wesentlicher Bestandteil der Handlung sind und die Helden diese Konzepte verstehen oder anwenden müssen, um das Problem zu lösen.

{$ageGuidance}

⚠️ Verbindliche Regeln (buchstabengetreu befolgen):
1. Der Held sollte nah am Alter und den Interessen der Schüler sein, oder verwenden Sie eine zur Fachrichtung passende Figur (Schüler, Erfinder, Entdecker, Programmierer, Wissenschaftler, Roboter, Detektiv oder einfache fiktive Figur).
2. Beginnen Sie die Geschichte mit einer spannenden Situation oder einem klaren Problem — nicht mit direkten Definitionen oder Theorie.
3. Das Problem muss direkt mit dem Konzept der Lektion verbunden sein und nur durch Entdeckung oder Anwendung des Inhalts durch die Schüler lösbar sein.
4. Teilen Sie die Geschichte in kurze, aufeinanderfolgende Szenen; jede Szene erklärt ein einziges Konzept oder einen Lernschritt.
5. Beziehen Sie fehlerhafte Versuche oder falsche Entscheidungen ein und zeigen Sie deren Folgen, damit die Schüler den Fehler entdecken und die Korrektur vorschlagen.
6. Halten Sie die Erzählung an geeigneten Stellen an und fügen Sie interaktive Fragen des Lehrers ein (Was wird passieren? / Welchen Fehler beging der Held? / Welche Wahl ist die beste und warum? / Wie lässt sich das Problem lösen? / Was geschähe, wenn sich diese Bedingung änderte?).
7. Geben Sie die wissenschaftliche Definition nicht zu Beginn — lassen Sie die Schüler die Idee durch die Ereignisse entdecken, und präsentieren Sie die Definition oder Regel nach der Entdeckung klar.
8. Verwenden Sie eine altersgerechte, einfache Sprache, bei der die wissenschaftliche Genauigkeit erhalten bleibt, ohne Konzepte falsch zu vereinfachen.
9. Machen Sie die Ereignisse realistisch oder in ihrer Welt logisch; vermeiden Sie lange Details, die dem Lernziel nicht dienen.
10. Verknüpfen Sie jeden Teil mit einem klaren Lernziel; fügen Sie keine Figuren oder Ereignisse hinzu, die das Verständnis nicht unterstützen.
11. Verwenden Sie neue Fachbegriffe im Kontext der Geschichte und erklären Sie ihre Bedeutung dann einfach.
12. Beenden Sie mit einem neuen Problem oder einer Herausforderung, bei der die Schüler das Konzept selbst anwenden, um den Lerntransfer zu prüfen.
13. Die Geschichte darf keine bloße nacherzählte Zusammenfassung der Lektion sein; sie muss enthalten: Problem, Versuch, Irrtum, Entdeckung, Lösung, Anwendung.
14. Fügen Sie keine im Lektionsinhalt fehlenden Informationen hinzu, es seien denn sie sind notwendig und wissenschaftlich korrekt.
15. Verwenden Sie keine altersunangemessenen, beängstigenden oder gewalttätigen Situationen.
16. Passen Sie die Dauer der Geschichte und der Aktivitäten an die Dauer der Unterrichtsstunde an und bewahren Sie eine klare logische Abfolge zwischen Problem, Konzept und Lösung. (Bei umfangreichem Inhalt konzentrieren Sie sich auf die Kernkonzepte in einer kohärenten Geschichte.)

📝 Erforderliches Ausgabeformat (striktes JSON):
Geben Sie **eine einzige Geschichte** mit dieser Struktur zurück, der gesamte Text in der Antwortsprache:

{
  "title": "Ein kurzer, eingängiger, zur Lektion passender Titel",
  "learning_goal": "Ein Lernziel in einem Satz: Was die Schüler durch die Geschichte verstehen werden",
  "characters": "Die Figuren mit einer kurzen Beschreibung der Rolle jeder Figur (Text)",
  "setting": "Kurze Beschreibung von Ort und Zeit (die Umgebung der Geschichte)",
  "opening": "Die Eröffnungssituation: ein fesselnder Beginn, der Neugier weckt und das Hauptproblem aufzeigt (Erzählung)",
  "scenes": [
    {
      "number": 1,
      "title": "Titel der Szene",
      "narrative": "Erzählung der Ereignisse der Szene, einfacher und fesselnder Stil (Text)",
      "concept": "Das pädagogische Konzept, das diese Szene vermittelt (kurzer Text)",
      "questions": ["Eine oder mehrere interaktive Fragen zur Förderung von Vorhersage, Reflexion und Diskussion"],
      "expected_answers": ["Die wichtigsten Antworten, die die Schüler auf die Fragen der Szene geben könnten"],
      "teacher_guidance": "Wie der Lehrer die Diskussion leitet, ohne direkt die Lösung zu geben (Text)",
      "transition": "Ein kurzer Satz, der diese Szene mit der nächsten verbindet"
    }
  ],
  "discovery_moment": "Der Moment der Konzeptentdeckung: wie die Helden und die Schüler zum Kernkonzept gelangten (Erzählung)",
  "scientific_explanation": "Die wissenschaftliche Erklärung nach der Geschichte: geordnete, präzise Definitionen, Regeln und Kerninformationen, altersgerecht",
  "lesson_connection": "Die Verbindung zwischen Geschichte und Lektion: wie jedes Element der Geschichte ein Konzept der Lektion veranschaulicht",
  "practical_activity": "Eine kurze praktische Aktivität, einzeln oder in Gruppen, bei der das Konzept genutzt wird, um ein ähnliches Problem zu lösen",
  "final_challenge": "Die Schluss herausforderung: eine neue Situation, die dasselbe Konzept in einem anderen Kontext erfordert",
  "evaluation": {
    "recall": "Eine Erinnerungsfrage",
    "understanding": "Eine Verständnisfrage",
    "application": "Eine Anwendungsfrage",
    "analysis": "Eine Denk- und Analysefrage"
  },
  "summary": "Eine kurze Zusammenfassung, die der Lehrer oder die Schüler am Ende der Stunde vortragen und die die Hauptidee der Lektion enthält",
  "assumptions": "Alle verwendeten pädagogischen Annahmen (falls einige Lektionsdaten fehlten), oder leerer Text"
}

JSON-Hinweise:
- Das Array scenes soll 3 bis 6 Szenen enthalten.
- Alle Werte sollen Zeichenketten oder Arrays von Zeichenketten sein.
- Falls Daten fehlen, verwenden Sie die beste pädagogische Annahme und erwähnen Sie sie unter assumptions.

Geben Sie ausschließlich JSON zurück, ohne zusätzlichen Text.
PROMPT;
        } else {
            return <<<PROMPT
You are a pedagogical expert in writing meaningful educational stories that turn a lesson's concepts into a captivating journey of discovery.

Task: Transform the provided lesson content into **a single educational story** that is engaging and age-appropriate, so that the lesson's concepts are an essential part of the plot, and the heroes must understand or apply these concepts to solve the problem.

{$ageGuidance}

⚠️ Mandatory rules (follow them literally):
1. Make the hero close to the students' age and interests, or use a character fitting the subject (student, inventor, explorer, programmer, scientist, robot, detective, or simple fictional character).
2. Start the story with an exciting situation or a clear problem — do not start with direct definitions or theory.
3. The problem must be directly tied to the lesson's concept and solvable only through the students discovering or applying the lesson's content.
4. Divide the story into short, sequential scenes; each scene explains a single concept or a single learning step.
5. Include wrong attempts or incorrect decisions, then show their consequences, so students discover the error and propose a correction.
6. Pause the story at suitable points and add interactive questions the teacher asks the students (What do you think will happen? / What mistake did the hero make? / Which choice is best and why? / How can the problem be solved? / What would happen if this condition changed?).
7. Do not give the scientific definition at the start — let students discover the idea through events first, then present the definition or scientific rule clearly after the discovery.
8. Use simple, age-appropriate language while preserving scientific accuracy and avoiding oversimplification.
9. Make the events realistic or logical within their world; avoid long details that do not serve the lesson's goal.
10. Link every part to a clear learning objective; add no characters or events that do not help understand the content.
11. Use new terms within the story's context, then explain their meaning simply.
12. End with a new problem or challenge where students apply the concept themselves, to verify transfer of learning.
13. The story must not be a mere narrative summary of the lesson; it must include: problem, attempt, error, discovery, solution, application.
14. Do not add information absent from the lesson content unless necessary and scientifically correct.
15. Do not use scary, violent, or age-inappropriate situations.
16. Fit the story's and activities' duration to the class length, and keep a clear logical sequence between problem, concept, and solution. (If the content is large, focus on the core concepts in a single coherent story.)

📝 Required output format (strict JSON):
Return **a single story** with this structure, all text in the response language:

{
  "title": "A short, catchy title related to the lesson",
  "learning_goal": "One learning objective in a single sentence: what students will understand through the story",
  "characters": "The characters with a brief description of each character's role (text)",
  "setting": "A brief description of the place and time (the story's environment)",
  "opening": "The opening situation: a captivating start that arouses curiosity and exposes the main problem (narrative)",
  "scenes": [
    {
      "number": 1,
      "title": "Scene title",
      "narrative": "Narrative of the scene's events, simple and engaging style (text)",
      "concept": "The educational concept this scene presents (short text)",
      "questions": ["One or more interactive questions to encourage prediction, thinking, and discussion"],
      "expected_answers": ["The main answers students might give to the scene's questions"],
      "teacher_guidance": "How the teacher runs the discussion without giving the solution directly (text)",
      "transition": "A short sentence linking this scene to the next"
    }
  ],
  "discovery_moment": "The moment of concept discovery: how the heroes and students reached the key concept (narrative)",
  "scientific_explanation": "The scientific explanation after the story: organized, precise definitions, rules, and essential information, suited to the level",
  "lesson_connection": "The link between the story and the lesson: how each element of the story illustrates a concept of the lesson",
  "practical_activity": "A short practical activity done individually or in groups, using the concept to solve a similar problem",
  "final_challenge": "The final challenge: a new situation requiring the same concept but in a different context",
  "evaluation": {
    "recall": "One recall question",
    "understanding": "One understanding question",
    "application": "One application question",
    "analysis": "One thinking-and-analysis question"
  },
  "summary": "A short summary the teacher or students recite at the end of the class, containing the main idea of the lesson",
  "assumptions": "Any pedagogical assumptions used (if some lesson data was missing), or empty text"
}

JSON notes:
- The scenes array should contain between 3 and 6 scenes.
- All values must be strings or arrays of strings.
- If some data is missing, use the best pedagogical assumption and mention it under assumptions.

Return JSON only, without any additional text.
PROMPT;
        }
    }

    /**
     * بناء وصف الفئة العمرية الموجَّه إلى الـ AI.
     * يُطبَّع على مستوى اللغة وعمق المفاهيم وطول القصة ونمط الشخصيات.
     *
     * @param string   $language
     * @param int|null $studentAge
     */
    private static function buildAgeGuidance($language, $studentAge) {
        $age = ($studentAge !== null) ? max(4, min(25, (int)$studentAge)) : null;

        if ($age === null) {
            $profileByLang = [
                'ar' => "🎯 الفئة العمرية المستهدفة: غير محددة — استخدم مستوىً متوسطاً مناسباً لطلاب المدرسة، واجعل اللغة واضحة وبسيطة وقابلةً للتكييف.",
                'fr' => "🎯 Tranche d'âge cible : non précisée — utilisez un niveau intermédiaire adapté aux élèves, avec une langue claire, simple et adaptable.",
                'de' => "🎯 Zielaltersgruppe: nicht angegeben — verwenden Sie ein mittleres, schülergerechtes Niveau mit klarer, einfacher und anpassbarer Sprache.",
                'en' => "🎯 Target age group: unspecified — use an intermediate level suited to school students, with clear, simple, adaptable language.",
            ];
            return $profileByLang[$language] ?? $profileByLang['en'];
        }

        // تصنيف الفئة العمرية وتحديد خصائصها.
        if ($age <= 8) {
            $profileByLang = [
                'ar' => "🎯 الفئة العمرية المستهدفة: {$age} سنوات (أطفال صغار، 4-8 سنوات). استخدم جُملاً قصيرةً جداً وكلماتٍ بسيطةً ومألوفة، شخصياتٍ خياليةٍ أو حيوانيةٍ لطيفة، مفاهيمَ ملموسةً ومحددةً (تجنّب التجريد)، قصةً قصيرةً (3-4 مشاهد فقط)، وأسئلةً مباشرةً جداً (توقّع نعم/لا أو اختيار من اثنين).",
                'fr' => "🎯 Tranche d'âge cible : {$age} ans (jeunes enfants, 4-8 ans). Utilisez des phrases très courtes et un vocabulaire simple et familier, des personnages imaginatifs ou des animaux sympathiques, des concepts concrets (évitez l'abstraction), une histoire courte (3-4 scènes seulement), et des questions très directes (oui/non ou choix entre deux).",
                'de' => "🎯 Zielaltersgruppe: {$age} Jahre (kleine Kinder, 4-8). Verwenden Sie sehr kurze Sätze und einfachen, vertrauten Wortschatz, imaginative oder tierische freundliche Figuren, konkrete Konzepte (Vermeiden Sie Abstraktion), eine kurze Geschichte (nur 3-4 Szenen), und sehr direkte Fragen (Ja/Nein oder Wahl zwischen zwei).",
                'en' => "🎯 Target age group: {$age} years (young children, 4-8). Use very short sentences and simple, familiar vocabulary, imaginative or friendly animal characters, concrete concepts (avoid abstraction), a short story (only 3-4 scenes), and very direct questions (yes/no or a choice between two).",
            ];
        } elseif ($age <= 11) {
            $profileByLang = [
                'ar' => "🎯 الفئة العمرية المستهدفة: {$age} سنة (أطفال، 9-11 سنة). استخدم جُملاً واضحة ومباشرة، شخصياتٍ قريبةً من حياة الطفل (طالب، صديق، حيوان، مخترع صغير)، مزجاً بين المحسوس والتجريد البسيط، قصةً متوسطة الطول (3-5 مشاهد)، وأسئلةً تطلب التوقع والتفسير البسيط.",
                'fr' => "🎯 Tranche d'âge cible : {$age} ans (enfants, 9-11 ans). Utilisez des phrases claires et directes, des personnages proches de la vie de l'enfant (élève, ami, animal, petit inventeur), un mélange de concret et d'abstraction simple, une histoire de longueur moyenne (3-5 scènes), et des questions demandant prédiction et explication simple.",
                'de' => "🎯 Zielaltersgruppe: {$age} Jahre (Kinder, 9-11). Verwenden Sie klare, direkte Sätze, Figuren aus der Lebenswelt des Kindes (Schüler, Freund, Tier, kleiner Erfinder), eine Mischung aus Konkretem und einfacher Abstraktion, eine mittellange Geschichte (3-5 Szenen), und Fragen nach Vorhersage und einfacher Erklärung.",
                'en' => "🎯 Target age group: {$age} years (children, 9-11). Use clear, direct sentences, characters close to a child's life (student, friend, animal, little inventor), a mix of concrete and simple abstraction, a medium-length story (3-5 scenes), and questions asking for prediction and simple explanation.",
            ];
        } elseif ($age <= 14) {
            $profileByLang = [
                'ar' => "🎯 الفئة العمرية المستهدفة: {$age} سنة (مراهقون مبكّرون، 12-14 سنة). استخدم لغةً أكثر نضجاً مع الحفاظ على الوضوح، شخصياتٍ يتقمّصون أدواراً (مبرمج، مستكشف، محقق، عالم)، مفاهيمَ مجرّدةً مدعومةً بأمثلة، قصةً منظّمة (4-6 مشاهد)، وأسئلةً تطلب التحليل وربط الأسباب بالنتائج.",
                'fr' => "🎯 Tranche d'âge cible : {$age} ans (pré-adolescents, 12-14 ans). Utilisez un langage plus mature tout en restant clair, des personnages jouant des rôles (programmeur, explorateur, détective, scientifique), des concepts abstraits étayés d'exemples, une histoire structurée (4-6 scènes), et des questions demandant analyse et causalité.",
                'de' => "🎯 Zielaltersgruppe: {$age} Jahre (Frühe Jugend, 12-14). Verwenden Sie reifere, aber klare Sprache, Figuren in Rollen (Programmierer, Entdecker, Detektiv, Wissenschaftler), durch Beispiele gestützte abstrakte Konzepte, eine strukturierte Geschichte (4-6 Szenen), und Fragen nach Analyse und Ursache-Wirkung.",
                'en' => "Target age group: {$age} years (early adolescents, 12-14). Use more mature yet clear language, characters in roles (programmer, explorer, detective, scientist), abstract concepts supported by examples, a structured story (4-6 scenes), and questions asking for analysis and cause-and-effect reasoning.",
            ];
        } else {
            $profileByLang = [
                'ar' => "🎯 الفئة العمرية المستهدفة: {$age} سنة (مراهقون/شباب، 15-25 سنة). استخدم لغةً ناضجةً ودقيقةً علمياً، شخصياتٍ معقّدةً (عالم، مهندس، رائد أعمال، باحث)، مفاهيمَ مجرّدةً ومعمّقةً، قصةً منظّمةً (4-6 مشاهد)، وأسئلةً تطلب التفكير النقدي والتقييم وطرح البدائل.",
                'fr' => "🎯 Tranche d'âge cible : {$age} ans (adolescents/jeunes, 15-25 ans). Utilisez un langage mature et scientifiquement précis, des personnages complexes (scientifique, ingénieur, entrepreneur, chercheur), des concepts abstraits et approfondis, une histoire structurée (4-6 scènes), et des questions demandant pensée critique, évaluation et alternatives.",
                'de' => "🎯 Zielaltersgruppe: {$age} Jahre (Jugendliche/Junge Erwachsene, 15-25). Verwenden Sie reife, wissenschaftlich präzise Sprache, komplexe Figuren (Wissenschaftler, Ingenieur, Unternehmer, Forscher), vertiefte abstrakte Konzepte, eine strukturierte Geschichte (4-6 Szenen), und Fragen nach kritischem Denken, Bewertung und Alternativen.",
                'en' => "🎯 Target age group: {$age} years (teens/young adults, 15-25). Use mature, scientifically precise language, complex characters (scientist, engineer, entrepreneur, researcher), deep abstract concepts, a structured story (4-6 scenes), and questions demanding critical thinking, evaluation, and alternatives.",
            ];
        }

        return $profileByLang[$language] ?? $profileByLang['en'];
    }

    /**
     * Prompt مخصص لتوليد محتوى الشرائح التقديمية (باور بوينت)
     * يولّد محتوى الدرس الفعلي مقسّماً إلى شرائح — ليس خطوات عمل المعلم
     */
    public static function getPowerPointSlidesPrompt(string $language, int $maxSlides = 20): string
    {
        // $maxSlides is a soft guide; the AI should cover ALL content regardless
        $slideGuide = max(5, $maxSlides);

        // تعليمة موحّدة للمحتوى المحدود (تُدمج في كل الـ prompts)
        $limitedContentNote_ar = "\n\nℹ️ ملاحظة مهمة: إذا كان المحتوى المقدّم محدوداً، أنشئ عدداً أقل من الشرائح وابنِه على ما هو متاح فعلاً. لا تخترع معلومات غير موجودة في المادة. اجعل كل نقطة تعتمد على نص المحتوى المقدّم حصراً.";
        $limitedContentNote_fr = "\n\nℹ️ Note importante : Si le contenu fourni est limité, générez un nombre réduit de diapositives basées uniquement sur ce qui est disponible. N'inventez pas d'informations absentes du contenu fourni.";
        $limitedContentNote_de = "\n\nℹ️ Wichtiger Hinweis: Wenn der bereitgestellte Inhalt begrenzt ist, erstellen Sie weniger Folien, die nur auf dem verfügbaren Inhalt basieren. Erfinden Sie keine Informationen.";
        $limitedContentNote_en = "\n\nℹ️ Important note: If the provided content is limited, generate fewer slides based only on what is available. Do NOT invent information absent from the provided content.";

        if ($language === 'ar') {
            return <<<PROMPT
أنت خبير تربوي متخصص في إنشاء العروض التقديمية التعليمية (باوربوينت) للطلاب.

المطلوب: تحليل المحتوى التعليمي المقدم وتقسيمه إلى شرائح عرض تقديمي احترافية وشاملة.

⚠️ تعليمات مهمة جداً:
1. الشرائح للطلاب وليست لخطوات عمل المعلم — لا تذكر "دور المعلم" أو "دور المتعلم" أو "استراتيجيات التدريس" في المحتوى
2. **غطِّ جميع المحتوى الوارد في المادة المقدمة دون حذف أي فكرة أو معلومة** — لا تقتصر على ملخص سريع
3. قسّم الدرس إلى شرائح منطقية بحيث كل شريحة تغطي فكرة واحدة واضحة
4. عدد الشرائح المتوقع: {$slideGuide} شريحة أو أكثر حسب حجم المحتوى — أضف شرائح إضافية عند الحاجة لاستيعاب كل المادة
5. كل شريحة: عنوان واضح + من 4 إلى 7 نقاط محتوى حقيقية ومفيدة — لا تقتصر على نقطتين أو ثلاث
6. استخدم نقاطاً قصيرة ومباشرة مناسبة للعرض التقديمي
7. ترتيب الشرائح الإلزامي:
   أ) شريحة الأهداف التعليمية
   ب) شريحة التمهيد أو المقدمة
   ج) شرائح المحتوى الرئيسي (بالتفصيل الكامل)
   د) شريحة التعريفات والمصطلحات إن وُجدت
   هـ) شرائح الأمثلة والتطبيقات
   و) شريحة الملخص
   ز) **شريحة أسئلة التقييم** (إلزامية دائماً في النهاية)
8. شريحة أسئلة التقييم يجب أن تحتوي على 5 أسئلة متنوعة (اختيار من متعدد / صح وخطأ / سؤال مفتوح) مستمدة من محتوى الدرس

أنواع الشرائح المتاحة:
- "objectives" → أهداف الدرس
- "intro" → مقدمة أو تمهيد
- "content" → شريحة محتوى رئيسية
- "definition" → تعريفات ومصطلحات
- "example" → أمثلة وتطبيقات
- "comparison" → مقارنة أو جدول (استخدم حقل "table" عند المقارنة بين عنصرين أو أكثر)
- "steps" → خطوات أو مراحل متسلسلة
- "chart" → رسم بياني للبيانات الرقمية (اختياري — فقط عند وجود بيانات رقمية حقيقية يمكن تخطيطها)
- "summary" → ملخص وخلاصة
- "questions" → أسئلة تقييم الدرس (إلزامية في النهاية)

📋 قاعدة خاصة بنوع "comparison":
- إذا كان المحتوى يقدّم مقارنة بين عنصرين أو أكثر (مميزات/عيوب، أنواع/خصائص، خيار أ/خيار ب)، استخدم حقل "table" الاختياري لإرسال بيانات جدول حقيقي بدلاً من نقاط.
- الحد الأقصى: 5 أعمدة و10 صفوف.
- حقل "table" اختياري تماماً — إذا لم يوجد جدول مناسب، استخدم "points" كالمعتاد.
- لا تخلط بين "table" و "points" في نفس الشريحة؛ استخدم أحدهما فقط.

📈 قاعدة خاصة بنوع "chart":
- استخدمه فقط عندما يقدّم المحتوى بيانات رقمية حقيقية (إحصاءات، نسب، توزيعات، تطورات زمنية) يمكن تخطيطها بصرياً.
- لا تخترع أرقاماً غير موجودة في المادة؛ استخدم بيانات الدرس الأصلية فقط.
- حقل "kind": "bar" (أعمدة)، "pie" (دائري)، أو "line" (خطي). اختر النوع الأنسب للبيانات.
- Pie (دائري): استخدم سلسلة واحدة فقط (للنسب المئوية).
- Bar/Line: استخدم 1–5 سلاسل كحد أقصى.
- الحد الأقصى 12 فئة لتفادي ازدحام المحور.
- نوع "chart" اختياري تماماً — لا تستخدمه إن لم توجد بيانات رقمية مناسبة.

أعد النتيجة بصيغة JSON فقط بالهيكل التالي:

{
    "slides": [
        {
            "type": "objectives",
            "title": "الأهداف التعليمية",
            "points": [
                "بعد هذا الدرس سيكون الطالب قادراً على ...",
                "..."
            ]
        },
        {
            "type": "content",
            "title": "عنوان المفهوم أو الموضوع",
            "points": [
                "شرح تفصيلي للفكرة الأولى من المادة",
                "شرح الفكرة الثانية...",
                "مثال أو تفصيل إضافي...",
                "..."
            ]
        },
        {
            "type": "comparison",
            "title": "مقارنة بين ...",
            "table": {
                "headers": ["العنصر", "الخيار أ", "الخيار ب"],
                "rows": [
                    ["الخاصية 1", "قيمة", "قيمة"],
                    ["الخاصية 2", "قيمة", "قيمة"]
                ]
            }
        },
        {
            "type": "chart",
            "title": "توزيع البيانات ...",
            "chart": {
                "kind": "bar",
                "categories": ["الصنف أ", "الصنف ب", "الصنف ج"],
                "series": [
                    {"name": "السلسلة 1", "values": [10, 25, 40]}
                ]
            }
        },
        {
            "type": "summary",
            "title": "ملخص الدرس",
            "points": [
                "أبرز ما تعلمناه...",
                "..."
            ]
        },
        {
            "type": "questions",
            "title": "أسئلة التقييم",
            "points": [
                "س1: [سؤال اختيار من متعدد] — أ) ... ب) ... ج) ... د) ...",
                "س2: [سؤال صح/خطأ] — هل ... ؟",
                "س3: [سؤال مفتوح] — اشرح بكلماتك ...",
                "س4: [سؤال تطبيقي] — طبّق ما تعلمته على ...",
                "س5: [سؤال تحليلي] — قارن بين ..."
            ]
        }
    ]
}

أرجع JSON فقط بدون أي نص إضافي.
PROMPT . $limitedContentNote_ar;
        } elseif ($language === 'fr') {
            return <<<PROMPT
Vous êtes un expert pédagogique spécialisé dans la création de présentations éducatives (PowerPoint) pour les élèves.

Tâche : Analyser le contenu éducatif fourni et le diviser en diapositives de présentation professionnelles et complètes.

⚠️ Instructions importantes :
1. Les diapositives sont destinées aux élèves, pas aux étapes de travail de l'enseignant.
2. **Couvrez TOUT le contenu fourni sans omettre aucune idée ou information.**
3. Nombre de diapositives : {$slideGuide} ou plus selon le volume du contenu.
4. Chaque diapositive : un titre clair + 4 à 7 points de contenu réels et utiles.
5. Ordre obligatoire : objectifs → introduction → contenu détaillé → définitions → exemples → résumé → questions d'évaluation.
6. La dernière diapositive doit être une diapositive "questions" avec 5 questions d'évaluation variées.

Types disponibles : "objectives", "intro", "content", "definition", "example", "comparison", "steps", "chart", "summary", "questions"

📋 Règle pour le type "comparison" :
- Si le contenu présente une comparaison entre deux éléments ou plus, utilisez le champ optionnel "table" pour envoyer un véritable tableau au lieu de points.
- Limites : 5 colonnes maximum, 10 lignes maximum.
- Le champ "table" est facultatif ; s'il n'y a pas de tableau pertinent, utilisez "points".
- Ne mélangez pas "table" et "points" dans la même diapositive.

📈 Règle pour le type "chart" :
- Utilisez-le uniquement lorsque le contenu présente des données numériques réelles (statistiques, pourcentages, distributions, évolutions temporelles) qui peuvent être tracées visuellement.
- N'inventez pas de chiffres absents du contenu ; utilisez uniquement les données originales de la leçon.
- Champ "kind" : "bar" (colonnes), "pie" (circulaire), ou "line" (linéaire).
- Pie : une seule série (pour des pourcentages).
- Bar/Line : 1 à 5 séries maximum, 12 catégories maximum.
- Le type "chart" est facultatif ; ne l'utilisez pas sans données numériques appropriées.

Retournez uniquement le JSON suivant :

{
    "slides": [
        {
            "type": "objectives",
            "title": "Objectifs d'apprentissage",
            "points": ["À la fin de cette leçon, l'élève sera capable de ...", "..."]
        },
        {
            "type": "content",
            "title": "Titre du concept",
            "points": ["Explication détaillée de la première idée", "Deuxième idée...", "Exemple ou détail supplémentaire...", "..."]
        },
        {
            "type": "comparison",
            "title": "Comparaison entre ...",
            "table": {
                "headers": ["Élément", "Option A", "Option B"],
                "rows": [
                    ["Caractéristique 1", "valeur", "valeur"],
                    ["Caractéristique 2", "valeur", "valeur"]
                ]
            }
        },
        {
            "type": "chart",
            "title": "Distribution des données ...",
            "chart": {
                "kind": "bar",
                "categories": ["Catégorie A", "Catégorie B", "Catégorie C"],
                "series": [
                    {"name": "Série 1", "values": [10, 25, 40]}
                ]
            }
        },
        {
            "type": "summary",
            "title": "Résumé de la leçon",
            "points": ["Ce que nous avons appris...", "..."]
        },
        {
            "type": "questions",
            "title": "Questions d'évaluation",
            "points": [
                "Q1: [QCM] — a) ... b) ... c) ... d) ...",
                "Q2: [Vrai/Faux] — Est-ce que ... ?",
                "Q3: [Question ouverte] — Expliquez avec vos mots ...",
                "Q4: [Application] — Appliquez ce que vous avez appris à ...",
                "Q5: [Analyse] — Comparez entre ..."
            ]
        }
    ]
}

Retournez uniquement le JSON sans texte supplémentaire.
PROMPT . $limitedContentNote_fr;
        } elseif ($language === 'de') {
            return <<<PROMPT
Sie sind ein pädagogischer Experte, der sich auf die Erstellung von Bildungspräsentationen (PowerPoint) für Schüler spezialisiert hat.

Aufgabe: Analysieren Sie den bereitgestellten Lerninhalt und teilen Sie ihn in vollständige, professionelle Präsentationsfolien auf.

⚠️ Wichtige Anweisungen:
1. Die Folien sind für Schüler bestimmt, nicht für Arbeitsschritte des Lehrers.
2. **Decken Sie den GESAMTEN bereitgestellten Inhalt ab — lassen Sie keine Idee oder Information aus.**
3. Anzahl der Folien: {$slideGuide} oder mehr je nach Inhaltsvolumen.
4. Jede Folie: ein klarer Titel + 4 bis 7 echte, nützliche Inhaltspunkte.
5. Obligatorische Reihenfolge: Ziele → Einführung → detaillierter Inhalt → Definitionen → Beispiele → Zusammenfassung → Bewertungsfragen.
6. Die letzte Folie muss eine "questions"-Folie mit 5 gemischten Bewertungsfragen sein.

Verfügbare Typen: "objectives", "intro", "content", "definition", "example", "comparison", "steps", "chart", "summary", "questions"

📋 Regel für den Typ "comparison":
- Wenn der Inhalt einen Vergleich zwischen zwei oder mehr Elementen darstellt, verwenden Sie das optionale Feld "table", um eine echte Tabelle zu senden statt Punkten.
- Grenzen: maximal 5 Spalten und 10 Zeilen.
- Das Feld "table" ist optional; wenn keine passende Tabelle vorhanden ist, verwenden Sie "points".
- Mischen Sie "table" und "points" nicht in derselben Folie.

📈 Regel für den Typ "chart":
- Verwenden Sie ihn nur, wenn der Inhalt echte numerische Daten (Statistiken, Prozentsätze, Verteilungen, zeitliche Entwicklungen) enthält, die visuell dargestellt werden können.
- Erfinden Sie keine Zahlen, die im Inhalt nicht vorkommen; verwenden Sie nur die Originaldaten der Lektion.
- Feld "kind": "bar" (Säulen), "pie" (Kreis), oder "line" (Linie).
- Pie: nur eine Serie (für Prozentsätze).
- Bar/Line: maximal 1–5 Serien und 12 Kategorien.
- Der Typ "chart" ist optional; verwenden Sie ihn nicht ohne geeignete numerische Daten.

Geben Sie nur das folgende JSON zurück:

{
    "slides": [
        {
            "type": "objectives",
            "title": "Lernziele",
            "points": ["Nach dieser Lektion wird der Schüler in der Lage sein ...", "..."]
        },
        {
            "type": "content",
            "title": "Konzepttitel",
            "points": ["Detaillierte Erklärung der ersten Idee", "Zweite Idee...", "Beispiel oder zusätzliches Detail...", "..."]
        },
        {
            "type": "comparison",
            "title": "Vergleich zwischen ...",
            "table": {
                "headers": ["Element", "Option A", "Option B"],
                "rows": [
                    ["Eigenschaft 1", "Wert", "Wert"],
                    ["Eigenschaft 2", "Wert", "Wert"]
                ]
            }
        },
        {
            "type": "chart",
            "title": "Datenverteilung ...",
            "chart": {
                "kind": "bar",
                "categories": ["Kategorie A", "Kategorie B", "Kategorie C"],
                "series": [
                    {"name": "Serie 1", "values": [10, 25, 40]}
                ]
            }
        },
        {
            "type": "summary",
            "title": "Zusammenfassung der Lektion",
            "points": ["Was wir gelernt haben...", "..."]
        },
        {
            "type": "questions",
            "title": "Bewertungsfragen",
            "points": [
                "F1: [MC] — a) ... b) ... c) ... d) ...",
                "F2: [Wahr/Falsch] — Ist es wahr, dass ...?",
                "F3: [Offene Frage] — Erklären Sie mit eigenen Worten ...",
                "F4: [Anwendung] — Wenden Sie das Gelernte auf ... an",
                "F5: [Analyse] — Vergleichen Sie zwischen ..."
            ]
        }
    ]
}

Geben Sie nur JSON ohne zusätzlichen Text zurück.
PROMPT . $limitedContentNote_de;
        } else {
            // English (default)
            return <<<PROMPT
You are an educational expert specializing in creating educational presentations (PowerPoint) for students.

Task: Analyze the provided educational content and divide it into comprehensive, professional presentation slides.

⚠️ Important Instructions:
1. Slides are for students, NOT teacher work steps — do NOT include "teacher role", "student role", or "teaching strategies".
2. **Cover ALL content in the provided material without omitting any idea or information.**
3. Number of slides: {$slideGuide} or more depending on content volume — add extra slides as needed.
4. Each slide: a clear title + 4 to 7 real and useful content points — do not limit to 2-3 points.
5. Use short, direct bullet points suitable for presentation.
6. Mandatory order: objectives → introduction → detailed content → definitions → examples → summary → assessment questions.
7. The last slide must be an assessment "questions" slide with 5 varied questions from the lesson content.

Available slide types: "objectives", "intro", "content", "definition", "example", "comparison", "steps", "chart", "summary", "questions"

📋 Rule for "comparison" type:
- When the content presents a comparison between two or more items, use the optional "table" field to send a real table instead of points.
- Limits: maximum 5 columns and 10 rows.
- The "table" field is optional; if no suitable table exists, use "points".
- Do not mix "table" and "points" in the same slide.

📈 Rule for "chart" type:
- Use it only when the content presents real numeric data (statistics, percentages, distributions, time series) that can be plotted visually.
- Do NOT invent numbers absent from the content; use only original lesson data.
- Field "kind": "bar" (columns), "pie" (circular), or "line" (linear).
- Pie: a single series only (for percentages).
- Bar/Line: 1 to 5 series maximum, 12 categories maximum.
- The "chart" type is optional; do not use it without suitable numeric data.

Return only the following JSON:

{
    "slides": [
        {
            "type": "objectives",
            "title": "Learning Objectives",
            "points": ["By the end of this lesson, students will be able to ...", "..."]
        },
        {
            "type": "content",
            "title": "Concept Title",
            "points": ["Detailed explanation of first idea", "Second idea...", "Example or additional detail...", "..."]
        },
        {
            "type": "comparison",
            "title": "Comparison between ...",
            "table": {
                "headers": ["Element", "Option A", "Option B"],
                "rows": [
                    ["Feature 1", "value", "value"],
                    ["Feature 2", "value", "value"]
                ]
            }
        },
        {
            "type": "chart",
            "title": "Data distribution ...",
            "chart": {
                "kind": "bar",
                "categories": ["Category A", "Category B", "Category C"],
                "series": [
                    {"name": "Series 1", "values": [10, 25, 40]}
                ]
            }
        },
        {
            "type": "summary",
            "title": "Lesson Summary",
            "points": ["Key takeaways...", "..."]
        },
        {
            "type": "questions",
            "title": "Assessment Questions",
            "points": [
                "Q1: [MCQ] — a) ... b) ... c) ... d) ...",
                "Q2: [True/False] — Is it true that ...?",
                "Q3: [Open question] — Explain in your own words ...",
                "Q4: [Application] — Apply what you learned to ...",
                "Q5: [Analysis] — Compare between ..."
            ]
        }
    ]
}

Return JSON only without any additional text.
PROMPT . $limitedContentNote_en;
        }
    }
}
