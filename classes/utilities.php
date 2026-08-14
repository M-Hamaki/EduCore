<?php
require_once __DIR__ . '/AuthorizationFacade.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/AdminRolePageCatalog.php';
require_once __DIR__ . '/StaffRoleCapabilityResolver.php';
require_once __DIR__ . '/StaffActiveRoleService.php';
require_once __DIR__ . '/../src/Modules/Accounts/StudentLoginAccessPolicy.php';
/**
 * Utilities Class
 * Contains helper functions for the application
 */
class Utilities {
    // Idle timeout in seconds (8 Hours)
    private const IDLE_TIMEOUT = 28800;

    /**
     * Check if user has supervisor capabilities (standalone supervisor role OR teacher with is_supervisor flag)
     * @return bool
     */
    public static function isSupervisor() {
        return AuthorizationFacade::isSupervisor($_SESSION);
    }

    /**
     * Get effective role considering supervisor active mode
     * Supervisor/teacher+is_supervisor with active_mode='teacher' acts as teacher
     * Supervisor mode remains a distinct role and never inherits specialist scope.
     * @return string
     */
    public static function getEffectiveRole() {
        return AuthorizationFacade::effectiveRole($_SESSION);
    }

    /**
     * Check if current user has the actual specialist role.
     * @return bool
     */
    public static function isActingAsSpecialist() {
        $role = trim((string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? ''));
        if ($role === 'specialist') {
            return true;
        }
        try {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            return (new StaffRoleCapabilityResolver($database->getConnection()))->isSpecialistFamily($role);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Check if current user has the Student Affairs Manager role.
     * @return bool
     */
    public static function isActingAsStudentAffairs() {
        $role = trim((string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? ''));
        if ($role === 'student_affairs_manager' || $role === 'student_affairs') {
            return true;
        }
        try {
            require_once __DIR__ . '/../config/database.php';
            require_once __DIR__ . '/AdminRolePageCatalog.php';
            $database = new Database();
            $family = (new StaffRoleCapabilityResolver($database->getConnection()))->family($role);
            return $family === AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Validate session and redirect if not logged in
     * @param string $requiredRole Optional - Required role for access
     * @return void
     */
    public static function validateSession($requiredRole = null) {
        // Start session if not already started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        // Get base URL for proper redirects
        $base_url = self::getBaseUrl();
        
        // Idle timeout enforcement
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > self::IDLE_TIMEOUT) {
            // Destroy session on timeout
            session_unset();
            session_destroy();
            header("Location: {$base_url}/index.php?timeout=1");
            exit;
        }
        $_SESSION['last_activity'] = $now;
        
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            header("Location: {$base_url}/index.php");
            exit;
        }

        if (!empty($_SESSION['role_selection_required'])) {
            header("Location: {$base_url}/select_role.php");
            exit;
        }

        $sessionRole = (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
        if ($sessionRole === 'student') {
            try {
                require_once __DIR__ . '/../config/database.php';
                $database = new Database();
                $decision = (new \EduCore\Modules\Accounts\StudentLoginAccessPolicy($database->getConnection()))
                    ->decisionForUserId((int) $_SESSION['user_id']);
                if (!$decision['allowed']) {
                    $message = (string) $decision['message'];
                    unset(
                        $_SESSION['user_id'], $_SESSION['name'], $_SESSION['role'], $_SESSION['active_role'],
                        $_SESSION['primary_role'], $_SESSION['available_roles'], $_SESSION['role_selection_required'],
                        $_SESSION['class_id'], $_SESSION['student_stage'], $_SESSION['microsoft_login'],
                        $_SESSION['microsoft_id'], $_SESSION['microsoft_email']
                    );
                    session_regenerate_id(true);
                    $_SESSION['login_access_message'] = $message;
                    header("Location: {$base_url}/index.php?skip_intro=1&error=account_disabled");
                    exit;
                }
            } catch (Throwable $e) {
                error_log('Student login access refresh failed: ' . $e->getMessage());
                session_unset();
                session_destroy();
                header("Location: {$base_url}/index.php");
                exit;
            }
        }
        if (!in_array($sessionRole, ['student'], true)) {
            try {
                require_once __DIR__ . '/../config/database.php';
                $database = new Database();
                (new StaffActiveRoleService($database->getConnection()))->refreshActiveRole(
                    $_SESSION,
                    (int)$_SESSION['user_id']
                );
            } catch (Throwable $e) {
                error_log('Authenticated role refresh failed: ' . $e->getMessage());
                session_unset();
                session_destroy();
                header("Location: {$base_url}/index.php");
                exit;
            }
        }

        if (!empty($_SESSION['role_selection_required'])) {
            header("Location: {$base_url}/select_role.php");
            exit;
        }

        // Check if user has required role
        if ($requiredRole !== null && !AuthorizationFacade::allowsRequiredRole(
            $_SESSION,
            (string) $requiredRole,
            static fn(string $role): bool => self::roleCanAccessAdminPage($role)
        )) {
            // Redirect based on the single active role and mode.
            $actualRole = (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
            if ($actualRole === 'admin' || $actualRole === 'super_admin') {
                header("Location: {$base_url}/admin/index.php");
            } elseif (self::isSupervisor()) {
                $active_mode = $_SESSION['active_mode'] ?? '';
                if ($active_mode === 'teacher') {
                    header("Location: {$base_url}/teacher/portal.php");
                } elseif ($active_mode === 'supervisor') {
                    header("Location: {$base_url}/supervisor/index.php");
                } else {
                    header("Location: {$base_url}/supervisor/select_mode.php");
                }
            } elseif ($actualRole === 'teacher') {
                header("Location: {$base_url}/teacher/portal.php");
            } elseif ($actualRole === 'specialist') {
                $target = self::getDashboardUrl('specialist');
                header("Location: {$base_url}/{$target}");
            } elseif (self::isCustomAdminRole($actualRole)) {
                $target = self::getDashboardUrl($actualRole);
                header("Location: {$base_url}/{$target}");
            } elseif ($actualRole === 'student') {
                header("Location: {$base_url}/student/portal.php");
            } elseif ($actualRole === 'external_teacher') {
                header("Location: {$base_url}/external/index.php");
            } else {
                header("Location: {$base_url}/index.php");
            }
            exit;
        }
    }
    
    /**
     * Get base URL for the application
     * @return string
     */
    private static function getBaseUrl() {
        // Build the protocol
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        
        // Get host
        $host = $_SERVER['HTTP_HOST'];
        
        // Get the script path
        $script = $_SERVER['SCRIPT_NAME'];
        
        // Get the directory path (remove the script filename)
        $path = dirname($script);
        
        // If we're in a subdirectory like /admin or /teacher, go up one level
        if (preg_match('#/(admin|teacher|student|specialist|supervisor)$#', $path)) {
            $path = dirname($path);
        }
        
        // Clean up path
        $path = rtrim($path, '/');
        
        return $protocol . $host . $path;
    }
    
    /**
     * Get current page name
     * @return string
     */
    public static function getCurrentPage() {
        return basename($_SERVER['PHP_SELF']);
    }
    
    /**
     * Generate random password
     * @param int $length
     * @return string
     */
    public static function generatePassword($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
    
    public static function formatDate($date) {
        $timestamp = strtotime($date);
        return date('Y-m-d h:i A', $timestamp);
    }
    
    /**
     * Format date to Arabic display
     * @param string $date
     * @return string
     */
    public static function formatDateArabic($date) {
        $timestamp = strtotime($date);
        
        $months = [
            "Jan" => "يناير", "Feb" => "فبراير", "Mar" => "مارس", "Apr" => "أبريل",
            "May" => "مايو", "Jun" => "يونيو", "Jul" => "يوليو", "Aug" => "أغسطس",
            "Sep" => "سبتمبر", "Oct" => "أكتوبر", "Nov" => "نوفمبر", "Dec" => "ديسمبر"
        ];
        
        $days = [
            "Sat" => "السبت", "Sun" => "الأحد", "Mon" => "الاثنين", "Tue" => "الثلاثاء",
            "Wed" => "الأربعاء", "Thu" => "الخميس", "Fri" => "الجمعة"
        ];
        
        $day = $days[date('D', $timestamp)];
        $month = $months[date('M', $timestamp)];
        
        return $day . ' ' . date('d', $timestamp) . ' ' . $month . ' ' . date('Y', $timestamp);
    }
    
    /**
     * Generate the appropriate dashboard URL based on user role
     * @param string $role
     * @return string
     */
    public static function getDashboardUrl($role) {
        switch ($role) {
            case 'admin':
            case 'super_admin':
                return 'admin/index.php';
            case 'teacher':
                // معلم مع صلاحية مشرف → صفحة اختيار الوضع
                if (!empty($_SESSION['is_supervisor'])) {
                    return 'supervisor/select_mode.php';
                }
                return 'teacher/portal.php';
            case 'supervisor':
                return 'supervisor/index.php';
            case 'specialist':
            case 'doctor':
            case 'librarian':
                if ($role === 'specialist' && !self::isCustomAdminRole('specialist')) {
                    return 'specialist/index.php';
                }
                $landingPages = [
                    'specialist' => 'specialist_dashboard.php',
                    'doctor' => 'role_dashboard.php',
                    'librarian' => 'role_dashboard.php',
                ];
                $allowedPages = self::getAllowedAdminPagesForRole((string)$role);
                $landingPage = $landingPages[(string)$role];
                if (is_array($allowedPages) && in_array($landingPage, $allowedPages, true)) {
                    return 'admin/' . $landingPage;
                }
                $fallbackPage = is_array($allowedPages)
                    ? AdminRolePageCatalog::landingPage((string)$role, $allowedPages)
                    : null;
                return $fallbackPage !== null ? 'admin/' . $fallbackPage : 'index.php';
            case 'student':
                return 'student/portal.php'; // توجيه الطالب إلى البوابة الرئيسية
            case 'external_teacher':
                return 'external/index.php'; // توجيه المعلم الخارجي إلى بوابته
            case 'employee':
                return 'staff_hr_portal.php'; // بوابة الخدمة الذاتية للعامل غير الأكاديمي
            default:
                if (self::isCustomAdminRole($role)) {
                    $allowedPages = self::getAllowedAdminPagesForRole((string)$role);
                    $roleFamily = self::getAdministrativeRoleFamily((string)$role);
                    $landingPage = is_array($allowedPages)
                        ? AdminRolePageCatalog::landingPage($roleFamily, $allowedPages)
                        : null;
                    return $landingPage !== null ? 'admin/' . $landingPage : 'index.php';
                }
                return 'index.php';
        }
    }

    public static function isCustomAdminRole(?string $role): bool {
        $role = trim((string)$role);
        if ($role === '' || in_array($role, ['admin', 'super_admin', 'teacher', 'student', 'external_teacher', 'supervisor'], true)) {
            return false;
        }

        try {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_roles'");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() === 0) {
                return false;
            }
            $stmt = $db->prepare("SELECT COUNT(*) FROM staff_roles WHERE role_key = ? AND portal_type = 'admin_like' AND status = 'active'");
            $stmt->execute([$role]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function getAllowedAdminPagesForRole(?string $role): ?array {
        $role = trim((string)$role);
        if ($role === 'admin' || $role === 'super_admin') {
            return null;
        }
        if (!self::isCustomAdminRole($role)) {
            return [];
        }

        try {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            $stmt = $db->prepare("SELECT page_name FROM staff_role_pages WHERE role_key = ? ORDER BY page_name");
            $stmt->execute([$role]);
            $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $roleFamily = (new StaffRoleCapabilityResolver($db))->family($role);
            // Portal landing pages and intrinsic workflow pages are always available
            // for the active role family, independently from editable page grants.
            $pages = array_merge($pages, AdminRolePageCatalog::mandatoryPages($roleFamily));
            $pages[] = 'profile.php';
            return AdminRolePageCatalog::expandWithDependencies(
                array_values(array_unique(array_filter($pages)))
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function getAdministrativeRoleFamily(?string $role): string {
        $role = trim((string)$role);
        if ($role === '') {
            return '';
        }
        try {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            return (new StaffRoleCapabilityResolver($database->getConnection()))->family($role);
        } catch (Throwable $e) {
            return $role;
        }
    }

    public static function roleCanAccessAdminPage(?string $role, ?string $page = null): bool {
        $role = trim((string)$role);
        $allowedPages = self::getAllowedAdminPagesForRole($role);
        $page = $page ?: self::getCurrentPage();
        return AuthorizationFacade::allowsAdminPage($role, $page, $allowedPages);
    }
    
    /**
     * Log actions for audit purposes
     * @param string $action
     * @param string $description
     * @param int $user_id
     * @return void
     */
    public static function logAction($action, $description, $user_id) {
        return ActivityLog::log(
            (string) $action,
            'user',
            (int) $user_id,
            null,
            ['description' => (string) $description, 'legacy_api' => 'Utilities::logAction'],
            ['actor_id' => (int) $user_id]
        );
    }
    
    /**
     * Show alert message
     * @param string $message
     * @param string $type (success, danger, warning, info)
     * @return string HTML for alert
     */
    public static function showAlert($message, $type = 'info') {
        return '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">
                    ' . $message . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
    }
    
    /**
     * Show flash message from session
     * @return string HTML for alert
     */
    public static function showFlashMessage() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $html = '';
        
        if (isset($_SESSION['alert_message']) && isset($_SESSION['alert_type'])) {
            $html = self::showAlert($_SESSION['alert_message'], $_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            unset($_SESSION['alert_type']);
        }
        
        return $html;
    }
      /**
     * Set flash message
     * @param string $message
     * @param string $type (success, danger, warning, info)
     * @return void
     */
    public static function setFlashMessage($message, $type = 'info') {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['alert_message'] = $message;
        $_SESSION['alert_type'] = $type;
    }
    
    /**
     * Translate role to Arabic
     * @param string $role
     * @return string
     */
    public static function translateRole($role) {
        switch ($role) {
            case 'admin':
                return 'مدير النظام';
            case 'teacher':
                return 'معلم';
            case 'specialist':
                return 'أخصائي';
            case 'student':
                return 'طالب';
            default:
                return $role;
        }
    }

    /**
     * Unified student points summary (total / positive / negative)
     * @param PDO $db
     * @param int $student_id
     * @return array
     */
    public static function getStudentPointsSummary($db, $student_id) {
        $summary = ['total'=>0,'positive'=>0,'negative'=>0];
        if (!$student_id) return $summary;
        $sql = "SELECT 
            COALESCE(SUM(CASE 
                WHEN e.custom_points IS NOT NULL THEN e.custom_points 
                ELSE CASE WHEN et.type='positive' THEN et.points ELSE -et.points END END),0) AS total,
            COALESCE(SUM(CASE 
                WHEN e.custom_points IS NOT NULL THEN CASE WHEN e.custom_points>0 THEN e.custom_points ELSE 0 END 
                ELSE CASE WHEN et.type='positive' THEN et.points ELSE 0 END END),0) AS positive,
            COALESCE(SUM(CASE 
                WHEN e.custom_points IS NOT NULL THEN CASE WHEN e.custom_points<0 THEN ABS(e.custom_points) ELSE 0 END 
                ELSE CASE WHEN et.type='negative' THEN et.points ELSE 0 END END),0) AS negative
            FROM evaluations e 
            JOIN evaluation_types et ON e.evaluation_type_id = et.id
            WHERE e.student_id = :sid";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':sid', $student_id, PDO::PARAM_INT);
        if ($stmt->execute()) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $summary['total'] = (int)$row['total'];
                $summary['positive'] = (int)$row['positive'];
                $summary['negative'] = (int)$row['negative'];
            }
        }
        return $summary;
    }

    /**
     * Calculate student progress level based on points
     * @param int $total_points
     * @return array
     */
    public static function getStudentLevel($total_points) {
        // Define level thresholds with legendary levels and stars (stars start from متفوق)
        // Using Bootstrap 5 standard colors only
        $levels = [
            ['name' => 'مبتدئ', 'min' => 0, 'max' => 10, 'color' => 'secondary', 'icon' => 'fa-seedling', 'stars' => 0],
            ['name' => 'متطور', 'min' => 11, 'max' => 25, 'color' => 'info', 'icon' => 'fa-leaf', 'stars' => 0],
            ['name' => 'جيد', 'min' => 26, 'max' => 50, 'color' => 'primary', 'icon' => 'fa-tree', 'stars' => 0],
            ['name' => 'ممتاز', 'min' => 51, 'max' => 75, 'color' => 'success', 'icon' => 'fa-star', 'stars' => 0],
            ['name' => 'متفوق', 'min' => 76, 'max' => 100, 'color' => 'warning', 'icon' => 'fa-crown', 'stars' => 1],
            ['name' => 'بطل', 'min' => 101, 'max' => 150, 'color' => 'danger', 'icon' => 'fa-trophy', 'stars' => 2],
            ['name' => 'بطل ذهبي', 'min' => 151, 'max' => 200, 'color' => 'warning', 'icon' => 'fa-medal', 'stars' => 3],
            ['name' => 'بطل ماسي', 'min' => 201, 'max' => 250, 'color' => 'info', 'icon' => 'fa-gem', 'stars' => 4],
            ['name' => 'بطل أسطوري', 'min' => 251, 'max' => PHP_INT_MAX, 'color' => 'legendary-gold', 'icon' => 'fa-dragon', 'stars' => 5]
        ];

        $current_level = $levels[0]; // Default to first level
        $next_level = $levels[1] ?? null;

        // Find current level
        foreach ($levels as $index => $level) {
            if ($total_points >= $level['min'] && $total_points <= $level['max']) {
                $current_level = $level;
                $next_level = $levels[$index + 1] ?? null;
                break;
            }
        }

        // Calculate progress percentage to next level
        $progress_percentage = 0;
        if ($next_level) {
            $current_progress = $total_points - $current_level['min'];
            $level_range = $current_level['max'] - $current_level['min'] + 1;
            $progress_percentage = min(100, ($current_progress / $level_range) * 100);
        } else {
            $progress_percentage = 100; // Max level reached
        }

        // Add stars display to level name
        $stars_display = '';
        if (isset($current_level['stars']) && $current_level['stars'] > 0) {
            $stars_display = ' ' . str_repeat('⭐', $current_level['stars']);
        }

        return [
            'current' => $current_level,
            'next' => $next_level,
            'progress' => round($progress_percentage, 1),
            'points_to_next' => $next_level ? ($current_level['max'] + 1 - $total_points) : 0,
            'stars_display' => $stars_display,
            'stars_count' => $current_level['stars'] ?? 0
        ];
    }

    /**
     * Generate stars HTML display for legendary levels
     * @param int $stars_count Number of stars (0-4)
     * @return string HTML string with stars
     */
    public static function getStarsHtml($stars_count) {
        if ($stars_count <= 0) {
            return '';
        }
        return ' <span class="level-stars" style="color: #FFD700; font-size: 0.9em;">' 
               . str_repeat('⭐', $stars_count) 
               . '</span>';
    }

    /**
     * Check if a teacher/supervisor is assigned to a class
     */
    public static function teacherHasClass(PDO $db, int $teacherId, int $classId): bool {
        $sql = "SELECT 1 FROM user_class_access WHERE user_id = :uid AND class_id = :cid LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':uid', $teacherId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $classId, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Check if a student belongs to a class (direct users.class_id or via user_class_access)
     */
    public static function studentInClass(PDO $db, int $studentId, int $classId): bool {
        $sql = "SELECT 1 FROM users WHERE id = :sid AND role='student' AND class_id = :cid LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $classId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn()) return true;
        $sql2 = "SELECT 1 FROM user_class_access WHERE user_id = :sid AND class_id = :cid LIMIT 1";
        $stmt2 = $db->prepare($sql2);
        $stmt2->bindValue(':sid', $studentId, PDO::PARAM_INT);
        $stmt2->bindValue(':cid', $classId, PDO::PARAM_INT);
        $stmt2->execute();
        return (bool)$stmt2->fetchColumn();
    }

    /**
     * Check if evaluations are currently allowed based on system settings
     * @param PDO $db Database connection
     * @return array ['allowed' => bool, 'message' => string, 'reason' => string]
     */
    public static function areEvaluationsAllowed($db) {
        try {
            // جلب إعدادات النظام من جدول settings
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            // إذا لم توجد إعدادات، السماح افتراضياً
            if (empty($settings)) {
                return ['allowed' => true, 'message' => '', 'reason' => ''];
            }
            
            // التحقق من تفعيل النظام
            if (isset($settings['evaluations_enabled']) && $settings['evaluations_enabled'] != '1') {
                return [
                    'allowed' => false,
                    'message' => 'نظام التقييمات متوقف مؤقتاً من قبل الإدارة',
                    'reason' => 'disabled'
                ];
            }
            
            // التحقق من اليوم الحالي
            $current_day = date('l'); // Sunday, Monday, etc.
            
            // تحويل اليوم الحالي من الإنجليزية إلى العربية
            $english_to_arabic_days = [
                'Sunday' => 'الأحد',
                'Monday' => 'الاثنين',
                'Tuesday' => 'الثلاثاء',
                'Wednesday' => 'الأربعاء',
                'Thursday' => 'الخميس',
                'Friday' => 'الجمعة',
                'Saturday' => 'السبت'
            ];
            
            $current_day_arabic = $english_to_arabic_days[$current_day] ?? $current_day;
            $allowed_days = isset($settings['allowed_days']) ? explode(',', $settings['allowed_days']) : [];
            $allowed_days = array_map('trim', $allowed_days); // إزالة المسافات
            
            if (!empty($allowed_days) && !in_array($current_day_arabic, $allowed_days)) {
                return [
                    'allowed' => false,
                    'message' => 'اليوم الحالي (' . $current_day_arabic . ') غير مسموح فيه بإعطاء التقييمات',
                    'reason' => 'day_not_allowed',
                    'allowed_days' => implode(', ', $allowed_days)
                ];
            }
            
            // التحقق من الوقت الحالي (إلا إذا كان الوقت مفتوح)
            $unlimited_time = isset($settings['unlimited_time']) && $settings['unlimited_time'] == '1';
            
            if (!$unlimited_time) {
                $current_time = date('H:i');
                $allowed_time_from = $settings['allowed_time_from'] ?? '00:00';
                $allowed_time_to = $settings['allowed_time_to'] ?? '23:59';
                
                // التحقق من صحة الوقت
                $time_allowed = false;
                
                // حالة 1: الوقت في نفس اليوم (مثل: من 08:00 إلى 14:00)
                if ($allowed_time_from <= $allowed_time_to) {
                    // الوقت المسموح لا يعبر منتصف الليل
                    $time_allowed = ($current_time >= $allowed_time_from && $current_time <= $allowed_time_to);
                } 
                // حالة 2: الوقت يعبر منتصف الليل (مثل: من 20:00 إلى 02:00 أو من 08:00 إلى 07:00)
                else {
                    // الوقت المسموح يعبر منتصف الليل
                    $time_allowed = ($current_time >= $allowed_time_from || $current_time <= $allowed_time_to);
                }
                
                if (!$time_allowed) {
                    // تحويل الوقت إلى صيغة 12 ساعة
                    $time_from_12h = date('g:i A', strtotime($allowed_time_from));
                    $time_to_12h = date('g:i A', strtotime($allowed_time_to));
                    
                    return [
                        'allowed' => false,
                        'message' => 'الوقت الحالي خارج أوقات التقييمات المسموح بها',
                        'reason' => 'time_not_allowed',
                        'allowed_time_from' => $time_from_12h,
                        'allowed_time_to' => $time_to_12h
                    ];
                }
            }
            
            // كل شيء على ما يرام
            return ['allowed' => true, 'message' => '', 'reason' => ''];
            
        } catch (Exception $e) {
            // في حالة حدوث خطأ، السماح افتراضياً لعدم تعطيل النظام
            return ['allowed' => true, 'message' => '', 'reason' => ''];
        }
    }

    /**
     * Build query string from allowed GET parameters
     * @param array $allowedParams
     * @return string
     */
    public static function buildQueryString($allowedParams = []) {
        $params = [];
        // يدعم نمطين للاستدعاء:
        //  1. قائمة مفهرسة بأسماء معاملات تُقرأ من $_GET: ['tab', 'action', 'id']
        //  2. مصفوفة ارتباطية بقيم تجاوز صريحة: ['tab' => 'basic', 'action' => 'edit']
        foreach ($allowedParams as $key => $p) {
            if (is_int($key)) {
                // نمط مفهرس: $p اسم معامل يُقرأ من $_GET
                if (isset($_GET[$p]) && $_GET[$p] !== '') {
                    $params[$p] = $_GET[$p];
                }
            } else {
                // نمط ارتباطي: $key اسم المعامل و $p قيمة التجاوز
                if ($p !== '' && $p !== null) {
                    $params[$key] = $p;
                }
            }
        }
        return empty($params) ? '' : '?' . http_build_query($params);
    }

    /**
     * Get UI preference for the current user
     * @param string $key Preference key (e.g. 'sidebar_layout', 'sidebar_theme', 'icon_style')
     * @param string $default Default value if not set
     * @return string
     */
    public static function getUserPreference($key, $default = '') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) {
            return $default;
        }

        // Cache file inside storage/private
        $prefFile = dirname(__DIR__) . '/storage/private/user_preferences.json';
        if (!file_exists($prefFile)) {
            return $default;
        }

        $allPrefs = json_decode(@file_get_contents($prefFile), true);
        if (!is_array($allPrefs)) {
            return $default;
        }

        return $allPrefs[$userId][$key] ?? $default;
    }

    /**
     * Set UI preference for the current user
     * @param string $key Preference key
     * @param string $value Preference value
     * @return bool
     */
    public static function setUserPreference($key, $value) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) {
            return false;
        }

        $prefDir = dirname(__DIR__) . '/storage/private';
        if (!is_dir($prefDir)) {
            @mkdir($prefDir, 0755, true);
        }

        $prefFile = $prefDir . '/user_preferences.json';
        $allPrefs = [];
        if (file_exists($prefFile)) {
            $allPrefs = json_decode(@file_get_contents($prefFile), true);
            if (!is_array($allPrefs)) {
                $allPrefs = [];
            }
        }

        if (!isset($allPrefs[$userId])) {
            $allPrefs[$userId] = [];
        }

        $allPrefs[$userId][$key] = $value;
        return @file_put_contents($prefFile, json_encode($allPrefs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    }
}
