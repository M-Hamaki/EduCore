<?php

function csrfToken(): string
{
    return (string)($_SESSION['csrf_token'] ?? '');
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function requireCsrfPost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $provided = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrfToken() || !$provided || !hash_equals(csrfToken(), $provided)) {
        http_response_code(419);
        if (str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/ajax/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>false,'message'=>'انتهت صلاحية رمز الأمان. أعد تحميل الصفحة وحاول مرة أخرى.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        exit('انتهت صلاحية رمز الأمان. أعد تحميل الصفحة وحاول مرة أخرى.');
    }
}
