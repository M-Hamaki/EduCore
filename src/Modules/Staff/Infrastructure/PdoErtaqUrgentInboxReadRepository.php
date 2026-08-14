<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\ErtaqUrgentInboxReadRepository;
use PDO;

final class PdoErtaqUrgentInboxReadRepository implements ErtaqUrgentInboxReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function forActor(int $actorId): array
    {
        $statement = $this->db->prepare(
            "SELECT e.id AS urgent_event_id, e.ticket_id, t.ticket_no, e.risk_type, e.status, e.lock_version, e.routed_at, e.acknowledged_at
             FROM staff_ertaq_urgent_events e
             JOIN staff_ertaq_tickets t ON t.id = e.ticket_id
             WHERE JSON_CONTAINS(e.route_snapshot, ?, '$.eligible_user_ids')
             ORDER BY e.id DESC LIMIT 100"
        );
        $statement->execute([json_encode($actorId, JSON_THROW_ON_ERROR)]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
