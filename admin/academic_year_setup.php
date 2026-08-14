<?php
/**
 * تهيئة عام دراسي جديد
 */
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/NewYearWizard.php';
require_once '../classes/NewYearRolloverService.php';
require_once '../classes/RecoveryBackupService.php';
require_once '../classes/user.php';

Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();

function yearSetupVerifyAdminPassword(PDO $db, string $password): void
{
    $user = new User($db);
    $user->id = (int) ($_SESSION['user_id'] ?? 0);
    if ($password === '' || !$user->verifyPassword($password)) {
        throw new RuntimeException('كلمة مرور المدير غير صحيحة.');
    }
}

function yearSetupPublicError(Throwable $error, string $fallback): string
{
    error_log('Academic year setup failed [' . get_class($error) . ']: ' . $error->getMessage());
    if (($error instanceof InvalidArgumentException || $error instanceof RuntimeException)
        && !($error instanceof PDOException)) {
        return $error->getMessage();
    }
    return $fallback;
}

/** @return array<int, int> */
function yearSetupNormalizeRetainedStudentIds(mixed $rawIds): array
{
    if ($rawIds === null || $rawIds === '') {
        return [];
    }
    if (!is_array($rawIds)) {
        throw new InvalidArgumentException('صيغة الطلاب الراسبين غير صالحة. أعد تحميل الصفحة ثم حاول مرة أخرى.');
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        if (!is_scalar($rawId)) {
            throw new InvalidArgumentException('صيغة الطلاب الراسبين غير صالحة.');
        }
        $rawId = trim((string) $rawId);
        if ($rawId === '') {
            continue;
        }
        if (!ctype_digit($rawId)) {
            throw new InvalidArgumentException('معرف أحد الطلاب الراسبين غير صالح.');
        }
        $studentId = (int) $rawId;
        if ($studentId > 0) {
            $ids[$studentId] = $studentId;
        }
    }

    return array_values($ids);
}

function yearSetupCompactPreviewForSession(array $preview): array
{
    // The page renders grouped blockers only; retaining every raw student blocker
    // duplicates data and can make file-backed sessions unnecessarily large.
    unset($preview['blockers']);
    return $preview;
}

/**
 * Recovery creation and an actual isolated restore are maintenance operations,
 * not ordinary web requests. Give only this explicit admin action enough time
 * to finish and keep it running if the browser tab is closed.
 */
function yearSetupPrepareRecoveryRuntime(int $seconds = 900): void
{
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(true);
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit($seconds);
    }

    $effectiveLimit = (int) ini_get('max_execution_time');
    if ($effectiveLimit > 0 && $effectiveLimit < $seconds) {
        throw new RuntimeException(
            'تعذر رفع مهلة تنفيذ اختبار الاستعادة على الخادم. اضبط max_execution_time إلى 900 ثانية ثم أعد المحاولة.'
        );
    }
}

function yearSetupTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function yearSetupColumnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

/**
 * User-facing recovery guidance for preflight blockers.
 * The rollover service remains the authority for readiness and blocker codes.
 */
function yearSetupBlockerGuidance(string $code): array
{
    $guidance = [
        'student_placement_missing' => [
            'text' => 'إن كان الطالب للتجربة فصنّف حسابه من صفحة حسابات الطلاب. وإن كان طالبًا حقيقيًا أكمل مرحلته وصفه من ملف الطالب.',
            'href' => '#yearSetupStudentsCard',
            'label' => 'مراجعة الطلاب',
        ],
        'decision_missing' => [
            'text' => 'راجع استثناءات الطلاب ثم اضغط «حفظ القرارات وفحص الجاهزية» مرة أخرى.',
            'href' => '#yearSetupStudentsCard',
            'label' => 'العودة إلى قرارات الطلاب',
        ],
        'decision_stale' => [
            'text' => 'تغيرت بيانات طالب بعد المعاينة. احفظ قرارات الطلاب من جديد لتحديثها.',
            'href' => '#yearSetupStudentsCard',
            'label' => 'تحديث القرارات',
        ],
        'decision_pending' => [
            'text' => 'اختر قرارًا نهائيًا للطالب أو اتركه خارج التنفيذ حتى تُحسم حالته.',
            'href' => '#yearSetupStudentsCard',
            'label' => 'مراجعة القرار',
        ],
        'test_decision_mismatch' => [
            'text' => 'احفظ قرارات الطلاب مرة أخرى ليُسجل الاستبعاد التجريبي صراحة.',
            'href' => '#yearSetupStudentsCard',
            'label' => 'تحديث القرارات',
        ],
        'decision_target_invalid' => [
            'text' => 'راجع قاعدة انتقال صف الطالب واحفظ قواعد الانتقال ثم أعد المعاينة.',
            'href' => '#yearSetupRulesCard',
            'label' => 'مراجعة قواعد الصفوف',
        ],
        'decision_invalid' => [
            'text' => 'أعد اختيار قرار الطالب من قائمة الاستثناءات ثم احفظ المعاينة.',
            'href' => '#yearSetupStudentsCard',
            'label' => 'مراجعة القرار',
        ],
        'promotion_rule_missing' => [
            'text' => 'اختر الصف التالي أو التخرج لهذا الصف، ثم احفظ قواعد الانتقال.',
            'href' => '#yearSetupRulesCard',
            'label' => 'إكمال قواعد الصفوف',
        ],
        'promotion_rule_invalid' => [
            'text' => 'أصلح الصف الهدف في قاعدة الانتقال ثم احفظ القواعد من جديد.',
            'href' => '#yearSetupRulesCard',
            'label' => 'إصلاح قواعد الصفوف',
        ],
        'promotion_rule_cycle' => [
            'text' => 'توجد دائرة في انتقال الصفوف. اجعل كل صف ينتقل إلى صف تالٍ أو إلى التخرج.',
            'href' => '#yearSetupRulesCard',
            'label' => 'إصلاح قواعد الصفوف',
        ],
        'class_mapping_missing' => [
            'text' => 'راجع اسم الفصل والصف الهدف ثم احفظ خريطة الفصول كاملة.',
            'href' => '#yearSetupClassesCard',
            'label' => 'إكمال خريطة الفصول',
        ],
        'class_mapping_stale' => [
            'text' => 'تغيرت قاعدة صف بعد حفظ الخريطة. احفظ خريطة الفصول من جديد.',
            'href' => '#yearSetupClassesCard',
            'label' => 'تحديث خريطة الفصول',
        ],
        'class_target_name_duplicate' => [
            'text' => 'غيّر أحد أسماء الفصول المتكررة داخل الصف الهدف.',
            'href' => '#yearSetupClassesCard',
            'label' => 'مراجعة أسماء الفصول',
        ],
        'class_capacity_exceeded' => [
            'text' => 'ارفع السعة أو اختر التسكين اليدوي لهذا الفصل ثم احفظ الخريطة وأعد المعاينة.',
            'href' => '#yearSetupClassesCard',
            'label' => 'معالجة سعة الفصل',
        ],
        'class_curriculum_mapping_missing' => [
            'text' => 'أعد تفعيل فصل مقابل للصف حتى يمكن نسخ إسنادات المواد دون فقد أو تخمين.',
            'href' => '#yearSetupClassesCard',
            'label' => 'إكمال فصول الصف',
        ],
        'calendar_missing' => [
            'text' => 'أكمل الفصول الدراسية والأسابيع في تقويم العام المصدر.',
            'href' => 'assessment_calendar.php',
            'label' => 'فتح التقويم الدراسي',
        ],
        'same_year' => [
            'text' => 'اختر عامين مختلفين: العام الحالي مصدرًا والعام الجديد هدفًا.',
            'href' => '#yearSetupPairCard',
            'label' => 'تغيير الأعوام',
        ],
        'target_active' => [
            'text' => 'العام الهدف يجب أن يكون عامًا جديدًا غير نشط قبل التهيئة.',
            'href' => 'academic_years.php',
            'label' => 'فتح الأعوام الدراسية',
        ],
        'year_dates_missing' => [
            'text' => 'أدخل تاريخ البداية والنهاية للعام المصدر والعام الهدف.',
            'href' => 'academic_years.php',
            'label' => 'تعديل تواريخ الأعوام',
        ],
        'year_dates_invalid' => [
            'text' => 'صحح ترتيب تواريخ العامين؛ يجب أن يبدأ العام الهدف بعد العام المصدر.',
            'href' => 'academic_years.php',
            'label' => 'مراجعة تواريخ الأعوام',
        ],
        'target_not_empty' => [
            'text' => 'العام الهدف يحتوي بيانات بالفعل. اختر عامًا فارغًا أو راجع البيانات الموجودة قبل المتابعة.',
            'href' => 'academic_years.php',
            'label' => 'مراجعة العام الهدف',
        ],
    ];

    return $guidance[$code] ?? [
        'text' => 'راجع تفاصيل المشكلة ثم أعد فحص الجاهزية. لن يسمح النظام بالتنفيذ قبل زوال المانع.',
        'href' => '#yearSetupStudentsCard',
        'label' => 'العودة إلى المراجعة',
    ];
}

// --- استخراج رسائل الجلسة ---
$success_message = $_SESSION['settings_success'] ?? null;
$error_message = $_SESSION['settings_error'] ?? null;
unset($_SESSION['settings_success'], $_SESSION['settings_error']);

// ==========================================
// معالجة الإجراءات (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrfToken = (string) ($_SESSION['csrf_token'] ?? '');
    if ($sessionCsrfToken === '' || $csrfToken === '' || !hash_equals($sessionCsrfToken, $csrfToken)) {
        $_SESSION['settings_error'] = "خطأ في التحقق من الأمان. يرجى إعادة المحاولة.";
        header("Location: academic_year_setup.php");
        exit();
    }

    // ---- حفظ قواعد الانتقال الصريحة ----
    if (isset($_POST['save_promotion_rules'])) {
        try {
            $sourceYearId = (int) ($_POST['source_year_id'] ?? 0);
            $targetYearId = (int) ($_POST['target_year_id'] ?? 0);
            (new NewYearRolloverService($db))->savePromotionRules(
                $sourceYearId,
                $targetYearId,
                is_array($_POST['promotion_rules'] ?? null) ? $_POST['promotion_rules'] : [],
                (int) ($_SESSION['user_id'] ?? 0) ?: null
            );
            $_SESSION['year_setup_form'] = [
                'source_year_id' => $sourceYearId,
                'target_year_id' => $targetYearId,
                'retained_student_ids' => [],
                'student_decisions' => [],
            ];
            unset(
                $_SESSION['year_setup_preview'],
                $_SESSION['year_setup_backup_key'],
                $_SESSION['year_setup_run_key']
            );
            $_SESSION['settings_success'] = 'تم حفظ قواعد انتقال الصفوف بالمعرّفات. راجع قرارات الطلاب ثم أنشئ المعاينة.';
        } catch (Throwable $e) {
            $_SESSION['settings_error'] = yearSetupPublicError($e, 'تعذر حفظ قواعد انتقال الصفوف.');
        }
        $source = (int) ($_POST['source_year_id'] ?? 0);
        $target = (int) ($_POST['target_year_id'] ?? 0);
        header("Location: academic_year_setup.php?setup_source_year_id={$source}&setup_target_year_id={$target}");
        exit();
    }

    // ---- حفظ خريطة انتقال الفصول وتسـكين الناجحين ----
    elseif (isset($_POST['save_class_mappings'])) {
        try {
            $sourceYearId = (int) ($_POST['source_year_id'] ?? 0);
            $targetYearId = (int) ($_POST['target_year_id'] ?? 0);
            (new NewYearRolloverService($db))->saveClassMappings(
                $sourceYearId,
                $targetYearId,
                is_array($_POST['class_mappings'] ?? null) ? $_POST['class_mappings'] : [],
                (int) ($_SESSION['user_id'] ?? 0) ?: null
            );
            $storedForm = is_array($_SESSION['year_setup_form'] ?? null)
                ? $_SESSION['year_setup_form']
                : [];
            $storedForm['source_year_id'] = $sourceYearId;
            $storedForm['target_year_id'] = $targetYearId;
            $_SESSION['year_setup_form'] = $storedForm;
            unset(
                $_SESSION['year_setup_preview'],
                $_SESSION['year_setup_backup_key'],
                $_SESSION['year_setup_run_key']
            );
            $_SESSION['settings_success'] = 'تم حفظ خريطة الفصول. راجع استثناءات الطلاب ثم أنشئ المعاينة.';
        } catch (Throwable $e) {
            $_SESSION['settings_error'] = yearSetupPublicError($e, 'تعذر حفظ خريطة انتقال الفصول.');
        }
        $source = (int) ($_POST['source_year_id'] ?? 0);
        $target = (int) ($_POST['target_year_id'] ?? 0);
        header("Location: academic_year_setup.php?setup_source_year_id={$source}&setup_target_year_id={$target}");
        exit();
    }

    // ---- إعداد قرارات الطلاب ومعاينة تهيئة عام جديد ----
    elseif (isset($_POST['preview_year_setup'])) {
        $source = (int) ($_POST['source_year_id'] ?? 0);
        $target = (int) ($_POST['target_year_id'] ?? 0);

        try {
            $decisionOverrides = is_array($_POST['student_decisions'] ?? null)
                ? $_POST['student_decisions']
                : [];
            $retainedStudentIds = yearSetupNormalizeRetainedStudentIds($_POST['retained_student_ids'] ?? []);
            $_SESSION['year_setup_form'] = [
                'source_year_id' => $source,
                'target_year_id' => $target,
                'retained_student_ids' => $retainedStudentIds,
                'student_decisions' => $decisionOverrides,
            ];

            $sourceYearId = $source;
            $targetYearId = $target;
            if ($sourceYearId <= 0 || $targetYearId <= 0 || $sourceYearId === $targetYearId) {
                throw new InvalidArgumentException('يرجى اختيار عام مصدر وهدف صحيحين ومختلفين.');
            }

            unset($_SESSION['year_setup_backup_key'], $_SESSION['year_setup_run_key']);
            $preview = NewYearWizard::prepareAndPreview(
                $db,
                $sourceYearId,
                $targetYearId,
                $decisionOverrides,
                $retainedStudentIds,
                (int) ($_SESSION['user_id'] ?? 0) ?: null
            );
            $_SESSION['year_setup_preview'] = yearSetupCompactPreviewForSession($preview);
            $ready = (bool) ($_SESSION['year_setup_preview']['ready'] ?? false);
            $_SESSION['settings_success'] = $ready
                ? 'اكتمل فحص الجاهزية. أنشئ نسخة تعافٍ واختبر استعادتها قبل التنفيذ.'
                : 'اكتمل الفحص وظهرت موانع يجب حلها قبل التنفيذ.';
        } catch (Throwable $e) {
            unset($_SESSION['year_setup_preview']);
            $_SESSION['settings_error'] = yearSetupPublicError($e, 'تعذر إكمال فحص الجاهزية.');
        }

        header("Location: academic_year_setup.php?setup_source_year_id={$source}&setup_target_year_id={$target}");
        exit();
    }

    elseif (isset($_POST['create_recovery_backup'])) {
        try {
            yearSetupVerifyAdminPassword($db, (string) ($_POST['admin_password'] ?? ''));
            if (!hash_equals('نسخة آمنة', trim((string) ($_POST['confirm_text'] ?? '')))) {
                throw new InvalidArgumentException('اكتب عبارة "نسخة آمنة" بدقة.');
            }
            yearSetupPrepareRecoveryRuntime();
            $service = new RecoveryBackupService($db);
            $receipt = $service->createPackage((int) ($_SESSION['user_id'] ?? 0) ?: null);
            $testDatabase = 'educore_restore_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_test';
            $receipt = $service->verifyPackage(
                (string) $receipt['backup_key'],
                $testDatabase,
                (int) ($_SESSION['user_id'] ?? 0) ?: null
            );
            $_SESSION['year_setup_backup_key'] = (string) $receipt['backup_key'];
            $_SESSION['settings_success'] = 'تم إنشاء حزمة التعافي واستعادتها والتحقق منها في قاعدة معزولة بنجاح.';
        } catch (Throwable $e) {
            unset($_SESSION['year_setup_backup_key']);
            $_SESSION['settings_error'] = yearSetupPublicError($e, 'فشل إنشاء النسخة أو اختبار استعادتها. لم تُمنح موافقة للتنفيذ.');
        }
        $source = (int) ($_SESSION['year_setup_form']['source_year_id'] ?? 0);
        $target = (int) ($_SESSION['year_setup_form']['target_year_id'] ?? 0);
        header("Location: academic_year_setup.php?setup_source_year_id={$source}&setup_target_year_id={$target}");
        exit();
    }

    elseif (isset($_POST['execute_year_setup'])) {
        $sourceYearId = (int)($_POST['source_year_id'] ?? 0);
        $targetYearId = (int)($_POST['target_year_id'] ?? 0);
        $confirmText = trim((string)($_POST['confirm_text'] ?? ''));
        $options = [
            'backup_key' => (string) ($_SESSION['year_setup_backup_key'] ?? ''),
            'actor_id' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
        ];

        try {
            $retainedStudentIds = yearSetupNormalizeRetainedStudentIds($_POST['retained_student_ids'] ?? []);
            yearSetupVerifyAdminPassword($db, (string) ($_POST['admin_password'] ?? ''));
            if (!hash_equals('أؤكد', $confirmText)) {
                throw new InvalidArgumentException('يجب كتابة كلمة التأكيد بدقة قبل تنفيذ التهيئة.');
            }

            if ($sourceYearId <= 0 || $targetYearId <= 0 || $sourceYearId === $targetYearId) {
                throw new InvalidArgumentException('بيانات العام المصدر أو الهدف غير صالحة.');
            }

            $report = NewYearWizard::execute($db, $sourceYearId, $targetYearId, $options, $retainedStudentIds);
            $_SESSION['year_setup_run_key'] = (string) $report['run_key'];
            $_SESSION['settings_success'] = sprintf(
                "اكتمل إنشاء بيانات العام دون تفعيله. فصول: %d | طلاب مُرقّون: %d | سُكّنوا تلقائيًا: %d | بلا فصل: %d | راسبون: %d | خريجون: %d. نفّذ التحقق المستقل الآن.",
                $report['classes_copied'],
                $report['students_promoted'],
                $report['students_auto_placed'],
                $report['students_unassigned_promoted'],
                $report['students_retained'],
                $report['students_graduating']
            );
        } catch (Throwable $e) {
            $_SESSION['settings_error'] = yearSetupPublicError($e, 'تعذر تنفيذ التهيئة بأمان، ولم تُفعّل أي بيانات.');
        }

        header("Location: academic_year_setup.php");
        exit();
    }

    elseif (isset($_POST['verify_year_setup'])) {
        try {
            $runKey = (string) ($_SESSION['year_setup_run_key'] ?? '');
            $verification = (new NewYearRolloverService($db))->verifyRun($runKey);
            if (empty($verification['passed'])) {
                throw new RuntimeException('فشل التحقق المستقل؛ التفعيل محظور.');
            }
            $_SESSION['settings_success'] = 'نجح التحقق المستقل من التهيئة. يمكنك الآن التفعيل أو الرجوع قبل التفعيل.';
        } catch (Throwable $e) {
            $_SESSION['settings_error'] = yearSetupPublicError($e, 'تعذر التحقق من تشغيل التهيئة.');
        }
        header('Location: academic_year_setup.php');
        exit();
    }

    elseif (isset($_POST['rollback_year_setup'])) {
        try {
            yearSetupVerifyAdminPassword($db, (string) ($_POST['admin_password'] ?? ''));
            if (!hash_equals('تراجع', trim((string) ($_POST['confirm_text'] ?? '')))) {
                throw new InvalidArgumentException('اكتب كلمة "تراجع" بدقة.');
            }
            $result = (new NewYearRolloverService($db))->rollback(
                (string) ($_SESSION['year_setup_run_key'] ?? ''),
                (int) ($_SESSION['user_id'] ?? 0) ?: null
            );
            unset($_SESSION['year_setup_run_key'], $_SESSION['year_setup_backup_key'], $_SESSION['year_setup_preview']);
            $_SESSION['settings_success'] = 'تم الرجوع عن التهيئة وحذف ' . (int) $result['deleted_manifest_rows'] . ' سجلاً مملوكاً للتشغيل فقط.';
        } catch (Throwable $e) {
            $_SESSION['settings_error'] = yearSetupPublicError($e, 'تعذر الرجوع الآمن عن التهيئة.');
        }
        header('Location: academic_year_setup.php');
        exit();
    }

    elseif (isset($_POST['activate_year_setup'])) {
        try {
            yearSetupVerifyAdminPassword($db, (string) ($_POST['admin_password'] ?? ''));
            if (!hash_equals('تفعيل', trim((string) ($_POST['confirm_text'] ?? '')))) {
                throw new InvalidArgumentException('اكتب كلمة "تفعيل" بدقة.');
            }
            (new NewYearRolloverService($db))->activate(
                (string) ($_SESSION['year_setup_run_key'] ?? ''),
                (int) ($_SESSION['user_id'] ?? 0) ?: null
            );
            unset($_SESSION['year_setup_backup_key'], $_SESSION['year_setup_preview'], $_SESSION['year_setup_form']);
            $_SESSION['settings_success'] = 'تم تفعيل العام الجديد وقفل العام المصدر ضد الكتابات التاريخية المحمية.';
        } catch (Throwable $e) {
            $_SESSION['settings_error'] = yearSetupPublicError($e, 'تعذر تفعيل العام الجديد.');
        }
        header('Location: academic_year_setup.php');
        exit();
    }
}

// ==========================================
// جلب البيانات
// ==========================================
$academicYears = [];
$academicYearStudentCounts = [];
try {
    $academicYears = AcademicYear::getAll($db);
    $academicYearStudentCounts = AcademicYear::countEnrollmentsByYear(
        $db,
        array_map(static fn(array $year): int => (int) $year['id'], $academicYears)
    );
} catch (Exception $e) {
    $academicYears = [];
}

$yearSetupPreview = $_SESSION['year_setup_preview'] ?? null;
$yearSetupForm = $_SESSION['year_setup_form'] ?? [];
$activeYearId = 0;
foreach ($academicYears as $ay) {
    if ((int)($ay['is_active'] ?? 0) === 1) {
        $activeYearId = (int)$ay['id'];
        break;
    }
}
$yearSetupSourceYearId = (int)($_GET['setup_source_year_id'] ?? ($yearSetupForm['source_year_id'] ?? $activeYearId));
$yearSetupTargetYearId = (int)($_GET['setup_target_year_id'] ?? ($yearSetupForm['target_year_id'] ?? 0));
$yearSetupRetainedIds = array_map('intval', $yearSetupForm['retained_student_ids'] ?? []);
$yearSetupRetainedSet = array_fill_keys($yearSetupRetainedIds, true);
$yearSetupDecisionOverrides = is_array($yearSetupForm['student_decisions'] ?? null)
    ? $yearSetupForm['student_decisions']
    : [];
$yearSetupSchemaReady = yearSetupTableExists($db, 'recovery_backups')
    && yearSetupTableExists($db, 'academic_year_rollover_runs')
    && yearSetupTableExists($db, 'academic_year_rollover_items')
    && yearSetupTableExists($db, 'grade_promotion_rules')
    && yearSetupTableExists($db, 'class_rollover_mappings')
    && yearSetupTableExists($db, 'student_promotion_decisions')
    && yearSetupColumnExists($db, 'grades', 'is_experimental')
    && yearSetupColumnExists($db, 'stages', 'is_experimental')
    && yearSetupColumnExists($db, 'classes', 'is_experimental')
    && yearSetupColumnExists($db, 'users', 'is_test_account')
    && yearSetupColumnExists($db, 'student_enrollments', 'promotion_decision_id')
    && yearSetupColumnExists($db, 'student_enrollments', 'academic_status')
    && yearSetupColumnExists($db, 'student_promotion_decisions', 'enrollment_status')
    && yearSetupColumnExists($db, 'student_promotion_decisions', 'academic_status');
$yearSetupBackup = null;
$yearSetupRun = null;
if ($yearSetupSchemaReady && !empty($_SESSION['year_setup_backup_key'])) {
    $receiptStmt = $db->prepare('SELECT * FROM recovery_backups WHERE backup_key = ? LIMIT 1');
    $receiptStmt->execute([(string) $_SESSION['year_setup_backup_key']]);
    $yearSetupBackup = $receiptStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($yearSetupSchemaReady && !empty($_SESSION['year_setup_run_key'])) {
    $runStmt = $db->prepare('SELECT * FROM academic_year_rollover_runs WHERE run_key = ? LIMIT 1');
    $runStmt->execute([(string) $_SESSION['year_setup_run_key']]);
    $yearSetupRun = $runStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$yearSetupStudentGroups = [];
$yearSetupRuleMatrix = ['grades' => [], 'rules' => []];
$yearSetupClassMatrix = ['mappings' => [], 'ready' => false, 'expected_count' => 0];
try {
    if ($yearSetupSchemaReady && $yearSetupSourceYearId > 0 && $yearSetupTargetYearId > 0
        && $yearSetupSourceYearId !== $yearSetupTargetYearId) {
        $rolloverService = new NewYearRolloverService($db);
        $yearSetupRuleMatrix = $rolloverService->promotionRuleMatrix($yearSetupSourceYearId, $yearSetupTargetYearId);
        $yearSetupStudentGroups = NewYearWizard::getStudentsGroupedByClass(
            $db,
            $yearSetupSourceYearId,
            $yearSetupTargetYearId
        );
    }
} catch (Exception $e) {
    $yearSetupStudentGroups = [];
}

$yearSetupHasValidPair = $yearSetupSourceYearId > 0
    && $yearSetupTargetYearId > 0
    && $yearSetupSourceYearId !== $yearSetupTargetYearId;
$yearSetupTargetYear = null;
foreach ($academicYears as $academicYear) {
    if ((int)$academicYear['id'] === $yearSetupTargetYearId) {
        $yearSetupTargetYear = $academicYear;
        break;
    }
}
$yearSetupPairReady = $yearSetupHasValidPair
    && $yearSetupTargetYear
    && (int)($yearSetupTargetYear['is_active'] ?? 0) === 0
    && (int)($academicYearStudentCounts[$yearSetupTargetYearId] ?? 0) === 0;
$yearSetupRulesReady = $yearSetupPairReady && !empty($yearSetupRuleMatrix['rules']);
foreach ($yearSetupRuleMatrix['rules'] as $rule) {
    if (empty($rule['saved'])) {
        $yearSetupRulesReady = false;
        break;
    }
}
$yearSetupMappingsReady = false;
if ($yearSetupRulesReady) {
    try {
        $yearSetupClassMatrix = (new NewYearRolloverService($db))->classPromotionMatrix(
            $yearSetupSourceYearId,
            $yearSetupTargetYearId
        );
        $yearSetupMappingsReady = !empty($yearSetupClassMatrix['ready']);
    } catch (Throwable $e) {
        $yearSetupClassMatrix = ['mappings' => [], 'ready' => false, 'expected_count' => 0];
    }
}
$yearSetupPreviewReady = is_array($yearSetupPreview) && !empty($yearSetupPreview['ready']);
$yearSetupBackupVerified = is_array($yearSetupBackup)
    && ($yearSetupBackup['status'] ?? '') === 'verified';
$yearSetupRunStatus = (string)($yearSetupRun['status'] ?? '');
$yearSetupActivated = $yearSetupRunStatus === 'activated';
$yearSetupSpecificBlockerGroups = is_array($yearSetupPreview['blocker_groups'] ?? null)
    ? array_values(array_filter(
        $yearSetupPreview['blocker_groups'],
        static fn(array $group): bool => (string)($group['code'] ?? '') !== 'students_skipped'
    ))
    : [];
$yearSetupPlacementBlockerCount = 0;
foreach ($yearSetupSpecificBlockerGroups as $group) {
    if (($group['code'] ?? '') === 'student_placement_missing') {
        $yearSetupPlacementBlockerCount += (int)($group['count'] ?? 0);
    }
}

$yearSetupCurrentStep = 1;
if ($yearSetupPairReady) {
    $yearSetupCurrentStep = 2;
}
if ($yearSetupRulesReady) {
    $yearSetupCurrentStep = 3;
}
if ($yearSetupMappingsReady) {
    $yearSetupCurrentStep = 4;
}
if ($yearSetupPreviewReady) {
    $yearSetupCurrentStep = 5;
}
if ($yearSetupBackupVerified) {
    $yearSetupCurrentStep = 6;
}
if ($yearSetupActivated) {
    $yearSetupCurrentStep = 5;
}

$page_title = 'تهيئة عام جديد';
$custom_page_title = true;
require_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="admin-page-heading">
    <div>
        <h1 class="h2"><i class="fas fa-calendar-plus me-2 text-primary"></i>بدء عام دراسي جديد</h1>
        <p class="text-muted m-0">اتبع الخطوات بالترتيب؛ لن تُنقل أي بيانات أو يُفعّل العام قبل موافقتك النهائية.</p>
    </div>
</div>

<!-- رسائل النجاح والخطأ -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $success_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars((string) $error_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$yearSetupSchemaReady): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-database me-2"></i>
        مكونات التهيئة الآمنة غير مثبتة بعد. يجب تشغيل migration المعتمد قبل استخدام الصفحة؛ لا يتم إنشاء جداول أثناء طلب الويب.
    </div>
<?php endif; ?>

<section class="year-setup-guide mb-4" aria-labelledby="yearSetupGuideTitle">
    <div class="year-setup-steps" aria-label="خطوات تهيئة العام الجديد">
        <?php
        $yearSetupSteps = [
            1 => ['label' => 'اختيار العامين', 'icon' => 'fa-calendar-days', 'href' => '#yearSetupPairCard'],
            2 => ['label' => 'قواعد الصفوف', 'icon' => 'fa-route', 'href' => '#yearSetupRulesCard'],
            3 => ['label' => 'خريطة الفصول', 'icon' => 'fa-people-arrows', 'href' => '#yearSetupClassesCard'],
            4 => ['label' => 'الطلاب والمعاينة', 'icon' => 'fa-user-check', 'href' => '#yearSetupStudentsCard'],
            5 => ['label' => 'نسخة التعافي', 'icon' => 'fa-database', 'href' => '#yearSetupPreviewCard'],
            6 => ['label' => 'التنفيذ والتفعيل', 'icon' => 'fa-flag-checkered', 'href' => $yearSetupRun ? '#yearSetupRunCard' : '#yearSetupPreviewCard'],
        ];
        foreach ($yearSetupSteps as $stepNumber => $step):
            $stepComplete = $stepNumber < $yearSetupCurrentStep || ($stepNumber === 6 && $yearSetupActivated);
            $stepCurrent = $stepNumber === $yearSetupCurrentStep && !$yearSetupActivated;
            $stepClass = $stepComplete ? 'is-complete' : ($stepCurrent ? 'is-current' : 'is-upcoming');
        ?>
            <a class="year-setup-step <?php echo $stepClass; ?>" href="<?php echo htmlspecialchars($step['href'], ENT_QUOTES, 'UTF-8'); ?>"
                <?php echo $stepCurrent ? 'aria-current="step"' : ''; ?>>
                <span class="year-setup-step-number">
                    <?php if ($stepComplete): ?><i class="fas fa-check"></i><?php else: ?><?php echo $stepNumber; ?><?php endif; ?>
                </span>
                <span><i class="fas <?php echo htmlspecialchars($step['icon'], ENT_QUOTES, 'UTF-8'); ?> me-1"></i><?php echo htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="year-setup-next-action <?php echo is_array($yearSetupPreview) && !$yearSetupPreviewReady ? 'has-blocker' : ''; ?>">
        <div class="year-setup-next-icon"><i class="fas <?php echo is_array($yearSetupPreview) && !$yearSetupPreviewReady ? 'fa-triangle-exclamation' : 'fa-arrow-left'; ?>"></i></div>
        <div class="flex-grow-1">
            <div class="year-setup-next-eyebrow" id="yearSetupGuideTitle">ماذا أفعل الآن؟</div>
            <?php if ($yearSetupActivated): ?>
                <div class="fw-bold">اكتملت تهيئة العام الجديد وتفعيله بنجاح.</div>
                <small>يمكنك الآن تسكين الطلاب في فصول العام الجديد.</small>
            <?php elseif (!$yearSetupHasValidPair): ?>
                <div class="fw-bold">اختر العام السابق في «العام المصدر»، والعام الجديد الفارغ في «العام الهدف».</div>
                <small>إذا لم يكن العام الجديد موجودًا، أضفه أولًا من صفحة الأعوام الدراسية دون تفعيله.</small>
            <?php elseif (!$yearSetupPairReady): ?>
                <div class="fw-bold">العام الهدف المختار غير صالح للتهيئة؛ يجب أن يكون غير نشط وفارغًا تمامًا.</div>
                <small>أنشئ عامًا جديدًا أو اختر عامًا فارغًا، ولا تستخدم العام النشط الحالي كهدف.</small>
            <?php elseif (!$yearSetupRulesReady): ?>
                <div class="fw-bold">راجع الصف الذي سينتقل إليه طلاب كل صف، ثم اضغط «حفظ قواعد الانتقال».</div>
                <small>اترك آخر صف في المرحلة على «تخرج» إذا كان هذا هو الإجراء الصحيح.</small>
            <?php elseif (!$yearSetupMappingsReady): ?>
                <div class="fw-bold">راجع مسار كل فصل واسمه وسعته في الصف الهدف، ثم احفظ خريطة الفصول.</div>
                <small>يمكنك إيقاف التسكين التلقائي لفصل يحتاج إلى تقسيم أو إعادة تنظيم.</small>
            <?php elseif (!is_array($yearSetupPreview)): ?>
                <div class="fw-bold">اترك الطلاب الناجحين كما هم، وحدد الراسبين أو الحالات الاستثنائية فقط، ثم افحص الجاهزية.</div>
                <small>لا تحتاج إلى اختيار قرار لكل طالب؛ النظام يطبق قاعدة الصف تلقائيًا.</small>
            <?php elseif (!$yearSetupPreviewReady): ?>
                <div class="fw-bold">لا تنفذ الآن؛ عالج <?php echo count($yearSetupSpecificBlockerGroups); ?> <?php echo count($yearSetupSpecificBlockerGroups) === 1 ? 'مشكلة' : 'مجموعات مشكلات'; ?> موضحة أسفل الصفحة.</div>
                <?php if ($yearSetupPlacementBlockerCount > 0): ?>
                    <small>يوجد <?php echo $yearSetupPlacementBlockerCount; ?> طالب بلا مرحلة أو صف. الحساب التجريبي يُحدد من «حسابات الطلاب»، أما الطالب الحقيقي فتُستكمل مرحلته وصفه من ملفه.</small>
                <?php else: ?>
                    <small>ستجد بجوار كل مشكلة الإجراء المطلوب والرابط المناسب لمعالجتها.</small>
                <?php endif; ?>
            <?php elseif (!$yearSetupBackupVerified): ?>
                <div class="fw-bold">المعاينة سليمة. أنشئ الآن نسخة تعافٍ وسيختبر النظام استعادتها تلقائيًا.</div>
                <small>لن يفتح زر التنفيذ ما لم تنجح تجربة الاستعادة.</small>
            <?php elseif (!$yearSetupRun): ?>
                <div class="fw-bold">النسخة الاحتياطية مجرّبة. يمكنك تنفيذ التهيئة كمسودة دون تفعيل العام.</div>
                <small>بعد التنفيذ ستبقى أمامك مرحلتا التحقق والتفعيل النهائي.</small>
            <?php elseif ($yearSetupRunStatus === 'completed'): ?>
                <div class="fw-bold">تم إنشاء المسودات. اضغط «تحقق من النتيجة» قبل التفعيل.</div>
                <small>يمكنك الرجوع بأمان ما دام العام الجديد لم يُفعّل.</small>
            <?php elseif ($yearSetupRunStatus === 'verified'): ?>
                <div class="fw-bold">نجح التحقق. راجع الملخص ثم فعّل العام الجديد عندما تكون مستعدًا.</div>
                <small>التفعيل يجعل العام الجديد تشغيليًا ويقفل العام السابق ضد الكتابات التاريخية.</small>
            <?php endif; ?>
        </div>
        <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars($yearSetupSteps[$yearSetupCurrentStep]['href'], ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fas fa-location-arrow me-1"></i>اذهب إلى الخطوة
        </a>
    </div>
</section>

<div class="row g-4">
    <div class="col-lg-12">
        <div class="alert alert-info border-0">
            <i class="fas fa-info-circle me-2"></i>
            أضف العام الجديد أولاً من صفحة <a href="academic_years.php" class="fw-bold alert-link">الأعوام الدراسية</a>، ثم اختره كعام هدف. لا تُنقل الدرجات أو الحضور أو التقييمات أو المدفوعات أو النقل أو التقارير التاريخية.
        </div>

        <div class="card shadow mb-4" id="yearSetupPairCard">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0 fw-bold text-white"><span class="year-setup-card-step">1</span>اختر العام السابق والعام الجديد</h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">المصدر هو العام الذي يدرس فيه الطلاب الآن، والهدف هو العام الجديد الذي أضفته ولم تفعّله بعد.</p>
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label fw-bold" for="setup_source_year_id">العام المصدر <small class="text-muted">(العام السابق)</small></label>
                        <select name="source_year_id" id="setup_source_year_id" class="form-select" required onchange="reloadYearSetupStudents()">
                            <option value="">اختر العام السابق</option>
                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo (int)$year['id']; ?>" <?php echo (int)$year['id'] === $yearSetupSourceYearId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $year['name'], ENT_QUOTES, 'UTF-8'); ?><?php echo (int)($year['is_active'] ?? 0) === 1 ? ' (نشط الآن)' : ''; ?>
                                    — <?php echo number_format($academicYearStudentCounts[(int)$year['id']] ?? 0); ?> طالب
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 text-center year-setup-pair-arrow" aria-hidden="true">
                        <i class="fas fa-arrow-left"></i>
                        <small class="d-block text-muted mt-1">ترحيل إلى</small>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-bold" for="setup_target_year_id">العام الهدف <small class="text-muted">(العام الجديد)</small></label>
                        <select name="target_year_id" id="setup_target_year_id" class="form-select" required onchange="reloadYearSetupStudents()">
                            <option value="">اختر العام الجديد الفارغ</option>
                            <?php foreach ($academicYears as $year):
                                $count = $academicYearStudentCounts[(int)$year['id']] ?? 0;
                            ?>
                                <option value="<?php echo (int)$year['id']; ?>" <?php echo (int)$year['id'] === $yearSetupTargetYearId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $year['name'], ENT_QUOTES, 'UTF-8'); ?> — <?php echo $count > 0 ? number_format($count) . ' طالب (غير فارغ)' : 'فارغ وجاهز'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">لن يسمح النظام بالتهيئة إذا كان العام الهدف نشطًا أو يحتوي بيانات.</small>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($yearSetupRuleMatrix['rules'])): ?>
            <div class="card shadow mb-4" id="yearSetupRulesCard">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0 fw-bold text-white"><span class="year-setup-card-step">2</span>تأكد من الصف التالي لكل صف</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-circle-info me-2"></i>
                        هذه القاعدة تطبق تلقائيًا على الطلاب الناجحين. راجعها مرة واحدة فقط، ثم احفظها. الصفوف التجريبية مستبعدة.
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="source_year_id" value="<?php echo (int)$yearSetupSourceYearId; ?>">
                        <input type="hidden" name="target_year_id" value="<?php echo (int)$yearSetupTargetYearId; ?>">
                        <div class="admin-table-wrap mb-3">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead>
                                    <tr><th>المرحلة</th><th>الصف الحالي</th><th>القرار الافتراضي</th><th>الحفظ</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($yearSetupRuleMatrix['rules'] as $rule): ?>
                                        <?php
                                        $selectedRule = ($rule['rule_type'] ?? '') === 'graduate'
                                            ? 'graduate'
                                            : (string)($rule['target_grade_id'] ?? '');
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$rule['stage_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars((string)$rule['source_grade_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <select class="form-select" name="promotion_rules[<?php echo (int)$rule['source_grade_id']; ?>]" required>
                                                    <option value="">اختر القرار</option>
                                                    <?php foreach ($yearSetupRuleMatrix['grades'] as $targetGrade): ?>
                                                        <?php if ((int)$targetGrade['id'] === (int)$rule['source_grade_id']) continue; ?>
                                                        <option value="<?php echo (int)$targetGrade['id']; ?>" <?php echo $selectedRule === (string)$targetGrade['id'] ? 'selected' : ''; ?>>
                                                            انتقال إلى: <?php echo htmlspecialchars((string)$targetGrade['grade_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                    <option value="graduate" <?php echo $selectedRule === 'graduate' ? 'selected' : ''; ?>>تخرج — لا قيد في العام الجديد</option>
                                                </select>
                                            </td>
                                            <td>
                                                <?php if (!empty($rule['saved'])): ?>
                                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>محفوظة</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark"><i class="fas fa-pen me-1"></i>اقتراح غير محفوظ</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-end">
                            <button type="submit" name="save_promotion_rules" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>حفظ قواعد الانتقال
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($yearSetupRulesReady): ?>
        <div class="card shadow mb-4" id="yearSetupClassesCard">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0 fw-bold text-white"><span class="year-setup-card-step">3</span>راجع انتقال الفصول إلى الصفوف الجديدة</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-people-arrows me-2"></i>
                    «مجموعة طلاب» تنشئ الفصل في الصف الأعلى وتسكّن الناجحين فيه. «قالب فصل فارغ» يحافظ على فصول أول صف دخول أو يكمل عدد فصول الصف عند اختلاف حجم الدفعات.
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="source_year_id" value="<?php echo (int)$yearSetupSourceYearId; ?>">
                    <input type="hidden" name="target_year_id" value="<?php echo (int)$yearSetupTargetYearId; ?>">
                    <?php if (empty($yearSetupClassMatrix['mappings'])): ?>
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-circle-info me-2"></i>لا توجد فصول مصدر تحتاج إلى إنشاء خطة انتقال.
                        </div>
                    <?php else: ?>
                        <div class="admin-table-wrap mb-3">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>المسار</th>
                                        <th>الفصل المصدر</th>
                                        <th>الفصل في العام الجديد</th>
                                        <th>السعة</th>
                                        <th>إنشاء الفصل</th>
                                        <th>تسكين الناجحين</th>
                                        <th>الحفظ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($yearSetupClassMatrix['mappings'] as $mapping): ?>
                                    <?php
                                    $mappingKey = (string)$mapping['mapping_key'];
                                    $isCohort = (string)$mapping['mapping_type'] === 'cohort';
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge <?php echo $isCohort ? 'bg-success' : 'bg-info text-dark'; ?>">
                                                <i class="fas <?php echo $isCohort ? 'fa-users' : 'fa-door-open'; ?> me-1"></i>
                                                <?php echo $isCohort ? 'مجموعة طلاب' : 'قالب فصل فارغ'; ?>
                                            </span>
                                            <?php if ($isCohort): ?>
                                                <small class="text-muted d-block mt-1"><?php echo (int)$mapping['student_count']; ?> طالب قبل الاستثناءات</small>
                                            <?php else: ?>
                                                <small class="text-muted d-block mt-1">يبدأ بلا طلاب</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="fw-bold"><?php echo htmlspecialchars((string)$mapping['source_class_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <small class="text-muted d-block">
                                                <?php echo htmlspecialchars(trim((string)$mapping['source_stage_name'] . ' - ' . (string)$mapping['source_grade_name'], ' -'), ENT_QUOTES, 'UTF-8'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="small text-primary fw-semibold mb-1">
                                                <i class="fas fa-arrow-left me-1"></i>
                                                <?php echo htmlspecialchars(trim((string)$mapping['target_stage_name'] . ' - ' . (string)$mapping['target_grade_name'], ' -'), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                            <input type="text" class="form-control form-control-sm"
                                                name="class_mappings[<?php echo htmlspecialchars($mappingKey, ENT_QUOTES, 'UTF-8'); ?>][target_name]"
                                                value="<?php echo htmlspecialchars((string)$mapping['target_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                maxlength="100" required aria-label="اسم الفصل الهدف">
                                        </td>
                                        <td>
                                            <input type="number" class="form-control form-control-sm"
                                                name="class_mappings[<?php echo htmlspecialchars($mappingKey, ENT_QUOTES, 'UTF-8'); ?>][target_capacity]"
                                                value="<?php echo $mapping['target_capacity'] !== null ? (int)$mapping['target_capacity'] : ''; ?>"
                                                min="1" max="65535" placeholder="بدون حد" aria-label="سعة الفصل الهدف">
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm" name="class_mappings[<?php echo htmlspecialchars($mappingKey, ENT_QUOTES, 'UTF-8'); ?>][is_enabled]">
                                                <option value="1" <?php echo !empty($mapping['is_enabled']) ? 'selected' : ''; ?>>إنشاء</option>
                                                <option value="0" <?php echo empty($mapping['is_enabled']) ? 'selected' : ''; ?>>لا تنشئ</option>
                                            </select>
                                        </td>
                                        <td>
                                            <?php if ($isCohort): ?>
                                                <select class="form-select form-select-sm" name="class_mappings[<?php echo htmlspecialchars($mappingKey, ENT_QUOTES, 'UTF-8'); ?>][auto_place_students]">
                                                    <option value="1" <?php echo !empty($mapping['auto_place_students']) ? 'selected' : ''; ?>>تلقائي</option>
                                                    <option value="0" <?php echo empty($mapping['auto_place_students']) ? 'selected' : ''; ?>>يدوي بعد التهيئة</option>
                                                </select>
                                            <?php else: ?>
                                                <input type="hidden" name="class_mappings[<?php echo htmlspecialchars($mappingKey, ENT_QUOTES, 'UTF-8'); ?>][auto_place_students]" value="0">
                                                <span class="text-muted small">لا يوجد طلاب</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($mapping['saved'])): ?>
                                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>محفوظ</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="fas fa-pen me-1"></i>اقتراح</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <small class="text-muted"><i class="fas fa-shield-halved me-1"></i>الراسب والخريج والمنقول والمنقطع لا يتبعون الفصل تلقائيًا.</small>
                        <button type="submit" name="save_class_mappings" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>حفظ خريطة الفصول
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($yearSetupMappingsReady): ?>
        <div class="card shadow mb-4" id="yearSetupStudentsCard">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0 fw-bold text-white"><span class="year-setup-card-step">4</span>راجع الطلاب الاستثنائيين فقط</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="yearSetupPreviewForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="source_year_id" value="<?php echo (int)$yearSetupSourceYearId; ?>">
                    <input type="hidden" name="target_year_id" value="<?php echo (int)$yearSetupTargetYearId; ?>">

                    <?php if (!$yearSetupRulesReady): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-lock me-2"></i>أكمل الخطوة 2 واحفظ جميع قواعد انتقال الصفوف قبل فحص الطلاب.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                        <div>
                            <h6 class="fw-bold mb-1"><i class="fas fa-user-check text-success me-2"></i>اعتمد حالتي القيد والدراسة لكل طالب</h6>
                            <small class="text-muted">«حسب قاعدة الصف» يرقّي الطالب تلقائيًا أو يخرّجه وفق القاعدة المحفوظة.</small>
                        </div>
                        <span class="badge bg-primary"><i class="fas fa-link me-1"></i>كل قرار يرتبط بالقيد السنوي المصدر</span>
                    </div>

                    <div class="input-group mb-3 year-setup-student-search">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="search" class="form-control" id="yearSetupStudentSearch" placeholder="ابحث عن طالب بالاسم أو الكود..." autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" id="clearYearSetupStudentSearch"><i class="fas fa-times me-1"></i>مسح</button>
                    </div>

                    <?php if (empty($yearSetupStudentGroups)): ?>
                        <div class="alert alert-warning mb-4">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            لا توجد تسجيلات طلاب مقيدين في العام المصدر المختار.
                        </div>
                    <?php else: ?>
                        <div class="accordion mb-4" id="retainedStudentsAccordion">
                            <?php foreach ($yearSetupStudentGroups as $idx => $group): ?>
                                <div class="accordion-item year-setup-student-group">
                                    <h2 class="accordion-header" id="classHeading<?php echo $idx; ?>">
                                        <button class="accordion-button <?php echo $idx === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#classCollapse<?php echo $idx; ?>">
                                            <span class="fw-bold me-2"><?php echo htmlspecialchars((string) $group['class_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="text-muted small">
                                                <?php echo htmlspecialchars(trim(($group['stage_name'] ?? '') . ' - ' . ($group['grade_name'] ?? ''), ' -'), ENT_QUOTES, 'UTF-8'); ?>
                                                (<?php echo count($group['students']); ?> طالب)
                                            </span>
                                        </button>
                                    </h2>
                                    <div id="classCollapse<?php echo $idx; ?>" class="accordion-collapse collapse <?php echo $idx === 0 ? 'show' : ''; ?>" data-bs-parent="#retainedStudentsAccordion">
                                        <div class="accordion-body">
                                            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2">
                                                <?php foreach ($group['students'] as $student): ?>
                                                     <?php
                                                     $studentId = (int)$student['student_id'];
                                                     $postedDecision = $yearSetupDecisionOverrides[$studentId] ?? null;
                                                     $savedEnrollmentStatus = is_array($postedDecision)
                                                         ? (string)($postedDecision['enrollment_status'] ?? 'enrolled')
                                                         : (string)($student['saved_enrollment_status'] ?? 'enrolled');
                                                     $savedAcademicStatus = is_array($postedDecision)
                                                         ? (string)($postedDecision['academic_status'] ?? 'auto')
                                                         : (string)($student['saved_academic_status'] ?? 'auto');
                                                      if (!is_array($postedDecision) && is_string($postedDecision)) {
                                                         $savedEnrollmentStatus = match ($postedDecision) {
                                                             'transferred_out' => 'transferred',
                                                             'withdrawn' => 'discontinued',
                                                             default => 'enrolled',
                                                         };
                                                         $savedAcademicStatus = match ($postedDecision) {
                                                             'retained' => 'retained',
                                                             'graduated' => 'graduated',
                                                             'pending' => 'pending',
                                                              default => 'auto',
                                                          };
                                                      }
                                                      if (!is_array($postedDecision)
                                                          && (string)($student['decision_source'] ?? '') === 'rule') {
                                                          $savedEnrollmentStatus = 'enrolled';
                                                          $savedAcademicStatus = 'auto';
                                                      } elseif (!is_array($postedDecision)
                                                          && (string)($student['decision_source'] ?? '') === 'manual'
                                                          && (string)($student['saved_decision'] ?? '') === 'pending') {
                                                          $savedAcademicStatus = 'pending';
                                                      }
                                                      $isTestStudent = !empty($student['is_test_account'])
                                                          || !empty($student['stage_is_experimental'])
                                                          || !empty($student['grade_is_experimental'])
                                                          || !empty($student['class_is_experimental']);
                                                      $placementMissing = !$isTestStudent
                                                          && ((int)($student['stage_id'] ?? 0) <= 0 || (int)($student['grade_id'] ?? 0) <= 0);
                                                     ?>
                                                    <div class="col year-setup-student-card"
                                                        data-student-search="<?php echo htmlspecialchars((string)$student['student_name'] . ' ' . (string)($student['student_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <div class="border rounded p-2 h-100">
                                                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                                                <div>
                                                                    <span class="fw-bold"><?php echo htmlspecialchars((string) $student['student_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                                    <?php if (!empty($student['student_code'])): ?>
                                                                        <small class="text-muted d-block"><?php echo htmlspecialchars((string) $student['student_code'], ENT_QUOTES, 'UTF-8'); ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <?php if ($isTestStudent): ?>
                                                                    <span class="badge bg-warning text-dark"><i class="fas fa-flask me-1"></i>مستبعد تجريبيًا</span>
                                                                <?php elseif (($student['decision_status'] ?? '') === 'approved'): ?>
                                                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>قرار محفوظ</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if ($placementMissing): ?>
                                                                <div class="alert alert-warning py-2 px-3 mb-0 small">
                                                                    <div class="fw-bold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>يحتاج تصحيح ملفه قبل الترحيل</div>
                                                                    <div class="mb-2">إن كان للتجربة غيّر نوع حسابه من صفحة الحسابات. وإن كان حقيقيًا أكمل مرحلته وصفه من ملف الطالب.</div>
                                                                    <div class="d-flex flex-wrap gap-3">
                                                                        <a href="student_accounts.php?student_id=<?php echo $studentId; ?>#studentsAccountsTable" target="_blank" rel="noopener" class="fw-bold">
                                                                            <i class="fas fa-user-shield me-1"></i>فتح حساب الطالب
                                                                        </a>
                                                                        <a href="students.php?action=edit&amp;id=<?php echo $studentId; ?>" target="_blank" rel="noopener" class="fw-bold">
                                                                            <i class="fas fa-user-pen me-1"></i>فتح ملف الطالب
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                             <?php elseif (!$isTestStudent): ?>
                                                                 <div class="small text-muted mb-2">
                                                                     الحالة الحالية:
                                                                     <span class="fw-semibold">
                                                                         <?php
                                                                         echo htmlspecialchars(match ((string)($student['current_academic_status'] ?? 'new')) {
                                                                             'promoted' => 'ناجح ومنقول',
                                                                             'retained' => 'راسب',
                                                                             'graduated' => 'خريج',
                                                                             default => 'مستجد',
                                                                         }, ENT_QUOTES, 'UTF-8');
                                                                         ?>
                                                                     </span>
                                                                 </div>
                                                                 <div class="row g-2">
                                                                     <div class="col-sm-6">
                                                                         <label class="form-label small mb-1" for="setup_enrollment_<?php echo $studentId; ?>">حالة القيد</label>
                                                                         <select class="form-select form-select-sm student-decision-select"
                                                                             id="setup_enrollment_<?php echo $studentId; ?>"
                                                                             data-decision-name="student_decisions[<?php echo $studentId; ?>][enrollment_status]">
                                                                             <option value="" <?php echo $savedEnrollmentStatus === 'enrolled' ? 'selected' : ''; ?>>مقيد</option>
                                                                             <option value="transferred" <?php echo $savedEnrollmentStatus === 'transferred' ? 'selected' : ''; ?>>منقول خارج المدرسة</option>
                                                                             <option value="discontinued" <?php echo $savedEnrollmentStatus === 'discontinued' ? 'selected' : ''; ?>>منقطع</option>
                                                                         </select>
                                                                     </div>
                                                                     <div class="col-sm-6">
                                                                         <label class="form-label small mb-1" for="setup_academic_<?php echo $studentId; ?>">الحالة الدراسية</label>
                                                                         <select class="form-select form-select-sm student-decision-select"
                                                                             id="setup_academic_<?php echo $studentId; ?>"
                                                                             data-decision-name="student_decisions[<?php echo $studentId; ?>][academic_status]">
                                                                             <option value="" <?php echo $savedAcademicStatus === 'auto' ? 'selected' : ''; ?>>حسب قاعدة الصف</option>
                                                                             <option value="promoted" <?php echo $savedAcademicStatus === 'promoted' ? 'selected' : ''; ?>>ناجح ومنقول</option>
                                                                             <option value="retained" <?php echo $savedAcademicStatus === 'retained' ? 'selected' : ''; ?>>راسب</option>
                                                                             <option value="graduated" <?php echo $savedAcademicStatus === 'graduated' ? 'selected' : ''; ?>>خريج</option>
                                                                             <option value="pending" <?php echo $savedAcademicStatus === 'pending' ? 'selected' : ''; ?>>قيد المراجعة</option>
                                                                         </select>
                                                                     </div>
                                                                 </div>
                                                             <?php else: ?>
                                                                <small class="text-muted">سيُحفظ قرار استبعاد صريح ولن يُنشأ قيد في العام الجديد.</small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="text-end">
                        <button type="submit" name="preview_year_setup" class="btn btn-primary" <?php echo !$yearSetupRulesReady ? 'disabled' : ''; ?>>
                            <i class="fas fa-clipboard-check me-1"></i>حفظ القرارات وفحص الجاهزية
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (is_array($yearSetupPreview)): ?>
            <div class="card shadow mb-4" id="yearSetupPreviewCard">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0 fw-bold text-white"><span class="year-setup-card-step">5</span>نتيجة فحص الجاهزية ونسخة التعافي</h5>
                </div>
                <div class="card-body">
                    <?php if ($yearSetupPreviewReady): ?>
                        <div class="alert alert-success d-flex align-items-start gap-2">
                            <i class="fas fa-circle-check mt-1"></i>
                            <div><strong>البيانات جاهزة.</strong> راجع الأعداد التالية، ثم أنشئ نسخة التعافي واختبر استعادتها.</div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger d-flex align-items-start gap-2" id="yearSetupBlockers">
                            <i class="fas fa-ban mt-1"></i>
                            <div>
                                <strong>توقف هنا: التنفيذ غير مسموح حاليًا.</strong>
                                عالج المشكلات المبينة في الجدول، ثم ارجع إلى الخطوة 4 واضغط «حفظ القرارات وفحص الجاهزية» من جديد.
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
                        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-school"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$yearSetupPreview['classes_to_copy']; ?>">0</div><div class="stat-card-label">فصول</div></div></div></div>
                        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-arrow-up"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$yearSetupPreview['students_promoted']; ?>">0</div><div class="stat-card-label">سيترقون</div></div></div></div>
                        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);"><div class="stat-card-icon"><i class="fas fa-users-rectangle"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$yearSetupPreview['students_auto_placed']; ?>">0</div><div class="stat-card-label">تسكين تلقائي</div></div></div></div>
                        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);"><div class="stat-card-icon"><i class="fas fa-user-pen"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$yearSetupPreview['students_unassigned_promoted']; ?>">0</div><div class="stat-card-label">ناجحون بلا فصل</div></div></div></div>
                        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-user-clock"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$yearSetupPreview['students_retained']; ?>">0</div><div class="stat-card-label">راسبون</div></div></div></div>
                        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);"><div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$yearSetupPreview['students_graduating']; ?>">0</div><div class="stat-card-label">خريجون</div></div></div></div>
                        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);"><div class="stat-card-icon"><i class="fas fa-hourglass-half"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$yearSetupPreview['students_pending']; ?>">0</div><div class="stat-card-label">قرارات معلقة</div></div></div></div>
                        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);"><div class="stat-card-icon"><i class="fas fa-flask"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$yearSetupPreview['students_excluded_test']; ?>">0</div><div class="stat-card-label">مستبعدون تجريبيًا</div></div></div></div>
                        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);"><div class="stat-card-icon"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$yearSetupPreview['students_skipped']; ?>">0</div><div class="stat-card-label">موانع طلاب</div></div></div></div>
                    </div>

                    <?php if (!empty($yearSetupSpecificBlockerGroups)): ?>
                        <div class="admin-table-wrap mb-4">
                            <table class="table table-hover table-striped align-middle mb-0 year-setup-blockers-table">
                                <thead><tr><th>المشكلة</th><th>الطلاب أو البيانات المتأثرة</th><th>العدد</th><th>ما الذي تفعله؟</th></tr></thead>
                                <tbody>
                                    <?php foreach ($yearSetupSpecificBlockerGroups as $group): ?>
                                        <?php
                                        $blockerCode = (string)($group['code'] ?? '');
                                        $guidance = yearSetupBlockerGuidance($blockerCode);
                                        $samples = is_array($group['samples'] ?? null) ? $group['samples'] : [];
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars((string)($group['message'] ?? 'مانع غير معروف'), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php if (!empty($group['grade_name']) || !empty($group['table'])): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars((string)(($group['grade_name'] ?? '') ?: ($group['table'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($samples): ?>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <?php foreach ($samples as $sample): ?>
                                                            <?php
                                                            $sampleStudentId = (int)($sample['student_id'] ?? 0);
                                                            $sampleLabel = (string)(($sample['student_code'] ?? '') ?: ('طالب #' . $sampleStudentId));
                                                            ?>
                                                            <?php if ($sampleStudentId > 0): ?>
                                                                <a href="student_accounts.php?student_id=<?php echo $sampleStudentId; ?>#studentsAccountsTable" target="_blank" rel="noopener" class="year-setup-student-link">
                                                                    <i class="fas fa-user-shield me-1"></i><?php echo htmlspecialchars($sampleLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <span><?php echo htmlspecialchars($sampleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php if ((int)($group['count'] ?? 0) > count($samples)): ?>
                                                        <small class="text-muted">معروض <?php echo count($samples); ?> من <?php echo (int)$group['count']; ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted"><?php echo htmlspecialchars((string)(($group['grade_name'] ?? '') ?: ($group['table'] ?? 'عام')), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-danger"><?php echo (int)($group['count'] ?? 0); ?></span></td>
                                            <td>
                                                <div><?php echo htmlspecialchars($guidance['text'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <a href="<?php echo htmlspecialchars($guidance['href'], ENT_QUOTES, 'UTF-8'); ?>" class="fw-bold d-inline-block mt-2">
                                                    <i class="fas fa-arrow-left me-1"></i><?php echo htmlspecialchars($guidance['label'], ENT_QUOTES, 'UTF-8'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($yearSetupPreview['warnings'])): ?>
                        <div class="alert alert-warning">
                            <?php foreach ($yearSetupPreview['warnings'] as $warning): ?>
                                <div><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars((string)($warning['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <i class="fas fa-shield-halved me-2"></i>
                        السياسة ثابتة: تُنشأ الفصول من الخريطة المحفوظة؛ الناجح يتبع فصل مجموعته إذا كان التسكين التلقائي مفعّلًا، والراسب يبقى في صفه بلا فصل. لا تُنسخ الدرجات أو الحضور أو الرسوم أو النتائج التاريخية.
                    </div>

                    <?php if ($yearSetupBackup && ($yearSetupBackup['status'] ?? '') === 'verified'): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-database me-2"></i>
                            نسخة التعافي مجرّبة الاستعادة — تم التحقق في <?php echo htmlspecialchars((string)$yearSetupBackup['verified_at'], ENT_QUOTES, 'UTF-8'); ?> وتنتهي صلاحيتها في <?php echo htmlspecialchars((string)$yearSetupBackup['expires_at'], ENT_QUOTES, 'UTF-8'); ?>.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-lock me-2"></i>لا توجد نسخة تعافٍ حالية مجرّبة الاستعادة؛ التنفيذ مقفول.
                        </div>
                    <?php endif; ?>

                    <?php if (!$yearSetupRun): ?>
                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRecoveryModal" <?php echo empty($yearSetupPreview['ready']) || !$yearSetupSchemaReady ? 'disabled' : ''; ?>>
                                <i class="fas fa-database me-1"></i>إنشاء نسخة واختبار الاستعادة
                            </button>
                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#executeYearSetupModal" <?php echo empty($yearSetupPreview['ready']) || !$yearSetupBackup || ($yearSetupBackup['status'] ?? '') !== 'verified' ? 'disabled' : ''; ?>>
                                <i class="fas fa-play me-1"></i>إنشاء مسودة العام الجديد
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($yearSetupRun): ?>
            <div class="card shadow mb-4" id="yearSetupRunCard">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0 fw-bold text-white"><span class="year-setup-card-step">6</span>تحقق من المسودة ثم فعّل العام</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <?php if (($yearSetupRun['status'] ?? '') === 'completed'): ?>
                            <strong>تم إنشاء بيانات العام كمسودة.</strong> لم يُفعّل العام بعد؛ نفّذ التحقق من النتيجة الآن.
                        <?php elseif (($yearSetupRun['status'] ?? '') === 'verified'): ?>
                            <strong>نجح التحقق من المسودة.</strong> يمكنك تفعيل العام الجديد أو الرجوع قبل التفعيل.
                        <?php elseif (($yearSetupRun['status'] ?? '') === 'activated'): ?>
                            <strong>العام الجديد نشط الآن.</strong> اكتملت دورة التهيئة وقُفل العام المصدر ضد الكتابات التاريخية المحمية.
                        <?php else: ?>
                            الحالة الحالية: <span class="badge bg-dark"><?php echo htmlspecialchars((string)$yearSetupRun['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                        <?php if (($yearSetupRun['status'] ?? '') === 'completed'): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" name="verify_year_setup" class="btn btn-primary"><i class="fas fa-check-double me-1"></i>تحقق من النتيجة</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array((string)($yearSetupRun['status'] ?? ''), ['completed', 'verified'], true)): ?>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rollbackYearSetupModal"><i class="fas fa-rotate-left me-1"></i>إلغاء المسودة والرجوع</button>
                        <?php endif; ?>
                        <?php if (($yearSetupRun['status'] ?? '') === 'verified'): ?>
                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#activateYearSetupModal"><i class="fas fa-power-off me-1"></i>تفعيل العام الجديد</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="createRecoveryModal" tabindex="-1" aria-labelledby="createRecoveryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-info">
            <form method="POST" id="createRecoveryForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="createRecoveryModalLabel"><i class="fas fa-database me-2"></i>نسخة تعافٍ مجرّبة الاستعادة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-shield-halved text-primary" style="font-size: 3rem;"></i></div>
                    <p class="text-center">سيتم تصدير قاعدة البيانات والملفات، ثم استعادتها في قاعدة معزولة مؤقتة والتحقق من البصمات.</p>
                    <div class="alert alert-info py-2 small">
                        <i class="fas fa-clock me-1"></i>
                        قد تستغرق العملية عدة دقائق حسب حجم المرفقات. لا تعِد تحميل الصفحة ولا تضغط الزر مرة أخرى.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">كلمة مرور المدير</label>
                        <input type="password" name="admin_password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">اكتب: نسخة آمنة</label>
                        <input type="text" name="confirm_text" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="create_recovery_backup" class="btn btn-primary" id="createRecoverySubmit"><i class="fas fa-database me-1"></i>إنشاء واختبار</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('createRecoveryForm')?.addEventListener('submit', function (event) {
    var button = document.getElementById('createRecoverySubmit');
    if (!button) return;
    if (button.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }
    button.dataset.submitting = '1';
    button.classList.add('disabled');
    button.setAttribute('aria-disabled', 'true');
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>جارٍ إنشاء النسخة واختبار الاستعادة…';
});
</script>

<div class="modal fade" id="executeYearSetupModal" tabindex="-1" aria-labelledby="executeYearSetupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-success">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="source_year_id" value="<?php echo (int)$yearSetupSourceYearId; ?>">
                <input type="hidden" name="target_year_id" value="<?php echo (int)$yearSetupTargetYearId; ?>">
                <?php foreach ($yearSetupRetainedIds as $retainedId): ?>
                    <input type="hidden" name="retained_student_ids[]" value="<?php echo (int)$retainedId; ?>">
                <?php endforeach; ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="executeYearSetupModalLabel"><i class="fas fa-play me-2"></i>تنفيذ التهيئة المحمية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-calendar-check text-success" style="font-size: 3rem;"></i></div>
                    <p class="text-center">سيتم إنشاء بيانات العام كمسودات في معاملة واحدة، ولن يتم تفعيل العام تلقائيًا.</p>
                    <div class="mb-3"><label class="form-label fw-bold">كلمة مرور المدير</label><input type="password" name="admin_password" class="form-control" autocomplete="current-password" required></div>
                    <div class="mb-3"><label class="form-label fw-bold">اكتب: أؤكد</label><input type="text" name="confirm_text" class="form-control" required></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="execute_year_setup" class="btn btn-success"><i class="fas fa-check me-1"></i>تنفيذ دون تفعيل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="rollbackYearSetupModal" tabindex="-1" aria-labelledby="rollbackYearSetupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-header"><h5 class="modal-title" id="rollbackYearSetupModalLabel"><i class="fas fa-rotate-left me-2"></i>الرجوع عن التهيئة</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-trash-arrow-up text-danger" style="font-size: 3rem;"></i></div>
                    <p class="text-center">سيحذف النظام فقط السجلات المسجلة في manifest هذا التشغيل. لا يُسمح بذلك بعد التفعيل أو بدء معاملات تشغيلية.</p>
                    <div class="mb-3"><label class="form-label fw-bold">كلمة مرور المدير</label><input type="password" name="admin_password" class="form-control" autocomplete="current-password" required></div>
                    <div class="mb-3"><label class="form-label fw-bold">اكتب: تراجع</label><input type="text" name="confirm_text" class="form-control" required></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="rollback_year_setup" class="btn btn-danger"><i class="fas fa-rotate-left me-1"></i>تأكيد الرجوع</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="activateYearSetupModal" tabindex="-1" aria-labelledby="activateYearSetupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-success">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-header"><h5 class="modal-title" id="activateYearSetupModalLabel"><i class="fas fa-power-off me-2"></i>تفعيل العام الجديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-lock text-success" style="font-size: 3rem;"></i></div>
                    <p class="text-center">سيصبح العام الهدف نشطًا وسيُقفل العام المصدر ضد الكتابات المحمية. هذه خطوة تشغيلية نهائية.</p>
                    <div class="mb-3"><label class="form-label fw-bold">كلمة مرور المدير</label><input type="password" name="admin_password" class="form-control" autocomplete="current-password" required></div>
                    <div class="mb-3"><label class="form-label fw-bold">اكتب: تفعيل</label><input type="text" name="confirm_text" class="form-control" required></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="activate_year_setup" class="btn btn-success"><i class="fas fa-power-off me-1"></i>تفعيل وقفل المصدر</button></div>
            </form>
        </div>
    </div>
</div>

<script>
function reloadYearSetupStudents() {
    const source = document.getElementById('setup_source_year_id');
    const target = document.getElementById('setup_target_year_id');
    const url = new URL(window.location);
    if (source && source.value) {
        url.searchParams.set('setup_source_year_id', source.value);
    } else {
        url.searchParams.delete('setup_source_year_id');
    }
    if (target && target.value) {
        url.searchParams.set('setup_target_year_id', target.value);
    }
    window.location.href = url.toString();
}

function persistYearSetupTarget() {
    const source = document.getElementById('setup_source_year_id');
    const target = document.getElementById('setup_target_year_id');
    const url = new URL(window.location);
    if (source && source.value) {
        url.searchParams.set('setup_source_year_id', source.value);
    }
    if (target && target.value) {
        url.searchParams.set('setup_target_year_id', target.value);
    } else {
        url.searchParams.delete('setup_target_year_id');
    }
    window.history.replaceState({}, '', url);
}

function updateRetainedStudentsCounter() {
    const counter = document.getElementById('retainedStudentsCounter');
    if (!counter) return;
    counter.textContent = document.querySelectorAll('.retained-student-checkbox:checked').length;
}

function syncStudentDecisionField(select) {
    if (!select) return;
    if (select.value && select.dataset.decisionName) {
        select.setAttribute('name', select.dataset.decisionName);
    } else {
        select.removeAttribute('name');
    }
}

function filterYearSetupStudents(value) {
    const query = (value || '').trim().toLocaleLowerCase('ar');
    document.querySelectorAll('.year-setup-student-group').forEach(function (group) {
        let visibleCards = 0;
        group.querySelectorAll('.year-setup-student-card').forEach(function (card) {
            const haystack = (card.dataset.studentSearch || '').toLocaleLowerCase('ar');
            const matches = query === '' || haystack.includes(query);
            card.classList.toggle('d-none', !matches);
            if (matches) visibleCards++;
        });
        group.classList.toggle('d-none', visibleCards === 0);
        if (query !== '' && visibleCards > 0) {
            const collapse = group.querySelector('.accordion-collapse');
            if (collapse && window.bootstrap) {
                bootstrap.Collapse.getOrCreateInstance(collapse, { toggle: false }).show();
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    updateRetainedStudentsCounter();
    document.querySelectorAll('.retained-student-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', updateRetainedStudentsCounter);
    });
    document.querySelectorAll('.student-decision-select').forEach(function (select) {
        syncStudentDecisionField(select);
        select.addEventListener('change', function () {
            syncStudentDecisionField(select);
        });
    });
    const previewForm = document.getElementById('yearSetupPreviewForm');
    if (previewForm) {
        previewForm.addEventListener('submit', function () {
            previewForm.querySelectorAll('.student-decision-select').forEach(syncStudentDecisionField);
        });
    }
    const studentSearch = document.getElementById('yearSetupStudentSearch');
    const clearStudentSearch = document.getElementById('clearYearSetupStudentSearch');
    if (studentSearch) {
        studentSearch.addEventListener('input', function () {
            filterYearSetupStudents(studentSearch.value);
        });
    }
    if (clearStudentSearch && studentSearch) {
        clearStudentSearch.addEventListener('click', function () {
            studentSearch.value = '';
            filterYearSetupStudents('');
            studentSearch.focus();
        });
    }
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
