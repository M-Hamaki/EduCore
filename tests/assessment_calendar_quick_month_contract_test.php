<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/assessment_calendar.php');

$checks = [
    'month_query_exposes_term_boundary_and_tracks_existing_orders' => strpos($page, 't.end_date AS term_end_date') !== false
        && strpos($page, '$monthOrderKeys') !== false
        && strpos($page, '$nextMonthAlreadyExists') !== false,
    'quick_month_action_requires_dates_free_order_and_remaining_term_time' => strpos($page, '$monthHasCompleteDates') !== false
        && strpos($page, '$monthTermHasEnded') !== false
        && strpos($page, '$canSuggestNextMonth') !== false
        && strpos($page, 'quick-add-next-month-btn') !== false,
    'quick_month_button_publishes_the_required_source_context' => strpos($page, 'data-month-term-end=') !== false
        && strpos($page, 'data-month-order=') !== false
        && strpos($page, 'data-month-start=') !== false
        && strpos($page, 'data-month-end=') !== false
        && strpos($page, 'data-month-type=') !== false,
    'existing_add_month_modal_is_reused_and_resettable' => strpos($page, 'id="addMonthForm"') !== false
        && strpos($page, 'id="addMonthModalTitle"') !== false
        && strpos($page, 'id="addMonthQuickContext"') !== false
        && strpos($page, 'function resetAddMonthForm()') !== false
        && strpos($page, "setCalendarDateValue(document.getElementById('addMonthStart'), '');") !== false
        && strpos($page, "setCalendarDateValue(document.getElementById('addMonthEnd'), '');") !== false,
    'full_gregorian_months_use_the_real_length_of_the_next_month' => strpos($page, 'function lastDayOfUtcMonth(') !== false
        && strpos($page, 'function isFullGregorianMonth(') !== false
        && strpos($page, '? lastDayOfUtcMonth(nextStart)') !== false,
    'custom_month_ranges_preserve_duration' => strpos($page, 'const durationDays = calendarDaysBetween(sourceStart, sourceEnd);') !== false
        && strpos($page, ': addCalendarDays(nextStart, durationDays);') !== false,
    'suggestion_starts_after_source_and_stays_inside_term' => strpos($page, 'const nextStart = addCalendarDays(sourceEnd, 1);') !== false
        && strpos($page, 'if (termEnd && nextEnd > termEnd)') !== false
        && strpos($page, 'تم تقصير تاريخ النهاية ليتوافق مع نهاية الترم') !== false,
    'suggestion_uses_arabic_month_name_and_does_not_copy_weeks' => strpos($page, 'gregorianMonthNames[nextStart.getUTCMonth()]') !== false
        && strpos($page, 'دون نسخ أسابيعه') !== false
        && strpos($page, "showModal('addMonthsModal');") !== false,
    'server_add_month_guards_and_audit_remain_authoritative' => strpos($page, 'يوجد شهر بنفس الاسم أو الترتيب داخل هذا الترم') !== false
        && strpos($page, 'تاريخ نهاية الشهر خارج نطاق نهاية الترم') !== false
        && strpos($page, "ActivityLog::logCreate('academic_month'") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
