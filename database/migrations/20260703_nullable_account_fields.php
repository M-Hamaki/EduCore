<?php
/**
 * Migration: جعل أعمدة بيانات الدخول قابلة لـ NULL
 *
 * الهدف: السماح بإضافة طالب/موظف ببيانات أساسية فقط، دون اسم مستخدم/كلمة مرور/دور،
 * ثم إكمال بيانات الدخول لاحقاً من صفحات (student_accounts.php / staff_accounts.php).
 *
 * - username: يصبح NULLABLE مع بقاء القيد UNIQUE (MariaDB يسمح بتعدّد قيم NULL).
 * - password: يصبح NULLABLE.
 * - role:    يصبح NULLABLE.
 *
 * أمان تسجيل الدخول: usernameExists() يستخدم WHERE username = ? فلا يطابق صفوف NULL،
 * وبالتالي لا يستطيع المستخدم غير المُهيَّأ تسجيل الدخول حتى يُعرف له اسم مستخدم وكلمة مرور.
 *
 * التشغيل اليدوي:
 *   php database/migrations/20260703_nullable_account_fields.php
 */
declare(strict_types=1);

// دالة Migration قابلة لإعادة الاستخدام عبر الـ runner المستقبلي
$educore_migration_20260703 = static function (PDO $db): void {
    $db->exec("ALTER TABLE users MODIFY username VARCHAR(50) NULL");
    $db->exec("ALTER TABLE users MODIFY password VARCHAR(255) NULL");
    $db->exec("ALTER TABLE users MODIFY role ENUM('admin','teacher','supervisor','specialist','student') NULL");
};

// ===== تشغيل مباشر عند التنفيذ عبر CLI =====
if (PHP_SAPI === 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    require_once __DIR__ . '/../../config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();
    if (!$pdo instanceof PDO) {
        fwrite(STDERR, "FAILED: no database connection.\n");
        exit(1);
    }
    try {
        $educore_migration_20260703($pdo);
        echo "OK: nullable account fields (username, password, role).\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
        exit(1);
    }
}
