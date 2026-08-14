<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/LibraryListDataTableQuery.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = (new Database())->getConnection();
    $yearId = AcademicYear::currentId($db);
    $portalContext = new ScopedStaffPortalContext($db, $yearId);
    $type = (string)($_POST['list'] ?? '');
    $result = (new LibraryListDataTableQuery($db))->load($type, $_POST, $yearId, $portalContext->allowedClassIds());
    $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $data = [];
    $start = max(0, (int)($_POST['start'] ?? 0));
    $idx = 0;
    foreach ($result['rows'] as $row) {
        $idx++;
        $seq = $start + $idx;
        if ($type === 'books') {
            $data[] = [
                '<strong>' . $escape($row['title']) . '</strong>',
                $escape($row['author'] ?: '-'),
                $escape($row['category'] ?: '-'),
                $row['copies_available'] . ' / ' . $row['copies_total'],
                $escape($row['location'] ?: '-'),
                '<button class="btn btn-action-pills btn-edit edit-book-btn" data-bs-toggle="tooltip" title="تعديل" data-id="' . $row['id'] . '" data-title="' . $escape($row['title']) . '" data-author="' . $escape($row['author']) . '" data-category="' . $escape($row['category']) . '" data-isbn="' . $escape($row['isbn']) . '" data-copies_total="' . $row['copies_total'] . '" data-location="' . $escape($row['location']) . '" data-notes="' . $escape($row['notes']) . '"><i class="fas fa-edit"></i></button>',
            ];
        } elseif ($type === 'fines') {
            $data[] = [
                $escape($row['student_name']),
                $escape($row['title'] ?: '-'),
                number_format((float)$row['amount'], 2),
                $escape($row['reason'] ?: '-'),
                $row['paid'] ? '<span class="badge bg-success">مسددة</span>' : '<span class="badge bg-warning text-dark">غير مسددة</span>',
                $row['paid'] ? '' : '<form method="POST"><input type="hidden" name="csrf_token" value="' . $escape($_SESSION['csrf_token']) . '"><input type="hidden" name="action" value="pay_fine"><input type="hidden" name="fine_id" value="' . $row['id'] . '"><button class="btn btn-primary btn-sm"><i class="fas fa-money-bill-wave me-1"></i>تسديد</button></form>',
            ];
        } elseif ($type === 'loans') {
            $isOverdue = !empty($row['due_at']) && strtotime($row['due_at']) < strtotime('today');
            if ($row['status'] === 'returned') {
                $statusBadge = '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-check-circle me-1"></i>تم الإرجاع</span>';
            } elseif ($isOverdue) {
                $statusBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-exclamation-triangle me-1"></i>متأخر</span>';
            } else {
                $statusBadge = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="fas fa-book-reader me-1"></i>مستعار</span>';
            }

            $userRole = (string)($row['user_role'] ?? 'student');
            $isStaff = $userRole !== 'student';
            if ($isStaff) {
                $roleLabels = [
                    'teacher' => 'معلم',
                    'employee' => 'موظف',
                    'specialist' => 'أخصائي',
                    'supervisor' => 'مشرف',
                    'admin' => 'مسؤول',
                    'super_admin' => 'مدير النظام'
                ];
                $roleName = $roleLabels[$userRole] ?? 'موظف';
                $roleBadge = ' <span class="badge bg-info-subtle text-info border border-info-subtle ms-1">' . $escape($roleName) . '</span>';
                $studentStr = '<strong>' . $escape($row['student_name']) . '</strong>' . $roleBadge;
                $stageGradeClassStr = '<span class="text-muted">' . $escape($roleName) . '</span>';
            } else {
                $codeStr = !empty($row['student_code']) ? ' <span class="badge bg-light text-secondary border ms-1">' . $escape($row['student_code']) . '</span>' : '';
                $studentStr = '<strong>' . $escape($row['student_name']) . '</strong>' . $codeStr;
                $sgcParts = array_filter([$row['stage_name'] ?? '', $row['grade_name'] ?? '', $row['class_name'] ?? '']);
                $stageGradeClassStr = !empty($sgcParts) ? implode(' / ', array_map($escape, $sgcParts)) : '-';
            }

            $editBtn = '<button type="button" class="btn btn-action-pills btn-edit edit-loan-btn me-1" data-bs-toggle="tooltip" title="تعديل الاستعارة" data-id="' . $row['id'] . '" data-book_id="' . $row['book_id'] . '" data-student_id="' . $row['student_id'] . '" data-user_role="' . $escape($userRole) . '" data-stage_id="' . ($row['stage_id'] ?? 0) . '" data-grade_id="' . ($row['grade_id'] ?? 0) . '" data-class_id="' . ($row['class_id'] ?? 0) . '" data-student_name="' . $escape($row['student_name']) . '" data-title="' . $escape($row['title']) . '" data-borrowed_at="' . $escape($row['borrowed_at']) . '" data-due_at="' . $escape($row['due_at'] ?? '') . '" data-notes="' . $escape($row['notes'] ?? '') . '"><i class="fas fa-edit"></i></button>';

            $returnBtn = '<button type="button" class="btn btn-action-pills btn-activate return-loan-btn" data-bs-toggle="tooltip" title="تسجيل إرجاع الكتاب" data-id="' . $row['id'] . '" data-title="' . $escape($row['title']) . '" data-student_name="' . $escape($row['student_name']) . '"><i class="fas fa-undo"></i></button>';

            $data[] = [
                $seq,
                $studentStr,
                $stageGradeClassStr,
                '<strong class="text-primary">' . $escape($row['title']) . '</strong>',
                $escape($row['borrowed_at']),
                $escape($row['due_at'] ?: '-'),
                $escape($row['returned_at'] ?: '-'),
                $escape($row['notes'] ?: '-'),
                $statusBadge,
                $editBtn . $returnBtn
            ];
        } else {
            $data[] = [$escape($row['title']), $escape($row['student_name']), $escape($row['returned_at'])];
        }
    }

    echo json_encode([
        'draw' => $result['draw'],
        'recordsTotal' => $result['total'],
        'recordsFiltered' => $result['filtered'],
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Library DataTables endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => (int)($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
}
