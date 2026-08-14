<?php

declare(strict_types=1);

/**
 * Stable legacy URL for default and per-worker shift fields.
 * Persistence is delegated to the audited application owner while the new
 * policy/calendar page supplies the shared presentation.
 */
$page_title = 'إعدادات الدوام';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/HrSchemaGuard.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
(new HrSchemaGuard($db))->assertTable('staff_shift_overrides');

$attendanceFactory = new \EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory(
    $db,
    new \EduCore\Modules\Operations\Audit\AuditService($db)
);
$legacyShiftService = $attendanceFactory->legacyStaffShiftCompatibility();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_default_shift'])) {
            // Preserved fields: default_shift_start/default_shift_end/default_shift_grace_minutes.
            $legacyShiftService->saveDefaultShift($_POST);
            $_SESSION['success_message'] = 'تم حفظ إعدادات الدوام الافتراضي بنجاح.';
        } elseif (isset($_POST['save_shift_override'])) {
            // Preserved fields: user_id/shift_start/shift_end/grace_minutes/is_active/notes.
            $legacyShiftService->saveOverride($_POST);
            $_SESSION['success_message'] = 'تم حفظ الدوام المخصص بنجاح.';
        } elseif (isset($_POST['delete_shift_override'])) {
            // Preserved field: id.
            $legacyShiftService->deleteOverride((int) ($_POST['id'] ?? 0));
            $_SESSION['success_message'] = 'تم حذف الدوام المخصص.';
        }
    } catch (InvalidArgumentException $exception) {
        $_SESSION['error_message'] = $exception->getMessage();
    } catch (Throwable $exception) {
        $reference = 'SHIFT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        error_log($reference . ' legacy staff shift error: ' . $exception->getMessage());
        $_SESSION['error_message'] = 'تعذر تنفيذ العملية الآن. راجع البيانات وحاول مرة أخرى. مرجع المتابعة: ' . $reference;
    }

    header('Location: staff_shifts.php');
    exit();
}

try {
    $legacyView = $legacyShiftService->viewData();
    $defaultShiftStart = (string) $legacyView['defaultShiftStart'];
    $defaultShiftEnd = (string) $legacyView['defaultShiftEnd'];
    $defaultGrace = (int) $legacyView['defaultGrace'];
    $staffList = (array) $legacyView['staffList'];
    $overrides = (array) $legacyView['overrides'];
} catch (Throwable $exception) {
    $reference = 'SHIFT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    error_log($reference . ' legacy staff shift read error: ' . $exception->getMessage());
    $error_message = 'تعذر تحميل إعدادات الدوام. مرجع المتابعة: ' . $reference;
    $defaultShiftStart = '07:30';
    $defaultShiftEnd = '14:30';
    $defaultGrace = 15;
    $staffList = [];
    $overrides = [];
}

define('STAFF_SHIFTS_COMPATIBILITY_MODE', true);
require __DIR__ . '/hr_policy_calendar.php';
