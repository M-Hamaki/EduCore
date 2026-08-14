<?php
/**
 * Prompts الذكاء الاصطناعي لتوليد تحضير الدروس والاختبارات
 * AI Prompts for Lesson Preparation System
 */
require_once __DIR__ . '/AIPrompts/ContentPrompts.php';


class AIPrompts {
    
    use AIPromptsContentTrait;
    /**
     * الحصول على prompt تحضير الدرس
     */
    public static function getLessonPrepPrompt($language, $duration, $selectedElements = null, $selectedPhases = null) {
        $langText = $language === 'ar' ? 'العربية' : ($language === 'fr' ? 'Français' : ($language === 'de' ? 'Deutsch' : 'English'));
        
        // Default elements if none specified
        if ($selectedElements === null) {
            $selectedElements = ['objectives', 'strategies', 'lesson_phases', 'resources'];
        }
        
        // حساب توزيع الوقت بشكل ديناميكي
        $d = max(1, intval($duration));
        
        // بناء مراحل الدرس حسب اختيار المعلم
        $phasesJsonAr = self::buildPhasesJson($selectedPhases, $d, 'ar');
        $phasesJsonFr = self::buildPhasesJson($selectedPhases, $d, 'fr');
        $phasesJsonDe = self::buildPhasesJson($selectedPhases, $d, 'de');
        $phasesJsonEn = self::buildPhasesJson($selectedPhases, $d, 'en');
        
        // Build additional elements JSON based on selection
        $additionalElementsAr = self::buildAdditionalElementsJson($selectedElements, 'ar');
        $additionalElementsFr = self::buildAdditionalElementsJson($selectedElements, 'fr');
        $additionalElementsDe = self::buildAdditionalElementsJson($selectedElements, 'de');
        $additionalElementsEn = self::buildAdditionalElementsJson($selectedElements, 'en');
        
        if ($language === 'ar') {
            $additionalInstructions = self::getAdditionalInstructions($selectedElements, 'ar');
            return <<<PROMPT
أنت خبير تربوي متخصص في إعداد وتحضير الدروس. 

المطلوب: إنشاء تحضير درس احترافي ومنظم بناءً على المحتوى المقدم.

زمن الحصة: {$duration} دقيقة

⚠️ تعليمات مهمة:
1. اعتمد فقط على المحتوى المقدم دون إضافة معلومات خارجية
2. وزّع الوقت بذكاء على جميع عناصر التحضير بحيث يكون المجموع الكلي = {$duration} دقيقة بالضبط
3. في مراحل الدرس (lesson_phases) حيث يوجد "duration_minutes": "auto"، حدد الوقت المناسب لكل مرحلة بالدقائق (رقم صحيح) بناءً على رؤيتك التربوية وطبيعة المحتوى، بحيث يكون مجموع أوقات المراحل = {$duration} دقيقة. أعطِ مرحلة الشرح الوقت الأكبر
4. في كل مرحلة، يجب ملء حقل "teacher_role" بوصف تفصيلي لدور المعلم في تلك المرحلة، وحقل "student_role" بوصف تفصيلي لدور المتعلم. لا تتركهما فارغين أبداً
5. اجعل كل عنصر واضحاً ومحدداً
{$additionalInstructions}

📋 يجب أن يحتوي التحضير على العناصر التالية بصيغة JSON:

{
    "lesson_title": "عنوان الدرس",
    "objectives": {
        "cognitive": ["الأهداف المعرفية - قائمة من 3-5 أهداف"],
        "affective": ["الأهداف الوجدانية - قائمة من 2-3 أهداف"],
        "psychomotor": ["الأهداف المهارية - قائمة من 2-3 أهداف"]
    },
    "strategies": {
        "teaching_strategies": ["قائمة استراتيجيات التدريس المناسبة"],
        "active_learning": ["أساليب التعلم النشط المستخدمة"]
    },
    "lesson_phases": {$phasesJsonAr},
    "total_duration": {$duration},
    "resources_needed": ["الموارد والأدوات المطلوبة"]{$additionalElementsAr}
}

أرجع الإجابة بصيغة JSON فقط بدون أي نص إضافي.
PROMPT;
        } elseif ($language === 'fr') {
            $additionalInstructions = self::getAdditionalInstructions($selectedElements, 'fr');
            return <<<PROMPT
Vous êtes un expert pédagogique spécialisé dans la préparation des cours.

Tâche : Créer une préparation de cours professionnelle et organisée basée sur le contenu fourni.

Durée du cours : {$duration} minutes

⚠️ Instructions importantes :
1. Basez-vous uniquement sur le contenu fourni sans ajouter d'informations externes
2. Répartissez intelligemment le temps sur tous les éléments de la préparation pour un total de {$duration} minutes exactement
3. Dans les phases du cours (lesson_phases) où "duration_minutes": "auto", déterminez la durée appropriée en minutes (nombre entier) pour chaque phase selon votre expertise pédagogique et la nature du contenu, de sorte que le total des durées = {$duration} minutes. Accordez la plus grande durée à la phase d'explication
4. Pour chaque phase, remplissez le champ "teacher_role" avec une description détaillée du rôle de l'enseignant, et le champ "student_role" avec une description détaillée du rôle de l'élève. Ne les laissez jamais vides
5. Rendez chaque élément clair et précis
{$additionalInstructions}

📋 La préparation doit contenir les éléments suivants au format JSON :

{
    "lesson_title": "Titre du cours",
    "objectives": {
        "cognitive": ["Objectifs cognitifs - liste de 3-5 objectifs"],
        "affective": ["Objectifs affectifs - liste de 2-3 objectifs"],
        "psychomotor": ["Objectifs psychomoteurs - liste de 2-3 objectifs"]
    },
    "strategies": {
        "teaching_strategies": ["Liste des stratégies d'enseignement appropriées"],
        "active_learning": ["Méthodes d'apprentissage actif utilisées"]
    },
    "lesson_phases": {$phasesJsonFr},
    "total_duration": {$duration},
    "resources_needed": ["Ressources et outils nécessaires"]{$additionalElementsFr}
}

Retournez la réponse au format JSON uniquement sans texte supplémentaire.
PROMPT;
        } elseif ($language === 'de') {
            $additionalInstructions = self::getAdditionalInstructions($selectedElements, 'de');
            return <<<PROMPT
Sie sind ein erfahrener Pädagoge, spezialisiert auf Unterrichtsplanung und -vorbereitung.

Aufgabe: Erstellen Sie einen professionellen und strukturierten Unterrichtsplan basierend auf dem bereitgestellten Inhalt.

Unterrichtsdauer: {$duration} Minuten

⚠️ Wichtige Anweisungen:
1. Stützen Sie sich ausschließlich auf den bereitgestellten Inhalt, ohne externe Informationen hinzuzufügen
2. Verteilen Sie die Zeit intelligent auf alle Unterrichtselemente, sodass die Gesamtzeit genau {$duration} Minuten beträgt
3. In den Unterrichtsphasen (lesson_phases), wo "duration_minutes": "auto" steht, bestimmen Sie die angemessene Dauer in Minuten (ganze Zahl) für jede Phase basierend auf Ihrer pädagogischen Expertise und der Art des Inhalts, sodass die Summe aller Phasendauern = {$duration} Minuten ergibt. Geben Sie der Erklärungsphase den größten Zeitanteil
4. Füllen Sie für jede Phase das Feld "teacher_role" mit einer detaillierten Beschreibung der Lehrerrolle und das Feld "student_role" mit einer detaillierten Beschreibung der Schülerrolle aus. Lassen Sie diese niemals leer
5. Gestalten Sie jedes Element klar und präzise
{$additionalInstructions}

📋 Der Unterrichtsplan muss die folgenden Elemente im JSON-Format enthalten:

{
    "lesson_title": "Unterrichtstitel",
    "objectives": {
        "cognitive": ["Kognitive Ziele - Liste von 3-5 Zielen"],
        "affective": ["Affektive Ziele - Liste von 2-3 Zielen"],
        "psychomotor": ["Psychomotorische Ziele - Liste von 2-3 Zielen"]
    },
    "strategies": {
        "teaching_strategies": ["Liste geeigneter Unterrichtsstrategien"],
        "active_learning": ["Verwendete aktive Lernmethoden"]
    },
    "lesson_phases": {$phasesJsonDe},
    "total_duration": {$duration},
    "resources_needed": ["Benötigte Ressourcen und Materialien"]{$additionalElementsDe}
}

Geben Sie die Antwort ausschließlich im JSON-Format ohne zusätzlichen Text zurück.
PROMPT;
        } else {
            $additionalInstructions = self::getAdditionalInstructions($selectedElements, 'en');
            return <<<PROMPT
You are an expert educator specializing in lesson planning and preparation.

Task: Create a professional and organized lesson plan based on the provided content.

Lesson Duration: {$duration} minutes

⚠️ Important Instructions:
1. Base your plan only on the provided content without adding external information
2. Distribute time intelligently across all lesson elements so the total equals exactly {$duration} minutes
3. In the lesson phases (lesson_phases) where "duration_minutes": "auto", determine the appropriate duration in minutes (integer) for each phase based on your pedagogical expertise and the nature of the content, ensuring the total of all phase durations = {$duration} minutes. Give the explanation phase the largest share of time
4. For each phase, fill in the "teacher_role" field with a detailed description of the teacher's role, and the "student_role" field with a detailed description of the student's role. Never leave them empty
5. Make each element clear and specific
{$additionalInstructions}

📋 The lesson plan must contain the following elements in JSON format:

{
    "lesson_title": "Lesson Title",
    "objectives": {
        "cognitive": ["Cognitive objectives - list of 3-5 objectives"],
        "affective": ["Affective objectives - list of 2-3 objectives"],
        "psychomotor": ["Psychomotor objectives - list of 2-3 objectives"]
    },
    "strategies": {
        "teaching_strategies": ["List of appropriate teaching strategies"],
        "active_learning": ["Active learning methods used"]
    },
    "lesson_phases": {$phasesJsonEn},
    "total_duration": {$duration},
    "resources_needed": ["Required resources and tools"]{$additionalElementsEn}
}

Return the response in JSON format only without any additional text.
PROMPT;
        }
    }
    
    /**
     * بناء JSON مراحل الدرس حسب اختيار المعلم واللغة
     * المعلم يختار المراحل فقط والذكاء الاصطناعي يوزع الوقت
     */
    private static function buildPhasesJson($selectedPhases, $duration, $language) {
        // تعريف المراحل مع أسمائها حسب اللغة
        $allPhases = [
            'warmup'     => ['ar' => 'التمهيد',              'fr' => 'Échauffement',              'en' => 'Warm-Up',              'de' => 'Aufwärmung'],
            'review'     => ['ar' => 'المراجعة الجزئية',     'fr' => 'Révision partielle',        'en' => 'Partial Review',       'de' => 'Teilwiederholung'],
            'intro'      => ['ar' => 'مقدمة الدرس',          'fr' => 'Introduction du cours',     'en' => 'Lesson Introduction',  'de' => 'Einführung in die Lektion'],
            'explanation'=> ['ar' => 'شرح الدرس',            'fr' => 'Explication du cours',      'en' => 'Lesson Explanation',   'de' => 'Erklärung der Lektion'],
            'assessment' => ['ar' => 'التقويم أثناء الحصة',  'fr' => 'Évaluation pendant le cours','en' => 'Formative Assessment', 'de' => 'Lernstandserhebung'],
            'keypoints'  => ['ar' => 'مراجعة أهم النقاط',    'fr' => 'Révision des points clés',  'en' => 'Key Points Review',    'de' => 'Wiederholung der Kernpunkte'],
            'homework'   => ['ar' => 'الواجب المنزلي',       'fr' => 'Devoir à la maison',        'en' => 'Homework Assignment',  'de' => 'Hausaufgabe'],
        ];

        // محتوى كل مرحلة حسب اللغة (قالب للذكاء الاصطناعي)
        $phaseContent = [
            'warmup'     => ['ar' => '"description": "نشاط تمهيدي لجذب انتباه الطلاب"',
                            'fr' => '"description": "Activité d\'introduction pour capter l\'attention"',
                            'en' => '"description": "Introductory activity to capture attention"',
                            'de' => '"description": "Einführungsaktivität zur Aufmerksamkeitsgewinnung"'],
            'review'     => ['ar' => '"description": "مراجعة سريعة لأهم نقاط الدرس السابق"',
                            'fr' => '"description": "Révision rapide des points clés du cours précédent"',
                            'en' => '"description": "Quick review of key points from previous lesson"',
                            'de' => '"description": "Kurze Wiederholung der wichtigsten Punkte der letzten Lektion"'],
            'intro'      => ['ar' => '"description": "تقديم موضوع الدرس الجديد وربطه بالمعرفة السابقة"',
                            'fr' => '"description": "Présentation du nouveau sujet et lien avec les connaissances antérieures"',
                            'en' => '"description": "Introduce new topic and connect to prior knowledge"',
                            'de' => '"description": "Neues Thema vorstellen und an Vorwissen anknüpfen"'],
            'explanation'=> ['ar' => '"content_points": ["النقطة الأولى في الشرح", "النقطة الثانية", "النقطة الثالثة"], "activities": ["نشاط تطبيقي أثناء الشرح"]',
                            'fr' => '"content_points": ["Premier point d\'explication", "Deuxième point", "Troisième point"], "activities": ["Activité pratique pendant l\'explication"]',
                            'en' => '"content_points": ["First explanation point", "Second point", "Third point"], "activities": ["Practical activity during explanation"]',
                            'de' => '"content_points": ["Erster Erklärungspunkt", "Zweiter Punkt", "Dritter Punkt"], "activities": ["Praktische Aktivität während der Erklärung"]'],
            'assessment' => ['ar' => '"activities": ["نشاط تقويمي أثناء الحصة"], "method": "أسلوب التقويم المستخدم"',
                            'fr' => '"activities": ["Activité d\'évaluation pendant le cours"], "method": "Méthode d\'évaluation utilisée"',
                            'en' => '"activities": ["Assessment activity during class"], "method": "Assessment method used"',
                            'de' => '"activities": ["Bewertungsaktivität während des Unterrichts"], "method": "Verwendete Bewertungsmethode"'],
            'keypoints'  => ['ar' => '"key_points": ["النقطة الأولى", "النقطة الثانية", "النقطة الثالثة"]',
                            'fr' => '"key_points": ["Premier point clé", "Deuxième point clé", "Troisième point clé"]',
                            'en' => '"key_points": ["First key point", "Second key point", "Third key point"]',
                            'de' => '"key_points": ["Erster Kernpunkt", "Zweiter Kernpunkt", "Dritter Kernpunkt"]'],
            'homework'   => ['ar' => '"homework": "وصف الواجب المنزلي والمهام المطلوبة"',
                            'fr' => '"homework": "Description du devoir à la maison et des tâches requises"',
                            'en' => '"homework": "Description of homework and required tasks"',
                            'de' => '"homework": "Beschreibung der Hausaufgaben und erforderlichen Aufgaben"'],
        ];

        // قوالب دور المعلم والمتعلم حسب اللغة
        $roleTemplates = [
            'ar' => '"teacher_role": "وصف تفصيلي لدور المعلم في هذه المرحلة", "student_role": "وصف تفصيلي لدور المتعلم في هذه المرحلة"',
            'fr' => '"teacher_role": "Description détaillée du rôle de l\'enseignant dans cette phase", "student_role": "Description détaillée du rôle de l\'élève dans cette phase"',
            'en' => '"teacher_role": "Detailed description of the teacher role in this phase", "student_role": "Detailed description of the student role in this phase"',
            'de' => '"teacher_role": "Detaillierte Beschreibung der Lehrerrolle in dieser Phase", "student_role": "Detaillierte Beschreibung der Schülerrolle in dieser Phase"',
        ];

        // تحديد المراحل المطلوبة
        $phaseIds = [];
        if ($selectedPhases !== null && is_array($selectedPhases) && count($selectedPhases) > 0) {
            // المعلم اختار مراحل محددة (قائمة IDs فقط)
            foreach ($selectedPhases as $phaseId) {
                // دعم الصيغتين: مصفوفة نصوص أو مصفوفة كائنات
                $id = is_array($phaseId) ? ($phaseId['id'] ?? '') : $phaseId;
                if (isset($allPhases[$id])) {
                    $phaseIds[] = $id;
                }
            }
        }

        // إذا لم يتم تحديد مراحل، استخدام جميع المراحل
        if (empty($phaseIds)) {
            $phaseIds = array_keys($allPhases);
        }

        // بناء نص JSON — بدون تحديد وقت ثابت، الذكاء الاصطناعي يوزع الوقت
        $items = [];
        $order = 1;
        foreach ($phaseIds as $id) {
            if (!isset($allPhases[$id])) continue;
            $name = $allPhases[$id][$language];
            $content = $phaseContent[$id][$language] ?? '';
            $roles = $roleTemplates[$language];
            $items[] = '{"order": ' . $order . ', "phase": "' . $name . '", "duration_minutes": "auto", ' . $content . ', ' . $roles . '}';
            $order++;
        }

        return '[' . "\n        " . implode(",\n        ", $items) . "\n    ]";
    }

    /**
     * بناء JSON العناصر الإضافية حسب اللغة
     */
    private static function buildAdditionalElementsJson($selectedElements, $language) {
        $parts = [];
        
        if (in_array('learning_styles', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"learning_styles": {"visual": "أنشطة للمتعلم البصري", "auditory": "أنشطة للمتعلم السمعي", "kinesthetic": "أنشطة للمتعلم الحركي", "reading_writing": "أنشطة للمتعلم القرائي"}';
            } elseif ($language === 'fr') {
                $parts[] = '"learning_styles": {"visual": "Activités pour l\'apprenant visuel", "auditory": "Activités pour l\'apprenant auditif", "kinesthetic": "Activités pour l\'apprenant kinesthésique", "reading_writing": "Activités pour l\'apprenant lecteur-scripteur"}';
            } elseif ($language === 'de') {
                $parts[] = '"learning_styles": {"visual": "Aktivit\u00e4ten f\u00fcr visuelle Lerner", "auditory": "Aktivit\u00e4ten f\u00fcr auditive Lerner", "kinesthetic": "Aktivit\u00e4ten f\u00fcr kin\u00e4sthetische Lerner", "reading_writing": "Aktivit\u00e4ten f\u00fcr Lese-/Schreiblernende"}';
            } else {
                $parts[] = '"learning_styles": {"visual": "Activities for visual learners", "auditory": "Activities for auditory learners", "kinesthetic": "Activities for kinesthetic learners", "reading_writing": "Activities for reading/writing learners"}';
            }
        }
        
        if (in_array('target_competencies', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"target_competencies": ["الكفاية الأولى المستهدفة", "الكفاية الثانية المستهدفة"]';
            } elseif ($language === 'fr') {
                $parts[] = '"target_competencies": ["Première compétence ciblée", "Deuxième compétence ciblée"]';
            } elseif ($language === 'de') {
                $parts[] = '"target_competencies": ["Erste Zielkompetenz", "Zweite Zielkompetenz"]';
            } else {
                $parts[] = '"target_competencies": ["First target competency", "Second target competency"]';
            }
        }
        
        if (in_array('motivational_intro', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"motivational_intro": {"hook": "سؤال أو موقف تحفيزي لجذب انتباه الطلاب", "connection": "ربط بالحياة الواقعية أو خبرات سابقة", "curiosity_trigger": "عنصر يثير الفضول والتشويق"}';
            } elseif ($language === 'fr') {
                $parts[] = '"motivational_intro": {"hook": "Question ou situation motivante", "connection": "Lien avec la vie réelle", "curiosity_trigger": "Élément suscitant la curiosité"}';
            } elseif ($language === 'de') {
                $parts[] = '"motivational_intro": {"hook": "Motivierende Frage oder Szenario zur Aufmerksamkeitsgewinnung", "connection": "Verbindung zum realen Leben oder fr\u00fcheren Erfahrungen", "curiosity_trigger": "Element, das Neugier weckt"}';
            } else {
                $parts[] = '"motivational_intro": {"hook": "Motivational question or scenario to grab attention", "connection": "Connection to real life or prior experiences", "curiosity_trigger": "Element that triggers curiosity"}';
            }
        }
        
        if (in_array('differentiation', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"differentiation": {"gifted": ["أنشطة إثرائية للمتفوقين - 2-3 أنشطة"], "struggling": ["أنشطة علاجية للضعاف - 2-3 أنشطة"], "strategies": ["استراتيجيات مراعاة الفروق الفردية"]}';
            } elseif ($language === 'fr') {
                $parts[] = '"differentiation": {"gifted": ["Activités d\'enrichissement pour les avancés"], "struggling": ["Activités de remédiation pour les élèves en difficulté"], "strategies": ["Stratégies de différenciation"]}';
            } elseif ($language === 'de') {
                $parts[] = '"differentiation": {"gifted": ["Enrichment-Aktivit\u00e4ten f\u00fcr fortgeschrittene Sch\u00fcler"], "struggling": ["F\u00f6rderaktivit\u00e4ten f\u00fcr leistungsschwache Sch\u00fcler"], "strategies": ["Differenzierungsstrategien"]}';
            } else {
                $parts[] = '"differentiation": {"gifted": ["Enrichment activities for advanced students"], "struggling": ["Remedial activities for struggling students"], "strategies": ["Differentiation strategies"]}';
            }
        }
        
        if (in_array('enrichment', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"enrichment": {"extension_activities": ["أنشطة توسعية وإثرائية - 2-3 أنشطة"], "additional_resources": ["مصادر إضافية للتعمق"], "challenge_questions": ["أسئلة تحدي للمتفوقين"]}';
            } elseif ($language === 'fr') {
                $parts[] = '"enrichment": {"extension_activities": ["Activités d\'extension et d\'enrichissement"], "additional_resources": ["Ressources supplémentaires"], "challenge_questions": ["Questions de défi"]}';
            } elseif ($language === 'de') {
                $parts[] = '"enrichment": {"extension_activities": ["Erweiterungs- und Enrichment-Aktivit\u00e4ten"], "additional_resources": ["Zus\u00e4tzliche Ressourcen f\u00fcr vertieftes Lernen"], "challenge_questions": ["Herausforderungsfragen f\u00fcr fortgeschrittene Sch\u00fcler"]}';
            } else {
                $parts[] = '"enrichment": {"extension_activities": ["Extension and enrichment activities"], "additional_resources": ["Additional resources for deeper learning"], "challenge_questions": ["Challenge questions for advanced students"]}';
            }
        }
        
        if (in_array('new_vocabulary', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"new_vocabulary": [{"term": "المصطلح", "definition": "التعريف", "example": "مثال توضيحي"}]';
            } elseif ($language === 'fr') {
                $parts[] = '"new_vocabulary": [{"term": "Le terme", "definition": "La définition", "example": "Exemple illustratif"}]';
            } elseif ($language === 'de') {
                $parts[] = '"new_vocabulary": [{"term": "Begriff", "definition": "Definition", "example": "Anschauliches Beispiel"}]';
            } else {
                $parts[] = '"new_vocabulary": [{"term": "Term", "definition": "Definition", "example": "Illustrative example"}]';
            }
        }
        
        if (in_array('formative_assessment', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"formative_assessment": [{"method": "أسلوب التقويم التكويني", "timing": "التوقيت (بداية/أثناء/نهاية)", "tool": "أداة التقويم", "success_criteria": "معايير النجاح"}]';
            } elseif ($language === 'fr') {
                $parts[] = '"formative_assessment": [{"method": "Méthode d\'évaluation formative", "timing": "Moment (début/pendant/fin)", "tool": "Outil d\'évaluation", "success_criteria": "Critères de réussite"}]';
            } elseif ($language === 'de') {
                $parts[] = '"formative_assessment": [{"method": "Formative Bewertungsmethode", "timing": "Zeitpunkt (Anfang/W\u00e4hrend/Ende)", "tool": "Bewertungsinstrument", "success_criteria": "Erfolgskriterien"}]';
            } else {
                $parts[] = '"formative_assessment": [{"method": "Formative assessment method", "timing": "Timing (beginning/during/end)", "tool": "Assessment tool", "success_criteria": "Success criteria"}]';
            }
        }
        
        if (in_array('closure_summary', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"closure_summary": {"closing_activity": "نشاط ختامي لتلخيص الدرس", "key_takeaways": ["أهم النقاط المستفادة من الدرس"], "exit_ticket": "بطاقة خروج أو سؤال تقييم سريع"}';
            } elseif ($language === 'fr') {
                $parts[] = '"closure_summary": {"closing_activity": "Activité de clôture pour résumer la leçon", "key_takeaways": ["Points clés à retenir"], "exit_ticket": "Ticket de sortie ou question d\'évaluation rapide"}';
            } elseif ($language === 'de') {
                $parts[] = '"closure_summary": {"closing_activity": "Abschlussaktivit\u00e4t zur Zusammenfassung der Lektion", "key_takeaways": ["Wichtigste Erkenntnisse aus der Lektion"], "exit_ticket": "Exit-Ticket oder schnelle Bewertungsfrage"}';
            } else {
                $parts[] = '"closure_summary": {"closing_activity": "Closing activity to summarize the lesson", "key_takeaways": ["Key takeaways from the lesson"], "exit_ticket": "Exit ticket or quick assessment question"}';
            }
        }
        
        if (in_array('real_life_connections', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"real_life_connections": [{"context": "السياق الواقعي", "application": "كيفية تطبيق المفهوم في الحياة", "example": "مثال عملي من الواقع"}]';
            } elseif ($language === 'fr') {
                $parts[] = '"real_life_connections": [{"context": "Contexte réel", "application": "Comment appliquer le concept", "example": "Exemple pratique de la vie réelle"}]';
            } elseif ($language === 'de') {
                $parts[] = '"real_life_connections": [{"context": "Realer Kontext", "application": "Wie das Konzept angewendet wird", "example": "Praktisches Beispiel aus dem Alltag"}]';
            } else {
                $parts[] = '"real_life_connections": [{"context": "Real-life context", "application": "How to apply the concept", "example": "Practical real-world example"}]';
            }
        }
        
        if (in_array('self_reflection', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"self_reflection": {"questions": ["أسئلة تأمل ذاتي للمعلم بعد الدرس"], "improvement_areas": ["مجالات محتملة للتحسين"], "what_worked": "ملاحظة حول ما نجح في الدرس"}';
            } elseif ($language === 'fr') {
                $parts[] = '"self_reflection": {"questions": ["Questions de réflexion pour l\'enseignant"], "improvement_areas": ["Domaines d\'amélioration potentiels"], "what_worked": "Ce qui a bien fonctionné"}';
            } elseif ($language === 'de') {
                $parts[] = '"self_reflection": {"questions": ["Selbstreflexionsfragen f\u00fcr die Lehrkraft"], "improvement_areas": ["M\u00f6gliche Verbesserungsbereiche"], "what_worked": "Was in der Lektion gut funktioniert hat"}';
            } else {
                $parts[] = '"self_reflection": {"questions": ["Self-reflection questions for the teacher"], "improvement_areas": ["Potential improvement areas"], "what_worked": "What worked well in the lesson"}';
            }
        }
        
        if (in_array('post_notes', $selectedElements)) {
            if ($language === 'ar') {
                $parts[] = '"post_notes": {"template": "مساحة لملاحظات ما بعد التنفيذ", "prompts": ["هل تحققت الأهداف؟", "ما التعديلات المقترحة للمرة القادمة؟", "ما التحديات التي واجهتها؟"]}';
            } elseif ($language === 'fr') {
                $parts[] = '"post_notes": {"template": "Espace pour les notes post-mise en œuvre", "prompts": ["Les objectifs ont-ils été atteints?", "Quelles modifications pour la prochaine fois?", "Quels défis rencontrés?"]}';
            } elseif ($language === 'de') {
                $parts[] = '"post_notes": {"template": "Platz f\u00fcr Nachbereitungsnotizen", "prompts": ["Wurden die Ziele erreicht?", "Welche \u00c4nderungen f\u00fcr das n\u00e4chste Mal?", "Welche Herausforderungen gab es?"]}';
            } else {
                $parts[] = '"post_notes": {"template": "Space for post-implementation notes", "prompts": ["Were objectives achieved?", "What modifications for next time?", "What challenges were faced?"]}';
            }
        }
        
        if (empty($parts)) return '';
        return ",\n    " . implode(",\n    ", $parts);
    }
    
    /**
     * توليد تعليمات إضافية حسب العناصر المختارة
     */
    private static function getAdditionalInstructions($selectedElements, $language) {
        $instructions = [];
        
        if (in_array('learning_styles', $selectedElements)) {
            if ($language === 'ar') $instructions[] = '4. قدّم أنشطة متنوعة تناسب جميع أنماط التعلم (بصري، سمعي، حركي، قرائي)';
            elseif ($language === 'fr') $instructions[] = '4. Proposez des activités variées pour tous les styles d\'apprentissage';
            elseif ($language === 'de') $instructions[] = '4. Bieten Sie abwechslungsreiche Aktivitäten für alle Lernstile (visuell, auditiv, kinästhetisch, Lesen/Schreiben)';
            else $instructions[] = '4. Provide varied activities for all learning styles (visual, auditory, kinesthetic, reading/writing)';
        }
        
        if (in_array('differentiation', $selectedElements)) {
            if ($language === 'ar') $instructions[] = '5. راعِ الفروق الفردية بين الطلاب (متفوقين وضعاف)';
            elseif ($language === 'fr') $instructions[] = '5. Prenez en compte les différences individuelles';
            elseif ($language === 'de') $instructions[] = '5. Berücksichtigen Sie individuelle Unterschiede zwischen Schülern (begabte und leistungsschwache)';
            else $instructions[] = '5. Consider individual differences between students (gifted and struggling)';
        }
        
        if (in_array('new_vocabulary', $selectedElements)) {
            if ($language === 'ar') $instructions[] = '6. استخرج المفردات والمصطلحات الجديدة من المحتوى مع تعريفاتها';
            elseif ($language === 'fr') $instructions[] = '6. Extrayez le vocabulaire et les termes nouveaux du contenu';
            elseif ($language === 'de') $instructions[] = '6. Extrahieren Sie neues Vokabular und Fachbegriffe aus dem Inhalt mit Definitionen';
            else $instructions[] = '6. Extract new vocabulary and terms from the content with definitions';
        }
        
        if (empty($instructions)) return '';
        return "\n" . implode("\n", $instructions);
    }
    
    /**
     * الحصول على prompt بنك الأسئلة
     */
    public static function getQuestionBankPrompt($language, $level = 0) {
        // المستويات: 0=كامل (~46), 1=مخفض (~23), 2=أدنى (~14)
        $counts = [
            0 => ['mc' => 10, 'tf' => 10, 'grad' => 6, 'sa' => 6, 'fb' => 6, 'ord' => 4, 'mat' => 4],
            1 => ['mc' => 5,  'tf' => 5,  'grad' => 3, 'sa' => 3, 'fb' => 3, 'ord' => 2, 'mat' => 2],
            2 => ['mc' => 3,  'tf' => 3,  'grad' => 2, 'sa' => 2, 'fb' => 2, 'ord' => 1, 'mat' => 1],
        ];
        $c = $counts[min($level, 2)];
        $mc = $c['mc']; $tf = $c['tf']; $grad = $c['grad'];
        $sa = $c['sa']; $fb = $c['fb']; $ord = $c['ord']; $mat = $c['mat'];
        
        if ($language === 'ar') {
            return <<<PROMPT
أنت خبير في إعداد الأسئلة التعليمية والاختبارات.

المطلوب: إنشاء بنك أسئلة شامل ومتنوع بناءً على المحتوى المقدم.
🎯 الهدف: توليد أسئلة متنوعة وعالية الجودة بالأعداد المحددة أدناه.

⚠️ تعليمات مهمة:
1. جميع الأسئلة يجب أن تكون من المحتوى المقدم فقط
2. تنويع مستويات الصعوبة (سهل، متوسط، صعب) في كل نوع
3. صياغة واضحة ومباشرة
4. ⚠️ مهم جداً: التزم بالأعداد المطلوبة لكل نوع. إذا لم يسمح المحتوى بالعدد الكامل، ولّد أقرب عدد ممكن
5. ⛔ ممنوع منعاً باتاً: لا تُكرر سؤالاً بصياغة مختلفة، ولا تخترع معلومات خارج المحتوى، ولا تُوَسّع السؤال بشكل اصطناعي. إذا نفد المحتوى، توقف عند آخر سؤال جيد فقط
6. يجب توليد أسئلة من جميع الأنواع أدناه ما أمكن

📋 أنواع الأسئلة المطلوبة مع الأعداد:

1. أسئلة اختيار من متعدد (multiple_choice): {$mc} أسئلة
   - 4 اختيارات لكل سؤال، إجابة صحيحة واحدة
   - مستويات صعوبة متنوعة

2. أسئلة صح أو خطأ (true_false): {$tf} أسئلة
   - عبارات واضحة ومباشرة مع توضيح

3. أسئلة مقالية متدرجة (graduated): {$grad} أسئلة
   - مستويات معرفية متنوعة (تذكر، فهم، تطبيق، تحليل)

4. أسئلة إجابة قصيرة (short_answer): {$sa} أسئلة
   - أسئلة تتطلب إجابة مختصرة من كلمة إلى جملة

5. أسئلة ملء الفراغ (fill_blank): {$fb} أسئلة
   - عبارات بها فراغ واحد يجب ملؤه

6. أسئلة ترتيب (ordering): {$ord} أسئلة
   - ترتيب خطوات أو أحداث أو مراحل حسب التسلسل الصحيح

7. أسئلة توصيل/مطابقة (matching): {$mat} أسئلة
   - ربط مصطلحات بتعريفاتها أو عناصر ببعضها

أرجع الإجابة بصيغة JSON التالية:

{
    "multiple_choice": [
        {
            "id": 1,
            "question": "نص السؤال",
            "options": ["أ) الخيار الأول", "ب) الخيار الثاني", "ج) الخيار الثالث", "د) الخيار الرابع"],
            "correct_answer": 0,
            "difficulty": "easy|medium|hard"
        }
    ],
    "true_false": [
        {
            "id": 1,
            "statement": "نص العبارة",
            "correct_answer": true,
            "explanation": "التوضيح",
            "difficulty": "easy|medium|hard"
        }
    ],
    "graduated": [
        {
            "id": 1,
            "question": "نص السؤال",
            "model_answer": "الإجابة النموذجية",
            "difficulty": "easy|medium|hard",
            "cognitive_level": "remember|understand|apply|analyze"
        }
    ],
    "short_answer": [
        {
            "id": 1,
            "question": "نص السؤال",
            "model_answer": "الإجابة النموذجية",
            "difficulty": "easy|medium|hard"
        }
    ],
    "fill_blank": [
        {
            "id": 1,
            "statement": "الجملة مع ___ فراغ",
            "answer": "الإجابة",
            "difficulty": "easy|medium|hard"
        }
    ],
    "ordering": [
        {
            "id": 1,
            "question": "رتب العناصر التالية",
            "items": ["العنصر ب", "العنصر أ", "العنصر ج"],
            "correct_order": [2, 1, 3],
            "difficulty": "easy|medium|hard"
        }
    ],
    "matching": [
        {
            "id": 1,
            "question": "وصّل كل مصطلح بتعريفه المناسب",
            "pairs": [
                {"term": "المصطلح", "definition": "التعريف"}
            ],
            "difficulty": "easy|medium|hard"
        }
    ]
}

أرجع JSON فقط بدون أي نص إضافي. التزم بالأعداد المطلوبة لكل نوع.
إذا كان المحتوى لا يناسب نوعاً معيناً، أرجع مصفوفة فارغة [] لذلك النوع.
PROMPT;
        } elseif ($language === 'fr') {
            return <<<PROMPT
Vous êtes un expert dans la création de questions éducatives et d'évaluations.

Tâche : Créer une banque de questions complète et variée basée sur le contenu fourni.
🎯 Objectif : Générer des questions variées et de haute qualité selon les quantités spécifiées ci-dessous.

⚠️ Instructions importantes :
1. Toutes les questions doivent être basées uniquement sur le contenu fourni
2. Varier les niveaux de difficulté (facile, moyen, difficile) pour chaque type
3. Formulation claire et directe
4. ⚠️ CRITIQUE : Respectez les quantités demandées. Si le contenu ne permet pas le nombre complet, générez le plus proche possible
5. ⛔ INTERDIT : Ne répétez jamais une question avec une formulation différente, n'inventez pas d'informations hors du contenu. Si le contenu est épuisé, arrêtez à la dernière bonne question
6. Générez des questions de TOUS les types ci-dessous autant que possible

📋 Types de questions requis avec quantités :

1. Choix multiples (multiple_choice) : {$mc} questions, 4 options, une réponse correcte
2. Vrai/Faux (true_false) : {$tf} questions, énoncés clairs avec explication
3. Questions progressives/essai (graduated) : {$grad} questions, niveaux cognitifs variés
4. Réponse courte (short_answer) : {$sa} questions, réponse d'un mot à une phrase
5. Texte à trous (fill_blank) : {$fb} questions, phrases avec un blanc à compléter
6. Mise en ordre (ordering) : {$ord} questions, ordonner des étapes ou éléments
7. Appariement (matching) : {$mat} questions, relier des termes à leurs définitions

Retournez la réponse au format JSON suivant :

{
    "multiple_choice": [
        {"id": 1, "question": "Texte", "options": ["A) Opt1", "B) Opt2", "C) Opt3", "D) Opt4"], "correct_answer": 0, "difficulty": "easy|medium|hard"}
    ],
    "true_false": [
        {"id": 1, "statement": "Énoncé", "correct_answer": true, "explanation": "Explication", "difficulty": "easy|medium|hard"}
    ],
    "graduated": [
        {"id": 1, "question": "Texte", "model_answer": "Réponse modèle", "difficulty": "easy|medium|hard", "cognitive_level": "remember|understand|apply|analyze"}
    ],
    "short_answer": [
        {"id": 1, "question": "Texte", "model_answer": "Réponse", "difficulty": "easy|medium|hard"}
    ],
    "fill_blank": [
        {"id": 1, "statement": "Phrase avec ___ blanc", "answer": "Réponse", "difficulty": "easy|medium|hard"}
    ],
    "ordering": [
        {"id": 1, "question": "Ordonnez", "items": ["B", "A", "C"], "correct_order": [2, 1, 3], "difficulty": "easy|medium|hard"}
    ],
    "matching": [
        {"id": 1, "question": "Associez", "pairs": [{"term": "Terme", "definition": "Définition"}], "difficulty": "easy|medium|hard"}
    ]
}

Retournez uniquement le JSON. Respectez les quantités demandées pour chaque type.
Si le contenu ne convient pas à un type, retournez un tableau vide [] pour ce type.
PROMPT;
        } elseif ($language === 'de') {
            return <<<PROMPT
Sie sind ein Experte für die Erstellung von Bildungsfragen und Prüfungen.

Aufgabe: Erstellen Sie eine umfassende und vielfältige Fragenbank basierend auf dem bereitgestellten Inhalt.
🎯 Ziel: Generieren Sie hochwertige und vielfältige Fragen gemäß den unten angegebenen Mengen.

⚠️ Wichtige Anweisungen:
1. Alle Fragen müssen ausschließlich auf dem bereitgestellten Inhalt basieren
2. Variieren Sie die Schwierigkeitsgrade (leicht, mittel, schwer) für jeden Typ
3. Klare und direkte Formulierung
4. ⚠️ KRITISCH: Halten Sie sich an die geforderten Mengen. Wenn der Inhalt nicht die volle Anzahl erlaubt, generieren Sie die nächstmögliche Anzahl
5. ⛔ VERBOTEN: Wiederholen Sie keine Frage mit anderen Worten, erfinden Sie keine Informationen außerhalb des Inhalts. Wenn der Inhalt erschöpft ist, hören Sie bei der letzten guten Frage auf
6. Generieren Sie Fragen ALLER unten genannten Typen, soweit möglich

📋 Erforderliche Fragentypen mit Mengen:

1. Multiple-Choice (multiple_choice): {$mc} Fragen, 4 Optionen, eine richtige Antwort
2. Richtig/Falsch (true_false): {$tf} Fragen, klare Aussagen mit Erklärung
3. Gestufte/Essay-Fragen (graduated): {$grad} Fragen, verschiedene kognitive Niveaus
4. Kurzantwort (short_answer): {$sa} Fragen, Antwort von einem Wort bis einem Satz
5. Lückentext (fill_blank): {$fb} Fragen, Sätze mit einer Lücke
6. Ordnung (ordering): {$ord} Fragen, Schritte oder Elemente ordnen
7. Zuordnung (matching): {$mat} Fragen, Begriffe mit Definitionen verbinden

Geben Sie die Antwort im folgenden JSON-Format zurück:

{
    "multiple_choice": [
        {"id": 1, "question": "Text", "options": ["A) Opt1", "B) Opt2", "C) Opt3", "D) Opt4"], "correct_answer": 0, "difficulty": "easy|medium|hard"}
    ],
    "true_false": [
        {"id": 1, "statement": "Aussage", "correct_answer": true, "explanation": "Erklärung", "difficulty": "easy|medium|hard"}
    ],
    "graduated": [
        {"id": 1, "question": "Text", "model_answer": "Musterantwort", "difficulty": "easy|medium|hard", "cognitive_level": "remember|understand|apply|analyze"}
    ],
    "short_answer": [
        {"id": 1, "question": "Text", "model_answer": "Antwort", "difficulty": "easy|medium|hard"}
    ],
    "fill_blank": [
        {"id": 1, "statement": "Satz mit ___ Lücke", "answer": "Antwort", "difficulty": "easy|medium|hard"}
    ],
    "ordering": [
        {"id": 1, "question": "Ordnen Sie", "items": ["B", "A", "C"], "correct_order": [2, 1, 3], "difficulty": "easy|medium|hard"}
    ],
    "matching": [
        {"id": 1, "question": "Zuordnen", "pairs": [{"term": "Begriff", "definition": "Definition"}], "difficulty": "easy|medium|hard"}
    ]
}

Geben Sie ausschließlich JSON zurück. Halten Sie sich an die geforderten Mengen für jeden Typ.
Wenn der Inhalt nicht zu einem Typ passt, geben Sie ein leeres Array [] zurück.
PROMPT;
        } else {
            return <<<PROMPT
You are an expert in creating educational questions and assessments.

Task: Create a comprehensive and diverse question bank based on the provided content.
🎯 Goal: Generate high-quality and varied questions according to the specified quantities below.

⚠️ Important Instructions:
1. All questions must be based only on the provided content
2. Vary difficulty levels (easy, medium, hard) for each type
3. Clear and direct wording
4. ⚠️ CRITICAL: Follow the requested quantities for each type. If the content doesn't allow the full number, generate the closest possible count
5. ⛔ FORBIDDEN: Never repeat a question with different wording, never invent information outside the content. If the content runs out, stop at the last good question
6. Generate questions for ALL types below whenever possible

📋 Required question types with quantities:

1. Multiple Choice (multiple_choice): {$mc} questions, 4 options, one correct answer
2. True/False (true_false): {$tf} questions, clear statements with explanation
3. Graduated/Essay (graduated): {$grad} questions, various cognitive levels
4. Short Answer (short_answer): {$sa} questions, answer from one word to one sentence
5. Fill in the Blank (fill_blank): {$fb} questions, sentences with one blank to complete
6. Ordering (ordering): {$ord} questions, order steps or elements in correct sequence
7. Matching (matching): {$mat} questions, match terms with their definitions

Return the response in the following JSON format:

{
    "multiple_choice": [
        {"id": 1, "question": "Question text", "options": ["A) Option 1", "B) Option 2", "C) Option 3", "D) Option 4"], "correct_answer": 0, "difficulty": "easy|medium|hard"}
    ],
    "true_false": [
        {"id": 1, "statement": "Statement text", "correct_answer": true, "explanation": "Explanation", "difficulty": "easy|medium|hard"}
    ],
    "graduated": [
        {"id": 1, "question": "Question text", "model_answer": "Model answer", "difficulty": "easy|medium|hard", "cognitive_level": "remember|understand|apply|analyze"}
    ],
    "short_answer": [
        {"id": 1, "question": "Question text", "model_answer": "Model answer", "difficulty": "easy|medium|hard"}
    ],
    "fill_blank": [
        {"id": 1, "statement": "Sentence with ___ blank", "answer": "Answer", "difficulty": "easy|medium|hard"}
    ],
    "ordering": [
        {"id": 1, "question": "Order the following", "items": ["B", "A", "C"], "correct_order": [2, 1, 3], "difficulty": "easy|medium|hard"}
    ],
    "matching": [
        {"id": 1, "question": "Match terms with definitions", "pairs": [{"term": "Term", "definition": "Definition"}], "difficulty": "easy|medium|hard"}
    ]
}

Return JSON only without any additional text. Follow the requested quantities for each type.
If the content doesn't suit a particular type, return an empty array [] for that type.
PROMPT;
        }
    }
    
    /**
     * الحصول على prompt المواد البصرية
     */
    public static function getVisualMaterialsPrompt($language) {
        if ($language === 'ar') {
            return <<<PROMPT
أنت خبير في تصميم المواد البصرية التعليمية.

المطلوب: إنشاء وصف تفصيلي للمواد البصرية التعليمية بناءً على المحتوى المقدم.

⚠️ تعليمات مهمة:
1. الأوصاف يجب أن تكون دقيقة وقابلة للتصميم
2. التركيز على المفاهيم الأساسية
3. مراعاة الوضوح والبساطة

📋 يجب إنشاء:

1. بطاقات تعليمية (Flash Cards):
   - بطاقات لأهم المصطلحات والمفاهيم
   - كل بطاقة تحتوي: المصطلح، التعريف، صورة مقترحة

2. صور تعليمية توضيحية:
   - صور توضح الأفكار الأساسية
   - وصف تفصيلي لمحتوى كل صورة

3. صور تسلسلية:
   - تشرح تتابع عناصر الدرس أو خطوات المفاهيم
   - وصف كل خطوة بالترتيب

4. فيديوهات يوتيوب مقترحة (YouTube Videos):
   - اقتراح 3-5 عمليات بحث على يوتيوب ذات صلة بموضوع الدرس
   - كل اقتراح يحتوي: عنوان وصفي، استعلام البحث بالإنجليزية، ووصف لما سيجده المعلم
   - التركيز على فيديوهات تعليمية مفيدة للشرح في الحصة
   - استعلام البحث (search_query) يجب أن يكون دقيقاً ومحدداً بالإنجليزية (5-10 كلمات) ليوصل مباشرة لفيديوهات تعليمية حقيقية
   - حقل video_url: إذا كنت تعرف رابط فيديو يوتيوب حقيقي ومحدد ضعه، وإلا اتركه فارغاً ""

⚠️ مهم جداً - كلمات البحث عن الصور:
لكل عنصر أضف حقل "search_keywords" يحتوي على كلمات بحث دقيقة ومحددة بالإنجليزية (4-7 كلمات) مرتبطة مباشرة بموضوع العنصر.
- يجب أن تكون الكلمات وصفية ومحددة وقابلة للبحث في مواقع الصور مثل Pixabay وUnsplash
- استخدم مصطلحات علمية/تعليمية دقيقة وأسماء أشياء مرئية يمكن تصويرها
- أمثلة جيدة: "human heart anatomy blood circulation" أو "water cycle evaporation rain clouds" أو "plant cell microscope diagram" أو "volcanic eruption lava mountain"
- أمثلة سيئة: "science education" أو "lesson learning" أو "important concept"
- تجنب تماماً الكلمات العامة مثل: education, learning, lesson, concept, study, school
- ركّز على المصطلحات المرئية: أشياء، كائنات، ظواهر، أماكن يمكن تصويرها فعلاً

أرجع الإجابة بصيغة JSON التالية:

{
    "flash_cards": [
        {
            "id": 1,
            "term": "المصطلح",
            "definition": "التعريف المختصر",
            "suggested_image": "وصف الصورة المقترحة",
            "search_keywords": "English search keywords for image"
        }
    ],
    "educational_images": [
        {
            "id": 1,
            "title": "عنوان الصورة",
            "description": "وصف تفصيلي للصورة",
            "elements": ["العناصر التي يجب أن تتضمنها الصورة"],
            "colors_suggested": "الألوان المقترحة",
            "search_keywords": "English search keywords for finding relevant image online"
        }
    ],
    "sequential_images": [
        {
            "id": 1,
            "title": "عنوان التسلسل",
            "search_keywords": "English search keywords for sequence",
            "steps": [
                {
                    "step_number": 1,
                    "description": "وصف الخطوة",
                    "visual_elements": "العناصر البصرية"
                }
            ]
        }
    ],
    "youtube_videos": [
        {
            "id": 1,
            "title": "عنوان وصفي للفيديو المقترح",
            "search_query": "specific educational YouTube search query in English 5-10 words",
            "video_url": "",
            "description": "وصف قصير لما سيجده المعلم عند البحث",
            "why_relevant": "لماذا هذا الفيديو مناسب للدرس"
        }
    ]
}

أرجع JSON فقط بدون أي نص إضافي.
PROMPT;
        } elseif ($language === 'fr') {
            return <<<PROMPT
Vous êtes un expert en conception de matériaux visuels éducatifs.

Tâche : Créer des descriptions détaillées de matériaux visuels éducatifs basés sur le contenu fourni.

⚠️ Instructions importantes :
1. Les descriptions doivent être précises et réalisables
2. Se concentrer sur les concepts fondamentaux
3. Assurer la clarté et la simplicité

📋 À créer :

1. Cartes Mémoire (Flash Cards) :
   - Cartes pour les termes et concepts clés
   - Chaque carte contient : terme, définition, image suggérée

2. Images Éducatives Illustratives :
   - Images expliquant les idées principales
   - Description détaillée du contenu de chaque image

3. Images Séquentielles :
   - Expliquent la succession des éléments du cours ou les étapes des concepts
   - Description de chaque étape dans l'ordre

4. Vidéos YouTube Suggérées :
   - Suggérez 3-5 recherches YouTube pertinentes au sujet de la leçon
   - Chaque suggestion contient : titre descriptif, requête de recherche en anglais, et description
   - Focus sur les vidéos éducatives utiles pour l'enseignement en classe

⚠️ Très important - Mots-clés de recherche d'images:
Pour chaque élément, ajoutez un champ "search_keywords" contenant des mots-clés précis et spécifiques en anglais (4-7 mots) directement liés au sujet de l'élément.
- Les mots-clés doivent être descriptifs, spécifiques et recherchables sur des sites comme Pixabay et Unsplash
- Utilisez des termes scientifiques/éducatifs précis et des noms d'objets visuels photographiables
- Bons exemples: "human heart anatomy blood circulation" ou "water cycle evaporation rain clouds"
- Mauvais exemples: "science education" ou "lesson learning"
- Évitez les mots génériques: education, learning, lesson, concept, study, school
- Concentrez-vous sur des termes visuels: objets, organismes, phénomènes, lieux photographiables

Retournez la réponse au format JSON suivant :

{
    "flash_cards": [
        {
            "id": 1,
            "term": "Terme",
            "definition": "Définition courte",
            "suggested_image": "Description de l'image suggérée",
            "search_keywords": "English search keywords for image"
        }
    ],
    "educational_images": [
        {
            "id": 1,
            "title": "Titre de l'image",
            "description": "Description détaillée de l'image",
            "elements": ["Éléments que l'image doit inclure"],
            "colors_suggested": "Couleurs suggérées",
            "search_keywords": "English search keywords for relevant image"
        }
    ],
    "sequential_images": [
        {
            "id": 1,
            "title": "Titre de la séquence",
            "search_keywords": "English search keywords for sequence",
            "steps": [
                {
                    "step_number": 1,
                    "description": "Description de l'étape",
                    "visual_elements": "Éléments visuels"
                }
            ]
        }
    ],
    "youtube_videos": [
        {
            "id": 1,
            "title": "Titre descriptif de la vidéo suggérée",
            "search_query": "specific educational YouTube search query in English 5-10 words",
            "video_url": "",
            "description": "Brève description de ce que l'enseignant trouvera",
            "why_relevant": "Pourquoi cette vidéo est pertinente pour la leçon"
        }
    ]
}

Retournez uniquement le JSON sans texte supplémentaire.
PROMPT;
        } elseif ($language === 'de') {
            return <<<PROMPT
Sie sind ein Experte für die Gestaltung von pädagogischen visuellen Materialien.

Aufgabe: Erstellen Sie detaillierte Beschreibungen von pädagogischen visuellen Materialien basierend auf dem bereitgestellten Inhalt.

⚠️ Wichtige Anweisungen:
1. Beschreibungen müssen präzise und umsetzbar sein
2. Fokus auf Kernkonzepte
3. Klarheit und Einfachheit sicherstellen

📋 Zu erstellen:

1. Lernkarten (Flash Cards):
   - Karten für Schlüsselbegriffe und Konzepte
   - Jede Karte enthält: Begriff, Definition, vorgeschlagenes Bild

2. Pädagogische Illustrationsbilder:
   - Bilder, die Hauptideen erklären
   - Detaillierte Beschreibung des Inhalts jedes Bildes

3. Sequenzbilder:
   - Erklären die Abfolge von Lektionselementen oder Konzeptschritten
   - Beschreibung jedes Schritts in der richtigen Reihenfolge

4. Vorgeschlagene YouTube-Videos:
   - Schlagen Sie 3-5 YouTube-Suchen vor, die zum Unterrichtsthema passen
   - Jeder Vorschlag enthält: beschreibenden Titel, englische Suchanfrage und Beschreibung
   - Fokus auf lehrreiche Videos, die im Unterricht nützlich sind

⚠️ Sehr wichtig - Bildsuch-Schlüsselwörter:
Fügen Sie für jedes Element ein Feld "search_keywords" hinzu, das präzise und spezifische englische Schlüsselwörter (4-7 Wörter) enthält, die direkt mit dem Thema des Elements zusammenhängen.
- Die Schlüsselwörter sollten beschreibend, spezifisch und auf Bilderseiten wie Pixabay und Unsplash suchbar sein
- Verwenden Sie präzise wissenschaftliche/pädagogische Begriffe und Namen fotografierbarer visueller Objekte
- Gute Beispiele: "human heart anatomy blood circulation" oder "water cycle evaporation rain clouds"
- Schlechte Beispiele: "science education" oder "lesson learning"
- Vermeiden Sie generische Wörter: education, learning, lesson, concept, study, school
- Konzentrieren Sie sich auf visuelle Begriffe: Objekte, Organismen, Phänomene, fotografierbare Orte

Geben Sie die Antwort im folgenden JSON-Format zurück:

{
    "flash_cards": [
        {
            "id": 1,
            "term": "Begriff",
            "definition": "Kurze Definition",
            "suggested_image": "Beschreibung des vorgeschlagenen Bildes",
            "search_keywords": "English search keywords for image"
        }
    ],
    "educational_images": [
        {
            "id": 1,
            "title": "Bildtitel",
            "description": "Detaillierte Bildbeschreibung",
            "elements": ["Elemente, die das Bild enthalten sollte"],
            "colors_suggested": "Vorgeschlagene Farben",
            "search_keywords": "English search keywords for relevant image"
        }
    ],
    "sequential_images": [
        {
            "id": 1,
            "title": "Sequenztitel",
            "search_keywords": "English search keywords for sequence",
            "steps": [
                {
                    "step_number": 1,
                    "description": "Schrittbeschreibung",
                    "visual_elements": "Visuelle Elemente"
                }
            ]
        }
    ],
    "youtube_videos": [
        {
            "id": 1,
            "title": "Beschreibender Titel des vorgeschlagenen Videos",
            "search_query": "specific educational YouTube search query in English 5-10 words",
            "video_url": "",
            "description": "Kurze Beschreibung, was der Lehrer finden wird",
            "why_relevant": "Warum dieses Video für die Lektion relevant ist"
        }
    ]
}

Geben Sie ausschließlich JSON ohne zusätzlichen Text zurück.
PROMPT;
        } else {
            return <<<PROMPT
You are an expert in designing educational visual materials.

Task: Create detailed descriptions of educational visual materials based on the provided content.

⚠️ Important Instructions:
1. Descriptions must be precise and designable
2. Focus on core concepts
3. Ensure clarity and simplicity

📋 Must create:

1. Flash Cards:
   - Cards for key terms and concepts
   - Each card contains: term, definition, suggested image

2. Educational Illustrative Images:
   - Images explaining main ideas
   - Detailed description of each image's content

3. Sequential Images:
   - Explain the sequence of lesson elements or concept steps
   - Describe each step in order

4. Suggested YouTube Videos:
   - Suggest 3-5 YouTube searches relevant to the lesson topic
   - Each suggestion contains: descriptive title, English search query, and description
   - Focus on educational videos useful for classroom instruction

⚠️ Very important - Image search keywords:
For each element, add a "search_keywords" field containing precise and specific English keywords (4-7 words) directly related to the element's topic.
- Keywords must be descriptive, specific, and searchable on image sites like Pixabay and Unsplash
- Use precise scientific/educational terms and names of visual, photographable objects
- Good examples: "human heart anatomy blood circulation" or "water cycle evaporation rain clouds" or "plant cell microscope diagram" or "volcanic eruption lava mountain"
- Bad examples: "science education" or "lesson learning" or "important concept"
- Strictly avoid generic words: education, learning, lesson, concept, study, school
- Focus on visual terms: objects, organisms, phenomena, places that can actually be photographed

Return the response in the following JSON format:

{
    "flash_cards": [
        {
            "id": 1,
            "term": "Term",
            "definition": "Short definition",
            "suggested_image": "Suggested image description",
            "search_keywords": "English search keywords for image"
        }
    ],
    "educational_images": [
        {
            "id": 1,
            "title": "Image title",
            "description": "Detailed image description",
            "elements": ["Elements the image should include"],
            "colors_suggested": "Suggested colors",
            "search_keywords": "English search keywords for relevant image"
        }
    ],
    "sequential_images": [
        {
            "id": 1,
            "title": "Sequence title",
            "search_keywords": "English search keywords for sequence",
            "steps": [
                {
                    "step_number": 1,
                    "description": "Step description",
                    "visual_elements": "Visual elements"
                }
            ]
        }
    ],
    "youtube_videos": [
        {
            "id": 1,
            "title": "Descriptive title of suggested video",
            "search_query": "specific educational YouTube search query in English 5-10 words",
            "video_url": "",
            "description": "Brief description of what the teacher will find",
            "why_relevant": "Why this video is relevant to the lesson"
        }
    ]
}

Return JSON only without any additional text.
PROMPT;
        }
    }
    
    /**
     * الحصول على prompt استخراج المحتوى من صورة
     */
    public static function getImageExtractionPrompt($language) {
        if ($language === 'ar') {
            return <<<PROMPT
حلل هذه الصورة واستخرج جميع المحتوى التعليمي منها.

المطلوب:
1. استخراج جميع النصوص الموجودة في الصورة
2. وصف الرسومات والمخططات
3. شرح المفاهيم المعروضة
4. ترتيب المعلومات بشكل منطقي

أرجع المحتوى كنص منظم يمكن استخدامه لإعداد درس.
PROMPT;
        } elseif ($language === 'fr') {
            return <<<PROMPT
Analysez cette image et extrayez tout le contenu éducatif.

Requis :
1. Extraire tous les textes présents dans l'image
2. Décrire les diagrammes et graphiques
3. Expliquer les concepts affichés
4. Organiser les informations de manière logique

Retournez le contenu sous forme de texte organisé utilisable pour la préparation d'un cours.
PROMPT;
        } elseif ($language === 'de') {
            return <<<PROMPT
Analysieren Sie dieses Bild und extrahieren Sie alle pädagogischen Inhalte daraus.

Erforderlich:
1. Extrahieren Sie alle im Bild vorhandenen Texte
2. Beschreiben Sie Diagramme und Grafiken
3. Erklären Sie die dargestellten Konzepte
4. Ordnen Sie die Informationen logisch

Geben Sie den Inhalt als organisierten Text zurück, der für die Unterrichtsvorbereitung verwendet werden kann.
PROMPT;
        } else {
            return <<<PROMPT
Analyze this image and extract all educational content from it.

Required:
1. Extract all text present in the image
2. Describe diagrams and charts
3. Explain displayed concepts
4. Organize information logically

Return the content as organized text that can be used for lesson preparation.
PROMPT;
        }
    }
    
    /**
     * الحصول على prompt استخراج المحتوى من PDF
     */
    public static function getPDFExtractionPrompt($language) {
        if ($language === 'ar') {
            return <<<PROMPT
حلل ملف PDF هذا واستخرج جميع المحتوى التعليمي منه.

المطلوب:
1. استخراج جميع النصوص والعناوين
2. تحديد الأفكار الرئيسية
3. استخراج المصطلحات والمفاهيم المهمة
4. وصف أي صور أو رسومات موجودة
5. ترتيب المعلومات بشكل منطقي ومتسلسل

أرجع المحتوى كنص منظم يمكن استخدامه لإعداد درس شامل.
PROMPT;
        } elseif ($language === 'fr') {
            return <<<PROMPT
Analysez ce fichier PDF et extrayez tout le contenu éducatif.

Requis :
1. Extraire tous les textes et titres
2. Identifier les idées principales
3. Extraire les termes et concepts importants
4. Décrire les images ou diagrammes présents
5. Organiser les informations de manière logique et séquentielle

Retournez le contenu sous forme de texte organisé utilisable pour la préparation d'un cours complet.
PROMPT;
        } elseif ($language === 'de') {
            return <<<PROMPT
Analysieren Sie diese PDF-Datei und extrahieren Sie alle pädagogischen Inhalte daraus.

Erforderlich:
1. Extrahieren Sie alle Texte und Überschriften
2. Identifizieren Sie die Hauptideen
3. Extrahieren Sie wichtige Begriffe und Konzepte
4. Beschreiben Sie vorhandene Bilder oder Diagramme
5. Ordnen Sie die Informationen logisch und sequentiell

Geben Sie den Inhalt als organisierten Text zurück, der für eine umfassende Unterrichtsvorbereitung verwendet werden kann.
PROMPT;
        } else {
            return <<<PROMPT
Analyze this PDF file and extract all educational content from it.

Required:
1. Extract all text and headings
2. Identify main ideas
3. Extract important terms and concepts
4. Describe any images or diagrams present
5. Organize information logically and sequentially

Return the content as organized text that can be used for comprehensive lesson preparation.
PROMPT;
        }
    }
    
    /**
     * الحصول على prompt الأنشطة الصفية
     * Activities inspired by Padlet, Kahoot, Mentimeter, Nearpod, etc.
     */
    public static function getClassActivitiesPrompt($language) {
        if ($language === 'ar') {
            return <<<PROMPT
أنت خبير في تصميم الأنشطة الصفية التفاعلية والإبداعية.

المطلوب: إنشاء مجموعة متنوعة من الأنشطة الصفية التفاعلية بناءً على المحتوى المقدم.

⚠️ تعليمات مهمة:
1. الأنشطة يجب أن تكون مبنية على المحتوى المقدم فقط
2. تنويع أنماط الأنشطة لتناسب مختلف أساليب التعلم
3. توفير أنشطة فردية وجماعية
4. مراعاة الوقت المتاح في الحصة
5. تضمين أنشطة رقمية يمكن تنفيذها باستخدام أدوات مثل Padlet, Kahoot, Mentimeter

📋 يجب إنشاء الأنشطة التالية:

1. أنشطة تفاعلية رقمية (مستوحاة من Padlet):
   - لوحة تفاعلية للأفكار والملاحظات
   - جدران تعاونية لجمع المعلومات
   - خرائط ذهنية تشاركية

2. ألعاب تعليمية ومسابقات (مستوحاة من Kahoot):
   - مسابقات سريعة
   - تحديات بين الفرق
   - ألعاب التخمين

3. أنشطة تغذية راجعة فورية (مستوحاة من Mentimeter):
   - استطلاعات رأي سريعة
   - سحب كلمات مفتاحية
   - مقاييس القبول والفهم

4. أنشطة تعاونية جماعية:
   - مشاريع صغيرة
   - عروض تقديمية مصغرة
   - نقاشات موجهة

5. أنشطة إبداعية وحركية:
   - لعب أدوار
   - محاكاة
   - أنشطة حركية للذكاء الحركي

أرجع الإجابة بصيغة JSON التالية:

{
    "digital_activities": [
        {
            "id": 1,
            "title": "عنوان النشاط",
            "type": "padlet|kahoot|mentimeter|nearpod",
            "description": "وصف النشاط بالتفصيل",
            "duration_minutes": 10,
            "instructions": ["خطوة 1", "خطوة 2"],
            "materials_needed": ["المواد المطلوبة"],
            "digital_tool_suggestion": "اقتراح الأداة الرقمية المناسبة",
            "learning_outcome": "المخرج التعليمي المتوقع"
        }
    ],
    "collaborative_activities": [
        {
            "id": 1,
            "title": "عنوان النشاط",
            "type": "group_work|pair_work|whole_class",
            "group_size": "2-4 طلاب",
            "description": "وصف النشاط",
            "duration_minutes": 15,
            "instructions": ["خطوات التنفيذ"],
            "assessment_criteria": ["معايير التقييم"],
            "differentiation": "كيفية تكييف النشاط لمختلف المستويات"
        }
    ],
    "creative_activities": [
        {
            "id": 1,
            "title": "عنوان النشاط",
            "type": "role_play|simulation|art|movement",
            "description": "وصف النشاط",
            "duration_minutes": 10,
            "instructions": ["خطوات التنفيذ"],
            "props_needed": ["الأدوات أو المواد المطلوبة"],
            "learning_styles_addressed": ["أنماط التعلم المستهدفة"]
        }
    ],
    "quick_activities": [
        {
            "id": 1,
            "title": "عنوان النشاط",
            "type": "icebreaker|energizer|review|exit_ticket",
            "description": "وصف النشاط",
            "duration_minutes": 5,
            "when_to_use": "متى يُستخدم (بداية/وسط/نهاية الحصة)"
        }
    ],
    "assessment_activities": [
        {
            "id": 1,
            "title": "عنوان النشاط",
            "type": "formative|summative|peer|self",
            "description": "وصف النشاط",
            "duration_minutes": 10,
            "rubric": "معايير التقييم البسيطة"
        }
    ]
}

أرجع JSON فقط بدون أي نص إضافي.
PROMPT;
        } elseif ($language === 'fr') {
            return <<<PROMPT
Vous êtes un expert en conception d'activités de classe interactives et créatives.

Tâche : Créer une variété d'activités de classe interactives basées sur le contenu fourni.

⚠️ Instructions importantes :
1. Les activités doivent être basées uniquement sur le contenu fourni
2. Varier les types d'activités pour s'adapter à différents styles d'apprentissage
3. Inclure des activités individuelles et en groupe
4. Tenir compte du temps disponible en classe
5. Inclure des activités numériques utilisant des outils comme Padlet, Kahoot, Mentimeter

📋 Créer les activités suivantes :

1. Activités numériques interactives (inspirées de Padlet)
2. Jeux éducatifs et quiz (inspirés de Kahoot)
3. Activités de feedback instantané (inspirées de Mentimeter)
4. Activités collaboratives en groupe
5. Activités créatives et kinesthésiques

Retournez la réponse au format JSON suivant :

{
    "digital_activities": [
        {
            "id": 1,
            "title": "Titre de l'activité",
            "type": "padlet|kahoot|mentimeter|nearpod",
            "description": "Description détaillée",
            "duration_minutes": 10,
            "instructions": ["Étape 1", "Étape 2"],
            "materials_needed": ["Matériaux nécessaires"],
            "digital_tool_suggestion": "Outil numérique suggéré",
            "learning_outcome": "Résultat d'apprentissage attendu"
        }
    ],
    "collaborative_activities": [
        {
            "id": 1,
            "title": "Titre de l'activité",
            "type": "group_work|pair_work|whole_class",
            "group_size": "2-4 élèves",
            "description": "Description",
            "duration_minutes": 15,
            "instructions": ["Étapes d'exécution"],
            "assessment_criteria": ["Critères d'évaluation"],
            "differentiation": "Adaptation pour différents niveaux"
        }
    ],
    "creative_activities": [
        {
            "id": 1,
            "title": "Titre de l'activité",
            "type": "role_play|simulation|art|movement",
            "description": "Description",
            "duration_minutes": 10,
            "instructions": ["Étapes d'exécution"],
            "props_needed": ["Accessoires nécessaires"],
            "learning_styles_addressed": ["Styles d'apprentissage ciblés"]
        }
    ],
    "quick_activities": [
        {
            "id": 1,
            "title": "Titre de l'activité",
            "type": "icebreaker|energizer|review|exit_ticket",
            "description": "Description",
            "duration_minutes": 5,
            "when_to_use": "Quand utiliser (début/milieu/fin du cours)"
        }
    ],
    "assessment_activities": [
        {
            "id": 1,
            "title": "Titre de l'activité",
            "type": "formative|summative|peer|self",
            "description": "Description",
            "duration_minutes": 10,
            "rubric": "Critères d'évaluation simples"
        }
    ]
}

Retournez uniquement le JSON sans texte supplémentaire.
PROMPT;
        } elseif ($language === 'de') {
            return <<<PROMPT
Sie sind ein Experte für die Gestaltung interaktiver und kreativer Unterrichtsaktivitäten.

Aufgabe: Erstellen Sie eine Vielfalt interaktiver Unterrichtsaktivitäten basierend auf dem bereitgestellten Inhalt.

⚠️ Wichtige Anweisungen:
1. Aktivitäten müssen ausschließlich auf dem bereitgestellten Inhalt basieren
2. Variieren Sie die Aktivitätstypen für verschiedene Lernstile
3. Sowohl Einzel- als auch Gruppenaktivitäten einbeziehen
4. Verfügbare Unterrichtszeit berücksichtigen
5. Digitale Aktivitäten mit Werkzeugen wie Padlet, Kahoot, Mentimeter einbeziehen

📋 Folgende Aktivitäten erstellen:

1. Interaktive digitale Aktivitäten (inspiriert von Padlet):
   - Interaktive Ideentafeln
   - Kollaborative Pinnwände zum Sammeln von Informationen
   - Gemeinsame Mindmaps

2. Lernspiele und Quizze (inspiriert von Kahoot):
   - Schnelle Quizze
   - Team-Herausforderungen
   - Ratespiele

3. Sofort-Feedback-Aktivitäten (inspiriert von Mentimeter):
   - Schnelle Umfragen
   - Wortwolken
   - Verständnisskalen

4. Kollaborative Gruppenaktivitäten:
   - Miniprojekte
   - Kurzpräsentationen
   - Geführte Diskussionen

5. Kreative und kinästhetische Aktivitäten:
   - Rollenspiele
   - Simulationen
   - Bewegungsaktivitäten

Geben Sie die Antwort im folgenden JSON-Format zurück:

{
    "digital_activities": [
        {
            "id": 1,
            "title": "Aktivitätstitel",
            "type": "padlet|kahoot|mentimeter|nearpod",
            "description": "Detaillierte Aktivitätsbeschreibung",
            "duration_minutes": 10,
            "instructions": ["Schritt 1", "Schritt 2"],
            "materials_needed": ["Benötigte Materialien"],
            "digital_tool_suggestion": "Vorgeschlagenes digitales Werkzeug",
            "learning_outcome": "Erwartetes Lernergebnis"
        }
    ],
    "collaborative_activities": [
        {
            "id": 1,
            "title": "Aktivitätstitel",
            "type": "group_work|pair_work|whole_class",
            "group_size": "2-4 Schüler",
            "description": "Aktivitätsbeschreibung",
            "duration_minutes": 15,
            "instructions": ["Durchführungsschritte"],
            "assessment_criteria": ["Bewertungskriterien"],
            "differentiation": "Anpassung für verschiedene Niveaus"
        }
    ],
    "creative_activities": [
        {
            "id": 1,
            "title": "Aktivitätstitel",
            "type": "role_play|simulation|art|movement",
            "description": "Aktivitätsbeschreibung",
            "duration_minutes": 10,
            "instructions": ["Durchführungsschritte"],
            "props_needed": ["Benötigte Requisiten oder Materialien"],
            "learning_styles_addressed": ["Angesprochene Lernstile"]
        }
    ],
    "quick_activities": [
        {
            "id": 1,
            "title": "Aktivitätstitel",
            "type": "icebreaker|energizer|review|exit_ticket",
            "description": "Aktivitätsbeschreibung",
            "duration_minutes": 5,
            "when_to_use": "Einsatzzeitpunkt (Anfang/Mitte/Ende der Stunde)"
        }
    ],
    "assessment_activities": [
        {
            "id": 1,
            "title": "Aktivitätstitel",
            "type": "formative|summative|peer|self",
            "description": "Aktivitätsbeschreibung",
            "duration_minutes": 10,
            "rubric": "Einfache Bewertungskriterien"
        }
    ]
}

Geben Sie ausschließlich JSON ohne zusätzlichen Text zurück.
PROMPT;
        } else {
            return <<<PROMPT
You are an expert in designing interactive and creative classroom activities.

Task: Create a variety of interactive classroom activities based on the provided content.

⚠️ Important Instructions:
1. Activities must be based only on the provided content
2. Vary activity types to suit different learning styles
3. Include both individual and group activities
4. Consider available class time
5. Include digital activities using tools like Padlet, Kahoot, Mentimeter

📋 Create the following activities:

1. Interactive digital activities (inspired by Padlet):
   - Interactive idea boards
   - Collaborative walls for collecting information
   - Shared mind maps

2. Educational games and quizzes (inspired by Kahoot):
   - Quick quizzes
   - Team challenges
   - Guessing games

3. Instant feedback activities (inspired by Mentimeter):
   - Quick polls
   - Word clouds
   - Understanding scales

4. Collaborative group activities:
   - Mini projects
   - Short presentations
   - Guided discussions

5. Creative and kinesthetic activities:
   - Role playing
   - Simulations
   - Movement activities

Return the response in the following JSON format:

{
    "digital_activities": [
        {
            "id": 1,
            "title": "Activity title",
            "type": "padlet|kahoot|mentimeter|nearpod",
            "description": "Detailed activity description",
            "duration_minutes": 10,
            "instructions": ["Step 1", "Step 2"],
            "materials_needed": ["Required materials"],
            "digital_tool_suggestion": "Suggested digital tool",
            "learning_outcome": "Expected learning outcome"
        }
    ],
    "collaborative_activities": [
        {
            "id": 1,
            "title": "Activity title",
            "type": "group_work|pair_work|whole_class",
            "group_size": "2-4 students",
            "description": "Activity description",
            "duration_minutes": 15,
            "instructions": ["Execution steps"],
            "assessment_criteria": ["Assessment criteria"],
            "differentiation": "How to adapt for different levels"
        }
    ],
    "creative_activities": [
        {
            "id": 1,
            "title": "Activity title",
            "type": "role_play|simulation|art|movement",
            "description": "Activity description",
            "duration_minutes": 10,
            "instructions": ["Execution steps"],
            "props_needed": ["Required props or materials"],
            "learning_styles_addressed": ["Targeted learning styles"]
        }
    ],
    "quick_activities": [
        {
            "id": 1,
            "title": "Activity title",
            "type": "icebreaker|energizer|review|exit_ticket",
            "description": "Activity description",
            "duration_minutes": 5,
            "when_to_use": "When to use (beginning/middle/end of class)"
        }
    ],
    "assessment_activities": [
        {
            "id": 1,
            "title": "Activity title",
            "type": "formative|summative|peer|self",
            "description": "Activity description",
            "duration_minutes": 10,
            "rubric": "Simple assessment criteria"
        }
    ]
}

Return JSON only without any additional text.
PROMPT;
        }
    }
    
    /**
     * الحصول على prompt الخرائط الذهنية
     * يولّد بيانات JSON غنية لمحرك EduVisual SVG التفاعلي
     */
    public static function getMindMapPrompt($language) {
        // JSON structure shared across all languages
        $jsonStructure = <<<'JSON'
{
    "main_mind_map": {
        "central_node": "Lesson title (3-6 words)",
        "central_icon": "🎯",
        "central_color": "#667eea",
        "branches": [
            {
                "id": 1,
                "title": "Branch title (3-6 words)",
                "icon": "📚",
                "color": "#10b981",
                "shape": "hexagon",
                "sub_branches": [
                    {
                        "id": 1,
                        "title": "Sub-branch (3-6 words)",
                        "details": "Explanation in 1-2 sentences",
                        "icon": "📝",
                        "shape": "rounded_rect"
                    }
                ]
            }
        ]
    },
    "concept_maps": [
        {
            "id": 1,
            "title": "Concept map title",
            "description": "Brief description of the concept relationships",
            "nodes": [
                { "icon": "📚", "label": "Main Concept (3-6 words)" },
                { "icon": "🔗", "label": "Related Concept 1 (3-6 words)" },
                { "icon": "💡", "label": "Related Concept 2 (3-6 words)" },
                { "icon": "📝", "label": "Related Concept 3 (3-6 words)" },
                { "icon": "⚙️", "label": "Related Concept 4 (3-6 words)" },
                { "icon": "🎯", "label": "Related Concept 5 (3-6 words)" }
            ]
        }
    ],
    "fishbone_maps": [
        {
            "id": 1,
            "title": "Fishbone diagram title",
            "description": "Brief description of the analysis",
            "problem": "Main topic or problem to analyze (3-8 words)",
            "categories": [
                {
                    "name": "Category 1 (2-4 words)",
                    "icon": "🔍",
                    "color": "#3b82f6",
                    "causes": ["Cause/Factor 1 (3-6 words)", "Cause/Factor 2 (3-6 words)", "Cause/Factor 3 (3-6 words)"]
                },
                {
                    "name": "Category 2 (2-4 words)",
                    "icon": "⚙️",
                    "color": "#10b981",
                    "causes": ["Cause/Factor 1 (3-6 words)", "Cause/Factor 2 (3-6 words)"]
                },
                {
                    "name": "Category 3 (2-4 words)",
                    "icon": "📊",
                    "color": "#f59e0b",
                    "causes": ["Cause/Factor 1 (3-6 words)", "Cause/Factor 2 (3-6 words)"]
                },
                {
                    "name": "Category 4 (2-4 words)",
                    "icon": "📈",
                    "color": "#8b5cf6",
                    "causes": ["Cause/Factor 1 (3-6 words)", "Cause/Factor 2 (3-6 words)"]
                }
            ]
        }
    ],
    "timeline_maps": [
        {
            "id": 1,
            "title": "Timeline title",
            "description": "Brief description",
            "events": [
                {
                    "id": 1,
                    "label": "Event title (3-6 words)",
                    "description": "Brief explanation in 1-2 sentences",
                    "icon": "📅",
                    "color": "#3b82f6",
                    "period": "Stage/Time label"
                }
            ]
        }
    ],
    "hierarchy_maps": [
        {
            "id": 1,
            "title": "Hierarchy map title",
            "description": "Brief description",
            "root": {
                "label": "Root (3-6 words)",
                "icon": "🏢",
                "color": "#8b5cf6",
                "children": [
                    {
                        "label": "Level 1 (3-6 words)",
                        "icon": "📁",
                        "children": [
                            { "label": "Level 2 (3-6 words)", "icon": "📄" }
                        ]
                    }
                ]
            }
        }
    ],
    "flowchart_maps": [
        {
            "id": 1,
            "title": "Flowchart title",
            "description": "Brief description",
            "steps": [
                { "id": 1, "label": "Start (3-6 words)", "type": "start", "icon": "▶️" },
                { "id": 2, "label": "Step (3-6 words)", "type": "process", "icon": "⚙️" },
                { "id": 3, "label": "Decision? (3-6 words)", "type": "decision", "icon": "❓" },
                { "id": 4, "label": "End (3-6 words)", "type": "end", "icon": "🏁" }
            ]
        }
    ],
    "multi_flow_maps": [
        {
            "id": 1,
            "title": "Multi-flow map title",
            "description": "Brief description",
            "event": { "label": "Main event (3-8 words)", "icon": "⚡" },
            "causes": [
                { "label": "Cause 1 (3-6 words)", "icon": "🔍" }
            ],
            "effects": [
                { "label": "Effect 1 (3-6 words)", "icon": "💡" }
            ]
        }
    ],
    "pyramid_maps": [
        {
            "id": 1,
            "title": "Pyramid map title",
            "description": "Brief description",
            "levels": [
                { "label": "Top (most important)", "description": "Brief explanation", "icon": "⭐", "color": "#ef4444" },
                { "label": "Second level", "description": "Brief explanation", "icon": "🔶", "color": "#f59e0b" },
                { "label": "Third level", "description": "Brief explanation", "icon": "🔷", "color": "#3b82f6" },
                { "label": "Base (widest)", "description": "Brief explanation", "icon": "🟢", "color": "#10b981" }
            ]
        }
    ],
    "circle_maps": [
        {
            "id": 1,
            "title": "Circle map title",
            "description": "Brief description",
            "center": "Central concept (2-5 words)",
            "center_icon": "🎯",
            "context_items": [
                { "text": "Context item 1 (3-6 words)", "icon": "📌" }
            ]
        }
    ],
    "visual_summaries": [
        {
            "id": 1,
            "title": "Summary title",
            "icon": "📋",
            "color": "#f59e0b",
            "key_points": [
                { "point": "Key point (4-8 words)", "icon": "✅", "explanation": "Brief explanation" }
            ]
        }
    ]
}
JSON;

        if ($language === 'ar') {
            return <<<PROMPT
أنت خبير في إنشاء الخرائط الذهنية التعليمية التفاعلية على غرار أدوات مثل Napkin AI.
سيتم عرض البيانات كرسوم SVG تفاعلية (تكبير/تصغير، سحب، تصدير صور).

المطلوب: إنشاء مجموعة شاملة ومتنوعة من الخرائط الذهنية والبصرية بناءً على المحتوى التعليمي المقدم.

⚠️ تعليمات صارمة:
1. اعتمد فقط على المحتوى المقدم - لا تخترع معلومات
2. اجعل كل عقدة مختصرة (3-8 كلمات) لتظهر جيداً في الرسم
3. استخدم ألواناً مختلفة وأيقونات إيموجي متنوعة ومعبرة
4. أنشئ أكبر عدد ممكن من الخرائط المتنوعة

📋 المخرجات المطلوبة (أنشئ الكل):

1. **خريطة ذهنية رئيسية شعاعية** (main_mind_map): 4-6 فروع رئيسية مع 2-4 فروع فرعية لكل منها

2. **1 خريطة عظمة السمكة (إيشيكاوا)** (fishbone_maps): لتحليل موضوع أو ظاهرة من الدرس وتصنيف عواملها في 4-6 فئات، كل فئة تحتوي 2-3 عوامل

3. **1 خط زمني** (timeline_maps): لتسلسل خطوات أو مراحل أو أحداث من الدرس، 5-8 أحداث مع وصف مختصر لكل حدث

4. **1 خريطة هيكلية** (hierarchy_maps): لتصنيف محتوى الدرس هرمياً، 2-3 مستويات

5. **1 خريطة تدفق** (flowchart_maps): لعملية أو تسلسل منطقي من الدرس، 5-8 خطوات (أنواع: start, process, decision, end)

6. **1 خريطة أسباب ونتائج** (multi_flow_maps): لحدث أو ظاهرة من الدرس، 3-4 أسباب + 3-4 نتائج

7. **1 خريطة هرمية** (pyramid_maps): لترتيب المفاهيم حسب الأهمية أو التسلسل، 4-6 مستويات

8. **1 خريطة دائرية** (circle_maps): لتعريف مفهوم مركزي مع سياقه، 6-10 عناصر محيطة

9. **2 ملخصات بصرية** (visual_summaries): 4-6 نقاط رئيسية لكل ملخص

🎨 الألوان المتاحة:
#10b981 • #3b82f6 • #f59e0b • #ef4444 • #8b5cf6 • #ec4899 • #06b6d4 • #f97316

🔷 أشكال العقد المتاحة:
rounded_rect • hexagon • diamond • ellipse • cloud • octagon • pill

أرجع الإجابة بصيغة JSON التالية فقط (بدون أي نص إضافي):

$jsonStructure
PROMPT;
        } elseif ($language === 'fr') {
            return <<<PROMPT
Vous êtes un expert en création de cartes mentales éducatives interactives, similaires à des outils comme Napkin AI.
Les données seront affichées sous forme de diagrammes SVG interactifs (zoom, panoramique, export d'images).

Tâche : Créer un ensemble complet et diversifié de cartes mentales et visuelles basé sur le contenu éducatif fourni.

⚠️ Instructions strictes :
1. Basez-vous uniquement sur le contenu fourni - n'inventez pas d'informations
2. Gardez chaque nœud concis (3-8 mots) pour un bon affichage SVG
3. Utilisez des couleurs différentes et des émojis variés et expressifs
4. Créez le maximum de cartes diversifiées

📋 Sorties requises (créez TOUTES) :

1. **Carte mentale radiale principale** (main_mind_map) : 4-6 branches principales avec 2-4 sous-branches chacune

2. **1 diagramme en arête de poisson (Ishikawa)** (fishbone_maps) : pour analyser un sujet ou phénomène de la leçon et classifier ses facteurs en 4-6 catégories, chaque catégorie contenant 2-3 facteurs

3. **1 ligne chronologique** (timeline_maps) : pour une séquence d'étapes, phases ou événements de la leçon, 5-8 événements avec une brève description chacun

4. **1 carte hiérarchique** (hierarchy_maps) : pour classifier le contenu de la leçon de manière hiérarchique, 2-3 niveaux

5. **1 organigramme** (flowchart_maps) : pour un processus ou séquence logique de la leçon, 5-8 étapes (types : start, process, decision, end)

6. **1 carte causes et effets** (multi_flow_maps) : pour un événement ou phénomène de la leçon, 3-4 causes + 3-4 effets

7. **1 carte pyramidale** (pyramid_maps) : pour ordonner les concepts par importance ou séquence, 4-6 niveaux

8. **1 carte circulaire** (circle_maps) : pour définir un concept central avec son contexte, 6-10 éléments périphériques

9. **2 résumés visuels** (visual_summaries) : 4-6 points clés par résumé

🎨 Couleurs disponibles :
#10b981 • #3b82f6 • #f59e0b • #ef4444 • #8b5cf6 • #ec4899 • #06b6d4 • #f97316

🔷 Formes de nœuds disponibles :
rounded_rect • hexagon • diamond • ellipse • cloud • octagon • pill

Retournez la réponse au format JSON suivant uniquement (sans texte supplémentaire) :

$jsonStructure
PROMPT;
        } elseif ($language === 'de') {
            return <<<PROMPT
Sie sind ein Experte für die Erstellung interaktiver pädagogischer Mindmaps, ähnlich wie Tools wie Napkin AI.
Die Daten werden als interaktive SVG-Diagramme dargestellt (Zoom, Verschieben, Bildexport).

Aufgabe: Erstellen Sie einen umfassenden und vielfältigen Satz von Mindmaps und visuellen Darstellungen basierend auf dem bereitgestellten pädagogischen Inhalt.

⚠️ Strikte Anweisungen:
1. Basieren Sie sich ausschließlich auf dem bereitgestellten Inhalt - erfinden Sie keine Informationen
2. Halten Sie jeden Knoten kurz (3-8 Wörter) für eine korrekte SVG-Darstellung
3. Verwenden Sie verschiedene Farben und vielfältige, ausdrucksstarke Emojis
4. Erstellen Sie die maximale Anzahl an vielfältigen Karten

📋 Erforderliche Ausgaben (erstellen Sie ALLE):

1. **Radiale Haupt-Mindmap** (main_mind_map): 4-6 Hauptzweige mit jeweils 2-4 Unterzweigen

2. **1 Fischgrätendiagramm (Ishikawa)** (fishbone_maps): zur Analyse eines Themas oder Phänomens aus der Lektion und Klassifizierung seiner Faktoren in 4-6 Kategorien, jede Kategorie enthält 2-3 Faktoren

3. **1 Zeitleiste** (timeline_maps): für eine Abfolge von Schritten, Phasen oder Ereignissen der Lektion, 5-8 Ereignisse mit jeweils einer kurzen Beschreibung

4. **1 Hierarchiekarte** (hierarchy_maps): zur hierarchischen Klassifizierung des Lektionsinhalts, 2-3 Ebenen

5. **1 Flussdiagramm** (flowchart_maps): für einen Prozess oder logische Abfolge aus der Lektion, 5-8 Schritte (Typen: start, process, decision, end)

6. **1 Ursache-Wirkungs-Karte** (multi_flow_maps): für ein Ereignis oder Phänomen aus der Lektion, 3-4 Ursachen + 3-4 Wirkungen

7. **1 Pyramidenkarte** (pyramid_maps): zur Anordnung der Konzepte nach Wichtigkeit oder Reihenfolge, 4-6 Ebenen

8. **1 Kreiskarte** (circle_maps): zur Definition eines zentralen Konzepts mit seinem Kontext, 6-10 umgebende Elemente

9. **2 visuelle Zusammenfassungen** (visual_summaries): 4-6 Kernpunkte pro Zusammenfassung

🎨 Verfügbare Farben:
#10b981 • #3b82f6 • #f59e0b • #ef4444 • #8b5cf6 • #ec4899 • #06b6d4 • #f97316

🔷 Verfügbare Knotenformen:
rounded_rect • hexagon • diamond • ellipse • cloud • octagon • pill

Geben Sie die Antwort ausschließlich im folgenden JSON-Format zurück (ohne zusätzlichen Text):

$jsonStructure
PROMPT;
        } else {
            return <<<PROMPT
You are an expert in creating interactive educational mind maps, similar to tools like Napkin AI.
The data will be rendered as interactive SVG diagrams (zoom, pan, image export).

Task: Create a comprehensive and diverse set of mind maps and visual representations based on the provided educational content.

⚠️ Strict Instructions:
1. Base only on the provided content - do not invent information
2. Keep each node concise (3-8 words) for proper SVG rendering
3. Use different colors and varied, expressive emojis
4. Create the maximum number of diverse maps

📋 Required Outputs (create ALL):

1. **Main radial mind map** (main_mind_map): 4-6 main branches with 2-4 sub-branches each

2. **1 fishbone (Ishikawa) diagram** (fishbone_maps): to analyze a topic or phenomenon from the lesson and classify its factors into 4-6 categories, each category containing 2-3 factors

3. **1 timeline** (timeline_maps): for a sequence of steps, phases, or events from the lesson, 5-8 events with a brief description each

4. **1 hierarchy map** (hierarchy_maps): to classify lesson content hierarchically, 2-3 levels

5. **1 flowchart** (flowchart_maps): for a process or logical sequence from the lesson, 5-8 steps (types: start, process, decision, end)

6. **1 cause-and-effect map** (multi_flow_maps): for an event or phenomenon from the lesson, 3-4 causes + 3-4 effects

7. **1 pyramid map** (pyramid_maps): to rank concepts by importance or sequence, 4-6 levels

8. **1 circle map** (circle_maps): to define a central concept with its context, 6-10 surrounding elements

9. **2 visual summaries** (visual_summaries): 4-6 key points per summary

🎨 Available Colors:
#10b981 • #3b82f6 • #f59e0b • #ef4444 • #8b5cf6 • #ec4899 • #06b6d4 • #f97316

🔷 Available Node Shapes:
rounded_rect • hexagon • diamond • ellipse • cloud • octagon • pill

Return the response in the following JSON format only (without any additional text):

$jsonStructure
PROMPT;
        }
    }
    
    /**
     * الحصول على prompt ملخص الدرس ثنائي اللغة (إنجليزي + عربي)
     * Bilingual Lesson Summary Prompt
     */
}
