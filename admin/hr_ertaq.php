<?php

declare(strict_types=1);

/**
 * منصة ارتق — صندوق التذاكر المعيّنة لمسؤول HR.
 *
 * This is deliberately a read-only adapter for the first rollout. Assignment,
 * classification, replies, urgent protection, attachments, and notification
 * commands remain with their separately authorized Staff application services.
 */
$page_title = 'منصة ارتق';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Infrastructure\StaffHrFeatureFlags;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;
use EduCore\Modules\Staff\Presentation\ErtaqPortal;

$allowedStatuses = [
    'new', 'triaged', 'assigned', 'in_progress', 'awaiting_requester',
    'resolved', 'closed', 'reopened', 'withdrawal_requested',
    'urgent_protected', 'cancelled',
];
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];
$rawStatus = trim((string) ($_GET['status'] ?? ''));
$rawPriority = trim((string) ($_GET['priority'] ?? ''));
$rawQuery = trim((string) ($_GET['q'] ?? ''));
$filters = [
    'status' => in_array($rawStatus, $allowedStatuses, true) ? $rawStatus : '',
    'priority' => in_array($rawPriority, $allowedPriorities, true) ? $rawPriority : '',
    'query' => $rawQuery,
    'limit' => 50,
];
$feedback = null;
$urgentFeedback = is_array($_SESSION['staff_hr_ertaq_urgent_feedback'] ?? null)
    ? $_SESSION['staff_hr_ertaq_urgent_feedback']
    : null;
unset($_SESSION['staff_hr_ertaq_urgent_feedback']);
if (($rawStatus !== '' && $filters['status'] === '')
    || ($rawPriority !== '' && $filters['priority'] === '')
    || strlen($rawQuery) > 160
    || preg_match('/[\x00-\x1F\x7F]/', $rawQuery) === 1) {
    $filters = ['status' => '', 'priority' => '', 'query' => '', 'limit' => 50];
    $feedback = ['kind' => 'warning', 'code' => 'ERTAQ_INBOX_FILTER_INVALID'];
}

$selectedTicketId = null;
$rawTicketId = trim((string) ($_GET['ticket_id'] ?? ''));
if ($rawTicketId !== '') {
    if (ctype_digit($rawTicketId) && (int) $rawTicketId > 0 && (string) (int) $rawTicketId === $rawTicketId) {
        $selectedTicketId = (int) $rawTicketId;
    } else {
        http_response_code(404);
        $feedback = ['kind' => 'warning', 'code' => 'ERTAQ_TICKET_NOT_FOUND'];
    }
}

$inbox = [
    'items' => [],
    'total' => 0,
    'summary' => ['total' => 0, 'overdue' => 0, 'urgent' => 0],
    'selected_ticket' => null,
    'messages' => [],
    'access' => 'none',
];
$featureFlags = StaffHrFeatureFlags::fromEnvironment();
$available = $featureFlags->exposesNewResults();
$urgentItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    try {
        $database = new Database();
        $db = $database->getConnection();
        $factory = new StaffModuleFactory($db, new AuditService($db));
        $intent = (string)($_POST['ertaq_urgent_intent'] ?? '');
        $actorId = (int)($_SESSION['user_id'] ?? 0);
        if ($intent === 'acknowledge') {
            $factory->ertaqUrgentRouting()->acknowledgeUrgentRoute([
                'actor_id' => $actorId,
                'urgent_event_id' => $_POST['urgent_event_id'] ?? null,
                'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
            ]);
            $_SESSION['staff_hr_ertaq_urgent_feedback'] = ['kind' => 'success', 'message' => 'تم إثبات استلام البلاغ العاجل بواسطة مسؤول حماية مؤهل.'];
        } elseif ($intent === 'manage_collective') {
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $conversation = $factory->ertaqCaseManagementConversation();
            if (!$db->inTransaction()) {
                $db->beginTransaction();
            }
            try {
                $conversation->addParty([
                    'actor_id' => $actorId,
                    'ticket_id' => $ticketId,
                    'external_party_label' => 'مجموعة عاملين متضررين - بيانات محجوبة',
                    'party_role' => 'affected',
                    'visibility_scope' => null,
                    'idempotency_key' => substr('q28-party:' . $ticketId, 0, 64),
                ]);
                $conversation->linkTicket([
                    'actor_id' => $actorId,
                    'ticket_id' => $ticketId,
                    'target_resource_type' => 'staff_ertaq_collective',
                    'target_resource_id' => $ticketId,
                    'link_type' => 'collective',
                    'link_reason' => 'ربط البلاغ كسياق جماعي دون نسخ محتواه',
                    'visibility_scope' => null,
                    'idempotency_key' => substr('q28-link:' . $ticketId, 0, 64),
                ]);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                throw $exception;
            }
            $_SESSION['staff_hr_ertaq_urgent_feedback'] = ['kind' => 'success', 'message' => 'تمت إضافة الطرف الجماعي وربط السياق دون نسخ محتوى البلاغ.'];
        } else {
            throw new DomainException('ERTAQ_URGENT_ACCESS_DENIED');
        }
    } catch (Throwable $exception) {
        error_log('Ertaq urgent acknowledgement failed: ' . $exception->getMessage());
        $_SESSION['staff_hr_ertaq_urgent_feedback'] = ['kind' => 'danger', 'message' => 'تعذر إثبات استلام البلاغ العاجل.'];
    }
    header('Location: hr_ertaq.php');
    exit;
}

if (!$available) {
    $feedback = $feedback ?? ['kind' => 'info', 'code' => 'ERTAQ_NOT_ENABLED'];
} elseif ($feedback === null) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        $factory = new StaffModuleFactory($db, new AuditService($db));
        $urgentItems = $factory->ertaqUrgentInbox()->forActor((int)($_SESSION['user_id'] ?? 0));
        $inbox = $factory->ertaqInboxQuery()->forAssignee(
            (int) ($_SESSION['user_id'] ?? 0),
            $filters,
            $selectedTicketId
        );
        if ($inbox['access'] === 'forbidden') {
            http_response_code(403);
            $feedback = ['kind' => 'danger', 'code' => 'ERTAQ_ACCESS_FORBIDDEN'];
        } elseif ($inbox['access'] === 'not_found') {
            http_response_code(404);
            $feedback = ['kind' => 'warning', 'code' => 'ERTAQ_TICKET_NOT_FOUND'];
        }
    } catch (Throwable $exception) {
        error_log('Ertaq assigned inbox unavailable: ' . $exception->getMessage());
        $inbox = [
            'items' => [],
            'total' => 0,
            'summary' => ['total' => 0, 'overdue' => 0, 'urgent' => 0],
            'selected_ticket' => null,
            'messages' => [],
            'access' => 'none',
        ];
        $feedback = ['kind' => 'danger', 'code' => 'ERTAQ_INBOX_UNAVAILABLE'];
    }
}

require_once '../includes/admin_header.php';

echo ErtaqPortal::renderAssignedInbox([
    'action_url' => 'hr_ertaq.php',
    'filters' => $filters,
    'items' => $inbox['items'],
    'total' => $inbox['total'],
    'summary' => $inbox['summary'],
    'selected_ticket' => $inbox['selected_ticket'],
    'messages' => $inbox['messages'],
    'access' => $inbox['access'],
    'feedback' => $feedback,
    'available' => $available,
]);

if ($available): ?>
    <section class="admin-list-surface mb-4" id="ertaqUrgentInbox">
        <div class="p-3 border-bottom"><h2 class="h5 mb-1"><i class="fas fa-shield-heart me-2 text-danger"></i>بلاغات الحماية العاجلة</h2><p class="small text-muted mb-0">لا يظهر موضوع البلاغ أو محتواه هنا؛ تظهر فقط بيانات التوجيه اللازمة لإثبات الاستلام.</p></div>
        <div class="p-3">
            <?php if ($urgentFeedback !== null): ?><div class="alert alert-<?php echo htmlspecialchars((string)($urgentFeedback['kind'] ?? 'danger'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($urgentFeedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <div class="admin-table-wrap"><table class="table table-hover table-striped admin-data-table mb-0"><thead><tr><th>رقم التذكرة</th><th>نوع الخطر</th><th>الحالة</th><th>وقت التوجيه</th><th>الإجراء</th></tr></thead><tbody>
            <?php if ($urgentItems === []): ?><tr><td colspan="5" class="text-center text-muted py-3">لا توجد بلاغات عاجلة موجهة إليك.</td></tr><?php endif; ?>
            <?php foreach ($urgentItems as $urgent): ?><tr data-ertaq-urgent-event-id="<?php echo (int)$urgent['urgent_event_id']; ?>" data-ertaq-ticket-id="<?php echo (int)$urgent['ticket_id']; ?>" data-ertaq-urgent-status="<?php echo htmlspecialchars((string)$urgent['status'], ENT_QUOTES, 'UTF-8'); ?>"><td><?php echo htmlspecialchars((string)$urgent['ticket_no'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$urgent['risk_type'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$urgent['status'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$urgent['routed_at'], ENT_QUOTES, 'UTF-8'); ?></td><td><form method="post" class="d-inline me-1"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="ertaq_urgent_intent" value="manage_collective"><input type="hidden" name="ticket_id" value="<?php echo (int)$urgent['ticket_id']; ?>"><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-people-group me-1"></i>ربط جماعي</button></form><?php if ((string)$urgent['status'] === 'routed'): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="ertaq_urgent_intent" value="acknowledge"><input type="hidden" name="urgent_event_id" value="<?php echo (int)$urgent['urgent_event_id']; ?>"><input type="hidden" name="expected_lock_version" value="<?php echo (int)$urgent['lock_version']; ?>"><button class="btn btn-success btn-sm" type="submit"><i class="fas fa-check me-1"></i>إثبات الاستلام</button></form><?php else: ?><span class="badge bg-success">تم الاستلام</span><?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
    </section>
<?php endif;

require_once '../includes/admin_footer.php';
