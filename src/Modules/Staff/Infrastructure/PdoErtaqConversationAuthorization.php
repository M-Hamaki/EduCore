<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\ErtaqConversationAuthorization;
use PDO;

/** Administrative Ertaq conversation boundary; visibility is always server-owned. */
final class PdoErtaqConversationAuthorization implements ErtaqConversationAuthorization
{
    public function __construct(private PDO $db) {}

    public function assertCanAct(int $actorId, string $action, ?array $ticket, DateTimeImmutable $atInstant): void
    {
        if ($ticket === null || !in_array($action, ['add_party', 'link_ticket', 'post_message', 'decide_withdrawal'], true) || !$this->isAdmin($actorId)) {
            throw new DomainException('ERTAQ_ACCESS_DENIED');
        }
    }

    public function resolveMessageVisibility(int $actorId, array $ticket, string $messageType, ?string $requestedVisibility, DateTimeImmutable $atInstant): string
    {
        $this->assertCanAct($actorId, 'post_message', $ticket, $atInstant);
        return $messageType === 'team_reply' ? 'requester' : 'protection_team';
    }

    public function resolvePartyVisibility(int $actorId, array $ticket, array $party, ?string $requestedVisibility, DateTimeImmutable $atInstant): string
    {
        $this->assertCanAct($actorId, 'add_party', $ticket, $atInstant);
        return 'protection_team';
    }

    public function resolveLinkVisibility(int $actorId, array $ticket, array $link, ?string $requestedVisibility, DateTimeImmutable $atInstant): string
    {
        $this->assertCanAct($actorId, 'link_ticket', $ticket, $atInstant);
        return 'protection_team';
    }

    private function isAdmin(int $actorId): bool
    {
        $statement = $this->db->prepare("SELECT COUNT(*) FROM user_role_assignments WHERE user_id = ? AND status = 'active' AND role_key IN ('admin','super_admin')");
        $statement->execute([$actorId]);
        return (int)$statement->fetchColumn() > 0;
    }
}
