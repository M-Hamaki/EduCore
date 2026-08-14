<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/StudentArchiveQuery.php';
require_once '../classes/StudentArchiveService.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $result = (new StudentArchiveQuery((new Database())->getConnection()))->loadDataTable($_POST);
    $escape = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
    $statusLabels = ['active' => 'نشط', 'inactive' => 'غير نشط', 'graduated' => 'خريج'];
    $enrollmentLabels = ['enrolled' => 'مقيد', 'graduated' => 'خريج', 'transferred' => 'منقول', 'withdrawn' => 'منسحب'];
    $offset = max(0, (int)($_POST['start'] ?? 0));
    $data = [];

    foreach ($result['students'] as $index => $student) {
        $confirmationCode = trim((string)($student['student_code'] ?: $student['id']));
        $archiveDate = new DateTimeImmutable((string)$student['deleted_at']);
        $deleteReadyAt = $archiveDate->modify('+' . StudentArchiveService::PERMANENT_DELETE_DELAY_HOURS . ' hours');
        $deleteDelayPassed = $deleteReadyAt <= new DateTimeImmutable('now');
        $enrollmentStatus = (string)($student['enrollment_status'] ?: $student['profile_enrollment_status']);
        $name = (string)$student['name'];
        $restore = '<button type="button" class="btn btn-action-pills btn-activate restore-student me-1" data-id="' . (int)$student['id'] . '" data-name="' . $escape($name) . '" data-bs-toggle="tooltip" title="استرجاع"><i class="fas fa-trash-restore"></i></button>';
        $deleteTitle = $deleteDelayPassed ? 'حذف نهائي' : 'متاح بعد ' . $deleteReadyAt->format('Y-m-d H:i');
        $delete = '<button type="button" class="btn btn-action-pills btn-delete permanent-delete-student" data-id="' . (int)$student['id'] . '" data-name="' . $escape($name) . '" data-code="' . $escape($confirmationCode) . '" data-ready-at="' . $escape($deleteReadyAt->format('Y-m-d H:i')) . '" data-bs-toggle="tooltip" title="' . $escape($deleteTitle) . '"' . ($deleteDelayPassed ? '' : ' disabled') . '><i class="fas fa-trash"></i></button>';
        $data[] = [
            $offset + $index + 1,
            '<span dir="ltr">' . $escape($confirmationCode) . '</span>',
            '<div class="fw-semibold">' . $escape($name) . '</div><small class="text-muted">' . $escape($student['username'] ?: 'بدون حساب دخول') . '</small>',
            '<div>' . $escape($student['class_name'] ?: 'غير مسند') . '</div><small class="text-muted">' . $escape($student['academic_year'] ?: '—') . '</small>',
            '<span class="badge bg-secondary">' . $escape($enrollmentLabels[$enrollmentStatus] ?? ($enrollmentStatus ?: 'غير محددة')) . '</span><small class="d-block text-muted mt-1">الحساب السابق: ' . $escape($statusLabels[$student['status_before_archive']] ?? ($student['status_before_archive'] ?: 'غير معروف')) . '</small>',
            '<div class="text-nowrap">' . $escape($student['deleted_at']) . '</div><small class="text-muted">' . $escape($student['archived_by_name'] ?: 'عملية قديمة') . '</small>',
            nl2br($escape($student['archive_reason'] ?: 'لم يُسجل سبب')),
            '<div class="text-center admin-table-actions">' . $restore . $delete . '</div>'
        ];
    }
    echo json_encode(['draw' => $result['draw'], 'recordsTotal' => $result['recordsTotal'], 'recordsFiltered' => $result['recordsFiltered'], 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Student archive DataTables endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => (int)($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
}
