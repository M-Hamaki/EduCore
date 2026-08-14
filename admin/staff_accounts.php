<?php
/**
 * حسابات العاملين: بيانات الدخول، دور البوابة، النطاق، الأدوار المخصصة،
 * واستيراد/تصدير بيانات الدخول للحسابات العاملة أو غير المهيأة.
 */
$page_title = "حسابات العاملين";
$custom_page_title = true;
require_once '../config/database.php';
require_once '../config/encryption.php';
require_once '../classes/utilities.php';
require_once '../classes/user.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/StaffAccountSchemaGuard.php';
require_once '../classes/AccountListDataTableQuery.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/StaffAcademicScopeService.php';
require_once '../classes/StaffRoleAssignmentService.php';
require_once '../classes/AdminRolePageCatalog.php';
require_once '../classes/StaffRoleCapabilityResolver.php';
require_once '../classes/SystemAdministratorRoleService.php';
require_once '../classes/FileUploadGuard.php';
require_once '../classes/Ajax/DataTableActionResponder.php';
require_once '../src/Modules/Accounts/AccountCredentialCsvService.php';
require_once '../vendor/autoload.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
$database = new Database();
$db = $database->getConnection();
$staffAccountSchemaGuard = new StaffAccountSchemaGuard($db);
$staffAccountSchemaGuard->assertReady();
$isSuperAdmin = (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '') === 'super_admin';
$systemAdministratorRoleService = new SystemAdministratorRoleService($db);
$staffRoleCapabilityResolver = new StaffRoleCapabilityResolver($db);
$staffRoleAssignmentService = new StaffRoleAssignmentService($db);
function normalize_staff_role_key(string $value): string {
    $value = strtolower(trim($value));
    // تحويل الحروف العربية شائعة إلى ما يقابلها باللاتيني (مساعدة المستخدم)
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?: '';
    $value = trim($value, '_');
    // منع المفاتيح الرقمية البحتة: PHP يحوّل المفتاح النصي الرقمي ("1") إلى int
    // عند استخدامه كمفتاح array، مما يكسر in_array الصارم لاحقاً.
    // إضافة prefix "role_" يضمن بقاء المفتاح نصياً صريحاً.
    if ($value !== '' && ctype_digit($value)) {
        $value = 'role_' . $value;
    }
    return $value;
}
function get_available_admin_role_pages(): array {
    $labelMap = [
        'index.php' => 'لوحة التحكم',
        'staff.php' => 'بيانات الموظفين',
        'staff_accounts.php' => 'حسابات العاملين والأدوار',
        'staff_statistics.php' => 'إحصائيات العاملين',
        'staff_financial_data.php' => 'مالية العاملين',
        'assessment_teacher_assignments.php' => 'تعيينات المعلمين',
        'students.php' => 'الطلاب المقيدين',
        'pending_operations.php' => 'العمليات المعلقة',
        'new_students.php' => 'منقول إلى المدرسة',
        'transferred_students.php' => 'منقول من المدرسة',
        'graduate_students.php' => 'الخريجين',
        'student_archive.php' => 'أرشيف الطلاب',
        'student_data_completeness.php' => 'اكتمال بيانات الطلاب',
        'class_lists.php' => 'قوائم الفصول',
        'siblings.php' => 'صلات القرابة',
        'attendance.php' => 'الحضور والغياب',
        'statements.php' => 'إفادات رسمية',
        'student_file.php' => 'ملف الطالب',
        'student_numbers_reports.php' => 'ميزانية المدرسة',
        'student_id_cards.php' => 'كروت الطلاب (ID)',
        'export_students.php' => 'تصدير بيانات الطلاب',
        'student_statistics.php' => 'إحصائيات الطلاب',
        'calculation_tools.php' => 'أدوات الحساب',
        'role_dashboard.php' => 'لوحة الترحيب بالدور',
        'student_accounts.php' => 'حسابات الطلاب',
        'subjects.php' => 'المواد الدراسية',
        'classes.php' => 'الفصول',
        'grades.php' => 'الصفوف الدراسية',
        'stages.php' => 'المراحل الدراسية',
        'fee_payments.php' => 'سداد الرسوم والمصاريف',
        'activity_logs.php' => 'سجل النشاطات',
        'school_settings.php' => 'حسابات المدرسة (SMTP)',
        'locations.php' => 'المناطق الجغرافية',
        'bus_staff.php' => 'طاقم الحافلات',
        'buses.php' => 'إدارة الحافلات',
        'student_buses.php' => 'تعيين الطلاب للحافلات',
        'bus_lists.php' => 'قوائم الحافلات',
        'bus_report.php' => 'تقارير الحركة والتنقلات',
        'transport_statistics.php' => 'إحصائيات النقل',
    ];
    $excluded = ['ajax_handlers.php'];
    $pages = [];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
        $name = basename($file);
        if (in_array($name, $excluded, true)
            || strpos($name, '_old') !== false
            || AdminRolePageCatalog::isSupportingPage($name)) {
            continue;
        }
        $pages[$name] = $labelMap[$name] ?? str_replace('_', ' ', basename($name, '.php'));
    }
    ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);
    return $pages;
}
$availableAdminPages = get_available_admin_role_pages();
$customRoleStmt = $db->query("SELECT sr.*, GROUP_CONCAT(srp.page_name ORDER BY srp.page_name SEPARATOR ',') AS pages
    FROM staff_roles sr
    LEFT JOIN staff_role_pages srp ON srp.role_key = sr.role_key
    GROUP BY sr.id
    ORDER BY sr.role_name");
$customRoles = $customRoleStmt->fetchAll(PDO::FETCH_ASSOC);
$portalRoleLabels = [
    'employee' => 'موظف',
    'teacher' => 'معلم',
    'specialist' => 'أخصائي',
    'doctor' => 'طبيب',
    'librarian' => 'أمين مكتبة',
];
$roleLabels = $portalRoleLabels;
$roleColors = [
    'employee' => 'secondary',
    'teacher' => 'primary',
    'specialist' => 'success',
    'doctor' => 'danger',
    'librarian' => 'warning text-dark',
    'admin' => 'purple',
];
$colorPalette = ['purple', 'dark', 'success', 'danger', 'warning text-dark', 'info text-dark', 'primary'];
$paletteIdx = 0;
foreach ($customRoles as $customRole) {
    if (($customRole['status'] ?? 'active') !== 'active') {
        continue;
    }
    if (in_array((string)$customRole['role_key'], ['employee', 'student', 'external_teacher', 'admin', 'super_admin', 'teacher', 'supervisor', 'specialist', 'doctor', 'librarian'], true)) {
        continue;
    }
    $portalRoleLabels[$customRole['role_key']] = $customRole['role_name'];
    $roleLabels[$customRole['role_key']] = $customRole['role_name'];
    $roleColors[$customRole['role_key']] = $colorPalette[$paletteIdx % count($colorPalette)];
    $paletteIdx++;
}
if ($isSuperAdmin) {
    $portalRoleLabels['admin'] = 'مدير نظام';
    $portalRoleLabels['super_admin'] = 'مدير النظام الأعلى';
    $roleLabels['admin'] = 'مدير نظام';
    $roleLabels['super_admin'] = 'مدير النظام الأعلى';
}
$validRoles = array_keys($portalRoleLabels);
$customizableRoleKeys = AdminRolePageCatalog::customizableRoleKeys();
$roleScopeRequirements = [];
foreach ($validRoles as $validRoleKey) {
    $roleScopeRequirements[$validRoleKey] = $staffRoleCapabilityResolver->requiresAcademicScope((string)$validRoleKey);
}
$systemRoleKeys = array_values(array_unique(array_merge(
    ['admin', 'super_admin', 'teacher', 'specialist', 'doctor', 'librarian', 'student', 'external_teacher', 'supervisor', 'employee'],
    array_keys(AdminRolePageCatalog::predefinedRoles())
)));
$storedRoleKeys = array_map('strval', array_column($customRoles, 'role_key'));
$fixedRoleDefinitions = [
    'admin' => ['مدير النظام', 'جميع صفحات الإدارة'],
    'super_admin' => ['مدير النظام الأعلى', 'جميع صفحات الإدارة والصلاحيات العليا'],
    'teacher' => ['معلم', 'بوابة المعلم وتعييناته الأكاديمية'],
    'specialist' => ['أخصائي', 'صفحات الإدارة المحددة مع نطاق أكاديمي سنوي'],
    'doctor' => ['طبيب', 'صفحة العيادة مع نطاق أكاديمي سنوي'],
    'librarian' => ['أمين مكتبة', 'صفحة المكتبة مع نطاق أكاديمي سنوي'],
    'student' => ['طالب', 'بوابة الطالب'],
    'external_teacher' => ['معلم خارجي', 'بوابة المعلم الخارجي'],
    'supervisor' => ['مشرف مستقل', 'بوابة المشرف'],
    'employee' => ['موظف', 'بوابة الخدمة الذاتية لشؤون العاملين'],
];
$fixedRoleRows = [];
foreach ($fixedRoleDefinitions as $fixedRoleKey => [$fixedRoleName, $fixedRoleDescription]) {
    if (in_array($fixedRoleKey, $storedRoleKeys, true)) {
        continue;
    }
    $fixedRoleRows[] = [
        'role_key' => $fixedRoleKey,
        'role_name' => $fixedRoleName,
        'description' => $fixedRoleDescription,
    ];
}
$rawTab = (string)($_GET['tab'] ?? 'accounts');
$activeTab = $rawTab === 'roles' ? 'roles' : 'accounts';
$legacyAccountGroup = ['academics' => 'academic', 'employees' => 'non_academic'][$rawTab] ?? '';
$accountGroupFilter = $_GET['account_group'] ?? [];
$accountGroupFilter = is_array($accountGroupFilter) ? $accountGroupFilter : explode(',', (string)$accountGroupFilter);
$accountGroupFilter = array_values(array_unique(array_intersect(array_map('trim', $accountGroupFilter), ['academic', 'non_academic'])));
if ($accountGroupFilter === [] && $legacyAccountGroup !== '') $accountGroupFilter[] = $legacyAccountGroup;
$accountGroupMode = count($accountGroupFilter) === 1 ? $accountGroupFilter[0] : '';
$academicRoles = ['teacher', 'specialist'];
foreach ($customRoles as $customRole) {
    if ((string)($customRole['base_role_key'] ?? '') === 'specialist') {
        $academicRoles[] = (string)$customRole['role_key'];
    }
}
$academicRoles = array_values(array_unique($academicRoles));
$tabRoleLabels = $roleLabels;
if (($_GET['action'] ?? '') === 'export_credentials') {
    if ($activeTab === 'roles') {
        http_response_code(400);
        exit('لا يمكن تصدير بيانات دخول من تبويب الأدوار.');
    }
    $transferService = new \EduCore\Modules\Accounts\AccountCredentialCsvService(
        $db,
        static fn(string $password, int $targetUserId): string => encryptPasswordForUser($password, $targetUserId),
        static fn(): bool => true,
        null,
        static fn(string $stored, int $targetUserId): string => decryptPasswordForUser($stored, $targetUserId)
    );
    $manageableExportRoles = array_values(array_unique(array_merge($validRoles, ['employee'])));
$portalExportRoles = $manageableExportRoles;
    $visibleRoles = match ($accountGroupMode) {
        'academic' => array_values(array_intersect($portalExportRoles, $academicRoles)),
        'non_academic' => array_values(array_diff($portalExportRoles, $academicRoles)),
        default => $portalExportRoles,
    };
    $dataset = $transferService->exportStaff($manageableExportRoles, $visibleRoles, $accountGroupMode !== 'academic');
    ActivityLog::setDb($db);
    $exportLogged = ActivityLog::log('export', 'staff_account', null, 'تصدير بيانات دخول العاملين', [
        'tab' => $activeTab,
        'account_group' => $accountGroupMode !== '' ? $accountGroupMode : 'all',
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
    $filename = 'staff_accounts_with_passwords_' . ($accountGroupMode !== '' ? $accountGroupMode : 'all') . '_' . date('Y-m-d') . '.csv';
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
// ====== معالجة POST (PRG) ======
$success_message = $_SESSION['success_message'] ?? null;
$error_message   = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
$isDataTableAjaxRequest = DataTableActionResponder::accepts($_SERVER, $_POST);
$staffAccountResponder = new DataTableActionResponder($isDataTableAjaxRequest, static fn(): array => (new AccountListDataTableQuery($db))->staffSummary($validRoles, ['tab' => $activeTab, 'account_group' => $accountGroupFilter]));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);

    if (!$csrfOk) {
        $csrfMessage = "رمز التحقق (CSRF) غير صالح، يرجى المحاولة مرة أخرى.";
        $staffAccountResponder->reject($csrfMessage, 419, $_SERVER['PHP_SELF'] . '?tab=' . urlencode($activeTab));
    }
    $action = $_POST['action'] ?? '';
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    if ($action === 'save_role') {
        try {
            if (!$isSuperAdmin) {
                throw new RuntimeException('إنشاء الأدوار وتعديل صلاحياتها متاح لمدير النظام الأعلى فقط.');
            }
            $roleId = (int)($_POST['role_id'] ?? 0);
            $roleName = trim((string)($_POST['role_name'] ?? ''));
            $roleKeyInput = (string)($_POST['role_key'] ?? '');
            $roleKey = normalize_staff_role_key($roleKeyInput);
            $cloneSourceRoleKey = trim((string)($_POST['clone_source_role_key'] ?? ''));
            $roleFamily = '';
            $submittedPages = $_POST['pages'] ?? [];
            if (!is_array($submittedPages)) {
                $submittedPages = [];
            }
            $selectedPages = array_values(array_intersect(array_map('basename', $submittedPages), array_keys($availableAdminPages)));

            if ($roleName === '') {
                throw new RuntimeException('اكتب اسم الدور.');
            }
            if ($roleId > 0) {
                $oldRoleStmt = $db->prepare('SELECT role_key, role_name, base_role_key FROM staff_roles WHERE id = ? LIMIT 1');
                $oldRoleStmt->execute([$roleId]);
                $existingRole = $oldRoleStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingRole) {
                    throw new RuntimeException('الدور المطلوب تعديله غير موجود.');
                }
                $roleKey = (string)$existingRole['role_key'];
                $roleFamily = trim((string)($existingRole['base_role_key'] ?? ''));
                if ($roleFamily === '' && AdminRolePageCatalog::isCustomizableRole($roleKey)) {
                    $roleFamily = $roleKey;
                    $roleName = (string)$existingRole['role_name'];
                }
            } else {
                if ($cloneSourceRoleKey !== '') {
                    if (!AdminRolePageCatalog::isCustomizableRole($cloneSourceRoleKey)) {
                        throw new RuntimeException('الدور المصدر غير قابل للاستنساخ.');
                    }
                    $roleFamily = $cloneSourceRoleKey;
                }
                // للأدوار الجديدة: إن كان المفتاح فارغاً بعد التطبيع، ولّد مفتاحاً تلقائياً
                // من اسم الدور (هكذا يمكن للمستخدم كتابة الاسم بالعربي وترك المفتاح فارغاً).
                if ($roleKey === '') {
                    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $roleName));
                    if ($slug !== '' && !ctype_digit($slug)) {
                        $candidate = 'role_' . substr($slug, 0, 12);
                    } else {
                        $candidate = 'role_' . bin2hex(random_bytes(3));
                    }
                    // ضمان التفرّد
                    $roleKey = $candidate;
                    $suffix = 2;
                    $dupStmt = $db->prepare('SELECT COUNT(*) FROM staff_roles WHERE role_key = ?');
                    while (true) {
                        $dupStmt->execute([$roleKey]);
                        if ((int)$dupStmt->fetchColumn() === 0) {
                            break;
                        }
                        $roleKey = $candidate . '_' . $suffix++;
                    }
                }
            }
            $editingCustomizableSystemRole = $roleId > 0 && AdminRolePageCatalog::isCustomizableRole($roleKey);
            if ($roleKey === ''
                || ($roleId <= 0 && in_array($roleKey, $systemRoleKeys, true))
                || ($roleId > 0 && in_array($roleKey, $systemRoleKeys, true) && !$editingCustomizableSystemRole)) {
                throw new RuntimeException('اكتب مفتاح دور صالحاً بالإنجليزية، ولا تستخدم أدوار النظام.');
            }
            if ($roleFamily !== '') {
                $selectedPages = array_values(array_intersect(
                    $selectedPages,
                    AdminRolePageCatalog::customizablePages($roleFamily)
                ));
                $selectedPages = array_values(array_unique(array_merge(
                    $selectedPages,
                    AdminRolePageCatalog::mandatoryPages($roleFamily)
                )));
            }

            ActivityLog::setDb($db);
            $db->beginTransaction();
            $systemAdministratorRoleService->assertActorCanManage(
                (int)($_SESSION['user_id'] ?? 0),
                (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')
            );

            if ($roleId > 0) {
                $db->prepare("UPDATE staff_roles SET role_name = ?, portal_type = 'admin_like', status = 'active' WHERE id = ?")->execute([$roleName, $roleId]);
                $logAction = 'logUpdate';
            } else {
                $dupRoleStmt = $db->prepare('SELECT COUNT(*) FROM staff_roles WHERE role_key = ?');
                $dupRoleStmt->execute([$roleKey]);
                if ((int)$dupRoleStmt->fetchColumn() > 0) {
                    throw new RuntimeException('مفتاح الدور مستخدم بالفعل.');
                }
                $db->prepare("INSERT INTO staff_roles (role_key, role_name, base_role_key, portal_type, status) VALUES (?, ?, ?, 'admin_like', 'active')")
                    ->execute([$roleKey, $roleName, $roleFamily !== '' ? $roleFamily : null]);
                $roleId = (int)$db->lastInsertId();
                $logAction = 'logCreate';
            }

            $db->prepare('DELETE FROM staff_role_pages WHERE role_key = ?')->execute([$roleKey]);
            if ($selectedPages) {
                $pageStmt = $db->prepare('INSERT INTO staff_role_pages (role_key, page_name) VALUES (?, ?)');
                foreach ($selectedPages as $pageName) {
                    $pageStmt->execute([$roleKey, $pageName]);
                }
            }

            if ($logAction === 'logCreate') {
                $logged = ActivityLog::logCreate('staff_role', $roleId, $roleName, ['role_key' => $roleKey, 'base_role_key' => $roleFamily ?: null, 'pages' => $selectedPages]);
                $_SESSION['success_message'] = 'تم إضافة الدور بنجاح.';
            } else {
                $logged = ActivityLog::logUpdate('staff_role', $roleId, $roleName, ['role_key' => $roleKey, 'base_role_key' => $roleFamily ?: null, 'pages' => $selectedPages]);
                $_SESSION['success_message'] = 'تم تحديث الدور بنجاح.';
            }
            if (!$logged) {
                throw new RuntimeException('تعذر تسجيل تغيير الدور. لم يتم حفظ أي تغيير.');
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=roles");
        exit();
    }

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
                ),
                static function (array $target, string $accountType) use ($systemAdministratorRoleService, $staffRoleAssignmentService): void {
                    if ($accountType !== 'staff') {
                        return;
                    }
                    if ((int)$target['id'] === (int)($_SESSION['user_id'] ?? 0)) {
                        throw new RuntimeException('لا يمكنك تعديل بيانات دخول حسابك الحالي عبر الاستيراد.');
                    }
                    $targetRoles = $staffRoleAssignmentService->roleKeysForUser((int)$target['id'], true);
                    if (array_intersect(['admin', 'super_admin'], $targetRoles) !== []) {
                        $systemAdministratorRoleService->assertActorCanManage(
                            (int)($_SESSION['user_id'] ?? 0),
                            (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')
                        );
                    }
                }
            );
            $result = $transferService->import($_FILES['accounts_file'] ?? [], 'staff', ['manageable_roles' => $validRoles]);
            $_SESSION['success_message'] = 'تم استيراد بيانات الدخول وتحديث ' . (int)$result['updated'] . ' حساب عامل بنجاح.'
                . ((int)$result['skipped'] > 0 ? ' تم تجاوز ' . (int)$result['skipped'] . ' صف دون تغيير.' : '');
        } catch (Throwable $e) {
            error_log('staff account credentials import failed: ' . $e->getMessage());
            $_SESSION['error_message'] = ($e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !($e instanceof PDOException)))
                ? $e->getMessage()
                : 'تعذر استيراد بيانات الدخول بسبب خطأ داخلي.';
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=' . urlencode($activeTab));
        exit();
    }

    if ($action === 'update_login_credentials') {
        try {
            ActivityLog::setDb($db);
            $db->beginTransaction();
            $manageableRoles = array_values(array_unique(array_merge($validRoles, ['employee'])));
            $rolePlaceholders = implode(',', array_fill(0, count($manageableRoles), '?'));
            $targetStmt = $db->prepare("SELECT u.id, u.name, u.username, u.password, u.password_hash, u.role
                FROM users u
                INNER JOIN staff_profiles sp ON sp.user_id = u.id
                WHERE u.id = ? AND (u.role IN ({$rolePlaceholders}) OR u.role IS NULL)
                LIMIT 1 FOR UPDATE");
            $targetStmt->execute(array_merge([$userId], $manageableRoles));
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                throw new RuntimeException('العامل غير موجود أو غير قابل للإدارة من هذه الصفحة.');
            }
            if ((int)$target['id'] === (int)($_SESSION['user_id'] ?? 0)) {
                throw new RuntimeException('لا يمكنك تعديل بيانات الدخول لحسابك الحالي من هنا.');
            }
            if ($target['role'] === null) {
                throw new RuntimeException('عيّن دورًا نشطًا للعامل أولًا من زر الأدوار والصلاحيات.');
            }
            $targetRoleKeys = $staffRoleAssignmentService->roleKeysForUser($userId, true);
            if (array_intersect(['admin', 'super_admin'], $targetRoleKeys) !== []) {
                $systemAdministratorRoleService->assertActorCanManage(
                    (int)($_SESSION['user_id'] ?? 0),
                    (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')
                );
            }

            $newUsername = trim((string)($_POST['username'] ?? ''));
            $newPassword = trim((string)($_POST['new_password'] ?? ''));
            if (mb_strlen($newUsername) < 3) {
                throw new RuntimeException('اسم المستخدم مطلوب ويجب ألا يقل عن 3 أحرف.');
            }
            $duplicateStmt = $db->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
            $duplicateStmt->execute([$newUsername, $userId]);
            if ($duplicateStmt->fetchColumn()) {
                throw new RuntimeException('اسم المستخدم مأخوذ بالفعل لحساب آخر، اختر اسماً مختلفاً.');
            }
            if ($newPassword === '' && empty($target['password']) && empty($target['password_hash'])) {
                throw new RuntimeException('أدخل كلمة مرور للحساب قبل تفعيل بوابته.');
            }
            if ($newPassword !== '' && mb_strlen($newPassword) < 4) {
                throw new RuntimeException('كلمة المرور يجب ألا تقل عن 4 أحرف.');
            }

            $updates = ['username = ?'];
            $params = [$newUsername];
            $details = ['username' => ['old' => (string)($target['username'] ?? ''), 'new' => $newUsername]];
            if ($newPassword !== '') {
                $updates[] = 'password = ?';
                $params[] = encryptPasswordForUser($newPassword, $userId);
                $updates[] = 'password_hash = ?';
                $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
                $details['password_reset'] = true;
            }
            $params[] = $userId;
            $db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
            if (!ActivityLog::logUpdate('staff_account', $userId, (string)$target['name'], $details)) {
                throw new RuntimeException('تعذر تسجيل تعديل حساب العامل؛ لم يتم حفظ أي تغيير.');
            }
            $db->commit();
            $_SESSION['success_message'] = 'تم تحديث بيانات دخول العامل «' . $target['name'] . '» بنجاح.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error_message'] = $e->getMessage();
        }
        $staffAccountResponder->finish($_SERVER['PHP_SELF'] . '?tab=' . urlencode($activeTab), (string)$userId);
    }

    if ($action === 'update_role_access') {
        try {
            ActivityLog::setDb($db);
            $db->beginTransaction();
            $manageableRoles = array_values(array_unique(array_merge($validRoles, ['employee'])));
            $rolePlaceholders = implode(',', array_fill(0, count($manageableRoles), '?'));
            $targetStmt = $db->prepare("SELECT u.id, u.name, u.role, u.status, u.is_supervisor
                FROM users u
                INNER JOIN staff_profiles sp ON sp.user_id = u.id
                WHERE u.id = ? AND (u.role IN ({$rolePlaceholders}) OR u.role IS NULL)
                LIMIT 1 FOR UPDATE");
            $targetStmt->execute(array_merge([$userId], $manageableRoles));
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                throw new RuntimeException('العامل غير موجود أو غير قابل للإدارة من هذه الصفحة.');
            }
            $isSelfRoleEdit = (int)$target['id'] === (int)($_SESSION['user_id'] ?? 0);
            if ($isSelfRoleEdit && !$isSuperAdmin) {
                throw new RuntimeException('لا يمكنك تعديل دور حسابك الحالي من هنا.');
            }

            $selectedRoles = is_array($_POST['roles'] ?? null)
                ? array_values(array_unique(array_filter(array_map('strval', $_POST['roles']))))
                : [];
            if ($selectedRoles === []) {
                throw new RuntimeException('حدد دوراً واحداً على الأقل للعامل.');
            }
            $invalidRoles = array_values(array_diff($selectedRoles, array_merge(['employee'], array_map('strval', $validRoles))));
            if ($invalidRoles !== []) {
                throw new RuntimeException('تتضمن الأدوار المحددة دوراً غير صالح.');
            }
            $isEmployee = in_array('employee', $selectedRoles, true);
            if ($isEmployee && count($selectedRoles) > 1) {
                throw new RuntimeException('دور الموظف للخدمة الذاتية حصري ولا يمكن جمعه مع دور آخر.');
            }
            if ($isSelfRoleEdit && !in_array('super_admin', $selectedRoles, true)) {
                throw new RuntimeException('يجب الإبقاء على دور مدير النظام الأعلى في حسابك الحالي.');
            }
            $primaryRole = trim((string)($_POST['primary_role'] ?? ''));
            if (!in_array($primaryRole, $selectedRoles, true)) {
                $primaryRole = $selectedRoles[0];
            }
            if ($isSelfRoleEdit) {
                $primaryRole = 'super_admin';
            }
            $currentRoleRows = $staffRoleAssignmentService->rolesForUser($userId, true);
            $currentRoles = array_values(array_map(
                static fn(array $row): string => (string)$row['role_key'],
                $currentRoleRows
            ));
            if (array_intersect(['admin', 'super_admin'], array_merge($currentRoles, $selectedRoles)) !== []) {
                if (!$isSuperAdmin) {
                    throw new RuntimeException('هذه العملية متاحة عند تفعيل دور مدير النظام الأعلى فقط.');
                }
                $systemAdministratorRoleService->assertActorCanManage(
                    (int)($_SESSION['user_id'] ?? 0),
                    (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')
                );
            }
            $systemAdministratorRoleService->assertRoleSetChangeAllowed(
                (int)($_SESSION['user_id'] ?? 0),
                $userId,
                $currentRoles,
                $selectedRoles,
                (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')
            );
            $newIsSupervisor = in_array('teacher', $selectedRoles, true)
                && (string)($_POST['is_supervisor'] ?? '') === '1' ? 1 : 0;

            $details = [
                'roles' => ['old' => $currentRoles, 'new' => $selectedRoles],
                'primary_role' => ['old' => $target['role'] ?: 'employee', 'new' => $primaryRole],
                'is_supervisor' => ['old' => (int)($target['is_supervisor'] ?? 0), 'new' => $newIsSupervisor],
            ];
            $staffRoleAssignmentService->replaceRoles(
                $userId,
                $selectedRoles,
                $primaryRole,
                (int)($_SESSION['user_id'] ?? 0)
            );
            if ($isEmployee) {
                $newStatus = in_array((string)$target['status'], ['active', 'inactive'], true) ? (string)$target['status'] : 'active';
                $db->prepare('UPDATE users SET is_supervisor = 0, status = ? WHERE id = ?')
                    ->execute([$newStatus, $userId]);
            } else {
                $newStatus = in_array((string)$target['status'], ['active', 'inactive'], true) ? (string)$target['status'] : 'active';
                $db->prepare('UPDATE users SET is_supervisor = ?, status = ? WHERE id = ?')
                    ->execute([$newIsSupervisor, $newStatus, $userId]);
            }

            $scopeService = new StaffAcademicScopeService($db);
            $academicYearId = AcademicYear::currentId($db);
            $scopePayload = is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [];
            foreach ($selectedRoles as $selectedRole) {
                if (!$staffRoleCapabilityResolver->requiresAcademicScope($selectedRole)) {
                    continue;
                }
                if ($academicYearId <= 0) {
                    throw new RuntimeException('لا يوجد عام دراسي نشط لحفظ النطاق الأكاديمي.');
                }
                $roleScope = is_array($scopePayload[$selectedRole] ?? null) ? $scopePayload[$selectedRole] : [];
                $gradeIds = is_array($roleScope['grade_ids'] ?? null) ? $roleScope['grade_ids'] : [];
                $classIds = is_array($roleScope['class_ids'] ?? null) ? $roleScope['class_ids'] : [];
                if ($gradeIds === [] && $classIds === []) {
                    throw new RuntimeException('حدد صفاً أو فصلاً واحداً على الأقل لدور «' . ($roleLabels[$selectedRole] ?? $selectedRole) . '».');
                }
                $scopeService->replaceAssignments(
                    $userId,
                    $academicYearId,
                    $gradeIds,
                    $classIds,
                    (int)($_SESSION['user_id'] ?? 0),
                    $selectedRole
                );
                $details['academic_scope_updated'][] = $selectedRole;
            }
            foreach (array_diff($currentRoles, $selectedRoles) as $removedRole) {
                if ($staffRoleCapabilityResolver->requiresAcademicScope($removedRole)) {
                    $scopeService->removeRoleAssignments(
                        $userId,
                        $removedRole,
                        (int)($_SESSION['user_id'] ?? 0),
                        'إلغاء الدور المرتبط بالنطاق الأكاديمي'
                    );
                    $details['academic_scope_removed'][] = $removedRole;
                }
            }

            if (!ActivityLog::logUpdate('staff_account', $userId, (string)$target['name'], $details)) {
                throw new RuntimeException('تعذر تسجيل تعديل دور العامل؛ لم يتم حفظ أي تغيير.');
            }
            $db->commit();
            $_SESSION['success_message'] = $isEmployee
                ? 'تم تحويل «' . $target['name'] . '» إلى موظف بدون صلاحية دخول.'
                : 'تم تحديث أدوار وصلاحيات العامل «' . $target['name'] . '» بنجاح.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error_message'] = $e->getMessage();
        }
        $staffAccountResponder->finish($_SERVER['PHP_SELF'] . '?tab=' . urlencode($activeTab), (string)$userId);
    }

    // جلب العامل المستهدف (دور ضمن المسموح أو NULL)
    $checkStmt = $db->prepare("SELECT id, name, username, password, role, status FROM users WHERE id = ? LIMIT 1");
    $checkStmt->execute([$userId]);
    $target = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $_SESSION['error_message'] = "العامل غير موجود.";
        $staffAccountResponder->finish($_SERVER['PHP_SELF'] . '?tab=' . urlencode($activeTab), (string)$userId);
    }
    // التأكد أن المستهدف عامل وليس طالباً (الطلاب لهم صفحتهم)
    if ($target['role'] === 'student') {
        $_SESSION['error_message'] = "هذا الحساب طالب، يُدار من صفحة حسابات الطلاب.";
        $staffAccountResponder->finish($_SERVER['PHP_SELF'] . '?tab=' . urlencode($activeTab), (string)$userId);
    }
    if ($target['role'] !== null && !in_array((string)$target['role'], array_merge($validRoles, ['employee']), true)) {
        $_SESSION['error_message'] = "هذا الحساب ليس حساب عامل قابل للإدارة من هذه الصفحة.";
        $staffAccountResponder->finish($_SERVER['PHP_SELF'] . '?tab=' . urlencode($activeTab), (string)$userId);
    }

    $selfLock = ((int)$target['id'] === (int)($_SESSION['user_id'] ?? 0));

    try {
        if ($action === 'toggle_status') {
            if ($selfLock) {
                throw new RuntimeException("لا يمكنك تعطيل/تفعيل حسابك الحالي.");
            }
            $logged = false;
            $db->beginTransaction();
            try {
                $lockedTargetStmt = $db->prepare(
                    'SELECT id, name, role, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
                );
                $lockedTargetStmt->execute([$userId]);
                $lockedTarget = $lockedTargetStmt->fetch(PDO::FETCH_ASSOC);
                if (!$lockedTarget) {
                    throw new RuntimeException('العامل غير موجود.');
                }
                $target = array_merge($target, $lockedTarget);
                $newStatus = ((string)$target['status'] === 'active') ? 'inactive' : 'active';
                $targetRoleKeys = $staffRoleAssignmentService->roleKeysForUser($userId, true);
                if (array_intersect(['admin', 'super_admin'], $targetRoleKeys) !== [] && !$isSuperAdmin) {
                    throw new RuntimeException('هذه العملية متاحة عند تفعيل دور مدير النظام الأعلى فقط.');
                }
                $systemAdministratorRoleService->assertStatusChangeAllowed(
                    (int)($_SESSION['user_id'] ?? 0),
                    $userId,
                    $target['role'] !== null ? (string)$target['role'] : null,
                    (string)$target['status'],
                    $newStatus,
                    (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')
                );
                $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);
                if (!ActivityLog::logStatusChange('staff_account', $userId, $target['name'], $newStatus)) {
                    throw new RuntimeException('تعذر تسجيل تغيير حالة الحساب؛ لم يتم حفظ أي تغيير.');
                }
                $logged = true;
                $db->commit();
            } catch (Throwable $logEx) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $logEx;
            }

            $label = ($newStatus === 'active') ? 'تفعيل' : 'تعطيل';
            $_SESSION['success_message'] = "تم {$label} حساب العامل «" . $target['name'] . "».";

        } else {
            throw new RuntimeException("إجراء غير معروف.");
        }
    } catch (Throwable $e) {
        // تسجيل الخطأ الداخلي للسجل فقط — عرض رسالة عامة للمستخدم
        error_log('staff_accounts.php action error: ' . $e->getMessage());
        $_SESSION['error_message'] = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'حدث خطأ غير متوقع أثناء تنفيذ العملية. يرجى المحاولة مرة أخرى.';
    }

    $staffAccountResponder->finish($_SERVER['PHP_SELF'] . '?tab=' . urlencode($activeTab), (string)$userId);
}

// ====== الفلاتس ======
$roleFilter   = isset($_GET['role']) ? trim($_GET['role']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$accessFilter = isset($_GET['access']) ? trim($_GET['access']) : '';

// خيار الفلترة: "all" (الكل)، "configured" (مُهيّأ)، "unconfigured" (غير مُهيّأ)
$configFilter = isset($_GET['configured']) ? trim($_GET['configured']) : '';

// ملاحظة: كتلة الاستعلام القديم (التي كانت تفك تشفير كلمات المرور جماعياً) تمت إزالتها.
// البيانات الآن تُجلب عبر AccountListDataTableQuery المعيارية والموثقة عبر AJAX.
$accountSummary = (new AccountListDataTableQuery($db))->staffSummary($validRoles, ['tab' => $activeTab, 'account_group' => $accountGroupFilter]);
$total = $accountSummary['total'];
$unconfiguredCount = $accountSummary['unconfigured'];
$portalCount = $accountSummary['portal'];
$employeeCount = $accountSummary['employee'];
$staff = [];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$staffScopeYearName = AcademicYear::currentName($db);

include_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-user-shield me-2 text-info"></i>حسابات العاملين</h1>
    <div class="admin-top-actions no-print">
        <?php if ($activeTab !== 'roles'): ?>
            <button type="button" class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal" data-bs-target="#importCredentialsModal">
                <i class="fas fa-file-import me-1"></i>استيراد Excel
            </button>
            <a href="staff_accounts.php?<?php echo htmlspecialchars(http_build_query(['tab' => $activeTab, 'account_group' => $accountGroupFilter, 'action' => 'export_credentials']), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-header-premium btn-export-soft" title="يحتوي الملف على كلمات المرور القابلة للاسترجاع">
                <i class="fas fa-file-excel me-1"></i>تصدير Excel
            </a>
        <?php endif; ?>
        <a href="staff.php" class="btn btn-header-premium btn-import-soft">
            <i class="fas fa-users-cog me-1"></i>بيانات العاملين
        </a>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<?php if ($activeTab === 'accounts'): ?>

<?php if ($unconfiguredCount > 0): ?>
    <div class="alert alert-warning alert-dismissible sticky-alert fade show" role="alert" data-datatable-summary-visible="unconfigured">
        <i class="fas fa-exclamation-triangle me-2"></i>
        يوجد <strong data-datatable-summary-key="unconfigured"><?php echo (int)$unconfiguredCount; ?></strong> حساب بوابة بيانات دخوله غير مكتملة.
        <a href="?<?php echo htmlspecialchars(http_build_query(['tab' => $activeTab, 'account_group' => $accountGroupFilter, 'access' => 'incomplete']), ENT_QUOTES, 'UTF-8'); ?>" class="alert-link">عرضها الآن</a>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<!-- بطاقات إحصائية -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$total; ?>" data-datatable-summary-key="total">0</div>
                <div class="stat-card-label">إجمالي العاملين</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-circle-check"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$portalCount; ?>" data-datatable-summary-key="portal">0</div>
                <div class="stat-card-label">لديهم بوابة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-user-lock"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$employeeCount; ?>" data-datatable-summary-key="employee">0</div>
                <div class="stat-card-label">الموظفون</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-user-clock"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$unconfiguredCount; ?>" data-datatable-summary-key="unconfigured">0</div>
                <div class="stat-card-label">دخول غير مكتمل</div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<ul class="nav nav-tabs mb-3 border-bottom">
    <li class="nav-item">
        <a class="nav-link fw-semibold <?php echo $activeTab === 'accounts' ? 'active' : ''; ?>" href="staff_accounts.php?tab=accounts">
            <i class="fas fa-users me-2"></i>حسابات العاملين
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-semibold <?php echo $activeTab === 'roles' ? 'active' : ''; ?>" href="staff_accounts.php?tab=roles">
            <i class="fas fa-user-shield me-2"></i>الأدوار والصلاحيات
            <span class="badge bg-secondary ms-1"><?php echo count($customRoles); ?></span>
        </a>
    </li>
</ul>

<?php if ($activeTab === 'accounts'): ?>

<form class="admin-filter-bar" id="staffAccountFilters" autocomplete="off">
    <div class="admin-filter-controls">
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="accountGroupDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 160px;">
                <span>مجال الدور: <span id="selectedAccountGroupsLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="accountGroupDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach (['academic' => 'صلاحيات أكاديمية', 'non_academic' => 'صلاحيات غير أكاديمية'] as $groupKey => $groupLabel): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input account-group-checkbox" type="checkbox" name="account_group[]" value="<?php echo $groupKey; ?>" id="account_group_<?php echo $groupKey; ?>" <?php echo in_array($groupKey, $accountGroupFilter, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="account_group_<?php echo $groupKey; ?>"><?php echo $groupLabel; ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Role Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="roleDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>الدور: <span id="selectedRolesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="roleDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($tabRoleLabels as $key => $label): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input role-checkbox" type="checkbox" name="role[]" value="<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>" id="role_<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($key, (array)($roleFilter ? (is_array($roleFilter) ? $roleFilter : [$roleFilter]) : []), true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="role_<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Status Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="statusDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 130px;">
                <span>الحالة: <span id="selectedStatusesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="statusDropdown" style="max-height: 250px; overflow-y: auto; min-width: 180px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <div class="form-check mb-1">
                    <input class="form-check-input status-checkbox" type="checkbox" name="status[]" value="active" id="status_active" <?php echo in_array('active', (array)($statusFilter ? (is_array($statusFilter) ? $statusFilter : [$statusFilter]) : []), true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="status_active">مفعّل</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input status-checkbox" type="checkbox" name="status[]" value="inactive" id="status_inactive" <?php echo in_array('inactive', (array)($statusFilter ? (is_array($statusFilter) ? $statusFilter : [$statusFilter]) : []), true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="status_inactive">معطّل</label>
                </div>
            </div>
        </div>

        <!-- Access Dropdown -->
        <div class="dropdown d-inline-block me-2" id="staffAccessFilter">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="accessDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 150px;">
                <span>حالة الدخول: <span id="selectedAccessLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="accessDropdown" style="max-height: 250px; overflow-y: auto; min-width: 190px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <div class="form-check mb-1">
                    <input class="form-check-input access-checkbox" type="checkbox" name="access[]" value="portal" id="access_portal" <?php echo in_array('portal', (array)($accessFilter ? (is_array($accessFilter) ? $accessFilter : [$accessFilter]) : []), true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="access_portal">بوابة مكتملة</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input access-checkbox" type="checkbox" name="access[]" value="employee" id="access_employee" <?php echo in_array('employee', (array)($accessFilter ? (is_array($accessFilter) ? $accessFilter : [$accessFilter]) : []), true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="access_employee">موظفون</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input access-checkbox" type="checkbox" name="access[]" value="incomplete" id="access_incomplete" <?php echo in_array('incomplete', (array)($accessFilter ? (is_array($accessFilter) ? $accessFilter : [$accessFilter]) : []), true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="access_incomplete">دخول غير مكتمل</label>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-filter-actions">
        <button type="button" class="btn btn-light btn-sm" id="resetStaffAccountFilters">
            <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
        </button>
        <button type="button" class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#tableSettingsModal">
            <i class="fas fa-cog me-1"></i>إعدادات الجدول
        </button>
    </div>
</form>

<!-- Modal إعدادات عرض الجدول -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-labelledby="tableSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="tableSettingsModalLabel"><i class="fas fa-cog me-2"></i>إعدادات عرض الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="form-check mb-2">
                    <input class="form-check-input column-toggle" type="checkbox" id="col_employee_code" checked>
                    <label class="form-check-label" for="col_employee_code">الكود</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input column-toggle" type="checkbox" id="col_employee_name" checked>
                    <label class="form-check-label" for="col_employee_name">الاسم</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input column-toggle" type="checkbox" id="col_role" checked>
                    <label class="form-check-label" for="col_role">دور البوابة</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input column-toggle" type="checkbox" id="col_job_title" checked>
                    <label class="form-check-label" for="col_job_title">الوظيفة / القسم</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input column-toggle" type="checkbox" id="col_username" checked>
                    <label class="form-check-label" for="col_username">اسم المستخدم</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input column-toggle" type="checkbox" id="col_configured" checked>
                    <label class="form-check-label" for="col_configured">التهيئة</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input column-toggle" type="checkbox" id="col_status" checked>
                    <label class="form-check-label" for="col_status">الحالة</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal تأكيد تغيير حالة الحساب -->
<div class="modal fade" id="toggleStatusModal" tabindex="-1" aria-labelledby="toggleStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleStatusModalContent">
            <div class="modal-header">
                <h5 class="modal-title" id="toggleStatusModalLabel">
                    <i class="fas fa-power-off me-2" id="toggleStatusIcon"></i>
                    <span id="toggleStatusTitle">تغيير حالة الحساب</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">العامل: <strong id="toggleStatusName"></strong></p>
                <p class="text-muted mb-0" id="toggleStatusConsequence"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <form method="post" action="staff_accounts.php?tab=<?php echo urlencode($activeTab); ?>" style="display:inline" data-datatable-ajax="true" data-datatable-return-table="staffAccountsTable" data-datatable-return-row-field="user_id">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="user_id" id="toggleStatusUserId" value="">
                    <input type="hidden" name="action" value="toggle_status">
                    <button type="submit" class="btn" id="toggleStatusSubmit">
                        <i class="fas me-1" id="toggleStatusSubmitIcon"></i>
                        <span id="toggleStatusSubmitLabel"></span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="bulkFilterResetNotice" class="alert alert-info py-2 px-3 small d-none mb-3">
    <i class="fas fa-info-circle me-2"></i>تم إلغاء التحديد السابق بسبب تغيير الفلاتر الحية.
</div>

<div id="staffBulkActionBar" class="admin-bulk-action-bar d-none">
    <div class="admin-bulk-info">
        <span class="admin-bulk-badge bulk-selected-count">0</span>
        <span>حسابات محددة</span>
        <span class="text-muted small bulk-mode-label"></span>
        <button type="button" class="btn btn-sm btn-outline-primary btn-select-all-filtered d-none ms-2">
            <i class="fas fa-check-double me-1"></i>تحديد كل النتائج المطابقة للفلاتر
            (<span class="filtered-count-badge">0</span>)
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger btn-clear-selection ms-2">
            <i class="fas fa-times me-1"></i>إلغاء التحديد
        </button>
    </div>
    <div class="admin-bulk-actions">
        <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="openBulkStaffRoleModal()">
            <i class="fas fa-user-shield me-1"></i>تطبيق الأدوار والنطاق
        </button>
        <button type="button" class="btn btn-sm btn-outline-warning shadow-sm" onclick="openBulkStaffSupervisorModal()">
            <i class="fas fa-user-check me-1"></i>صفة المشرف
        </button>
        <button type="button" class="btn btn-sm btn-outline-success shadow-sm" onclick="openBulkStaffActionModal('activate')">
            <i class="fas fa-check-circle me-1"></i>تفعيل
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger shadow-sm" onclick="openBulkStaffActionModal('deactivate')">
            <i class="fas fa-ban me-1"></i>تعطيل
        </button>
        <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="openBulkStaffActionModal('generate_credentials')">
            <i class="fas fa-key me-1"></i>توليد بيانات الدخول
        </button>
        <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="openBulkStaffActionModal('reset_passwords')">
            <i class="fas fa-sync-alt me-1"></i>إعادة تعيين المرور
        </button>
        <button type="button" class="btn btn-sm btn-outline-success shadow-sm" onclick="openBulkStaffActionModal('export_credentials')">
            <i class="fas fa-file-csv me-1"></i>تصدير المحدد
        </button>
    </div>
</div>

<div class="admin-list-surface">
    <div class="admin-table-wrap">
            <table class="table table-hover table-striped admin-data-table align-middle" id="staffAccountsTable">
                <thead>
                    <tr>
                        <th class="no-sort text-center" style="width: 40px;"><input type="checkbox" class="form-check-input select-all-page" title="تحديد جميع سجلات الصفحة الحالية" aria-label="تحديد جميع سجلات الصفحة الحالية"></th><th class="no-sort" style="width: 40px;">#</th>
                        <th>الكود</th>
                        <th>الاسم</th>
                        <th>دور البوابة</th>
                        <th>الوظيفة / القسم</th>
                        <th>اسم المستخدم</th>
                        <th>كلمة المرور</th>
                        <th>التهيئة</th>
                        <th>الحالة</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staff)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-5">
                            <i class="fas fa-user-shield fa-3x mb-3 d-block text-muted"></i>
                            جاري تحميل حسابات العاملين...
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

<?php if (!$isSuperAdmin): ?>
    <div class="alert alert-info">
        <i class="fas fa-shield-alt me-2"></i>
        يمكنك مراجعة الأدوار وصلاحياتها، أما إنشاء الأدوار أو تعديلها أو منح صلاحيات الإدارة العليا فمتاح لمدير النظام الأعلى فقط.
    </div>
<?php endif; ?>

<div class="row g-3">
    <?php if ($isSuperAdmin): ?>
    <div class="col-lg-4">
        <div class="card shadow h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i><span id="roleFormTitle">إضافة دور جديد</span></h5>
            </div>
            <div class="card-body">
                <form method="post" action="staff_accounts.php?tab=roles" id="roleForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <input type="hidden" name="action" value="save_role">
                    <input type="hidden" name="role_id" id="role_id" value="">
                    <input type="hidden" name="clone_source_role_key" id="clone_source_role_key" value="">
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم الدور</label>
                        <input type="text" name="role_name" id="role_name" class="form-control" required placeholder="مثال: شؤون الطلاب">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مفتاح الدور</label>
                        <input type="text" name="role_key" id="role_key" class="form-control" dir="ltr" placeholder="student_affairs">
                        <div class="form-text">حروف إنجليزية صغيرة وأرقام وشرطة سفلية فقط. لا يتغير بعد إنشاء الدور.</div>
                    </div>
                    <div class="border rounded p-2" style="max-height: 360px; overflow:auto;">
                        <div class="fw-bold mb-2"><i class="fas fa-file-alt me-1 text-primary"></i>الصفحات المسموح بها</div>
                        <?php foreach ($availableAdminPages as $pageName => $pageLabel): ?>
                            <div class="form-check role-page-option" data-page-name="<?php echo htmlspecialchars($pageName, ENT_QUOTES, 'UTF-8'); ?>">
                                <input class="form-check-input role-page-checkbox" type="checkbox" name="pages[]" value="<?php echo htmlspecialchars($pageName); ?>" id="page_<?php echo md5($pageName); ?>">
                                <label class="form-check-label" for="page_<?php echo md5($pageName); ?>">
                                    <?php echo htmlspecialchars($pageLabel); ?>
                                    <small class="text-muted" dir="ltr">(<?php echo htmlspecialchars($pageName); ?>)</small>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-success" id="saveRoleButton">
                            <i class="fas fa-save me-1"></i>حفظ الدور
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="resetRoleForm">
                            <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="<?php echo $isSuperAdmin ? 'col-lg-8' : 'col-12'; ?>">
        <?php if ($isSuperAdmin): ?>
        <div id="bulkRolePermissionsBar" class="admin-bulk-action-bar d-none mb-3">
            <div class="admin-bulk-info">
                <span class="admin-bulk-badge bulk-selected-role-count">0</span>
                <span>أدوار مخصصة محددة</span>
            </div>
            <div class="admin-bulk-actions">
                <button type="button" class="btn btn-sm btn-primary shadow-sm" onclick="openBulkRolePagesModal()">
                    <i class="fas fa-layer-group me-1"></i>تطبيق الصلاحيات والصفحات جماعياً
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger btn-clear-role-selection">
                    <i class="fas fa-times me-1"></i>إلغاء التحديد
                </button>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>الأدوار الفعلية والصلاحيات</h5>
                <span class="badge bg-light text-dark"><?php echo count($fixedRoleRows) + count($customRoles); ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0" id="rolesPermissionsTable">
                    <thead class="table-light">
                        <tr>
                            <?php if ($isSuperAdmin): ?><th class="text-center" style="width: 40px;"><input type="checkbox" class="form-check-input select-all-custom-roles" title="تحديد جميع الأدوار القابلة للتخصيص" aria-label="تحديد جميع الأدوار القابلة للتخصيص"></th><?php endif; ?><th style="width: 40px;">#</th>
                            <th>الدور</th>
                            <th>المفتاح</th>
                            <th>الصفحات</th>
                            <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fixedRoleRows as $idx => $fixedRole): ?>
                            <tr>
                                <?php if ($isSuperAdmin): ?><td></td><?php endif; ?>
                                <td><span class="text-muted"><?php echo $idx + 1; ?></span></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($fixedRole['role_name']); ?></td>
                                <td><code><?php echo htmlspecialchars($fixedRole['role_key']); ?></code></td>
                                <td><span class="text-muted"><?php echo htmlspecialchars($fixedRole['description']); ?></span></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><i class="fas fa-lock me-1"></i>دور نظامي ثابت</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($customRoles as $idx => $roleRow):
                            $currentRoleKey = (string)$roleRow['role_key'];
                            $roleFamily = trim((string)($roleRow['base_role_key'] ?? ''));
                            if ($roleFamily === '' && AdminRolePageCatalog::isCustomizableRole($currentRoleKey)) {
                                $roleFamily = $currentRoleKey;
                            }
                            $customizablePages = $roleFamily !== ''
                                ? AdminRolePageCatalog::customizablePages($roleFamily)
                                : [];
                            $mandatoryPages = $roleFamily !== ''
                                ? AdminRolePageCatalog::mandatoryPages($roleFamily)
                                : [];
                            $rolePages = explode(',', (string)($roleRow['pages'] ?? ''));
                            $rolePages = array_values(array_filter(
                                array_unique(array_map(
                                    static fn($page): string => AdminRolePageCatalog::canonicalPage((string)$page),
                                    $rolePages
                                )),
                                static fn(string $page): bool => $page !== ''
                                    && isset($availableAdminPages[$page])
                                    && !AdminRolePageCatalog::isSupportingPage($page)
                            ));
                            $isCustomizable = AdminRolePageCatalog::isCustomizableRole($currentRoleKey) || ($roleFamily !== '' && AdminRolePageCatalog::isCustomizableRole($roleFamily));
                        ?>
                            <tr>
                                <?php if ($isSuperAdmin): ?><td class="text-center">
                                    <?php if ($isCustomizable): ?>
                                        <input type="checkbox" class="form-check-input role-bulk-cb" value="<?php echo htmlspecialchars($currentRoleKey, ENT_QUOTES, 'UTF-8'); ?>" data-role-name="<?php echo htmlspecialchars($roleRow['role_name'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="تحديد دور <?php echo htmlspecialchars($roleRow['role_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php endif; ?>
                                </td><?php endif; ?>
                                <td><span class="text-muted"><?php echo count($fixedRoleRows) + $idx + 1; ?></span></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($roleRow['role_name']); ?></td>
                                <td><code><?php echo htmlspecialchars($roleRow['role_key']); ?></code></td>
                                <td>
                                    <?php if ($rolePages): ?>
                                        <?php foreach (array_slice($rolePages, 0, 6) as $pageName): ?>
                                            <span class="badge bg-light text-dark border me-1 mb-1"><?php echo htmlspecialchars($availableAdminPages[$pageName] ?? $pageName); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($rolePages) > 6): ?>
                                            <span class="badge bg-secondary">+<?php echo count($rolePages) - 6; ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">لا توجد صفحات محددة</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (in_array($currentRoleKey, $systemRoleKeys, true) && !AdminRolePageCatalog::isCustomizableRole($currentRoleKey)): ?>
                                        <span class="badge bg-secondary"><i class="fas fa-lock me-1"></i>دور نظامي ثابت</span>
                                    <?php elseif ($isSuperAdmin): ?>
                                        <button type="button" class="btn btn-action-pills btn-edit"
                                                data-role-id="<?php echo (int)$roleRow['id']; ?>"
                                                data-role-key="<?php echo htmlspecialchars($roleRow['role_key'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-role-name="<?php echo htmlspecialchars($roleRow['role_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-pages="<?php echo htmlspecialchars(json_encode($rolePages, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-allowed-pages="<?php echo htmlspecialchars(json_encode($customizablePages, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-mandatory-pages="<?php echo htmlspecialchars(json_encode($mandatoryPages, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-pages-only="<?php echo AdminRolePageCatalog::isCustomizableRole($currentRoleKey) ? '1' : '0'; ?>"
                                                onclick="editRoleFromButton(this)"
                                                data-bs-toggle="tooltip" title="تعديل الصفحات">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if (AdminRolePageCatalog::isCustomizableRole($currentRoleKey)): ?>
                                            <button type="button" class="btn btn-action-pills btn-activate me-1"
                                                    data-role-key="<?php echo htmlspecialchars($currentRoleKey, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-role-name="<?php echo htmlspecialchars($roleRow['role_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-pages="<?php echo htmlspecialchars(json_encode($rolePages, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-allowed-pages="<?php echo htmlspecialchars(json_encode($customizablePages, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-mandatory-pages="<?php echo htmlspecialchars(json_encode($mandatoryPages, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                    onclick="cloneRoleFromButton(this)"
                                                    data-bs-toggle="tooltip" title="استنساخ الدور">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border"><i class="fas fa-shield-alt me-1"></i>للمدير الأعلى فقط</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$fixedRoleRows && !$customRoles): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">لم يتم إضافة أدوار مخصصة بعد.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ===== Modal: تعديل بيانات الدخول ===== -->
<div class="modal fade" id="credentialsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="staff_accounts.php?tab=<?php echo urlencode($activeTab); ?>" class="modal-content admin-modal admin-modal-premium admin-modal-edit" data-no-form-safety="true" data-datatable-ajax="true" data-datatable-return-table="staffAccountsTable" data-datatable-return-row-field="user_id">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="update_login_credentials">
            <input type="hidden" name="user_id" id="cred_user_id">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center flex-wrap gap-1">
                    <i class="fas fa-key me-1"></i>تعديل بيانات الدخول
                    <span class="fs-6 text-muted ms-2 me-1">| الموظف:</span>
                    <strong id="cred_staff_name" class="text-primary me-1">—</strong>
                    <button type="button" class="btn btn-sm btn-light border-0 p-1 py-0 text-muted me-1" id="copyStaffNameBtn" data-bs-toggle="tooltip" title="نسخ اسم الموظف">
                        <i class="fas fa-copy"></i>
                    </button>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2">
                    <i class="fas fa-info-circle me-1"></i>هذا النموذج يغيّر اسم المستخدم وكلمة المرور فقط، ولا يغيّر الدور أو الصلاحيات.
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="cred_username" class="form-label fw-bold">اسم المستخدم <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="cred_username" class="form-control" autocomplete="off" minlength="3">
                    </div>
                    <div class="col-md-6">
                        <label for="cred_password" class="form-label fw-bold">كلمة المرور الجديدة</label>
                        <div class="input-group">
                            <input type="text" name="new_password" id="cred_password" class="form-control" autocomplete="off" placeholder="اتركها فارغة للحفاظ على الحالية">
                            <button type="button" class="btn btn-outline-secondary" id="generatePasswordBtn" title="توليد كلمة مرور"><i class="fas fa-dice"></i></button>
                            <button type="button" class="btn btn-outline-secondary" id="copyPasswordBtn" title="نسخ كلمة المرور"><i class="fas fa-copy"></i></button>
                        </div>
                        <div class="form-text">أدخل كلمة مرور عند إنشاء دخول جديد، أو اتركها فارغة للحفاظ على الحالية.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ بيانات الدخول</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/staff_single_modals.php'; ?>

<script src="../assets/js/admin_table_actions.js"></script>
<script src="<?php echo asset_url('../assets/js/admin-server-side-table.js'); ?>"></script>
<script src="../assets/js/staff-accounts-modals.js?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/js/staff-accounts-modals.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
    var staffAccountsTable = null;
    if (window.AdminServerSideTable && document.getElementById('staffAccountsTable')) {
        staffAccountsTable = window.AdminServerSideTable.init({
            selector: '#staffAccountsTable', url: 'ajax_staff_accounts_datatable.php', order: [[3, 'asc']],
            dtOptions: { columnDefs: [{ targets: [0, 1, 10], orderable: false }] },
            language: { processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل الحسابات…', emptyTable: 'لا توجد حسابات مطابقة للفلاتر.' },
            requestData: function () {
                return {
                    role: Array.from(document.querySelectorAll('.role-checkbox:checked')).map(function (cb) { return cb.value; }),
                    status: Array.from(document.querySelectorAll('.status-checkbox:checked')).map(function (cb) { return cb.value; }),
                    access: Array.from(document.querySelectorAll('.access-checkbox:checked')).map(function (cb) { return cb.value; }),
                    account_group: Array.from(document.querySelectorAll('.account-group-checkbox:checked')).map(function (cb) { return cb.value; }),
                    tab: <?php echo json_encode($activeTab); ?>
                };
            }
        });
    }

    if (typeof initializeTableColumnSettings === 'function') {
        initializeTableColumnSettings('staffAccountsTable', {
            col_employee_code: 2,
            col_employee_name: 3,
            col_role: 4,
            col_job_title: 5,
            col_username: 6,
            col_configured: 8,
            col_status: 9
        });
    }

    function updateStaffDropdownLabels() {
        var filterDefs = [
            { cb: '.account-group-checkbox', label: 'selectedAccountGroupsLabel', btn: 'accountGroupDropdown' },
            { cb: '.role-checkbox', label: 'selectedRolesLabel', btn: 'roleDropdown' },
            { cb: '.status-checkbox', label: 'selectedStatusesLabel', btn: 'statusDropdown' },
            { cb: '.access-checkbox', label: 'selectedAccessLabel', btn: 'accessDropdown' }
        ];

        filterDefs.forEach(function (def) {
            var checked = document.querySelectorAll(def.cb + ':checked');
            var labelEl = document.getElementById(def.label);
            var btnEl = document.getElementById(def.btn);
            var totalCount = document.querySelectorAll(def.cb).length;

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

    updateStaffDropdownLabels();

    ['.account-group-checkbox', '.role-checkbox', '.status-checkbox', '.access-checkbox'].forEach(function (selector) {
        document.querySelectorAll(selector).forEach(function (cb) {
            cb.addEventListener('change', function () {
                updateStaffDropdownLabels();
                if (staffAccountsTable) staffAccountsTable.ajax.reload();
            });
        });
    });

    var resetFilters = document.getElementById('resetStaffAccountFilters');
    if (resetFilters) {
        resetFilters.addEventListener('click', function () {
            document.querySelectorAll('.account-group-checkbox, .role-checkbox, .status-checkbox, .access-checkbox').forEach(function (cb) {
                cb.checked = false;
            });
            updateStaffDropdownLabels();
            if (staffAccountsTable) staffAccountsTable.ajax.reload();
        });
    }
    var revealTimers = {};
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.reveal-password');
        if (!button) return;
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

        fetch('ajax/get_password.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ csrf_token: csrfToken, user_id: userId, account_type: 'user' }) })
            .then(function (response) { return response.json(); }).then(function (data) {
                if (!data.success || !data.password) throw new Error();
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
        var copyText = function (text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }
            return Promise.reject(new Error('No clipboard API'));
        };

        if (textEl && !textEl.classList.contains('d-none') && textEl.textContent.trim()) {
            copyText(textEl.textContent.trim()).then(showSuccess).catch(function () {});
            return;
        }

        copyBtn.disabled = true; copyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        fetch('ajax/get_password.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ csrf_token: csrfToken, user_id: userId, account_type: 'user' })
        }).then(function (response) { return response.json(); }).then(function (data) {
            if (!data.success || !data.password) throw new Error();
            return copyText(data.password);
        }).then(showSuccess).catch(function () {
            copyBtn.innerHTML = originalIcon; copyBtn.disabled = false;
        });
    });

    document.addEventListener('click', function (event) {
        var copyBtn = event.target.closest('.copy-username-btn');
        if (!copyBtn) return;
        event.preventDefault();
        var username = copyBtn.getAttribute('data-username') || '';
        if (!username) return;
        var icon = copyBtn.querySelector('i') || copyBtn;
        var origClass = icon.className;
        var copyText = function (text) {
            if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text);
            return Promise.reject();
        };
        copyText(username).then(function () {
            icon.className = 'fas fa-check text-success';
            setTimeout(function () { icon.className = origClass; }, 1500);
        }).catch(function () {});
    });
    // إظهار/إخفاء كلمة المرور
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

    // توليد كلمة مرور + نسخ
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

    var copyNameBtn = document.getElementById('copyStaffNameBtn');
    if (copyNameBtn) {
        copyNameBtn.addEventListener('click', function () {
            var nameEl = document.getElementById('cred_staff_name');
            var nameText = nameEl ? nameEl.textContent.trim() : '';
            if (nameText && nameText !== '—') {
                try {
                    navigator.clipboard.writeText(nameText).then(function () {
                        var icon = copyNameBtn.querySelector('i');
                        if (icon) {
                            icon.className = 'fas fa-check text-success';
                            setTimeout(function () { icon.className = 'fas fa-copy'; }, 1500);
                        }
                    });
                } catch (e) {}
            }
        });
    }

    var resetRoleBtn = document.getElementById('resetRoleForm');
    if (resetRoleBtn) {
        resetRoleBtn.addEventListener('click', resetRoleFormState);
    }

    var roleInputs = Array.from(document.querySelectorAll('.role-access-checkbox'));
    var primaryRoleInput = document.getElementById('role_primary_value');
    var employeeNotice = document.getElementById('employeeAccessNotice');
    var teacherSupervisorField = document.getElementById('teacherSupervisorField');
    var supervisorInput = document.getElementById('role_is_supervisor');
    var staffScopeSection = document.getElementById('staffScopeSection');
    var scopeContent = document.getElementById('staffScopeContent');
    var scopeStatus = document.getElementById('staffScopeStatus');
    var saveRoleAccess = document.getElementById('saveRoleAccessButton');
    var roleAccessForm = document.getElementById('roleAccessForm');
    var loadedScopeRoles = new Set();
    var pendingScopeLoads = 0;

    window.resetStaffRoleScopes = function () {
        loadedScopeRoles.clear();
        pendingScopeLoads = 0;
        if (scopeContent) scopeContent.innerHTML = '';
    };

    function showScopeStatus(type, message) {
        if (!scopeStatus) return;
        scopeStatus.className = 'alert alert-' + type;
        scopeStatus.textContent = message;
    }

    function syncScopeGrade(card, uncheckClasses) {
        var grade = card.querySelector('.assignment-grade-checkbox');
        if (!grade) return;
        var classInputs = Array.from(card.querySelectorAll('.assignment-class-checkbox'));
        classInputs.forEach(function (input) {
            input.disabled = grade.checked;
            if (grade.checked || uncheckClasses) {
                input.checked = false;
            }
        });
        var selectedClasses = classInputs.filter(function (input) { return input.checked; });
        var hasScope = grade.checked || selectedClasses.length > 0;
        card.classList.toggle('border-primary', hasScope);
        card.classList.toggle('shadow', hasScope);
        var badge = card.querySelector('.assignment-grade-scope-badge');
        if (badge) {
            badge.className = hasScope
                ? 'badge bg-primary-subtle text-primary border border-primary assignment-grade-scope-badge'
                : 'badge bg-light text-dark border assignment-grade-scope-badge';
            badge.textContent = grade.checked
                ? 'الصف بالكامل'
                : (selectedClasses.length > 0 ? selectedClasses.length + ' فصول' : 'غير محدد');
        }
    }

    function syncStaffScopeSection(section) {
        if (!section) return;
        section.querySelectorAll('.assignment-grade-card').forEach(function (card) {
            syncScopeGrade(card, false);
        });
        section.querySelectorAll('.assignment-stage-group').forEach(function (stageGroup) {
            var grades = Array.from(stageGroup.querySelectorAll('.assignment-grade-checkbox:not(:disabled)'));
            var allSelected = grades.length > 0 && grades.every(function (input) { return input.checked; });
            var button = stageGroup.querySelector('.select-assignment-stage-btn');
            if (!button) return;
            button.classList.toggle('btn-primary', allSelected);
            button.classList.toggle('btn-outline-primary', !allSelected);
            button.innerHTML = allSelected
                ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد المرحلة'
                : '<i class="fas fa-check-double me-1"></i>تحديد المرحلة';
        });
        var selectableGrades = Array.from(section.querySelectorAll('.assignment-grade-checkbox:not(:disabled)'));
        var allGradesSelected = selectableGrades.length > 0 && selectableGrades.every(function (input) { return input.checked; });
        var allButton = section.querySelector('.select-staff-all-grades-btn');
        if (allButton) {
            allButton.classList.toggle('btn-primary', allGradesSelected);
            allButton.classList.toggle('btn-outline-primary', !allGradesSelected);
            allButton.innerHTML = allGradesSelected
                ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد الكل'
                : '<i class="fas fa-check-double me-1"></i>تحديد كل الصفوف';
        }
    }

    function renderStaffScope(data, roleKey, roleLabel) {
        var safeRoleKey = roleKey.replace(/[^a-z0-9_]/gi, '_');
        var roleSection = document.createElement('section');
        roleSection.className = 'staff-scope-role';
        roleSection.setAttribute('data-scope-role', roleKey);

        var roleHeader = document.createElement('div');
        roleHeader.className = 'd-flex align-items-center justify-content-between gap-2 p-2 mb-2 bg-light rounded border-start border-3 border-primary';
        var roleTitle = document.createElement('span');
        roleTitle.className = 'fw-bold text-dark';
        roleTitle.innerHTML = '<i class="fas fa-layer-group text-primary me-2"></i>نطاق دور: ';
        roleTitle.appendChild(document.createTextNode(roleLabel));
        var allGradesButton = document.createElement('button');
        allGradesButton.type = 'button';
        allGradesButton.className = 'btn btn-sm btn-outline-primary py-0 px-2 small select-staff-all-grades-btn';
        allGradesButton.innerHTML = '<i class="fas fa-check-double me-1"></i>تحديد كل الصفوف';
        roleHeader.appendChild(roleTitle);
        roleHeader.appendChild(allGradesButton);
        roleSection.appendChild(roleHeader);

        var roleContent = document.createElement('div');
        roleContent.className = 'staff-scope-role-list';
        roleSection.appendChild(roleContent);
        scopeContent.appendChild(roleSection);

        var selectedGrades = (data.scope.grade_ids || []).map(String);
        var selectedClasses = (data.scope.explicit_class_ids || []).map(String);
        var stagesMap = {};
        var stagesOrder = [];
        data.grades.forEach(function (grade) {
            var sKey = String(grade.stage_id || 'other');
            var sName = grade.stage_name || 'غير محددة';
            if (!stagesMap[sKey]) {
                stagesMap[sKey] = { id: sKey, name: sName, grades: [] };
                stagesOrder.push(sKey);
            }
            stagesMap[sKey].grades.push(grade);
        });

        stagesOrder.forEach(function (sKey) {
            var stage = stagesMap[sKey];
            var stageWrapper = document.createElement('div');
            stageWrapper.className = 'assignment-stage-group mb-3';
            stageWrapper.setAttribute('data-stage-id', sKey);

            var stageHeader = document.createElement('div');
            stageHeader.className = 'd-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 rounded border bg-light shadow-sm';
            var titleDiv = document.createElement('div');
            titleDiv.className = 'd-flex align-items-center gap-2';
            var icon = document.createElement('i');
            icon.className = 'fas fa-graduation-cap text-primary';
            var titleText = document.createElement('span');
            titleText.className = 'fw-bold text-dark';
            titleText.textContent = stage.name;
            var stageBadge = document.createElement('span');
            stageBadge.className = 'badge bg-secondary';
            stageBadge.textContent = stage.grades.length + ' صفوف';
            titleDiv.appendChild(icon);
            titleDiv.appendChild(titleText);
            titleDiv.appendChild(stageBadge);
            var actionBtn = document.createElement('button');
            actionBtn.type = 'button';
            actionBtn.className = 'btn btn-sm btn-outline-primary py-0 px-2 small select-assignment-stage-btn';
            actionBtn.innerHTML = '<i class="fas fa-check-double me-1"></i>تحديد المرحلة';
            stageHeader.appendChild(titleDiv);
            stageHeader.appendChild(actionBtn);
            stageWrapper.appendChild(stageHeader);

            var gradesRow = document.createElement('div');
            gradesRow.className = 'row g-2';
            stage.grades.forEach(function (grade) {
                var column = document.createElement('div');
                column.className = 'col-md-6 col-xl-4';
                var card = document.createElement('div');
                card.className = 'border rounded-3 p-2 bg-white shadow-sm h-100 assignment-grade-card';
                card.setAttribute('data-grade-id', String(grade.id));
                var cardHeading = document.createElement('div');
                cardHeading.className = 'd-flex align-items-center justify-content-between gap-2 p-2 mb-2 bg-light rounded border-start border-3 border-primary';
                var gradeLabelWrap = document.createElement('div');
                gradeLabelWrap.className = 'd-flex align-items-center gap-2';
                var gradeInput = document.createElement('input');
                gradeInput.type = 'checkbox';
                gradeInput.name = 'scopes[' + roleKey + '][grade_ids][]';
                gradeInput.className = 'form-check-input assignment-grade-checkbox staff-grade-scope mt-0';
                gradeInput.value = String(grade.id);
                gradeInput.id = 'staff_scope_' + safeRoleKey + '_grade_' + grade.id;
                var classes = (data.classes || []).filter(function (item) { return String(item.grade_id) === String(grade.id); });
                gradeInput.disabled = classes.length === 0;
                gradeInput.checked = selectedGrades.indexOf(String(grade.id)) !== -1;
                var label = document.createElement('label');
                label.className = 'fw-bold text-dark cursor-pointer mb-0';
                label.htmlFor = gradeInput.id;
                label.textContent = grade.grade_name;
                var scopeBadge = document.createElement('span');
                scopeBadge.className = 'badge bg-light text-dark border assignment-grade-scope-badge';
                scopeBadge.textContent = 'غير محدد';
                gradeLabelWrap.appendChild(gradeInput);
                gradeLabelWrap.appendChild(label);
                cardHeading.appendChild(gradeLabelWrap);
                cardHeading.appendChild(scopeBadge);
                card.appendChild(cardHeading);

                var classOptions = document.createElement('div');
                classOptions.className = 'px-2 py-1 assignment-class-options';
                classOptions.setAttribute('data-grade-id', String(grade.id));
                if (!classes.length) {
                    var empty = document.createElement('span');
                    empty.className = 'text-muted small';
                    empty.textContent = 'لا توجد فصول نشطة؛ لا يمكن إسناد هذا الصف حتى يُنشأ فصل نشط.';
                    classOptions.appendChild(empty);
                } else {
                    var classRow = document.createElement('div');
                    classRow.className = 'row row-cols-2 g-2';
                    classes.forEach(function (item) {
                        var classCol = document.createElement('div');
                        classCol.className = 'col';
                        var wrapper = document.createElement('div');
                        wrapper.className = 'form-check mb-1';
                        var input = document.createElement('input');
                        input.type = 'checkbox';
                        input.name = 'scopes[' + roleKey + '][class_ids][]';
                        input.className = 'form-check-input assignment-class-checkbox staff-class-scope';
                        input.value = String(item.id);
                        input.id = 'staff_scope_' + safeRoleKey + '_class_' + item.id;
                        input.setAttribute('data-grade-id', String(grade.id));
                        input.checked = selectedClasses.indexOf(String(item.id)) !== -1;
                        var classLabel = document.createElement('label');
                        classLabel.className = 'form-check-label small fw-semibold cursor-pointer';
                        classLabel.htmlFor = input.id;
                        classLabel.textContent = item.name;
                        wrapper.appendChild(input);
                        wrapper.appendChild(classLabel);
                        classCol.appendChild(wrapper);
                        classRow.appendChild(classCol);
                    });
                    classOptions.appendChild(classRow);
                }
                card.appendChild(classOptions);
                column.appendChild(card);
                gradesRow.appendChild(column);
                gradeInput.addEventListener('change', function () {
                    syncScopeGrade(card, true);
                    syncStaffScopeSection(roleSection);
                });
                card.querySelectorAll('.assignment-class-checkbox').forEach(function (input) {
                    input.addEventListener('change', function () {
                        if (input.checked) gradeInput.checked = false;
                        syncScopeGrade(card, false);
                        syncStaffScopeSection(roleSection);
                    });
                });
                syncScopeGrade(card, false);
            });

            actionBtn.addEventListener('click', function () {
                var stageGrades = Array.from(stageWrapper.querySelectorAll('.assignment-grade-checkbox:not(:disabled)'));
                var allSelected = stageGrades.length > 0 && stageGrades.every(function (input) { return input.checked; });
                stageGrades.forEach(function (input) {
                    input.checked = !allSelected;
                    var card = input.closest('.assignment-grade-card');
                    if (card) syncScopeGrade(card, true);
                });
                syncStaffScopeSection(roleSection);
            });
            stageWrapper.appendChild(gradesRow);
            roleContent.appendChild(stageWrapper);
        });

        allGradesButton.addEventListener('click', function () {
            var gradeInputs = Array.from(roleSection.querySelectorAll('.assignment-grade-checkbox:not(:disabled)'));
            var allSelected = gradeInputs.length > 0 && gradeInputs.every(function (input) { return input.checked; });
            gradeInputs.forEach(function (input) {
                input.checked = !allSelected;
                var card = input.closest('.assignment-grade-card');
                if (card) syncScopeGrade(card, true);
            });
            syncStaffScopeSection(roleSection);
        });
        syncStaffScopeSection(roleSection);
    }

    function requestStaffScope(roleKey) {
        var userId = document.getElementById('role_user_id').value;
        if (!userId) return Promise.reject(new Error('تعذر تحديد العامل.'));
        var formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('action', 'get');
        formData.append('staff_id', userId);
        formData.append('role_key', roleKey);
        return fetch('ajax_staff_scope.php', { method: 'POST', body: formData }).then(function (response) {
            return response.json().then(function (data) { if (!response.ok || !data.success) throw new Error(data.message || 'تعذر تنفيذ العملية.'); return data; });
        });
    }

    function loadStaffScope(roleKey, roleLabel) {
        var userId = document.getElementById('role_user_id').value;
        if (!userId || loadedScopeRoles.has(roleKey)) return;
        loadedScopeRoles.add(roleKey);
        pendingScopeLoads++;
        saveRoleAccess.disabled = true;
        showScopeStatus('info', 'جاري تحميل الصفوف والفصول…');
        requestStaffScope(roleKey).then(function (data) {
            renderStaffScope(data, roleKey, roleLabel);
        }).catch(function (error) {
            loadedScopeRoles.delete(roleKey);
            showScopeStatus('danger', error.message);
        }).finally(function () {
            pendingScopeLoads = Math.max(0, pendingScopeLoads - 1);
            if (pendingScopeLoads === 0) {
                if (loadedScopeRoles.size > 0 && scopeStatus.classList.contains('alert-info')) {
                    scopeStatus.className = 'alert d-none';
                }
                saveRoleAccess.disabled = false;
            }
        });
    }

    function syncRoleAccess(changedInput) {
        var employeeInput = roleInputs.find(function (input) { return input.value === 'employee'; });
        var superAdminInput = roleInputs.find(function (input) { return input.value === 'super_admin'; });
        var protectSelfSuperAdmin = roleAccessForm.dataset.protectSelfSuperAdmin === '1';
        if (protectSelfSuperAdmin) {
            if (employeeInput) employeeInput.checked = false;
            if (superAdminInput) superAdminInput.checked = true;
        } else if (changedInput && changedInput.value === 'employee' && changedInput.checked) {
            roleInputs.forEach(function (input) {
                if (input !== employeeInput) input.checked = false;
            });
        } else if (changedInput && changedInput.value !== 'employee' && changedInput.checked && employeeInput) {
            employeeInput.checked = false;
        }
        var selectedInputs = roleInputs.filter(function (input) { return input.checked; });
        var selectedRoles = selectedInputs.map(function (input) { return input.value; });
        var isEmployee = selectedRoles.length === 1 && selectedRoles[0] === 'employee';
        var isTeacher = selectedRoles.indexOf('teacher') !== -1;
        var scopedInputs = selectedInputs.filter(function (input) {
            return input.getAttribute('data-requires-scope') === '1';
        });
        employeeNotice.classList.toggle('d-none', !isEmployee);
        teacherSupervisorField.classList.toggle('d-none', !isTeacher);
        supervisorInput.disabled = !isTeacher;
        if (!isTeacher) supervisorInput.checked = false;
        staffScopeSection.classList.toggle('d-none', scopedInputs.length === 0);

        var previousPrimary = primaryRoleInput.value || primaryRoleInput.getAttribute('data-current-primary') || '';
        primaryRoleInput.innerHTML = '';
        selectedInputs.forEach(function (input) {
            var option = document.createElement('option');
            option.value = input.value;
            option.textContent = input.getAttribute('data-role-label') || input.value;
            primaryRoleInput.appendChild(option);
        });
        if (selectedRoles.indexOf(previousPrimary) !== -1) {
            primaryRoleInput.value = previousPrimary;
        }
        if (protectSelfSuperAdmin) {
            primaryRoleInput.value = 'super_admin';
        }
        primaryRoleInput.disabled = protectSelfSuperAdmin;
        document.getElementById('primaryRoleField').classList.toggle('d-none', selectedInputs.length <= 1);

        Array.from(scopeContent.querySelectorAll('[data-scope-role]')).forEach(function (section) {
            var sectionRole = section.getAttribute('data-scope-role');
            if (!selectedRoles.includes(sectionRole)) {
                section.remove();
                loadedScopeRoles.delete(sectionRole);
            }
        });
        scopedInputs.forEach(function (input) {
            loadStaffScope(input.value, input.getAttribute('data-role-label') || input.value);
        });
        if (pendingScopeLoads === 0) saveRoleAccess.disabled = false;
    }

    roleInputs.forEach(function (input) {
        input.addEventListener('change', function () { syncRoleAccess(input); });
    });
    if (roleAccessForm) {
        roleAccessForm.addEventListener('submit', function (event) {
            var selectedInputs = roleInputs.filter(function (input) { return input.checked; });
            if (roleAccessForm.dataset.protectSelfSuperAdmin === '1'
                && !selectedInputs.some(function (input) { return input.value === 'super_admin'; })) {
                event.preventDefault();
                showScopeStatus('warning', 'يجب الإبقاء على دور مدير النظام الأعلى في حسابك الحالي.');
                return;
            }
            if (selectedInputs.length === 0) {
                event.preventDefault();
                showScopeStatus('warning', 'حدد دوراً واحداً على الأقل للعامل.');
                return;
            }
            for (var i = 0; i < selectedInputs.length; i++) {
                var input = selectedInputs[i];
                if (input.getAttribute('data-requires-scope') !== '1') continue;
                var section = scopeContent.querySelector('[data-scope-role="' + CSS.escape(input.value) + '"]');
                var selected = section ? section.querySelectorAll('.staff-grade-scope:checked, .staff-class-scope:checked:not(:disabled)') : [];
                if (selected.length === 0) {
                    event.preventDefault();
                    showScopeStatus('warning', 'حدد صفاً أو فصلاً واحداً على الأقل لدور «' + (input.getAttribute('data-role-label') || input.value) + '».');
                    return;
                }
            }
            if (window.EduCoreDataTableState && typeof window.EduCoreDataTableState.submitAjax === 'function') {
                window.EduCoreDataTableState.submitAjax(roleAccessForm, event);
            }
        });
    }

});
</script>

<?php require_once __DIR__ . '/../includes/staff_bulk_modals.php'; ?>

<?php require_once '../includes/admin_footer.php'; ?>
