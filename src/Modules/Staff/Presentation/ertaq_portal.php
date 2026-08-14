<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Presentation;

use InvalidArgumentException;

/**
 * Shared, data-only Ertaq presentation.
 *
 * Role entrypoints own authentication, CSRF verification, request mapping,
 * service calls, PRG, and the scoped ErtaqInboxQuery result. This renderer
 * never accepts a mutable requester/assignee identifier and never emits an
 * attachment URL, filesystem path, party, route, or hidden-message field.
 */
final class ErtaqPortal
{
    /**
     * Worker-facing ticket list and requester-visible conversation. A future
     * adapter for each existing worker portal supplies the safe relative URL,
     * CSRF token, fresh idempotency keys, and the current worker-scoped query.
     *
     * @param array<string,mixed> $view
     */
    public static function renderWorkerConversation(array $view): string
    {
        $items = self::tickets($view['items'] ?? []);
        $selected = self::nullableTicket($view['selected_ticket'] ?? null, false);
        $messages = self::messages($view['messages'] ?? [], false);
        $feedback = self::feedback($view['feedback'] ?? null, (string) ($view['access'] ?? 'none'));
        $staffName = trim((string) ($view['staff_display_name'] ?? ''));
        $viewUrl = self::nullableAction($view['view_url'] ?? $view['action_url'] ?? null);
        $canCreate = (bool) ($view['can_create'] ?? false);
        $canReply = $selected !== null
            && self::allowsReply((string) $selected['status'])
            && (bool) ($view['can_reply'] ?? false);
        $command = ($canCreate || $canReply) ? self::commandContext($view, $canCreate, $canReply) : null;

        ob_start();
        ?>
        <section class="card shadow admin-card-surface mb-4" aria-labelledby="ertaqWorkerPortalTitle">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0" id="ertaqWorkerPortalTitle"><i class="fas fa-comments me-2"></i>ارتق</h5>
                    <small class="opacity-75">قناة موثقة للتواصل؛ لا تظهر رسائلك إلا ضمن مسار المعالجة المخوّل.</small>
                </div>
                <?php if ($staffName !== ''): ?>
                    <span class="badge bg-light text-dark"><i class="fas fa-user me-1"></i><?php echo self::e($staffName); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-4" role="note">
                    <i class="fas fa-shield-halved me-2"></i>لا تضع رابط ملف أو مسار جهازك داخل الرسالة. يحكم النظام السرية والأولوية والمسار عند الحفظ، وتصل التنبيهات بنص محايد.
                </div>
                <?php echo self::feedbackHtml($feedback); ?>

                <?php if ($canCreate && $command !== null): ?>
                    <form method="post" action="<?php echo self::e($command['action_url']); ?>" class="border rounded p-3 mb-4" id="ertaqCreateTicketForm" data-draft-scope="<?php echo self::e('staff:' . $command['draft_scope'] . ':ertaq-create'); ?>" novalidate>
                        <?php echo self::hidden('csrf_token', $command['csrf_token']); ?>
                        <?php echo self::hidden('ertaq_intent', 'create_ticket'); ?>
                        <?php echo self::hidden('create_idempotency_key', $command['create_idempotency_key']); ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h6 class="mb-1"><i class="fas fa-plus-circle text-success me-2"></i>فتح تذكرة جديدة</h6>
                                <p class="small text-secondary mb-0">اكتب ملخصًا واضحًا؛ ستتمكن من متابعة الحوار بعد إنشاء التذكرة.</p>
                            </div>
                            <span class="badge bg-primary-subtle text-primary-emphasis">هوية المرسل تُستمد من جلستك</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="ertaq_ticket_type">النوع <span class="text-danger">*</span></label>
                                <select class="form-select" name="type" id="ertaq_ticket_type" required>
                                    <option value="complaint">شكوى</option>
                                    <option value="suggestion">مقترح</option>
                                    <option value="inquiry">استفسار</option>
                                    <option value="other">أخرى</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="ertaq_ticket_confidentiality">مستوى السرية المطلوب</label>
                                <select class="form-select" name="confidentiality_level" id="ertaq_ticket_confidentiality">
                                    <option value="restricted" selected>سري</option>
                                    <option value="normal">عادي</option>
                                    <option value="highly_restricted">سري للغاية</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="ertaq_ticket_priority">الأولوية المطلوبة</label>
                                <select class="form-select" name="priority" id="ertaq_ticket_priority">
                                    <option value="normal" selected>عادية</option>
                                    <option value="high">مرتفعة</option>
                                    <option value="urgent">عاجلة</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="ertaq_ticket_subject">الموضوع أو الملخص <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="subject" id="ertaq_ticket_subject" rows="3" maxlength="500" required placeholder="اكتب ملخصًا موجزًا يساعد فريق المعالجة على التوجيه الصحيح"></textarea>
                                <div class="form-text">يعيد الخادم تقييم التصنيف والسرية والأولوية وفق السياسة؛ لا يحدد المتصفح فريق المعالجة أو مستوى الخطر.</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check border border-danger-subtle rounded p-3 ps-5 bg-danger-subtle">
                                    <input class="form-check-input" type="checkbox" name="immediate_risk" value="1" id="ertaq_immediate_risk">
                                    <label class="form-check-label fw-semibold text-danger-emphasis" for="ertaq_immediate_risk">يوجد خطر فوري يستلزم توجيهًا عاجلًا لفريق الحماية</label>
                                    <div class="small text-danger-emphasis mt-1">عند التحديد يفرض الخادم السرية القصوى والأولوية العاجلة، ويختار فريق الحماية تلقائيًا بعد استبعاد الأطراف والمديرين المتعارضين.</div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-success"><i class="fas fa-paper-plane me-1"></i>فتح التذكرة</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-lg-5">
                        <section class="admin-list-surface h-100" aria-labelledby="ertaqWorkerTicketsTitle">
                            <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-2">
                                <div>
                                    <h6 class="mb-1" id="ertaqWorkerTicketsTitle"><i class="fas fa-ticket text-primary me-2"></i>تذاكري</h6>
                                    <p class="small text-secondary mb-0">لا تظهر هنا إلا التذاكر الخاصة بك.</p>
                                </div>
                                <span class="badge bg-primary rounded-pill"><?php echo count($items); ?></span>
                            </div>
                            <?php if ($items === []): ?>
                                <div class="alert alert-light m-3 mb-0"><i class="fas fa-circle-info me-2"></i>لا توجد تذاكر ظاهرة لك حاليًا.</div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($items as $ticket): ?>
                                        <?php $ticketHref = $viewUrl === null ? null : self::ticketHref($viewUrl, (int) $ticket['id'], 'ertaq_ticket_id'); ?>
                                        <div class="list-group-item" data-ertaq-ticket-id="<?php echo (int)$ticket['id']; ?>" data-ertaq-lock-version="<?php echo (int)$ticket['lock_version']; ?>">
                                            <div class="d-flex justify-content-between align-items-start gap-2">
                                                <div class="min-w-0">
                                                    <div class="fw-semibold text-break"><?php echo self::e($ticket['subject']); ?></div>
                                                    <div class="small text-secondary mt-1"><i class="fas fa-hashtag me-1"></i><?php echo self::e($ticket['ticket_no']); ?> · <?php echo self::e(self::typeLabel($ticket['type'])); ?></div>
                                                </div>
                                                <span class="badge bg-<?php echo self::e(self::statusBadge($ticket['status'])); ?> text-nowrap"><?php echo self::e(self::statusLabel($ticket['status'])); ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mt-2 gap-2">
                                                <span class="small text-secondary"><i class="fas fa-clock me-1"></i><?php echo self::e($ticket['updated_at']); ?></span>
                                                <?php if ($ticketHref !== null): ?>
                                                    <a href="<?php echo self::e($ticketHref); ?>" class="btn btn-action-pills btn-edit" data-bs-toggle="tooltip" title="فتح المحادثة" aria-label="فتح المحادثة"><i class="fas fa-arrow-left"></i></a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>
                    <div class="col-lg-7">
                        <?php echo self::workerConversationHtml($selected, $messages, $command, $canReply); ?>
                    </div>
                </div>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Admin assigned-inbox surface. The HTTP page supplies only the current
     * admin's directly assigned view from ErtaqInboxQuery; this component does
     * not provide a broad search, assignment, classification, or state write.
     *
     * @param array<string,mixed> $view
     */
    public static function renderAssignedInbox(array $view): string
    {
        $actionUrl = self::requiredAction($view['action_url'] ?? null);
        $filters = self::filters($view['filters'] ?? []);
        $items = self::tickets($view['items'] ?? [], true);
        $total = self::nonNegativeInt($view['total'] ?? count($items), 'ERTAQ_INBOX_TOTAL_INVALID');
        $summary = self::summary($view['summary'] ?? []);
        $selected = self::nullableTicket($view['selected_ticket'] ?? null, true);
        $messages = self::messages($view['messages'] ?? [], true);
        $feedback = self::feedback($view['feedback'] ?? null, (string) ($view['access'] ?? 'none'));
        $available = (bool) ($view['available'] ?? true);

        ob_start();
        ?>
        <div class="admin-page-heading">
            <div>
                <h1 class="h2"><i class="fas fa-headset me-2 text-primary"></i>منصة ارتق</h1>
                <p class="text-muted mb-0">صندوقك المعيّن فقط؛ لا تكشف القائمة أو العدادات تذاكر خارج نطاقك.</p>
            </div>
            <div class="admin-top-actions no-print">
                <a href="hr_center.php" class="btn btn-outline-secondary shadow-sm px-3 py-2"><i class="fas fa-arrow-right me-1"></i>مركز شؤون العاملين</a>
            </div>
        </div>

        <?php if ($available): ?>
            <div class="row row-cols-1 row-cols-sm-3 g-3 mb-4">
                <div class="col">
                    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                        <div class="stat-card-icon"><i class="fas fa-inbox"></i></div>
                        <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $summary['total']; ?>">0</div><div class="stat-card-label">مسند إليك</div></div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                        <div class="stat-card-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $summary['overdue']; ?>">0</div><div class="stat-card-label">متأخر عن المهلة</div></div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                        <div class="stat-card-icon"><i class="fas fa-triangle-exclamation"></i></div>
                        <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $summary['urgent']; ?>">0</div><div class="stat-card-label">أولوية عاجلة</div></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php echo self::feedbackHtml($feedback); ?>

        <?php if ($available): ?>
            <form method="get" action="<?php echo self::e($actionUrl); ?>" class="admin-filter-bar mb-3" id="ertaqAssignedFilterForm">
                <div class="admin-filter-controls">
                    <select class="form-select form-select-sm admin-inline-select-sm" name="status" aria-label="فلترة الحالة">
                        <option value="">كل الحالات</option>
                        <?php foreach (self::statuses() as $status): ?>
                            <option value="<?php echo self::e($status); ?>"<?php echo $filters['status'] === $status ? ' selected' : ''; ?>><?php echo self::e(self::statusLabel($status)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm admin-inline-select-sm" name="priority" aria-label="فلترة الأولوية">
                        <option value="">كل الأولويات</option>
                        <?php foreach (['low', 'normal', 'high', 'urgent'] as $priority): ?>
                            <option value="<?php echo self::e($priority); ?>"<?php echo $filters['priority'] === $priority ? ' selected' : ''; ?>><?php echo self::e(self::priorityLabel($priority)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="search" class="form-control form-control-sm admin-inline-select-sm" name="q" maxlength="160" value="<?php echo self::e($filters['query']); ?>" placeholder="رقم التذكرة أو الموضوع" aria-label="بحث في صندوقي المعيّن">
                </div>
                <div class="admin-filter-actions">
                    <a href="<?php echo self::e($actionUrl); ?>" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
                </div>
            </form>

            <section class="admin-list-surface mb-4" aria-labelledby="ertaqAssignedInboxTitle">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1" id="ertaqAssignedInboxTitle"><i class="fas fa-inbox text-primary me-2"></i>التذاكر المعيّنة لي</h5>
                        <p class="small text-secondary mb-0">يفتح الرابط سجلًا داخل نطاق إسنادك الحالي فقط.</p>
                    </div>
                    <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo $total; ?> ظاهرة</span>
                </div>
                <?php if ($items === []): ?>
                    <div class="alert alert-success m-3 mb-0" role="status"><i class="fas fa-check-circle me-2"></i>لا توجد تذاكر مطابقة مسندة إليك حاليًا.</div>
                <?php else: ?>
                    <div class="table-responsive admin-table-wrap">
                        <table class="table table-hover table-striped admin-data-table mb-0" id="ertaqAssignedInboxTable">
                            <thead><tr><th>التذكرة</th><th>السرية</th><th>الحالة</th><th>المهلة</th><th>الإسناد</th><th class="text-center">إجراءات</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $ticket): ?>
                                    <tr data-ertaq-ticket-id="<?php echo (int) $ticket['id']; ?>">
                                        <td><div class="fw-semibold text-break"><?php echo self::e($ticket['subject']); ?></div><div class="small text-secondary"><?php echo self::e($ticket['ticket_no']); ?> · <?php echo self::e(self::typeLabel($ticket['type'])); ?> · <?php echo self::e($ticket['classification']); ?></div></td>
                                        <td><span class="badge bg-<?php echo self::e(self::confidentialityBadge($ticket['confidentiality_level'])); ?>"><?php echo self::e(self::confidentialityLabel($ticket['confidentiality_level'])); ?></span></td>
                                        <td><span class="badge bg-<?php echo self::e(self::statusBadge($ticket['status'])); ?>"><?php echo self::e(self::statusLabel($ticket['status'])); ?></span><div class="small text-secondary mt-1"><?php echo self::e(self::priorityLabel($ticket['priority'])); ?></div></td>
                                        <td><?php echo self::dueHtml($ticket); ?></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary-emphasis"><i class="fas fa-user-check me-1"></i><?php echo self::e($ticket['assignment_status'] === 'accepted' ? 'تم الاستلام' : 'مسند إليك'); ?></span><div class="small text-secondary mt-1"><?php echo self::e($ticket['assigned_at']); ?></div></td>
                                        <td class="text-center admin-table-actions"><a href="<?php echo self::e(self::ticketHref($actionUrl, (int) $ticket['id'], 'ticket_id')); ?>" class="btn btn-action-pills btn-edit" data-bs-toggle="tooltip" title="فتح التذكرة" aria-label="فتح التذكرة"><i class="fas fa-eye"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <?php echo self::assignedConversationHtml($selected, $messages); ?>
        <?php endif; ?>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string,mixed>|null $selected @param list<array<string,mixed>> $messages @param array<string,string>|null $command */
    private static function workerConversationHtml(?array $selected, array $messages, ?array $command, bool $canReply): string
    {
        if ($selected === null) {
            return '<section class="border rounded p-4 h-100 text-center text-secondary"><i class="fas fa-comments fa-2x text-primary mb-3"></i><h6>اختر تذكرة لمتابعة الحوار</h6><p class="small mb-0">يعرض النظام رسائلك والردود المخصصة لك فقط.</p></section>';
        }

        ob_start();
        ?>
        <section class="border rounded h-100" aria-labelledby="ertaqWorkerConversationTitle">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-start gap-2">
                <div><h6 class="mb-1" id="ertaqWorkerConversationTitle"><?php echo self::e($selected['subject']); ?></h6><div class="small text-secondary"><?php echo self::e($selected['ticket_no']); ?> · <?php echo self::e(self::statusLabel($selected['status'])); ?></div></div>
                <span class="badge bg-<?php echo self::e(self::confidentialityBadge($selected['confidentiality_level'])); ?>"><?php echo self::e(self::confidentialityLabel($selected['confidentiality_level'])); ?></span>
            </div>
            <div class="p-3">
                <?php echo self::messagesHtml($messages, false); ?>
                <?php if ($canReply && $command !== null): ?>
                    <form method="post" action="<?php echo self::e($command['action_url']); ?>" class="border-top pt-3 mt-3" id="ertaqWorkerReplyForm" data-draft-scope="<?php echo self::e('staff:' . $command['draft_scope'] . ':ertaq-reply:' . $selected['id']); ?>" novalidate>
                        <?php echo self::hidden('csrf_token', $command['csrf_token']); ?>
                        <?php echo self::hidden('ertaq_intent', 'post_message'); ?>
                        <?php echo self::hidden('ticket_id', (string) $selected['id']); ?>
                        <?php echo self::hidden('message_type', 'requester_message'); ?>
                        <?php echo self::hidden('idempotency_key', $command['reply_idempotency_key']); ?>
                        <label class="form-label" for="ertaq_reply_body">إضافة رسالة</label>
                        <textarea class="form-control" id="ertaq_reply_body" name="body" rows="4" maxlength="50000" required placeholder="اكتب التفاصيل أو الرد المطلوب. لا تحدد من يرى الرسالة؛ الخادم يحدد نطاق الرؤية."></textarea>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2"><small class="text-secondary"><i class="fas fa-lock me-1"></i>لا تتوفر المرفقات هنا قبل ربط بوابة رفع خاصة ومصرح بها.</small><button type="submit" class="btn btn-primary"><i class="fas fa-reply me-1"></i>إرسال الرسالة</button></div>
                    </form>
                    <?php if (self::allowsWithdrawal((string)$selected['status'])): ?>
                        <form method="post" action="<?php echo self::e($command['action_url']); ?>" class="border-top pt-3 mt-3" id="ertaqWithdrawalRequestForm">
                            <?php echo self::hidden('csrf_token', $command['csrf_token']); ?>
                            <?php echo self::hidden('ertaq_intent', 'request_withdrawal'); ?>
                            <?php echo self::hidden('ticket_id', (string)$selected['id']); ?>
                            <?php echo self::hidden('expected_lock_version', (string)$selected['lock_version']); ?>
                            <?php echo self::hidden('idempotency_key', substr('withdraw:' . $command['reply_idempotency_key'], 0, 64)); ?>
                            <label class="form-label" for="ertaq_withdrawal_reason">طلب سحب التذكرة مع الاحتفاظ بالسجل</label>
                            <div class="d-flex gap-2"><input class="form-control" id="ertaq_withdrawal_reason" name="withdrawal_reason" maxlength="4000" required><button class="btn btn-primary text-nowrap" type="submit"><i class="fas fa-hand me-1"></i>طلب السحب</button></div>
                        </form>
                    <?php endif; ?>
                <?php elseif (!self::allowsReply((string) $selected['status'])): ?>
                    <div class="alert alert-secondary mb-0 mt-3"><i class="fas fa-circle-info me-2"></i>هذه التذكرة مغلقة ولا تقبل رسالة جديدة حاليًا.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string,mixed>|null $selected @param list<array<string,mixed>> $messages */
    private static function assignedConversationHtml(?array $selected, array $messages): string
    {
        if ($selected === null) {
            return '';
        }

        ob_start();
        ?>
        <section class="card shadow admin-card-surface mb-4" aria-labelledby="ertaqAssignedConversationTitle">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div><h5 class="mb-0" id="ertaqAssignedConversationTitle"><i class="fas fa-comments me-2"></i><?php echo self::e($selected['ticket_no']); ?></h5><small class="opacity-75">عرض الرسائل المتاحة لإسنادك الحالي فقط.</small></div>
                <span class="badge bg-light text-dark"><?php echo self::e(self::statusLabel($selected['status'])); ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3"><div class="col-md-8"><h6 class="mb-1"><?php echo self::e($selected['subject']); ?></h6><div class="small text-secondary">التصنيف: <?php echo self::e($selected['classification']); ?> · الأولوية: <?php echo self::e(self::priorityLabel($selected['priority'])); ?></div></div><div class="col-md-4 text-md-start"><span class="badge bg-<?php echo self::e(self::confidentialityBadge($selected['confidentiality_level'])); ?>"><?php echo self::e(self::confidentialityLabel($selected['confidentiality_level'])); ?></span></div></div>
                <?php echo self::messagesHtml($messages, true); ?>
                <div class="alert alert-light border mb-0 mt-3"><i class="fas fa-shield-halved text-primary me-2"></i>تظهر فقط الرسائل ذات نطاق «المرسل» أو «فريق المعالجة». الملاحظات المقيدة ومسار الحماية والمرفقات الخاصة لا تعرضها هذه الواجهة.</div>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param list<array<string,mixed>> $messages */
    private static function messagesHtml(array $messages, bool $assignedView): string
    {
        if ($messages === []) {
            return '<div class="alert alert-light border mb-0"><i class="fas fa-comment-slash me-2"></i>لا توجد رسائل متاحة ضمن نطاق العرض الحالي.</div>';
        }
        ob_start();
        ?>
        <div class="vstack gap-3" aria-live="polite">
            <?php foreach ($messages as $message): ?>
                <article class="border rounded p-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"><span class="fw-semibold"><i class="fas fa-comment-dots text-primary me-2"></i><?php echo self::e(self::messageLabel($message['message_type'], $assignedView)); ?></span><span class="small text-secondary"><i class="fas fa-clock me-1"></i><?php echo self::e($message['sent_at']); ?></span></div>
                    <div class="text-break" style="white-space: pre-wrap;"><?php echo self::e($message['body']); ?></div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $ticket */
    private static function dueHtml(array $ticket): string
    {
        $due = $ticket['sla_due_at'];
        if ($due === null) {
            return '<span class="small text-secondary">بلا موعد معلن</span>';
        }
        $badge = $ticket['status'] === 'closed' || $ticket['status'] === 'cancelled' ? 'secondary' : 'info';
        return '<div>' . self::e($due) . '</div><span class="badge bg-' . self::e($badge) . '">مهلة الخدمة</span>';
    }

    /** @param mixed $raw @return list<array<string,mixed>> */
    private static function tickets(mixed $raw, bool $assigned = false): array
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('ERTAQ_PORTAL_ITEMS_INVALID');
        }
        $items = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('ERTAQ_PORTAL_ITEM_INVALID');
            }
            $ticket = self::ticket($row, $assigned);
            $items[] = $ticket;
        }

        return $items;
    }

    /** @param mixed $raw @return array<string,mixed>|null */
    private static function nullableTicket(mixed $raw, bool $assigned): ?array
    {
        return $raw === null ? null : self::ticket(is_array($raw) ? $raw : [], $assigned);
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private static function ticket(array $raw, bool $assigned): array
    {
        $ticket = [
            'id' => self::positiveInt($raw['id'] ?? null, 'ERTAQ_PORTAL_ITEM_INVALID'),
            'ticket_no' => self::requiredText($raw['ticket_no'] ?? null, 'ERTAQ_PORTAL_ITEM_INVALID', 80),
            'type' => self::enum($raw['type'] ?? null, ['complaint', 'suggestion', 'inquiry', 'other'], 'ERTAQ_PORTAL_ITEM_INVALID'),
            'subject' => self::requiredText($raw['subject'] ?? null, 'ERTAQ_PORTAL_ITEM_INVALID', 500),
            'confidentiality_level' => self::enum($raw['confidentiality_level'] ?? null, ['normal', 'restricted', 'highly_restricted'], 'ERTAQ_PORTAL_ITEM_INVALID'),
            'status' => self::enum($raw['status'] ?? null, self::statuses(), 'ERTAQ_PORTAL_ITEM_INVALID'),
            'lock_version' => self::positiveInt($raw['lock_version'] ?? 1, 'ERTAQ_PORTAL_ITEM_INVALID'),
            'created_at' => self::dateText($raw['created_at'] ?? null, 'ERTAQ_PORTAL_ITEM_INVALID'),
            'updated_at' => self::dateText($raw['updated_at'] ?? null, 'ERTAQ_PORTAL_ITEM_INVALID'),
        ];
        if (!$assigned) {
            return $ticket;
        }

        return $ticket + [
            'classification' => self::requiredText($raw['classification'] ?? null, 'ERTAQ_PORTAL_ITEM_INVALID', 100),
            'priority' => self::enum($raw['priority'] ?? null, ['low', 'normal', 'high', 'urgent'], 'ERTAQ_PORTAL_ITEM_INVALID'),
            'first_response_due_at' => self::nullableDateText($raw['first_response_due_at'] ?? null, 'ERTAQ_PORTAL_ITEM_INVALID'),
            'sla_due_at' => self::nullableDateText($raw['sla_due_at'] ?? null, 'ERTAQ_PORTAL_ITEM_INVALID'),
            'assignment_status' => self::enum($raw['assignment_status'] ?? null, ['active', 'accepted'], 'ERTAQ_PORTAL_ITEM_INVALID'),
            'assigned_at' => self::dateText($raw['assigned_at'] ?? null, 'ERTAQ_PORTAL_ITEM_INVALID'),
        ];
    }

    /** @param mixed $raw @return list<array<string,mixed>> */
    private static function messages(mixed $raw, bool $assignedView): array
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('ERTAQ_PORTAL_MESSAGES_INVALID');
        }
        $messages = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('ERTAQ_PORTAL_MESSAGE_INVALID');
            }
            $messageType = self::enum($row['message_type'] ?? null, $assignedView
                ? ['requester_message', 'team_reply', 'internal_note', 'system_event', 'withdrawal_request', 'status_update']
                : ['requester_message', 'team_reply', 'system_event', 'withdrawal_request', 'status_update'], 'ERTAQ_PORTAL_MESSAGE_INVALID');
            $messages[] = [
                'id' => self::positiveInt($row['id'] ?? null, 'ERTAQ_PORTAL_MESSAGE_INVALID'),
                'message_type' => $messageType,
                'body' => self::requiredText($row['body'] ?? null, 'ERTAQ_PORTAL_MESSAGE_INVALID', 50000),
                'sent_at' => self::dateText($row['sent_at'] ?? null, 'ERTAQ_PORTAL_MESSAGE_INVALID'),
            ];
        }

        return $messages;
    }

    /** @param mixed $raw @return array{status:string,priority:string,query:string} */
    private static function filters(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('ERTAQ_PORTAL_FILTERS_INVALID');
        }
        $status = trim((string) ($raw['status'] ?? ''));
        $priority = trim((string) ($raw['priority'] ?? ''));
        $query = trim((string) ($raw['query'] ?? ''));
        if (($status !== '' && !in_array($status, self::statuses(), true))
            || ($priority !== '' && !in_array($priority, ['low', 'normal', 'high', 'urgent'], true))
            || strlen($query) > 160) {
            throw new InvalidArgumentException('ERTAQ_PORTAL_FILTERS_INVALID');
        }

        return ['status' => $status, 'priority' => $priority, 'query' => $query];
    }

    /** @param mixed $raw @return array{total:int,overdue:int,urgent:int} */
    private static function summary(mixed $raw): array
    {
        if ($raw === []) {
            return ['total' => 0, 'overdue' => 0, 'urgent' => 0];
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException('ERTAQ_PORTAL_SUMMARY_INVALID');
        }

        return [
            'total' => self::nonNegativeInt($raw['total'] ?? 0, 'ERTAQ_PORTAL_SUMMARY_INVALID'),
            'overdue' => self::nonNegativeInt($raw['overdue'] ?? 0, 'ERTAQ_PORTAL_SUMMARY_INVALID'),
            'urgent' => self::nonNegativeInt($raw['urgent'] ?? 0, 'ERTAQ_PORTAL_SUMMARY_INVALID'),
        ];
    }

    /** @param array<string,mixed> $view @return array<string,string> */
    private static function commandContext(array $view, bool $requiresCreateKey, bool $requiresReplyKey): array
    {
        return [
            'action_url' => self::requiredAction($view['action_url'] ?? null),
            'csrf_token' => self::requiredText($view['csrf_token'] ?? null, 'ERTAQ_PORTAL_CSRF_REQUIRED', 512),
            'draft_scope' => self::requiredText($view['draft_scope'] ?? null, 'ERTAQ_PORTAL_DRAFT_SCOPE_REQUIRED', 160),
            'create_idempotency_key' => $requiresCreateKey
                ? self::requiredText($view['create_idempotency_key'] ?? null, 'ERTAQ_PORTAL_CREATE_KEY_REQUIRED', 64)
                : '',
            'reply_idempotency_key' => $requiresReplyKey
                ? self::requiredText($view['reply_idempotency_key'] ?? null, 'ERTAQ_PORTAL_REPLY_KEY_REQUIRED', 64)
                : '',
        ];
    }

    /** @return array{kind:string,message:string}|null */
    private static function feedback(mixed $raw, string $access): ?array
    {
        if ($access === 'forbidden') {
            return ['kind' => 'danger', 'message' => 'لا تملك صلاحية فتح هذه التذكرة ضمن نطاقك الحالي.'];
        }
        if ($access === 'not_found') {
            return ['kind' => 'warning', 'message' => 'لم يتم العثور على التذكرة المطلوبة.'];
        }
        if (!is_array($raw)) {
            return null;
        }
        $kind = (string) ($raw['kind'] ?? 'danger');
        if (!in_array($kind, ['success', 'info', 'warning', 'danger'], true)) {
            $kind = 'danger';
        }
        $code = strtoupper(trim((string) ($raw['code'] ?? '')));
        $messages = [
            'ERTAQ_NOT_ENABLED' => 'منصة ارتق غير مفعلة للعرض بعد. طبّق الترحيلات ثم فعّل مرحلة العرض المراجعة.',
            'ERTAQ_INBOX_UNAVAILABLE' => 'لا تتوفر بيانات ارتق الآن. تحقق من تفعيل الميزة والترحيلات ثم أعد المحاولة.',
            'ERTAQ_INBOX_FILTER_INVALID' => 'تحقق من قيم البحث والفلترة ثم أعد المحاولة.',
            'ERTAQ_ACCESS_FORBIDDEN' => 'لا تملك صلاحية فتح هذه التذكرة ضمن نطاقك الحالي.',
            'ERTAQ_TICKET_NOT_FOUND' => 'لم يتم العثور على التذكرة المطلوبة.',
            'ERTAQ_CREATE_SUCCESS' => 'تم فتح التذكرة بنجاح. يمكنك متابعة الحوار من القائمة.',
            'ERTAQ_URGENT_SUCCESS' => 'تم تسجيل البلاغ العاجل وتوجيهه تلقائيًا إلى فريق الحماية المؤهل دون كشف محتواه في التنبيهات.',
            'ERTAQ_WITHDRAWAL_SUCCESS' => 'تم تسجيل طلب السحب دون حذف التذكرة أو الرسائل أو أدلة التوجيه.',
            'ERTAQ_MESSAGE_SUCCESS' => 'تم إرسال رسالتك بنجاح.',
            'CSRF_INVALID' => 'انتهت صلاحية التحقق الأمني. حدّث الصفحة ثم أعد المحاولة.',
        ];
        if (!isset($messages[$code])) {
            return null;
        }

        return ['kind' => $kind, 'message' => $messages[$code]];
    }

    /** @param array{kind:string,message:string}|null $feedback */
    private static function feedbackHtml(?array $feedback): string
    {
        if ($feedback === null) {
            return '';
        }

        return '<div class="alert alert-' . self::e($feedback['kind']) . ' alert-dismissible fade show mb-4" role="alert">'
            . '<i class="fas fa-' . ($feedback['kind'] === 'success' ? 'check-circle' : 'circle-exclamation') . ' me-2"></i>'
            . self::e($feedback['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>';
    }

    private static function ticketHref(string $baseUrl, int $ticketId, string $field): string
    {
        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . rawurlencode($field) . '=' . $ticketId;
    }

    private static function allowsReply(string $status): bool
    {
        return !in_array($status, ['closed', 'cancelled'], true);
    }

    private static function allowsWithdrawal(string $status): bool
    {
        return in_array($status, ['triaged', 'assigned', 'in_progress', 'awaiting_requester', 'resolved', 'urgent_protected'], true);
    }

    private static function typeLabel(string $type): string
    {
        return ['complaint' => 'شكوى', 'suggestion' => 'مقترح', 'inquiry' => 'استفسار', 'other' => 'أخرى'][$type] ?? 'تذكرة';
    }

    private static function confidentialityLabel(string $level): string
    {
        return ['normal' => 'عادي', 'restricted' => 'سري', 'highly_restricted' => 'سري للغاية'][$level] ?? 'مقيد';
    }

    private static function confidentialityBadge(string $level): string
    {
        return ['normal' => 'secondary', 'restricted' => 'primary', 'highly_restricted' => 'danger'][$level] ?? 'secondary';
    }

    private static function priorityLabel(string $priority): string
    {
        return ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'مرتفعة', 'urgent' => 'عاجلة'][$priority] ?? 'غير محددة';
    }

    private static function statusLabel(string $status): string
    {
        return [
            'new' => 'جديدة', 'triaged' => 'قيد الفرز', 'assigned' => 'مسندة',
            'in_progress' => 'قيد المعالجة', 'awaiting_requester' => 'بانتظار المرسل',
            'resolved' => 'تمت المعالجة', 'closed' => 'مغلقة', 'reopened' => 'أعيد فتحها',
            'withdrawal_requested' => 'طلب سحب', 'urgent_protected' => 'مسار حماية',
            'cancelled' => 'ملغاة',
        ][$status] ?? 'غير محددة';
    }

    private static function statusBadge(string $status): string
    {
        return [
            'new' => 'secondary', 'triaged' => 'info', 'assigned' => 'primary',
            'in_progress' => 'primary', 'awaiting_requester' => 'warning text-dark',
            'resolved' => 'success', 'closed' => 'secondary', 'reopened' => 'warning text-dark',
            'withdrawal_requested' => 'warning text-dark', 'urgent_protected' => 'danger',
            'cancelled' => 'secondary',
        ][$status] ?? 'secondary';
    }

    private static function messageLabel(string $type, bool $assignedView): string
    {
        if ($assignedView) {
            return [
                'requester_message' => 'رسالة العامل', 'team_reply' => 'رد فريق المعالجة',
                'internal_note' => 'ملاحظة فريق المعالجة', 'system_event' => 'تحديث النظام',
                'withdrawal_request' => 'طلب سحب', 'status_update' => 'تحديث حالة',
            ][$type] ?? 'رسالة';
        }

        return [
            'requester_message' => 'رسالتك', 'team_reply' => 'رد فريق المتابعة',
            'system_event' => 'تحديث النظام', 'withdrawal_request' => 'طلب سحب',
            'status_update' => 'تحديث حالة',
        ][$type] ?? 'رسالة';
    }

    /** @return list<string> */
    private static function statuses(): array
    {
        return ['new', 'triaged', 'assigned', 'in_progress', 'awaiting_requester', 'resolved', 'closed', 'reopened', 'withdrawal_requested', 'urgent_protected', 'cancelled'];
    }

    private static function requiredAction(mixed $value): string
    {
        $action = self::nullableAction($value);
        if ($action === null) {
            throw new InvalidArgumentException('ERTAQ_PORTAL_ACTION_REQUIRED');
        }

        return $action;
    }

    private static function nullableAction(mixed $value): ?string
    {
        $action = trim((string) $value);
        if ($action === '') {
            return null;
        }
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|\\\\|/)~i', $action) === 1
            || str_contains($action, '\\')
            || str_contains($action, '..')
            || preg_match('/[\x00-\x1F\x7F]/', $action) === 1) {
            throw new InvalidArgumentException('ERTAQ_PORTAL_ACTION_INVALID');
        }

        return $action;
    }

    private static function positiveInt(mixed $value, string $error): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $id = (int) $value;
        if ($id <= 0 || (string) $id !== trim((string) $value)) {
            throw new InvalidArgumentException($error);
        }

        return $id;
    }

    private static function nonNegativeInt(mixed $value, string $error): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $number = (int) $value;
        if ($number < 0 || (string) $number !== trim((string) $value)) {
            throw new InvalidArgumentException($error);
        }

        return $number;
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed, string $error): string
    {
        $text = trim((string) $value);
        if (!in_array($text, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private static function requiredText(mixed $value, string $error, int $max): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > $max) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private static function dateText(mixed $value, string $error): string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $text) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return str_replace('T', ' ', substr($text, 0, 16));
    }

    private static function nullableDateText(mixed $value, string $error): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : self::dateText($text, $error);
    }

    private static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
