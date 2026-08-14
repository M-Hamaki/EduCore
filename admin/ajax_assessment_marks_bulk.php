<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/AssessmentMarkAdministrationService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

$db = null;
try {
    $db = (new Database())->getConnection();
    $academicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
    $academicYearId = (int) ($academicYear['id'] ?? 0);
    if ($academicYearId <= 0 || (int) ($_POST['academic_year_id'] ?? 0) !== $academicYearId) {
        throw new RuntimeException('العام الدراسي المختار غير صالح أو لم يعد هو العام المحدد.');
    }

    (new AcademicYearWriteGuard($db))->assertWritable($academicYearId);
    $service = new AssessmentMarkAdministrationService($db);
    $action = trim((string) ($_POST['action'] ?? ''));
    $actorId = (int) ($_SESSION['user_id'] ?? 0);
    $actorRole = (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');

    if ($action === 'update') {
        $markIds = AssessmentMarkAdministrationService::normalizeIds($_POST['mark_ids'] ?? []);
        $result = $service->bulkUpdateMarks($markIds, $_POST, $academicYearId, $actorId, $actorRole);
        $message = 'تم تعديل ' . (int) $result['affected'] . ' درجة في عملية ذرية واحدة.';
    } elseif ($action === 'apply_cells') {
        $changes = json_decode((string) ($_POST['changes'] ?? ''), true);
        if (!is_array($changes)) {
            throw new InvalidArgumentException('بيانات خلايا العملية الجماعية غير صحيحة.');
        }
        $result = $service->bulkApplyCells(
            $changes,
            $academicYearId,
            $actorId,
            $actorRole,
            (string) ($_POST['reason'] ?? '')
        );
        $message = 'تم حفظ ' . (int) $result['affected'] . ' خلية في عملية ذرية واحدة.';
    } elseif ($action === 'delete') {
        $markIds = AssessmentMarkAdministrationService::normalizeIds($_POST['mark_ids'] ?? []);
        $result = $service->deleteMarks(
            $markIds,
            $academicYearId,
            $actorId,
            $actorRole,
            (string) ($_POST['reason'] ?? '')
        );
        $message = 'تم حذف ' . (int) $result['affected'] . ' درجة وتسجيل العملية للسوبر أدمن.';
    } else {
        throw new InvalidArgumentException('نوع العملية الجماعية غير صحيح.');
    }

    echo json_encode([
        'ok' => true,
        'message' => $message,
        'affected' => (int) ($result['affected'] ?? 0),
        'published_count' => (int) ($result['published_count'] ?? 0),
        'batch_id' => (string) ($result['batch_id'] ?? ''),
        'mark_ids' => array_values(array_map('intval', $result['mark_ids'] ?? [])),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Assessment marks sheet bulk action: ' . $error->getMessage());
    $safeMessage = !($error instanceof PDOException)
        && ($error instanceof InvalidArgumentException || get_class($error) === RuntimeException::class)
        ? $error->getMessage()
        : 'تعذر تنفيذ العملية الجماعية. لم يتغير أي سجل.';
    http_response_code($error instanceof AssessmentMarkConflictException ? 409 : ($error instanceof PDOException ? 500 : 422));
    echo json_encode(['ok' => false, 'message' => $safeMessage], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
