<?php

require_once '../../includes/session_config.php';
require_once '../../includes/http_helpers.php';
require_once '../../config/database.php';
require_once '../../config/encryption.php';
require_once '../../classes/utilities.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

requireJsonUser();
if (!Utilities::roleCanAccessAdminPage((string)($_SESSION['role'] ?? ''), 'get_password.php')) {
    jsonError('ليس لديك صلاحية لتنفيذ هذا الإجراء', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('طريقة غير مسموحة', 405);
requireCsrfToken();

$input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$userId = (int)($input['user_id'] ?? 0);
$accountType = (string)($input['account_type'] ?? 'user');
if ($userId <= 0) jsonError('معرف المستخدم مطلوب', 422);

$database = new Database();
$db = $database->getConnection();
if (!$db) jsonError('تعذر الاتصال بقاعدة البيانات', 500);

try {
    if ($accountType === 'external_teacher') {
        $stmt = $db->prepare('SELECT id, name, email AS username, password_hash AS password FROM external_teachers WHERE id = ?');
    } else {
        $accountType = 'user';
        $stmt = $db->prepare('SELECT id, name, username, password FROM users WHERE id = ?');
    }
    $stmt->execute([$userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) jsonError('المستخدم غير موجود', 404);

    $plaintext = $accountType === 'user'
        ? decryptPasswordForUser((string)$target['password'], $userId)
        : decryptPassword((string)$target['password']);
    if ($plaintext === '') throw new RuntimeException('Stored password could not be decrypted');

    $db->beginTransaction();
    $keyRotated = false;
    if ($accountType === 'user' && passwordCipherNeedsRotation((string)$target['password'])) {
        $upgrade = $db->prepare('UPDATE users SET password = ?, password_hash = COALESCE(password_hash, ?), password_key_version = ? WHERE id = ?');
        $upgrade->execute([encryptPasswordForUser($plaintext, $userId), password_hash($plaintext, PASSWORD_DEFAULT), PASSWORD_KEY_VERSION, $userId]);
        $keyRotated = true;
    }
    if ($accountType === 'external_teacher' && !str_starts_with((string)$target['password'], 'gcm:1:')) {
        $upgrade = $db->prepare('UPDATE external_teachers SET password_hash = ? WHERE id = ?');
        $upgrade->execute([encryptPassword($plaintext), $userId]);
        $keyRotated = true;
    }

    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
        'security_view',
        $accountType === 'user' ? 'user_password' : 'external_teacher_password',
        $userId,
        (string)$target['name'],
        [
            'event' => 'password_revealed',
            'account_type' => $accountType,
            'username' => (string)$target['username'],
            'encryption_key_rotated' => $keyRotated,
            'undo_policy' => 'security_observation_not_undoable',
        ]
    );
    $db->commit();

    jsonSuccess(['password' => $plaintext, 'hide_after_seconds' => 15]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    handleJsonException($e, 'تعذر عرض كلمة المرور');
}
