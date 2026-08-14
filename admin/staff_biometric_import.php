<?php
/**
 * استيراد سجلات البصمة للموظفين من CSV
 */
$page_title = "استيراد بصمة الموظفين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/StaffAttendanceService.php';
require_once '../classes/FileUploadGuard.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');

require_once '../vendor/autoload.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';

$database = new Database();
$db = $database->getConnection();
$attendanceService = new StaffAttendanceService($db);
$staffHrFlags = \EduCore\Modules\Staff\Infrastructure\StaffHrFeatureFlags::fromEnvironment();
$useAttendanceEventPipeline = $staffHrFlags->calculatesNewResults();

// The legacy owner remains available only while the rollout flag is off.
// New modes require the migration-owned tables and never create schema at
// request time.
if (!$useAttendanceEventPipeline) {
    $attendanceService->ensureBiometricTables();
    $attendanceService->ensureAttendanceAuditTable();
    $attendanceService->ensureEmployeeCodeColumn();
}

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
$identityFeedback = is_array($_SESSION['biometric_identity_feedback'] ?? null) ? $_SESSION['biometric_identity_feedback'] : null;
unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['biometric_identity_feedback']);
$biometricEntryMethods = [];
if ($useAttendanceEventPipeline) {
    try {
        $entryMethodFactory = new \EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory(
            $db,
            new \EduCore\Modules\Operations\Audit\AuditService($db)
        );
        $biometricEntryMethods = $entryMethodFactory->attendanceEntryMethods()->activeBiometricMethods();
    } catch (Throwable $exception) {
        error_log('biometric entry-method catalog unavailable: ' . $exception->getMessage());
        $error_message = $error_message ?: 'تعذر تحميل وسائل الحضور النشطة. تحقق من تطبيق تحديثات الحضور ثم أعد المحاولة.';
    }
}

// الحد الأقصى لعدد الصفوف في ملف البصمة الواحد (يُطبَّق أثناء القراءة لتفادي استهلاك الذاكرة)
const BIOMETRIC_MAX_ROWS = 20000;
// الحد الأقصى لحجم ملف البصمة (10 ميجابايت)
const BIOMETRIC_MAX_BYTES = 10 * 1024 * 1024;

if (!function_exists('parseBiometricCsvFile')) {
    /**
     * وضع user_id: العمود الأول رقم المستخدم الداخلي (id في جدول users).
     * وضع employee_code: العمود الأول كود الموظف ويُحَوَّل لاحقاً.
     * وضع biometric_identity: العمود الأول هو رقم البصمة في الجهاز؛ لا
     * يحوله هذا السطح إلى عامل، فالإسناد يمر عبر الربط المؤرخ في Attendance.
     * التنسيق: col0, datetime, log_type, device_id
     *
     * ملاحظة على الطابع الزمني: نمرّر قيمة التاريخ كما هي من الملف عند مطابقة الصيغة
     * القياسية Y-m-d H:i:s لتفادي إعادة تفسير الطابع الزمني عبر المنطقة الزمنية للخادم.
     */
    function parseBiometricCsvFile(string $tmpPath, string $mode = 'user_id'): array
    {
        $rows = [];
        $invalidRows = 0;
        $truncated = false;

        $fileHandle = fopen($tmpPath, 'r');
        if ($fileHandle === false) {
            return ['ok' => false, 'error' => 'تعذر قراءة ملف CSV.', 'rows' => [], 'invalid_rows' => 0, 'truncated' => false];
        }

        $lineNo = 0;
        while (($cols = fgetcsv($fileHandle)) !== false) {
            $lineNo++;
            if ($cols === [null] || $cols === false) {
                continue;
            }

            $firstCol  = isset($cols[0]) ? trim((string)$cols[0]) : '';
            $secondCol = isset($cols[1]) ? trim((string)$cols[1]) : '';

            // تخطي سطر العناوين
            if ($lineNo === 1 && (stripos($firstCol, 'user') !== false || stripos($firstCol, 'emp') !== false
                || stripos($secondCol, 'date') !== false || stripos($secondCol, 'time') !== false)) {
                continue;
            }

            if ($firstCol === '' && $secondCol === '') {
                continue;
            }

            // التحقق من صيغة الطابع الزمني — نفضّل التمرير المباشر للصيغة القياسية
            // للحفاظ على القيمة الأصلية دون إعادة تفسير زمني بواسطة الخادم.
            $logDatetime = normalizeBiometricDatetime($secondCol);
            if ($logDatetime === null) {
                $invalidRows++;
                continue;
            }

            if ($mode === 'user_id' && !ctype_digit($firstCol)) {
                $invalidRows++;
                continue;
            }
            if ($mode === 'biometric_identity' && ($firstCol === '' || strlen($firstCol) > 100)) {
                $invalidRows++;
                continue;
            }

            $logType = strtolower(trim((string)($cols[2] ?? 'unknown')));
            if (!in_array($logType, ['in', 'out', 'unknown'], true)) {
                $logType = 'unknown';
            }

            $rowDeviceId = trim((string)($cols[3] ?? ''));

            if ($mode === 'employee_code') {
                $rows[] = [
                    'employee_code' => $firstCol,
                    'log_datetime'  => $logDatetime,
                    'log_type'      => $logType,
                    'device_id'     => $rowDeviceId,
                    'raw_payload'   => json_encode($cols, JSON_UNESCAPED_UNICODE)
                ];
            } elseif ($mode === 'biometric_identity') {
                $rows[] = [
                    'biometric_identity' => $firstCol,
                    'log_datetime'       => $logDatetime,
                    'log_type'           => $logType,
                    'device_id'          => $rowDeviceId,
                    'raw_payload'        => json_encode($cols, JSON_UNESCAPED_UNICODE),
                ];
            } else {
                $rows[] = [
                    'user_id'      => (int)$firstCol,
                    'log_datetime' => $logDatetime,
                    'log_type'     => $logType,
                    'device_id'    => $rowDeviceId,
                    'raw_payload'  => json_encode($cols, JSON_UNESCAPED_UNICODE)
                ];
            }

            // توقّف مبكّر عند تجاوز الحد — يمنع استهلاك الذاكرة على الملفات الضخمة
            if (count($rows) >= BIOMETRIC_MAX_ROWS) {
                $truncated = true;
                break;
            }
        }
        fclose($fileHandle);

        return ['ok' => true, 'rows' => $rows, 'invalid_rows' => $invalidRows, 'mode' => $mode, 'truncated' => $truncated];
    }
}

if (!function_exists('normalizeBiometricDatetime')) {
    /**
     * يُرجع صيغة Y-m-d H:i:s أو null عند الفشل.
     * - الصيغة القياسية Y-m-d H:i:s تُمرَّر كما هي للحفاظ على الطابع الزمني الأصلي.
     * - الصيغ الأخرى تُحاوَل عبر strtotime كحلّ احتياطي.
     */
    function normalizeBiometricDatetime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // الصيغة القياسية — تمرير مباشر دون إعادة تفسير زمني
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value)) {
            return str_replace('T', ' ', $value);
        }

        // صيغة بدون ثوانٍ (Y-m-d H:i) — أضف الثواني
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $value)) {
            return str_replace('T', ' ', $value) . ':00';
        }

        // حلّ احتياطي للصيغ الأخرى عبر strtotime
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }
}

$biometricPreview = $_SESSION['biometric_preview'] ?? null;
$allowedLookupModes = $useAttendanceEventPipeline
    ? ['biometric_identity']
    : ['user_id', 'employee_code'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost(); // حماية CSRF لعمليات المعاينة/الاستيراد/الإلغاء
    if (isset($_POST['biometric_identity_intent'])) {
        try {
            $factory = new \EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory(
                $db,
                new \EduCore\Modules\Operations\Audit\AuditService($db)
            );
            $intent = (string) $_POST['biometric_identity_intent'];
            if ($intent === 'assign') {
                $receipt = $factory->biometricIdentityMappings()->assign(
                    (int) ($_SESSION['user_id'] ?? 0),
                    (int) ($_POST['device_id'] ?? 0),
                    (string) ($_POST['biometric_identity'] ?? ''),
                    (int) ($_POST['staff_user_id'] ?? 0),
                    new DateTimeImmutable((string) ($_POST['valid_from'] ?? '')),
                    trim((string) ($_POST['valid_to'] ?? '')) === '' ? null : new DateTimeImmutable((string) $_POST['valid_to']),
                    'admin_ui',
                    trim((string) ($_POST['retired_reason'] ?? '')) ?: null
                );
            } elseif ($intent === 'reassign') {
                $receipt = $factory->biometricIdentityMappings()->reassign(
                    (int) ($_SESSION['user_id'] ?? 0),
                    (int) ($_POST['device_id'] ?? 0),
                    (string) ($_POST['biometric_identity'] ?? ''),
                    (int) ($_POST['staff_user_id'] ?? 0),
                    new DateTimeImmutable((string) ($_POST['valid_from'] ?? '')),
                    'admin_ui',
                    (string) ($_POST['retired_reason'] ?? '')
                );
            } else {
                throw new DomainException('BIOMETRIC_MAPPING_ACTION_INVALID');
            }
            $_SESSION['biometric_identity_feedback'] = ['kind' => 'success', 'receipt' => $receipt];
        } catch (Throwable $exception) {
            error_log('biometric identity mapping failed: ' . $exception->getMessage());
            $_SESSION['biometric_identity_feedback'] = ['kind' => 'danger', 'code' => $exception->getMessage()];
        }
        header('Location: staff_biometric_import.php');
        exit();
    }
    if (isset($_POST['preview_biometric'])) {
        $defaultDeviceId  = trim((string)($_POST['default_device_id'] ?? ''));
        $lookupMode       = (string)($_POST['lookup_mode'] ?? '');
        if (!in_array($lookupMode, $allowedLookupModes, true)) {
            $_SESSION['error_message'] = $useAttendanceEventPipeline
                ? 'في وضع الحضور الجديد يجب أن يكون العمود الأول رقم البصمة في الجهاز.'
                : 'اختر وضع تعريف صالح للملف.';
            header('Location: staff_biometric_import.php');
            exit();
        }

        if (!isset($_FILES['biometric_csv']) || !is_array($_FILES['biometric_csv']) || ($_FILES['biometric_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $_SESSION['error_message'] = 'يرجى اختيار ملف CSV صالح لعرض المعاينة.';
            header('Location: staff_biometric_import.php');
            exit();
        }

        // الملف مؤقت للمعاينة فقط ولا يُنقل إلى التخزين الدائم. يظل التحقق
        // عبر الحارس الموحد إلزاميًا قبل قراءته.
        try {
            $validatedCsv = FileUploadGuard::validate(
                $_FILES['biometric_csv'],
                ['csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel']],
                BIOMETRIC_MAX_BYTES
            );
        } catch (InvalidArgumentException $ex) {
            $_SESSION['error_message'] = $ex->getMessage();
            header('Location: staff_biometric_import.php');
            exit();
        }

        $parsed = parseBiometricCsvFile($validatedCsv['tmp_name'], $lookupMode);
        if (!$parsed['ok']) {
            $_SESSION['error_message'] = $parsed['error'] ?? 'تعذر قراءة ملف CSV.';
            header('Location: staff_biometric_import.php');
            exit();
        }

        $parsedRows       = $parsed['rows'];
        $parseInvalidRows = (int)$parsed['invalid_rows'];
        $truncated        = !empty($parsed['truncated']);
        $unresolvedCodes  = [];

        // تحويل أكواد الموظفين إلى user_id هو مهايئ السجل القديم فقط. وضع
        // Attendance الجديد يطلب رقم بصمة ويترك ربط العامل للخدمة المؤرخة.
        if (!$useAttendanceEventPipeline && $lookupMode === 'employee_code') {
            $resolved        = $attendanceService->resolveEmployeeCodesFromRows($parsedRows);
            $unresolvedCodes = $resolved['unresolved_codes'];
            $parsedRows      = $resolved['rows'];
            if (!empty($unresolvedCodes)) {
                $parseInvalidRows += count($unresolvedCodes);
            }
        }

        if (empty($parsedRows)) {
            $_SESSION['error_message'] = 'لا توجد صفوف صالحة للاستيراد داخل الملف.'
                . (!empty($unresolvedCodes) ? ' أكواد غير معرّفة: ' . implode(', ', $unresolvedCodes) : '');
            header('Location: staff_biometric_import.php');
            exit();
        }

        // التنبيه عند اقتطاع الملف على الحد الأقصى يُعرض في ملخص المعاينة
        // (تم نقل فحص الحد داخل دالة القراءة لتفادي استهلاك الذاكرة على الملفات الضخمة).
        if ($useAttendanceEventPipeline) {
            $entryMethodId = filter_var($_POST['entry_method_id'] ?? null, FILTER_VALIDATE_INT);
            $deviceId = filter_var($defaultDeviceId, FILTER_VALIDATE_INT);
            $deviceTimezone = trim((string) ($_POST['device_timezone'] ?? 'Africa/Cairo'));
            if ($entryMethodId === false || (int) $entryMethodId <= 0) {
                $_SESSION['error_message'] = 'حدد وسيلة حضور معتمدة قبل عرض المعاينة.';
                header('Location: staff_biometric_import.php');
                exit();
            }
            if ($deviceId === false || (int) $deviceId <= 0) {
                $_SESSION['error_message'] = 'حدد رقم جهاز بصمة رقميًا وصحيحًا قبل عرض المعاينة.';
                header('Location: staff_biometric_import.php');
                exit();
            }

            try {
                $receivedAt = gmdate('Y-m-d\\TH:i:s\\Z');
                $fileFingerprint = hash_file('sha256', $validatedCsv['tmp_name']);
                if (!is_string($fileFingerprint) || strlen($fileFingerprint) !== 64) {
                    throw new RuntimeException('BIOMETRIC_FILE_FINGERPRINT_INVALID');
                }
                $prepared = (new \EduCore\Modules\Attendance\Application\BiometricCsvImportPreparationService())->prepare(
                    $parsedRows,
                    (int) $deviceId,
                    $deviceTimezone,
                    $receivedAt
                );
                $previewResult = $prepared['summary'];
                $previewResult['invalid_rows_total'] = $parseInvalidRows;
                $previewResult['duplicate_rows_in_db'] = null;
                $previewResult['lookup_mode'] = $lookupMode;
                $previewResult['truncated'] = $truncated;
                $previewResult['pipeline'] = 'attendance_event';

                $_SESSION['biometric_preview'] = [
                    'engine' => 'attendance_event',
                    'events' => $prepared['events'],
                    'batch' => [
                        'source_type' => 'file_import',
                        'device_id' => (int) $deviceId,
                        'entry_method_id' => (int) $entryMethodId,
                        'device_timezone' => $deviceTimezone,
                        'file_fingerprint' => $fileFingerprint,
                        'clock_drift_threshold_seconds' => 300,
                        'started_at' => $receivedAt,
                    ],
                    'idempotency_key' => 'csv-biometric-' . bin2hex(random_bytes(16)),
                    'summary' => $previewResult,
                    'prepared_at' => gmdate('Y-m-d H:i:s'),
                ];
            } catch (Throwable $exception) {
                error_log('new biometric preview failed: ' . $exception->getMessage());
                $_SESSION['error_message'] = \EduCore\Modules\Attendance\Presentation\BiometricImportErrorPresenter::present($exception);
            }
        } else {
            $previewResult = $attendanceService->previewBiometricRows($parsedRows, $defaultDeviceId);
            $previewResult['invalid_rows_total']  = $parseInvalidRows + (int)$previewResult['invalid_rows'];
            $previewResult['unresolved_codes']    = $unresolvedCodes;
            $previewResult['lookup_mode']         = $lookupMode;
            $previewResult['truncated']           = $truncated;

            $_SESSION['biometric_preview'] = [
                'engine'            => 'legacy',
                'rows'              => $parsedRows,
                'default_device_id' => $defaultDeviceId,
                'summary'           => $previewResult,
                'prepared_at'       => date('Y-m-d H:i:s')
            ];
        }

        header('Location: staff_biometric_import.php');
        exit();
    }

    if (isset($_POST['confirm_biometric'])) {
        $previewData = $_SESSION['biometric_preview'] ?? null;
        $usesNewPreview = is_array($previewData) && ($previewData['engine'] ?? '') === 'attendance_event';
        $hasPreviewRows = is_array($previewData) && !empty($previewData['rows']) && is_array($previewData['rows']);
        $hasPreviewEvents = is_array($previewData) && !empty($previewData['events']) && is_array($previewData['events']);
        if ((!$usesNewPreview && !$hasPreviewRows) || ($usesNewPreview && !$hasPreviewEvents)) {
            $_SESSION['error_message'] = 'لا توجد معاينة جاهزة للتأكيد. قم برفع الملف وعرض المعاينة أولاً.';
            header('Location: staff_biometric_import.php');
            exit();
        }

        $importedRowsCount = $usesNewPreview
            ? count($previewData['events'])
            : count($previewData['rows']);
        $wasTruncated = !empty($previewData['summary']['truncated']);
        try {
            if ($usesNewPreview) {
                $factory = new \EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory(
                    $db,
                    new \EduCore\Modules\Operations\Audit\AuditService($db)
                );
                $result = $factory->attendanceEventIngestor()->ingest(
                    (int) ($_SESSION['user_id'] ?? 0),
                    (array) $previewData['batch'],
                    (array) $previewData['events'],
                    (string) $previewData['idempotency_key']
                );
                $counts = (array) ($result['counts'] ?? []);
                $_SESSION['success_message'] = 'تم حفظ البصمات الخام في وضع '
                    . $staffHrFlags->mode()
                    . '. سجلات جديدة: ' . (int) ($counts['inserted'] ?? 0)
                    . ' | مكررة: ' . (int) ($counts['duplicates'] ?? 0)
                    . ' | غير مربوطة: ' . (int) ($counts['unmatched'] ?? 0)
                    . ' | متعارضة: ' . (int) ($counts['ambiguous'] ?? 0)
                    . '. لم يُعدّل سجل الحضور القديم تلقائيًا.';
            } else {
                $db->beginTransaction();
                $result = $attendanceService->importBiometricRows(
                    $previewData['rows'],
                    (int)($_SESSION['user_id'] ?? 0),
                    (string)($previewData['default_device_id'] ?? '')
                );
                $db->commit();

                $invalidTotal = (int)($previewData['summary']['invalid_rows_total'] ?? 0);
                $_SESSION['success_message'] = 'تم الاستيراد بنجاح. '
                    . 'سجلات جديدة: ' . (int)$result['inserted_logs']
                    . ' | مكررة: ' . (int)$result['duplicate_logs']
                    . ' | صفوف غير صالحة: ' . $invalidTotal
                    . ' | سجلات حضور تمت مزامنتها: ' . (int)$result['synced_attendance'];
                if ($wasTruncated) {
                    $_SESSION['success_message'] .= ' | تنبيه: تم اقتطاع الملف عند ' . BIOMETRIC_MAX_ROWS . ' سجل.';
                }

                // تسجيل عملية الاستيراد الدفعي في سجل النشاط للمراجعة الأمنية
                require_once '../classes/ActivityLog.php';
                ActivityLog::logImport('staff_biometric', (int)$result['inserted_logs'], [
                    'duplicate_logs' => (int)$result['duplicate_logs'],
                    'invalid_rows' => $invalidTotal,
                    'synced_attendance' => (int)$result['synced_attendance'],
                    'input_rows' => $importedRowsCount,
                    'truncated' => $wasTruncated,
                    'default_device_id' => (string)($previewData['default_device_id'] ?? ''),
                ]);
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // تسجيل التفاصيل الداخلية للسجل فقط — عدم إفشاء رسالة الاستثناء للمستخدم
            error_log('biometric import confirm failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $_SESSION['error_message'] = $usesNewPreview
                ? \EduCore\Modules\Attendance\Presentation\BiometricImportErrorPresenter::present($e)
                : 'حدث خطأ أثناء تنفيذ الاستيراد بعد المعاينة.';
        }

        unset($_SESSION['biometric_preview']);
        header('Location: staff_biometric_import.php');
        exit();
    }

    if (isset($_POST['cancel_biometric_preview'])) {
        unset($_SESSION['biometric_preview']);
        $_SESSION['success_message'] = 'تم إلغاء المعاينة.';
        header('Location: staff_biometric_import.php');
        exit();
    }
}

$recentLogs = [];
try {
    // This is a legacy history surface. New-mode imports are deliberately
    // reviewed through the migration-owned attendance query instead.
    $recentLogs = $attendanceService->getRecentBiometricLogs(200);
} catch (Throwable $exception) {
    error_log('legacy biometric history unavailable: ' . $exception->getMessage());
}
$biometricPreview = $_SESSION['biometric_preview'] ?? null;

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-fingerprint me-2"></i>استيراد بصمة الموظفين</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="hr_attendance_exceptions.php" class="btn btn-sm btn-outline-warning">
            <i class="fas fa-triangle-exclamation me-1"></i>مركز الاستثناءات
        </a>
        <a href="staff_attendance.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-right me-1"></i>العودة للحضور
        </a>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($useAttendanceEventPipeline): ?>
<div class="card shadow admin-card-surface mb-4" id="biometricIdentityMappingControl">
    <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-id-badge me-2"></i>الربط المؤرخ لهوية البصمة</h5></div>
    <div class="card-body">
        <?php if ($identityFeedback !== null): $identityReceipt = (array) ($identityFeedback['receipt'] ?? []); ?>
            <div class="alert alert-<?php echo htmlspecialchars((string) ($identityFeedback['kind'] ?? 'danger'), ENT_QUOTES, 'UTF-8'); ?>"
                 data-biometric-mapping-id="<?php echo (int) ($identityReceipt['mapping_id'] ?? $identityReceipt['new_mapping']['mapping_id'] ?? 0); ?>"
                 data-retired-mapping-id="<?php echo (int) ($identityReceipt['retired_mapping_id'] ?? 0); ?>"
                 data-biometric-mapping-code="<?php echo htmlspecialchars((string) ($identityFeedback['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo ($identityFeedback['kind'] ?? '') === 'success' ? 'تم حفظ الربط المؤرخ دون تعديل الأحداث الخام السابقة.' : 'تعذر حفظ الربط؛ توجد فترة متداخلة أو بيانات غير صالحة.'; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="row g-3" id="biometricIdentityMappingForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="biometric_identity_mapping_form" value="1">
            <div class="col-md-2"><label class="form-label">الإجراء</label><select class="form-select" name="biometric_identity_intent"><option value="assign">إسناد فترة</option><option value="reassign">إنهاء وإعادة إسناد</option></select></div>
            <div class="col-md-2"><label class="form-label">رقم الجهاز</label><input class="form-control" name="device_id" type="number" min="1" required></div>
            <div class="col-md-2"><label class="form-label">هوية البصمة</label><input class="form-control" name="biometric_identity" maxlength="100" required></div>
            <div class="col-md-2"><label class="form-label">العامل</label><input class="form-control" name="staff_user_id" type="number" min="1" required></div>
            <div class="col-md-2"><label class="form-label">بداية السريان</label><input class="form-control" name="valid_from" type="datetime-local" required></div>
            <div class="col-md-2"><label class="form-label">نهاية السريان</label><input class="form-control" name="valid_to" type="datetime-local"></div>
            <div class="col-md-10"><label class="form-label">سبب الإنهاء/إعادة الاستخدام</label><input class="form-control" name="retired_reason" maxlength="1000"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-save me-1"></i>حفظ الربط</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-file-import me-2"></i>استيراد من ملف CSV</h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
            <?php echo csrfField(); ?>
            <div class="col-md-5">
                <label class="form-label fw-semibold">ملف CSV <span class="text-danger">*</span></label>
                <input type="file" name="biometric_csv" class="form-control" accept=".csv,text/csv" required>
            </div>
            <?php if ($useAttendanceEventPipeline): ?>
                <input type="hidden" name="lookup_mode" value="biometric_identity">
                <div class="col-md-2">
                    <label class="form-label fw-semibold">رقم جهاز البصمة <span class="text-danger">*</span></label>
                    <input type="number" name="default_device_id" class="form-control" min="1" step="1" required placeholder="7">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">وسيلة الحضور <span class="text-danger">*</span></label>
                    <select name="entry_method_id" class="form-select" required><option value="">اختر الوسيلة</option><?php foreach ($biometricEntryMethods as $entryMethod): $entryMethodId = (int) ($entryMethod['id'] ?? 0); if ($entryMethodId <= 0) { continue; } ?><option value="<?php echo $entryMethodId; ?>"><?php echo htmlspecialchars((string) ($entryMethod['name'] ?? $entryMethod['code'] ?? ('#' . $entryMethodId)), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">توقيت الجهاز</label>
                    <input type="text" name="device_timezone" class="form-control" maxlength="64" value="Africa/Cairo" required>
                </div>
                <div class="col-md-1">
                    <button type="submit" name="preview_biometric" class="btn btn-primary w-100" title="معاينة قبل الحفظ">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            <?php else: ?>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">وضع التعريف</label>
                    <select name="lookup_mode" class="form-select" id="lookupModeSelect">
                        <option value="user_id">رقم المستخدم (user_id)</option>
                        <option value="employee_code">كود الموظف</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">معرف الجهاز (اختياري)</label>
                    <input type="text" name="default_device_id" class="form-control" maxlength="100" placeholder="DEV-01">
                </div>
                <div class="col-md-3">
                    <button type="submit" name="preview_biometric" class="btn btn-primary w-100">
                        <i class="fas fa-eye me-1"></i>معاينة قبل الاستيراد
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($useAttendanceEventPipeline): ?>
            <div class="alert alert-info mt-3 mb-1">
                <i class="fas fa-shield-halved me-2"></i>
                <strong>وضع الحضور الجديد (<?php echo htmlspecialchars($staffHrFlags->mode(), ENT_QUOTES, 'UTF-8'); ?>):</strong>
                العمود الأول هو رقم البصمة في الجهاز، وليس كود الموظف أو رقمه الداخلي. يجب أن يكون الرقم مربوطًا مسبقًا بعامل عبر الربط المؤرخ؛
                يحفظ الاستيراد سجلات بصمة خام قابلة للمراجعة فقط، ولا يغيّر حضور العامل القديم أو نتيجته اليومية تلقائيًا.
            </div>
            <div class="alert alert-secondary mb-0">
                <i class="fas fa-table me-2"></i>تنسيق CSV: <strong>biometric_identity, log_datetime, log_type (in/out/unknown), device_id</strong><br>
                رقم الجهاز في كل صف اختياري، لكن إن وُجد يجب أن يطابق رقم الجهاز أعلى النموذج.
                مثال: <code>F-9001, 2026-03-19 07:28:00, in, 7</code>
            </div>
        <?php else: ?>
            <div class="alert alert-info mt-3 mb-1">
                <i class="fas fa-info-circle me-2"></i>
                <strong>وضع رقم المستخدم:</strong> العمود الأول هو <code>id</code> الموظف في النظام.<br>
                <strong>وضع كود الموظف:</strong> العمود الأول كود الموظف المسجل في حقل <code>employee_code</code> (يُعيَّن من صفحة بيانات الموظفين).
            </div>
            <div class="alert alert-secondary mb-0">
                <i class="fas fa-table me-2"></i>تنسيق CSV: <strong>col1, log_datetime, log_type (in/out/unknown), device_id</strong><br>
                مثال: <code>15, 2026-03-19 07:28:00, in, DEV-01</code>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($biometricPreview) && !empty($biometricPreview['summary'])):
    $summary = $biometricPreview['summary'];
    $isNewBiometricPreview = ($biometricPreview['engine'] ?? '') === 'attendance_event';
?>
<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>نتيجة المعاينة قبل الاستيراد</h5>
    </div>
    <div class="card-body">
        <div class="row row-cols-2 row-cols-md-4 g-3 mb-3">
            <div class="col"><span class="badge bg-success">صالح: <?php echo (int)$summary['valid_rows']; ?></span></div>
            <div class="col"><span class="badge bg-danger">غير صالح: <?php echo (int)$summary['invalid_rows_total']; ?></span></div>
            <div class="col"><span class="badge bg-warning text-dark">مكرر داخل الملف: <?php echo (int)$summary['duplicate_rows_in_file']; ?></span></div>
            <?php if ($isNewBiometricPreview): ?>
                <div class="col"><span class="badge bg-secondary">فحص التكرار بالنظام: عند التأكيد</span></div>
            <?php else: ?>
                <div class="col"><span class="badge bg-secondary">موجود مسبقاً بالنظام: <?php echo (int)$summary['duplicate_rows_in_db']; ?></span></div>
            <?php endif; ?>
            <div class="col"><span class="badge bg-primary">سجلات جديدة متوقعة: <?php echo (int)$summary['new_rows']; ?></span></div>
            <div class="col"><span class="badge bg-info text-dark"><?php echo $isNewBiometricPreview ? 'أيام تشملها الأدلة' : 'أيام متوقعة للمزامنة'; ?>: <?php echo (int)$summary['estimated_attendance_days_to_sync']; ?></span></div>
        </div>

        <?php if ($isNewBiometricPreview): ?>
        <div class="alert alert-warning mb-3">
            <i class="fas fa-circle-info me-2"></i>
            التأكيد التالي يحفظ دليل البصمة الخام فقط. سيظهر السجل غير المربوط أو المتعارض في
            <a href="hr_attendance_exceptions.php" class="alert-link">مركز الاستثناءات</a>،
            ولا تُحسب النتيجة اليومية أو تُعدَّل بيانات الحضور القديمة ضمن هذه العملية.
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['truncated'])): ?>
        <div class="alert alert-warning mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i>
            تم اقتطاع الملف عند الحد الأقصى المسموح به (<strong><?php echo (int)BIOMETRIC_MAX_ROWS; ?> سجل</strong>).
            الصفوف الإضافية لم تُقرأ. قسّم الملف إلى دفعات أصغر لاستيراد الكل.
        </div>
        <?php endif; ?>

        <div class="d-flex gap-2 flex-wrap mb-3">
            <form method="POST" class="m-0">
                <?php echo csrfField(); ?>
                <button type="submit" name="confirm_biometric" class="btn btn-success">
                    <i class="fas fa-check me-1"></i><?php echo $isNewBiometricPreview ? 'تأكيد حفظ السجلات الخام' : 'تأكيد الاستيراد الآن'; ?>
                </button>
            </form>
            <form method="POST" class="m-0">
                <?php echo csrfField(); ?>
                <button type="submit" name="cancel_biometric_preview" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i>إلغاء المعاينة
                </button>
            </form>
        </div>

        <?php if (!empty($summary['preview_rows'])): ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php echo $isNewBiometricPreview ? 'معرّف البصمة' : 'user_id'; ?></th>
                            <th>log_datetime</th>
                            <th>log_type</th>
                            <th>device_id</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary['preview_rows'] as $i => $pr): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <?php if ($isNewBiometricPreview): ?>
                                        <?php echo htmlspecialchars((string)($pr['identity_hint'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php else: ?>
                                        <?php echo (int)$pr['user_id']; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($pr['log_datetime'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($pr['log_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($pr['device_id'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ($isNewBiometricPreview && !empty($pr['duplicate_in_file'])): ?>
                                        <span class="badge bg-warning text-dark">مكرر داخل الملف</span>
                                    <?php elseif (!$isNewBiometricPreview && !empty($pr['exists_in_db'])): ?>
                                        <span class="badge bg-secondary">موجود مسبقاً</span>
                                    <?php else: ?>
                                        <span class="badge bg-success"><?php echo $isNewBiometricPreview ? 'جاهز كدليل خام' : 'سجل جديد'; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-muted">يتم عرض أول 200 سجل فقط في المعاينة.</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>آخر سجلات البصمة المستوردة</h5>
    </div>
    <div class="card-body">
        <?php if (!empty($recentLogs)): ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped" id="biometricLogsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الموظف</th>
                            <th>التاريخ والوقت</th>
                            <th>النوع</th>
                            <th>الجهاز</th>
                            <th>وقت الاستيراد</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $i => $row): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo htmlspecialchars($row['staff_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['log_datetime'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ($row['log_type'] === 'in'): ?>
                                        <span class="badge bg-success">دخول</span>
                                    <?php elseif ($row['log_type'] === 'out'): ?>
                                        <span class="badge bg-warning text-dark">خروج</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">غير محدد</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['device_id'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>لا توجد سجلات بصمة مستوردة بعد.</div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        $('#biometricLogsTable').DataTable({
            pageLength: 50,
            order: [[2, 'desc']],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json',
                search: 'بحث:',
                lengthMenu: 'عرض _MENU_ سجل',
                info: 'عرض _START_ إلى _END_ من _TOTAL_ سجل'
            }
        });
    }
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
