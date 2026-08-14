<?php
/**
 * صفحة عرض نتائج الامتحان
 * Exam Results Page for Teachers
 */

require_once '../includes/session_config.php';
require_once '../config/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit;
}

$teacherId = $_SESSION['user_id'];
$teacherName = $_SESSION['name'] ?? 'المعلم';

$examId = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$exam = null;
$results = [];
$stats = [];

if ($examId) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // الحصول على معلومات الامتحان
        $stmt = $db->prepare("
            SELECT e.*, l.title as lesson_title 
            FROM ai_online_exams e
            JOIN ai_lessons l ON e.lesson_id = l.id
            WHERE e.id = ? AND e.teacher_id = ?
        ");
        $stmt->execute([$examId, $teacherId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exam) {
            // الحصول على النتائج
            $stmt = $db->prepare("
                SELECT * FROM ai_exam_results 
                WHERE exam_id = ? 
                ORDER BY submitted_at DESC
            ");
            $stmt->execute([$examId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // حساب الإحصائيات
            if (count($results) > 0) {
                $stats['total'] = count($results);
                $stats['passed'] = count(array_filter($results, fn($r) => $r['passed']));
                $stats['failed'] = $stats['total'] - $stats['passed'];
                $stats['pass_rate'] = round(($stats['passed'] / $stats['total']) * 100);
                $stats['avg_score'] = round(array_sum(array_column($results, 'percentage')) / $stats['total'], 1);
                $stats['highest'] = max(array_column($results, 'percentage'));
                $stats['lowest'] = min(array_column($results, 'percentage'));
            }
        }
    } catch (Exception $e) {
        error_log("Exam Results Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="../assets/js/datatable-state.js?v=3"></script>
    <title>نتائج الامتحان - <?php echo $exam ? htmlspecialchars($exam['title']) : 'غير موجود'; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="../assets/css/premium-dashboard.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            direction: rtl;
            transition: all 0.3s ease;
        }
        
        body.dark-mode {
            background: linear-gradient(135deg, #1e3a8a 0%, #4c1d95 100%);
        }
        
        .main-container {
            flex: 1;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            background: white;
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        body.dark-mode .page-header {
            background: #1e293b;
        }
        
        .page-title {
            color: #1e293b;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        body.dark-mode .page-title {
            color: #f1f5f9;
        }
        
        .back-btn {
            background: linear-gradient(135deg, #64748b, #475569);
            color: white;
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            transform: translateY(-2px);
            color: white;
        }
        
        .content-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        
        body.dark-mode .content-card {
            background: #1e293b;
            color: #f1f5f9;
        }
        
        .exam-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        body.dark-mode .exam-info {
            border-color: #334155;
        }
        
        .exam-info-item {
            text-align: center;
        }
        
        .exam-info-label {
            color: #64748b;
            font-size: 0.85rem;
            display: block;
        }
        
        .exam-info-value {
            color: #1e293b;
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        body.dark-mode .exam-info-value {
            color: #f1f5f9;
        }
        
        .exam-link-box {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            padding: 15px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        body.dark-mode .exam-link-box {
            background: #0f172a;
        }
        
        .exam-link-input {
            flex: 1;
            min-width: 200px;
            padding: 10px 15px;
            border: 2px solid #0ea5e9;
            border-radius: 8px;
            font-size: 0.9rem;
            direction: ltr;
            text-align: center;
        }
        
        .copy-link-btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .copy-link-btn:hover {
            transform: translateY(-2px);
        }
        
        /* الإحصائيات */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        /* الجدول */
        .table-container {
            overflow-x: auto;
        }
        
        .results-table {
            width: 100%;
        }
        
        .results-table th {
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            padding: 15px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .results-table td {
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #e2e8f0;
        }
        
        body.dark-mode .results-table td {
            border-color: #334155;
            color: #e2e8f0;
        }
        
        .badge-passed {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .badge-failed {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .model-badge {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            padding: 3px 10px;
            border-radius: 5px;
            font-weight: 600;
        }
        
        .cheating-warning {
            color: #ef4444;
            font-weight: 600;
        }
        
        .export-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
        }
        
        .no-results {
            text-align: center;
            padding: 50px;
            color: #64748b;
        }
        
        .no-results i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        /* Theme Toggle */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: none;
            background: white;
            color: #667eea;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        body.dark-mode .theme-toggle {
            background: #334155;
            color: #fbbf24;
        }
        
        .theme-toggle:hover {
            transform: scale(1.1);
        }
        
        /* Footer */
        .portal-footer {
            background: #1e293b;
            color: white;
            padding: 2rem 0 1rem 0;
            border-top: 3px solid rgba(102, 126, 234, 0.3);
        }
        
        body.dark-mode .portal-footer {
            background: #0f172a;
        }
        
        .portal-footer p {
            margin: 0 0 1rem 0;
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
        }
        
        .social-media-footer {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .social-footer-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            font-size: 1.3rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .social-footer-icon.facebook { background: linear-gradient(135deg, #1877f2, #0c63d4); }
        .social-footer-icon.whatsapp { background: linear-gradient(135deg, #25d366, #128c7e); }
        .social-footer-icon.instagram { background: linear-gradient(135deg, #e1306c, #c13584 50%, #833ab4); }
        .social-footer-icon:hover { transform: translateY(-3px) scale(1.1); }
    </style>
</head>
<body>
    <!-- Theme Toggle -->
    <button class="theme-toggle" id="themeToggle" title="تبديل الوضع">
        <i class="fas fa-moon"></i>
    </button>

    <div class="main-container">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-chart-bar"></i> 
                    نتائج الامتحان
                </h1>
                <?php if ($exam): ?>
                <p style="color: #64748b; margin-top: 5px;"><?php echo htmlspecialchars($exam['title']); ?></p>
                <?php endif; ?>
            </div>
            <div>
                <a href="lesson_archive.php" class="back-btn">
                    <i class="fas fa-arrow-right"></i> العودة للأرشيف
                </a>
            </div>
        </div>
        
        <?php if (!$exam): ?>
        <div class="content-card">
            <div class="no-results">
                <i class="fas fa-exclamation-circle"></i>
                <h3>الامتحان غير موجود</h3>
                <p>تأكد من صحة الرابط أو أنك صاحب هذا الامتحان</p>
            </div>
        </div>
        <?php else: ?>
        
        <!-- معلومات الامتحان -->
        <div class="content-card">
            <div class="exam-info">
                <div class="exam-info-item">
                    <span class="exam-info-label">مدة الامتحان</span>
                    <span class="exam-info-value"><?php echo $exam['duration_minutes'] == 0 ? 'وقت مفتوح' : $exam['duration_minutes'] . ' دقيقة'; ?></span>
                </div>
                <div class="exam-info-item">
                    <span class="exam-info-label">عدد النماذج</span>
                    <span class="exam-info-value"><?php echo $exam['models_count']; ?> نماذج</span>
                </div>
                <div class="exam-info-item">
                    <span class="exam-info-label">درجة النجاح</span>
                    <span class="exam-info-value"><?php echo $exam['passing_percentage']; ?>%</span>
                </div>
                <div class="exam-info-item">
                    <span class="exam-info-label">تاريخ الإنشاء</span>
                    <span class="exam-info-value"><?php echo date('Y/m/d', strtotime($exam['created_at'])); ?></span>
                </div>
            </div>
            
            <div class="exam-link-box">
                <span style="font-weight: 600; color: #0369a1;"><i class="fas fa-link"></i> رابط الامتحان:</span>
                <input type="text" class="exam-link-input" id="examLink" readonly 
                       value="<?php echo rtrim((isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']), '/'); ?>/../take_exam.php?code=<?php echo $exam['exam_code']; ?>">
                <button class="copy-link-btn" onclick="copyLink()">
                    <i class="fas fa-copy"></i> نسخ الرابط
                </button>
            </div>
        </div>
        
        <?php if (count($results) > 0): ?>
        <!-- الإحصائيات -->
        <div class="content-card">
            <h3 style="margin-bottom: 20px; color: #1e293b;"><i class="fas fa-chart-pie"></i> الإحصائيات</h3>
            
            <div class="stats-grid">
                <div class="stat-card" style="--card-gradient: var(--primary-gradient);">
                    <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-card-label">إجمالي المشاركين</div>
                    </div>
                </div>
                <div class="stat-card" style="--card-gradient: var(--success-gradient);">
                    <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number"><?php echo $stats['passed']; ?></div>
                        <div class="stat-card-label">ناجحون</div>
                    </div>
                </div>
                <div class="stat-card" style="--card-gradient: var(--danger-gradient);">
                    <div class="stat-card-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number"><?php echo $stats['failed']; ?></div>
                        <div class="stat-card-label">راسبون</div>
                    </div>
                </div>
                <div class="stat-card" style="--card-gradient: var(--warning-gradient);">
                    <div class="stat-card-icon"><i class="fas fa-percentage"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number"><?php echo $stats['avg_score']; ?>%</div>
                        <div class="stat-card-label">متوسط الدرجات</div>
                    </div>
                </div>
                <div class="stat-card" style="--card-gradient: var(--info-gradient);">
                    <div class="stat-card-icon"><i class="fas fa-arrow-up"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number"><?php echo $stats['highest']; ?>%</div>
                        <div class="stat-card-label">أعلى درجة</div>
                    </div>
                </div>
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ec4899, #be185d);">
                    <div class="stat-card-icon"><i class="fas fa-arrow-down"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number"><?php echo $stats['lowest']; ?>%</div>
                        <div class="stat-card-label">أدنى درجة</div>
                    </div>
                </div>
            </div>
            
            <!-- شريط نسبة النجاح -->
            <div style="background: #e2e8f0; border-radius: 10px; overflow: hidden; height: 30px; margin-top: 20px;">
                <div style="background: linear-gradient(90deg, #10b981, #34d399); height: 100%; width: <?php echo $stats['pass_rate']; ?>%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700;">
                    نسبة النجاح: <?php echo $stats['pass_rate']; ?>%
                </div>
            </div>
        </div>
        
        <!-- جدول النتائج -->
        <div class="content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h3 style="color: #1e293b;"><i class="fas fa-list"></i> تفاصيل النتائج</h3>
                <button class="export-btn" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> تصدير Excel
                </button>
                <a href="essay_grading.php?exam_id=<?php echo $examId; ?>" class="export-btn" style="background: linear-gradient(135deg, #f59e0b, #d97706); text-decoration: none;">
                    <i class="fas fa-pen-fancy"></i> تصحيح المقالي
                </a>
            </div>
            
            <div class="table-container">
                <table class="results-table" id="resultsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم الطالب</th>
                            <th>الفصل</th>
                            <th>النموذج</th>
                            <th>الدرجة</th>
                            <th>النسبة</th>
                            <th>الحالة</th>
                            <th>الوقت</th>
                            <th>المخالفات</th>
                            <th>تاريخ الإرسال</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $index => $result): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($result['student_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($result['student_class']); ?></td>
                            <td><span class="model-badge"><?php echo $result['model_letter']; ?></span></td>
                            <td><?php echo $result['correct_answers']; ?> / <?php echo $result['total_questions']; ?></td>
                            <td><strong><?php echo $result['percentage']; ?>%</strong></td>
                            <td>
                                <?php
                                    $answersData = json_decode($result['answers_data'], true);
                                    $hasEssay = isset($answersData['_has_essay']) && $answersData['_has_essay'];
                                    $isEssayOnly = ($result['total_questions'] == 0 && $hasEssay);
                                ?>
                                <?php if ($isEssayOnly): ?>
                                <span class="badge-passed" style="background: linear-gradient(135deg, #f59e0b, #d97706);">بانتظار التصحيح</span>
                                <?php elseif ($result['passed']): ?>
                                <span class="badge-passed">ناجح</span>
                                <?php else: ?>
                                <span class="badge-failed">راسب</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo floor($result['time_spent_seconds'] / 60); ?>:<?php echo str_pad($result['time_spent_seconds'] % 60, 2, '0', STR_PAD_LEFT); ?></td>
                            <td>
                                <?php if ($result['cheating_attempts'] > 0): ?>
                                <span class="cheating-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo $result['cheating_attempts']; ?></span>
                                <?php else: ?>
                                <span style="color: #10b981;"><i class="fas fa-check"></i> 0</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y/m/d H:i', strtotime($result['submitted_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="content-card">
            <div class="no-results">
                <i class="fas fa-inbox"></i>
                <h3>لا توجد نتائج بعد</h3>
                <p>لم يقم أي طالب بأداء الامتحان حتى الآن</p>
                <p style="margin-top: 15px;">شارك رابط الامتحان مع طلابك للبدء</p>
            </div>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
    
    <!-- Footer -->
    <footer class="portal-footer">
        <div class="container text-center">
            <p>جميع الحقوق محفوظة © <?php echo date('Y'); ?><br> EduCore<br>
                Open Source School Platform</p>
            
            <div class="social-media-footer">
                <a href="https://github.com/M-Hamaki/EduCore" target="_blank" class="social-footer-icon github">
                    <i class="fab fa-github"></i>
                </a>
            </div>
        </div>
    </footer>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // تهيئة DataTables
        $(document).ready(function() {
            if ($('#resultsTable').length) {
                $('#resultsTable').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json'
                    },
                    order: [[9, 'desc']],
                    pageLength: 50
                });
            }
        });
        
        // نسخ الرابط
        function copyLink() {
            const input = document.getElementById('examLink');
            input.select();
            document.execCommand('copy');
            
            const btn = event.target.closest('button');
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> تم النسخ!';
            btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
            
            setTimeout(() => {
                btn.innerHTML = original;
                btn.style.background = 'linear-gradient(135deg, #3b82f6, #2563eb)';
            }, 2000);
        }
        
        // تصدير Excel
        function exportToExcel() {
            const table = document.getElementById('resultsTable');
            const rows = table.querySelectorAll('tr');
            
            let csv = '\ufeff'; // BOM for UTF-8
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('th, td');
                const rowData = [];
                cells.forEach(cell => {
                    let text = cell.innerText.replace(/"/g, '""');
                    rowData.push('"' + text + '"');
                });
                csv += rowData.join(',') + '\n';
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'exam_results_<?php echo date("Y-m-d"); ?>.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        
        // Theme Toggle
        (function() {
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            }
            
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                
                if (document.body.classList.contains('dark-mode')) {
                    localStorage.setItem('theme', 'dark');
                    themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                } else {
                    localStorage.setItem('theme', 'light');
                    themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                }
            });
        })();
    </script>
</body>
</html>
