<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/ActivityLog.php';

function systemActivityCoverageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function systemActivityUntranslatedLiteralAuditCodes(string $root): array
{
    $untranslated = [];
    $roots = ['admin', 'api', 'ajax', 'teacher', 'student', 'specialist', 'supervisor', 'external_teacher', 'classes', 'src'];
    $pattern = '/(?:ActivityLog::log|->recordEvent)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]/';

    foreach ($roots as $relativeRoot) {
        $directory = $root . '/' . $relativeRoot;
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                foreach ([ActivityLog::getActionLabel($match[1]), ActivityLog::getTargetLabel($match[2])] as $label) {
                    if (preg_match('/[A-Za-z_]/', $label)) {
                        $untranslated[] = $label;
                    }
                }
            }
        }
    }

    return array_values(array_unique($untranslated));
}

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/activity_logs.php');
$auditService = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditService.php');
$coverageGate = (string) file_get_contents($root . '/tools/audit_write_coverage.php');
$teacherAssignmentsPage = (string) file_get_contents($root . '/admin/assessment_teacher_assignments.php');

$customAction = ActivityLog::getActionLabel('staff_leave_request_submitted');
$customTarget = ActivityLog::getTargetLabel('finance_fee_plan');
$untranslatedLiteralAuditCodes = systemActivityUntranslatedLiteralAuditCodes($root);
if ($untranslatedLiteralAuditCodes !== []) {
    fwrite(STDERR, 'UNTRANSLATED_AUDIT_CODES=' . json_encode($untranslatedLiteralAuditCodes, JSON_UNESCAPED_UNICODE) . PHP_EOL);
}
$assignmentRows = [
    [
        'teacher_id' => 1060,
        'subject_id' => 1,
        'grade_id' => 7,
        'class_id' => 44,
        'can_record' => 1,
        'can_review' => 1,
        'requested_active' => 1,
    ],
    [
        'teacher_id' => 1060,
        'subject_id' => 12,
        'grade_id' => 12,
        'class_id' => 45,
        'can_record' => 1,
        'can_review' => 1,
        'requested_active' => 1,
    ],
];
$legacyStructuredHtml = ActivityLog::formatDetailsHtml([
    'changes' => [
        'assignments' => ['from' => $assignmentRows, 'to' => null],
    ],
], 'inline');
$snapshotHtml = ActivityLog::formatDetailsHtml([
    'audit_snapshot' => ['previous_assignments' => $assignmentRows],
], 'inline');
$structuredDiffTableHtml = ActivityLog::formatDetailsHtml([
    'changes' => [
        'assignments' => ['from' => $assignmentRows, 'to' => null],
    ],
], 'diff_table');
$systemOperationPresentation = ActivityLog::getOperationPresentation([
    'id' => 1937,
    'action' => 'update',
    'target_type' => 'student',
    'target_id' => 880,
    'target_name' => 'أحمد شريف عثمان',
]);
$legacyArabicDetailsHtml = ActivityLog::formatDetailsHtml([
    'description' => 'Active portal role changed to super_admin',
    'legacy_api' => 'Utilities::logAction',
], 'diff_table');

$checks = [
    'student_change_fields_have_clear_arabic_diff_labels' =>
        ActivityLog::getDetailKeyLabel('enrollment_status') === 'حالة القيد'
        && ActivityLog::getDetailKeyLabel('transfer_destination') === 'جهة النقل'
        && ActivityLog::getDetailKeyLabel('external_transfer_date') === 'تاريخ النقل الخارجي'
        && ActivityLog::getDetailKeyLabel('academic_year_id') === 'العام الدراسي',
    'guardian_details_have_arabic_labels_and_values' => (static function (): bool {
        $html = ActivityLog::formatDetailsHtml([
            'changes' => [
                'guardian_name' => ['from' => null, 'to' => 'ولي أمر'],
                'relationship' => ['from' => null, 'to' => 'mother'],
                'is_primary' => ['from' => null, 'to' => 0],
                'created_at' => ['from' => null, 'to' => '2026-07-26 14:14:22'],
            ],
        ], 'diff_table');

        return strpos($html, 'اسم ولي الأمر') !== false
            && strpos($html, 'صلة القرابة') !== false
            && strpos($html, 'ولي الأمر الأساسي') !== false
            && strpos($html, 'تاريخ الإنشاء') !== false
            && strpos($html, 'الأم') !== false
            && strpos($html, '>لا<') !== false
            && strpos($html, 'guardian_name') === false
            && strpos($html, 'relationship') === false
            && strpos($html, '>mother<') === false;
    })(),
    'shared_audit_event_writer_targets_activity_log' => strpos($auditService, 'implements AuditEventWriter') !== false
        && strpos($auditService, '\\ActivityLog::log($action, $entityType, $recordId, $name, $details, $context)') !== false,
    'system_activity_page_keeps_global_query' => strpos($page, '$systemActivityLogQuery->load($filters, $activeLogTab') !== false
        && strpos($page, 'StudentOperationLogQuery') === false,
    'system_activity_page_uses_structured_arabic_diff_table' => strpos($page, "ActivityLog::formatDetailsHtml(\$details, 'diff_table')") !== false
        && strpos($page, "ActivityLog::formatDetailsHtml(\$details, 'inline')") === false,
    'system_activity_page_matches_student_technical_details_presentation' => strpos($page, 'ActivityLog::getOperationPresentation($log)') !== false
        && strpos($page, '<details class="mt-2">') !== false
        && strpos($page, '<summary class="text-primary fw-semibold">عرض التفاصيل الفنية</summary>') !== false
        && strpos($page, "\$presentation['technical_reference']") !== false,
    'system_activity_presentation_is_clear_and_referenced' => strpos($systemOperationPresentation['summary'], 'تم تنفيذ عملية «تعديل»') === 0
        && strpos($systemOperationPresentation['summary'], 'أحمد شريف عثمان') !== false
        && $systemOperationPresentation['technical_reference'] === 'سجل النشاط #1937 · مرجع البيانات #880',
    'legacy_system_details_are_presented_in_arabic' => strpos($legacyArabicDetailsHtml, 'تم تغيير الدور النشط في بوابة المستخدم إلى المدير العام') !== false
        && strpos($legacyArabicDetailsHtml, 'مسجّل العمليات القديم للنظام') !== false
        && strpos($legacyArabicDetailsHtml, 'Active portal role') === false
        && strpos($legacyArabicDetailsHtml, 'Utilities::logAction') === false,
    'custom_action_identifiers_are_readable' => $customAction === 'شؤون العاملين · إجازة · طلب · إرسال'
        && strpos($customAction, '_') === false,
    'custom_target_identifiers_are_readable' => $customTarget === 'المالية · رسوم · خطة'
        && strpos($customTarget, '_') === false,
    'all_literal_shared_audit_codes_are_arabic_readable' => $untranslatedLiteralAuditCodes === [],
    'specialized_events_receive_semantic_visuals' => ActivityLog::getActionBadgeClass('staff_leave_request_cancelled') === 'bg-danger'
        && ActivityLog::getActionIcon('finance_fee_plan_create') === 'fa-coins',
    'undo_columns_are_visibly_anchored' => strpos($page, 'system-activity-log-undo-guide') !== false
        && strpos($page, 'system-activity-log-undo-state-col') !== false
        && strpos($page, 'system-activity-log-undo-action-col') !== false,
    'legacy_structured_details_are_compact_and_not_struck_through' => strpos($legacyStructuredHtml, 'teacher_id') === false
        && strpos($legacyStructuredHtml, 'بيانات سابقة (سجلان)') !== false
        && strpos($legacyStructuredHtml, '<del') === false,
    'audit_snapshots_are_compact_and_preserved_as_audit_evidence' => strpos($snapshotHtml, 'لقطة تدقيق محفوظة') !== false
        && strpos($snapshotHtml, 'التعيينات السابقة: سجلان') !== false
        && strpos($snapshotHtml, 'teacher_id') === false,
    'structured_diff_tables_do_not_emit_raw_json_or_invalid_strike_markup' => strpos($structuredDiffTableHtml, 'teacher_id') === false
        && strpos($structuredDiffTableHtml, '<del') === false,
    'teacher_assignment_audit_uses_summary_and_preserves_snapshot' => strpos($teacherAssignmentsPage, 'teacher_assignments_audit_summary') !== false
        && strpos($teacherAssignmentsPage, "'audit_snapshot' => ['previous_assignments' => \$oldAssignments]") !== false
        && strpos($teacherAssignmentsPage, "['assignments' => \$oldAssignments]") === false,
    'log_change_supports_auditable_supplementary_details' => strpos((string) file_get_contents($root . '/classes/ActivityLog.php'), 'array $additionalDetails = []') !== false
        && strpos((string) file_get_contents($root . '/classes/ActivityLog.php'), "\$details['changes'] = EntityChangeTracker::diff") !== false,
    'coverage_gate_fails_closed' => strpos($coverageGate, '$exitCode = $report[\'review_required_files\'] > 0 ? 1 : 0;') !== false
        && substr_count($coverageGate, 'exit($exitCode);') >= 3,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
