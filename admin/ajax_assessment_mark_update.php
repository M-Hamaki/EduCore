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
    $requestedYearId = (int) ($_POST['academic_year_id'] ?? 0);
    if ($academicYearId <= 0 || $requestedYearId !== $academicYearId) {
        throw new RuntimeException('العام الدراسي المختار غير صالح أو لم يعد هو العام المحدد.');
    }

    (new AcademicYearWriteGuard($db))->assertWritable($academicYearId);
    $payload = $_POST;
    $payload['reason'] = trim((string) ($payload['reason'] ?? '')) !== ''
        ? (string) $payload['reason']
        : 'إدخال مباشر من شيت الدرجات';
    $service = new AssessmentMarkAdministrationService($db);
    $markId = (int) ($_POST['mark_id'] ?? 0);
    if ($markId > 0) {
        $result = $service->updateMark(
            $markId,
            $payload,
            $academicYearId,
            (int) ($_SESSION['user_id'] ?? 0),
            (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')
        );
    } else {
        $weekId = array_key_exists('week_id', $_POST) && $_POST['week_id'] !== ''
            ? (int) $_POST['week_id']
            : null;
        $result = $service->createMark(
            (int) ($_POST['student_id'] ?? 0),
            (int) ($_POST['window_id'] ?? 0),
            (int) ($_POST['scheme_id'] ?? 0),
            (int) ($_POST['component_id'] ?? 0),
            $weekId,
            $payload,
            $academicYearId,
            (int) ($_SESSION['user_id'] ?? 0),
            (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')
        );
        $markId = (int) ($result['mark_id'] ?? 0);
    }

    $mark = null;
    if ($markId > 0) {
        $markStmt = $db->prepare('SELECT id, value, mark_status, note, review_status, updated_at
            FROM student_marks WHERE id = ? AND academic_year_id = ? LIMIT 1');
        $markStmt->execute([$markId, $academicYearId]);
        $mark = $markStmt->fetch(PDO::FETCH_ASSOC);
        if (!$mark) {
            throw new RuntimeException('تعذر قراءة الدرجة بعد حفظها.');
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => !empty($result['no_change']) ? 'القيمة محفوظة بالفعل.' : 'تم الحفظ تلقائيًا وتسجيل التغيير.',
        'batch_id' => (string) ($result['batch_id'] ?? ''),
        'mark' => $mark ? [
            'id' => (int) $mark['id'],
            'value' => $mark['value'] !== null ? (float) $mark['value'] : null,
            'status' => (string) $mark['mark_status'],
            'note' => (string) ($mark['note'] ?? ''),
            'review_status' => (string) $mark['review_status'],
            'published_count' => (int) ($result['published_count'] ?? 0),
            'updated_at' => (string) ($mark['updated_at'] ?? ''),
        ] : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Assessment mark inline update: ' . $error->getMessage());
    $safeMessage = !($error instanceof PDOException)
        && ($error instanceof InvalidArgumentException || get_class($error) === RuntimeException::class)
        ? $error->getMessage()
        : 'تعذر حفظ تصحيح الدرجة. لم تُنفذ العملية.';
    http_response_code($error instanceof AssessmentMarkConflictException ? 409 : ($error instanceof PDOException ? 500 : 422));
    echo json_encode(['ok' => false, 'message' => $safeMessage], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
