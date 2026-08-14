<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/FileCache.php';
require_once '../includes/session_config.php';

// التحقق من صلاحيات المدير
Utilities::validateSession('admin');

$page_title = "إحصائيات وتحليل الكود البرمجي";
$custom_page_title = true;

// إعدادات المسح البرمجي
$rootPath = realpath(__DIR__ . '/../');
$excludedFolders = [
    '.git',
    '.agents',
    '.zcode',
    '.cursor',
    '.specify',
    'tmp',
    'uploads',
    'node_modules',
    'vendor',
    'phpmyadmin',
    'archive',
    'storage',
    'assets/vendor',
    '%SystemDrive%',
];
$allowedExtensions = ['php', 'js', 'css', 'sql', 'json', 'md', 'htaccess'];

$analyticsCache = new FileCache();
$analyticsCacheKey = 'system-code-analytics-v3';
$analyticsCacheTtl = 600;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_analysis') {
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])
    ) {
        $_SESSION['system_code_analytics_error'] = 'تعذر التحقق من أمان الطلب. يرجى إعادة المحاولة.';
        header('Location: system_code_analytics.php');
        exit();
    }

    $analyticsCache->forget($analyticsCacheKey);
    $_SESSION['system_code_analytics_notice'] = 'تم تحديث تحليل الكود بنجاح.';
    header('Location: system_code_analytics.php');
    exit();
}

// دالة لحساب عدد الأسطر بكفاءة وبدون استهلاك للذاكرة
function countLines(string $filePath): int
{
    $handle = @fopen($filePath, 'rb');
    if ($handle === false) {
        return 0;
    }

    $lineCount = 0;
    $hasContent = false;
    $lastCharacter = null;

    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                continue;
            }

            $hasContent = true;
            $lineCount += substr_count($chunk, "\n");
            $lastCharacter = substr($chunk, -1);
        }
    } finally {
        fclose($handle);
    }

    if ($hasContent && $lastCharacter !== "\n") {
        $lineCount++;
    }

    return $lineCount;
}

// دالة المسح العودية المخصصة لتجنب دخول المجلدات المستبعدة برمتها
function scanDirectoryRecursively($dir, $rootPath, $excludedFolders, $allowedExtensions, &$totalFiles, &$totalDirectories, &$totalLinesOfCode, &$extensionStats, &$directoryStats, &$allTrackedFiles)
{
    $files = @scandir($dir);
    if (!$files)
        return;

    foreach ($files as $file) {
        if ($file === '.' || $file === '..')
            continue;

        $path = $dir . DIRECTORY_SEPARATOR . $file;

        // لا نتبع الروابط الرمزية خارج شجرة المشروع.
        if (is_link($path)) {
            continue;
        }

        // التحقق من استبعاد المجلد
        $relativePath = str_replace($rootPath, '', $path);
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        $isExcluded = false;
        foreach ($excludedFolders as $exFolder) {
            if ($relativePath === $exFolder || strpos($relativePath, $exFolder . '/') === 0) {
                $isExcluded = true;
                break;
            }
        }
        if ($isExcluded)
            continue;

        if (is_dir($path)) {
            $totalDirectories++;
            scanDirectoryRecursively($path, $rootPath, $excludedFolders, $allowedExtensions, $totalFiles, $totalDirectories, $totalLinesOfCode, $extensionStats, $directoryStats, $allTrackedFiles);
        } else {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($file === '.htaccess') {
                $ext = 'htaccess';
            }

            if (in_array($ext, $allowedExtensions)) {
                $totalFiles++;
                $lines = countLines($path);
                $totalLinesOfCode += $lines;

                // تحديث إحصائيات الصيغ
                $extensionStats[$ext]['files']++;
                $extensionStats[$ext]['lines'] += $lines;

                // تحديد المجلد الرئيسي (المستوى الأول)
                $parentPath = dirname($path);
                $relativeParent = str_replace($rootPath, '', $parentPath);
                $relativeParent = ltrim(str_replace('\\', '/', $relativeParent), '/');
                $parts = explode('/', $relativeParent);
                $rootFolder = $parts[0] !== '' ? $parts[0] : 'ملفات الجذر';

                if (!isset($directoryStats[$rootFolder])) {
                    $directoryStats[$rootFolder] = ['files' => 0, 'lines' => 0];
                }
                $directoryStats[$rootFolder]['files']++;
                $directoryStats[$rootFolder]['lines'] += $lines;

                // تخزين تفاصيل الملف للترتيب اللاحق
                $size = @filesize($path) ?: 0;
                $allTrackedFiles[] = [
                    'name' => $file,
                    'path' => $relativePath,
                    'extension' => $ext,
                    'lines' => $lines,
                    'size' => $size
                ];
            }
        }
    }
}

function buildSystemCodeAnalytics(string $rootPath, array $excludedFolders, array $allowedExtensions): array
{
    $totalFiles = 0;
    $totalDirectories = 0;
    $totalLinesOfCode = 0;
    $directoryStats = [];
    $allTrackedFiles = [];

    $extensionStats = [
        'php' => ['files' => 0, 'lines' => 0, 'color' => '#4F5D95', 'icon' => 'fab fa-php'],
        'js' => ['files' => 0, 'lines' => 0, 'color' => '#f1e05a', 'icon' => 'fab fa-js-square'],
        'css' => ['files' => 0, 'lines' => 0, 'color' => '#563d7c', 'icon' => 'fab fa-css3-alt'],
        'sql' => ['files' => 0, 'lines' => 0, 'color' => '#e38c00', 'icon' => 'fas fa-database'],
        'json' => ['files' => 0, 'lines' => 0, 'color' => '#002b36', 'icon' => 'fas fa-brackets-curly'],
        'md' => ['files' => 0, 'lines' => 0, 'color' => '#083fa6', 'icon' => 'fab fa-markdown'],
        'htaccess' => ['files' => 0, 'lines' => 0, 'color' => '#64b5f6', 'icon' => 'fas fa-server'],
    ];

    scanDirectoryRecursively(
        $rootPath,
        $rootPath,
        $excludedFolders,
        $allowedExtensions,
        $totalFiles,
        $totalDirectories,
        $totalLinesOfCode,
        $extensionStats,
        $directoryStats,
        $allTrackedFiles
    );

    usort($allTrackedFiles, static function (array $left, array $right): int {
        return $right['lines'] <=> $left['lines'];
    });

    return [
        'total_files' => $totalFiles,
        'total_directories' => $totalDirectories,
        'total_lines_of_code' => $totalLinesOfCode,
        'extension_stats' => $extensionStats,
        'directory_stats' => $directoryStats,
        'top_files' => array_slice($allTrackedFiles, 0, 100),
        'average_lines_per_file' => $totalFiles > 0 ? (int) round($totalLinesOfCode / $totalFiles) : 0,
        'generated_at' => time(),
    ];
}

if (!is_string($rootPath) || !is_dir($rootPath)) {
    throw new RuntimeException('تعذر الوصول إلى مجلد المشروع لتحليل الكود.');
}

$analytics = $analyticsCache->remember(
    $analyticsCacheKey,
    $analyticsCacheTtl,
    static function () use ($rootPath, $excludedFolders, $allowedExtensions): array {
        return buildSystemCodeAnalytics($rootPath, $excludedFolders, $allowedExtensions);
    }
);

$totalFiles = (int) ($analytics['total_files'] ?? 0);
$totalDirectories = (int) ($analytics['total_directories'] ?? 0);
$totalLinesOfCode = (int) ($analytics['total_lines_of_code'] ?? 0);
$extensionStats = (array) ($analytics['extension_stats'] ?? []);
$directoryStats = (array) ($analytics['directory_stats'] ?? []);
$topFiles = (array) ($analytics['top_files'] ?? []);
$averageLinesPerFile = (int) ($analytics['average_lines_per_file'] ?? 0);
$analysisGeneratedAt = (int) ($analytics['generated_at'] ?? time());
$analysisNotice = $_SESSION['system_code_analytics_notice'] ?? null;
$analysisError = $_SESSION['system_code_analytics_error'] ?? null;
unset($_SESSION['system_code_analytics_notice'], $_SESSION['system_code_analytics_error']);

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-code me-2 text-info"></i>إحصائيات وتحليل الكود البرمجي</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <form method="post" action="system_code_analytics.php" class="d-inline">
            <input type="hidden" name="action" value="refresh_analysis">
            <input type="hidden" name="csrf_token"
                value="<?php echo htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn btn-outline-primary shadow-sm px-3 py-2">
                <i class="fas fa-sync-alt me-1"></i>إعادة التحليل
            </button>
        </form>
    </div>
</div>

<?php if ($analysisNotice): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars((string) $analysisNotice, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($analysisError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars((string) $analysisError, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="text-muted small mb-3">
    <i class="fas fa-clock me-1"></i>
    آخر تحليل: <?php echo htmlspecialchars(date('Y-m-d H:i', $analysisGeneratedAt), ENT_QUOTES, 'UTF-8'); ?>
    — يتم الاحتفاظ بالنتيجة لمدة 10 دقائق.
</div>

<!-- Stat Cards Row -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-align-left"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalLinesOfCode; ?>">0</div>
                <div class="stat-card-label">إجمالي أسطر الكود</div>
                <div class="stat-card-sub"><i class="fas fa-terminal"></i> أسطر برمجية نشطة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-file-code"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalFiles; ?>">0</div>
                <div class="stat-card-label">عدد الملفات النشطة</div>
                <div class="stat-card-sub"><i class="fas fa-check-circle"></i> ملفات مفحوصة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-folder-open"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalDirectories; ?>">0</div>
                <div class="stat-card-label">عدد المجلدات</div>
                <div class="stat-card-sub"><i class="fas fa-sitemap"></i> هيكل النظام الدليلي</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);">
            <div class="stat-card-icon"><i class="fas fa-calculator"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $averageLinesPerFile; ?>">0</div>
                <div class="stat-card-label">متوسط أسطر الملف</div>
                <div class="stat-card-sub"><i class="fas fa-file-alt"></i> سطر/ملف واحد</div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <!-- Language Distribution -->
    <div class="col-md-6 col-12 mb-4 mb-md-0">
        <div class="card shadow h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>توزيع اللغات والملفات</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-4">يعرض هذا المؤشر مساهمة كل صيغة ولغة برمجة في النظام بناءً على إجمالي
                    عدد الأسطر البرمجية المكتوبة.</p>
                <?php
                foreach ($extensionStats as $ext => $stats):
                    if ($stats['files'] === 0)
                        continue;
                    $percent = $totalLinesOfCode > 0 ? round(($stats['lines'] / $totalLinesOfCode) * 100, 1) : 0;
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold">
                                <i class="<?php echo $stats['icon']; ?> me-1"
                                    style="color: <?php echo $stats['color']; ?>;"></i>
                                <?php echo strtoupper($ext); ?>
                            </span>
                            <span class="text-muted small">
                                <?php echo number_format($stats['lines']); ?> سطر (<?php echo $stats['files']; ?> ملف) -
                                <strong class="text-primary"><?php echo $percent; ?>%</strong>
                            </span>
                        </div>
                        <div class="progress" style="height: 10px; border-radius: 5px;">
                            <div class="progress-bar" role="progressbar"
                                style="width: <?php echo $percent; ?>%; background-color: <?php echo $stats['color']; ?>; border-radius: 5px;"
                                aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Top Directories -->
    <div class="col-md-6 col-12">
        <div class="card shadow h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-folder-tree me-2"></i>تحليل المجلدات الرئيسية</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead>
                            <tr>
                                <th>المجلد / الحزمة</th>
                                <th class="text-center">عدد الملفات</th>
                                <th class="text-center">إجمالي الأسطر</th>
                                <th>النسبة من الكود</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            arsort($directoryStats); // ترتيب تنازلي حسب ملفات المجلد
                            foreach ($directoryStats as $folder => $stats):
                                $dirPercent = $totalLinesOfCode > 0 ? round(($stats['lines'] / $totalLinesOfCode) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-folder text-warning me-2"></i>
                                        <strong class="text-dark"><?php echo $folder; ?>/</strong>
                                    </td>
                                    <td class="text-center"><?php echo $stats['files']; ?></td>
                                    <td class="text-center fw-bold text-secondary">
                                        <?php echo number_format($stats['lines']); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 6px;">
                                                <div class="progress-bar bg-info"
                                                    style="width: <?php echo $dirPercent; ?>%"></div>
                                            </div>
                                            <span class="small fw-bold"
                                                style="min-width: 35px;"><?php echo $dirPercent; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Largest Files Explorer -->
<h5 class="mb-3 mt-4 fw-bold text-dark"><i class="fas fa-file-invoice me-2 text-primary"></i>أكبر 100 ملف برمجي في
    النظام (حسب حجم الأسطر)</h5>
<div class="admin-list-surface">
    <div class="admin-table-wrap">
        <table class="table table-hover table-striped datatable admin-data-table">
            <thead>
                <tr>
                    <th style="width: 50px;" class="text-center">#</th>
                    <th>اسم الملف</th>
                    <th>المسار النسبي</th>
                    <th>النوع</th>
                    <th class="text-center">عدد الأسطر</th>
                    <th class="text-center">حجم الملف</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $counter = 1;
                foreach ($topFiles as $file):
                    $sizeKb = round($file['size'] / 1024, 1);
                    $badgeClass = 'bg-secondary';
                    if ($file['extension'] === 'php')
                        $badgeClass = 'bg-primary-subtle text-primary';
                    elseif ($file['extension'] === 'js')
                        $badgeClass = 'bg-warning-subtle text-warning-emphasis';
                    elseif ($file['extension'] === 'css')
                        $badgeClass = 'bg-purple-subtle text-purple';
                    elseif ($file['extension'] === 'sql')
                        $badgeClass = 'bg-danger-subtle text-danger';
                    ?>
                    <tr>
                        <td class="text-center text-muted"><?php echo $counter++; ?></td>
                        <td>
                            <i class="<?php echo $extensionStats[$file['extension']]['icon']; ?> me-2"
                                style="color: <?php echo $extensionStats[$file['extension']]['color']; ?>;"></i>
                            <span class="fw-bold text-dark"><?php echo htmlspecialchars($file['name']); ?></span>
                        </td>
                        <td><code class="text-secondary"
                                style="font-size: 0.8rem;"><?php echo htmlspecialchars($file['path']); ?></code></td>
                        <td><span
                                class="badge <?php echo $badgeClass; ?>"><?php echo strtoupper($file['extension']); ?></span>
                        </td>
                        <td class="text-center fw-bold text-info" data-order="<?php echo $file['lines']; ?>">
                            <?php echo number_format($file['lines']); ?></td>
                        <td class="text-center text-muted" data-order="<?php echo $file['size']; ?>">
                            <?php echo $sizeKb; ?> KB</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
