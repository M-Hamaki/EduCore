<?php
/**
 * Supervisor Materials Preview Page - Teachers & Supervisors Portal
 * Dedicated Preview & Audit System for Educational Materials
 * EduCore
 */

// Connect to database
$dbConnected = false;
$databaseFile = __DIR__ . '/../../config/database.php';

if (file_exists($databaseFile)) {
    try {
        require_once $databaseFile;
        $database = new Database();
        $db = $database->getConnection();
        if ($db) {
            $dbConnected = true;
        }
    } catch (Exception $e) {
        $dbConnected = false;
    }
}

// Fetch all stages and grades for dynamic supervisor navigation
$allStages = [];
$allGrades = [];
if ($dbConnected) {
    $allStages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $allGrades = $db->query("SELECT id, grade_name, grade_code, stage_id FROM grades WHERE status = 'active' ORDER BY stage_id, grade_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$gradeParam = isset($_GET['grade']) ? trim($_GET['grade']) : 'all';
$scopeParam = isset($_GET['scope']) ? trim($_GET['scope']) : $gradeParam;
$term = isset($_GET['term']) ? trim($_GET['term']) : 'term1';
if (!in_array($term, ['term1', 'term2'])) {
    $term = 'term1';
}

$dbMaterials = [];
$gradeName = '';
$dbGrade = null;

if ($dbConnected) {
    if ($gradeParam === 'all' || empty($gradeParam)) {
        $gradeName = 'جميع المراحل والصفوف الدراسية';
        $mStmt = $db->prepare("SELECT m.*, s.stage_name, g.grade_name FROM materials m LEFT JOIN stages s ON m.stage_id = s.id LEFT JOIN grades g ON m.grade_id = g.id WHERE m.term = ? AND m.enabled = 1 ORDER BY s.stage_order, g.grade_order, m.sort_order ASC, m.id DESC");
        $mStmt->execute([$term]);
        $rawDbMaterials = $mStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawDbMaterials as $dm) {
            $dbMaterials[] = [
                'name' => $dm['subject_name'],
                'stage_name' => $dm['stage_name'] ?: 'غير محدد',
                'grade_name' => $dm['grade_name'] ?: 'غير محدد',
                'file' => $dm['file_name'],
                'original_name' => $dm['original_file_name'],
                'downloadable' => (bool)$dm['downloadable'],
                'is_uploaded' => !empty($dm['file_name']),
                'path' => '../../uploads/materials/' . $dm['file_name']
            ];
        }
    } elseif (strpos($gradeParam, 'stage_') === 0) {
        $stageId = intval(str_replace('stage_', '', $gradeParam));
        $stgStmt = $db->prepare("SELECT stage_name FROM stages WHERE id = ?");
        $stgStmt->execute([$stageId]);
        $stgRow = $stgStmt->fetch(PDO::FETCH_ASSOC);
        $gradeName = $stgRow ? 'جميع ' . $stgRow['stage_name'] : 'المرحلة';

        $mStmt = $db->prepare("SELECT m.*, s.stage_name, g.grade_name FROM materials m LEFT JOIN stages s ON m.stage_id = s.id LEFT JOIN grades g ON m.grade_id = g.id WHERE m.stage_id = ? AND m.term = ? AND m.enabled = 1 ORDER BY g.grade_order, m.sort_order ASC, m.id DESC");
        $mStmt->execute([$stageId, $term]);
        $rawDbMaterials = $mStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawDbMaterials as $dm) {
            $dbMaterials[] = [
                'name' => $dm['subject_name'],
                'stage_name' => $dm['stage_name'] ?: 'غير محدد',
                'grade_name' => $dm['grade_name'] ?: 'غير محدد',
                'file' => $dm['file_name'],
                'original_name' => $dm['original_file_name'],
                'downloadable' => (bool)$dm['downloadable'],
                'is_uploaded' => !empty($dm['file_name']),
                'path' => '../../uploads/materials/' . $dm['file_name']
            ];
        }
    } else {
        $cleanParam = strtolower(str_replace([' ', '-'], '', $gradeParam));
        $stmt = $db->prepare("SELECT g.*, s.stage_name FROM grades g LEFT JOIN stages s ON g.stage_id = s.id WHERE LOWER(REPLACE(REPLACE(g.grade_code, ' ', ''), '-', '')) = ? OR g.grade_code = ? OR g.id = ?");
        $stmt->execute([$cleanParam, $gradeParam, intval($gradeParam)]);
        $dbGrade = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dbGrade) {
            $gradeName = $dbGrade['grade_name'];
            $gradeId = $dbGrade['id'];

            $mStmt = $db->prepare("SELECT m.*, s.stage_name, g.grade_name FROM materials m LEFT JOIN stages s ON m.stage_id = s.id LEFT JOIN grades g ON m.grade_id = g.id WHERE m.grade_id = ? AND m.term = ? AND m.enabled = 1 ORDER BY m.sort_order ASC, m.id DESC");
            $mStmt->execute([$gradeId, $term]);
            $rawDbMaterials = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rawDbMaterials as $dm) {
                $dbMaterials[] = [
                    'name' => $dm['subject_name'],
                    'stage_name' => $dm['stage_name'] ?: 'غير محدد',
                    'grade_name' => $dm['grade_name'] ?: 'غير محدد',
                    'file' => $dm['file_name'],
                    'original_name' => $dm['original_file_name'],
                    'downloadable' => (bool)$dm['downloadable'],
                    'is_uploaded' => !empty($dm['file_name']),
                    'path' => '../../uploads/materials/' . $dm['file_name']
                ];
            }
        }
    }
}

if (empty($gradeName)) {
    $gradeName = $gradeParam ?: 'مركز المشرفين';
}

$termName = ($term === 'term1') ? 'الفصل الدراسي الأول' : 'الفصل الدراسي الثاني';
$pageTitle = '[معاينة مشرفين] ' . $gradeName . ' - ' . $termName;

// Helper functions for stylish badges
function getStageBadgeClass($stageName) {
    if (mb_strpos($stageName, 'رياض') !== false || mb_strpos($stageName, 'KG') !== false) return 'stage-badge-kg';
    if (mb_strpos($stageName, 'ابتدائ') !== false || mb_strpos($stageName, 'Prim') !== false) return 'stage-badge-prim';
    if (mb_strpos($stageName, 'إعداد') !== false || mb_strpos($stageName, 'Prep') !== false) return 'stage-badge-prep';
    if (mb_strpos($stageName, 'ثانو') !== false || mb_strpos($stageName, 'Sec') !== false) return 'stage-badge-sec';
    return 'stage-badge-default';
}

function getGradeColorClass($gradeName) {
    $hash = abs(crc32($gradeName)) % 6 + 1;
    return 'grade-clr-' . $hash;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - EduCore Audit</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    
    <!-- Portal Design CSS -->
    <link rel="stylesheet" href="materials-portal-style.css?v=<?php echo time(); ?>">
    
    <style>
        .supervisor-banner {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: #f8fafc;
            padding: 1.25rem 1.5rem;
            border-radius: 14px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.2);
            border: 1px solid #334155;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .selector-bar {
            background: white;
            padding: 1.25rem 1.5rem;
            border-radius: 14px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
            border: 2px solid #667eea;
        }
        .selector-form {
            display: flex;
            gap: 1.5rem;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
        }
        .selector-group {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }
        .selector-group label {
            font-weight: 700;
            color: #475569;
            white-space: nowrap;
            margin: 0;
            font-size: 0.95rem;
        }
        .selector-form select {
            padding: 0.65rem 1.1rem;
            font-size: 0.95rem;
            border-radius: 10px;
            border: 1.5px solid #cbd5e1;
            background: #f8fafc;
            color: #1e293b;
            font-family: 'Tajawal', sans-serif;
            font-weight: 600;
            cursor: pointer;
            outline: none;
            min-width: 210px;
        }
        .selector-form select:focus {
            border-color: #667eea;
            background: white;
        }
        
        /* Grade Text Colors (No background boxes) */
        .grade-badge {
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            white-space: nowrap;
        }
        .grade-clr-1 { color: #4f46e5; }
        .grade-clr-2 { color: #0d9488; }
        .grade-clr-3 { color: #b45309; }
        .grade-clr-4 { color: #e11d48; }
        .grade-clr-5 { color: #c026d3; }
        .grade-clr-6 { color: #0284c7; }

        /* Mobile Optimization Rules */
        @media (max-width: 768px) {
            .selector-bar {
                padding: 0.85rem !important;
            }
            .selector-form {
                flex-direction: column;
                align-items: stretch;
                gap: 0.85rem;
            }
            .selector-group {
                flex-direction: column;
                align-items: stretch;
                gap: 0.35rem;
            }
            .selector-form select {
                width: 100% !important;
                min-width: 100% !important;
                font-size: 0.9rem !important;
            }
            .supervisor-banner {
                flex-direction: column;
                text-align: center;
                padding: 1rem !important;
            }
        }
    </style>
</head>
<body>
    <!-- Particles Background -->
    <div id="particles-js"></div>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Header with Logo -->
        <header class="materials-header">
            <a href="../../admin/materials_center.php">
                <img src="../../assets/img/logo.png" alt="EduCore Logo" class="materials-logo" loading="lazy">
            </a>
            <h1 class="materials-title"><?php echo htmlspecialchars($gradeName); ?></h1>
            <p class="materials-subtitle"><?php echo htmlspecialchars($termName); ?></p>
        </header>

        <!-- Supervisor Notice Banner -->
        <div class="supervisor-banner">
            <i class="fas fa-user-shield text-warning" style="font-size: 1.6rem;"></i>
            <div style="text-align: center;">
                <div style="font-size: 1.1rem; font-weight: 700; color: #fbbf24; margin-bottom: 0.35rem;">
                    وضع تدقيق ومعاينة المشرفين والمعلمين 🛡️
                </div>
                <div style="font-size: 0.9rem; color: #cbd5e1; line-height: 1.5;">
                    يتيح لك هذا المركز تحميل ومعاينة كافة الملفات لتأكيد سلامتها قبل تفعيلها للطلاب.
                </div>
            </div>
        </div>

        <!-- Dynamic Grade & Term Selector Bar for Supervisor -->
        <div class="selector-bar">
            <form method="GET" action="supervisor_preview.php" class="selector-form">
                <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scopeParam); ?>">
                
                <div class="selector-group">
                    <label for="gradeSelect"><i class="fas fa-school text-primary ms-1"></i>تحديد النطاق والصف:</label>
                    <select name="grade" id="gradeSelect" onchange="this.form.submit()">
                        <?php if ($scopeParam === 'all'): ?>
                            <option value="all" <?php echo ($gradeParam === 'all') ? 'selected' : ''; ?>>🌐 جميع المراحل والصفوف الدراسية</option>
                        <?php endif; ?>

                        <?php foreach ($allStages as $stg): ?>
                            <?php 
                            // Check scope restriction for stages
                            $isStageInScope = ($scopeParam === 'all' || $scopeParam === 'stage_' . $stg['id']);
                            if (!$isStageInScope) {
                                // Also check if scope matches a grade in this stage
                                $hasGradeInScope = false;
                                foreach ($allGrades as $grd) {
                                    if ($grd['stage_id'] == $stg['id'] && strtolower($grd['grade_code']) === strtolower($scopeParam)) {
                                        $hasGradeInScope = true;
                                        break;
                                    }
                                }
                                if (!$hasGradeInScope) continue;
                            }
                            ?>
                            <optgroup label="📍 <?php echo htmlspecialchars($stg['stage_name']); ?>">
                                <?php if ($scopeParam === 'all' || $scopeParam === 'stage_' . $stg['id']): ?>
                                    <option value="stage_<?php echo $stg['id']; ?>" <?php echo ($gradeParam === 'stage_' . $stg['id']) ? 'selected' : ''; ?> style="font-weight: bold; color: #2563eb;">
                                        🎯 جميع <?php echo htmlspecialchars($stg['stage_name']); ?>
                                    </option>
                                <?php endif; ?>

                                <?php foreach ($allGrades as $grd): ?>
                                    <?php if ($grd['stage_id'] == $stg['id']): ?>
                                        <?php 
                                        // Check scope restriction for individual grades
                                        $isGradeInScope = ($scopeParam === 'all' || $scopeParam === 'stage_' . $stg['id'] || strtolower($grd['grade_code']) === strtolower($scopeParam));
                                        if (!$isGradeInScope) continue;

                                        $isSelected = ($dbGrade && $dbGrade['id'] == $grd['id']) || ($gradeParam === $grd['grade_code']);
                                        ?>
                                        <option value="<?php echo htmlspecialchars($grd['grade_code']); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                            &nbsp;&nbsp;&nbsp;&nbsp;🔹 <?php echo htmlspecialchars($grd['grade_name']); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="selector-group">
                    <label for="termSelect"><i class="fas fa-calendar-alt text-primary ms-1"></i>الفصل الدراسي:</label>
                    <select name="term" id="termSelect" onchange="this.form.submit()">
                        <option value="term1" <?php echo $term === 'term1' ? 'selected' : ''; ?>>الفصل الدراسي الأول</option>
                        <option value="term2" <?php echo $term === 'term2' ? 'selected' : ''; ?>>الفصل الدراسي الثاني</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- Materials Card -->
        <div class="materials-card">
            <div class="card-header-custom">
                <h2><i class="fas fa-book-open"></i> المواد الدراسية المعروضة (<?php echo count($dbMaterials); ?> مادة)</h2>
            </div>

            <!-- Materials Table -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>اسم المادة</th>
                            <th><i class="fas fa-file-pdf"></i> تحميل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dbMaterials)): ?>
                            <tr>
                                <td colspan="2" style="text-align: center; padding: 2rem; color: #64748b;">
                                    <i class="fas fa-folder-open" style="font-size: 2rem; display: block; margin-bottom: 0.5rem;"></i>
                                    لا توجد مواد دراسية متاحة حالياً لهذا الصف.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dbMaterials as $material): ?>
                                <tr>
                                    <td class="material-name">
                                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.25rem;">
                                            <span><?php echo htmlspecialchars($material['name']); ?></span>
                                            <div style="margin-top: 0.1rem;">
                                                <span class="grade-badge <?php echo getGradeColorClass($material['grade_name']); ?>">
                                                    <i class="fas fa-bookmark" style="font-size: 0.65rem; opacity: 0.85;"></i>
                                                    <?php echo htmlspecialchars($material['grade_name']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($material['is_uploaded']): ?>
                                            <a href="<?php echo htmlspecialchars($material['path']); ?>" 
                                               download="<?php echo htmlspecialchars($material['original_name']); ?>" 
                                               class="download-btn">
                                                <i class="fas fa-download"></i>
                                                تحميل
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small" style="color: #94a3b8;">لم يرفع ملف بعد</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <footer class="materials-footer">
            <p>جميع الحقوق محفوظة © <?php echo date('Y'); ?><br> EduCore<br>
                Computer Department</p>
        </footer>
    </div>
</body>
</html>
