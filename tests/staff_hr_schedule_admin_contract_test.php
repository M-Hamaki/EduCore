<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$newPagePath = $root . '/admin/hr_policy_calendar.php';
$legacyPagePath = $root . '/admin/staff_shifts.php';
$newPage = is_file($newPagePath) ? (string) file_get_contents($newPagePath) : '';
$legacyPage = is_file($legacyPagePath) ? (string) file_get_contents($legacyPagePath) : '';
$compatibilitySource = $legacyPage . PHP_EOL . $newPage;
$legacyService = (string) file_get_contents($root . '/src/Modules/Attendance/Application/LegacyStaffShiftCompatibilityService.php');
$requestMapper = (string) file_get_contents($root . '/src/Modules/Attendance/Presentation/SchedulePolicyAdminRequestMapper.php');
$legacyRepository = (string) file_get_contents($root . '/src/Modules/Attendance/Infrastructure/PdoLegacyStaffShiftRepository.php');
$staffDirectory = (string) file_get_contents($root . '/src/Modules/Staff/Infrastructure/PdoLegacyStaffDirectoryQuery.php');
$scopeOptions = (string) file_get_contents($root . '/src/Modules/Staff/Infrastructure/PdoStaffScheduleScopeOptionQuery.php');
$failures = [];

function scheduleAdminCheck(string $name, bool $passed, array &$failures): void
{
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
}

function firstScheduleRequestRead(string $source): int
{
    $positions = [
        strpos($source, '$_POST'),
        strpos($source, '$_GET'),
        strpos($source, "\$_SERVER['REQUEST_METHOD']"),
    ];
    $positions = array_values(array_filter($positions, static fn ($position): bool => $position !== false));
    return $positions === [] ? PHP_INT_MAX : min($positions);
}

scheduleAdminCheck('new_page.exists', $newPage !== '', $failures);
scheduleAdminCheck('legacy_page.exists', $legacyPage !== '', $failures);

$authPosition = strpos($newPage, "Utilities::validateSession('admin');");
scheduleAdminCheck(
    'new_page.auth_before_request_processing',
    $authPosition !== false && $authPosition < firstScheduleRequestRead($newPage),
    $failures
);
scheduleAdminCheck('new_page.csrf_post_guard', strpos($newPage, 'requireCsrfPost();') !== false, $failures);
scheduleAdminCheck('new_page.csrf_fields', substr_count($newPage, 'csrfField()') >= 3, $failures);
scheduleAdminCheck(
    'new_page.thin_controller_command_service',
    strpos($newPage, 'schedulePolicyCommand()') !== false
        && strpos($newPage, 'INSERT INTO staff_schedule_') === false
        && strpos($newPage, 'UPDATE staff_schedule_') === false
        && strpos($newPage, 'DELETE FROM staff_schedule_') === false,
    $failures
);

foreach ([
    'save_schedule_policy_draft',
    'publish_schedule_policy',
    'save_calendar_exception',
    'retire_calendar_exception',
    'preview_schedule_policy',
] as $action) {
    scheduleAdminCheck(
        'new_page.action_' . $action,
        strpos($newPage, "'" . $action . "'") !== false || strpos($newPage, '"' . $action . '"') !== false,
        $failures
    );
}

foreach ([
    'policy_name',
    'valid_from',
    'valid_to',
    'timezone',
    'scope_type',
    'scope_id',
    'priority',
    'weekday',
    'start_time',
    'end_time',
    'end_day_offset',
    'late_grace_minutes',
    'early_grace_minutes',
    'entry_window_before_minutes',
    'entry_window_after_minutes',
    'exit_window_before_minutes',
    'exit_window_after_minutes',
    'season_start_mmdd',
    'season_end_mmdd',
    'segment_type',
    'start_day_offset',
    'counts_required_minutes',
    'calendar_date',
    'exception_type',
    'reason',
] as $field) {
    scheduleAdminCheck(
        'new_page.field_' . $field,
        strpos($newPage, 'name="' . $field) !== false
            || strpos($newPage, '[' . $field . ']') !== false,
        $failures
    );
}

foreach (['admin-card-surface', 'admin-filter-bar', 'admin-list-surface', 'admin-data-table', 'stat-card', 'counter'] as $class) {
    scheduleAdminCheck('new_page.ui_' . $class, strpos($newPage, $class) !== false, $failures);
}
scheduleAdminCheck(
    'new_page.explains_effective_schedule',
    strpos($newPage, 'سبب الدوام الفعلي') !== false
        && strpos($newPage, 'مصدر السياسة') !== false
        && strpos($newPage, 'effectiveScheduleQuery->forStaffDate') !== false
        && strpos($newPage, 'name="resolve_staff_user_id"') !== false
        && strpos($newPage, 'id="effectiveScheduleResult"') !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.segment_editor_supports_full_schedule',
    strpos($newPage, "'paid_break'") !== false
        && strpos($newPage, "'unpaid_break'") !== false
        && strpos($newPage, "'on_call'") !== false
        && strpos($newPage, "'overtime'") !== false
        && strpos($newPage, 'requiredMinutes += $duration') !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.segment_editor_is_dynamic_and_guarded',
    strpos($newPage, 'id="scheduleSegmentTemplate"') !== false
        && strpos($newPage, 'js-add-schedule-segment') !== false
        && strpos($newPage, 'rows.length >= 20') !== false
        && strpos($newPage, 'count($rawSegments) > 20') !== false
        && strpos($newPage, 'id="removeScheduleSegmentModal"') !== false
        && strpos($newPage, 'new bootstrap.Modal(removeSegmentElement)') !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.inclusive_end_date_is_normalized',
    strpos($newPage, "modify('+1 day')->format('Y-m-d')") !== false
        && strpos($newPage, 'آخر يوم سريان (شامل)') !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.same_day_inclusive_range_is_allowed',
    strpos($newPage, '$toDate < $fromDate') !== false
        && strpos($newPage, '$toDate <= $fromDate') === false,
    $failures
);
scheduleAdminCheck(
    'new_page.clone_version_is_hydrated_without_mutating_published_source',
    strpos($newPage, 'clone_version_id') !== false
        && strpos($newPage, 'findVersion($cloneVersionId)') !== false
        && strpos($newPage, 'existing_policy_id') !== false
        && strpos($newPage, 'supersedes_version_id') !== false
        && strpos($newPage, '$policyFormDays') !== false
        && strpos($newPage, 'إنشاء نسخة جديدة') !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.clone_payload_maps_command_lineage',
    strpos($newPage, 'SchedulePolicyAdminRequestMapper::attachVersionLineage') !== false
        && strpos($requestMapper, "['policy_id']") !== false
        && strpos($requestMapper, "['supersedes_id']") !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.draft_edit_uses_optimistic_lock_and_csrf',
    strpos($newPage, 'edit_version_id') !== false
        && strpos($newPage, 'expected_lock_version') !== false
        && strpos($newPage, 'updateDraft(') !== false
        && strpos($newPage, "(int) (\$editSource['supersedes_id'] ?? 0)") !== false
        && strpos($newPage, "(string) (\$editSource['state'] ?? '') !== 'draft'") !== false
        && substr_count($newPage, 'csrfField()') >= 4,
    $failures
);
scheduleAdminCheck(
    'new_page.named_searchable_scope_selectors',
    strpos($newPage, 'id="policyScopeSearch"') !== false
        && strpos($newPage, 'id="exceptionScopeSearch"') !== false
        && strpos($newPage, 'data-scope-type=') !== false
        && strpos($newPage, 'bindScopePicker(') !== false
        && strpos($newPage, 'isSelectable(') !== false
        && strpos($scopeOptions, 'staff_org_units') !== false
        && strpos($scopeOptions, 'staff_job_titles') !== false
        && strpos($scopeOptions, 'staff_policy_groups') !== false
        && strpos($scopeOptions, 'sp.employee_code') !== false
        && strpos($scopeOptions, 'biometric_id') === false,
    $failures
);
scheduleAdminCheck(
    'new_page.calendar_exception_matches_command_contract',
    strpos($newPage, 'saveCalendarException($actorId') !== false
        && strpos($newPage, 'createCalendarException(') === false
        && strpos($newPage, 'id="exceptionPolicyVersion"') !== false
        && strpos($newPage, "'CALENDAR_EXCEPTION_OVERRIDE_REQUIRED' =>") !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.calendar_retirement_uses_audited_command_and_confirmation_modal',
    strpos($newPage, 'retireCalendarException(') !== false
        && strpos($newPage, 'id="retireCalendarExceptionModal"') !== false
        && strpos($newPage, 'id="retireCalendarExceptionId"') !== false
        && strpos($newPage, 'id="retireCalendarExceptionLockVersion"') !== false
        && strpos($newPage, 'data-bs-target="#retireCalendarExceptionModal"') !== false
        && strpos($newPage, "'CALENDAR_EXCEPTION_NOT_ACTIVE' =>") !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.ddl_limits_are_validated',
    strpos($newPage, 'mb_strlen($name,') !== false
        && strpos($newPage, '> 200') !== false
        && strpos($newPage, '> 1000') !== false
        && strpos($newPage, '$priority > 65535') !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.domain_errors_are_arabic',
    strpos($newPage, "'SCHEDULE_SEGMENT_OVERLAP' =>") !== false
        && strpos($newPage, "'SCHEDULE_POLICY_OVERLAP' =>") !== false
        && strpos($newPage, "'SCHEDULE_POLICY_CODE_INVALID' =>") !== false
        && strpos($newPage, "'SCHEDULE_SUCCESSOR_RANGE_INVALID' =>") !== false
        && strpos($newPage, "'SCHEDULE_PUBLICATION_CONFLICT' =>") !== false
        && strpos($newPage, "'CALENDAR_SCHEDULE_VERSION_NOT_PUBLISHED' =>") !== false
        && strpos($newPage, "'CALENDAR_SUPERSESSION_SCOPE_MISMATCH' =>") !== false,
    $failures
);
scheduleAdminCheck(
    'new_page.errors_do_not_disclose_sql_or_exception_details',
    strpos($newPage, 'htmlspecialchars($exception->getMessage()') === false
        && strpos($newPage, "preg_match('/[\\x{0600}-\\x{06FF}]/u'") !== false
        && strpos($newPage, 'مرجع المتابعة:') !== false,
    $failures
);
scheduleAdminCheck('new_page.no_local_style', stripos($newPage, '<style') === false, $failures);
scheduleAdminCheck('new_page.no_swal', stripos($newPage, 'Swal') === false, $failures);
scheduleAdminCheck('new_page.no_browser_confirm', preg_match('/\bconfirm\s*\(/i', $newPage) !== 1, $failures);

$legacyAuthPosition = strpos($legacyPage, "Utilities::validateSession('admin');");
scheduleAdminCheck(
    'legacy.auth_before_request_processing',
    $legacyAuthPosition !== false && $legacyAuthPosition < firstScheduleRequestRead($legacyPage),
    $failures
);
scheduleAdminCheck(
    'legacy.compatibility_adapter',
    strpos($legacyPage, 'STAFF_SHIFTS_COMPATIBILITY_MODE') !== false
        && strpos($legacyPage, "require __DIR__ . '/hr_policy_calendar.php';") !== false,
    $failures
);

foreach ([
    'save_default_shift',
    'default_shift_start',
    'default_shift_end',
    'default_shift_grace_minutes',
    'save_shift_override',
    'user_id',
    'shift_start',
    'shift_end',
    'grace_minutes',
    'is_active',
    'notes',
    'delete_shift_override',
    'id',
] as $field) {
    scheduleAdminCheck(
        'legacy.contract_' . $field,
        strpos($compatibilitySource, "'" . $field . "'") !== false
            || strpos($compatibilitySource, '"' . $field . '"') !== false
            || strpos($compatibilitySource, 'name="' . $field . '"') !== false,
        $failures
    );
}

scheduleAdminCheck(
    'legacy.controller_has_no_business_sql',
    preg_match('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|SELECT)\b/i', $legacyPage) !== 1,
    $failures
);
scheduleAdminCheck(
    'legacy.application_depends_on_contracts',
    strpos($legacyService, 'LegacyStaffShiftRepository') !== false
        && strpos($legacyService, 'AttendanceTransactionManager') !== false
        && strpos($legacyService, 'LegacyStaffShiftAuditWriter') !== false
        && strpos($legacyService, 'use PDO;') === false
        && strpos($legacyService, 'AuditService') === false,
    $failures
);
scheduleAdminCheck(
    'legacy.staff_directory_respects_module_boundary',
    strpos($legacyRepository, 'LegacyStaffDirectoryQuery') !== false
        && strpos($legacyRepository, 'FROM users') === false
        && strpos($legacyRepository, 'JOIN users') === false
        && strpos($legacyRepository, 'staff_profiles') === false
        && strpos($staffDirectory, 'implements LegacyStaffDirectoryQuery') !== false,
    $failures
);
scheduleAdminCheck('legacy.active_staff_revalidated', strpos($legacyService, 'isEligibleActiveStaff') !== false, $failures);
scheduleAdminCheck(
    'legacy.shift_invariants',
    strpos($legacyService, 'MAX_GRACE_MINUTES') !== false && strpos($legacyService, '$start === $end') !== false,
    $failures
);

scheduleAdminCheck(
    'compatibility.explicit_legacy_post_url',
    strpos($newPage, '$compatibilityFormAction') !== false && strpos($newPage, "'staff_shifts.php'") !== false,
    $failures
);
scheduleAdminCheck(
    'compatibility.bootstrap_delete_modal',
    strpos($newPage, 'id="deleteShiftOverrideModal"') !== false
        && strpos($newPage, 'admin-modal-delete') !== false
        && strpos($newPage, 'name="delete_shift_override"') !== false
        && strpos($newPage, 'id="deleteShiftOverrideId"') !== false,
    $failures
);
scheduleAdminCheck(
    'compatibility.delete_opens_modal',
    preg_match('/type="button"[^>]+data-bs-target="#deleteShiftOverrideModal"/s', $newPage) === 1,
    $failures
);

exit($failures === [] ? 0 : 1);
