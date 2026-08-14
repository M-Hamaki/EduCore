<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manager = (string) file_get_contents($root . '/classes/UndoManager.php');
$endpoint = (string) file_get_contents($root . '/api/undo.php');
$client = (string) file_get_contents($root . '/assets/js/undo-toast.js');

$checks = [
    'undo_notice_is_queued_only_for_current_session_actor' => strpos($manager, "session_status() !== PHP_SESSION_ACTIVE") !== false
        && strpos($manager, "\$_SESSION['pending_undo_notice']") !== false
        && strpos($manager, "\$_SESSION['user_id']") !== false,
    'quick_undo_has_server_owned_window' => strpos($manager, 'private const QUICK_UNDO_MINUTES = 5') !== false
        && strpos($manager, 'public static function getQuickUndoable(') !== false
        && strpos($manager, 'DATE_SUB(NOW(), INTERVAL " . $minutes . " MINUTE)') !== false,
    'check_consumes_only_the_session_notice' => strpos($endpoint, "\$_SESSION['pending_undo_notice'] ?? null") !== false
        && strpos($endpoint, "unset(\$_SESSION['pending_undo_notice'])") !== false
        && strpos($endpoint, 'UndoManager::getQuickUndoable($userId, $noticeId)') !== false
        && strpos($endpoint, 'UndoManager::getLastUndoable($userId)') === false,
    'quick_undo_requires_the_explicit_notice_id' => strpos($endpoint, "filter_input(INPUT_POST, 'undo_id', FILTER_VALIDATE_INT)") !== false
        && strpos($endpoint, 'UndoManager::undo($userId, $undoId, false, UndoManager::quickUndoMinutes())') !== false
        && strpos($client, "'&undo_id=' + encodeURIComponent(currentUndoId)") !== false,
    'client_uses_server_expiry_not_browser_clock' => strpos($client, 'Number(data.expires_in) <= 0') !== false
        && strpos($client, 'new Date(') === false
        && strpos($client, 'last_shown_undo_id') === false
        && strpos($client, 'sessionStorage') === false,
    'client_uses_action_specific_arabic_copy' => strpos($client, "insert: {prefix: 'إضافة', completed: 'تمت إضافة'}") !== false
        && strpos($client, "update: {prefix: 'تعديل', completed: 'تم تعديل'}") !== false
        && strpos($client, "delete: {prefix: 'حذف', completed: 'تم حذف'}") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}

exit($failed ? 1 : 0);
