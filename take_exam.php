<?php
/**
 * صفحة أداء الامتحان للطلاب
 * Student Exam Taking Page
 */

require_once 'config/database.php';
require_once 'includes/session_config.php';

$examCode = isset($_GET['code']) ? trim($_GET['code']) : '';
$exam = null;
$error = '';

if ($examCode) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $stmt = $db->prepare("
            SELECT e.*, COALESCE(l.title, e.title) as lesson_title 
            FROM ai_online_exams e
            LEFT JOIN ai_lessons l ON e.lesson_id = l.id
            WHERE e.exam_code = ? AND e.is_active = 1
        ");
        $stmt->execute([$examCode]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$exam) {
            $error = 'كود الامتحان غير صالح أو الامتحان غير متاح';
        }
    } catch (Exception $e) {
        $error = 'حدث خطأ في الاتصال بالخادم';
    }
}

// Determine exam theme
$examTheme = 'classic';
if ($exam && isset($exam['exam_theme']) && $exam['exam_theme']) {
    $examTheme = $exam['exam_theme'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo htmlspecialchars($examTheme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $exam ? htmlspecialchars($exam['title']) : 'الامتحان الإلكتروني'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* === Exam Themes === */
        [data-theme="classic"], :root {
            --exam-bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --exam-header: linear-gradient(135deg, #1e3a8a, #3b82f6);
            --exam-primary: #3b82f6;
            --exam-primary-dark: #1e3a8a;
            --exam-primary-light: #dbeafe;
            --exam-primary-hover: #eff6ff;
            --exam-badge: linear-gradient(135deg, #3b82f6, #2563eb);
            --exam-submit: linear-gradient(135deg, #10b981, #059669);
            --exam-submit-hover: rgba(16, 185, 129, 0.4);
            --exam-heading: #1e3a8a;
            --exam-card-bg: #f8fafc;
            --exam-body-bg: #ffffff;
            --exam-container-bg: #ffffff;
            --exam-text: #1e293b;
            --exam-text-secondary: #64748b;
            --exam-text-option: #334155;
            --exam-border: #e2e8f0;
            --exam-input-border: #94a3b8;
            --exam-shadow: rgba(0,0,0,0.3);
            --exam-register-bg: #ffffff;
            --exam-register-title: #1e3a8a;
            --exam-register-label: #334155;
            --exam-info-bg: rgba(254, 243, 199, 1);
            --exam-info-text: #92400e;
            --exam-progress: linear-gradient(90deg, #10b981, #34d399);
            --exam-cheat-overlay: rgba(0,0,0,0.9);
            --exam-result-bg: #ffffff;
            --exam-result-detail-bg: #f8fafc;
        }
        [data-theme="ocean"] {
            --exam-bg: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            --exam-header: linear-gradient(135deg, #023e8a, #0096c7);
            --exam-primary: #0096c7;
            --exam-primary-dark: #023e8a;
            --exam-primary-light: #caf0f8;
            --exam-primary-hover: #e0f7fa;
            --exam-badge: linear-gradient(135deg, #0096c7, #0077b6);
            --exam-submit: linear-gradient(135deg, #00b4d8, #0077b6);
            --exam-submit-hover: rgba(0, 180, 216, 0.4);
            --exam-heading: #023e8a;
        }
        [data-theme="nature"] {
            --exam-bg: linear-gradient(135deg, #2d6a4f 0%, #52b788 100%);
            --exam-header: linear-gradient(135deg, #1b4332, #2d6a4f);
            --exam-primary: #40916c;
            --exam-primary-dark: #1b4332;
            --exam-primary-light: #d8f3dc;
            --exam-primary-hover: #e8f5e9;
            --exam-badge: linear-gradient(135deg, #2d6a4f, #1b4332);
            --exam-submit: linear-gradient(135deg, #40916c, #2d6a4f);
            --exam-submit-hover: rgba(64, 145, 108, 0.4);
            --exam-heading: #1b4332;
        }
        [data-theme="sunset"] {
            --exam-bg: linear-gradient(135deg, #e76f51 0%, #f4a261 100%);
            --exam-header: linear-gradient(135deg, #9c2c10, #e76f51);
            --exam-primary: #e76f51;
            --exam-primary-dark: #9c2c10;
            --exam-primary-light: #fce4d6;
            --exam-primary-hover: #fff3e0;
            --exam-badge: linear-gradient(135deg, #e76f51, #9c2c10);
            --exam-submit: linear-gradient(135deg, #f4a261, #e76f51);
            --exam-submit-hover: rgba(231, 111, 81, 0.4);
            --exam-heading: #9c2c10;
        }
        [data-theme="rose"] {
            --exam-bg: linear-gradient(135deg, #be185d 0%, #ec4899 100%);
            --exam-header: linear-gradient(135deg, #831843, #be185d);
            --exam-primary: #ec4899;
            --exam-primary-dark: #831843;
            --exam-primary-light: #fce7f3;
            --exam-primary-hover: #fdf2f8;
            --exam-badge: linear-gradient(135deg, #ec4899, #be185d);
            --exam-submit: linear-gradient(135deg, #f472b6, #be185d);
            --exam-submit-hover: rgba(236, 72, 153, 0.4);
            --exam-heading: #831843;
        }
        [data-theme="dark"] {
            --exam-bg: linear-gradient(135deg, #1e1e2e 0%, #2d2d44 100%);
            --exam-header: linear-gradient(135deg, #0f0f1a, #1e1e2e);
            --exam-primary: #818cf8;
            --exam-primary-dark: #4f46e5;
            --exam-primary-light: rgba(129, 140, 248, 0.15);
            --exam-primary-hover: rgba(129, 140, 248, 0.1);
            --exam-badge: linear-gradient(135deg, #818cf8, #6366f1);
            --exam-submit: linear-gradient(135deg, #818cf8, #6366f1);
            --exam-submit-hover: rgba(129, 140, 248, 0.4);
            --exam-heading: #a5b4fc;
            --exam-card-bg: #2d2d44;
            --exam-body-bg: #1e1e2e;
            --exam-container-bg: #252540;
            --exam-text: #e2e8f0;
            --exam-text-secondary: #94a3b8;
            --exam-text-option: #cbd5e1;
            --exam-border: #3d3d5c;
            --exam-input-border: #4a4a6a;
            --exam-shadow: rgba(0,0,0,0.5);
            --exam-register-bg: #252540;
            --exam-register-title: #a5b4fc;
            --exam-register-label: #cbd5e1;
            --exam-info-bg: rgba(129, 140, 248, 0.12);
            --exam-info-text: #a5b4fc;
            --exam-progress: linear-gradient(90deg, #34d399, #6ee7b7);
            --exam-cheat-overlay: rgba(0,0,0,0.95);
            --exam-result-bg: #252540;
            --exam-result-detail-bg: #2d2d44;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Cairo', sans-serif;
            background: var(--exam-bg);
            min-height: 100vh;
            padding: 20px;
            direction: rtl;
            user-select: none;
            -webkit-user-select: none;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        /* بطاقة التسجيل */
        .register-card {
            background: var(--exam-register-bg);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px var(--exam-shadow);
            text-align: center;
        }
        
        .register-card h1 {
            color: var(--exam-register-title);
            font-size: 1.8rem;
            margin-bottom: 10px;
        }
        
        .register-card .subtitle {
            color: var(--exam-text-secondary);
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: right;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--exam-register-label);
        }
        
        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid var(--exam-border);
            border-radius: 12px;
            font-size: 1rem;
            font-family: 'Cairo', sans-serif;
            transition: all 0.3s ease;
            background: var(--exam-body-bg);
            color: var(--exam-text);
        }
        
        .form-control:focus {
            border-color: var(--exam-primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
        }
        
        .btn-start {
            background: var(--exam-submit);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }
        
        .btn-start:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px var(--exam-submit-hover);
        }
        
        .btn-start:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
        }
        
        /* الامتحان */
        .exam-container {
            background: var(--exam-container-bg);
            border-radius: 20px;
            box-shadow: 0 20px 60px var(--exam-shadow);
            overflow: hidden;
            display: none;
        }
        
        .exam-header {
            background: var(--exam-header);
            color: white;
            padding: 25px 30px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .exam-title {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .student-info {
            background: rgba(255,255,255,0.15);
            padding: 8px 15px;
            border-radius: 10px;
            font-size: 0.9rem;
        }
        
        .model-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: 600;
        }
        
        .timer-container {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.15);
            padding: 10px 20px;
            border-radius: 10px;
        }
        
        .timer {
            font-size: 1.5rem;
            font-weight: 700;
            font-family: monospace;
        }
        
        .timer.warning { color: #fbbf24; animation: pulse 1s infinite; }
        .timer.danger { color: #ef4444; animation: pulse 0.5s infinite; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .progress-container { margin-top: 15px; }
        
        .progress-bar {
            width: 100%;
            height: 10px;
            background: rgba(255,255,255,0.2);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--exam-progress);
            border-radius: 5px;
            transition: width 0.3s ease;
        }
        
        .progress-text {
            margin-top: 8px;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .exam-body { padding: 30px; background: var(--exam-body-bg); }
        
        .section-title {
            font-size: 1.2rem;
            color: var(--exam-heading);
            margin: 25px 0 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--exam-border);
        }
        
        .question-card {
            background: var(--exam-card-bg);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .question-card:hover { border-color: var(--exam-primary); }
        .question-card.answered { border-color: #10b981; background: #f0fdf4; }
        [data-theme="dark"] .question-card.answered { background: rgba(16, 185, 129, 0.1); }
        
        .question-number {
            background: var(--exam-badge);
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-bottom: 15px;
        }
        
        .question-text {
            font-size: 1.1rem;
            color: var(--exam-text);
            margin-bottom: 20px;
            line-height: 1.8;
        }
        
        .options-list { list-style: none; }
        
        .option-item {
            background: var(--exam-body-bg);
            border: 2px solid var(--exam-border);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }
        
        .option-item:hover { border-color: var(--exam-primary); background: var(--exam-primary-hover); }
        .option-item.selected { border-color: var(--exam-primary); background: var(--exam-primary-light); }
        
        .option-radio {
            width: 22px;
            height: 22px;
            border: 2px solid var(--exam-input-border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .option-item.selected .option-radio {
            border-color: var(--exam-primary);
            background: var(--exam-primary);
        }
        
        .option-item.selected .option-radio::after {
            content: '';
            width: 10px;
            height: 10px;
            background: white;
            border-radius: 50%;
        }
        
        .tf-options {
            display: flex;
            gap: 15px;
        }
        
        .tf-option {
            flex: 1;
            padding: 15px;
            text-align: center;
            border: 2px solid var(--exam-border);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .tf-option:hover { border-color: var(--exam-primary); }
        .tf-option.selected.true { border-color: #10b981; background: #d1fae5; color: #059669; }
        .tf-option.selected.false { border-color: #ef4444; background: #fee2e2; color: #dc2626; }
        [data-theme="dark"] .tf-option.selected.true { background: rgba(16, 185, 129, 0.15); color: #86efac; }
        [data-theme="dark"] .tf-option.selected.false { background: rgba(239, 68, 68, 0.15); color: #fca5a5; }
        
        /* أسئلة مقالية */
        .essay-answer {
            width: 100%;
            min-height: 120px;
            padding: 15px;
            border: 2px solid var(--exam-border);
            border-radius: 12px;
            background: var(--exam-body-bg);
            color: var(--exam-text);
            font-size: 1rem;
            font-family: inherit;
            line-height: 1.8;
            resize: vertical;
            transition: border-color 0.3s ease;
            direction: rtl;
        }
        .essay-answer:focus {
            outline: none;
            border-color: var(--exam-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        .essay-difficulty {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .essay-difficulty.easy { background: #d1fae5; color: #059669; }
        .essay-difficulty.medium { background: #fef3c7; color: #d97706; }
        .essay-difficulty.hard { background: #fee2e2; color: #dc2626; }
        [data-theme="dark"] .essay-difficulty.easy { background: rgba(16,185,129,0.15); color: #86efac; }
        [data-theme="dark"] .essay-difficulty.medium { background: rgba(217,119,6,0.15); color: #fcd34d; }
        [data-theme="dark"] .essay-difficulty.hard { background: rgba(239,68,68,0.15); color: #fca5a5; }
        .essay-note {
            font-size: 0.85rem;
            color: var(--exam-text);
            opacity: 0.6;
            margin-top: 8px;
        }
        
        .btn-submit {
            background: var(--exam-submit);
            color: white;
            border: none;
            padding: 18px 50px;
            border-radius: 15px;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 30px;
            display: block;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px var(--exam-submit-hover);
        }
        
        /* النتيجة */
        .result-card {
            background: var(--exam-result-bg);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            display: none;
            box-shadow: 0 20px 60px var(--exam-shadow);
        }
        
        .result-icon {
            font-size: 5rem;
            margin-bottom: 20px;
        }
        
        .result-icon.passed { color: #10b981; }
        .result-icon.failed { color: #ef4444; }
        
        .result-title {
            font-size: 2rem;
            margin-bottom: 30px;
            color: var(--exam-text);
        }
        
        .result-details {
            background: var(--exam-result-detail-bg);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .result-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--exam-border);
        }
        
        .result-row:last-child { border-bottom: none; }
        
        .result-label { color: var(--exam-text-secondary); }
        .result-value { font-weight: 700; color: var(--exam-text); }
        
        /* رسالة الخطأ */
        .error-card {
            background: var(--exam-register-bg);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 60px var(--exam-shadow);
        }
        
        .error-icon {
            font-size: 4rem;
            color: #ef4444;
            margin-bottom: 20px;
        }
        
        .error-message {
            color: var(--exam-text);
            font-size: 1.2rem;
        }
        
        /* منع الغش */
        .cheating-warning {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--exam-cheat-overlay);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .cheating-warning .content {
            background: var(--exam-register-bg);
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            max-width: 400px;
        }
        
        .cheating-warning .icon {
            font-size: 4rem;
            color: #ef4444;
            margin-bottom: 20px;
        }
        
        .cheating-warning h3 {
            color: var(--exam-text);
            margin-bottom: 15px;
        }
        
        .cheating-warning p {
            color: var(--exam-text-secondary);
            margin-bottom: 20px;
        }
        
        .cheating-warning .btn {
            background: var(--exam-primary);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
            <div class="error-card">
                <div class="error-icon"><i class="fas fa-exclamation-circle"></i></div>
                <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
                <p style="margin-top: 20px; color: #64748b;">تأكد من صحة رابط الامتحان أو تواصل مع معلمك</p>
            </div>
        <?php elseif (!$examCode): ?>
            <div class="error-card">
                <div class="error-icon"><i class="fas fa-link"></i></div>
                <p class="error-message">يرجى استخدام رابط الامتحان الصحيح</p>
            </div>
        <?php else: ?>
            <!-- نموذج التسجيل -->
            <div class="register-card" id="registerCard">
                <h1><i class="fas fa-clipboard-check"></i> <?php echo htmlspecialchars($exam['title']); ?></h1>
                <p class="subtitle">أدخل بياناتك لبدء الامتحان</p>
                
                <form id="registerForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> اسم الطالب</label>
                            <input type="text" class="form-control" id="studentName" required 
                                   placeholder="أدخل اسمك الكامل">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-school"></i> الفصل</label>
                            <input type="text" class="form-control" id="studentClass" required 
                                   placeholder="مثال: 3/أ">
                        </div>
                    </div>
                    
                    <?php if ($exam['models_count'] > 1): ?>
                    <div class="form-group">
                        <label><i class="fas fa-copy"></i> اختر النموذج</label>
                        <select class="form-control" id="modelSelect" required>
                            <?php 
                            $letters = ['A', 'B', 'C', 'D'];
                            for ($i = 0; $i < $exam['models_count']; $i++): 
                            ?>
                            <option value="<?php echo $letters[$i]; ?>">النموذج <?php echo $letters[$i]; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php
                    // حساب عدد الأسئلة الفعلي من بيانات الامتحان
                    $questionsData = json_decode($exam['questions_data'], true);
                    $firstModelQuestions = !empty($questionsData) ? reset($questionsData) : [];
                    $totalQuestionsCount = is_array($firstModelQuestions) ? count($firstModelQuestions) : 0;
                    ?>
                    <div style="background: var(--exam-info-bg); padding: 15px; border-radius: 10px; margin-top: 20px; text-align: right;">
                        <p style="color: var(--exam-info-text); font-size: 0.9rem;">
                            <i class="fas fa-info-circle"></i> 
                            مدة الامتحان: <strong><?php echo ((int)$exam['duration_minutes'] === 0) ? 'وقت مفتوح ∞' : ((int)$exam['duration_minutes'] . ' دقيقة'); ?></strong>
                            | عدد الأسئلة: <strong><?php echo $totalQuestionsCount; ?> سؤال</strong>
                        </p>
                    </div>
                    
                    <button type="submit" class="btn-start" id="startBtn">
                        <i class="fas fa-play"></i> ابدأ الامتحان
                    </button>
                </form>
            </div>
            
            <!-- الامتحان -->
            <div class="exam-container" id="examContainer">
                <div class="exam-header">
                    <div class="header-top">
                        <h1 class="exam-title"><?php echo htmlspecialchars($exam['title']); ?></h1>
                        <span class="student-info" id="displayStudentInfo"></span>
                        <span class="model-badge" id="displayModel">النموذج A</span>
                    </div>
                    <div class="header-top" style="margin-top: 15px;">
                        <div class="timer-container">
                            <i class="fas fa-clock"></i>
                            <span class="timer" id="timer">00:00</span>
                        </div>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill" id="progressFill" style="width: 0%"></div>
                        </div>
                        <p class="progress-text">
                            تمت الإجابة عن <span id="answeredCount">0</span> من <span id="totalQCount"><?php echo $totalQuestionsCount; ?></span> سؤال
                        </p>
                    </div>
                </div>
                
                <div class="exam-body" id="questionsContainer"></div>
                
                <div style="padding: 0 30px 30px;">
                    <button class="btn-submit" id="submitBtn" onclick="submitExam()">
                        <i class="fas fa-paper-plane"></i> إنهاء الامتحان وإرسال الإجابات
                    </button>
                </div>
            </div>
            
            <!-- النتيجة -->
            <div class="result-card" id="resultCard">
                <div class="result-icon" id="resultIcon"><i class="fas fa-check-circle"></i></div>
                <h2 class="result-title" id="resultTitle">تم إنهاء الامتحان</h2>
                
                <div class="result-details">
                    <div class="result-row">
                        <span class="result-label">اسم الطالب</span>
                        <span class="result-value" id="resultName"></span>
                    </div>
                    <div class="result-row">
                        <span class="result-label">الفصل</span>
                        <span class="result-value" id="resultClass"></span>
                    </div>
                    <div class="result-row">
                        <span class="result-label">النموذج</span>
                        <span class="result-value" id="resultModel"></span>
                    </div>
                    <div class="result-row">
                        <span class="result-label">الدرجة</span>
                        <span class="result-value" id="resultScore"></span>
                    </div>
                    <div class="result-row">
                        <span class="result-label">النسبة المئوية</span>
                        <span class="result-value" id="resultPercentage"></span>
                    </div>
                    <div class="result-row">
                        <span class="result-label">الحالة</span>
                        <span class="result-value" id="resultStatus"></span>
                    </div>
                </div>
            </div>
            
            <!-- تحذير الغش -->
            <div class="cheating-warning" id="cheatingWarning">
                <div class="content">
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3>تحذير!</h3>
                    <p>تم رصد محاولة مغادرة الصفحة. عدد المخالفات: <span id="cheatingCount">0</span>/3</p>
                    <button class="btn" onclick="closeCheatingWarning()">العودة للامتحان</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($exam): ?>
    <script>
        // بيانات الامتحان
        const examData = <?php echo $exam['questions_data']; ?>;
        const examId = <?php echo (int)$exam['id']; ?>;
        const duration = <?php echo (int)$exam['duration_minutes']; ?>;
        const passingPercentage = <?php echo (int)$exam['passing_percentage']; ?>;
        const UNLIMITED_TIME = (duration === 0);
        
        let studentName = '';
        let studentClass = '';
        let selectedModel = 'A';
        let questions = [];
        let answers = {};
        let timeRemaining = UNLIMITED_TIME ? 0 : (duration * 60);
        let elapsedTime = 0;
        let timerInterval = null;
        let cheatingAttempts = 0;
        let startTime = null;
        let examSubmitted = false;
        
        // Auto-save exam progress
        const EXAM_PROGRESS_KEY = 'educore_exam_' + examId + '_progress';
        
        function autoSaveProgress() {
            if (examSubmitted || !studentName) return;
            try {
                var progressData = {
                    answers: answers,
                    studentName: studentName,
                    studentClass: studentClass,
                    selectedModel: selectedModel,
                    elapsedTime: Math.round((new Date() - startTime) / 1000),
                    savedAt: new Date().toISOString()
                };
                localStorage.setItem(EXAM_PROGRESS_KEY, JSON.stringify(progressData));
            } catch(e) { /* ignore storage errors */ }
        }
        
        function loadSavedProgress() {
            try {
                var saved = localStorage.getItem(EXAM_PROGRESS_KEY);
                if (!saved) return null;
                return JSON.parse(saved);
            } catch(e) { return null; }
        }
        
        function clearSavedProgress() {
            try { localStorage.removeItem(EXAM_PROGRESS_KEY); } catch(e) {}
        }
        
        function restoreAnswersToUI() {
            Object.keys(answers).forEach(function(qIndex) {
                var qi = parseInt(qIndex);
                var q = questions[qi];
                var card = document.getElementById('question-' + qi);
                if (!card || !q) return;
                if (q.type === 'essay') {
                    var ta = document.getElementById('essay-' + qi);
                    if (ta) { ta.value = answers[qi]; card.classList.add('answered'); }
                } else if (q.type === 'true_false') {
                    var val = answers[qi];
                    card.querySelectorAll('.tf-option').forEach(function(opt) { opt.classList.remove('selected'); });
                    var target = card.querySelector('.tf-option.' + (val === 1 ? 'true' : 'false'));
                    if (target) target.classList.add('selected');
                    card.classList.add('answered');
                } else {
                    var optIndex = answers[qi];
                    card.querySelectorAll('.option-item').forEach(function(opt, i) { opt.classList.toggle('selected', i === optIndex); });
                    card.classList.add('answered');
                }
            });
            updateProgress();
        }
        
        // بدء الامتحان
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            studentName = document.getElementById('studentName').value.trim();
            studentClass = document.getElementById('studentClass').value.trim();
            
            const modelSelect = document.getElementById('modelSelect');
            selectedModel = modelSelect ? modelSelect.value : 'A';
            
            if (!studentName || !studentClass) {
                alert('يرجى إدخال جميع البيانات');
                return;
            }
            
            // تحميل الأسئلة
            questions = examData[selectedModel] || examData['A'];
            
            // عرض معلومات الطالب
            document.getElementById('displayStudentInfo').textContent = studentName + ' - ' + studentClass;
            document.getElementById('displayModel').textContent = 'النموذج ' + selectedModel;
            
            // إخفاء التسجيل وعرض الامتحان
            document.getElementById('registerCard').style.display = 'none';
            document.getElementById('examContainer').style.display = 'block';
            
            // عرض الأسئلة
            renderQuestions();
            
            // استعادة الإجابات المحفوظة
            var savedProgress = loadSavedProgress();
            if (savedProgress && savedProgress.selectedModel === selectedModel) {
                answers = savedProgress.answers || {};
                restoreAnswersToUI();
            }
            
            // بدء المؤقت
            startTime = new Date();
            startTimer();
            
            // بدء الحفظ التلقائي كل 30 ثانية
            setInterval(autoSaveProgress, 30000);
            
            // تفعيل منع الغش
            enableCheatingPrevention();
        });
        
        // عرض الأسئلة
        function renderQuestions() {
            const container = document.getElementById('questionsContainer');
            let html = '';
            
            // فصل الأسئلة حسب النوع
            const mcQuestions = questions.filter(q => q.type === 'multiple_choice');
            const tfQuestions = questions.filter(q => q.type === 'true_false');
            const essayQuestions = questions.filter(q => q.type === 'essay');
            
            let questionNum = 0;
            
            // أسئلة الاختيار من متعدد
            if (mcQuestions.length > 0) {
                html += '<h3 class="section-title"><i class="fas fa-list"></i> أسئلة الاختيار من متعدد</h3>';
                mcQuestions.forEach((q, i) => {
                    const qIndex = questions.indexOf(q);
                    questionNum++;
                    html += `
                        <div class="question-card" id="question-${qIndex}">
                            <div class="question-number">${questionNum}</div>
                            <div class="question-text">${q.question}</div>
                            <ul class="options-list">
                                ${q.options.map((opt, optIndex) => `
                                    <li class="option-item" onclick="selectOption(${qIndex}, ${optIndex})">
                                        <span class="option-radio"></span>
                                        <span>${opt}</span>
                                    </li>
                                `).join('')}
                            </ul>
                        </div>
                    `;
                });
            }
            
            // أسئلة صح/خطأ
            if (tfQuestions.length > 0) {
                html += '<h3 class="section-title"><i class="fas fa-check-double"></i> أسئلة صح أو خطأ</h3>';
                tfQuestions.forEach((q, i) => {
                    const qIndex = questions.indexOf(q);
                    questionNum++;
                    html += `
                        <div class="question-card" id="question-${qIndex}">
                            <div class="question-number">${questionNum}</div>
                            <div class="question-text">${q.question}</div>
                            <div class="tf-options">
                                <div class="tf-option true" onclick="selectTF(${qIndex}, 1)">
                                    <i class="fas fa-check"></i> صح
                                </div>
                                <div class="tf-option false" onclick="selectTF(${qIndex}, 0)">
                                    <i class="fas fa-times"></i> خطأ
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            // الأسئلة المقالية
            if (essayQuestions.length > 0) {
                const diffLabels = { easy: 'سهل', medium: 'متوسط', hard: 'صعب' };
                html += '<h3 class="section-title"><i class="fas fa-pen-fancy"></i> الأسئلة المقالية</h3>';
                essayQuestions.forEach((q, i) => {
                    const qIndex = questions.indexOf(q);
                    questionNum++;
                    const diff = q.difficulty || 'medium';
                    html += `
                        <div class="question-card" id="question-${qIndex}">
                            <div class="question-number">${questionNum}</div>
                            <span class="essay-difficulty ${diff}">${diffLabels[diff] || diff}</span>
                            <div class="question-text">${q.question}</div>
                            <textarea class="essay-answer" id="essay-${qIndex}" placeholder="اكتب إجابتك هنا..." oninput="updateEssayAnswer(${qIndex})"></textarea>
                            <div class="essay-note"><i class="fas fa-info-circle"></i> سيتم تصحيح هذا السؤال يدوياً من قبل المعلم</div>
                        </div>
                    `;
                });
            }
            
            container.innerHTML = html;
        }
        
        // اختيار إجابة (اختيار من متعدد)
        function selectOption(qIndex, optIndex) {
            answers[qIndex] = optIndex;
            
            // تحديث المظهر
            const card = document.getElementById(`question-${qIndex}`);
            card.querySelectorAll('.option-item').forEach((opt, i) => {
                opt.classList.toggle('selected', i === optIndex);
            });
            card.classList.add('answered');
            
            updateProgress();
            autoSaveProgress();
        }
        
        // اختيار إجابة (صح/خطأ)
        function selectTF(qIndex, value) {
            answers[qIndex] = value;
            
            // تحديث المظهر
            const card = document.getElementById(`question-${qIndex}`);
            card.querySelectorAll('.tf-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            card.querySelector(`.tf-option.${value === 1 ? 'true' : 'false'}`).classList.add('selected');
            card.classList.add('answered');
            
            updateProgress();
            autoSaveProgress();
        }
        
        // إجابة سؤال مقالي
        function updateEssayAnswer(qIndex) {
            const textarea = document.getElementById(`essay-${qIndex}`);
            const text = textarea.value.trim();
            const card = document.getElementById(`question-${qIndex}`);
            if (text.length > 0) {
                answers[qIndex] = text;
                card.classList.add('answered');
            } else {
                delete answers[qIndex];
                card.classList.remove('answered');
            }
            updateProgress();
            autoSaveProgress();
        }
        
        // تحديث شريط التقدم
        function updateProgress() {
            const answered = Object.keys(answers).length;
            const total = questions.length;
            const percentage = (answered / total) * 100;
            
            document.getElementById('progressFill').style.width = percentage + '%';
            document.getElementById('answeredCount').textContent = answered;
        }
        
        // المؤقت
        function startTimer() {
            if (UNLIMITED_TIME) {
                // وقت مفتوح - عداد تصاعدي
                document.getElementById('timer').textContent = '00:00';
                timerInterval = setInterval(() => {
                    elapsedTime++;
                    const minutes = Math.floor(elapsedTime / 60);
                    const seconds = elapsedTime % 60;
                    const timerEl = document.getElementById('timer');
                    timerEl.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                }, 1000);
                return;
            }
            timerInterval = setInterval(() => {
                timeRemaining--;
                
                const minutes = Math.floor(timeRemaining / 60);
                const seconds = timeRemaining % 60;
                const timerEl = document.getElementById('timer');
                
                timerEl.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                
                // تحذيرات الوقت
                if (timeRemaining <= 60) {
                    timerEl.className = 'timer danger';
                } else if (timeRemaining <= 300) {
                    timerEl.className = 'timer warning';
                }
                
                // انتهاء الوقت
                if (timeRemaining <= 0) {
                    clearInterval(timerInterval);
                    submitExam(true);
                }
            }, 1000);
        }
        
        // منع الغش
        function enableCheatingPrevention() {
            // مغادرة الصفحة
            document.addEventListener('visibilitychange', () => {
                if (document.hidden && !examSubmitted) {
                    cheatingAttempts++;
                    showCheatingWarning();
                }
            });
            
            // النقر خارج الصفحة
            window.addEventListener('blur', () => {
                if (!examSubmitted) {
                    cheatingAttempts++;
                    showCheatingWarning();
                }
            });
            
            // منع النقر اليمين
            document.addEventListener('contextmenu', e => e.preventDefault());
            
            // منع النسخ
            document.addEventListener('copy', e => e.preventDefault());
            
            // منع بعض اختصارات لوحة المفاتيح
            document.addEventListener('keydown', e => {
                if (e.ctrlKey && ['c', 'v', 'u', 'p', 's'].includes(e.key.toLowerCase())) {
                    e.preventDefault();
                }
                if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && e.key === 'I')) {
                    e.preventDefault();
                }
            });
        }
        
        function showCheatingWarning() {
            document.getElementById('cheatingCount').textContent = cheatingAttempts;
            document.getElementById('cheatingWarning').style.display = 'flex';
            
            if (cheatingAttempts >= 3) {
                submitExam(true, 'cheating');
            }
        }
        
        function closeCheatingWarning() {
            document.getElementById('cheatingWarning').style.display = 'none';
        }
        
        // إرسال الامتحان
        async function submitExam(forced = false, reason = '') {
            if (examSubmitted) return;
            
            if (!forced) {
                const unanswered = questions.length - Object.keys(answers).length;
                if (unanswered > 0) {
                    const confirm = window.confirm(`يوجد ${unanswered} سؤال غير مجاب عنه. هل تريد الإرسال؟`);
                    if (!confirm) return;
                }
            }
            
            examSubmitted = true;
            clearInterval(timerInterval);
            clearSavedProgress();
            
            // حساب النتيجة - الأسئلة المقالية لا تُصحح تلقائياً
            let correct = 0;
            const autoGradedQuestions = questions.filter(q => q.type !== 'essay');
            const essayQuestions = questions.filter(q => q.type === 'essay');
            autoGradedQuestions.forEach(q => {
                const i = questions.indexOf(q);
                if (answers[i] !== undefined && answers[i] === q.correct) {
                    correct++;
                }
            });
            
            // جمع إجابات الأسئلة المقالية
            const essayAnswers = {};
            essayQuestions.forEach(q => {
                const i = questions.indexOf(q);
                essayAnswers[i] = answers[i] || '';
            });
            
            const score = correct;
            const total = autoGradedQuestions.length;
            const percentage = total > 0 ? Math.round((correct / total) * 100) : 0;
            const essayOnly = (total === 0 && essayQuestions.length > 0);
            const passed = essayOnly ? null : (percentage >= passingPercentage);
            const timeSpent = Math.round((new Date() - startTime) / 1000);
            
            // إرسال للخادم
            try {
                const response = await fetch('teacher/ajax/submit_exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                        exam_id: examId,
                        student_name: studentName,
                        student_class: studentClass,
                        model_letter: selectedModel,
                        score: score,
                        total_questions: total,
                        correct_answers: correct,
                        percentage: percentage,
                        passed: passed ? 1 : 0,
                        time_spent: timeSpent,
                        answers: answers,
                        essay_answers: essayAnswers,
                        essay_count: essayQuestions.length,
                        cheating_attempts: cheatingAttempts
                    })
                });
                
                const result = await response.json();
                console.log('Result saved:', result);
            } catch (error) {
                console.error('Error saving result:', error);
            }
            
            // عرض النتيجة
            const hasEssay = essayQuestions.length > 0;
            showResult(score, total, percentage, passed, reason, hasEssay, essayQuestions.length, essayOnly);
        }
        
        function showResult(score, total, percentage, passed, reason, hasEssay = false, essayCount = 0, essayOnly = false) {
            document.getElementById('examContainer').style.display = 'none';
            document.getElementById('resultCard').style.display = 'block';
            
            const icon = document.getElementById('resultIcon');
            const title = document.getElementById('resultTitle');
            
            if (reason === 'cheating') {
                icon.innerHTML = '<i class="fas fa-ban"></i>';
                icon.className = 'result-icon failed';
                title.textContent = 'تم إنهاء الامتحان بسبب مخالفات';
            } else if (essayOnly) {
                icon.innerHTML = '<i class="fas fa-pen-fancy"></i>';
                icon.className = 'result-icon passed';
                title.textContent = 'تم إرسال إجاباتك بنجاح';
                title.style.color = '#f59e0b';
            } else if (passed) {
                icon.innerHTML = '<i class="fas fa-check-circle"></i>';
                icon.className = 'result-icon passed';
                title.textContent = 'مبروك! لقد نجحت في الامتحان';
                title.style.color = '#10b981';
            } else {
                icon.innerHTML = '<i class="fas fa-times-circle"></i>';
                icon.className = 'result-icon failed';
                title.textContent = 'للأسف، لم تحقق درجة النجاح';
                title.style.color = '#ef4444';
            }
            
            document.getElementById('resultName').textContent = studentName;
            document.getElementById('resultClass').textContent = studentClass;
            document.getElementById('resultModel').textContent = selectedModel;
            
            if (essayOnly) {
                document.getElementById('resultScore').textContent = 'بانتظار التصحيح';
                document.getElementById('resultPercentage').textContent = '-';
                document.getElementById('resultStatus').textContent = 'بانتظار تصحيح المعلم';
                document.getElementById('resultStatus').style.color = '#f59e0b';
            } else {
                document.getElementById('resultScore').textContent = score + ' / ' + total;
                document.getElementById('resultPercentage').textContent = percentage + '%';
                document.getElementById('resultStatus').textContent = passed ? 'ناجح ✓' : 'راسب ✗';
                document.getElementById('resultStatus').style.color = passed ? '#10b981' : '#ef4444';
            }
            
            // إضافة ملاحظة الأسئلة المقالية
            if (hasEssay && reason !== 'cheating') {
                const resultCard = document.getElementById('resultCard');
                const essayNote = document.createElement('div');
                essayNote.style.cssText = 'margin-top: 20px; padding: 15px; background: #fef3c7; border-radius: 12px; text-align: center; color: #92400e; font-size: 0.95rem;';
                essayNote.innerHTML = '<i class="fas fa-pen-fancy"></i> يوجد <strong>' + essayCount + '</strong> سؤال مقالي سيتم تصحيحه من قبل المعلم. الدرجة النهائية قد تتغير.';
                resultCard.appendChild(essayNote);
            }
        }
    </script>
    <?php endif; ?>
</body>
</html>
