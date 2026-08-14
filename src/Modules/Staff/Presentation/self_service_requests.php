<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Presentation;

use InvalidArgumentException;

/**
 * Shared, data-only presentation component for permission and leave self-service.
 *
 * The entrypoint that uses this component owns authentication, CSRF validation,
 * POST routing and the application service call. In particular, it must derive
 * the staff member from the authenticated session; this component deliberately
 * does not render a mutable staff_user_id field.
 */
final class StaffSelfServiceRequests
{
    /**
     * @param array<string,mixed> $view
     */
    public static function renderPortal(array $view): string
    {
        $csrfToken = self::requiredText($view['csrf_token'] ?? null, 'STAFF_PORTAL_CSRF_TOKEN_REQUIRED');
        $draftScope = self::requiredText($view['draft_scope'] ?? null, 'STAFF_PORTAL_DRAFT_SCOPE_REQUIRED');
        $createIdempotencyKey = self::requiredText(
            $view['create_idempotency_key'] ?? null,
            'STAFF_PORTAL_CREATE_IDEMPOTENCY_KEY_REQUIRED'
        );
        $submissionIdempotencyKey = self::requiredText(
            $view['submission_idempotency_key'] ?? null,
            'STAFF_PORTAL_SUBMISSION_IDEMPOTENCY_KEY_REQUIRED'
        );
        $actionUrl = self::safeRelativeAction((string) ($view['action_url'] ?? ''));
        $timezone = self::timezone((string) ($view['timezone'] ?? 'Africa/Cairo'));
        $permissionTypes = self::permissionTypes($view['permission_types'] ?? []);
        $quotaRows = self::quotaRows($view['quota_rows'] ?? []);
        $requests = self::requests($view['requests'] ?? []);
        $values = is_array($view['values'] ?? null) ? $view['values'] : [];
        $fieldErrors = self::fieldErrors($view['field_errors'] ?? []);
        $feedback = self::feedback($view['feedback'] ?? null);
        $staffName = trim((string) ($view['staff_display_name'] ?? ''));
        $hasTypes = $permissionTypes !== [];
        $requestModals = [];

        ob_start();
        ?>
        <section class="card shadow-sm admin-card-surface mb-4" aria-labelledby="staffPermissionPortalTitle">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0" id="staffPermissionPortalTitle"><i class="fas fa-clock me-2"></i>طلب إذن</h5>
                    <small class="opacity-75">تُرسل الطلبات لمسار الاعتماد المحدد لك، ويحدد الخادم نطاقك عند كل عملية.</small>
                </div>
                <?php if ($staffName !== ''): ?>
                    <span class="badge bg-light text-dark"><i class="fas fa-user me-1"></i><?php echo self::e($staffName); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($feedback !== null): ?>
                    <div class="alert alert-<?php echo self::e($feedback['kind']); ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-<?php echo $feedback['kind'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i><?php echo self::e($feedback['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-lg-5">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-2"><i class="fas fa-circle-info text-primary me-2"></i>كيف يُحتسب الطلب؟</h6>
                            <p class="small text-secondary mb-0">الإذن يغطي الدقائق المعتمدة فقط ولا يحل محل بصمة الحضور أو الانصراف. راجع الفترة والسبب قبل الإرسال.</p>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-2"><i class="fas fa-wallet text-primary me-2"></i>رصيدي للشهر الحالي</h6>
                            <?php if ($quotaRows === []): ?>
                                <p class="small text-secondary mb-0">لا توجد حصة منشورة ظاهرة لهذا الشهر. ستتحقق المنظومة من السياسة النافذة عند الإرسال.</p>
                            <?php else: ?>
                                <div class="row row-cols-1 row-cols-md-2 g-2">
                                    <?php foreach ($quotaRows as $quota): ?>
                                        <div class="col">
                                            <div class="bg-light border rounded p-2 h-100">
                                                <div class="fw-semibold small mb-1"><?php echo self::e($quota['type_name']); ?></div>
                                                <div class="small text-secondary">المتاح: <?php echo self::e(self::quotaText($quota, 'available')); ?></div>
                                                <div class="small text-secondary">المحجوز: <?php echo self::e(self::quotaText($quota, 'held')); ?></div>
                                                <div class="small text-secondary">المستخدم: <?php echo self::e(self::quotaText($quota, 'used')); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!$hasTypes): ?>
                    <div class="alert alert-warning mb-0" role="alert">
                        <i class="fas fa-triangle-exclamation me-2"></i>لا يوجد نوع إذن متاح لك حاليًا. راجع شؤون العاملين إذا كان ذلك غير متوقع.
                    </div>
                <?php else: ?>
                    <form method="post" action="<?php echo self::e($actionUrl); ?>" id="staffPermissionRequestForm" data-draft-scope="<?php echo self::e('staff:' . $draftScope); ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo self::e($csrfToken); ?>">
                        <input type="hidden" name="create_idempotency_key" value="<?php echo self::e($createIdempotencyKey); ?>">
                        <input type="hidden" name="submission_idempotency_key" value="<?php echo self::e($submissionIdempotencyKey); ?>">
                        <input type="hidden" name="timezone" value="<?php echo self::e($timezone); ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="staff_permission_type_id">نوع الإذن <span class="text-danger">*</span></label>
                                <select class="form-select<?php echo self::invalidClass($fieldErrors, 'permission_type_id'); ?>" name="permission_type_id" id="staff_permission_type_id" required aria-describedby="staff_permission_type_id_help">
                                    <option value="">اختر نوع الإذن...</option>
                                    <?php foreach ($permissionTypes as $type): ?>
                                        <option value="<?php echo (int) $type['id']; ?>"
                                            data-requires-reason="<?php echo $type['requires_reason'] ? '1' : '0'; ?>"
                                            data-requires-custom-label="<?php echo $type['requires_custom_label'] ? '1' : '0'; ?>"
                                            data-requires-attachment="<?php echo $type['requires_attachment'] ? '1' : '0'; ?>"
                                            <?php echo (string) ($values['permission_type_id'] ?? '') === (string) $type['id'] ? 'selected' : ''; ?>><?php echo self::e($type['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="staff_permission_type_id_help">قد يطلب بعض الأنواع سببًا أو مسمىً إضافيًا أو مرفقًا عند الإرسال.</div>
                                <?php echo self::fieldFeedback($fieldErrors, 'permission_type_id'); ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="staff_permission_custom_label">المسمى التفصيلي</label>
                                <input type="text" class="form-control<?php echo self::invalidClass($fieldErrors, 'custom_label'); ?>" name="custom_label" id="staff_permission_custom_label" maxlength="200" value="<?php echo self::e((string) ($values['custom_label'] ?? '')); ?>" placeholder="مطلوب عند اختيار نوع «أخرى»">
                                <?php echo self::fieldFeedback($fieldErrors, 'custom_label'); ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="staff_permission_from_at">من <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control<?php echo self::invalidClass($fieldErrors, 'from_at'); ?>" name="from_at" id="staff_permission_from_at" required value="<?php echo self::e(self::dateTimeLocal($values['from_at'] ?? '')); ?>">
                                <?php echo self::fieldFeedback($fieldErrors, 'from_at'); ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="staff_permission_to_at">إلى <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control<?php echo self::invalidClass($fieldErrors, 'to_at'); ?>" name="to_at" id="staff_permission_to_at" required value="<?php echo self::e(self::dateTimeLocal($values['to_at'] ?? '')); ?>">
                                <?php echo self::fieldFeedback($fieldErrors, 'to_at'); ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="staff_permission_reason">السبب</label>
                                <textarea class="form-control<?php echo self::invalidClass($fieldErrors, 'reason'); ?>" name="reason" id="staff_permission_reason" rows="3" maxlength="4000" placeholder="اكتب سبب الإذن بوضوح عند الحاجة"><?php echo self::e((string) ($values['reason'] ?? '')); ?></textarea>
                                <?php echo self::fieldFeedback($fieldErrors, 'reason'); ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="staff_permission_attachment_ref">مرجع المرفق</label>
                                <input type="text" class="form-control<?php echo self::invalidClass($fieldErrors, 'attachment_ref'); ?>" name="attachment_ref" id="staff_permission_attachment_ref" maxlength="500" value="<?php echo self::e((string) ($values['attachment_ref'] ?? '')); ?>" placeholder="يظهر بعد رفع المرفق من الخدمة الآمنة عند تفعيلها">
                                <div class="form-text">لا تضع رابطًا عامًا أو مسارًا على جهازك؛ يقبل الخادم مرجع المرفق الخاص فقط.</div>
                                <?php echo self::fieldFeedback($fieldErrors, 'attachment_ref'); ?>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                            <button type="submit" name="permission_request_intent" value="draft" class="btn btn-outline-secondary">
                                <i class="fas fa-file-pen me-1"></i>حفظ مسودة
                            </button>
                            <button type="submit" name="permission_request_intent" value="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-1"></i>إرسال للموافقة
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="admin-list-surface mb-4" aria-labelledby="staffPermissionRequestsTitle">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0" id="staffPermissionRequestsTitle"><i class="fas fa-list-check text-primary me-2"></i>طلباتي</h5>
                <span class="badge bg-primary rounded-pill"><?php echo count($requests); ?></span>
            </div>
            <?php if ($requests === []): ?>
                <div class="alert alert-info m-3 mb-0"><i class="fas fa-info-circle me-2"></i>لا توجد طلبات إذن ظاهرة لك حاليًا.</div>
            <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped admin-data-table mb-0">
                        <thead>
                            <tr>
                                <th>النوع</th>
                                <th>الفترة</th>
                                <th>المدة</th>
                                <th>الحالة</th>
                                <th>مسار المتابعة</th>
                                <th class="text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr data-request-id="<?php echo (int) $request['id']; ?>">
                                    <td class="fw-semibold"><?php echo self::e($request['type_name']); ?><?php echo $request['custom_label'] !== '' ? '<div class="small text-secondary">' . self::e($request['custom_label']) . '</div>' : ''; ?></td>
                                    <td><div><?php echo self::e($request['from_at']); ?></div><div class="small text-secondary"><?php echo self::e($request['to_at']); ?></div></td>
                                    <td><?php echo self::e(self::minutesText($request['requested_minutes'])); ?></td>
                                    <td><span class="badge bg-<?php echo self::e(self::statusBadge($request['status'])); ?>"><?php echo self::e(self::statusLabel($request['status'])); ?></span></td>
                                    <td class="small"><?php echo self::e($request['workflow_label']); ?></td>
                                    <td class="text-center admin-table-actions"><?php echo self::requestActions($request, $actionUrl, $csrfToken, $requestModals); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php echo implode('', $requestModals); ?>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Data-only leave self-service surface.
     *
     * The HTTP entrypoint must derive the worker from its authenticated
     * session, validate CSRF, call LeaveRequestService / LeaveAttachmentService
     * and use PRG. This renderer never emits a mutable worker identifier or a
     * browser-supplied private attachment reference.
     *
     * @param array<string,mixed> $view
     */
    public static function renderLeavePortal(array $view): string
    {
        $csrfToken = self::requiredText($view['csrf_token'] ?? null, 'STAFF_LEAVE_PORTAL_CSRF_TOKEN_REQUIRED');
        $draftScope = self::requiredText($view['draft_scope'] ?? null, 'STAFF_LEAVE_PORTAL_DRAFT_SCOPE_REQUIRED');
        $createIdempotencyKey = self::requiredText(
            $view['create_idempotency_key'] ?? null,
            'STAFF_LEAVE_PORTAL_CREATE_IDEMPOTENCY_KEY_REQUIRED'
        );
        $submissionIdempotencyKey = self::requiredText(
            $view['submission_idempotency_key'] ?? null,
            'STAFF_LEAVE_PORTAL_SUBMISSION_IDEMPOTENCY_KEY_REQUIRED'
        );
        $actionUrl = self::safeRelativeAction((string) ($view['action_url'] ?? ''));
        $timezone = self::timezone((string) ($view['timezone'] ?? 'Africa/Cairo'));
        $leaveTypes = self::leaveTypes($view['leave_types'] ?? []);
        $balanceRows = self::leaveBalanceRows($view['balance_rows'] ?? []);
        $requests = self::leaveRequests($view['requests'] ?? []);
        $values = is_array($view['values'] ?? null) ? $view['values'] : [];
        $fieldErrors = self::leaveFieldErrors($view['field_errors'] ?? []);
        $feedback = self::leaveFeedback($view['feedback'] ?? null);
        $staffName = trim((string) ($view['staff_display_name'] ?? ''));
        $requestModals = [];

        ob_start();
        ?>
        <section class="card shadow-sm admin-card-surface mb-4" aria-labelledby="staffLeavePortalTitle">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0" id="staffLeavePortalTitle"><i class="fas fa-calendar-plus me-2"></i>طلب إجازة</h5>
                    <small class="opacity-75">تُراجع سياسة الإجازة والرصيد والتغطية التشغيلية عند الإرسال، ثم يسلك الطلب مسار الاعتماد المحدد لك.</small>
                </div>
                <?php if ($staffName !== ''): ?>
                    <span class="badge bg-light text-dark"><i class="fas fa-user me-1"></i><?php echo self::e($staffName); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($feedback !== null): ?>
                    <div class="alert alert-<?php echo self::e($feedback['kind']); ?> alert-dismissible fade show" role="alert" data-leave-feedback-code="<?php echo self::e($feedback['code']); ?>">
                        <i class="fas fa-<?php echo $feedback['kind'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i><?php echo self::e($feedback['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                    </div>
                <?php endif; ?>

                <section class="admin-list-surface mb-3" aria-labelledby="staffLeaveBalancesTitle">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h6 class="mb-1" id="staffLeaveBalancesTitle"><i class="fas fa-scale-balanced text-primary me-2"></i>رصيد إجازاتي</h6>
                            <p class="small text-secondary mb-0">الرصيد المتاح بعد خصم المستخدم والمحجوز في الطلبات الجارية.</p>
                        </div>
                        <span class="badge bg-primary rounded-pill"><?php echo count($balanceRows); ?></span>
                    </div>
                    <?php if ($balanceRows === []): ?>
                        <div class="alert alert-info m-3 mb-0" role="status"><i class="fas fa-info-circle me-2"></i>لا يوجد رصيد إجازة مسجل لك حاليًا. راجع شؤون العاملين إذا كان ذلك غير متوقع.</div>
                    <?php else: ?>
                        <div class="table-responsive admin-table-wrap">
                            <table class="table table-hover table-striped admin-data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>نوع الإجازة</th>
                                        <th>فترة الاستحقاق</th>
                                        <th>المتاح</th>
                                        <th>المحجوز</th>
                                        <th>المستخدم</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($balanceRows as $balance): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo self::e($balance['type_name']); ?></td>
                                            <td><?php echo self::e($balance['period_key']); ?></td>
                                            <td><span class="badge bg-success"><?php echo self::e($balance['available_units']); ?> وحدة</span></td>
                                            <td><?php echo self::e($balance['held_units']); ?> وحدة</td>
                                            <td><?php echo self::e($balance['used_units']); ?> وحدة</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <div class="alert alert-info d-flex gap-2 align-items-start" role="note">
                    <i class="fas fa-shield-halved mt-1"></i>
                    <div><strong>خطوات آمنة:</strong> احفظ المسودة أولًا عند الحاجة إلى مرفق، ثم أرفق الملف من بطاقة الطلب نفسها. لا تضع رابطًا عامًا أو مسارًا من جهازك في الطلب.</div>
                </div>

                <?php if ($leaveTypes === []): ?>
                    <div class="alert alert-warning mb-0" role="alert">
                        <i class="fas fa-triangle-exclamation me-2"></i>لا يوجد نوع إجازة متاح لك حاليًا. راجع شؤون العاملين إذا كان ذلك غير متوقع.
                    </div>
                <?php else: ?>
                    <form method="post" action="<?php echo self::e($actionUrl); ?>" id="staffLeaveRequestForm" data-draft-scope="<?php echo self::e('staff:' . $draftScope . ':leave'); ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo self::e($csrfToken); ?>">
                        <input type="hidden" name="create_idempotency_key" value="<?php echo self::e($createIdempotencyKey); ?>">
                        <input type="hidden" name="submission_idempotency_key" value="<?php echo self::e($submissionIdempotencyKey); ?>">
                        <input type="hidden" name="timezone" value="<?php echo self::e($timezone); ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="staff_leave_type_id">نوع الإجازة <span class="text-danger">*</span></label>
                                <select class="form-select<?php echo self::invalidClass($fieldErrors, 'leave_type_id'); ?>" name="leave_type_id" id="staff_leave_type_id" required aria-describedby="staff_leave_type_help">
                                    <option value="">اختر نوع الإجازة...</option>
                                    <?php foreach ($leaveTypes as $type): ?>
                                        <option value="<?php echo (int) $type['id']; ?>"
                                            data-unit="<?php echo self::e($type['unit']); ?>"
                                            data-requires-reason="<?php echo $type['requires_reason'] ? '1' : '0'; ?>"
                                            data-requires-attachment="<?php echo $type['requires_attachment'] ? '1' : '0'; ?>"
                                            data-requires-medical-document="<?php echo $type['requires_medical_document'] ? '1' : '0'; ?>"
                                            <?php echo (string) ($values['leave_type_id'] ?? '') === (string) $type['id'] ? 'selected' : ''; ?>><?php echo self::e($type['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="staff_leave_type_help">يحدد الخادم الاستحقاق والرصيد والمتطلبات الفعلية للنموذج المختار.</div>
                                <?php echo self::fieldFeedback($fieldErrors, 'leave_type_id'); ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="staff_leave_reason">السبب</label>
                                <input type="text" class="form-control<?php echo self::invalidClass($fieldErrors, 'reason'); ?>" name="reason" id="staff_leave_reason" maxlength="10000" value="<?php echo self::e((string) ($values['reason'] ?? '')); ?>" placeholder="اكتب السبب إذا كان نوع الإجازة يتطلبه">
                                <?php echo self::fieldFeedback($fieldErrors, 'reason'); ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="staff_leave_from_at">من <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control<?php echo self::invalidClass($fieldErrors, 'from_at'); ?>" name="from_at" id="staff_leave_from_at" required value="<?php echo self::e(self::dateTimeLocal($values['from_at'] ?? '')); ?>">
                                <?php echo self::fieldFeedback($fieldErrors, 'from_at'); ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="staff_leave_to_at">إلى <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control<?php echo self::invalidClass($fieldErrors, 'to_at'); ?>" name="to_at" id="staff_leave_to_at" required value="<?php echo self::e(self::dateTimeLocal($values['to_at'] ?? '')); ?>">
                                <?php echo self::fieldFeedback($fieldErrors, 'to_at'); ?>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                            <button type="submit" name="leave_request_intent" value="draft" class="btn btn-success">
                                <i class="fas fa-file-circle-plus me-1"></i>حفظ مسودة
                            </button>
                            <button type="submit" name="leave_request_intent" value="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-1"></i>حفظ وإرسال للموافقة
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="admin-list-surface mb-4" aria-labelledby="staffLeaveRequestsTitle">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1" id="staffLeaveRequestsTitle"><i class="fas fa-route text-primary me-2"></i>إجازاتي</h5>
                    <p class="small text-secondary mb-0">مسار الطلب يوضح حالته الحالية؛ القرار النهائي دائمًا عبر مسار الاعتماد المعيّن.</p>
                </div>
                <span class="badge bg-primary rounded-pill"><?php echo count($requests); ?></span>
            </div>
            <?php if ($requests === []): ?>
                <div class="alert alert-info m-3 mb-0"><i class="fas fa-info-circle me-2"></i>لا توجد طلبات إجازة ظاهرة لك حاليًا.</div>
            <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped admin-data-table mb-0">
                        <thead>
                            <tr>
                                <th>الإجازة</th>
                                <th>الفترة</th>
                                <th>الاستحقاق</th>
                                <th>المرفق</th>
                                <th>مسار الطلب</th>
                                <th class="text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr data-leave-request-id="<?php echo (int) $request['id']; ?>">
                                    <td>
                                        <div class="fw-semibold"><?php echo self::e($request['leave_type_name']); ?></div>
                                        <div class="small text-secondary"><?php echo self::e(self::leaveKindLabel($request['request_kind'])); ?></div>
                                    </td>
                                    <td><div><?php echo self::e($request['from_at']); ?></div><div class="small text-secondary"><?php echo self::e($request['to_at']); ?></div></td>
                                    <td><div><?php echo self::e($request['requested_units']); ?> وحدة</div><div class="small text-secondary"><?php echo self::e(self::minutesText($request['requested_minutes'])); ?></div></td>
                                    <td><span class="badge bg-<?php echo self::e(self::leaveAttachmentBadge($request['attachment_status'])); ?>"><?php echo self::e(self::leaveAttachmentLabel($request['attachment_status'])); ?></span></td>
                                    <td>
                                        <div class="small fw-semibold mb-1">مسار الطلب</div>
                                        <?php echo self::leaveJourney($request['status']); ?>
                                        <div class="small text-secondary mt-1"><?php echo self::e($request['workflow_label']); ?></div>
                                    </td>
                                    <td class="text-center admin-table-actions"><?php echo self::leaveRequestActions($request, $actionUrl, $csrfToken, $requestModals); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php echo implode('', $requestModals); ?>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Compatibility renderer for admin/permissions.php. Field names, IDs,
     * form actions and legacy mode values are intentionally kept unchanged.
     *
     * @param array<string,mixed> $view
     */
    public static function renderLegacyAdminModals(array $view): string
    {
        $csrfToken = self::requiredText($view['csrf_token'] ?? null, 'STAFF_PORTAL_CSRF_TOKEN_REQUIRED');
        $staffList = self::legacyStaffList($view['staff_list'] ?? []);
        $permissionTypes = self::legacyLabels($view['permission_types'] ?? []);
        $statusLabels = self::legacyLabels($view['status_labels'] ?? []);
        $today = self::legacyDate((string) ($view['today'] ?? ''));

        ob_start();
        ?>
        <div class="modal fade" id="permissionModal" tabindex="-1" aria-labelledby="permissionModalLabel">
            <div class="modal-dialog modal-lg">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
                    <form method="POST" id="permissionForm">
                        <input type="hidden" name="csrf_token" value="<?php echo self::e($csrfToken); ?>">
                        <input type="hidden" name="id" id="permission_id" value="">
                        <input type="hidden" name="permission_form_mode" id="permission_form_mode" value="add">
                        <div class="modal-header">
                            <h5 class="modal-title" id="permissionModalLabel"><i class="fas fa-plus-circle me-2"></i>إضافة إذن جديد</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">الموظف <span class="text-danger">*</span></label>
                                    <select class="form-select" name="user_id" id="permission_user_id" required>
                                        <option value="">اختر الموظف...</option>
                                        <?php foreach ($staffList as $staff): ?>
                                            <option value="<?php echo (int) $staff['id']; ?>"><?php echo self::e($staff['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">نوع الإذن <span class="text-danger">*</span></label>
                                    <select class="form-select" name="permission_type" id="permission_type" required>
                                        <?php foreach ($permissionTypes as $key => $label): ?>
                                            <option value="<?php echo self::e($key); ?>"><?php echo self::e($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control flatpickr-date" name="permission_date" id="permission_date" value="<?php echo self::e($today); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">الحالة</label>
                                    <select class="form-select" name="status" id="permission_status">
                                        <?php foreach ($statusLabels as $key => $label): ?>
                                            <option value="<?php echo self::e($key); ?>" <?php echo $key === 'approved' ? 'selected' : ''; ?>><?php echo self::e($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">من الساعة</label>
                                    <input type="time" class="form-control" name="time_from" id="permission_time_from" value="">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">إلى الساعة</label>
                                    <input type="time" class="form-control" name="time_to" id="permission_time_to" value="">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">السبب</label>
                                    <input type="text" class="form-control" name="reason" id="permission_reason" value="">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">ملاحظات</label>
                                    <input type="text" class="form-control" name="notes" id="permission_notes" value="">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                            <button type="submit" class="btn btn-success" id="permissionSubmitButton"><i class="fas fa-save me-1"></i>إضافة الإذن</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف إذن</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center">
                        <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
                        <p>هل أنت متأكد من حذف إذن <strong id="delete_name"></strong>؟</p>
                    </div>
                    <div class="modal-footer">
                        <form method="POST">
                            <input type="hidden" name="id" id="delete_id">
                            <input type="hidden" name="csrf_token" value="<?php echo self::e($csrfToken); ?>">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                            <button type="submit" name="delete_permission" class="btn btn-danger">حذف</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param mixed $raw @return list<array{id:int,name:string,requires_reason:bool,requires_custom_label:bool,requires_attachment:bool}> */
    private static function permissionTypes(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $types = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            $name = trim((string) ($item['name'] ?? $item['label'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $types[] = [
                'id' => $id,
                'name' => $name,
                'requires_reason' => (bool) ($item['requires_reason'] ?? false),
                'requires_custom_label' => (bool) ($item['requires_custom_label'] ?? false),
                'requires_attachment' => (bool) ($item['requires_attachment'] ?? false),
            ];
        }

        return $types;
    }

    /** @param mixed $raw @return list<array{type_name:string,available_count:?int,available_minutes:?int,held_count:?int,held_minutes:?int,used_count:?int,used_minutes:?int}> */
    private static function quotaRows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['type_name'] ?? $item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'type_name' => $name,
                'available_count' => self::nullableInt($item['available_count'] ?? null),
                'available_minutes' => self::nullableInt($item['available_minutes'] ?? null),
                'held_count' => self::nullableInt($item['held_count'] ?? null),
                'held_minutes' => self::nullableInt($item['held_minutes'] ?? null),
                'used_count' => self::nullableInt($item['used_count'] ?? null),
                'used_minutes' => self::nullableInt($item['used_minutes'] ?? null),
            ];
        }

        return $rows;
    }

    /** @param mixed $raw @return list<array{id:int,type_name:string,custom_label:string,from_at:string,to_at:string,requested_minutes:int,status:string,workflow_label:string,lock_version:int,actions:array<string,array<string,mixed>>}> */
    private static function requests(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $requests = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $requests[] = [
                'id' => $id,
                'type_name' => trim((string) ($item['type_name'] ?? $item['permission_type_name'] ?? 'إذن')) ?: 'إذن',
                'custom_label' => trim((string) ($item['custom_label'] ?? '')),
                'from_at' => self::displayDateTime($item['from_at'] ?? null),
                'to_at' => self::displayDateTime($item['to_at'] ?? null),
                'requested_minutes' => max(0, (int) ($item['requested_minutes'] ?? 0)),
                'status' => strtolower(trim((string) ($item['status'] ?? 'draft'))),
                'workflow_label' => trim((string) ($item['workflow_label'] ?? 'بانتظار الإرسال')) ?: 'بانتظار الإرسال',
                'lock_version' => max(1, (int) ($item['lock_version'] ?? 1)),
                'actions' => self::requestActionMap($item['actions'] ?? []),
            ];
        }

        return $requests;
    }

    /** @param mixed $raw @return array<string,array<string,mixed>> */
    private static function requestActionMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $actions = [];
        foreach (['submit', 'withdraw', 'cancel'] as $name) {
            $definition = $raw[$name] ?? null;
            if ($definition === true) {
                $actions[$name] = [];
            } elseif (is_array($definition) && ($definition['enabled'] ?? true) === true) {
                $actions[$name] = $definition;
            }
        }

        return $actions;
    }

    /** @param array<string,mixed> $request @param list<string> $modals */
    private static function requestActions(array $request, string $actionUrl, string $csrfToken, array &$modals): string
    {
        $buttons = [];
        $id = (int) $request['id'];
        $version = (int) $request['lock_version'];
        $actions = $request['actions'];

        if ($request['status'] === 'draft' && isset($actions['submit'])) {
            $token = trim((string) ($actions['submit']['idempotency_key'] ?? ''));
            if ($token !== '') {
                $buttons[] = '<form method="post" action="' . self::e($actionUrl) . '" class="d-inline" data-no-form-safety="true">'
                    . self::hidden('csrf_token', $csrfToken)
                    . self::hidden('permission_request_intent', 'submit')
                    . self::hidden('request_id', (string) $id)
                    . self::hidden('expected_lock_version', (string) $version)
                    . self::hidden('submission_idempotency_key', $token)
                    . '<button type="submit" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="إرسال للموافقة"><i class="fas fa-paper-plane"></i></button>'
                    . '</form>';
            }
        }

        if ($request['status'] === 'draft' && isset($actions['withdraw'])) {
            $modalId = 'staffPermissionWithdrawModal-' . $id;
            $buttons[] = '<button type="button" class="btn btn-action-pills btn-delete me-1" data-bs-toggle="modal" data-bs-target="#' . $modalId . '" title="سحب المسودة"><i class="fas fa-trash"></i></button>';
            $modals[] = self::withdrawModal($modalId, $actionUrl, $csrfToken, $id, $version);
        }

        if ($request['status'] === 'pending_approval' && isset($actions['cancel'])) {
            $modalId = 'staffPermissionCancelModal-' . $id;
            $buttons[] = '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#' . $modalId . '" title="إلغاء الطلب المعلّق"><i class="fas fa-ban"></i></button>';
            $modals[] = self::cancelModal($modalId, $actionUrl, $csrfToken, $id, $version);
        }

        if ($buttons === []) {
            return '<span class="text-secondary small">لا توجد عملية متاحة</span>';
        }

        return implode('', $buttons);
    }

    private static function withdrawModal(string $modalId, string $actionUrl, string $csrfToken, int $requestId, int $version): string
    {
        return '<div class="modal fade" id="' . self::e($modalId) . '" tabindex="-1" aria-hidden="true">'
            . '<div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete">'
            . '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>سحب المسودة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
            . '<div class="modal-body"><div class="text-center mb-3"><i class="fas fa-file-circle-xmark text-danger" style="font-size: 2.5rem;"></i></div><p class="text-center mb-0">هل تريد سحب هذه المسودة؟ لن يمكن إرسالها بعد السحب.</p></div>'
            . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>'
            . '<form method="post" action="' . self::e($actionUrl) . '" class="d-inline" data-no-form-safety="true">'
            . self::hidden('csrf_token', $csrfToken)
            . self::hidden('permission_request_intent', 'withdraw')
            . self::hidden('request_id', (string) $requestId)
            . self::hidden('expected_lock_version', (string) $version)
            . '<button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>سحب المسودة</button></form></div>'
            . '</div></div></div>';
    }

    private static function cancelModal(string $modalId, string $actionUrl, string $csrfToken, int $requestId, int $version): string
    {
        return '<div class="modal fade" id="' . self::e($modalId) . '" tabindex="-1" aria-hidden="true">'
            . '<div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning">'
            . '<form method="post" action="' . self::e($actionUrl) . '" data-no-form-safety="true">'
            . self::hidden('csrf_token', $csrfToken)
            . self::hidden('permission_request_intent', 'cancel')
            . self::hidden('request_id', (string) $requestId)
            . self::hidden('expected_lock_version', (string) $version)
            . '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-ban me-2"></i>إلغاء طلب معلّق</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
            . '<div class="modal-body"><div class="text-center mb-3"><i class="fas fa-triangle-exclamation text-warning" style="font-size: 2.5rem;"></i></div>'
            . '<p class="text-center">سيُرسل الإلغاء قبل الاعتماد ويُحرر الرصيد المحجوز وفق السياسة.</p>'
            . '<label class="form-label" for="staff_permission_cancel_reason_' . $requestId . '">سبب الإلغاء <span class="text-danger">*</span></label>'
            . '<textarea class="form-control" id="staff_permission_cancel_reason_' . $requestId . '" name="reason" rows="3" maxlength="2000" required></textarea></div>'
            . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>رجوع</button>'
            . '<button type="submit" class="btn btn-warning"><i class="fas fa-ban me-1"></i>تأكيد الإلغاء</button></div>'
            . '</form></div></div></div>';
    }

    /**
     * @param mixed $raw
     * @return list<array{id:int,name:string,unit:string,requires_reason:bool,requires_attachment:bool,requires_medical_document:bool}>
     */
    private static function leaveTypes(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $types = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            $name = trim((string) ($item['name'] ?? $item['label'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }

            $unit = strtolower(trim((string) ($item['unit'] ?? 'day')));
            if (!in_array($unit, ['day', 'hour'], true)) {
                $unit = 'day';
            }

            $types[] = [
                'id' => $id,
                'name' => $name,
                'unit' => $unit,
                'requires_reason' => self::portalBoolean($item['requires_reason'] ?? false),
                'requires_attachment' => self::portalBoolean($item['requires_attachment'] ?? false),
                'requires_medical_document' => self::portalBoolean($item['requires_medical_document'] ?? false),
            ];
        }

        return $types;
    }

    /**
     * @param mixed $raw
     * @return list<array{type_name:string,period_key:string,available_units:string,held_units:string,used_units:string}>
     */
    private static function leaveBalanceRows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $balances = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $typeName = trim((string) ($item['type_name'] ?? ''));
            $periodKey = trim((string) ($item['period_key'] ?? ''));
            if ($typeName === '' || $periodKey === '') {
                continue;
            }

            $balances[] = [
                'type_name' => $typeName,
                'period_key' => $periodKey,
                'available_units' => self::leaveUnits($item['available_units'] ?? null),
                'held_units' => self::leaveUnits($item['held_units'] ?? null),
                'used_units' => self::leaveUnits($item['used_units'] ?? null),
            ];
        }

        return $balances;
    }

    private static function leaveUnits(mixed $value): string
    {
        $units = trim((string) $value);
        if (preg_match('/^\d+(?:\.\d{1,3})?$/', $units) !== 1) {
            return '0.000';
        }

        return number_format((float) $units, 3, '.', '');
    }

    /**
     * @param mixed $raw
     * @return list<array{id:int,leave_type_name:string,request_kind:string,from_at:string,to_at:string,requested_units:string,requested_minutes:int,status:string,workflow_label:string,attachment_status:string,lock_version:int,actions:array<string,array<string,mixed>>}>
     */
    private static function leaveRequests(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $requests = [];
        $allowedKinds = ['leave', 'extension', 'early_return', 'cancellation'];
        $allowedStatuses = [
            'draft',
            'pending_approval',
            'approved',
            'rejected',
            'withdrawn',
            'cancelled',
            'cancellation_requested',
            'return_recorded',
            'cancelled_due_to_service_end',
            'superseded',
        ];
        $allowedAttachments = ['required', 'attached', 'not_required', 'missing'];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $kind = strtolower(trim((string) ($item['request_kind'] ?? 'leave')));
            if (!in_array($kind, $allowedKinds, true)) {
                $kind = 'leave';
            }
            $status = strtolower(trim((string) ($item['status'] ?? 'draft')));
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'draft';
            }
            $attachmentStatus = strtolower(trim((string) ($item['attachment_status'] ?? 'not_required')));
            if (!in_array($attachmentStatus, $allowedAttachments, true)) {
                $attachmentStatus = 'not_required';
            }

            $units = trim((string) ($item['requested_units'] ?? '0'));
            if (preg_match('/^\d+(?:\.\d{1,3})?$/', $units) !== 1) {
                $units = '0';
            }

            $requests[] = [
                'id' => $id,
                'leave_type_name' => trim((string) ($item['leave_type_name'] ?? $item['type_name'] ?? 'إجازة')) ?: 'إجازة',
                'request_kind' => $kind,
                'from_at' => self::displayDateTime($item['from_at'] ?? null),
                'to_at' => self::displayDateTime($item['to_at'] ?? null),
                'requested_units' => $units,
                'requested_minutes' => max(0, (int) ($item['requested_minutes'] ?? 0)),
                'status' => $status,
                'workflow_label' => trim((string) ($item['workflow_label'] ?? self::leaveJourneyLabel($status))) ?: self::leaveJourneyLabel($status),
                'attachment_status' => $attachmentStatus,
                'lock_version' => max(1, (int) ($item['lock_version'] ?? 1)),
                'actions' => self::leaveRequestActionMap($item['actions'] ?? []),
            ];
        }

        return $requests;
    }

    /** @param mixed $raw @return array<string,array<string,mixed>> */
    private static function leaveRequestActionMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $actions = [];
        foreach (['submit', 'withdraw', 'attach_medical'] as $name) {
            $definition = $raw[$name] ?? null;
            if ($definition === true) {
                $actions[$name] = [];
            } elseif (is_array($definition) && self::portalBoolean($definition['enabled'] ?? true)) {
                $actions[$name] = $definition;
            }
        }

        return $actions;
    }

    /** @param mixed $raw @return array<string,string> */
    private static function leaveFieldErrors(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $errors = [];
        foreach (['leave_type_id', 'reason', 'from_at', 'to_at', 'request_id', 'file'] as $field) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            $message = self::leaveSafeMessage((string) $raw[$field]);
            if ($message !== '') {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }

    /** @return array{kind:string,message:string,code:string}|null */
    private static function leaveFeedback(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $kind = (string) ($raw['kind'] ?? 'danger');
        if (!in_array($kind, ['success', 'info', 'warning', 'danger'], true)) {
            $kind = 'danger';
        }

        $rawCode = strtoupper(trim((string) ($raw['code'] ?? '')));
        $code = preg_match('/^[A-Z][A-Z0-9_]{2,120}$/D', $rawCode) === 1 ? $rawCode : '';
        $message = self::leaveSafeMessage((string) ($raw['code'] ?? $raw['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        return ['kind' => $kind, 'message' => $message, 'code' => $code];
    }

    private static function leaveSafeMessage(string $value): string
    {
        $code = strtoupper(trim($value));
        $messages = [
            'LEAVE_REQUEST_REASON_REQUIRED' => 'اكتب سبب الإجازة قبل الإرسال.',
            'LEAVE_REQUEST_WINDOW_INVALID' => 'يجب أن تكون نهاية الإجازة بعد بدايتها.',
            'LEAVE_REQUEST_FROM_INVALID' => 'اختر وقت بداية صحيحًا.',
            'LEAVE_REQUEST_TO_INVALID' => 'اختر وقت نهاية صحيحًا.',
            'LEAVE_REQUEST_TYPE_INVALID' => 'اختر نوع إجازة صحيحًا.',
            'LEAVE_REQUEST_ATTACHMENT_REQUIRED' => 'أرفق المستند المطلوب قبل الإرسال.',
            'LEAVE_REQUEST_MEDICAL_ATTACHMENT_REQUIRED' => 'أرفق التقرير الطبي المطلوب قبل الإرسال.',
            'LEAVE_REQUEST_STALE' => 'تغير الطلب أو أُجري عليه قرار آخر. حدّث الصفحة ثم أعد المحاولة.',
            'LEAVE_ATTACHMENT_STALE' => 'تغير الطلب قبل حفظ المرفق. حدّث الصفحة ثم أعد المحاولة.',
            'LEAVE_REQUEST_OWNER_ONLY' => 'لا تملك صلاحية تنفيذ هذه العملية على طلب الإجازة.',
            'LEAVE_REQUEST_NOT_DRAFT' => 'لا يمكن تنفيذ هذه العملية إلا على مسودة الإجازة الحالية.',
            'LEAVE_REQUEST_OVERLAP' => 'تتداخل فترة الإجازة مع طلب آخر قائم.',
            'LEAVE_REQUEST_BLACKOUT' => 'تتعارض فترة الإجازة مع فترة محظورة بحسب السياسة.',
            'LEAVE_BLACKOUT_OVERRIDE_REQUIRED' => 'تتطلب هذه الفترة موافقة استثنائية قبل الإرسال.',
            'LEAVE_STAFFING_OVERRIDE_REQUIRED' => 'تتطلب التغطية التشغيلية موافقة استثنائية قبل الإرسال.',
            'LEAVE_STAFFING_MINIMUM_BREACHED' => 'لا تسمح التغطية التشغيلية المتاحة بهذه الإجازة حاليًا.',
            'LEAVE_STAFFING_ABSENCE_LIMIT_BREACHED' => 'تجاوز الطلب الحد المسموح للغياب في المجموعة.',
            'LEAVE_ATTACHMENT_FILE_INVALID' => 'اختر ملفًا صالحًا لإرفاقه.',
            'LEAVE_ATTACHMENT_MIME_INVALID' => 'نوع الملف غير مسموح به للمرفق.',
            'LEAVE_ATTACHMENT_SIZE_INVALID' => 'حجم المرفق يتجاوز الحد المسموح.',
        ];
        if (isset($messages[$code])) {
            return $messages[$code];
        }
        if (preg_match('/SQLSTATE|PDO|DUPLICATE|STACK|TRACE|[A-Z]:\\\\|\.PHP:\d+/i', $value)) {
            return 'تعذر إتمام طلب الإجازة الآن. أعد المحاولة أو راجع شؤون العاملين إذا استمر الخطأ.';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length <= 500 && preg_match('/[\x{0600}-\x{06FF}]/u', $value)) {
            return trim($value);
        }

        return 'تعذر إتمام طلب الإجازة الآن. أعد المحاولة أو راجع شؤون العاملين إذا استمر الخطأ.';
    }

    private static function portalBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /** @param array<string,mixed> $request @param list<string> $modals */
    private static function leaveRequestActions(array $request, string $actionUrl, string $csrfToken, array &$modals): string
    {
        $buttons = [];
        $id = (int) $request['id'];
        $version = (int) $request['lock_version'];
        $actions = $request['actions'];

        if ($request['status'] === 'draft' && isset($actions['submit'])) {
            $token = trim((string) ($actions['submit']['idempotency_key'] ?? ''));
            if ($token !== '') {
                $buttons[] = '<form method="post" action="' . self::e($actionUrl) . '" class="d-inline" data-no-form-safety="true">'
                    . self::hidden('csrf_token', $csrfToken)
                    . self::hidden('leave_request_intent', 'submit')
                    . self::hidden('request_id', (string) $id)
                    . self::hidden('expected_lock_version', (string) $version)
                    . self::hidden('submission_idempotency_key', $token)
                    . '<button type="submit" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="إرسال للموافقة"><i class="fas fa-paper-plane"></i></button>'
                    . '</form>';
            }
        }

        if ($request['status'] === 'draft' && isset($actions['attach_medical'])) {
            $modalId = 'staffLeaveAttachmentModal-' . $id;
            $buttons[] = '<button type="button" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="modal" data-bs-target="#' . self::e($modalId) . '" title="إرفاق المستند"><i class="fas fa-paperclip"></i></button>';
            $modals[] = self::leaveAttachmentModal($modalId, $actionUrl, $csrfToken, $id, $version);
        }

        if ($request['status'] === 'draft' && isset($actions['withdraw'])) {
            $modalId = 'staffLeaveWithdrawModal-' . $id;
            $buttons[] = '<button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="modal" data-bs-target="#' . self::e($modalId) . '" title="سحب المسودة"><i class="fas fa-trash"></i></button>';
            $modals[] = self::leaveWithdrawModal($modalId, $actionUrl, $csrfToken, $id, $version);
        }

        if ($buttons === []) {
            return '<span class="text-secondary small">لا توجد عملية متاحة</span>';
        }

        return implode('', $buttons);
    }

    private static function leaveAttachmentModal(string $modalId, string $actionUrl, string $csrfToken, int $requestId, int $version): string
    {
        return '<div class="modal fade" id="' . self::e($modalId) . '" tabindex="-1" aria-hidden="true">'
            . '<div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium">'
            . '<form method="post" action="' . self::e($actionUrl) . '" enctype="multipart/form-data" data-no-form-safety="true">'
            . self::hidden('csrf_token', $csrfToken)
            . self::hidden('leave_request_intent', 'upload_medical_attachment')
            . self::hidden('request_id', (string) $requestId)
            . self::hidden('expected_lock_version', (string) $version)
            . '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-paperclip me-2"></i>إرفاق مستند الإجازة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
            . '<div class="modal-body"><div class="text-center mb-3"><i class="fas fa-file-medical text-primary" style="font-size: 2.5rem;"></i></div>'
            . '<p class="text-center small text-secondary">يُرفع الملف إلى التخزين الخاص الآمن ويُفحص على الخادم قبل ربطه بالمسودة.</p>'
            . '<label class="form-label" for="staff_leave_attachment_' . $requestId . '">الملف <span class="text-danger">*</span></label>'
            . '<input class="form-control" type="file" id="staff_leave_attachment_' . $requestId . '" name="file" accept=".pdf,image/jpeg,image/png" required>'
            . '<div class="form-text">الأنواع والحجم المقبولان يحددهما الخادم، وليس امتداد الملف وحده.</div></div>'
            . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>'
            . '<button type="submit" class="btn btn-success"><i class="fas fa-upload me-1"></i>رفع المرفق</button></div>'
            . '</form></div></div></div>';
    }

    private static function leaveWithdrawModal(string $modalId, string $actionUrl, string $csrfToken, int $requestId, int $version): string
    {
        return '<div class="modal fade" id="' . self::e($modalId) . '" tabindex="-1" aria-hidden="true">'
            . '<div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete">'
            . '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>سحب مسودة الإجازة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
            . '<div class="modal-body"><div class="text-center mb-3"><i class="fas fa-file-circle-xmark text-danger" style="font-size: 2.5rem;"></i></div><p class="text-center mb-0">هل تريد سحب هذه المسودة؟ لن يمكن إرسالها بعد السحب.</p></div>'
            . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>'
            . '<form method="post" action="' . self::e($actionUrl) . '" class="d-inline" data-no-form-safety="true">'
            . self::hidden('csrf_token', $csrfToken)
            . self::hidden('leave_request_intent', 'withdraw')
            . self::hidden('request_id', (string) $requestId)
            . self::hidden('expected_lock_version', (string) $version)
            . '<button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>سحب المسودة</button></form></div>'
            . '</div></div></div>';
    }

    private static function leaveKindLabel(string $kind): string
    {
        return [
            'leave' => 'إجازة جديدة',
            'extension' => 'تمديد إجازة',
            'early_return' => 'عودة مبكرة',
            'cancellation' => 'طلب إلغاء',
        ][$kind] ?? 'طلب إجازة';
    }

    private static function leaveAttachmentBadge(string $status): string
    {
        return [
            'required' => 'warning text-dark',
            'attached' => 'success',
            'missing' => 'danger',
            'not_required' => 'secondary',
        ][$status] ?? 'secondary';
    }

    private static function leaveAttachmentLabel(string $status): string
    {
        return [
            'required' => 'مرفق مطلوب',
            'attached' => 'مرفق محفوظ',
            'missing' => 'المرفق غير مكتمل',
            'not_required' => 'لا يلزم مرفق',
        ][$status] ?? 'حالة المرفق غير محددة';
    }

    private static function leaveJourneyLabel(string $status): string
    {
        return [
            'draft' => 'مسودة',
            'pending_approval' => 'بانتظار الموافقة',
            'approved' => 'معتمد',
            'rejected' => 'مرفوض',
            'withdrawn' => 'مسحوب',
            'cancelled' => 'ملغى',
            'cancellation_requested' => 'طلب الإلغاء بانتظار الموافقة',
            'return_recorded' => 'تم تسجيل العودة',
            'cancelled_due_to_service_end' => 'ألغي لانتهاء الخدمة',
            'superseded' => 'استُبدل بطلب أحدث',
        ][$status] ?? 'قيد المراجعة';
    }

    private static function leaveJourney(string $status): string
    {
        $label = self::leaveJourneyLabel($status);
        $badge = [
            'draft' => 'secondary',
            'pending_approval' => 'warning text-dark',
            'approved' => 'success',
            'rejected' => 'danger',
            'withdrawn' => 'secondary',
            'cancelled' => 'secondary',
            'cancellation_requested' => 'warning text-dark',
            'return_recorded' => 'success',
            'cancelled_due_to_service_end' => 'secondary',
            'superseded' => 'secondary',
        ][$status] ?? 'secondary';

        return '<span class="badge bg-' . self::e($badge) . '">' . self::e($label) . '</span>';
    }

    /** @param mixed $raw @return array<string,string> */
    private static function fieldErrors(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $allowed = ['permission_type_id', 'from_at', 'to_at', 'custom_label', 'reason', 'attachment_ref'];
        $errors = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            $message = self::safeMessage((string) $raw[$field]);
            if ($message !== '') {
                $errors[$field] = $message;
            }
        }

        return $errors;
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
        if ($message === '') {
            return null;
        }

        return ['kind' => $kind, 'message' => $message];
    }

    private static function safeMessage(string $value): string
    {
        $code = strtoupper(trim($value));
        $messages = [
            'PERMISSION_REQUEST_REASON_REQUIRED' => 'اكتب سبب الإذن قبل الإرسال.',
            'PERMISSION_REQUEST_CUSTOM_LABEL_REQUIRED' => 'اكتب مسمى الإذن الآخر قبل الإرسال.',
            'PERMISSION_REQUEST_ATTACHMENT_REQUIRED' => 'أضف المرفق المطلوب قبل الإرسال.',
            'PERMISSION_REQUEST_WINDOW_INVALID' => 'يجب أن تكون نهاية الإذن بعد بدايته.',
            'PERMISSION_REQUEST_FROM_INVALID' => 'اختر وقت بداية صحيحًا.',
            'PERMISSION_REQUEST_TO_INVALID' => 'اختر وقت نهاية صحيحًا.',
            'PERMISSION_REQUEST_MAX_DURATION_EXCEEDED' => 'مدة الإذن تتجاوز الحد المسموح لهذا النوع.',
            'PERMISSION_REQUEST_MIN_NOTICE_NOT_MET' => 'لا يحقق الطلب المهلة المطلوبة قبل موعد الإذن.',
            'PERMISSION_REQUEST_RETROACTIVE_NOT_ALLOWED' => 'لا تسمح السياسة الحالية بطلب إذن بأثر رجعي.',
            'PERMISSION_REQUEST_RETROACTIVE_LIMIT_EXCEEDED' => 'تجاوز الطلب نافذة الأثر الرجعي المسموح بها.',
            'PERMISSION_REQUEST_STALE' => 'تم تحديث الطلب من جلسة أخرى. حدّث الصفحة وراجع البيانات قبل المتابعة.',
            'PERMISSION_REQUEST_OWNER_ONLY' => 'لا تملك صلاحية الوصول إلى هذا الطلب.',
            'PERMISSION_REQUEST_FORBIDDEN' => 'لا تملك صلاحية تنفيذ هذه العملية.',
            'PERMISSION_REQUEST_NOT_DRAFT' => 'لم يعد الطلب مسودة قابلة للإرسال.',
            'PERMISSION_REQUEST_CANCELLATION_WORKFLOW_REQUIRED' => 'الطلب المعتمد يحتاج مسار إلغاء معتمد ولا يمكن إلغاؤه مباشرة.',
            'PERMISSION_REQUEST_OVERLAP' => 'تتداخل فترة الإذن مع طلب قائم أو تغطية أخرى.',
            'PERMISSION_QUOTA_EXCEEDED' => 'تجاوز الطلب الحصة الشهرية المسموح بها لهذا النوع.',
            'CSRF_INVALID' => 'انتهت صلاحية التحقق الأمني. حدّث الصفحة ثم أعد المحاولة.',
        ];
        if (isset($messages[$code])) {
            return $messages[$code];
        }
        if ($code === '') {
            return '';
        }
        if (preg_match('/SQLSTATE|PDO|DUPLICATE|STACK|TRACE|[A-Z]:\\\\|\.PHP:\d+/i', $value)) {
            return 'تعذر إتمام طلب الإذن الآن. أعد المحاولة أو راجع شؤون العاملين إذا استمر الخطأ.';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length <= 500 && preg_match('/[\x{0600}-\x{06FF}]/u', $value)) {
            return trim($value);
        }

        return 'تعذر إتمام طلب الإذن الآن. أعد المحاولة أو راجع شؤون العاملين إذا استمر الخطأ.';
    }

    /** @param array<string,string> $errors */
    private static function invalidClass(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' is-invalid' : '';
    }

    /** @param array<string,string> $errors */
    private static function fieldFeedback(array $errors, string $field): string
    {
        return isset($errors[$field]) ? '<div class="invalid-feedback">' . self::e($errors[$field]) . '</div>' : '';
    }

    /** @param array<string,?int> $quota */
    private static function quotaText(array $quota, string $prefix): string
    {
        $parts = [];
        if ($quota[$prefix . '_count'] !== null) {
            $parts[] = (string) $quota[$prefix . '_count'] . ' إذن';
        }
        if ($quota[$prefix . '_minutes'] !== null) {
            $parts[] = self::minutesText((int) $quota[$prefix . '_minutes']);
        }

        return $parts === [] ? 'غير محدد' : implode(' · ', $parts);
    }

    private static function minutesText(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) {
            return $minutes . ' دقيقة';
        }
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return $remaining === 0 ? $hours . ' ساعة' : $hours . ' ساعة و' . $remaining . ' دقيقة';
    }

    private static function displayDateTime(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '—';
        }

        return str_replace('T', ' ', $text);
    }

    private static function dateTimeLocal(mixed $value): string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $text) !== 1) {
            return '';
        }

        return str_replace(' ', 'T', substr($text, 0, 16));
    }

    private static function statusLabel(string $status): string
    {
        return [
            'draft' => 'مسودة',
            'pending_approval' => 'بانتظار الموافقة',
            'approved' => 'معتمد',
            'rejected' => 'مرفوض',
            'cancelled' => 'ملغى',
            'withdrawn' => 'مسحوب',
        ][$status] ?? 'قيد المراجعة';
    }

    private static function statusBadge(string $status): string
    {
        return [
            'draft' => 'secondary',
            'pending_approval' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'cancelled' => 'secondary',
            'withdrawn' => 'secondary',
        ][$status] ?? 'secondary';
    }

    /** @param mixed $raw @return list<array{id:int,name:string}> */
    private static function legacyStaffList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $staff = [];
        foreach ($raw as $item) {
            if (!is_array($item) || (int) ($item['id'] ?? 0) <= 0) {
                continue;
            }
            $staff[] = ['id' => (int) $item['id'], 'name' => (string) ($item['name'] ?? '')];
        }

        return $staff;
    }

    /** @param mixed $raw @return array<string,string> */
    private static function legacyLabels(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $labels = [];
        foreach ($raw as $key => $value) {
            if (!is_scalar($key) || !is_scalar($value)) {
                continue;
            }
            $labels[(string) $key] = (string) $value;
        }

        return $labels;
    }

    private static function legacyDate(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : date('Y-m-d');
    }

    private static function safeRelativeAction(string $action): string
    {
        $action = trim($action);
        if ($action === '') {
            return '';
        }
        if (str_contains($action, "\n") || str_contains($action, "\r") || str_starts_with($action, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $action) === 1) {
            throw new InvalidArgumentException('STAFF_PORTAL_ACTION_URL_INVALID');
        }

        return $action;
    }

    private static function timezone(string $timezone): string
    {
        return preg_match('/^[A-Za-z_]+\/[A-Za-z_]+$/', $timezone) === 1 ? $timezone : 'Africa/Cairo';
    }

    private static function requiredText(mixed $value, string $errorCode): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException($errorCode);
        }

        return $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(0, (int) $value) : null;
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
