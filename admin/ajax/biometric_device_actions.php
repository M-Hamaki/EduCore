<?php
/**
 * معالج AJAX لعمليات أجهزة البصمة
 * اختبار الاتصال، المزامنة، جلب المستخدمين
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Biometric Device Error [$errno]: $errstr in $errfile on $errline");
    return true;
});

require_once '../../includes/session_config.php';
require_once '../../config/database.php';
require_once '../../classes/utilities.php';
require_once '../../classes/ZKTecoDevice.php';
require_once '../../classes/StaffAttendanceService.php';
require_once '../../classes/StaffBiometricIdentityService.php';
require_once '../../classes/StaffProfileErrorPresenter.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

Utilities::validateSession('admin');

// CSRF validation
$csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'رمز الأمان غير صالح']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$biometricIdentityService = new StaffBiometricIdentityService($db);
$action = $_POST['action'] ?? '';

switch ($action) {

    case 'test_connection':
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $device = getDevice($db, $deviceId);
        if (!$device) {
            echo json_encode(['success' => false, 'error' => 'الجهاز غير موجود']);
            exit;
        }

        $zk = new ZKTecoDevice($device['ip_address'], (int)$device['port'], 8, (int)($device['comm_password'] ?? 0), $device['protocol'] ?? 'auto');
        $result = $zk->testConnection();

        if ($result['success']) {
            // تحديث الرقم التسلسلي إذا تم جلبه
            if (!empty($result['serial'])) {
                $db->prepare("UPDATE biometric_devices SET serial_number = ? WHERE id = ? AND (serial_number IS NULL OR serial_number = '')")
                   ->execute([$result['serial'], $deviceId]);
            }
            echo json_encode(['success' => true, 'info' => $result]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'فشل الاتصال']);
        }
        break;

    case 'sync_device':
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $device = getDevice($db, $deviceId);
        if (!$device) {
            echo json_encode(['success' => false, 'error' => 'الجهاز غير موجود']);
            exit;
        }

        $result = syncSingleDevice($db, $device, (int)($_SESSION['user_id'] ?? 0), 'manual');
        echo json_encode($result);
        break;

    case 'sync_all':
        $devices = $db->query("SELECT * FROM biometric_devices WHERE is_active = 1 AND auto_sync = 1")
                       ->fetchAll(PDO::FETCH_ASSOC);

        if (empty($devices)) {
            echo json_encode(['success' => false, 'error' => 'لا توجد أجهزة مفعّلة للمزامنة']);
            exit;
        }

        $results = [];
        $adminId = (int)($_SESSION['user_id'] ?? 0);
        foreach ($devices as $device) {
            $r = syncSingleDevice($db, $device, $adminId, 'manual');
            $r['device_name'] = $device['device_name'];
            $results[] = $r;
        }

        echo json_encode(['success' => true, 'results' => $results]);
        break;

    case 'get_enrolled_users':
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $device = getDevice($db, $deviceId);
        if (!$device) {
            echo json_encode(['success' => false, 'error' => 'الجهاز غير موجود']);
            exit;
        }

        $zk = new ZKTecoDevice($device['ip_address'], (int)$device['port'], 10, (int)($device['comm_password'] ?? 0), $device['protocol'] ?? 'auto');
        if (!$zk->connect()) {
            echo json_encode(['success' => false, 'error' => $zk->getLastError()]);
            exit;
        }

        $users = $zk->getEnrolledUsers();
        $zk->disconnect();

        // ربط أسماء الموظفين من قاعدة البيانات بأرقام الجهاز
        $uids = array_column($users, 'uid');
        $staffMap = [];
        if (!empty($uids)) {
            $in = implode(',', array_fill(0, count($uids), '?'));
            $stmt = $db->prepare(
                "SELECT u.id, u.name,
                        NULLIF(TRIM(sp.biometric_id), '') AS employee_code
                 FROM users u
                 INNER JOIN staff_profiles sp ON sp.user_id = u.id
                 WHERE NULLIF(TRIM(sp.biometric_id), '') IN ($in)
                   AND u.status = 'active'"
            );
            $stmt->execute($uids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $staffMap[$row['employee_code']] = $row['name'];
            }
        }
        foreach ($users as &$u) {
            $u['staff_name'] = $staffMap[$u['uid']] ?? null;
        }
        unset($u);

        echo json_encode(['success' => true, 'users' => $users, 'count' => count($users)]);
        break;

    case 'bulk_map_employees':
        $mappingsJson = $_POST['mappings'] ?? '[]';
        $mappings = json_decode($mappingsJson, true);
        if (!is_array($mappings) || empty($mappings)) {
            echo json_encode(['success' => false, 'error' => 'لا توجد بيانات ربط']);
            exit;
        }

        try {
            $db->beginTransaction();
            $successCount = 0;
            $items = [];
            foreach ($mappings as $map) {
                $userId = (int)($map['user_id'] ?? 0);
                $code = trim((string)($map['employee_code'] ?? ''));
                if ($userId <= 0 || $code === '') {
                    continue;
                }
                $sync = $biometricIdentityService->synchronizeWithinTransaction(
                    $userId,
                    $code
                );
                $items[] = [
                    'table' => 'staff_profiles',
                    'record_id' => (int)($sync['before_profile']['id'] ?? 0),
                    'before' => $sync['before_profile'],
                    'after' => $sync['after_profile'],
                    'description' => 'تحديث رقم البصمة في ملف العامل',
                ];
                $successCount++;
            }
            if ($items) {
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordCompositeUpdate(
                    'biometric_employee_mapping', null, 'ربط أكواد موظفي البصمة',
                    $items, ['count' => $successCount]
                );
            }
            $db->commit();
            echo json_encode([
                'success' => true,
                'updated' => $successCount,
                'total' => count($mappings),
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode([
                'success' => false,
                'error' => StaffProfileErrorPresenter::saveMessage(
                    $e,
                    'biometric_bulk_update'
                ),
            ]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'إجراء غير معرّف']);
}

// ====== Helper Functions ======

function getDevice(PDO $db, int $id): ?array
{
    $stmt = $db->prepare("SELECT * FROM biometric_devices WHERE id = ?");
    $stmt->execute([$id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    return $device ?: null;
}

/**
 * مزامنة جهاز واحد:
 * 1. الاتصال بالجهاز وسحب سجلات الحضور
 * 2. تحويل السجلات لصيغة employee_code وربطها بالمستخدمين
 * 3. استيراد عبر StaffAttendanceService::importBiometricRows
 * 4. تسجيل العملية في biometric_sync_log
 */
function syncSingleDevice(PDO $db, array $device, int $adminId, string $syncType = 'manual'): array
{
    $deviceId = (int)$device['id'];
    $startedAt = date('Y-m-d H:i:s');

    $logStmt = $db->prepare("INSERT INTO biometric_sync_log (device_id, sync_type, started_at, synced_by) VALUES (?, ?, ?, ?)");
    $logStmt->execute([$deviceId, $syncType, $startedAt, $adminId]);
    $syncLogId = (int)$db->lastInsertId();

    try {
        $zk = new ZKTecoDevice($device['ip_address'], (int)$device['port'], 10, (int)($device['comm_password'] ?? 0), $device['protocol'] ?? 'auto');
        if (!$zk->connect()) {
            $error = $zk->getLastError();
            updateSyncLog($db, $syncLogId, 'error', 0, 0, 0, 0, 0, $error);
            updateDeviceStatus($db, $deviceId, 'error', 0, $error);
            recordBiometricSyncOutcome($db, $device, $syncLogId, 'error', ['error' => $error]);
            return ['success' => false, 'status' => 'error', 'error' => $error];
        }

        $logs = $zk->getAttendanceLogs();
        $totalRecords = count($logs);

        if ($totalRecords === 0) {
            $zk->disconnect();
            $msg = 'لا توجد سجلات جديدة على الجهاز';
            updateSyncLog($db, $syncLogId, 'success', 0, 0, 0, 0, 0, $msg);
            updateDeviceStatus($db, $deviceId, 'success', 0, $msg);
            recordBiometricSyncOutcome($db, $device, $syncLogId, 'success', ['total_records' => 0]);
            return ['success' => true, 'status' => 'success', 'result' => [
                'total_records' => 0, 'new_records' => 0, 'duplicate_records' => 0,
                'unmapped_records' => 0, 'synced_attendance' => 0
            ]];
        }

        // تحويل السجلات لصيغة employee_code
        $deviceIdentifier = $device['serial_number'] ?: $device['ip_address'];
        $rows = [];
        foreach ($logs as $log) {
            $rows[] = [
                'employee_code' => $log['uid'] ?? '',
                'log_datetime' => $log['datetime'] ?? '',
                'log_type' => $log['log_type'] ?? 'unknown',
                'device_id' => $deviceIdentifier,
                'raw_payload' => json_encode($log, JSON_UNESCAPED_UNICODE),
            ];
        }

        // ربط أكواد المستخدمين بـ user_id عبر StaffAttendanceService
        $attendanceService = new StaffAttendanceService($db);
        $attendanceService->ensureBiometricTables();
        $attendanceService->ensureAttendanceAuditTable();
        $attendanceService->ensureEmployeeCodeColumn();

        $resolved = $attendanceService->resolveEmployeeCodesFromRows($rows);
        $unmappedRecords = count($resolved['unresolved_codes']);
        $resolvedRows = $resolved['rows'];

        // استيراد السجلات المربوطة
        // ملاحظة: لا نستخدم transaction هنا لأن importBiometricRows يستدعي ensureBiometricTables
        // الذي يحتوي DDL (CREATE TABLE) وهذا يعمل auto-commit ضمني في MySQL
        $importResult = $attendanceService->importBiometricRows($resolvedRows, $adminId, $deviceIdentifier);

        $newRecords = (int)$importResult['inserted_logs'];
        $duplicateRecords = (int)$importResult['duplicate_logs'];
        $syncedAttendance = (int)$importResult['synced_attendance'];

        // مسح السجلات من الجهاز إذا مطلوب
        if ($device['clear_after_sync'] && $newRecords > 0) {
            $zk->clearAttendanceLogs();
        }

        $zk->disconnect();

        $status = ($unmappedRecords > 0 && $newRecords > 0) ? 'partial' : ($unmappedRecords > 0 && $newRecords === 0 ? 'error' : 'success');
        updateSyncLog($db, $syncLogId, $status, $totalRecords, $newRecords, $duplicateRecords, $unmappedRecords, $syncedAttendance, $unmappedRecords > 0 ? 'أكواد غير مربوطة: ' . implode(', ', $resolved['unresolved_codes']) : null);
        updateDeviceStatus($db, $deviceId, $status, $newRecords, null);

        $db->prepare("UPDATE biometric_devices SET total_synced_records = total_synced_records + ? WHERE id = ?")
           ->execute([$newRecords, $deviceId]);

        recordBiometricSyncOutcome($db, $device, $syncLogId, $status, [
            'total_records' => $totalRecords,
            'new_records' => $newRecords,
            'duplicate_records' => $duplicateRecords,
            'unmapped_records' => $unmappedRecords,
            'synced_attendance' => $syncedAttendance,
        ]);
        return ['success' => true, 'status' => $status, 'result' => [
            'total_records' => $totalRecords,
            'new_records' => $newRecords,
            'duplicate_records' => $duplicateRecords,
            'unmapped_records' => $unmappedRecords,
            'synced_attendance' => $syncedAttendance
        ]];

    } catch (Exception $e) {
        $error = 'خطأ في المزامنة: ' . $e->getMessage();
        error_log('Biometric sync error for device ' . $deviceId . ': ' . $e->getMessage());
        updateSyncLog($db, $syncLogId, 'error', 0, 0, 0, 0, 0, $error);
        updateDeviceStatus($db, $deviceId, 'error', 0, $error);
        recordBiometricSyncOutcome($db, $device, $syncLogId, 'error', ['error' => $error]);
        return ['success' => false, 'status' => 'error', 'error' => $error];
    }
}

function recordBiometricSyncOutcome(PDO $db, array $device, int $syncLogId, string $status, array $result): void
{
    try {
        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
            'sync', 'biometric_device', (int)$device['id'],
            (string)($device['device_name'] ?? ('جهاز #' . $device['id'])),
            [
                'sync_log_id' => $syncLogId,
                'status' => $status,
                'result' => $result,
                'undo_policy' => 'external_device_sync_not_undoable',
            ]
        );
    } catch (Throwable $auditError) {
        error_log('Biometric sync activity audit error: ' . $auditError->getMessage());
    }
}

function updateSyncLog(PDO $db, int $logId, string $status, int $total, int $new, int $dup, int $unmapped, int $synced, ?string $error): void
{
    $db->prepare("UPDATE biometric_sync_log SET completed_at = NOW(), status = ?, total_records = ?, new_records = ?, duplicate_records = ?, unmapped_records = ?, synced_attendance = ?, error_message = ? WHERE id = ?")
       ->execute([$status, $total, $new, $dup, $unmapped, $synced, $error, $logId]);
}

function updateDeviceStatus(PDO $db, int $deviceId, string $status, int $records, ?string $message): void
{
    $db->prepare("UPDATE biometric_devices SET last_sync_at = NOW(), last_sync_status = ?, last_sync_records = ?, last_sync_message = ? WHERE id = ?")
       ->execute([$status, $records, $message, $deviceId]);
}
