<?php

declare(strict_types=1);

define('ACCESS_ALLOWED', true);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/includes/session_config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/azure_sso.php';
require_once dirname(__DIR__) . '/classes/MicrosoftSSO.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

/** @param array<string,mixed> $data */
function teamsJsonResponse(bool $success, string $message, string $code, array $data = [], int $status = 200): never
{
    if (!$success && $message !== '') {
        $_SESSION['login_access_message'] = $message;
    }
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
        'code' => $code,
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    teamsJsonResponse(false, 'طريقة الطلب غير مسموحة.', 'method_not_allowed', [], 405);
}

$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '') {
    $originParts = parse_url($origin);
    $originAuthority = strtolower((string) ($originParts['host'] ?? ''));
    if (isset($originParts['port'])) {
        $originAuthority .= ':' . (int) $originParts['port'];
    }
    $requestAuthority = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($originAuthority === '' || !hash_equals($requestAuthority, $originAuthority)) {
        teamsJsonResponse(false, 'تعذر التحقق من مصدر طلب Teams.', 'invalid_origin', [], 403);
    }
}

$portalConfig = require dirname(__DIR__) . '/config/public_portal.php';
if (empty($portalConfig['teams_auto_sso_enabled'])) {
    teamsJsonResponse(false, '', 'feature_disabled', [], 503);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$requestData = $_POST;
if (str_contains($contentType, 'application/json')) {
    $decodedBody = json_decode((string) file_get_contents('php://input'), true);
    $requestData = is_array($decodedBody) ? $decodedBody : [];
}
$token = trim((string) ($requestData['token'] ?? ''));
if ($token === '' || strlen($token) > 20000) {
    teamsJsonResponse(false, 'تعذر استلام رمز دخول Teams.', 'missing_token', [], 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        teamsJsonResponse(false, 'تعذر الاتصال بالخدمة حالياً.', 'service_unavailable', [], 503);
    }

    $sso = new MicrosoftSSO($db);
    $decoded = $sso->verifyTeamsToken($token);
    if (!$decoded) {
        teamsJsonResponse(false, 'تعذر التحقق من جلسة Microsoft. يمكنك استخدام خيارات الدخول الأخرى.', 'invalid_token', [], 401);
    }

    $microsoftId = trim((string) ($decoded['oid'] ?? ''));
    $email = trim((string) ($decoded['preferred_username'] ?? $decoded['upn'] ?? $decoded['email'] ?? ''));
    $displayName = trim((string) ($decoded['name'] ?? 'مستخدم'));
    if ($microsoftId === '' || $email === '') {
        teamsJsonResponse(false, 'بيانات حساب Microsoft غير مكتملة. يمكنك استخدام خيارات الدخول الأخرى.', 'incomplete_identity', [], 401);
    }

    // Teams silent SSO is intentionally stricter than interactive SSO: an existing link is mandatory.
    $user = $sso->resolveLinkedMicrosoftLoginUser($microsoftId, $email);
    if (!$user) {
        teamsJsonResponse(
            false,
            'تعذر تسجيل الدخول التلقائي لأن حساب Microsoft غير مرتبط أو لأن البريد واسم المستخدم لم يعودا متطابقين. يمكنك استخدام خيارات الدخول الأخرى.',
            'identity_not_linked',
            [],
            403
        );
    }

    $accessDecision = $sso->loginAccessDecision($user);
    if (!$accessDecision['allowed']) {
        teamsJsonResponse(false, (string) $accessDecision['message'], 'access_denied', [], 403);
    }

    $studentStage = null;
    if (($user['role'] ?? '') === 'student') {
        $stageStmt = $db->prepare(
            'SELECT s.stage_code
             FROM users u
             LEFT JOIN classes c ON u.class_id = c.id
             LEFT JOIN grades g ON c.grade_id = g.id
             LEFT JOIN stages s ON g.stage_id = s.id
             WHERE u.id = ?
             LIMIT 1'
        );
        $stageStmt->execute([(int) $user['id']]);
        $stageValue = $stageStmt->fetchColumn();
        $studentStage = is_string($stageValue) && trim($stageValue) !== '' ? trim($stageValue) : null;
        if ($studentStage === null) {
            teamsJsonResponse(false, 'لم يتم تعيين صف أو مرحلة دراسية لحسابك. يرجى التواصل مع الإدارة.', 'student_stage_missing', [], 403);
        }
    }

    unset(
        $_SESSION['user_id'],
        $_SESSION['name'],
        $_SESSION['role'],
        $_SESSION['active_role'],
        $_SESSION['primary_role'],
        $_SESSION['available_roles'],
        $_SESSION['role_selection_required'],
        $_SESSION['class_id'],
        $_SESSION['student_stage'],
        $_SESSION['microsoft_login'],
        $_SESSION['microsoft_id'],
        $_SESSION['microsoft_email']
    );

    $microsoftUser = ['id' => $microsoftId, 'mail' => $email, 'displayName' => $displayName];
    if (!$sso->loginUser($user, $microsoftUser, false)) {
        teamsJsonResponse(false, 'تعذر إكمال تسجيل الدخول مؤقتاً. يمكنك استخدام خيارات الدخول الأخرى.', 'session_failed', [], 503);
    }

    $_SESSION['from_teams'] = true;
    if ($studentStage !== null) {
        $_SESSION['student_stage'] = $studentStage;
    }
    unset($_SESSION['login_access_message']);

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/auth/teams_token_handler.php'));
    $applicationPath = rtrim(str_replace('\\', '/', dirname(dirname($scriptName))), '/');
    if ($applicationPath === '.' || $applicationPath === '/') {
        $applicationPath = '';
    }
    $destination = !empty($_SESSION['role_selection_required'])
        ? 'select_role.php'
        : ltrim($sso->getDashboardUrl((string) ($_SESSION['active_role'] ?? $user['role'])), '/');
    $redirectUrl = $applicationPath . '/' . $destination;

    teamsJsonResponse(true, 'تم تسجيل الدخول بنجاح.', 'authenticated', [
        'redirectUrl' => $redirectUrl,
        'role' => (string) $user['role'],
    ]);
} catch (Throwable $exception) {
    error_log('[Teams SSO] Automatic login failed [' . get_class($exception) . ':' . (string) $exception->getCode() . ']');
    teamsJsonResponse(false, 'تعذر إكمال الدخول التلقائي. يمكنك استخدام خيارات الدخول الأخرى.', 'unexpected_error', [], 500);
}
