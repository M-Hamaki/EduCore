<?php

declare(strict_types=1);

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/session_config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/utilities.php';
require_once __DIR__ . '/classes/user.php';
require_once __DIR__ . '/classes/StaffActiveRoleService.php';
require_once __DIR__ . '/classes/ActivityLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

$portalRoute = static function (bool $teamsContext = false): string {
    return 'index.php?skip_intro=1' . ($teamsContext ? '&from_teams=1' : '');
};

if (isset($_SESSION['user_id'])) {
    if (!empty($_SESSION['role_selection_required'])) {
        header('Location: select_role.php');
    } else {
        header('Location: ' . Utilities::getDashboardUrl((string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $teamsContext = (string) ($_GET['from_teams'] ?? '') === '1';
    header('Location: ' . $portalRoute($teamsContext), true, 303);
    exit;
}

requireCsrfPost();

$teamsContext = (string) ($_POST['from_teams'] ?? '') === '1';
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$_SESSION['login_username'] = $username;

$fail = static function (string $message) use ($portalRoute, $teamsContext): void {
    $_SESSION['login_access_message'] = $message;
    header('Location: ' . $portalRoute($teamsContext), true, 303);
    exit;
};

if ($username === '' || $password === '') {
    $fail('يرجى إدخال اسم المستخدم وكلمة المرور');
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    $fail('تعذر الاتصال بالخدمة حالياً. يرجى المحاولة لاحقاً.');
}

$user = new User($db);
$user->username = $username;
$user->password = $password;

try {
    if (!$user->login()) {
        // لا يظهر سبب التعطيل إلا بعد التحقق من كلمة المرور؛ والسبب المخصص يظهر وحده كما كتبه الأدمن.
        $fail($user->getLoginDenialMessage() ?: 'اسم المستخدم أو كلمة المرور غير صحيحة');
    }

    if ($user->role === 'student') {
        $stmt = $db->prepare(
            'SELECT s.stage_code
             FROM users u
             LEFT JOIN classes c ON u.class_id = c.id
             LEFT JOIN grades g ON c.grade_id = g.id
             LEFT JOIN stages s ON g.stage_id = s.id
             WHERE u.id = ?
             LIMIT 1'
        );
        $stmt->execute([(int) $user->id]);
        $studentStage = $stmt->fetchColumn();
        $studentStage = is_string($studentStage) && trim($studentStage) !== '' ? trim($studentStage) : null;
        if ($studentStage === null) {
            $fail('لم يتم تعيين صف أو مرحلة دراسية لحسابك. يرجى التواصل مع الإدارة.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user->id;
        $_SESSION['name'] = (string) $user->name;
        $_SESSION['role'] = 'student';
        $_SESSION['active_role'] = 'student';
        $_SESSION['primary_role'] = 'student';
        $_SESSION['is_supervisor'] = (int) ($user->is_supervisor ?? 0);
        $_SESSION['class_id'] = $user->class_id;
        $_SESSION['student_stage'] = $studentStage;
        $_SESSION['from_teams'] = $teamsContext;
        unset($_SESSION['login_username'], $_SESSION['login_access_message']);

        Utilities::logAction('login', 'User logged in successfully', (int) $user->id);
        ActivityLog::logLogin((int) $user->id, (string) $user->name, 'student');
        header('Location: ' . Utilities::getDashboardUrl('student'));
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user->id;
    $_SESSION['name'] = (string) $user->name;
    $_SESSION['role'] = (string) $user->role;
    $_SESSION['is_supervisor'] = (int) ($user->is_supervisor ?? 0);
    $_SESSION['from_teams'] = $teamsContext;

    $roleSession = (new StaffActiveRoleService($db))->startSession($_SESSION, (int) $user->id);
    unset($_SESSION['login_username'], $_SESSION['login_access_message']);
    Utilities::logAction('login', 'User logged in successfully', (int) $user->id);
    ActivityLog::logLogin(
        (int) $user->id,
        (string) $user->name,
        (string) ($_SESSION['primary_role'] ?? $user->role)
    );

    if ($roleSession['requires_selection']) {
        header('Location: select_role.php');
    } else {
        header('Location: ' . Utilities::getDashboardUrl((string) $roleSession['active_role']));
    }
    exit;
} catch (InvalidArgumentException $exception) {
    unset(
        $_SESSION['user_id'],
        $_SESSION['name'],
        $_SESSION['role'],
        $_SESSION['active_role'],
        $_SESSION['primary_role'],
        $_SESSION['available_roles'],
        $_SESSION['role_selection_required'],
        $_SESSION['student_stage'],
        $_SESSION['class_id']
    );
    $fail($exception->getMessage());
} catch (Throwable $exception) {
    error_log('Unified login failed: ' . $exception->getMessage());
    unset(
        $_SESSION['user_id'],
        $_SESSION['name'],
        $_SESSION['role'],
        $_SESSION['active_role'],
        $_SESSION['primary_role'],
        $_SESSION['available_roles'],
        $_SESSION['role_selection_required'],
        $_SESSION['student_stage'],
        $_SESSION['class_id']
    );
    $fail('تعذر إتمام تسجيل الدخول حالياً. يرجى المحاولة مرة أخرى.');
}
