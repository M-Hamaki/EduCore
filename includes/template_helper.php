<?php
/**
 * Check if PhpSpreadsheet is installed
 * If not, show a more informative error message
 * 
 * NOTA: La funcionalidad de importación ha sido desactivada.
 * Solo se puede exportar datos a Excel.
 */
function checkPhpSpreadsheet() {
    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
        return false;
    }
    
    require_once __DIR__ . '/../vendor/autoload.php';
    
    // Check if a real PhpSpreadsheet installation exists (not our mock)
    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet') || 
        get_class(new \PhpOffice\PhpSpreadsheet\Spreadsheet()) === 'ExcelMock') {
        return false;
    }
    
    return true;
}

/**
 * Create default templates for users
 * @param string $outputDir
 * @return array Status of template creation
 * 
 * NOTA: Esta función está obsoleta ya que la importación ha sido desactivada.
 * Se mantiene por compatibilidad con versiones anteriores.
 */
function createDefaultTemplates($outputDir = 'uploads/templates') {
    if (!checkPhpSpreadsheet()) {
        echo "Warning: PhpSpreadsheet library not found. Please run 'composer install' in the root directory to install the required dependencies.<br>";
        echo "You can download Composer from https://getcomposer.org/ and then run:<br>";
        echo "<pre>composer install</pre>";
        return [
            'success' => false,
            'message' => 'PhpSpreadsheet library not found'
        ];
    }
    
    // Ensure directory exists
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    $templates = [
        'students' => [
            'filename' => $outputDir . '/students_template.xlsx',
            'headers' => ['الاسم', 'اسم المستخدم', 'كلمة المرور', 'معرف الفصل', 'البريد الإلكتروني', 'رقم الهاتف']
        ],
        'teachers' => [
            'filename' => $outputDir . '/teachers_template.xlsx',
            'headers' => ['الاسم', 'اسم المستخدم', 'كلمة المرور', 'البريد الإلكتروني', 'رقم الهاتف']
        ],
        'supervisors' => [
            'filename' => $outputDir . '/supervisors_template.xlsx',
            'headers' => ['الاسم', 'اسم المستخدم', 'كلمة المرور', 'البريد الإلكتروني', 'رقم الهاتف']
        ]
    ];
    
    $results = [];
    
    foreach ($templates as $type => $template) {
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(ucfirst($type));
            
            // Add headers to first row
            for ($i = 0; $i < count($template['headers']); $i++) {
                $sheet->setCellValueByColumnAndRow($i + 1, 1, $template['headers'][$i]);
            }
            
            // Create writer and save file
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($template['filename']);
            
            $results[$type] = [
                'success' => true,
                'filename' => $template['filename']
            ];
        } catch (\Exception $e) {
            $results[$type] = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    return $results;
}

/**
 * Build asset URL with cache-busting using file modification time.
 * Accepts relative paths (e.g., 'assets/css/style.css' or '../assets/js/app.js').
 * If file cannot be resolved, returns original path without mtime parameter.
 *
 * @param string $path Relative URL to the asset from the current page context
 * @return string URL with ?v=<mtime> appended when possible
 */
function asset_url($path) {
    // Leave external URLs unchanged
    if (preg_match('#^https?://|^//#i', $path)) {
        return $path;
    }

    $fsPath = false;
    // Try resolving relative to the executing script (reliable for ../ paths)
    if (isset($_SERVER['SCRIPT_FILENAME'])) {
        $candidate = realpath(dirname($_SERVER['SCRIPT_FILENAME']) . DIRECTORY_SEPARATOR . $path);
        if ($candidate && is_file($candidate)) {
            $fsPath = $candidate;
        }
    }
    // Fallback: resolve relative to project root (includes/../)
    if ($fsPath === false) {
        $root = realpath(__DIR__ . '/../');
        if ($root !== false) {
            $candidate = realpath($root . DIRECTORY_SEPARATOR . ltrim($path, '/'));
            if ($candidate && is_file($candidate)) {
                $fsPath = $candidate;
            }
        }
    }

    // Append version if resolved
    if ($fsPath !== false) {
        $mtime = @filemtime($fsPath);
        if ($mtime) {
            // Preserve existing query string if present
            $separator = (strpos($path, '?') !== false) ? '&' : '?';
            return $path . $separator . 'v=' . $mtime;
        }
    }

    // Fallback to original path if not resolved
    return $path;
}

/**
 * Resolve the current deployment's URL base path without assuming a domain or
 * that the project directory name is exposed by the web server.
 */
function request_app_base_path(?string $scriptName = null, ?string $scriptFilename = null): string
{
    $scriptName = str_replace('\\', '/', $scriptName ?? (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFilename = $scriptFilename ?? (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $projectRoot = realpath(__DIR__ . '/..');
    $resolvedScript = $scriptFilename !== '' ? realpath($scriptFilename) : false;

    if ($projectRoot === false || $resolvedScript === false) {
        return '';
    }

    $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    $normalizedScript = str_replace('\\', '/', $resolvedScript);
    if (strncasecmp($normalizedScript, $normalizedRoot . '/', strlen($normalizedRoot) + 1) !== 0) {
        return '';
    }

    $relativeScript = substr($normalizedScript, strlen($normalizedRoot) + 1);
    $urlSuffix = '/' . ltrim($relativeScript, '/');
    if ($scriptName === '' || strlen($scriptName) < strlen($urlSuffix)
        || strcasecmp(substr($scriptName, -strlen($urlSuffix)), $urlSuffix) !== 0) {
        return '';
    }

    $basePath = substr($scriptName, 0, -strlen($urlSuffix));
    if ($basePath === '' || $basePath === '/') {
        return '';
    }

    return '/' . trim($basePath, '/');
}

/**
 * Get the school logo path from database settings.
 * Falls back to the default assets/img/logo.png if no custom logo is set.
 *
 * @param string $prefix Relative path prefix from the calling file to the project root
 *                       e.g. '' for root files, '../' for files one level deep, '../../' for two levels deep
 * @return string The logo image path ready for use in an <img> src attribute
 */
function get_school_logo($prefix = '') {
    static $cachedLogoFile = null;

    if ($cachedLogoFile === null) {
        $cachedLogoFile = ''; // default: no custom logo
        try {
            require_once __DIR__ . '/../config/database.php';
            $dbInstance = new Database();
            $dbConn = $dbInstance->getConnection();
            $stmt = $dbConn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'school_logo' LIMIT 1");
            $stmt->execute();
            $logoVal = $stmt->fetchColumn();
            if ($logoVal && file_exists(__DIR__ . '/../uploads/' . $logoVal)) {
                $cachedLogoFile = $logoVal;
            }
        } catch (Exception $e) {
            // fallback to default
        }
    }

    if ($cachedLogoFile) {
        return $prefix . 'uploads/' . $cachedLogoFile;
    }
    // Return base64 encoded generic school SVG as fallback
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" rx="24" fill="#e2e8f0"/><path d="M60 30 L25 50 L25 55 L30 55 L30 85 L90 85 L90 55 L95 55 L95 50 Z" fill="#475569"/><rect x="52" y="65" width="16" height="20" rx="2" fill="#cbd5e1"/><circle cx="60" cy="45" r="4" fill="#cbd5e1"/><path d="M40 60 H50 V70 H40 Z M70 60 H80 V70 H70 Z" fill="#cbd5e1"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
