<?php

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonSuccess(array $data = [], int $statusCode = 200): never
{
    jsonResponse(['success' => true] + $data, $statusCode);
}

function jsonError(string $message, int $statusCode = 400): never
{
    jsonResponse(['success' => false, 'message' => $message], $statusCode);
}

function requireJsonUser(array $roles = []): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        jsonError('غير مصرح', 401);
    }
    if ($roles && !in_array($_SESSION['role'], $roles, true)) {
        jsonError('ليس لديك صلاحية لتنفيذ هذا الإجراء', 403);
    }
}

function requireCsrfToken(): void
{
    $input = json_decode((string)file_get_contents('php://input'), true);
    $provided = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? (is_array($input) ? ($input['csrf_token'] ?? '') : '');
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!$expected || !$provided || !hash_equals($expected, (string)$provided)) {
        jsonError('رمز الأمان غير صالح', 419);
    }
}

function handleJsonException(Throwable $exception, string $safeMessage = 'حدث خطأ في الخادم'): never
{
    error_log(sprintf('%s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
    jsonError($safeMessage, 500);
}
