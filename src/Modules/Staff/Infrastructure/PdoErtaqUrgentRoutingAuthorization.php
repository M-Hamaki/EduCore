<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\ErtaqUrgentRoutingAuthorization;
use PDO;

/** Server-owned protection routing: callers can signal risk but never choose the team or recipients. */
final class PdoErtaqUrgentRoutingAuthorization implements ErtaqUrgentRoutingAuthorization
{
    public function __construct(private PDO $db)
    {
    }

    public function assertCanAct(int $actorId, string $action, ?array $ticket, DateTimeImmutable $atInstant): void
    {
        if ($ticket === null || $action !== 'route_urgent_ticket') {
            throw new DomainException('ERTAQ_URGENT_ACCESS_DENIED');
        }
        if ((int)($ticket['requester_user_id'] ?? 0) === $actorId
            && (string)($ticket['risk_level'] ?? '') === 'immediate') {
            return;
        }
        if (!$this->isAdmin($actorId)) {
            throw new DomainException('ERTAQ_URGENT_ACCESS_DENIED');
        }
    }

    public function resolveProtectionRoute(int $actorId, array $ticket, string $riskType, array $excludedUserIds, DateTimeImmutable $atInstant): array
    {
        if ($riskType !== 'immediate_protection') {
            throw new DomainException('ERTAQ_URGENT_ROUTE_FORBIDDEN');
        }
        $excludedUserIds = array_values(array_unique(array_map('intval', $excludedUserIds)));
        $params = [];
        $exclusion = '';
        if ($excludedUserIds !== []) {
            $exclusion = ' AND u.id NOT IN (' . implode(',', array_fill(0, count($excludedUserIds), '?')) . ')';
            $params = $excludedUserIds;
        }
        $statement = $this->db->prepare(
            "SELECT a.org_unit_id, u.id
             FROM users u
             JOIN user_role_assignments ura ON ura.user_id = u.id AND ura.status = 'active'
                 AND ura.role_key IN ('admin', 'super_admin')
             JOIN staff_assignments a ON a.staff_user_id = u.id
                 AND a.assignment_kind = 'primary' AND a.employment_status = 'active'
                 AND a.valid_from <= CURRENT_DATE AND (a.valid_to IS NULL OR a.valid_to >= CURRENT_DATE)
             WHERE u.status = 'active'" . $exclusion . "
             ORDER BY a.org_unit_id, u.id"
        );
        $statement->execute($params);
        $byTeam = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byTeam[(int)$row['org_unit_id']][] = (int)$row['id'];
        }
        if ($byTeam === []) {
            throw new DomainException('ERTAQ_URGENT_TEAM_UNAVAILABLE');
        }
        $teamId = (int)array_key_first($byTeam);
        $eligible = array_values(array_unique($byTeam[$teamId]));
        return [
            'routed_team_id' => $teamId,
            'eligible_user_ids' => $eligible,
            'route_snapshot' => [
                'policy' => 'staff_admin_team_without_conflicts',
                'team_id' => $teamId,
                'eligible_user_ids' => $eligible,
                'resolved_at' => $atInstant->format(DATE_ATOM),
            ],
        ];
    }

    public function assertCanAcknowledge(int $actorId, array $ticket, array $urgentEvent, DateTimeImmutable $atInstant): void
    {
        $snapshot = $urgentEvent['route_snapshot'] ?? [];
        $eligible = is_array($snapshot) ? array_map('intval', (array)($snapshot['eligible_user_ids'] ?? [])) : [];
        if (!in_array($actorId, $eligible, true) || !$this->isAdmin($actorId)) {
            throw new DomainException('ERTAQ_URGENT_ACKNOWLEDGEMENT_FORBIDDEN');
        }
    }

    private function isAdmin(int $actorId): bool
    {
        $statement = $this->db->prepare("SELECT COUNT(*) FROM user_role_assignments WHERE user_id = ? AND status = 'active' AND role_key IN ('admin','super_admin')");
        $statement->execute([$actorId]);
        return (int)$statement->fetchColumn() > 0;
    }
}
