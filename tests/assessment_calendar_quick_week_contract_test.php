<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/assessment_calendar.php');
$datePicker = (string) file_get_contents($root . '/assets/js/air-datepicker-init.js');

$checks = [
    'quick_action_is_exposed_only_when_a_next_week_can_be_suggested' => strpos($page, 'quick-add-next-week-btn') !== false
        && strpos($page, '$nextWeekAlreadyExists') !== false
        && strpos($page, '$termHasEnded') !== false
        && strpos($page, '$canSuggestNextWeek') !== false,
    'week_rows_expose_the_source_scope_and_dates' => strpos($page, 'data-week-term=') !== false
        && strpos($page, 'data-week-month=') !== false
        && strpos($page, 'data-week-start=') !== false
        && strpos($page, 'data-week-end=') !== false
        && strpos($page, 'data-week-term-end=') !== false,
    'existing_add_modal_is_reused_with_accessible_field_targets' => strpos($page, 'id="addWeekForm"') !== false
        && strpos($page, 'id="addWeekQuickContext"') !== false
        && strpos($page, 'id="addWeekMonth"') !== false
        && strpos($page, 'id="addWeekStart"') !== false
        && strpos($page, 'id="addWeekEnd"') !== false
        && strpos($page, 'for="addWeekMonth"') !== false,
    'month_options_publish_scope_boundaries_for_safe_suggestion' => strpos($page, 'data-month-term=') !== false
        && strpos($page, 'data-month-order=') !== false
        && strpos($page, 'data-month-start=') !== false
        && strpos($page, 'data-month-end=') !== false
        && strpos($page, 'data-month-status=') !== false,
    'next_week_preserves_duration_and_starts_after_source' => strpos($page, 'const nextStart = addCalendarDays(sourceEnd, 1);') !== false
        && strpos($page, 'const durationDays = calendarDaysBetween(sourceStart, sourceEnd);') !== false
        && strpos($page, 'let nextEnd = addCalendarDays(nextStart, durationDays);') !== false,
    'next_week_selects_the_covering_month_and_handles_missing_months' => strpos($page, 'containsDate(option, nextStart)') !== false
        && strpos($page, "monthSelect.value = targetMonth.value;") !== false
        && strpos($page, 'لا يوجد شهر يغطي تاريخ بداية الأسبوع التالي') !== false,
    'term_and_month_boundaries_are_clamped_with_review_warnings' => strpos($page, 'if (termEnd && nextEnd > termEnd)') !== false
        && strpos($page, 'if (monthEnd && nextEnd > monthEnd)') !== false
        && strpos($page, 'تم تقصير تاريخ النهاية ليتوافق مع نهاية الترم') !== false
        && strpos($page, 'تم تقصير الأسبوع ليتوافق مع نهاية الشهر') !== false,
    'quick_action_requires_review_instead_of_auto_saving' => strpos($page, "showModal('addWeeksModal');") !== false
        && strpos($page, 'إضافة الأسبوع التالي') !== false
        && strpos($page, 'راجع البيانات ثم اضغط إضافة') !== false,
    'shared_datepicker_keeps_programmatic_values_and_internal_selection_in_sync' => strpos($datePicker, 'var datepickerInstances = new WeakMap();') !== false
        && strpos($datePicker, 'datepickerInstances.set(el, instance);') !== false
        && strpos($datePicker, 'function setAirDatepickerValue(') !== false
        && strpos($datePicker, 'instance.selectDate(date, { silent: true });') !== false
        && strpos($datePicker, 'instance.setViewDate(date);') !== false
        && strpos($datePicker, 'window.setAirDatepickerValue = setAirDatepickerValue;') !== false,
    'calendar_modals_use_the_shared_datepicker_setter_and_clear_stale_selection' => strpos($page, 'window.setAirDatepickerValue(input, normalizedValue, { dispatchChange: false })') !== false
        && strpos($page, "setCalendarDateValue(document.getElementById('addWeekStart'), '');") !== false
        && strpos($page, "setCalendarDateValue(document.getElementById('addWeekEnd'), '');") !== false
        && strpos($page, "setCalendarDateValue(document.getElementById('editWeekStart'), this.dataset.weekStart || '');") !== false,
    'server_side_add_contract_still_rejects_overlap_order_and_scope_errors' => strpos($page, 'تاريخ الأسبوع يتداخل مع أسبوع آخر داخل نفس الترم') !== false
        && strpos($page, 'ترتيب الأسبوع مستخدم بالفعل داخل نفس الترم') !== false
        && strpos($page, 'تاريخ نهاية الأسبوع خارج نطاق نهاية الترم') !== false
        && strpos($page, 'تاريخ نهاية الأسبوع خارج نطاق نهاية الشهر') !== false,
    'creation_remains_audited_through_the_existing_write_owner' => strpos($page, "ActivityLog::logCreate('academic_week'") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
