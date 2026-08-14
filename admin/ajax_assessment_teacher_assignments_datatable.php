<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/AssessmentTeacherAssignmentListQuery.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $academicYearId = max(0, (int)($_POST['academic_year_id'] ?? 0));
    $db = (new Database())->getConnection();
    $roleLabels = [
        'employee' => 'موظف',
        'teacher' => 'معلم',
        'specialist' => 'أخصائي',
        'doctor' => 'طبيب',
        'librarian' => 'أمين مكتبة',
        'supervisor' => 'مشرف',
        'admin' => 'مدير نظام',
        'super_admin' => 'مدير النظام الأعلى',
    ];
    $roleColors = [
        'employee' => 'secondary',
        'teacher' => 'primary',
        'specialist' => 'success',
        'doctor' => 'danger',
        'librarian' => 'warning text-dark',
        'supervisor' => 'info text-dark',
        'admin' => 'purple',
        'super_admin' => 'dark',
    ];
    $palette = ['purple', 'dark', 'success', 'danger', 'warning text-dark', 'info text-dark', 'primary'];
    $customRoleStmt = $db->query("SELECT role_key, role_name FROM staff_roles WHERE status = 'active' ORDER BY role_name");
    foreach ($customRoleStmt->fetchAll(PDO::FETCH_ASSOC) as $customRole) {
        $roleKey = trim((string)($customRole['role_key'] ?? ''));
        if ($roleKey === '' || isset($roleLabels[$roleKey])) {
            continue;
        }
        $roleLabels[$roleKey] = (string)$customRole['role_name'];
        $roleColors[$roleKey] = $palette[count($roleColors) % count($palette)];
    }
    $result = (new AssessmentTeacherAssignmentListQuery($db))->load($academicYearId, $_POST);
    $escape = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
    $data = [];
    $offset = max(0, (int)($_POST['start'] ?? 0));

    foreach ($result['staff'] as $index => $staff) {
        $staffId = (int)$staff['id'];
        $assignment = $result['assignments'][$staffId] ?? [
            'subject_ids' => [], 'subject_names' => [], 'class_ids' => [], 'class_names' => [],
            'class_groups' => [], 'grade_ids' => [], 'whole_grade_ids' => [], 'stage_ids' => [],
            'can_record' => 0, 'can_review' => 0, 'is_active' => 1, 'requested_active' => 1, 'pending_count' => 0,
        ];
        $offDuty = ($staff['current_work_status'] ?? 'on_duty') === 'off_duty';
        $subjectNames = array_values($assignment['subject_names']);
        $classNames = array_values($assignment['class_names']);
        $classGroups = array_values($assignment['class_groups'] ?? []);
        $roleKeys = array_values(array_filter(array_map('trim', explode(',', (string)($staff['role_keys'] ?? '')))));
        if ($roleKeys === []) {
            $roleKeys = [trim((string)($staff['role'] ?? '')) ?: 'teacher'];
        }
        $primaryRole = trim((string)($staff['role'] ?? '')) ?: $roleKeys[0];
        $roleBadges = [];
        foreach ($roleKeys as $roleKey) {
            $primaryIcon = $roleKey === $primaryRole && count($roleKeys) > 1
                ? '<i class="fas fa-star me-1" data-bs-toggle="tooltip" title="الدور الأساسي"></i>'
                : '';
            $roleBadges[] = '<span class="badge bg-' . $escape($roleColors[$roleKey] ?? 'secondary') . '">'
                . $primaryIcon . $escape($roleLabels[$roleKey] ?? $roleKey) . '</span>';
        }
        $rolesCell = '<div class="teacher-assignment-role-cell" aria-label="الدور">'
            . '<div class="teacher-assignment-role-list">' . implode('', $roleBadges) . '</div></div>';
        $permissionBadges = [];
        if (!empty($assignment['can_record'])) {
            $permissionBadges[] = '<span class="badge bg-soft-success" title="صلاحية الرصد"><i class="fas fa-pen me-1"></i>رصد</span>';
        }
        if (!empty($assignment['can_review'])) {
            $permissionBadges[] = '<span class="badge bg-soft-info" title="صلاحية المراجعة"><i class="fas fa-check-double me-1"></i>مراجعة</span>';
        }
        $pendingCount = (int)($assignment['pending_count'] ?? 0);
        if ($pendingCount > 0) {
            $permissionBadges[] = '<span class="badge bg-soft-warning text-dark" title="لن تُمنح الصلاحيات قبل ربط المادة بالنطاق">'
                . '<i class="fas fa-clock me-1"></i>' . $pendingCount . ' بانتظار الربط</span>';
        }
        $permissionsCell = '<div class="teacher-assignment-permission-cell" aria-label="صلاحيات الحساب">'
            . ($permissionBadges ? implode('', $permissionBadges) : '<span class="teacher-assignment-no-permission">بدون صلاحيات</span>')
            . '</div>';
        $displayName = (string)($staff['display_name'] ?? $staff['name'] ?? '-');
        $nameCell = '<strong>' . $escape($displayName) . '</strong>';
        if (!empty($staff['employee_code'])) { $nameCell .= '<div class="small text-muted">' . $escape($staff['employee_code']) . '</div>'; }
        $button = '<button type="button" class="btn btn-sm btn-action-pills btn-edit me-1 assign-staff-btn" data-bs-toggle="tooltip" title="تحديد المواد والفصول"'
            . ' data-staff-id="' . $staffId . '" data-staff-name="' . $escape($displayName) . '"'
            . ' data-subject-ids="' . $escape(implode(',', array_values($assignment['subject_ids']))) . '"'
            . ' data-class-ids="' . $escape(implode(',', array_values($assignment['class_ids']))) . '"'
            . ' data-whole-grade-ids="' . $escape(implode(',', array_values($assignment['whole_grade_ids'] ?? []))) . '"'
            . ' data-can-record="' . (!empty($assignment['can_record']) ? '1' : '0') . '"'
            . ' data-can-review="' . (!empty($assignment['can_review']) ? '1' : '0') . '"'
            . ' data-is-active="' . (!empty($assignment['requested_active']) ? '1' : '0') . '"><i class="fas fa-sliders-h"></i></button>';
        $renderableClassGroups = [];
        foreach ($classGroups as $classGroup) {
            $gradeName = trim((string)($classGroup['grade_name'] ?? '')) ?: 'صف غير محدد';
            $groupClasses = array_values($classGroup['classes'] ?? []);
            if ($groupClasses === []) {
                continue;
            }
            $renderableClassGroups[] = ['grade_name' => $gradeName, 'classes' => $groupClasses];
        }
        if ($renderableClassGroups === [] && $classNames !== []) {
            $renderableClassGroups[] = ['grade_name' => 'الفصول المسندة', 'classes' => $classNames];
        }
        $stageCount = count(array_filter(array_map('intval', (array)($assignment['stage_ids'] ?? []))));
        $gradeCount = count($renderableClassGroups);
        $wholeGradeCount = count($assignment['whole_grade_ids'] ?? []);
        $classMenuModifier = $gradeCount <= 1
            ? '--single'
            : ($gradeCount <= 2 ? '--compact' : ($gradeCount <= 4 ? '--medium' : '--wide'));
        $classCell = '<div class="dropdown teacher-assignment-class-dropdown teacher-assignment-class-dropdown' . $classMenuModifier . '">'
            . '<button class="btn btn-sm btn-outline-primary dropdown-toggle rounded-pill py-1 px-3 fs-7" type="button" aria-expanded="false">'
            . '<i class="fas fa-layer-group me-1"></i>'
            . '<span class="teacher-assignment-class-summary-count">'
            . '<span class="teacher-assignment-class-summary-stages"><bdi>' . $stageCount . '</bdi> مراحل</span>'
            . '<span class="teacher-assignment-class-summary-separator" aria-hidden="true">·</span>'
            . '<span class="teacher-assignment-class-summary-grades"><bdi>' . $gradeCount . '</bdi> صفوف</span>'
            . '<span class="teacher-assignment-class-summary-separator" aria-hidden="true">·</span>'
            . '<span class="teacher-assignment-class-summary-classes"><bdi>' . count($classNames) . '</bdi> فصول</span>'
            . ($wholeGradeCount > 0
                ? '<span class="teacher-assignment-class-summary-separator" aria-hidden="true">·</span>'
                    . '<span class="teacher-assignment-class-summary-whole-grades"><bdi>' . $wholeGradeCount . '</bdi> صفوف كاملة</span>'
                : '')
            . '</span>'
            . '</button>'
            . '<ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 fs-7" aria-label="التعيينات حسب المرحلة والصف">';
        foreach ($renderableClassGroups as $classGroup) {
            $classCell .= '<li><span class="dropdown-item teacher-assignment-class-group-item">'
                . '<span class="teacher-assignment-class-group-copy">'
                . '<strong class="teacher-assignment-class-group-title"><i class="fas fa-check-circle text-primary me-2" aria-hidden="true"></i>'
                . $escape($classGroup['grade_name']) . '</strong>'
                . '<span class="teacher-assignment-class-group-items">' . $escape(implode('، ', $classGroup['classes'])) . '</span>'
                . '</span></span></li>';
        }
        $classCell .= '</ul></div>';

        $subjectCell = '<span class="text-muted">-</span>';
        if ($subjectNames !== []) {
            $subjectLabel = count($subjectNames) === 1 ? 'مادة' : 'مواد';
            $subjectCell = '<div class="dropdown teacher-assignment-subject-dropdown">'
                . '<button class="btn btn-sm btn-outline-danger dropdown-toggle rounded-pill py-1 px-3 fs-7" type="button" aria-expanded="false">'
                . '<i class="fas fa-book me-1"></i><bdi>' . count($subjectNames) . '</bdi> ' . $subjectLabel
                . '</button>'
                . '<ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 fs-7" aria-label="المواد المسندة">';
            foreach ($subjectNames as $subjectName) {
                $subjectCell .= '<li><span class="dropdown-item"><i class="fas fa-check-circle text-danger me-2" aria-hidden="true"></i>'
                    . $escape($subjectName) . '</span></li>';
            }
            $subjectCell .= '</ul></div>';
        }

        $data[] = [
            '0' => $offset + $index + 1,
            '1' => $nameCell,
            '2' => $escape($staff['job_title'] ?: '-'),
            '3' => $rolesCell,
            '4' => '<span class="badge bg-' . ($offDuty ? 'danger' : 'success') . '">' . ($offDuty ? 'ليس على رأس العمل' : 'على رأس العمل') . '</span>',
            '5' => $subjectCell,
            '6' => $renderableClassGroups ? $classCell : '<span class="text-muted">-</span>',
            '7' => $permissionsCell,
            '8' => '<div class="actions-column admin-table-actions">' . $button . '</div>',
            'DT_RowAttr' => [
                'data-job-title' => (string)($staff['job_title'] ?? ''),
                'data-stage-ids' => implode(',', array_values($assignment['stage_ids'])),
                'data-grade-ids' => implode(',', array_values($assignment['grade_ids'])),
                'data-class-ids' => implode(',', array_values($assignment['class_ids']))
            ]
        ];
    }

    echo json_encode(['draw' => $result['draw'], 'recordsTotal' => $result['recordsTotal'], 'recordsFiltered' => $result['recordsFiltered'], 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Teacher assignments DataTables endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => (int)($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
}
