<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/classes/AssessmentWindowLifecycleService.php');
$page = file_get_contents($root . '/admin/assessment_windows.php');
$bulk = file_get_contents($root . '/classes/AssessmentBulkActionService.php');
$marks = file_get_contents($root . '/teacher/assessment_marks.php');
$review = file_get_contents($root . '/teacher/assessment_review.php');

$checks = [
    'state_machine_is_explicit' => strpos($service, "'draft' => ['open']") !== false
        && strpos($service, "'open' => ['closed']") !== false
        && strpos($service, "'closed' => ['open', 'locked']") !== false
        && strpos($service, "'locked' => ['open']") !== false,
    'locked_reopen_requires_permission_reason_and_deadline' => strpos($service, "'reopen_window'") !== false
        && strpos($service, 'اكتب سبب إعادة فتح النافذة') !== false
        && strpos($service, 'حدد موعد إغلاق جديدًا') !== false
        && strpos($service, 'ليس لديك صلاحية إعادة فتح نافذة مقفلة') !== false,
    'final_lock_requires_completed_review' => strpos($service, "review_status <> 'approved'") !== false
        && strpos($service, 'لا يمكن قفل النافذة قبل اكتمال المراجعة') !== false
        && strpos($service, 'اكتب سبب القفل النهائي') !== false,
    'transition_is_locked_transactional_and_audited' => strpos($service, 'FOR UPDATE') !== false
        && strpos($service, 'beginTransaction') !== false
        && strpos($service, 'recordUpdate(') !== false
        && strpos($service, 'UndoManager::newBatchId()') !== false,
    'creation_cannot_bypass_lifecycle' => substr_count($page, "['draft', 'open']") >= 2
        && strpos($page, '<option value="closed">') === false
        && strpos($page, '<option value="locked">') === false,
    'table_has_one_contextual_status_action' => strpos($page, 'manage-window-status-btn') !== false
        && strpos($page, 'allowedTransitions') !== false
        && strpos($page, 'status-window-btn') === false
        && strpos($page, 'مجدولة') !== false
        && strpos($page, 'منتهية زمنيًا') !== false,
    'mixed_bulk_close_fails_before_writes' => strpos($bulk, 'assertAllWindowsOpen($rows)') !== false
        && strpos($bulk, 'لم تُغلق أي نافذة لأن الدفعة تحتوي حالات غير مفتوحة') !== false
        && strpos($bulk, "->transition(") !== false,
    'teacher_save_rechecks_live_open_window' => strpos($marks, 'FROM assessment_windows WHERE id = ? FOR UPDATE') !== false
        && strpos($marks, "liveWindowState['status']") !== false
        && strpos($marks, 'لم تُحفظ أي درجة') !== false,
    'review_is_closed_only_and_concurrency_safe' => strpos($review, "AND aw.status = 'closed'") !== false
        && strpos($review, 'SELECT status FROM assessment_windows WHERE id = ? FOR UPDATE') !== false
        && strpos($review, 'لم تعد نافذة الرصد مغلقة للمراجعة') !== false,
    'locked_window_settings_and_delete_are_blocked' => strpos($page, 'لا يمكن تعديل إعدادات نافذة مقفلة نهائيًا') !== false
        && strpos($page, 'لا يمكن حذف نافذة مقفلة نهائيًا') !== false
        && strpos($bulk, 'النافذة مقفلة نهائيًا') !== false
        && strpos($page, 'لا يمكن تغيير سياسات التعديل أو المراجعة بعد رصد درجات') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
exit($failed === [] ? 0 : 1);
