<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AssessmentMarkSheetQuery.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = (new Database())->getConnection();
    $academicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
    $academicYearId = (int) ($academicYear['id'] ?? 0);
    $requestedYearId = (int) ($_POST['academic_year_id'] ?? 0);
    if ($academicYearId <= 0 || $requestedYearId !== $academicYearId) {
        throw new RuntimeException('العام الدراسي المختار غير صالح أو لم يعد هو العام المحدد.');
    }

    $result = (new AssessmentMarkSheetQuery($db))->load(
        $academicYearId,
        (int) ($_POST['grade_id'] ?? 0),
        (int) ($_POST['term_id'] ?? 0),
        (int) ($_POST['scheme_id'] ?? 0),
        max(0, (int) ($_POST['class_id'] ?? 0))
    );
    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('Assessment marks sheet endpoint: ' . $error->getMessage());
    $safeMessage = !($error instanceof PDOException)
        && ($error instanceof InvalidArgumentException || get_class($error) === RuntimeException::class)
        ? $error->getMessage()
        : 'تعذر تحميل شيت الدرجات. حاول مرة أخرى.';
    http_response_code($error instanceof PDOException ? 500 : 422);
    echo json_encode([
        'ok' => false,
        'message' => $safeMessage,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
