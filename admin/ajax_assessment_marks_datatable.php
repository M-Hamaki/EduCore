<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AssessmentEngine.php';
require_once '../classes/AssessmentMarkAdministrationQuery.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $academicYearId = max(0, (int) ($_POST['academic_year_id'] ?? 0));
    $result = (new AssessmentMarkAdministrationQuery((new Database())->getConnection()))->load($academicYearId, $_POST);
    $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $isSuperAdmin = (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '') === 'super_admin';
    $markLabels = [
        'present' => ['مرصودة', 'bg-primary'],
        'absent' => ['غائب', 'bg-danger'],
        'excused_absent' => ['غياب بعذر', 'bg-warning text-dark'],
        'exempt' => ['معفى', 'bg-secondary'],
        'empty' => ['فارغة', 'bg-light text-dark border'],
    ];
    $reviewLabels = [
        'not_required' => ['لا تتطلب مراجعة', 'bg-secondary'],
        'pending' => ['بانتظار المراجعة', 'bg-warning text-dark'],
        'approved' => ['معتمدة', 'bg-success'],
        'rejected' => ['مرفوضة', 'bg-danger'],
    ];

    $data = [];
    foreach ($result['rows'] as $row) {
        $id = (int) $row['id'];
        $markStatus = (string) ($row['mark_status'] ?? 'empty');
        $reviewStatus = (string) ($row['review_status'] ?? 'not_required');
        $markLabel = $markLabels[$markStatus] ?? [$markStatus, 'bg-secondary'];
        $reviewLabel = $reviewLabels[$reviewStatus] ?? [$reviewStatus, 'bg-secondary'];
        $isLocked = !empty($row['locked_at']) || (int) ($row['student_locked'] ?? 0) === 1 || (int) ($row['locked_window_count'] ?? 0) > 0;
        $publishedCount = (int) ($row['published_count'] ?? 0);
        $valueLabel = $row['value'] !== null
            ? AssessmentEngine::formatNumber((float) $row['value']) . ' / ' . AssessmentEngine::formatNumber((float) $row['max_grade'])
            : $markLabel[0];
        $studentCode = trim((string) ($row['student_code'] ?? ''));
        $studentHtml = '<a href="students.php?action=view&amp;id=' . (int) $row['student_id'] . '" class="fw-bold text-decoration-none">' . $escape($row['student_name']) . '</a>'
            . ($studentCode !== '' ? '<div class="small text-muted">' . $escape($studentCode) . '</div>' : '');
        $scopeHtml = '<strong>' . $escape($row['stage_name'] ?? '-') . '</strong>'
            . '<div class="small text-muted">' . $escape($row['grade_name'] ?? '-') . ' — ' . $escape($row['class_name'] ?? 'بدون فصل محفوظ') . '</div>';
        $componentHtml = '<strong>' . $escape($row['component_name']) . '</strong>'
            . '<div class="small text-muted">' . $escape($row['scheme_name']) . ($row['week_name'] ? ' — ' . $escape($row['week_name']) : '') . '</div>';
        $statusHtml = '<span class="badge ' . $escape($markLabel[1]) . '">' . $escape($valueLabel) . '</span>'
            . '<div class="mt-1"><span class="badge ' . $escape($reviewLabel[1]) . '">' . $escape($reviewLabel[0]) . '</span></div>'
            . ($isLocked ? '<div class="small text-danger mt-1"><i class="fas fa-lock me-1"></i>درجة مقفلة</div>' : '');
        $recordedHtml = $escape($row['recorded_by_name'] ?? 'غير محدد')
            . '<div class="small text-muted" dir="ltr">' . $escape($row['updated_at'] ?? $row['created_at'] ?? '-') . '</div>';
        $publishedHtml = $publishedCount > 0
            ? '<span class="badge bg-info text-dark"><i class="fas fa-file-lines me-1"></i>' . $publishedCount . '</span>'
            : '<span class="text-muted">لا توجد</span>';

        $actions = '<a href="students.php?action=view&amp;id=' . (int) $row['student_id'] . '" class="btn btn-sm btn-action-pills btn-services me-1" data-bs-toggle="tooltip" title="ملف الطالب"><i class="fas fa-user"></i></a>';
        if (!$isLocked || $isSuperAdmin) {
            $actions .= '<button type="button" class="btn btn-sm btn-action-pills btn-edit me-1 edit-mark-btn" data-bs-toggle="tooltip" title="تعديل الدرجة"'
                . ' data-mark-id="' . $id . '" data-student-name="' . $escape($row['student_name']) . '"'
                . ' data-scope-label="' . $escape(($row['subject_name'] ?? '') . ' — ' . ($row['component_name'] ?? '') . ' — ' . ($row['class_name'] ?? '')) . '"'
                . ' data-mark-status="' . $escape($markStatus) . '" data-mark-value="' . $escape($row['value'] ?? '') . '"'
                . ' data-mark-note="' . $escape($row['note'] ?? '') . '" data-max-grade="' . $escape($row['max_grade']) . '"'
                . ' data-published-count="' . $publishedCount . '" data-locked="' . ($isLocked ? '1' : '0') . '"><i class="fas fa-edit"></i></button>';
        } else {
            $actions .= '<button type="button" class="btn btn-sm btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="لا يمكن تعديل درجة مقفلة" disabled><i class="fas fa-lock"></i></button>';
        }
        if ($isSuperAdmin) {
            $actions .= '<button type="button" class="btn btn-sm btn-action-pills btn-delete delete-single-mark-btn" data-bs-toggle="tooltip" title="حذف الدرجة" data-mark-id="' . $id . '" data-student-name="' . $escape($row['student_name']) . '" data-published-count="' . $publishedCount . '"><i class="fas fa-trash"></i></button>';
        }

        $data[] = [
            '0' => $isSuperAdmin ? '<input type="checkbox" class="form-check-input assessment-mark-select" value="' . $id . '" aria-label="تحديد درجة ' . $escape($row['student_name']) . '">' : '<span class="text-muted">—</span>',
            '1' => $studentHtml,
            '2' => $scopeHtml,
            '3' => $escape($row['subject_name']),
            '4' => $componentHtml,
            '5' => $statusHtml,
            '6' => $escape($row['note'] ?? '') ?: '<span class="text-muted">—</span>',
            '7' => $recordedHtml,
            '8' => $publishedHtml,
            '9' => '<div class="actions-column admin-table-actions">' . $actions . '</div>',
            'DT_RowId' => 'assessment-mark-row-' . $id,
            'DT_RowAttr' => ['data-mark-id' => (string) $id],
        ];
    }

    echo json_encode([
        'draw' => $result['draw'],
        'recordsTotal' => $result['recordsTotal'],
        'recordsFiltered' => $result['recordsFiltered'],
        'summary' => $result['summary'],
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Assessment marks DataTables endpoint: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'draw' => (int) ($_POST['draw'] ?? 0),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
    ], JSON_UNESCAPED_UNICODE);
}
