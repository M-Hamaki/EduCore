<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/assessment_calendar.php');

$checks = [
    'current_year_scope_remains_authoritative' => strpos($page, 'WHERE t.academic_year_id = ?') !== false
        && strpos($page, 'WHERE m.academic_year_id = ?') !== false
        && strpos($page, 'WHERE w.academic_year_id = ?') !== false
        && strpos($page, 'AcademicYearWriteGuard') !== false,
    'current_year_summary_remains_visible' => strpos($page, 'العام الحالي') !== false,
    'year_is_not_repeated_in_term_or_week_headers' => strpos($page, '<th>العام</th>') === false,
    'empty_table_colspans_match_the_visible_columns' => strpos($page, '<tr><td colspan="6" class="text-center text-muted py-4">لم تتم إضافة فصول دراسية بعد.</td></tr>') !== false
        && strpos($page, '<tr><td colspan="10" class="text-center text-muted py-4">لم تتم إضافة أسابيع دراسية بعد.</td></tr>') !== false,
    'month_rows_do_not_repeat_the_academic_year' => strpos($page, "htmlspecialchars(\$month['academic_year_name'], ENT_QUOTES, 'UTF-8')") === false,
    'term_choices_do_not_repeat_the_academic_year' => strpos($page, "\$term['academic_year_name'] . ' - ' . \$term['name']") === false
        && substr_count($page, "htmlspecialchars(\$term['name'], ENT_QUOTES, 'UTF-8')") >= 3,
    'month_choices_show_term_and_month_only' => strpos($page, "\$month['academic_year_name'] . ' - ' . \$month['term_name'] . ' - ' . \$month['name']") === false
        && substr_count($page, "\$month['term_name'] . ' - ' . \$month['name']") >= 2,
    'removed_year_columns_are_not_offered_in_table_settings' => strpos($page, 'chk_term_year') === false
        && strpos($page, 'chk_week_year') === false,
    'term_column_settings_use_the_shifted_indexes' => strpos($page, "'chk_term_name': 1") !== false
        && strpos($page, "'chk_term_dates': 3") !== false
        && strpos($page, "'chk_term_status': 4") !== false,
    'week_column_settings_use_the_shifted_indexes' => strpos($page, "'chk_week_term': 1") !== false
        && strpos($page, "'chk_week_month': 2") !== false
        && strpos($page, "'chk_week_name': 3") !== false
        && strpos($page, "'chk_week_dates': 5") !== false
        && strpos($page, "'chk_week_type': 6") !== false
        && strpos($page, "'chk_week_average': 7") !== false
        && strpos($page, "'chk_week_status': 8") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
