<?php
/**
 * صفحة تصحيح الأسئلة المقالية
 * Essay Grading Page for Teachers
 */

require_once '../includes/session_config.php';
require_once '../config/database.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    header('Location: ../index.php');
    exit;
}

$teacherId = $_SESSION['user_id'];
$examId = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;

if (!$examId) {
    header('Location: lesson_archive.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Verify exam ownership
$stmt = $db->prepare("SELECT e.*, l.title as lesson_title, l.question_bank 
    FROM ai_online_exams e 
    JOIN ai_lessons l ON e.lesson_id = l.id 
    WHERE e.id = ? AND e.teacher_id = ?");
$stmt->execute([$examId, $teacherId]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    header('Location: lesson_archive.php');
    exit;
}

// Get results with essay answers
$stmt = $db->prepare("SELECT * FROM ai_exam_results WHERE exam_id = ? ORDER BY submitted_at DESC");
$stmt->execute([$examId]);
$allResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter results that have essay questions
$results = [];
foreach ($allResults as $r) {
    $answers = json_decode($r['answers_data'], true);
    if (!$answers) continue;
    $hasEssay = false;
    foreach ($answers as $key => $val) {
        if (strpos($key, 'essay_') === 0) { $hasEssay = true; break; }
    }
    if (isset($answers['_has_essay']) && $answers['_has_essay']) $hasEssay = true;
    if ($hasEssay) $results[] = $r;
}

// Get essay questions from question bank
$qb = json_decode($exam['question_bank'], true);
$essayQuestions = [];
if ($qb && isset($qb['essay'])) {
    $essayQuestions = $qb['essay'];
}

// Handle POST grading
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'رمز الحماية غير صالح';
        header("Location: essay_grading.php?exam_id=$examId");
        exit;
    }
    
    try {
        $resultId = intval($_POST['result_id']);
        $grades = $_POST['essay_grade'] ?? [];
    
    $db->beginTransaction();
    // Verify result belongs to this exam
    $stmt = $db->prepare("SELECT * FROM ai_exam_results WHERE id = ? AND exam_id = ? FOR UPDATE");
    $stmt->execute([$resultId, $examId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $essayGrades = [];
        $totalEssayScore = 0;
        $maxEssayScore = 0;
        
        foreach ($grades as $qIndex => $grade) {
            $score = max(0, floatval($grade));
            $maxScore = floatval($_POST['essay_max'][$qIndex] ?? 10);
            $score = min($score, $maxScore);
            $essayGrades[$qIndex] = [
                'score' => $score,
                'max' => $maxScore,
                'feedback' => $_POST['essay_feedback'][$qIndex] ?? ''
            ];
            $totalEssayScore += $score;
            $maxEssayScore += $maxScore;
        }
        
        // Calculate final score combining MCQ/TF + Essay
        $mcScore = $result['correct_answers'];
        $mcTotal = $result['total_questions'];
        $totalScore = $mcScore + $totalEssayScore;
        $totalMax = $mcTotal + $maxEssayScore;
        $finalPercentage = $totalMax > 0 ? round(($totalScore / $totalMax) * 100, 1) : 0;
        
        $stmt = $db->prepare("UPDATE ai_exam_results SET 
            essay_grades = ?, essay_graded = 1, essay_graded_by = ?, essay_graded_at = NOW(),
            final_score = ?, percentage = ?
            WHERE id = ?");
        $encodedGrades = json_encode($essayGrades, JSON_UNESCAPED_UNICODE);
        $stmt->execute([
            $encodedGrades,
            $teacherId,
            $totalScore,
            $finalPercentage,
            $resultId
        ]);
        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
            'grade', 'online_exam_result', $resultId,
            (string)($result['student_name'] ?? ('نتيجة #' . $resultId)),
            [
                'exam_id' => $examId,
                'grader_id' => (int)$teacherId,
                'essay_item_count' => count($essayGrades),
                'grades_fingerprint' => hash('sha256', (string)$encodedGrades),
                'percentage_before' => (float)$result['percentage'],
                'percentage_after' => $finalPercentage,
                'undo_policy' => 'exam_grading_review_required',
            ]
        );
        $db->commit();
        $_SESSION['success_message'] = 'تم حفظ درجات الأسئلة المقالية بنجاح';
    } else {
        $db->rollBack();
        $_SESSION['error_message'] = 'النتيجة غير موجودة';
    }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Essay grading page error: ' . $e->getMessage());
        $_SESSION['error_message'] = 'تعذر حفظ درجات الأسئلة المقالية.';
    }

    header("Location: essay_grading.php?exam_id=$examId");
    exit;
}

$selectedResultId = isset($_GET['result_id']) ? intval($_GET['result_id']) : 0;
$selectedResult = null;
if ($selectedResultId) {
    foreach ($results as $r) {
        if ($r['id'] == $selectedResultId) {
            $selectedResult = $r;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تصحيح المقالي - <?php echo htmlspecialchars($exam['lesson_title']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            min-height: 100vh;
            direction: rtl;
        }
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 25px 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .student-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 8px;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
        }
        .student-list-item:hover { background: #fffbeb; border-color: #f59e0b; }
        .student-list-item.active { background: #fffbeb; border-color: #f59e0b; border-width: 2px; }
        .student-list-item.graded { border-right: 4px solid #10b981; }
        .student-list-item.ungraded { border-right: 4px solid #ef4444; }
        .essay-question-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        .student-answer {
            background: white;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            min-height: 60px;
            line-height: 1.8;
            color: #334155;
        }
        .model-answer {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            color: #166534;
        }
        .grade-input {
            width: 80px;
            text-align: center;
            border: 2px solid #d1d5db;
            border-radius: 8px;
            padding: 8px;
            font-size: 1.1rem;
            font-weight: 700;
            font-family: 'Cairo', sans-serif;
        }
        .grade-input:focus { border-color: #f59e0b; outline: none; box-shadow: 0 0 0 3px rgba(245,158,11,0.2); }
        .badge-graded { background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; }
        .badge-ungraded { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; }
        .back-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 20px; background: white; color: #1e293b;
            border-radius: 10px; text-decoration: none; font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.2s;
        }
        .back-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); color: #1e293b; }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 style="color: #92400e; margin-bottom: 5px;">
                        <i class="fas fa-pen-fancy me-2"></i>تصحيح الأسئلة المقالية
                    </h2>
                    <p style="color: #78716c; margin: 0;">
                        <?php echo htmlspecialchars($exam['lesson_title']); ?> — 
                        <?php echo count($results); ?> طالب بأسئلة مقالية
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="exam_results.php?exam_id=<?php echo $examId; ?>" class="back-btn">
                        <i class="fas fa-arrow-right"></i> العودة للنتائج
                    </a>
                </div>
            </div>
        </div>
        
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (empty($results)): ?>
        <div class="content-card text-center py-5">
            <i class="fas fa-inbox" style="font-size: 3rem; color: #d1d5db; margin-bottom: 15px;"></i>
            <h4 style="color: #64748b;">لا توجد إجابات مقالية</h4>
            <p style="color: #94a3b8;">لم يقم أي طالب بإرسال إجابات مقالية بعد</p>
        </div>
        <?php else: ?>
        <div class="row">
            <!-- Student list sidebar -->
            <div class="col-md-4">
                <div class="content-card">
                    <h5 style="margin-bottom: 15px; color: #92400e;"><i class="fas fa-users me-2"></i>الطلاب</h5>
                    <?php foreach ($results as $r): 
                        $isGraded = !empty($r['essay_graded']);
                        $isActive = $selectedResultId == $r['id'];
                    ?>
                    <a href="essay_grading.php?exam_id=<?php echo $examId; ?>&result_id=<?php echo $r['id']; ?>" 
                       class="student-list-item <?php echo $isGraded ? 'graded' : 'ungraded'; ?> <?php echo $isActive ? 'active' : ''; ?>">
                        <div>
                            <strong><?php echo htmlspecialchars($r['student_name']); ?></strong>
                            <div style="font-size: 0.8rem; color: #78716c;">
                                <?php echo htmlspecialchars($r['student_class']); ?> — 
                                نموذج <?php echo htmlspecialchars($r['model_letter']); ?>
                            </div>
                        </div>
                        <?php if ($isGraded): ?>
                        <span class="badge-graded"><i class="fas fa-check me-1"></i>تم التصحيح</span>
                        <?php else: ?>
                        <span class="badge-ungraded"><i class="fas fa-clock me-1"></i>بانتظار</span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Grading area -->
            <div class="col-md-8">
                <?php if ($selectedResult): 
                    $studentAnswers = json_decode($selectedResult['answers_data'], true);
                    $existingGrades = json_decode($selectedResult['essay_grades'] ?? '{}', true);
                ?>
                <div class="content-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 style="color: #1e293b; margin: 0;">
                            <i class="fas fa-user-graduate me-2" style="color: #f59e0b;"></i>
                            <?php echo htmlspecialchars($selectedResult['student_name']); ?>
                        </h5>
                        <div style="font-size: 0.85rem; color: #64748b;">
                            MCQ/TF: <?php echo $selectedResult['correct_answers']; ?>/<?php echo $selectedResult['total_questions']; ?>
                        </div>
                    </div>
                    
                    <form method="POST" action="essay_grading.php?exam_id=<?php echo $examId; ?>&result_id=<?php echo $selectedResult['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="result_id" value="<?php echo $selectedResult['id']; ?>">
                        <input type="hidden" name="save_grades" value="1">
                        
                        <?php 
                        $essayIndex = 0;
                        foreach ($studentAnswers as $key => $answer):
                            if (strpos($key, 'essay_') !== 0) continue;
                            $qNum = intval(str_replace('essay_', '', $key));
                            $essayQ = isset($essayQuestions[$qNum]) ? $essayQuestions[$qNum] : null;
                            $maxScore = 10;
                            $existingScore = isset($existingGrades[$essayIndex]['score']) ? $existingGrades[$essayIndex]['score'] : '';
                            $existingFeedback = isset($existingGrades[$essayIndex]['feedback']) ? $existingGrades[$essayIndex]['feedback'] : '';
                        ?>
                        <div class="essay-question-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 style="color: #92400e; font-weight: 700;">
                                    <i class="fas fa-question-circle me-1"></i>السؤال <?php echo $essayIndex + 1; ?>
                                </h6>
                                <div class="d-flex align-items-center gap-2">
                                    <input type="number" name="essay_grade[<?php echo $essayIndex; ?>]" 
                                           class="grade-input" min="0" max="<?php echo $maxScore; ?>" step="0.5"
                                           value="<?php echo htmlspecialchars($existingScore); ?>" 
                                           placeholder="?" required>
                                    <span style="color: #64748b; font-weight: 600;">/ <?php echo $maxScore; ?></span>
                                    <input type="hidden" name="essay_max[<?php echo $essayIndex; ?>]" value="<?php echo $maxScore; ?>">
                                </div>
                            </div>
                            
                            <?php if ($essayQ): ?>
                            <p style="color: #1e293b; font-weight: 600; margin-bottom: 10px;">
                                <?php echo htmlspecialchars($essayQ['question'] ?? $essayQ['text'] ?? ''); ?>
                            </p>
                            <?php endif; ?>
                            
                            <div style="font-size: 0.85rem; color: #64748b; font-weight: 600; margin-bottom: 5px;">
                                <i class="fas fa-pen me-1"></i>إجابة الطالب:
                            </div>
                            <div class="student-answer">
                                <?php echo nl2br(htmlspecialchars($answer)); ?>
                            </div>
                            
                            <?php if ($essayQ && !empty($essayQ['model_answer'])): ?>
                            <div style="font-size: 0.85rem; color: #166534; font-weight: 600; margin-bottom: 5px;">
                                <i class="fas fa-check-circle me-1"></i>الإجابة النموذجية:
                            </div>
                            <div class="model-answer">
                                <?php echo nl2br(htmlspecialchars($essayQ['model_answer'])); ?>
                            </div>
                            <?php endif; ?>
                            
                            <div style="margin-top: 10px;">
                                <label style="font-size: 0.85rem; color: #64748b; font-weight: 600;">
                                    <i class="fas fa-comment me-1"></i>ملاحظات (اختياري):
                                </label>
                                <textarea name="essay_feedback[<?php echo $essayIndex; ?>]" 
                                          class="form-control mt-1" rows="2" 
                                          placeholder="أضف ملاحظاتك هنا..."
                                          style="border-radius: 8px; font-family: 'Cairo', sans-serif;"
                                ><?php echo htmlspecialchars($existingFeedback); ?></textarea>
                            </div>
                        </div>
                        <?php 
                        $essayIndex++;
                        endforeach; 
                        ?>
                        
                        <?php if ($essayIndex > 0): ?>
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <a href="essay_grading.php?exam_id=<?php echo $examId; ?>" class="btn btn-danger">
                                <i class="fas fa-times me-1"></i>إلغاء
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save me-1"></i>حفظ الدرجات
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-3" style="color: #94a3b8;">
                            <i class="fas fa-info-circle me-1"></i>لا توجد إجابات مقالية لهذا الطالب
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <?php else: ?>
                <div class="content-card text-center py-5">
                    <i class="fas fa-hand-pointer" style="font-size: 3rem; color: #f59e0b; margin-bottom: 15px;"></i>
                    <h4 style="color: #64748b;">اختر طالباً من القائمة</h4>
                    <p style="color: #94a3b8;">انقر على اسم الطالب لعرض إجاباته المقالية وتصحيحها</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
