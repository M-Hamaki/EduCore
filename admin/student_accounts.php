<?php
/**
 * حسابات الطلاب — إدارة بيانات الدخول لكافة الطلاب.
 *
 * يتيح هذه الصفحة للمدير:
 *   - تعديل اسم المستخدم وكلمة المرور من خلال زر إجراء واحد
 *   - تفعيل / تعطيل الحساب (active / inactive)
 *   - تصنيف الحساب كرسمي أو تجريبي
 *   - عرض كلمة المرور وإظهارها/إخفاؤها لكل حساب
 *   - فلترة ديناميكية متسلسلة (مرحلة → صف → فصل)
 *   - تمييز الحسابات غير المُهيّأة (بدون username/password)
 *   - استيراد بيانات الدخول وتصديرها بصيغة CSV متضمنة كلمات المرور القابلة للاسترجاع
 *
 * مصدر البيانات: جدول users (role = 'student').
 */
$page_title = "حسابات الطلاب";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../config/encryption.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AccountListDataTableQuery.php';
require_once '../classes/StudentAccountClassificationService.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/FileUploadGuard.php';
require_once '../src/Modules/Accounts/AccountCredentialCsvService.php';
require_once '../src/Modules/Accounts/AccountBulkSelection.php';
require_once '../src/Modules/Accounts/StudentAccountBulkCommandService.php';
require_once '../vendor/autoload.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();

$currentAcademicYearId = AcademicYear::currentId($db);
$currentYear = AcademicYear::getCurrent($db);

// ====== معالجة POST (PRG) ======
$success_message = $_SESSION['success_message'] ?? null;
$error_message   = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);

    if (!$csrfOk) {
        $_SESSION['error_message'] = "رمز التحقق (CSRF) غير صالح، يرجى المحاولة مرة أخرى.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $action = $_POST['action'] ?? '';
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

    if ($action === 'import_credentials') {
        try {
            ActivityLog::setDb($db);
            $transferService = new \EduCore\Modules\Accounts\AccountCredentialCsvService(
                $db,
                static fn(string $password, int $targetUserId): string => encryptPasswordForUser($password, $targetUserId),
                static fn(string $entityType, int $targetId, string $targetName, array $details, string $batchId): bool => ActivityLog::log(
                    'update',
                    $entityType,
                    $targetId,
                    $targetName,
                    $details,
                    ['batch_id' => $batchId]
                )
            );
            $result = $transferService->import($_FILES['accounts_file'] ?? [], 'student');
            $_SESSION['success_message'] = 'تم استيراد بيانات الدخول وتحديث ' . (int)$result['updated'] . ' حساب طالب بنجاح.'
                . ((int)$result['skipped'] > 0 ? ' تم تجاوز ' . (int)$result['skipped'] . ' صف دون تغيير.' : '');
        } catch (Throwable $e) {
            error_log('student account credentials import failed: ' . $e->getMessage());
            $_SESSION['error_message'] = ($e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !($e instanceof PDOException)))
                ? $e->getMessage()
                : 'تعذر استيراد بيانات الدخول بسبب خطأ داخلي.';
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $checkStmt = $db->prepare("SELECT id, name, username, status, COALESCE(is_test_account, 0) AS is_test_account FROM users WHERE id = ? AND role = 'student' AND deleted_at IS NULL LIMIT 1");
    $checkStmt->execute([$userId]);
    $target = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $_SESSION['error_message'] = "الطالب غير موجود أو الحساب غير صالح.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    try {
        if ($action === 'update_credentials') {
            $newUsername = trim($_POST['username'] ?? '');
            $newPassword = trim($_POST['new_password'] ?? '');

            $updates = [];
            $params  = [];
            $details = [];

            if ($newUsername !== '' && $newUsername !== ($target['username'] ?? '')) {
                if (mb_strlen($newUsername) < 3) {
                    throw new RuntimeException("اسم المستخدم يجب ألا يقل عن 3 أحرف.");
                }
                $dupStmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
                $dupStmt->execute([$newUsername, $userId]);
                if ($dupStmt->fetchColumn()) {
                    throw new RuntimeException("اسم المستخدم مأخوذ بالفعل لحساب آخر، اختر اسماً مختلفاً.");
                }
                $updates[] = "username = ?";
                $params[]  = $newUsername;
                $details['username'] = ['old' => $target['username'] ?? '(فارغ)', 'new' => $newUsername];
            }

            if ($newPassword !== '') {
                if (mb_strlen($newPassword) < 4) {
                    throw new RuntimeException("كلمة المرور يجب ألا تقل عن 4 أحرف.");
                }
                $updates[] = "password = ?";
                $params[]  = encryptPasswordForUser($newPassword, $userId);
                $updates[] = "password_hash = ?";
                $params[]  = password_hash($newPassword, PASSWORD_DEFAULT);
                $details['password_reset'] = true;
            }

            if (empty($updates)) {
                throw new RuntimeException("لم يتم إدخال أي تغييرات، املأ حقل اسم المستخدم أو كلمة المرور.");
            }

            $params[] = $userId;
            $db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

            ActivityLog::logUpdate('student_account', $userId, $target['name'], $details);
            $_SESSION['success_message'] = "تم تحديث بيانات الدخول للطالب «" . $target['name'] . "» بنجاح.";

        } elseif ($action === 'set_test_account') {
            $rawClassification = (string) ($_POST['is_test_account'] ?? '');
            if (!in_array($rawClassification, ['0', '1'], true)) {
                throw new RuntimeException('نوع الحساب المطلوب غير صالح.');
            }
            $result = (new StudentAccountClassificationService($db))->setTestAccount(
                $userId,
                $rawClassification === '1',
                (int) ($_SESSION['user_id'] ?? 0)
            );
            $label = $result['is_test_account'] ? 'تجريبي' : 'رسمي';
            $_SESSION['success_message'] = $result['changed']
                ? "تم تحويل حساب الطالب «{$result['name']}» إلى حساب {$label}."
                : "حساب الطالب «{$result['name']}» مصنف بالفعل كحساب {$label}.";

        } elseif ($action === 'toggle_status') {
            $bulkAction = ($target['status'] === 'active') ? 'deactivate' : 'activate';
            $selection = \EduCore\Modules\Accounts\AccountBulkSelection::fromArray([
                'selection_mode' => 'selected',
                'ids' => [$userId],
            ]);
            $result = (new \EduCore\Modules\Accounts\StudentAccountBulkCommandService($db))->execute(
                $bulkAction,
                $selection,
                (int) $currentAcademicYearId,
                (int) ($_SESSION['user_id'] ?? 0),
                'stop',
                $bulkAction === 'deactivate' ? (string) ($_POST['disable_reason'] ?? '') : null
            );
            $_SESSION['success_message'] = $result['message'];

        } else {
            throw new RuntimeException("إجراء غير معروف.");
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ====== الفلاتر ======
$parseFilterArray = function ($key): array {
    if (!isset($_GET[$key])) return [];
    $val = $_GET[$key];
    if (is_array($val)) {
        return array_values(array_filter(array_map('trim', $val), fn ($v) => $v !== ''));
    }
    if (is_string($val) && trim($val) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $val)), fn ($v) => $v !== ''));
    }
    return [];
};

$stageFilters       = $parseFilterArray('stage_id');
$gradeFilters       = $parseFilterArray('grade_id');
$classFilters       = $parseFilterArray('class_id');
$statusFilters      = $parseFilterArray('status');
$configFilters      = $parseFilterArray('configured');
$accountTypeFilters = $parseFilterArray('account_type');
$studentIdFilter    = isset($_GET['student_id']) ? max(0, (int) $_GET['student_id']) : 0;

$legacyAccountListForRollback = false;
if ($legacyAccountListForRollback) {
$params = [];
$where = ["u.role = 'student'", "u.deleted_at IS NULL"];

if ($currentAcademicYearId > 0) {
    $where[] = "se.academic_year_id = ?";
    $params[] = $currentAcademicYearId;
}
if (!empty($stageFilters)) { $where[] = "se.stage_id IN (" . implode(',', array_fill(0, count($stageFilters), '?')) . ")"; foreach ($stageFilters as $v) $params[] = $v; }
if (!empty($gradeFilters)) { $where[] = "se.grade_id IN (" . implode(',', array_fill(0, count($gradeFilters), '?')) . ")"; foreach ($gradeFilters as $v) $params[] = $v; }
if (!empty($classFilters)) { $where[] = "se.class_id IN (" . implode(',', array_fill(0, count($classFilters), '?')) . ")"; foreach ($classFilters as $v) $params[] = $v; }
if ($studentIdFilter > 0) { $where[] = 'u.id = ?'; $params[] = $studentIdFilter; }

$query = "SELECT u.id, u.name, u.username, u.password, u.status, COALESCE(u.is_test_account, 0) AS is_test_account,
        sp.student_code,
        g.grade_name, s.stage_name, c.name AS class_name
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN student_enrollments se ON se.student_id = u.id
    LEFT JOIN grades g ON g.id = se.grade_id
    LEFT JOIN stages s ON s.id = se.stage_id
    LEFT JOIN classes c ON c.id = se.class_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        CASE WHEN u.username IS NULL THEN 0 ELSE 1 END,
        u.name ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($students as &$s) {
    $s['decrypted_password'] = '';
    if (!empty($s['password'])) {
        try {
            $s['decrypted_password'] = decryptPasswordForUser((string)$s['password'], (int)$s['id']);
        } catch (Throwable $e) {
            $s['decrypted_password'] = '';
        }
    }
    $s['is_configured'] = (!empty($s['username']) && !empty($s['password']));
}
unset($s);

}
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'non_enrolled' ? 'non_enrolled' : 'enrolled';

if (($_GET['action'] ?? '') === 'export_credentials') {
    $transferService = new \EduCore\Modules\Accounts\AccountCredentialCsvService(
        $db,
        static fn(string $password, int $targetUserId): string => encryptPasswordForUser($password, $targetUserId),
        static fn(): bool => true,
        null,
        static fn(string $stored, int $targetUserId): string => decryptPasswordForUser($stored, $targetUserId)
    );
    $dataset = $transferService->exportStudents();
    ActivityLog::setDb($db);
    $exportLogged = ActivityLog::log('export', 'student_account', null, 'تصدير بيانات دخول الطلاب', [
        'count' => count($dataset['rows']),
        'passwords_included' => true,
        'sensitive_export' => true,
    ]);
    if (!$exportLogged) {
        http_response_code(500);
        exit('تعذر تسجيل عملية تصدير كلمات المرور؛ لم يتم إنشاء الملف.');
    }

    $safeCsvCell = static function ($value): string {
        $value = (string)$value;
        return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
    };
    $filename = 'student_accounts_with_passwords_' . date('Y-m-d') . '.csv';
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_map($safeCsvCell, $dataset['headers']));
    foreach ($dataset['rows'] as $row) {
        fputcsv($output, array_map($safeCsvCell, $row));
    }
    fclose($output);
    exit();
}

$baseFiltersWithoutTab = [
    'stage_id' => $stageFilters, 'grade_id' => $gradeFilters, 'class_id' => $classFilters,
    'status' => $statusFilters, 'configured' => $configFilters, 'account_type' => $accountTypeFilters,
    'student_id' => $studentIdFilter,
];

$queryService = new AccountListDataTableQuery($db);
$enrolledSummary = $queryService->studentSummary($currentAcademicYearId, array_merge($baseFiltersWithoutTab, ['tab' => 'enrolled']));
$nonEnrolledSummary = $queryService->studentSummary($currentAcademicYearId, array_merge($baseFiltersWithoutTab, ['tab' => 'non_enrolled']));

$enrolledCount = $enrolledSummary['total'];
$nonEnrolledCount = $nonEnrolledSummary['total'];

$accountSummary = $activeTab === 'non_enrolled' ? $nonEnrolledSummary : $enrolledSummary;
$total = $accountSummary['total'];
$activeCount = $accountSummary['active'];
$inactiveCount = $accountSummary['inactive'];
$unconfiguredCount = $accountSummary['unconfigured'];
$activationRate = $total > 0 ? round(($activeCount / $total) * 100) : 0;
$students = [];

$stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_name")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY grade_name")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, name, grade_id FROM classes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

include_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-user-shield me-2 text-info"></i>حسابات الطلاب</h1>
    <div class="admin-top-actions no-print">
        <span class="badge bg-light text-dark border px-3 py-2">
            <i class="fas fa-calendar-alt me-1 text-primary"></i>
            العام: <?php echo htmlspecialchars($currentYear['name'] ?? '-'); ?>
        </span>
        <button type="button" class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal" data-bs-target="#importCredentialsModal">
            <i class="fas fa-file-import me-1"></i>استيراد Excel
        </button>
        <a href="student_accounts.php?action=export_credentials" class="btn btn-header-premium btn-export-soft" title="يحتوي الملف على كلمات المرور القابلة للاسترجاع">
            <i class="fas fa-file-excel me-1"></i>تصدير Excel
        </a>
        <a href="students.php" class="btn btn-header-premium btn-import-soft">
            <i class="fas fa-users me-1"></i>الطلاب المقيدين
        </a>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<?php if ($studentIdFilter > 0): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center" role="alert">
        <span><i class="fas fa-crosshairs me-2"></i>يتم عرض حساب الطالب المحدد لمعالجة نوع الحساب.</span>
        <a href="student_accounts.php" class="btn btn-light btn-sm"><i class="fas fa-list me-1"></i>عرض كل الحسابات</a>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<?php if ($unconfiguredCount > 0): ?>
    <div class="alert alert-warning alert-dismissible sticky-alert fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        يوجد <strong><?php echo (int)$unconfiguredCount; ?></strong> طالب بحاجة لإعداد بيانات الدخول.
        <a href="?configured=unconfigured&tab=<?php echo urlencode($activeTab); ?>" class="alert-link">عرضهم الآن</a>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<div id="servicesAlertContainer"></div>

<!-- بطاقات إحصائية -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$total; ?>">0</div>
                <div class="stat-card-label">إجمالي الحسابات</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-circle-check"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$activeCount; ?>">0</div>
                <div class="stat-card-label">حسابات مفعّلة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-percent"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><span class="counter" data-target="<?php echo (int)$activationRate; ?>">0</span>%</div>
                <div class="stat-card-label">نسبة التفعيل</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-user-clock"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$unconfiguredCount; ?>">0</div>
                <div class="stat-card-label">بحاجة لإعداد</div>
            </div>
        </div>
    </div>
</div>

<!-- تبويبات تقرير الحسابات (المقيدين / غير المقيدين) -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'enrolled' ? 'active' : ''; ?>" href="student_accounts.php?tab=enrolled">
            <i class="fas fa-user-graduate me-2 text-primary"></i>الطلاب المقيدين
            <span class="badge rounded-pill bg-primary ms-1"><?php echo (int)$enrolledCount; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'non_enrolled' ? 'active' : ''; ?>" href="student_accounts.php?tab=non_enrolled">
            <i class="fas fa-users-slash me-2 text-warning"></i>غير المقيدين (الخريجين والمنقولين)
            <span class="badge rounded-pill bg-secondary ms-1"><?php echo (int)$nonEnrolledCount; ?></span>
        </a>
    </li>
</ul>

<!-- جدول الحسابات مع فلاتر ديناميكية متسلسلة -->
<form class="admin-filter-bar" id="studentAccountFilters" autocomplete="off">
    <div class="admin-filter-controls">
        <!-- Stages Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($stages as $st): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input stage-checkbox" type="checkbox" name="stage_ids[]" value="<?php echo (int)$st['id']; ?>" id="stage_<?php echo (int)$st['id']; ?>" <?php echo in_array((string)$st['id'], $stageFilters, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="stage_<?php echo (int)$st['id']; ?>"><?php echo htmlspecialchars($st['stage_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Grades Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($grades as $gr): ?>
                    <div class="form-check mb-1 grade-item" data-stage="<?php echo (int)$gr['stage_id']; ?>">
                        <input class="form-check-input grade-checkbox" type="checkbox" name="grade_ids[]" value="<?php echo (int)$gr['id']; ?>" id="grade_<?php echo (int)$gr['id']; ?>" data-stage="<?php echo (int)$gr['stage_id']; ?>" <?php echo in_array((string)$gr['id'], $gradeFilters, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="grade_<?php echo (int)$gr['id']; ?>"><?php echo htmlspecialchars($gr['grade_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Classes Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($classes as $cl): ?>
                    <div class="form-check mb-1 class-item" data-grade="<?php echo (int)$cl['grade_id']; ?>">
                        <input class="form-check-input class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo (int)$cl['id']; ?>" id="class_<?php echo (int)$cl['id']; ?>" data-grade="<?php echo (int)$cl['grade_id']; ?>" <?php echo in_array((string)$cl['id'], $classFilters, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="class_<?php echo (int)$cl['id']; ?>"><?php echo htmlspecialchars($cl['name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Statuses Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="statusDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 130px;">
                <span>الحالة: <span id="selectedStatusesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="statusDropdown" style="max-height: 250px; overflow-y: auto; min-width: 180px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <div class="form-check mb-1">
                    <input class="form-check-input status-checkbox" type="checkbox" name="status[]" value="active" id="status_active" <?php echo in_array('active', $statusFilters, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="status_active">مفعّل</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input status-checkbox" type="checkbox" name="status[]" value="inactive" id="status_inactive" <?php echo in_array('inactive', $statusFilters, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="status_inactive">معطّل</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input status-checkbox" type="checkbox" name="status[]" value="graduated" id="status_graduated" <?php echo in_array('graduated', $statusFilters, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="status_graduated">خريج</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input status-checkbox" type="checkbox" name="status[]" value="transferred" id="status_transferred" <?php echo in_array('transferred', $statusFilters, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="status_transferred">منقول من المدرسة</label>
                </div>
            </div>
        </div>

        <!-- Configured Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="configuredDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 130px;">
                <span>التهيئة: <span id="selectedConfiguredLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="configuredDropdown" style="max-height: 250px; overflow-y: auto; min-width: 180px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <div class="form-check mb-1">
                    <input class="form-check-input configured-checkbox" type="checkbox" name="configured[]" value="configured" id="config_configured" <?php echo in_array('configured', $configFilters, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="config_configured">مُهيّأ</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input configured-checkbox" type="checkbox" name="configured[]" value="unconfigured" id="config_unconfigured" <?php echo in_array('unconfigured', $configFilters, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="config_unconfigured">غير مُهيّأ</label>
                </div>
            </div>
        </div>

        <!-- Account Types Dropdown -->
        <div class="dropdown d-inline-block me-2" name="account_type">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="accountTypeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>نوع الحساب: <span id="selectedAccountTypesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="accountTypeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 180px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <div class="form-check mb-1">
                    <input class="form-check-input account-type-checkbox" type="checkbox" name="account_type[]" value="official" id="type_official" <?php echo in_array('official', $accountTypeFilters, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="type_official">حساب رسمي</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input account-type-checkbox" type="checkbox" name="account_type[]" value="test" id="type_test" <?php echo in_array('test', $accountTypeFilters, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="type_test">حساب تجريبي</label>
                </div>
            </div>
        </div>

        <?php if ($studentIdFilter > 0): ?>
            <input type="hidden" name="student_id" value="<?php echo $studentIdFilter; ?>">
        <?php endif; ?>
    </div>

    <!-- الأزرار من جهة اليسار -->
    <div class="admin-filter-actions">
        <!-- Reset Filters Button -->
        <a href="student_accounts.php" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
            <i class="fas fa-undo me-1"></i>إعادة تعيين
        </a>

        <!-- Table Settings Button -->
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
            <i class="fas fa-cog me-1"></i>إعدادات الجدول
        </button>
    </div>
</form>

<div id="bulkFilterResetNotice" class="alert alert-info py-2 px-3 small d-none mb-3">
    <i class="fas fa-info-circle me-2"></i>تم إلغاء التحديد السابق بسبب تغيير الفلاتر الحية.
</div>

<div class="alert alert-info py-2 px-3 small mb-3" role="status">
    <i class="fas fa-layer-group me-2"></i>للإجراءات الجماعية: حدد الطلاب من العمود الأول. ولتعطيل مرحلة أو صف أو فصل، طبّق الفلتر ثم اختر «تحديد كل النتائج المطابقة للفلاتر».
</div>

<div id="bulkSelectionRequiredNotice" class="alert alert-warning py-2 px-3 small d-none mb-3" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i>حدد طالباً واحداً على الأقل، أو حدد الصفحة ثم اختر جميع النتائج المطابقة للفلاتر.
</div>

<div id="studentBulkActionBar" class="admin-bulk-action-bar d-none">
    <div class="admin-bulk-info">
        <span class="admin-bulk-badge bulk-selected-count">0</span>
        <span>حسابات محددة</span>
        <span class="text-muted small bulk-mode-label"></span>
        <button type="button" class="btn btn-sm btn-outline-primary btn-select-all-filtered d-none ms-2">
            <i class="fas fa-check-double me-1"></i>تحديد كل النتائج المطابقة للفلاتر (<span class="filtered-count-badge">0</span>)
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger btn-clear-selection ms-2">
            <i class="fas fa-times me-1"></i>إلغاء التحديد
        </button>
    </div>
    <div class="admin-bulk-actions">
        <button type="button" class="btn btn-sm btn-outline-success shadow-sm" onclick="openBulkStudentModal('activate')">
            <i class="fas fa-check-circle me-1"></i>تفعيل
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger shadow-sm" onclick="openBulkStudentModal('deactivate')">
            <i class="fas fa-ban me-1"></i>تعطيل
        </button>
        <button type="button" class="btn btn-sm btn-outline-warning shadow-sm" onclick="openBulkStudentModal('set_test')">
            <i class="fas fa-flask me-1"></i>تحويل لتجريبي
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary shadow-sm" onclick="openBulkStudentModal('set_official')">
            <i class="fas fa-school me-1"></i>تحويل لرسمي
        </button>
        <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="openBulkStudentModal('generate_credentials')">
            <i class="fas fa-key me-1"></i>توليد بيانات الدخول
        </button>
        <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="openBulkStudentModal('reset_passwords')">
            <i class="fas fa-sync-alt me-1"></i>إعادة تعيين المرور
        </button>
        <button type="button" class="btn btn-sm btn-outline-success shadow-sm" onclick="openBulkStudentModal('export_credentials')">
            <i class="fas fa-file-csv me-1"></i>تصدير المحدد
        </button>
    </div>
</div>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table" id="studentsAccountsTable">
                <thead>
                    <tr>
                        <th style="width: 40px;" class="no-sort text-center">
                            <input type="checkbox" class="form-check-input select-all-page" title="تحديد جميع سجلات الصفحة الحالية" aria-label="تحديد جميع سجلات الصفحة الحالية">
                        </th>
                        <th style="width: 40px;" class="no-sort">#</th>
                        <th>الكود</th>
                        <th>اسم الطالب</th>
                        <th>المرحلة</th>
                        <th>الصف</th>
                        <th>الفصل</th>
                        <th>اسم المستخدم</th>
                        <th>كلمة المرور</th>
                        <th>نوع الحساب</th>
                        <th>التهيئة</th>
                        <th>الحالة</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $idx => $s): ?>
                        <?php
                        $statusBadge = match($s['status']) {
                            'active'      => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>مفعّل</span>',
                            'inactive'    => '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>معطّل</span>',
                            'graduated'   => '<span class="badge bg-secondary"><i class="fas fa-graduation-cap me-1"></i>خريج</span>',
                            'transferred' => '<span class="badge bg-warning text-dark"><i class="fas fa-right-from-bracket me-1"></i>منقول من المدرسة</span>',
                            default       => '<span class="badge bg-light text-dark">' . htmlspecialchars($s['status']) . '</span>',
                        };
                        $pwdVal = $s['decrypted_password'] !== '' ? $s['decrypted_password'] : '—';
                        ?>
                        <tr data-user-id="<?php echo (int)$s['id']; ?>">
                            <td><?php echo $idx + 1; ?></td>
                            <td><?php echo htmlspecialchars($s['student_code'] ?? '-'); ?></td>
                            <td class="fw-bold">
                                <?php echo htmlspecialchars($s['name']); ?>
                            </td>
                            <td><?php echo !empty($s['stage_name']) ? htmlspecialchars($s['stage_name']) : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo !empty($s['grade_name']) ? htmlspecialchars($s['grade_name']) : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo !empty($s['class_name']) ? htmlspecialchars($s['class_name']) : '<span class="text-muted">—</span>'; ?></td>
                            <td>
                                <?php if (!empty($s['username'])): ?>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-light btn-sm pe-none" tabindex="-1"><?php echo htmlspecialchars($s['username']); ?></button>
                                        <button class="btn btn-light btn-sm copy-username-btn" type="button"
                                                data-username="<?php echo htmlspecialchars($s['username'], ENT_QUOTES); ?>"
                                                data-bs-toggle="tooltip" title="نسخ اسم المستخدم">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['decrypted_password'] !== ''): ?>
                                    <div class="input-group input-group-sm admin-password-compact">
                                        <input type="password" class="form-control form-control-sm pwd-field"
                                               value="<?php echo htmlspecialchars($pwdVal, ENT_QUOTES); ?>" readonly
                                               data-user-id="<?php echo (int)$s['id']; ?>">
                                        <button class="btn btn-light btn-sm pwd-toggle" type="button"
                                                data-target-input="pwd-field-<?php echo (int)$s['id']; ?>"
                                                data-bs-toggle="tooltip" title="إظهار/إخفاء كلمة المرور">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-light btn-sm pwd-copy" type="button"
                                                data-target-input="pwd-field-<?php echo (int)$s['id']; ?>"
                                                data-bs-toggle="tooltip" title="نسخ كلمة المرور">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($s['is_test_account'])): ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-flask me-1"></i>تجريبي</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border"><i class="fas fa-school me-1"></i>رسمي</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['is_configured']): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>مُهيّأ</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>غير مُهيّأ</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $statusBadge; ?></td>
                            <td class="text-center actions-column admin-table-actions">
                                <button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل اسم المستخدم وكلمة المرور"
                                        onclick="openCredentialsModal(<?php echo (int)$s['id']; ?>, <?php echo htmlspecialchars(json_encode($s['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($s['name']), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fas fa-user-edit"></i>
                                </button>
                                <button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip"
                                        title="<?php echo !empty($s['is_test_account']) ? 'تحويل إلى حساب رسمي' : 'تحويل إلى حساب تجريبي'; ?>"
                                        onclick="openTestAccountModal(<?php echo (int)$s['id']; ?>, <?php echo htmlspecialchars(json_encode($s['name']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo !empty($s['is_test_account']) ? 'true' : 'false'; ?>)">
                                    <i class="fas fa-flask"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-services customize-services me-1"
                                        data-id="<?php echo (int)$s['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($s['name']); ?>"
                                        data-role="student"
                                        data-bs-toggle="tooltip" title="تخصيص الخدمات">
                                    <i class="fas fa-cogs"></i>
                                </button>
                                <?php if ($s['status'] === 'active'): ?>
                                    <button class="btn btn-action-pills btn-deactivate" data-bs-toggle="tooltip" title="تعطيل الحساب"
                                            onclick="openToggleModal(<?php echo (int)$s['id']; ?>, <?php echo htmlspecialchars(json_encode($s['name']), ENT_QUOTES, 'UTF-8'); ?>, 'inactive')">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-action-pills btn-activate" data-bs-toggle="tooltip" title="تفعيل الحساب"
                                            onclick="openToggleModal(<?php echo (int)$s['id']; ?>, <?php echo htmlspecialchars(json_encode($s['name']), ENT_QUOTES, 'UTF-8'); ?>, 'active')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($students)): ?>
                        <tr><td colspan="12" class="text-center text-muted py-5">
                            <i class="fas fa-user-shield fa-3x mb-3 d-block text-muted"></i>
                            لا توجد حسابات طلاب مطابقة للفلاتر الحالية.
                        </td></tr>
                    <?php endif; ?>
                </tbody>
        </table>
    </div>
</div>

<!-- ===== Modal: إعدادات الأعمدة ===== -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">اختر الأعمدة التي تريد عرضها أو إخفاءها في جدول حسابات الطلاب:</p>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_student_code" checked>
                            <label class="form-check-label" for="col_student_code">الكود</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_student_name" checked>
                            <label class="form-check-label" for="col_student_name">اسم الطالب</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_stage_name" checked>
                            <label class="form-check-label" for="col_stage_name">المرحلة</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_grade_name" checked>
                            <label class="form-check-label" for="col_grade_name">الصف</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_class_name" checked>
                            <label class="form-check-label" for="col_class_name">الفصل</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_username" checked>
                            <label class="form-check-label" for="col_username">اسم المستخدم</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_password" checked>
                            <label class="form-check-label" for="col_password">كلمة المرور</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_account_type" checked>
                            <label class="form-check-label" for="col_account_type">نوع الحساب</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_configured" checked>
                            <label class="form-check-label" for="col_configured">التهيئة</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_status" checked>
                            <label class="form-check-label" for="col_status">الحالة</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Modal: الحساب الرسمي/التجريبي ===== -->
<div class="modal fade" id="testAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="testAccountModalContent">
            <form method="post" action="student_accounts.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="set_test_account">
                <input type="hidden" name="user_id" id="test_account_user_id">
                <input type="hidden" name="is_test_account" id="test_account_value">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-flask me-2"></i><span id="test_account_title">تغيير نوع الحساب</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-flask admin-modal-icon-lg text-warning" id="test_account_icon"></i></div>
                    <p class="text-center">الطالب: <span class="fw-bold text-primary" id="test_account_student_name">—</span></p>
                    <div class="alert alert-warning" id="test_account_consequence"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-warning" id="test_account_submit"><i class="fas fa-check me-1"></i><span>تأكيد</span></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="servicesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cogs me-2"></i>تخصيص خدمات الطالب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <span class="badge bg-primary px-3 py-2" id="servicesStudentName">—</span>
                </div>
                <div id="servicesModeAlert" class="alert alert-info py-2 small mb-3">
                    <i class="fas fa-layer-group me-1"></i><span id="servicesModeText">جاري التحميل...</span>
                </div>
                <div id="servicesStageAlert" class="alert alert-light border py-2 small mb-3">
                    <i class="fas fa-info-circle me-1"></i><span id="servicesStageText">جاري تحميل خدمات المرحلة...</span>
                </div>
                <div id="servicesLoading" class="text-center py-4 text-muted">
                    <i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل البيانات...
                </div>
                <div id="servicesOptions" class="row g-2 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-warning" id="resetServicesBtn">
                    <i class="fas fa-undo me-1"></i>إعادة للافتراضي
                </button>
                <button type="button" class="btn btn-primary" id="saveServicesBtn">
                    <i class="fas fa-save me-1"></i>حفظ التخصيص
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Modal: استيراد بيانات الدخول ===== -->
<div class="modal fade" id="importCredentialsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="student_accounts.php" enctype="multipart/form-data" class="modal-content admin-modal admin-modal-premium admin-modal-edit" data-no-form-safety="true">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="import_credentials">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد بيانات دخول الطلاب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-circle-info me-1"></i>
                    صدّر الحسابات أولًا، ثم عدّل عمود <code>username</code> واكتب كلمة المرور الجديدة في <code>new_password</code>. بقية الأعمدة مرجعية ولا يغيّرها الاستيراد.
                </div>
                <label for="studentAccountsFile" class="form-label fw-bold">ملف CSV</label>
                <input type="file" name="accounts_file" id="studentAccountsFile" class="form-control" accept=".csv,text/csv" required>
                <div class="form-text">الحد الأقصى 2 ميجابايت و2000 حساب. تُطبق العملية بالكامل أو تُلغى بالكامل عند وجود خطأ.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-file-import me-1"></i>استيراد وتطبيق</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== Modal: تعديل بيانات الدخول ===== -->
<div class="modal fade" id="credentialsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="post" action="student_accounts.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="update_credentials">
                <input type="hidden" name="user_id" id="cred_user_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-shield me-2"></i>تعديل بيانات الدخول</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center py-2 border-bottom border-light mb-3">
                        <i class="fas fa-user me-2 admin-icon-fixed"></i>
                        <span class="text-secondary me-2">الطالب:</span>
                        <strong class="text-dark" id="cred_student_name">—</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم المستخدم</label>
                        <input type="text" name="username" id="cred_username" class="form-control" autocomplete="off">
                        <div class="form-text"><i class="fas fa-info-circle me-1"></i>اتركه فارغاً للحفاظ على الحالي، أو اكتب جديداً (3 أحرف+).</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold">كلمة المرور الجديدة</label>
                        <div class="input-group">
                            <input type="text" name="new_password" id="cred_password" class="form-control" autocomplete="off" placeholder="اتركها فارغة إذا لم ترد تغييرها">
                            <button type="button" class="btn btn-light" id="generatePasswordBtn" title="توليد كلمة مرور مقترحة">
                                <i class="fas fa-dice"></i>
                            </button>
                            <button type="button" class="btn btn-light" id="copyPasswordBtn" title="نسخ">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        <div class="form-text"><i class="fas fa-info-circle me-1"></i>اتركها فارغة للحفاظ على الحالية، أو أدخل جديدة (4 أحرف+).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== Modal: تفعيل/تعطيل ===== -->
<div class="modal fade" id="toggleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleModalContent">
            <form method="post" action="student_accounts.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" id="toggle_user_id">
                <div class="modal-header" id="toggleModalHeader">
                    <h5 class="modal-title"><i class="fas fa-power-off me-2"></i><span id="toggle_title">تأكيد العملية</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-power-off admin-modal-icon-lg" id="toggle_icon"></i>
                    </div>
                    <p class="text-center">هل أنت متأكد من <strong id="toggle_action_label">هذه العملية</strong> حساب الطالب
                        <span class="fw-bold text-primary" id="toggle_student_name">—</span>؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="toggle_consequence">سيؤثر هذا الإجراء على قدرة الطالب على تسجيل الدخول للنظام.</span>
                    </div>
                    <div class="mb-3" id="individualDisableReasonWrap">
                        <label for="individualDisableReason" class="form-label fw-bold">سبب التعطيل (اختياري)</label>
                        <textarea class="form-control" name="disable_reason" id="individualDisableReason" rows="3" maxlength="500"></textarea>
                        <div class="form-text">إذا تركته فارغاً تظهر الرسالة العامة. إذا كتبته فسيظهر للطالب هذا النص فقط.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn" id="toggle_submit_btn">
                        <i class="fas fa-check me-1"></i>تأكيد
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/js/admin_table_actions.js"></script>
<script src="../assets/js/admin-server-side-table.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
    var studentAccountsTable = null;
    if (window.AdminServerSideTable) {
        studentAccountsTable = window.AdminServerSideTable.init({
            selector: '#studentsAccountsTable', url: 'ajax_student_accounts_datatable.php', order: [[3, 'asc']],
            dtOptions: {
                columnDefs: [
                    { targets: [0, 1, 12], orderable: false }
                ]
            },
            language: { processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل الحسابات…', emptyTable: 'لا توجد حسابات مطابقة للفلاتر.' },
            requestData: function () {
                return {
                    tab: <?php echo json_encode($activeTab); ?>,
                    stage_id: Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(function (cb) { return cb.value; }),
                    grade_id: Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(function (cb) { return cb.value; }),
                    class_id: Array.from(document.querySelectorAll('.class-checkbox:checked')).map(function (cb) { return cb.value; }),
                    status: Array.from(document.querySelectorAll('.status-checkbox:checked')).map(function (cb) { return cb.value; }),
                    configured: Array.from(document.querySelectorAll('.configured-checkbox:checked')).map(function (cb) { return cb.value; }),
                    account_type: Array.from(document.querySelectorAll('.account-type-checkbox:checked')).map(function (cb) { return cb.value; }),
                    student_id: <?php echo $studentIdFilter; ?>
                };
            }
        });
    }

    if (typeof initializeTableColumnSettings === 'function') {
        initializeTableColumnSettings('studentsAccountsTable', {
            col_student_code: 2,
            col_student_name: 3,
            col_stage_name: 4,
            col_grade_name: 5,
            col_class_name: 6,
            col_username: 7,
            col_password: 8,
            col_account_type: 9,
            col_configured: 10,
            col_status: 11
        }, 'student_accounts_table_columns');
    }
    function copyTextToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                resolve();
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(textarea);
            }
        });
    }

    var revealTimers = {};
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.reveal-password');
        if (!button) return;
        event.preventDefault();
        var userId = button.getAttribute('data-user-id');
        var container = button.closest('.glass-credential-chip');
        var dots = container ? container.querySelector('.pwd-dots') : null;
        var text = container ? container.querySelector('.pwd-text') : null;
        var icon = button.querySelector('i');

        if (dots && text && !text.classList.contains('d-none')) {
            text.classList.add('d-none');
            dots.classList.remove('d-none');
            if (icon) icon.className = 'fas fa-eye';
            if (revealTimers[userId]) {
                clearTimeout(revealTimers[userId]);
                delete revealTimers[userId];
            }
            return;
        }

        var original = button.innerHTML;
        button.disabled = true;
        if (icon) icon.className = 'fas fa-spinner fa-spin';

        fetch('ajax/get_password.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ csrf_token: csrfToken, user_id: userId, account_type: 'user' })
        }).then(function (response) { return response.json(); }).then(function (data) {
            if (!data.success || !data.password) throw new Error(data.message || 'تعذر كشف كلمة المرور');
            button.disabled = false;

            if (dots && text) {
                text.textContent = data.password;
                text.classList.remove('d-none');
                dots.classList.add('d-none');
                if (icon) icon.className = 'fas fa-eye-slash text-primary';

                if (revealTimers[userId]) clearTimeout(revealTimers[userId]);
                revealTimers[userId] = setTimeout(function () {
                    text.classList.add('d-none');
                    dots.classList.remove('d-none');
                    if (icon) icon.className = 'fas fa-eye';
                    delete revealTimers[userId];
                }, (Number(data.hide_after_seconds) || 10) * 1000);
            } else {
                button.textContent = data.password;
                button.classList.remove('btn-light');
                button.classList.add('btn-warning', 'text-dark', 'fw-bold');
                setTimeout(function () {
                    button.innerHTML = original;
                    button.classList.remove('btn-warning', 'text-dark', 'fw-bold');
                    button.classList.add('btn-light');
                    button.disabled = false;
                }, (Number(data.hide_after_seconds) || 10) * 1000);
            }
        }).catch(function () {
            button.disabled = false;
            if (icon) icon.className = 'fas fa-eye';
        });
    });

    document.addEventListener('click', function (event) {
        var copyBtn = event.target.closest('.copy-password');
        if (!copyBtn) return;
        event.preventDefault();
        var userId = copyBtn.getAttribute('data-user-id');
        var container = copyBtn.closest('.glass-credential-chip') || copyBtn.closest('.btn-group') || copyBtn.parentElement;
        var textEl = container ? container.querySelector('.pwd-text') : null;
        var originalIcon = copyBtn.innerHTML;

        var showSuccess = function () {
            copyBtn.innerHTML = '<i class="fas fa-check text-success"></i>';
            copyBtn.disabled = false;
            setTimeout(function () { copyBtn.innerHTML = originalIcon; }, 1500);
        };

        if (textEl && !textEl.classList.contains('d-none') && textEl.textContent.trim()) {
            copyTextToClipboard(textEl.textContent.trim()).then(showSuccess).catch(function () {});
            return;
        }

        copyBtn.disabled = true;
        copyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        fetch('ajax/get_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ csrf_token: csrfToken, user_id: userId, account_type: 'user' })
        }).then(function (response) { return response.json(); }).then(function (data) {
            if (!data.success || !data.password) throw new Error(data.message || 'تعذر كشف كلمة المرور');
            return copyTextToClipboard(data.password);
        }).then(function () {
            showSuccess();
        }).catch(function () {
            copyBtn.innerHTML = originalIcon;
            copyBtn.disabled = false;
        });
    });

    document.addEventListener('click', function (event) {
        var copyBtn = event.target.closest('.copy-username-btn, .copy-username');
        if (!copyBtn) return;
        event.preventDefault();
        var prevEl = copyBtn.previousElementSibling;
        var username = copyBtn.getAttribute('data-username') || (prevEl ? (prevEl.value || prevEl.textContent).trim() : '');
        if (!username || username === '—') return;
        var origIcon = copyBtn.innerHTML;
        copyTextToClipboard(username).then(function () {
            copyBtn.innerHTML = '<i class="fas fa-check text-success"></i>';
            setTimeout(function () { copyBtn.innerHTML = origIcon; }, 2000);
        }).catch(function () {});
    });
    var servicesModalEl = document.getElementById('servicesModal');
    var servicesModal = servicesModalEl ? new bootstrap.Modal(servicesModalEl) : null;
    var servicesOptions = document.getElementById('servicesOptions');
    var servicesLoading = document.getElementById('servicesLoading');
    var servicesStudentName = document.getElementById('servicesStudentName');
    var servicesModeAlert = document.getElementById('servicesModeAlert');
    var servicesModeText = document.getElementById('servicesModeText');
    var servicesStageAlert = document.getElementById('servicesStageAlert');
    var servicesStageText = document.getElementById('servicesStageText');
    var saveServicesBtn = document.getElementById('saveServicesBtn');
    var resetServicesBtn = document.getElementById('resetServicesBtn');
    var servicesAlertContainer = document.getElementById('servicesAlertContainer');
    var studentServices = {
        rewards: 'نظام المكافآت',
        reports: 'التقارير الشهرية',
        materials: 'المواد التعليمية',
        ebooks: 'الكتب الإلكترونية',
        results: 'النتائج',
        timetable: 'الجدول الدراسي'
    };
    var serviceIcons = {
        rewards: 'fas fa-star',
        reports: 'fas fa-file-alt',
        materials: 'fas fa-book',
        ebooks: 'fas fa-tablet-alt',
        results: 'fas fa-chart-bar',
        timetable: 'fas fa-calendar-alt'
    };

    function showServicesAlert(type, message) {
        if (!servicesAlertContainer) return;
        servicesAlertContainer.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">'
            + '<i class="fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + ' me-2"></i>'
            + message
            + '<button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>';
    }

    function setServicesBusy(isBusy) {
        if (saveServicesBtn) saveServicesBtn.disabled = isBusy;
        if (resetServicesBtn) resetServicesBtn.disabled = isBusy;
    }

    function updateServicesButtonState(userId, hasOverride) {
        var btn = document.querySelector('.customize-services[data-id="' + userId + '"][data-role="student"]');
        if (!btn) return;
        btn.classList.toggle('is-customized', hasOverride);
        btn.setAttribute('title', hasOverride ? 'تخصيص الخدمات (مُخصّص)' : 'تخصيص الخدمات');
        var existing = bootstrap.Tooltip.getInstance(btn);
        if (existing) existing.dispose();
        new bootstrap.Tooltip(btn);
    }

    function renderServicesOptions(selectedServices) {
        servicesOptions.innerHTML = '';
        Object.keys(studentServices).forEach(function (key) {
            var checked = selectedServices.indexOf(key) !== -1 ? 'checked' : '';
            var item = document.createElement('div');
            item.className = 'col-md-6';
            item.innerHTML = '<label class="border rounded px-3 py-2 d-flex align-items-center gap-2 w-100">'
                + '<input class="form-check-input mt-0 service-checkbox" type="checkbox" value="' + key + '" ' + checked + '>'
                + '<i class="' + (serviceIcons[key] || 'fas fa-cog') + ' text-primary admin-service-icon"></i>'
                + '<span class="fw-semibold">' + studentServices[key] + '</span>'
                + '</label>';
            servicesOptions.appendChild(item);
        });
    }

    function loadServicesConfig(userId, userName) {
        if (!servicesModal || !servicesOptions || !servicesLoading) return;
        servicesModalEl.dataset.userId = String(userId);
        servicesStudentName.textContent = userName || '—';
        servicesOptions.classList.add('d-none');
        servicesLoading.classList.remove('d-none');
        servicesLoading.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل البيانات...';
        servicesModeAlert.className = 'alert alert-info py-2 small mb-3';
        servicesModeText.textContent = 'جاري تحميل الإعدادات الحالية...';
        servicesStageAlert.className = 'alert alert-light border py-2 small mb-3';
        servicesStageText.textContent = 'جاري تحميل خدمات المرحلة...';
        setServicesBusy(true);
        servicesModal.show();

        fetch('../includes/ajax_handlers.php?action=get_user_services&user_id=' + encodeURIComponent(userId) + '&role=student')
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.message || 'تعذر تحميل الخدمات');
                }
                var stageServices = Array.isArray(data.stage_services) ? data.stage_services : [];
                var selectedServices = Array.isArray(data.user_services) ? data.user_services : stageServices;
                servicesModalEl.dataset.hasOverride = data.user_services !== null ? '1' : '0';
                servicesModeAlert.className = 'alert ' + (data.user_services !== null ? 'alert-warning' : 'alert-info') + ' py-2 small mb-3';
                servicesModeText.textContent = data.user_services !== null ? 'تخصيص فردي مُفعّل لهذا الطالب.' : 'يستخدم إعدادات المرحلة الافتراضية.';
                if (stageServices.length > 0) {
                    servicesStageAlert.className = 'alert alert-light border py-2 small mb-3';
                    servicesStageText.textContent = 'خدمات المرحلة الحالية: ' + stageServices.map(function (key) {
                        return studentServices[key] || key;
                    }).join('، ');
                } else {
                    servicesStageAlert.className = 'alert alert-warning py-2 small mb-3';
                    servicesStageText.textContent = 'لا توجد خدمات محددة للمرحلة الحالية.';
                }
                renderServicesOptions(selectedServices);
                servicesLoading.classList.add('d-none');
                servicesOptions.classList.remove('d-none');
                setServicesBusy(false);
            })
            .catch(function (error) {
                servicesLoading.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-2"></i>' + error.message + '</span>';
                servicesOptions.classList.add('d-none');
                setServicesBusy(true);
            });
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.customize-services');
        if (!btn) return;
        event.preventDefault();
        loadServicesConfig(btn.getAttribute('data-id'), btn.getAttribute('data-name'));
    });

    if (saveServicesBtn) {
        saveServicesBtn.addEventListener('click', function () {
            var userId = servicesModalEl.dataset.userId || '';
            if (!userId) return;
            var selectedServices = Array.from(document.querySelectorAll('#servicesOptions .service-checkbox:checked')).map(function (checkbox) {
                return checkbox.value;
            });
            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'save_user_services');
            formData.append('user_id', userId);
            formData.append('role', 'student');
            selectedServices.forEach(function (serviceKey) {
                formData.append('services[]', serviceKey);
            });
            setServicesBusy(true);
            fetch('../includes/ajax_handlers.php', { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.success) {
                        throw new Error(data.message || 'تعذر حفظ التخصيص');
                    }
                    updateServicesButtonState(userId, true);
                    servicesModal.hide();
                    showServicesAlert('success', data.message);
                    setServicesBusy(false);
                })
                .catch(function (error) {
                    showServicesAlert('danger', error.message);
                    setServicesBusy(false);
                });
        });
    }

    if (resetServicesBtn) {
        resetServicesBtn.addEventListener('click', function () {
            var userId = servicesModalEl.dataset.userId || '';
            if (!userId) return;
            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'reset_user_services');
            formData.append('user_id', userId);
            formData.append('role', 'student');
            setServicesBusy(true);
            fetch('../includes/ajax_handlers.php', { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.success) {
                        throw new Error(data.message || 'تعذر إعادة التعيين');
                    }
                    updateServicesButtonState(userId, false);
                    servicesModal.hide();
                    showServicesAlert('success', data.message);
                    setServicesBusy(false);
                })
                .catch(function (error) {
                    showServicesAlert('danger', error.message);
                    setServicesBusy(false);
                });
        });
    }

    document.querySelectorAll('.customize-services').forEach(function (btn) {
        var userId = btn.getAttribute('data-id');
        fetch('../includes/ajax_handlers.php?action=get_user_services&user_id=' + encodeURIComponent(userId) + '&role=student')
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success && data.user_services !== null) {
                    updateServicesButtonState(userId, true);
                }
            })
            .catch(function () {});
    });

    function updateDropdownLabels() {
        var filterDefs = [
            { cb: '.stage-checkbox', label: 'selectedStagesLabel', btn: 'stageDropdown' },
            { cb: '.grade-checkbox', label: 'selectedGradesLabel', btn: 'gradeDropdown', item: '.grade-item' },
            { cb: '.class-checkbox', label: 'selectedClassesLabel', btn: 'classDropdown', item: '.class-item' },
            { cb: '.status-checkbox', label: 'selectedStatusesLabel', btn: 'statusDropdown' },
            { cb: '.configured-checkbox', label: 'selectedConfiguredLabel', btn: 'configuredDropdown' },
            { cb: '.account-type-checkbox', label: 'selectedAccountTypesLabel', btn: 'accountTypeDropdown' }
        ];

        filterDefs.forEach(function (def) {
            var checked = document.querySelectorAll(def.cb + ':checked');
            var labelEl = document.getElementById(def.label);
            var btnEl = document.getElementById(def.btn);
            var totalCount = def.item
                ? document.querySelectorAll(def.item + ':not([style*="display: none"])').length || document.querySelectorAll(def.cb).length
                : document.querySelectorAll(def.cb).length;

            if (labelEl) {
                if (checked.length === 0 || (totalCount > 0 && checked.length === totalCount)) {
                    labelEl.textContent = 'الكل';
                } else if (checked.length <= 2) {
                    var names = [];
                    checked.forEach(function (cb) {
                        var lbl = cb.nextElementSibling;
                        if (lbl) names.push(lbl.textContent.trim());
                    });
                    labelEl.textContent = names.join('، ');
                } else {
                    labelEl.textContent = checked.length + ' محددة';
                }
            }
            if (btnEl) {
                btnEl.classList.toggle('active-filter', checked.length > 0 && checked.length < totalCount);
            }
        });
    }

    function applyCascadingFilters() {
        var checkedStages = Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(function (cb) {
            return cb.value;
        });

        var gradeItems = document.querySelectorAll('.grade-item');
        gradeItems.forEach(function (item) {
            var stageId = item.getAttribute('data-stage');
            if (checkedStages.length === 0 || checkedStages.indexOf(stageId) !== -1) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                var cb = item.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = false;
            }
        });

        var checkedGrades = Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(function (cb) {
            return cb.value;
        });

        var classItems = document.querySelectorAll('.class-item');
        classItems.forEach(function (item) {
            var gradeId = item.getAttribute('data-grade');
            if (checkedGrades.length === 0 || checkedGrades.indexOf(gradeId) !== -1) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                var cb = item.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = false;
            }
        });

        updateDropdownLabels();
    }

    applyCascadingFilters();

    document.querySelectorAll('.stage-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            applyCascadingFilters();
            if (studentAccountsTable) studentAccountsTable.ajax.reload();
        });
    });

    document.querySelectorAll('.grade-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            applyCascadingFilters();
            if (studentAccountsTable) studentAccountsTable.ajax.reload();
        });
    });

    ['.class-checkbox', '.status-checkbox', '.configured-checkbox', '.account-type-checkbox'].forEach(function (selector) {
        document.querySelectorAll(selector).forEach(function (cb) {
            cb.addEventListener('change', function () {
                updateDropdownLabels();
                if (studentAccountsTable) studentAccountsTable.ajax.reload();
            });
        });
    });

    var filterForm = document.getElementById('studentAccountFilters');
    if (filterForm) {
        filterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (studentAccountsTable) studentAccountsTable.ajax.reload();
        });
    }

    document.querySelectorAll('.pwd-field').forEach(function (field) {
        var uid = field.getAttribute('data-user-id');
        field.id = 'pwd-field-' + uid;
    });
    document.querySelectorAll('.pwd-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = this.getAttribute('data-target-input');
            var input = document.getElementById(targetId);
            if (!input) return;
            var icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
            } else {
                input.type = 'password';
                if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
            }
        });
    });
    document.querySelectorAll('.pwd-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = this.getAttribute('data-target-input');
            var input = document.getElementById(targetId);
            if (!input || !input.value || input.value === '—') return;
            var origIcon = this.innerHTML;
            var self = this;
            copyTextToClipboard(input.value).then(function () {
                self.innerHTML = '<i class="fas fa-check text-success"></i>';
                setTimeout(function () { self.innerHTML = origIcon; }, 2000);
            }).catch(function () {});
        });
    });

    var genBtn = document.getElementById('generatePasswordBtn');
    var copyBtn = document.getElementById('copyPasswordBtn');
    var pwdInput = document.getElementById('cred_password');
    if (genBtn && pwdInput) {
        genBtn.addEventListener('click', function () {
            var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
            var pwd = '';
            for (var i = 0; i < 8; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
            pwdInput.value = pwd;
        });
    }
    if (copyBtn && pwdInput) {
        copyBtn.addEventListener('click', function () {
            if (!pwdInput.value) return;
            pwdInput.select();
            try { document.execCommand('copy'); } catch (e) {}
        });
    }
});

function openCredentialsModal(userId, currentUsername, studentName) {
    document.getElementById('cred_user_id').value = userId;
    document.getElementById('cred_username').value = currentUsername || '';
    document.getElementById('cred_password').value = '';
    document.getElementById('cred_student_name').textContent = studentName || '—';
    new bootstrap.Modal(document.getElementById('credentialsModal')).show();
}

function openToggleModal(userId, studentName, newStatus) {
    document.getElementById('toggle_user_id').value = userId;
    document.getElementById('toggle_student_name').textContent = studentName || '—';
    var modalContent = document.getElementById('toggleModalContent');
    var icon = document.getElementById('toggle_icon');
    var label = document.getElementById('toggle_action_label');
    var submitBtn = document.getElementById('toggle_submit_btn');
    var consequence = document.getElementById('toggle_consequence');
    var title = document.getElementById('toggle_title');

    var reasonWrap = document.getElementById('individualDisableReasonWrap');
    var reasonInput = document.getElementById('individualDisableReason');
    submitBtn.classList.remove('btn-success','btn-warning');
    icon.classList.remove('text-success','text-danger');

    if (newStatus === 'active') {
        title.textContent = 'تفعيل الحساب';
        modalContent.classList.remove('admin-modal-warning');
        modalContent.classList.add('admin-modal-create');
        icon.classList.add('text-success');
        label.textContent = 'تفعيل';
        submitBtn.classList.add('btn-success');
        reasonWrap.classList.add('d-none');
        reasonInput.disabled = true;
        consequence.textContent = 'سيتمكن الطالب من تسجيل الدخول للنظام مرة أخرى.';
    } else {
        title.textContent = 'تعطيل الحساب';
        modalContent.classList.remove('admin-modal-create');
        modalContent.classList.add('admin-modal-warning');
        icon.classList.add('text-danger');
        label.textContent = 'تعطيل';
        submitBtn.classList.add('btn-warning');
        reasonWrap.classList.remove('d-none');
        reasonInput.disabled = false;
        reasonInput.value = '';
        consequence.textContent = 'لن يتمكن الطالب من تسجيل الدخول للنظام حتى إعادة التفعيل.';
    }
    new bootstrap.Modal(document.getElementById('toggleModal')).show();
}

function openTestAccountModal(userId, studentName, currentlyTest) {
    var makingTest = !currentlyTest;
    document.getElementById('test_account_user_id').value = userId;
    document.getElementById('test_account_value').value = makingTest ? '1' : '0';
    document.getElementById('test_account_student_name').textContent = studentName || '—';
    document.getElementById('test_account_title').textContent = makingTest ? 'تحويل إلى حساب تجريبي' : 'تحويل إلى حساب رسمي';
    document.getElementById('test_account_consequence').innerHTML = makingTest
        ? '<i class="fas fa-info-circle me-2"></i>سيُستبعد هذا الحساب من الترحيل الرسمي للعام الجديد، دون تعطيل تسجيل الدخول أو حذف أي بيانات.'
        : '<i class="fas fa-exclamation-triangle me-2"></i>سيعود الحساب إلى الترحيل الرسمي؛ تأكد من استكمال المرحلة والصف قبل تهيئة العام الجديد.';
    var submit = document.getElementById('test_account_submit');
    submit.classList.toggle('btn-warning', makingTest);
    submit.classList.toggle('btn-primary', !makingTest);
    submit.querySelector('span').textContent = makingTest ? 'تحويل إلى تجريبي' : 'تحويل إلى رسمي';
    new bootstrap.Modal(document.getElementById('testAccountModal')).show();
}
</script>

<!-- ===== Modal: الإجراءات الجماعية لحسابات الطلاب ===== -->
<div class="modal fade" id="bulkStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkStudentModalTitle"><i class="fas fa-layer-group me-2"></i>تأكيد الإجراء الجماعي</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bulkStudentActionValue" value="">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="fas fa-users me-2"></i>عدد الحسابات المحددة للعملية: <strong id="bulkStudentTargetCount">0</strong>
                </div>
                <div id="bulkStudentActionDescription" class="mb-3 fw-semibold text-dark"></div>

                <div class="alert alert-warning small">
                    <i class="fas fa-shield-alt me-2"></i><strong>تنفيذ كامل أو لا شيء:</strong> إذا تعذر تغيير أي حساب فلن يُغيّر أي حساب في المجموعة.
                </div>
                <div class="mb-3 d-none" id="bulkDisableReasonWrap">
                    <label for="bulkDisableReason" class="form-label fw-bold">سبب التعطيل للمجموعة (اختياري)</label>
                    <textarea class="form-control" id="bulkDisableReason" rows="3" maxlength="500"></textarea>
                    <div class="form-text">إذا تركته فارغاً تظهر الرسالة العامة. إذا كتبته فسيظهر لكل طالب هذا النص فقط.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-primary" id="submitStudentBulkActionBtn">
                    <i class="fas fa-check me-1"></i>تأكيد وتنفيذ الإجراء
                </button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin_bulk_actions.js"></script>
<script>
var studentBulkHandler = null;
document.addEventListener('DOMContentLoaded', function () {
    if (window.AdminBulkActions) {
        studentBulkHandler = window.AdminBulkActions({
            tableSelector: '#studentsAccountsTable',
            barSelector: '#studentBulkActionBar',
            endpointUrl: 'ajax_bulk_student_accounts.php',
            filterFormSelector: '#studentAccountFilters',
            filterInputSelectors: ['.stage-checkbox', '.grade-checkbox', '.class-checkbox', '.status-checkbox', '.configured-checkbox', '.account-type-checkbox'],
            getFilterData: function () {
                var parseArray = function (selector) {
                    return Array.from(document.querySelectorAll(selector + ':checked')).map(function (cb) { return cb.value; });
                };
                return {
                    stage_id: parseArray('.stage-checkbox'),
                    grade_id: parseArray('.grade-checkbox'),
                    class_id: parseArray('.class-checkbox'),
                    status: parseArray('.status-checkbox'),
                    configured: parseArray('.configured-checkbox'),
                    account_type: parseArray('.account-type-checkbox'),
                    tab: <?php echo json_encode($activeTab); ?>
                };
            }
        });
    }

    var submitBtn = document.getElementById('submitStudentBulkActionBtn');
    if (submitBtn) {
        submitBtn.addEventListener('click', function () {
            if (!studentBulkHandler) return;
            var action = document.getElementById('bulkStudentActionValue').value;
            var disableReason = action === 'deactivate' ? document.getElementById('bulkDisableReason').value : '';
            var $modal = $('#bulkStudentModal');
            studentBulkHandler.executeAction(action, { on_error: 'stop', disable_reason: disableReason }, $modal, '#submitStudentBulkActionBtn');
        });
    }
});

function openBulkStudentModal(action) {
    if (!studentBulkHandler || studentBulkHandler.getSelectedCount() === 0) {
        var notice = document.getElementById('bulkSelectionRequiredNotice');
        if (notice) notice.classList.remove('d-none');
        return;
    }

    var actionLabels = {
        'activate': 'تفعيل الحسابات المحددة والسماح لها بتسجيل الدخول.',
        'deactivate': 'تعطيل الحسابات المحددة ومنعها من تسجيل الدخول.',
        'set_test': 'تحويل الحسابات المحددة إلى حسابات تجريبية واستبعادها من الترحيل الرسمي.',
        'set_official': 'تحويل الحسابات التجريبية المحددة إلى حسابات رسمية.',
        'generate_credentials': 'توليد اسم مستخدم وكلمة مرور فريدة للحسابات غير المهيأة فقط.',
        'reset_passwords': 'إعادة تعيين كلمة المرور وإنشاء كلمات مرور فريدة للحسابات المحددة.',
        'export_credentials': 'تصدير بيانات الدخول وكلمات المرور للحسابات المحددة في ملف CSV آمن.'
    };

    var actionTitles = {
        'activate': 'تفعيل الحسابات جماعياً',
        'deactivate': 'تعطيل الحسابات جماعياً',
        'set_test': 'تحويل لحسابات تجريبية',
        'set_official': 'تحويل لحسابات رسمية',
        'generate_credentials': 'توليد بيانات الدخول',
        'reset_passwords': 'إعادة تعيين كلمات المرور',
        'export_credentials': 'تصدير بيانات الدخول (CSV)'
    };

    document.getElementById('bulkStudentActionValue').value = action;
    document.getElementById('bulkStudentTargetCount').textContent = studentBulkHandler.getSelectedCount();
    document.getElementById('bulkStudentModalTitle').innerHTML = '<i class="fas fa-layer-group me-2"></i>' + (actionTitles[action] || 'تأكيد الإجراء الجماعي');
    document.getElementById('bulkStudentActionDescription').textContent = actionLabels[action] || 'هل أنت تأكد من تنفيذ هذا الإجراء الجماعي؟';
    var reasonWrap = document.getElementById('bulkDisableReasonWrap');
    var reasonInput = document.getElementById('bulkDisableReason');
    reasonWrap.classList.toggle('d-none', action !== 'deactivate');
    reasonInput.value = '';

    new bootstrap.Modal(document.getElementById('bulkStudentModal')).show();
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>
