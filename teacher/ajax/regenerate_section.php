<?php
/**
 * معالج AJAX لإعادة توليد قسم محدد من تحضير الدرس
 * AJAX Handler for regenerating a specific lesson section
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../config/database.php';
require_once '../../config/ai_config.php';
require_once '../../classes/LessonGenerator.php';
require_once '../../classes/ExamGenerator.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
    exit;
}

requireCsrfPost();

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $teacherId = $_SESSION['user_id'];
    session_write_close();
    
    // التحقق من حدود الاستخدام اليومي
    if (!checkDailyLimit($db, $teacherId)) {
        echo json_encode([
            'success' => false, 
            'message' => 'لقد تجاوزت الحد اليومي المسموح. يرجى المحاولة غداً.'
        ]);
        exit;
    }
    
    $lessonId = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;
    $sectionType = isset($_POST['section_type']) ? trim($_POST['section_type']) : (isset($_POST['section']) ? trim($_POST['section']) : '');
    // عمر الطلاب المستهدف للقصة التربوية عند إعادة توليدها (اختياري).
    $studentAge = isset($_POST['student_age']) && $_POST['student_age'] !== '' ? $_POST['student_age'] : null;
    
    // الأقسام المسموح بإعادة توليدها
    $allowedSections = ['lesson_plan', 'question_bank', 'visual_materials', 'class_activities', 'educational_stories', 'mind_maps', 'lesson_summary', 'custom_content', 'exam'];
    
    if (!$lessonId || !in_array($sectionType, $allowedSections)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }
    
    // جلب بيانات الدرس الأصلي
    $stmt = $db->prepare("
        SELECT id, teacher_id, title, language, original_content, duration_minutes, generated_prep,
               question_bank, visual_materials, class_activities, educational_stories, mind_maps,
               lesson_summary, custom_content, exam_html,
               exam_duration, exam_models_count, exam_mc_count, exam_tf_count, exam_essay_count 
        FROM ai_lessons 
        WHERE id = ? AND teacher_id = ?
    ");
    $stmt->execute([$lessonId, $teacherId]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lesson) {
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الدرس']);
        exit;
    }
    
    // إنشاء المولد
    $generator = new LessonGenerator($db, $teacherId);
    $generator->setLanguage($lesson['language']);
    
    $content = $lesson['original_content'];
    $duration = intval($lesson['duration_minutes']);
    $result = null;
    $dbColumn = null;
    
    // التحقق من وجود المحتوى الأصلي
    if (empty(trim($content ?? ''))) {
        echo json_encode(['success' => false, 'message' => 'المحتوى الأصلي للدرس غير موجود. يرجى إعادة توليد الدرس كاملاً.']);
        exit;
    }
    
    // تعيين العناصر المختارة إذا كان القسم هو تحضير الدرس
    $selectedElements = isset($_POST['elements']) ? json_decode($_POST['elements'], true) : null;
    if ($selectedElements && is_array($selectedElements)) {
        $generator->setSelectedElements($selectedElements);
    }

    // عمر الطلاب المستهدف عند إعادة توليد القصة التربوية (إن أُرسل) — يُطبَّع داخل الـ setter.
    if ($studentAge !== null) {
        $generator->setStudentAge($studentAge);
    }
    
    // بنك الأسئلة يتولّد تلقائياً بجميع الأنواع — لا حاجة لأعداد مسبقة
    
    // توليد القسم المطلوب فقط
    switch ($sectionType) {
        case 'lesson_plan':
            $result = $generator->generateLessonPlan($content, $duration);
            $dbColumn = 'generated_prep';
            break;
            
        case 'question_bank':
            $result = $generator->generateQuestionBank($content);
            $dbColumn = 'question_bank';
            break;
            
        case 'visual_materials':
            $result = $generator->generateVisualMaterials($content, $lesson['title'] ?? '');
            $dbColumn = 'visual_materials';
            break;
            
        case 'class_activities':
            $result = $generator->generateClassActivities($content);
            $dbColumn = 'class_activities';
            break;
            
        case 'educational_stories':
            $result = $generator->generateEducationalStories($content);
            $dbColumn = 'educational_stories';
            break;
            
        case 'mind_maps':
            $result = $generator->generateMindMaps($content);
            $dbColumn = 'mind_maps';
            break;
            
        case 'lesson_summary':
            $result = $generator->generateLessonSummary($content);
            $dbColumn = 'lesson_summary';
            break;
            
        case 'custom_content':
            // جلب العناصر المخصصة من الطلب
            $customPrompts = isset($_POST['custom_prompts']) ? json_decode($_POST['custom_prompts'], true) : null;
            if ($customPrompts && is_array($customPrompts)) {
                $customPrompts = array_slice(array_values(array_filter(array_map(
                    static fn($prompt): string => is_string($prompt) ? trim($prompt) : '',
                    $customPrompts
                ))), 0, 6);
            }
            if (empty($customPrompts)) {
                echo json_encode(['success' => false, 'message' => 'لم يتم تحديد عناصر مخصصة لإعادة توليدها. أضف عناصر في قسم المحتوى المخصص.']);
                exit;
            }
            $result = $generator->generateCustomContent($content, $customPrompts);
            // custom_content عمود حقيقي في ai_lessons (migration 20260713_ai_content_runtime_schema.php)
            // و update_section.php يكتب إليه. إعادة التوليد يجب أن تستمرّ بنفس المسار + التدقيق.
            $dbColumn = 'custom_content';
            break;
            
        case 'exam':
            // إعادة توليد الامتحان من بنك الأسئلة الموجود
            $questionBank = !empty($lesson['question_bank']) ? json_decode($lesson['question_bank'], true) : null;
            if (!$questionBank) {
                echo json_encode(['success' => false, 'message' => 'لا يوجد بنك أسئلة لتوليد الامتحان منه. أعد توليد بنك الأسئلة أولاً.']);
                exit;
            }
            $examDuration = max(5, min(180, intval($_POST['exam_duration'] ?? 20)));
            $examModels = max(1, min(4, intval($_POST['exam_models'] ?? 3)));
            $mcCount = max(0, min(50, intval($_POST['mc_count'] ?? 10)));
            $tfCount = max(0, min(50, intval($_POST['tf_count'] ?? 10)));
            $essayCount = max(0, min(20, intval($_POST['essay_count'] ?? 0)));
            $modelType = in_array($_POST['model_type'] ?? '', ['shuffle', 'different'], true) ? $_POST['model_type'] : 'shuffle';
            $antiCheat = isset($_POST['anti_cheat']) && $_POST['anti_cheat'] === '1';
            $studentInfo = isset($_POST['student_info']) && $_POST['student_info'] === '1';
            $examTheme = isset($_POST['exam_theme']) ? $_POST['exam_theme'] : 'classic';
            
            $examGenerator = new ExamGenerator($lesson['language']);
            $examGenerator->setQuestions($questionBank);
            $examGenerator->setDuration($examDuration);
            $examGenerator->setModelsCount($examModels);
            $examGenerator->setPassingPercentage(50);
            $examGenerator->setAntiCheatEnabled($antiCheat);
            $examGenerator->setStudentInfoEnabled($studentInfo);
            $examGenerator->setMCCount($mcCount);
            $examGenerator->setTFCount($tfCount);
            $examGenerator->setEssayCount($essayCount);
            $examGenerator->setModelType($modelType);
            $examGenerator->setTheme($examTheme);
            
            $examTitle = $lesson['language'] === 'ar' ? 'امتحان: ' . $lesson['title'] : 'Exam: ' . $lesson['title'];
            $newExamHtml = $examGenerator->generateExamHTML($examTitle);
            
            if (!$newExamHtml) {
                echo json_encode(['success' => false, 'message' => 'فشل في إعادة توليد الامتحان']);
                exit;
            }
            
            // حفظ الامتحان في معاملة واحدة مع أثر التدقيق.
            try {
                $db->beginTransaction();
                $lockStmt = $db->prepare('SELECT exam_html FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE');
                $lockStmt->execute([$lessonId, $teacherId]);
                $beforeExam = $lockStmt->fetchColumn();
                if ($beforeExam === false) throw new RuntimeException('Lesson not found while saving exam.');
                if ((string) $beforeExam !== (string) ($lesson['exam_html'] ?? '')) {
                    throw new RuntimeException('Lesson exam changed during generation.');
                }
                $examStmt = $db->prepare("UPDATE ai_lessons SET exam_html = ?, exam_duration = ?, exam_models_count = ?, exam_mc_count = ?, exam_tf_count = ?, exam_essay_count = ?, updated_at = NOW() WHERE id = ? AND teacher_id = ?");
                $examStmt->execute([$newExamHtml, $examDuration, $examModels, $mcCount, $tfCount, $essayCount, $lessonId, $teacherId]);
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                    'ai_lesson_section_regenerated',
                    'ai_lesson',
                    $lessonId,
                    (string) $lesson['title'],
                    [
                        'section_type' => 'exam',
                        'before_sha256' => hash('sha256', (string) $beforeExam),
                        'after_sha256' => hash('sha256', (string) $newExamHtml),
                        'before_length' => mb_strlen((string) $beforeExam),
                        'after_length' => mb_strlen((string) $newExamHtml),
                        'question_counts' => ['mc' => $mcCount, 'tf' => $tfCount, 'essay' => $essayCount],
                        'models_count' => $examModels,
                        'direct_undo' => false,
                        'reason' => 'generated_content_restore_not_enabled',
                    ]
                );
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log("Exam regenerate save error: " . $e->getMessage());
                throw new RuntimeException('تعذر حفظ الامتحان المعاد توليده بأمان');
            }
            
            echo json_encode([
                'success' => true,
                'section_type' => 'exam',
                'exam_html' => $newExamHtml,
                'exam_models_count' => $examModels
            ], JSON_UNESCAPED_UNICODE);
            exit;
    }
    
    if (!$result) {
        echo json_encode([
            'success' => false, 
            'message' => 'فشل في إعادة التوليد: ' . $generator->getLastError()
        ]);
        exit;
    }
    
    // حفظ القسم وأي امتحان مشتق منه كوحدة ذرية واحدة.
    $db->beginTransaction();
    $lockStmt = $db->prepare('SELECT * FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE');
    $lockStmt->execute([$lessonId, $teacherId]);
    $lockedLesson = $lockStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lockedLesson) throw new RuntimeException('لم يعد الدرس متاحاً للحفظ');
    if ($dbColumn && (string) ($lockedLesson[$dbColumn] ?? '') !== (string) ($lesson[$dbColumn] ?? '')) {
        throw new RuntimeException('Lesson section changed during generation.');
    }

    if ($dbColumn) {
        // التحقق الإضافي من اسم العمود (defense-in-depth)
        $allowedColumns = ['generated_prep', 'question_bank', 'visual_materials', 'class_activities', 'educational_stories', 'mind_maps', 'lesson_summary', 'custom_content'];
        if (!in_array($dbColumn, $allowedColumns, true)) {
            error_log("Regenerate: Invalid dbColumn '$dbColumn' blocked");
        } else {
            $updateStmt = $db->prepare("
                    UPDATE ai_lessons 
                    SET {$dbColumn} = ?, updated_at = NOW() 
                    WHERE id = ? AND teacher_id = ?
                ");
            $updateStmt->execute([
                    json_encode($result, JSON_UNESCAPED_UNICODE),
                    $lessonId,
                    $teacherId
            ]);
        }
    }
    
    // إعادة توليد الامتحان إذا تم إعادة توليد بنك الأسئلة
    $examHtml = null;
    if ($sectionType === 'question_bank' && $result && is_array($result) && !empty($lesson['exam_html'])) {
        $examGenerator = new ExamGenerator($lesson['language']);
        $examGenerator->setQuestions($result);
        
        // استخدام الإعدادات المحفوظة في الدرس
        $savedDurationRaw = isset($lesson['exam_duration']) ? intval($lesson['exam_duration']) : 0;
        $savedDuration = $savedDurationRaw === 0 ? 0 : max(5, min(180, $savedDurationRaw));
        $savedModels = max(1, min(4, intval($lesson['exam_models_count'] ?? 3)));
        $savedMcCount = max(0, min(50, intval($lesson['exam_mc_count'] ?? 10)));
        $savedTfCount = max(0, min(50, intval($lesson['exam_tf_count'] ?? 10)));
        $savedEssayCount = max(0, min(20, intval($lesson['exam_essay_count'] ?? 0)));
        $existingExamHtml = (string) ($lesson['exam_html'] ?? '');
        $savedModelType = in_array($_POST['model_type'] ?? '', ['shuffle', 'different'], true)
            ? $_POST['model_type']
            : 'shuffle';
        $existingTheme = preg_match('/<html\b[^>]*\bdata-theme="([^"]+)"/i', $existingExamHtml, $themeMatch)
            ? (string) $themeMatch[1]
            : 'classic';
        $savedThemeCandidate = $_POST['exam_theme'] ?? $existingTheme;
        $savedTheme = in_array($savedThemeCandidate, ['classic', 'ocean', 'nature', 'sunset', 'rose', 'dark', 'royal'], true)
            ? $savedThemeCandidate
            : 'classic';
        $savedAntiCheat = array_key_exists('anti_cheat', $_POST)
            ? $_POST['anti_cheat'] === '1'
            : preg_match('/const\s+ANTI_CHEAT_ENABLED\s*=\s*true\s*;/i', $existingExamHtml) === 1;
        $savedStudentInfo = array_key_exists('student_info', $_POST)
            ? $_POST['student_info'] === '1'
            : preg_match('/const\s+STUDENT_INFO_ENABLED\s*=\s*true\s*;/i', $existingExamHtml) === 1;
        
        $examGenerator->setDuration($savedDuration);
        $examGenerator->setModelsCount($savedModels);
        $examGenerator->setPassingPercentage(50);
        $examGenerator->setMCCount($savedMcCount);
        $examGenerator->setTFCount($savedTfCount);
        $examGenerator->setEssayCount($savedEssayCount);
        $examGenerator->setModelType($savedModelType);
        $examGenerator->setAntiCheatEnabled($savedAntiCheat);
        $examGenerator->setStudentInfoEnabled($savedStudentInfo);
        $examGenerator->setTheme($savedTheme);
        
        $examTitle = $lesson['language'] === 'ar' ? 'امتحان: ' . $lesson['title'] : 'Exam: ' . $lesson['title'];
        $examHtml = $examGenerator->generateExamHTML($examTitle);
        
        $examStmt = $db->prepare("UPDATE ai_lessons SET exam_html = ? WHERE id = ? AND teacher_id = ?");
        $examStmt->execute([$examHtml, $lessonId, $teacherId]);
    }

    $encodedResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
        'ai_lesson_section_regenerated',
        'ai_lesson',
        $lessonId,
        (string) $lesson['title'],
        [
            'section_type' => $sectionType,
            'before_sha256' => hash('sha256', (string) ($dbColumn ? ($lockedLesson[$dbColumn] ?? '') : '')),
            'after_sha256' => hash('sha256', $encodedResult),
            'before_length' => mb_strlen((string) ($dbColumn ? ($lockedLesson[$dbColumn] ?? '') : '')),
            'after_length' => mb_strlen($encodedResult),
            'derived_exam_sha256' => $examHtml ? hash('sha256', (string) $examHtml) : null,
            'direct_undo' => false,
            'reason' => 'generated_content_restore_not_enabled',
        ]
    );
    $db->commit();
    
    $response = [
        'success' => true,
        'section_type' => $sectionType,
        'data' => $result
    ];
    
    if ($examHtml) {
        $response['exam_html'] = $examHtml;
    }
    
    // ملخص أعداد بنك الأسئلة المولّدة
    if ($sectionType === 'question_bank' && $result && is_array($result)) {
        $actualMc   = isset($result['multiple_choice']) ? count($result['multiple_choice']) : 0;
        $actualTf   = isset($result['true_false'])      ? count($result['true_false'])      : 0;
        $actualGrad = isset($result['graduated'])       ? count($result['graduated'])       : 0;
        $actualSA   = isset($result['short_answer'])    ? count($result['short_answer'])    : 0;
        $actualFB   = isset($result['fill_blank'])      ? count($result['fill_blank'])      : 0;
        $actualOrd  = isset($result['ordering'])        ? count($result['ordering'])        : 0;
        $actualMatch = isset($result['matching'])       ? count($result['matching'])        : 0;
        $response['qb_counts'] = [
            'mc' => $actualMc, 'tf' => $actualTf, 'graduated' => $actualGrad,
            'short_answer' => $actualSA, 'fill_blank' => $actualFB,
            'ordering' => $actualOrd, 'matching' => $actualMatch,
            'total' => $actualMc + $actualTf + $actualGrad + $actualSA + $actualFB + $actualOrd + $actualMatch
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("Regenerate Section Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log("Regenerate Section Stack: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'تعذر إتمام إعادة التوليد بأمان'
    ]);
}
