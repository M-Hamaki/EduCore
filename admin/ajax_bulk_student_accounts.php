<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../src/Modules/Accounts/AccountBulkSelection.php';
require_once '../src/Modules/Accounts/StudentAccountBulkCommandService.php';
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
    $academicYearId = AcademicYear::currentId($db);
    $actorId = (int)($_SESSION['user_id'] ?? 0);

    $action = (string)($_POST['action'] ?? '');
    $onError = (string)($_POST['on_error'] ?? 'stop');

    $selection = \EduCore\Modules\Accounts\AccountBulkSelection::fromArray($_POST);
    $service = new \EduCore\Modules\Accounts\StudentAccountBulkCommandService($db);

    $result = $service->execute(
        $action,
        $selection,
        $academicYearId,
        $actorId,
        $onError,
        isset($_POST['disable_reason']) ? (string) $_POST['disable_reason'] : null
    );

    $downloadUrl = null;
    if (!empty($result['credentials'])) {
        $token = bin2hex(random_bytes(16));
        $_SESSION['bulk_credentials_export'][$token] = [
            'type' => 'student',
            'data' => $result['credentials'],
            'created_at' => time()
        ];
        $downloadUrl = 'ajax/download_bulk_credentials.php?token=' . $token;
    }

    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'succeeded' => $result['succeeded'],
        'skipped' => $result['skipped'],
        'failed' => $result['failed'],
        'download_url' => $downloadUrl
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $isClientError = $e instanceof InvalidArgumentException
        || ($e instanceof RuntimeException && !($e instanceof PDOException));
    if (!$isClientError) {
        error_log('Student bulk account action failed: ' . $e->getMessage());
    }
    http_response_code($isClientError ? 400 : 500);
    echo json_encode([
        'success' => false,
        'message' => $isClientError ? $e->getMessage() : 'تعذر تنفيذ العملية الجماعية بسبب خطأ داخلي.'
    ], JSON_UNESCAPED_UNICODE);
}
