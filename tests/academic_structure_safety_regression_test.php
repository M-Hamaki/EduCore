<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$stages = (string) file_get_contents($root . '/admin/stages.php');
$classes = (string) file_get_contents($root . '/admin/classes.php');
$grades = (string) file_get_contents($root . '/admin/grades.php');
$yearSetup = (string) file_get_contents($root . '/admin/academic_year_setup.php');
$academicYear = (string) file_get_contents($root . '/classes/AcademicYear.php');
$mainJs = (string) file_get_contents($root . '/assets/js/main.js');

$checks = [
    'stage_grade_mutations_send_csrf' => substr_count(
        $stages,
        "formData.append('csrf_token', stageGradesCsrfToken());"
    ) === 2,
    'stage_grade_mutations_handle_non_json_failures' => str_contains($stages, 'async function postStageGradeMutation')
        && str_contains($stages, 'await response.json()'),
    'stage_grade_names_are_escaped_before_html_rendering' => str_contains($stages, 'function escapeStageGradeHtml')
        && str_contains($stages, '${escapeStageGradeHtml(grade.grade_name)}'),
    'invalid_stage_delete_uses_prg' => str_contains($stages, "FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]")
        && str_contains($stages, "header('Location: stages.php');")
        && str_contains($stages, 'exit();'),
    'status_transitions_are_whitelisted' => substr_count(
        $stages . $classes . $grades,
        "in_array(\$newStatus, ['active', 'inactive'], true)"
    ) === 3,
    'class_filters_use_stage_and_grade_columns' => str_contains($classes, "var stageText = (data[5] || '').trim();")
        && str_contains($classes, "var gradeText = (data[6] || '').trim();")
        && str_contains($classes, 'var stageCell = cells[5];')
        && str_contains($classes, 'var gradeCell = cells[6];'),
    'structure_writes_are_transactional_and_audited' => substr_count($stages . $classes . $grades, 'new \\EduCore\\Modules\\Operations\\Audit\\AuditService($db)') === 3
        && str_contains($stages, "SELECT * FROM stages WHERE id = ? FOR UPDATE")
        && str_contains($grades, "SELECT * FROM grades WHERE id = ? FOR UPDATE")
        && str_contains($classes, "SELECT * FROM classes WHERE id = ? FOR UPDATE"),
    'class_creation_binds_academic_year_atomically' => str_contains($classes, '$class->academic_year_id = $currentAcademicYearId > 0')
        && !str_contains($classes, 'UPDATE classes SET academic_year_id = ? WHERE id = ? AND academic_year_id IS NULL'),
    'stage_service_payloads_are_whitelisted' => str_contains($stages, '$normalizeSelections')
        && str_contains($stages, 'array_intersect')
        && str_contains($stages, 'JSON_THROW_ON_ERROR'),
    'class_list_reads_do_not_normalize_order_by_writing' => !str_contains($classes, 'UPDATE classes SET display_order = ? WHERE id = ?')
        && str_contains($classes, 'CASE WHEN c.display_order IS NULL OR c.display_order = 0 THEN 1 ELSE 0 END'),
    'year_setup_rejects_malformed_retained_ids_safely' => str_contains($yearSetup, 'function yearSetupNormalizeRetainedStudentIds')
        && !str_contains($yearSetup, "array_map('intval', \$_POST['retained_student_ids'] ?? [])"),
    'year_setup_rejects_empty_csrf_tokens' => str_contains(
        $yearSetup,
        "if (\$sessionCsrfToken === '' || \$csrfToken === '' || !hash_equals(\$sessionCsrfToken, \$csrfToken))"
    ),
    'academic_year_dates_are_validated_and_ordered' => str_contains($academicYear, 'private static function assertDateRange')
        && str_contains($academicYear, '$startDate >= $endDate')
        && str_contains($academicYear, "'يجب أن يسبق تاريخ بداية العام الدراسي تاريخ النهاية.'"),
    'year_setup_compacts_session_preview' => str_contains($yearSetup, 'function yearSetupCompactPreviewForSession')
        && str_contains($yearSetup, "unset(\$preview['blockers'])")
        && str_contains($yearSetup, 'yearSetupCompactPreviewForSession($preview)'),
    'grade_filter_has_single_change_path' => !str_contains($grades, 'onchange="filterGrades()"')
        && !str_contains($grades, "stageFilter.dispatchEvent(new Event('change'))"),
    'academic_structure_datatables_are_initialized_once' => str_contains(
        $mainJs,
        'if ($.fn.dataTable.isDataTable(this))'
    )
        && !str_contains($stages, "$('#stagesTable').DataTable({")
        && !str_contains($grades, '$(gradesTable).DataTable({'),
    'academic_structure_messages_are_escaped' => str_contains($classes, "htmlspecialchars((string) \$success_message, ENT_QUOTES, 'UTF-8')")
        && str_contains($classes, "htmlspecialchars((string) \$error_message, ENT_QUOTES, 'UTF-8')")
        && str_contains($grades, "htmlspecialchars((string) \$success_message, ENT_QUOTES, 'UTF-8')")
        && str_contains($grades, "htmlspecialchars((string) \$error_message, ENT_QUOTES, 'UTF-8')")
        && str_contains($yearSetup, "htmlspecialchars((string) \$success_message, ENT_QUOTES, 'UTF-8')")
        && str_contains($yearSetup, "htmlspecialchars((string) \$error_message, ENT_QUOTES, 'UTF-8')"),
    'academic_structure_tables_use_shared_column_settings' => str_contains($stages, "initializeTableColumnSettings('stagesTable'")
        && str_contains($classes, "initializeTableColumnSettings('classesTable'")
        && str_contains($grades, "initializeTableColumnSettings('gradesTable'"),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
