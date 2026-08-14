<?php

declare(strict_types=1);

use EduCore\Modules\Staff\Application\Ertaq\ErtaqInboxQuery;
use EduCore\Modules\Staff\Contracts\ErtaqInboxReadRepository;
use EduCore\Modules\Staff\Presentation\ErtaqPortal;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/bootstrap.php';

final class ErtaqInboxUiMemoryRepository implements ErtaqInboxReadRepository
{
    /** @var array<int,array<string,mixed>> */
    private array $tickets;

    public function __construct()
    {
        $this->tickets = [
            41 => [
                'id' => 41,
                'ticket_no' => 'ERT-0041',
                'requester_user_id' => 501,
                'type' => 'complaint',
                'subject' => '<script>hidden()</script> شكوى آمنة',
                'classification' => 'general',
                'confidentiality_level' => 'restricted',
                'priority' => 'high',
                'status' => 'in_progress',
                'first_response_due_at' => '2026-08-10 09:00:00',
                'sla_due_at' => '2026-08-11 09:00:00',
                'created_at' => '2026-08-09 09:00:00',
                'updated_at' => '2026-08-09 10:00:00',
                'assignment_status' => 'active',
                'assigned_at' => '2026-08-09 10:00:00',
                'assigned_to_user_id' => 701,
            ],
            42 => [
                'id' => 42,
                'ticket_no' => 'ERT-0042',
                'requester_user_id' => 502,
                'type' => 'complaint',
                'subject' => 'تذكرة مقيدة خارج النطاق',
                'classification' => 'sensitive',
                'confidentiality_level' => 'highly_restricted',
                'priority' => 'urgent',
                'status' => 'urgent_protected',
                'first_response_due_at' => null,
                'sla_due_at' => null,
                'created_at' => '2026-08-09 09:00:00',
                'updated_at' => '2026-08-09 10:00:00',
                'assignment_status' => 'active',
                'assigned_at' => '2026-08-09 10:00:00',
                'assigned_to_user_id' => 702,
            ],
        ];
    }

    public function requesterTickets(int $requesterUserId, array $filters): array
    {
        return array_values(array_filter($this->tickets, static fn (array $ticket): bool => $ticket['requester_user_id'] === $requesterUserId));
    }

    public function requesterTicketCount(int $requesterUserId, array $filters): int
    {
        return count($this->requesterTickets($requesterUserId, $filters));
    }

    public function requesterTicket(int $requesterUserId, int $ticketId): ?array
    {
        $ticket = $this->tickets[$ticketId] ?? null;
        return is_array($ticket) && $ticket['requester_user_id'] === $requesterUserId ? $ticket : null;
    }

    public function requesterMessages(int $requesterUserId, int $ticketId): array
    {
        return $this->requesterTicket($requesterUserId, $ticketId) === null ? [] : [[
            'id' => 1001,
            'message_type' => 'team_reply',
            'body' => '<b>رد مسموح</b>',
            'sent_at' => '2026-08-09 11:00:00',
        ]];
    }

    public function assignedTickets(int $assigneeUserId, array $filters): array
    {
        return array_values(array_filter($this->tickets, static fn (array $ticket): bool => $ticket['assigned_to_user_id'] === $assigneeUserId));
    }

    public function assignedTicketCount(int $assigneeUserId, array $filters): int
    {
        return count($this->assignedTickets($assigneeUserId, $filters));
    }

    public function assignedSummary(int $assigneeUserId): array
    {
        $items = $this->assignedTickets($assigneeUserId, []);
        return ['total' => count($items), 'overdue' => 0, 'urgent' => 0];
    }

    public function assignedTicket(int $assigneeUserId, int $ticketId): ?array
    {
        $ticket = $this->tickets[$ticketId] ?? null;
        return is_array($ticket) && $ticket['assigned_to_user_id'] === $assigneeUserId ? $ticket : null;
    }

    public function assignedMessages(int $assigneeUserId, int $ticketId): array
    {
        return $this->assignedTicket($assigneeUserId, $ticketId) === null ? [] : [[
            'id' => 1002,
            'message_type' => 'internal_note',
            'body' => 'ملاحظة فريق المعالجة',
            'sent_at' => '2026-08-09 11:30:00',
        ]];
    }

    public function ticketExists(int $ticketId): bool
    {
        return isset($this->tickets[$ticketId]);
    }
}

$failures = [];
$assert = static function (string $name, bool $passed) use (&$failures): void {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
};
$throws = static function (callable $callback): bool {
    try {
        $callback();
    } catch (Throwable) {
        return true;
    }

    return false;
};

$query = new ErtaqInboxQuery(new ErtaqInboxUiMemoryRepository());
$worker = $query->forRequester(501, ['status' => '', 'query' => ''], 41);
$workerForbidden = $query->forRequester(501, [], 42);
$workerMissing = $query->forRequester(501, [], 999);
$admin = $query->forAssignee(701, ['priority' => '', 'query' => ''], 41);
$adminForbidden = $query->forAssignee(701, [], 42);

$assert(
    'worker_and_direct_assignee_queries_are_scoped_before_rendering',
    $worker['access'] === 'granted'
        && count($worker['items']) === 1
        && $admin['access'] === 'granted'
        && count($admin['items']) === 1
        && $admin['summary']['total'] === 1
        && $workerForbidden['access'] === 'forbidden'
        && $workerForbidden['selected_ticket'] === null
        && $workerForbidden['messages'] === []
        && $adminForbidden['access'] === 'forbidden'
        && $adminForbidden['selected_ticket'] === null
        && $workerMissing['access'] === 'not_found'
);

$workerHtml = ErtaqPortal::renderWorkerConversation([
    'action_url' => 'teacher/portal.php?tab=ertaq',
    'view_url' => 'teacher/portal.php?tab=ertaq',
    'csrf_token' => str_repeat('a', 64),
    'draft_scope' => 'teacher-501',
    'create_idempotency_key' => 'ertaq-create-ui-contract',
    'reply_idempotency_key' => 'ertaq-reply-ui-contract',
    'can_create' => true,
    'can_reply' => true,
    'items' => $worker['items'],
    'selected_ticket' => $worker['selected_ticket'],
    'messages' => $worker['messages'],
    'access' => $worker['access'],
]);
$assert(
    'worker_portal_escapes_content_uses_session_scoped_forms_and_never_emits_mutable_worker_id',
    str_contains($workerHtml, 'id="ertaqCreateTicketForm"')
        && str_contains($workerHtml, 'id="ertaqWorkerReplyForm"')
        && str_contains($workerHtml, 'name="csrf_token"')
        && str_contains($workerHtml, 'name="create_idempotency_key"')
        && str_contains($workerHtml, 'name="ticket_id"')
        && !str_contains($workerHtml, 'name="requester_user_id"')
        && !str_contains($workerHtml, '<script>hidden()</script>')
        && str_contains($workerHtml, '&lt;script&gt;hidden()&lt;/script&gt;')
        && !str_contains($workerHtml, '<b>رد مسموح</b>')
        && str_contains($workerHtml, '&lt;b&gt;رد مسموح&lt;/b&gt;')
        && !str_contains($workerHtml, 'type="file"')
        && !str_contains($workerHtml, 'storage_ref')
        && !str_contains($workerHtml, 'attachment_path')
);

$assert(
    'worker_portal_fails_closed_if_an_internal_note_is_accidentally_passed_to_it',
    $throws(static fn (): string => ErtaqPortal::renderWorkerConversation([
        'items' => $worker['items'],
        'selected_ticket' => $worker['selected_ticket'],
        'messages' => [[
            'id' => 1099,
            'message_type' => 'internal_note',
            'body' => 'لا يجب عرضه للعامل',
            'sent_at' => '2026-08-09 12:00:00',
        ]],
    ]))
);

$adminHtml = ErtaqPortal::renderAssignedInbox([
    'action_url' => 'hr_ertaq.php',
    'filters' => ['status' => '', 'priority' => '', 'query' => ''],
    'items' => $admin['items'],
    'total' => $admin['total'],
    'summary' => $admin['summary'],
    'selected_ticket' => $admin['selected_ticket'],
    'messages' => $admin['messages'],
    'access' => $admin['access'],
    'available' => true,
]);
$unavailableHtml = ErtaqPortal::renderAssignedInbox([
    'action_url' => 'hr_ertaq.php',
    'filters' => ['status' => '', 'priority' => '', 'query' => ''],
    'items' => [],
    'total' => 0,
    'summary' => ['total' => 0, 'overdue' => 0, 'urgent' => 0],
    'selected_ticket' => null,
    'messages' => [],
    'feedback' => ['kind' => 'info', 'code' => 'ERTAQ_NOT_ENABLED'],
    'available' => false,
]);
$assert(
    'assigned_inbox_uses_rtl_admin_primitives_neutral_errors_and_no_broad_attachment_surface',
    str_contains($adminHtml, 'admin-filter-bar')
        && str_contains($adminHtml, 'admin-list-surface')
        && str_contains($adminHtml, 'admin-data-table')
        && str_contains($adminHtml, 'btn-action-pills btn-edit')
        && str_contains($adminHtml, 'data-ertaq-ticket-id="41"')
        && !str_contains($adminHtml, 'تذكرة مقيدة خارج النطاق')
        && !str_contains($adminHtml, 'type="file"')
        && !str_contains($adminHtml, 'storage_ref')
        && str_contains($unavailableHtml, 'منصة ارتق غير مفعلة للعرض بعد')
        && $throws(static fn (): string => ErtaqPortal::renderAssignedInbox([
            'action_url' => 'https://outside.example/ertaq',
            'filters' => ['status' => '', 'priority' => '', 'query' => ''],
            'items' => [],
            'total' => 0,
            'summary' => ['total' => 0, 'overdue' => 0, 'urgent' => 0],
            'selected_ticket' => null,
            'messages' => [],
        ]))
);

$pageSource = (string) file_get_contents(dirname(__DIR__) . '/admin/hr_ertaq.php');
$repositorySource = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/PdoErtaqInboxReadRepository.php');
$portalSource = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Staff/Presentation/ertaq_portal.php');
$headerSource = (string) file_get_contents(dirname(__DIR__) . '/includes/admin_header.php');
$authPosition = strpos($pageSource, "Utilities::validateSession('admin');");
$requestPosition = strpos($pageSource, '$_GET');
$assert(
    'admin_route_checks_auth_before_request_data_and_returns_explicit_idor_statuses',
    $authPosition !== false
        && $requestPosition !== false
        && $authPosition < $requestPosition
        && str_contains($pageSource, 'StaffHrFeatureFlags::fromEnvironment()')
        && str_contains($pageSource, 'ertaqInboxQuery()->forAssignee')
        && str_contains($pageSource, 'http_response_code(403)')
        && str_contains($pageSource, 'http_response_code(404)')
        && !str_contains($pageSource, 'staff_ertaq_tickets')
        && !str_contains($pageSource, 'confirm(')
        && !str_contains($pageSource, 'Swal')
        && str_contains($headerSource, 'dir="rtl"')
        && str_contains($headerSource, 'bootstrap.rtl')
);
$assert(
    'read_repository_has_scoped_predicates_and_avoids_nonassigned_sensitive_resources',
    str_contains($repositorySource, 't.requester_user_id = :requester_user_id')
        && str_contains($repositorySource, 'assigned_to_user_id = :assignee_user_id')
        && str_contains($repositorySource, "m.visibility = 'requester'")
        && str_contains($repositorySource, "m.visibility IN ('requester', 'assigned_team')")
        && !str_contains($repositorySource, 'SELECT *')
        && !str_contains($repositorySource, 'staff_resource_attachments')
        && !str_contains($repositorySource, 'staff_ertaq_parties')
        && !str_contains($repositorySource, 'staff_ertaq_urgent_events')
        && !str_contains($repositorySource, 'FROM users')
        && str_contains($portalSource, 'internal_note')
        && str_contains($portalSource, 'ERTAQ_PORTAL_MESSAGE_INVALID')
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Staff-HR Ertaq UI contract failure(s).\n");
    exit(1);
}

echo "Staff-HR Ertaq UI contract tests passed.\n";
