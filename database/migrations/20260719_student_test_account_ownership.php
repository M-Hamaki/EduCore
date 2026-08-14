<?php

declare(strict_types=1);

/** Move and verify test-account flags before removing the legacy profile field. */
return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };
    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
        $stmt->execute([$table, $index]);
        return (bool) $stmt->fetchColumn();
    };

    if (!$columnExists('users', 'is_test_account')) {
        $db->exec('ALTER TABLE users ADD COLUMN is_test_account TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
    }
    if (!$indexExists('users', 'idx_users_role_test_account')) {
        $db->exec('ALTER TABLE users ADD KEY idx_users_role_test_account (role, is_test_account, status)');
    }

    if ($columnExists('student_profiles', 'is_test_account')) {
        $db->exec("UPDATE users u INNER JOIN student_profiles sp ON sp.user_id = u.id SET u.is_test_account = 1 WHERE u.role = 'student' AND sp.is_test_account = 1");
        $unmigrated = (int) $db->query("SELECT COUNT(*) FROM student_profiles sp INNER JOIN users u ON u.id = sp.user_id AND u.role = 'student' WHERE sp.is_test_account = 1 AND COALESCE(u.is_test_account, 0) <> 1")->fetchColumn();
        if ($unmigrated !== 0) {
            throw new RuntimeException('لم يكتمل نقل تصنيفات الحسابات التجريبية؛ تم إيقاف migration لحماية البيانات.');
        }
        if ($indexExists('student_profiles', 'idx_student_profiles_test_account')) {
            $db->exec('ALTER TABLE student_profiles DROP INDEX idx_student_profiles_test_account');
        }
        $db->exec('ALTER TABLE student_profiles DROP COLUMN is_test_account');
    }
};
