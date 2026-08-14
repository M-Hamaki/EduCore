<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../src/Modules/Staff/StaffRolePageBulkCommandService.php';
require_once '../includes/session_config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    Utilities::validateSession('admin');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('طريقة الطلب غير صالحة.');
    }

    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrfToken)) {
        throw new InvalidArgumentException('رمز التحقق (CSRF) غير صالح.');
    }

    $database = new Database();
    $db = $database->getConnection();
    $activeRole = (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');

    $operation = (string)($_POST['operation'] ?? '');
    $targetRoleKeys = is_array($_POST['target_role_keys'] ?? null) ? $_POST['target_role_keys'] : [];
    $sourceRoleKey = (string)($_POST['source_role_key'] ?? '');
    $pages = is_array($_POST['pages'] ?? null) ? array_map('strval', $_POST['pages']) : [];
    $dryRun = !empty($_POST['dry_run']);

    $service = new \EduCore\Modules\Staff\StaffRolePageBulkCommandService($db);
    $result = $service->execute($operation, $targetRoleKeys, $sourceRoleKey, $pages, $activeRole, $dryRun);

    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'updated' => $result['updated'],
        'preview' => $result['preview']
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $isClientError = $e instanceof InvalidArgumentException
        || ($e instanceof RuntimeException && !($e instanceof PDOException));
    if (!$isClientError) {
        error_log('Bulk role page action failed: ' . $e->getMessage());
    }
    http_response_code($isClientError ? 400 : 500);
    echo json_encode([
        'success' => false,
        'message' => $isClientError ? $e->getMessage() : 'تعذر تحديث صفحات الأدوار بسبب خطأ داخلي.'
    ], JSON_UNESCAPED_UNICODE);
}
