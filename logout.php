<?php
// Include utilities class
require_once 'classes/utilities.php';

// Start session
session_start();

// حفظ معلومة إذا كان الدخول من Teams
$fromTeams = isset($_SESSION['from_teams']) || isset($_GET['from_teams']);
$isExternal = isset($_SESSION['role']) && $_SESSION['role'] === 'external_teacher';

// If user is logged in, log the logout action
if (isset($_SESSION['user_id'])) {
    // Include database for logging
    require_once 'config/database.php';
    
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Log the logout action (skip for external teachers - their IDs aren't in users table)
    if (!$isExternal) {
        Utilities::logAction('logout', 'User logged out', $_SESSION['user_id']);
    }
}

// Destroy the session
session_destroy();

// كشف مسار المجلد الحالي والبروتوكول لضمان التوجيه الصحيح محلياً وفي الإنتاج
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$dir = dirname($scriptName);
if ($dir === '/' || $dir === '\\') {
    $dir = '';
}
$dir = str_replace('\\', '/', $dir);
$baseUrl = $protocol . ($_SERVER['HTTP_HOST'] ?? 'portal.dmls.edu.eg') . $dir;

if ($isExternal) {
    header("Location: " . $baseUrl . "/external_login.php");
} elseif ($fromTeams) {
    header("Location: " . $baseUrl . "/index.php?skip_intro=1&from_teams=1");
} else {
    header("Location: " . $baseUrl . "/index.php?skip_intro=1");
}
exit;
