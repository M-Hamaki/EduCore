<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$engine = file_get_contents($root . '/classes/AssessmentEngine.php');
$page = file_get_contents($root . '/admin/assessment_reports.php');
$adminView = file_get_contents($root . '/admin/view_student_report.php');

$checks = [
    'engine_owns_atomic_unpublish' => strpos($engine, 'function unpublishReportWindow') !== false
        && strpos($engine, "SELECT * FROM published_reports WHERE report_window_id = ? ORDER BY id") !== false
        && strpos($engine, 'DELETE FROM published_report_details WHERE published_report_id IN') !== false
        && strpos($engine, 'DELETE FROM published_reports WHERE id IN') !== false
        && strpos($engine, 'UPDATE report_windows SET is_published = 0, published_at = NULL, hidden_at = NOW()') !== false
        && strpos($engine, "'published_report_details' => \$beforeDetails") !== false
        && strpos($engine, "'published_reports' => \$beforeReports") !== false
        && strpos($engine, "'report_windows' => [\$beforeWindow]") !== false,
    'server_action_is_scoped_and_delegated' => strpos($page, "\$action === 'unpublish_report_window'") !== false
        && strpos($page, 'reports_assert_current_year($currentAcademicYearId, $reportWindow)') !== false
        && strpos($page, '->unpublishReportWindow($reportWindowId)') !== false,
    'published_rows_offer_management_action' => strpos($page, "if ((int) \$reportWindow['published_count'] > 0)") !== false
        && strpos($page, 'unpublish-report-btn') !== false
        && strpos($page, 'إلغاء النشر وحذف نسخ الطلاب') !== false
        && strpos($page, 'data-published-count=') !== false
        && strpos($page, 'مخفي - النسخ محفوظة') !== false
        && strpos($page, 'غير منشور') !== false,
    'window_delete_waits_for_snapshot_removal' => strpos($page, 'احذف النسخ المنشورة أولا') !== false
        && strpos($page, 'disabled aria-disabled="true"') !== false,
    'destructive_confirmation_explains_scope' => strpos($page, 'id="unpublishReportModal"') !== false
        && strpos($page, 'لا يؤثر ذلك في درجات الطلاب الأصلية') !== false
        && strpos($page, 'يمكن التراجع فورًا من إشعار النظام') !== false
        && strpos($page, 'class="btn btn-danger"') !== false,
    'admin_archive_badge_is_reachable' => strpos($adminView, "if (\$selectedReport && !empty(\$selectedReport['hidden_at']))")
        < strpos($adminView, "elseif (\$selectedReport && empty(\$selectedReport['is_published']))"),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
