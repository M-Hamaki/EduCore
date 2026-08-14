<?php
/**
 * إعدادات قاعدة البيانات
 * تم الإنشاء تلقائياً بواسطة معالج التثبيت
 * 
 * جميع بيانات الاتصال بقاعدة البيانات موحدة هنا
 * لا تضع بيانات اتصال في أي ملف آخر - استخدم الثوابت التالية
 */

// تحميل متغيرات البيئة من .env
require_once __DIR__ . '/env_loader.php';
require_once dirname(__DIR__) . '/classes/SafeErrorPolicy.php';

// المنطقة الزمنية الافتراضية للنظام — تضمن اتساق الطوابع الزمنية حتى لو كانت
// قيمة date.timezone في php.ini غير مضبوطة أو أُعيدت لقيمة خاطئة بعد تحديث XAMPP.
// يمكن تجاوزها عبر APP_TIMEZONE في ملف .env.
$_app_timezone = (string)env('APP_TIMEZONE', 'Africa/Cairo');
if ($_app_timezone !== '' && @date_default_timezone_set($_app_timezone)) {
    if (!defined('APP_TIMEZONE')) {
        define('APP_TIMEZONE', $_app_timezone);
    }
} elseif (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', @date_default_timezone_get() ?: 'UTC');
}
unset($_app_timezone);

// عنوان التطبيق للروابط المطلقة التي تُرسل خارج النظام.
$appUrl = rtrim((string)env('APP_URL', env('SITE_URL', 'https://portal.dmls.edu.eg')), '/');
if (!defined('APP_URL')) define('APP_URL', $appUrl);
if (!defined('SITE_URL')) define('SITE_URL', APP_URL); // Backward-compatible alias.
unset($appUrl);

// بيانات الاتصال بقاعدة البيانات - مكان واحد فقط
// Database credentials - single source of truth
if (!defined('DB_HOST'))     define('DB_HOST', env('DB_HOST', 'localhost'));
if (!defined('DB_NAME'))     define('DB_NAME', env('DB_NAME', 'educore'));
if (!defined('DB_USERNAME')) define('DB_USERNAME', env('DB_USERNAME', 'root'));
// اختيار كلمة المرور تلقائياً: إذا كنا على localhost وDB_PASSWORD_LOCAL معرّف، استخدمه
// نكتشف البيئة المحلية من SERVER_NAME أو من كوننا نعمل عبر CLI (php_sapi_name) أو من المضيف
$_db_server_name = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? '');
$_db_is_cli      = (PHP_SAPI === 'cli');
// CLI دائماً محلي في بيئة XAMPP — استخدم كلمة المرور المحلية حتى لا نستخدم كلمة مرور الإنتاج
$_db_is_local    = in_array($_db_server_name, ['localhost', '127.0.0.1', '::1'], true)
    || ($_db_is_cli && (env('APP_ENV') !== 'production' || DIRECTORY_SEPARATOR === '\\'));
// كلمة المرور المحلية: لو معرّفة في .env استخدمها، وإلا سلسلة فارغة (افتراضي XAMPP)
$_db_local_pw    = env('DB_PASSWORD_LOCAL', null);
$_db_password    = ($_db_is_local)
    ? ($_db_local_pw !== null && $_db_local_pw !== '' ? $_db_local_pw : '')
    : env('DB_PASSWORD', '');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', $_db_password);
unset($_db_server_name, $_db_is_cli, $_db_is_local, $_db_local_pw, $_db_password);

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host = DB_HOST;
        $this->db_name = DB_NAME;
        $this->username = DB_USERNAME;
        $this->password = DB_PASSWORD;
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            SafeErrorPolicy::report($e, 'database.connection');
        }
        return $this->conn;
    }
}
