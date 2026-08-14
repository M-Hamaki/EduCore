<?php
/**
 * Push Notification Client Include
 * يتم تضمينه في جميع الصفحات لتسجيل Service Worker وعرض زر الإشعارات
 */

// فقط للمستخدمين المسجلين
if (!empty($_SESSION['user_id'])):
    // تحميل VAPID key
    if (!defined('VAPID_PUBLIC_KEY')) {
        $pushConfigPath = __DIR__ . '/../config/push_config.php';
        if (file_exists($pushConfigPath)) {
            if (!defined('SITE_URL')) {
                require_once __DIR__ . '/../config/database.php';
            }
            require_once $pushConfigPath;
        }
    }
    
    if (defined('VAPID_PUBLIC_KEY')):
        if (!function_exists('request_app_base_path')) {
            require_once __DIR__ . '/template_helper.php';
        }
        $pushBasePath = request_app_base_path();
?>
<!-- Push Notifications -->
<script>
window.VAPID_PUBLIC_KEY = '<?php echo VAPID_PUBLIC_KEY; ?>';
window.PUSH_BASE_URL = <?php echo json_encode($pushBasePath, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="<?php echo asset_url('../assets/js/push-notifications.js'); ?>"></script>
<?php 
        unset($pushBasePath);
    endif;
endif;
?>
