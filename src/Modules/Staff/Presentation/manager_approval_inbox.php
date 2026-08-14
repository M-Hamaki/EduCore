<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Presentation;

use InvalidArgumentException;

/**
 * Data-only, reusable presentation for the current manager's assigned work.
 * The route that uses it owns authorization, CSRF verification, PRG, and the
 * ApprovalWorkflowService call; this class only renders validated evidence.
 */
final class ManagerApprovalInbox
{
    /**
     * @param array<string,mixed> $view
     */
    public static function renderInbox(array $view): string
    {
        $csrfToken = self::requiredText($view['csrf_token'] ?? null, 'APPROVAL_INBOX_CSRF_TOKEN_REQUIRED', 512);
        $actionUrl = self::safeRelativeAction((string) ($view['action_url'] ?? ''));
        $items = self::items($view['items'] ?? []);
        $total = self::nonNegativeInt($view['total'] ?? count($items), 'APPROVAL_INBOX_TOTAL_INVALID');
        $feedback = self::feedback($view['feedback'] ?? null);
        $modals = [];

        ob_start();
        ?>
        <section class="admin-list-surface mb-4" aria-labelledby="managerApprovalInboxTitle">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1" id="managerApprovalInboxTitle"><i class="fas fa-inbox text-primary me-2"></i>اعتماداتي المعيّنة</h5>
                    <p class="small text-secondary mb-0">تظهر لك المراحل النشطة المسندة إليك فقط. يحفظ النظام نسخة المرحلة وقفلها قبل القرار.</p>
                </div>
                <span class="badge bg-primary rounded-pill px-3 py-2"><i class="fas fa-list-check me-1"></i><?php echo $total; ?> معلّق</span>
            </div>
            <?php if ($feedback !== null): ?>
                <div class="alert alert-<?php echo self::e($feedback['kind']); ?> alert-dismissible fade show m-3 mb-0" role="alert">
                    <i class="fas fa-<?php echo $feedback['kind'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i><?php echo self::e($feedback['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                </div>
            <?php endif; ?>
            <?php if ($items === []): ?>
                <div class="alert alert-success m-3 mb-0" role="status"><i class="fas fa-check-circle me-2"></i>لا توجد اعتمادات نشطة مسندة إليك حاليًا.</div>
            <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped admin-data-table mb-0">
                        <thead>
                            <tr>
                                <th>الطلب</th>
                                <th>العامل</th>
                                <th>مرحلة الاعتماد</th>
                                <th>الموعد</th>
                                <th>الإسناد</th>
                                <th class="text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo self::e(self::resourceLabel($item['resource_type'])); ?></div>
                                        <div class="small text-secondary">رقم <?php echo $item['resource_id']; ?> · مرحلة <?php echo $item['sequence_no']; ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo self::e($item['staff_display_name']); ?></div>
                                        <div class="small text-secondary">طلب #<?php echo $item['request_id'] ?? $item['resource_id']; ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo self::e($item['stage_name']); ?></div>
                                        <span class="badge bg-primary-subtle text-primary-emphasis"><?php echo self::e(self::decisionModeLabel($item['decision_mode'])); ?></span>
                                    </td>
                                    <td>
                                        <div><?php echo self::e($item['due_label']); ?></div>
                                        <span class="badge bg-<?php echo self::e(self::dueBadge($item['due_state'])); ?>"><?php echo self::e(self::dueLabel($item['due_state'])); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis"><i class="fas fa-user-shield me-1"></i><?php echo self::e($item['assignment_label']); ?></span>
                                    </td>
                                    <td class="text-center admin-table-actions">
                                        <?php echo self::actionButtons($item, $actionUrl, $csrfToken, $modals); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php echo implode('', $modals); ?>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Compact dashboard trigger; the route supplies the already scoped count.
     */
    public static function renderDashboardCounter(int $total, string $href): string
    {
        if ($total < 0) {
            throw new InvalidArgumentException('APPROVAL_INBOX_TOTAL_INVALID');
        }
        $href = self::safeRelativeAction($href);

        return '<a href="' . self::e($href) . '" class="btn btn-outline-primary shadow-sm px-3 py-2">'
            . '<i class="fas fa-inbox me-2"></i>اعتماداتي المعيّنة'
            . '<span class="badge bg-primary ms-2">' . $total . '</span></a>';
    }

    /** @param mixed $raw @return list<array<string,mixed>> */
    private static function items(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('APPROVAL_INBOX_ITEMS_INVALID');
        }
        $items = [];
        foreach ($raw as $rawItem) {
            if (!is_array($rawItem)) {
                throw new InvalidArgumentException('APPROVAL_INBOX_ITEM_INVALID');
            }
            $instanceId = self::positiveInt($rawItem['instance_id'] ?? null, 'APPROVAL_INBOX_ITEM_INVALID');
            $stepId = self::positiveInt($rawItem['step_id'] ?? null, 'APPROVAL_INBOX_ITEM_INVALID');
            $resourceId = self::positiveInt($rawItem['resource_id'] ?? null, 'APPROVAL_INBOX_ITEM_INVALID');
            $staffUserId = self::nullablePositiveInt($rawItem['staff_user_id'] ?? null);
            $actions = self::actions($rawItem['actions'] ?? []);
            $items[] = [
                'instance_id' => $instanceId,
                'step_id' => $stepId,
                'step_lock_version' => self::positiveInt($rawItem['step_lock_version'] ?? null, 'APPROVAL_INBOX_ITEM_INVALID'),
                'resource_type' => self::resourceType($rawItem['resource_type'] ?? null),
                'resource_id' => $resourceId,
                'sequence_no' => self::positiveInt($rawItem['sequence_no'] ?? null, 'APPROVAL_INBOX_ITEM_INVALID'),
                'stage_name' => self::requiredText($rawItem['stage_name'] ?? null, 'APPROVAL_INBOX_ITEM_INVALID', 200),
                'decision_mode' => self::decisionMode($rawItem['decision_mode'] ?? null),
                'due_state' => self::dueState($rawItem['due_state'] ?? null),
                'due_label' => self::dueDateLabel($rawItem['due_at'] ?? null),
                'staff_display_name' => self::staffLabel($rawItem['staff_display_name'] ?? null, $staffUserId),
                'request_id' => self::nullablePositiveInt($rawItem['request_id'] ?? null),
                'assignment_label' => self::assignmentLabel($rawItem),
                'actions' => $actions,
            ];
        }

        return $items;
    }

    /** @param mixed $raw @return array<string,string> */
    private static function actions(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException('APPROVAL_INBOX_ACTIONS_INVALID');
        }
        $actions = [];
        foreach (['approve', 'reject'] as $name) {
            $definition = $raw[$name] ?? null;
            if ($definition === null || $definition === false) {
                continue;
            }
            $key = is_array($definition) ? ($definition['idempotency_key'] ?? null) : $definition;
            $actions[$name] = self::requiredText($key, 'APPROVAL_INBOX_ACTIONS_INVALID', 190);
        }

        return $actions;
    }

    /** @param array<string,mixed> $item @param list<string> $modals */
    private static function actionButtons(array $item, string $actionUrl, string $csrfToken, array &$modals): string
    {
        $actions = $item['actions'];
        if ($actions === []) {
            return '<span class="text-secondary small">لا توجد عملية متاحة</span>';
        }
        $buttons = [];
        if (isset($actions['approve'])) {
            $buttons[] = '<form method="post" action="' . self::e($actionUrl) . '" class="d-inline" data-no-form-safety="true">'
                . self::hidden('csrf_token', $csrfToken)
                . self::hidden('approval_intent', 'decide')
                . self::hidden('decision', 'approve')
                . self::hidden('step_id', (string) $item['step_id'])
                . self::hidden('expected_lock_version', (string) $item['step_lock_version'])
                . self::hidden('idempotency_key', $actions['approve'])
                . '<button type="submit" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="موافقة"><i class="fas fa-check"></i></button></form>';
        }
        if (isset($actions['reject'])) {
            $modalId = 'managerApprovalRejectModal-' . $item['instance_id'] . '-' . $item['step_id'];
            $buttons[] = '<button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="modal" data-bs-target="#' . self::e($modalId) . '" title="رفض"><i class="fas fa-times"></i></button>';
            $modals[] = self::rejectModal($modalId, $item, $actionUrl, $csrfToken, $actions['reject']);
        }

        return implode('', $buttons);
    }

    /** @param array<string,mixed> $item */
    private static function rejectModal(string $modalId, array $item, string $actionUrl, string $csrfToken, string $idempotencyKey): string
    {
        return '<div class="modal fade" id="' . self::e($modalId) . '" tabindex="-1" aria-hidden="true">'
            . '<div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete">'
            . '<form method="post" action="' . self::e($actionUrl) . '" data-no-form-safety="true">'
            . self::hidden('csrf_token', $csrfToken)
            . self::hidden('approval_intent', 'decide')
            . self::hidden('decision', 'reject')
            . self::hidden('step_id', (string) $item['step_id'])
            . self::hidden('expected_lock_version', (string) $item['step_lock_version'])
            . self::hidden('idempotency_key', $idempotencyKey)
            . '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>رفض الاعتماد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
            . '<div class="modal-body"><div class="text-center mb-3"><i class="fas fa-file-circle-xmark text-danger" style="font-size: 2.5rem;"></i></div>'
            . '<p class="text-center">اكتب سبب رفض «' . self::e($item['stage_name']) . '» قبل الإرسال.</p>'
            . '<label class="form-label" for="manager_approval_comment_' . $item['step_id'] . '">سبب الرفض <span class="text-danger">*</span></label>'
            . '<textarea class="form-control" id="manager_approval_comment_' . $item['step_id'] . '" name="comment" rows="3" maxlength="5000" required></textarea></div>'
            . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>'
            . '<button type="submit" class="btn btn-danger"><i class="fas fa-times-circle me-1"></i>تأكيد الرفض</button></div>'
            . '</form></div></div></div>';
    }

    /** @return array{kind:string,message:string}|null */
    private static function feedback(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $kind = (string) ($raw['kind'] ?? 'danger');
        if (!in_array($kind, ['success', 'info', 'warning', 'danger'], true)) {
            $kind = 'danger';
        }
        $message = self::safeMessage((string) ($raw['code'] ?? $raw['message'] ?? ''));

        return $message === '' ? null : ['kind' => $kind, 'message' => $message];
    }

    private static function safeMessage(string $value): string
    {
        $code = strtoupper(trim($value));
        $messages = [
            'NOT_ASSIGNED_APPROVER' => 'لا تملك صلاحية اتخاذ قرار في هذه المرحلة.',
            'ALREADY_DECIDED' => 'لم تعد هذه المرحلة متاحة للقرار. حدّث القائمة.',
            'STALE_APPROVAL_STEP' => 'تم تحديث المرحلة من جلسة أخرى. حدّث القائمة ثم راجعها.',
            'SELF_APPROVAL_FORBIDDEN' => 'لا يسمح المسار باعتماد صاحب الطلب لنفسه.',
            'APPROVAL_REJECTION_REASON_REQUIRED' => 'اكتب سبب الرفض قبل الإرسال.',
            'CSRF_INVALID' => 'انتهت صلاحية التحقق الأمني. حدّث الصفحة ثم أعد المحاولة.',
            'APPROVAL_INBOX_UNAVAILABLE' => 'لا تتوفر خدمة الاعتماد الآن. تحقق من الترحيلات ثم أعد المحاولة.',
            'PERMISSION_APPROVAL_OUTCOME_STALE' => 'تم تحديث طلب الإذن أو انتهت مرحلته. حدّث القائمة ثم راجعه.',
            'PERMISSION_QUOTA_EXCEEDED' => 'لا يمكن اعتماد الطلب لأن الرصيد الشهري المتاح لم يعد كافيًا.',
            'PERMISSION_QUOTA_RESERVATION_MISSING' => 'تعذر التحقق من حجز رصيد الإذن. لا يتم حفظ القرار قبل معالجة ذلك.',
            'LEAVE_APPROVAL_OUTCOME_STALE' => 'تم تحديث طلب الإجازة أو انتهت مرحلته. حدّث القائمة ثم راجعه.',
            'LEAVE_REQUEST_STALE' => 'تم تحديث طلب الإجازة من جلسة أخرى. حدّث القائمة ثم راجعه.',
            'LEAVE_REQUEST_NOT_DRAFT' => 'لا يمكن تنفيذ هذا القرار لأن طلب الإجازة لم يعد مسودة صالحة للاعتماد.',
            'LEAVE_REQUEST_OVERLAP' => 'لا يمكن اعتماد الطلب بسبب تداخله مع إجازة أخرى.',
            'LEAVE_REQUEST_BLACKOUT' => 'لا يمكن اعتماد الطلب لأنه يقع ضمن فترة محظورة بحسب السياسة.',
            'LEAVE_BLACKOUT_OVERRIDE_REQUIRED' => 'يتطلب الطلب موافقة استثنائية على فترة الحظر قبل إتمام الاعتماد.',
            'LEAVE_STAFFING_OVERRIDE_REQUIRED' => 'يتطلب الطلب موافقة استثنائية على التغطية التشغيلية قبل إتمام الاعتماد.',
            'LEAVE_STAFFING_MINIMUM_BREACHED' => 'لا تسمح التغطية التشغيلية المتاحة باعتماد هذه الإجازة حاليًا.',
        ];
        if (isset($messages[$code])) {
            return $messages[$code];
        }
        if ($code === '') {
            return '';
        }
        if (preg_match('/SQLSTATE|PDO|DUPLICATE|STACK|TRACE|[A-Z]:\\\\|\.PHP:\\d+/i', $value)) {
            return 'تعذر حفظ قرار الاعتماد الآن. حدّث القائمة ثم أعد المحاولة.';
        }
        if (mb_strlen($value) <= 500 && preg_match('/[\x{0600}-\x{06FF}]/u', $value)) {
            return trim($value);
        }

        return 'تعذر حفظ قرار الاعتماد الآن. حدّث القائمة ثم أعد المحاولة.';
    }

    private static function resourceLabel(string $resourceType): string
    {
        return [
            'permission_request' => 'طلب إذن',
            'leave_request' => 'طلب إجازة',
            'staff_schedule_change_request' => 'طلب تغيير دوام',
            'attendance_adjustment' => 'تصحيح حضور',
        ][$resourceType] ?? 'طلب إداري';
    }

    private static function decisionModeLabel(string $mode): string
    {
        return [
            'sequential' => 'قرار متسلسل',
            'any_one' => 'يكفي معتمد واحد',
            'all' => 'موافقة الجميع',
            'quorum' => 'نصاب مطلوب',
        ][$mode] ?? 'مسار اعتماد';
    }

    private static function dueBadge(string $state): string
    {
        return ['overdue' => 'danger', 'open' => 'info', 'no_due_date' => 'secondary'][$state];
    }

    private static function dueLabel(string $state): string
    {
        return ['overdue' => 'متأخر', 'open' => 'ضمن المهلة', 'no_due_date' => 'بلا موعد'][$state];
    }

    /** @param array<string,mixed> $item */
    private static function assignmentLabel(array $item): string
    {
        if (($item['acting_for_user_id'] ?? null) !== null) {
            return 'بالإنابة';
        }

        return 'مسند إليك';
    }

    private static function dueDateLabel(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return 'لا يوجد موعد محدد';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $text) !== 1) {
            throw new InvalidArgumentException('APPROVAL_INBOX_ITEM_INVALID');
        }

        return str_replace('T', ' ', substr($text, 0, 16));
    }

    private static function staffLabel(mixed $value, ?int $staffUserId): string
    {
        $name = trim((string) $value);
        if ($name !== '') {
            return self::requiredText($name, 'APPROVAL_INBOX_ITEM_INVALID', 200);
        }

        return $staffUserId === null ? 'عامل غير محدد' : 'العامل #' . $staffUserId;
    }

    private static function decisionMode(mixed $value): string
    {
        $mode = trim((string) $value);
        if (!in_array($mode, ['sequential', 'any_one', 'all', 'quorum'], true)) {
            throw new InvalidArgumentException('APPROVAL_INBOX_ITEM_INVALID');
        }

        return $mode;
    }

    private static function dueState(mixed $value): string
    {
        $state = trim((string) $value);
        if (!in_array($state, ['overdue', 'open', 'no_due_date'], true)) {
            throw new InvalidArgumentException('APPROVAL_INBOX_ITEM_INVALID');
        }

        return $state;
    }

    private static function resourceType(mixed $value): string
    {
        $type = trim((string) $value);
        if ($type === '' || mb_strlen($type) > 80 || preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new InvalidArgumentException('APPROVAL_INBOX_ITEM_INVALID');
        }

        return $type;
    }

    private static function positiveInt(mixed $value, string $errorCode): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($errorCode);
        }

        return (int) $value;
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::positiveInt($value, 'APPROVAL_INBOX_ITEM_INVALID');
    }

    private static function nonNegativeInt(mixed $value, string $errorCode): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new InvalidArgumentException($errorCode);
        }

        return (int) $value;
    }

    private static function requiredText(mixed $value, string $errorCode, int $maximum): string
    {
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text) > $maximum) {
            throw new InvalidArgumentException($errorCode);
        }

        return $text;
    }

    private static function safeRelativeAction(string $action): string
    {
        $action = trim($action);
        if ($action === '' || str_contains($action, "\n") || str_contains($action, "\r") || str_starts_with($action, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $action) === 1) {
            throw new InvalidArgumentException('APPROVAL_INBOX_ACTION_URL_INVALID');
        }

        return $action;
    }

    private static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
