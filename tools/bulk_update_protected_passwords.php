<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/encryption.php';
require_once __DIR__ . '/../classes/ActivityLog.php';

function generateRandomReadablePassword(int $length = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max = strlen($chars) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

try {
    $db = (new Database())->getConnection();
    ActivityLog::setDb($db);

    $sql = "SELECT u.id, u.name, u.username, u.role
        FROM users u
        WHERE u.role IS NOT NULL AND u.role != 'student'
          AND (u.password LIKE '$2y$%' OR u.password LIKE '\$argon%' OR (u.password IS NULL AND u.password_hash IS NOT NULL))
        ORDER BY u.id ASC";

    $stmt = $db->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "No protected staff accounts found.\n";
        exit(0);
    }

    echo "Found " . count($users) . " protected staff accounts to update.\n";

    $db->beginTransaction();

    $updateStmt = $db->prepare("UPDATE users SET password = :enc, password_hash = :hash, password_key_version = :ver WHERE id = :id");
    $version = PASSWORD_KEY_VERSION;

    $records = [];

    foreach ($users as $user) {
        $userId = (int)$user['id'];
        $plainPassword = generateRandomReadablePassword(10);
        $encPassword = encryptPasswordForUser($plainPassword, $userId);
        $hashPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

        $updateStmt->execute([
            ':enc' => $encPassword,
            ':hash' => $hashPassword,
            ':ver' => $version,
            ':id' => $userId,
        ]);

        $records[] = [
            'id' => $userId,
            'name' => $user['name'],
            'username' => $user['username'] ?? '—',
            'role' => $user['role'],
            'new_password' => $plainPassword,
        ];
    }

    $db->commit();
    echo "Successfully updated " . count($records) . " accounts in database.\n";

    // Save CSV file to exports/
    $exportDir = __DIR__ . '/../storage/exports';
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }

    $csvFile = $exportDir . '/staff_new_passwords_' . date('Y-m-d_H-i-s') . '.csv';
    $fp = fopen($csvFile, 'w');
    // Write UTF-8 BOM for Excel Arabic support
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, ['م', 'كود/معرف الحساب', 'الاسم', 'اسم المستخدم', 'الدور', 'كلمة المرور الجديدة']);

    $num = 1;
    foreach ($records as $r) {
        fputcsv($fp, [
            $num++,
            $r['id'],
            $r['name'],
            $r['username'],
            $r['role'],
            $r['new_password']
        ]);
    }
    fclose($fp);

    echo "CSV exported to: " . realpath($csvFile) . "\n";

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
