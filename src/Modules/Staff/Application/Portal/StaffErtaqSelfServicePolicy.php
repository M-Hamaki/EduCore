<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Portal;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\ErtaqConversationAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqSlaAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqTicketAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqTicketPolicyResolver;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityQuery;

/** Fail-closed worker policy for opening and replying to their own Ertaq tickets. */
final class StaffErtaqSelfServicePolicy implements
    ErtaqTicketAuthorization,
    ErtaqTicketPolicyResolver,
    ErtaqConversationAuthorization,
    ErtaqSlaAuthorization
{
    public function __construct(private StaffPortalEligibilityQuery $eligibility)
    {
    }

    public function assertCanAct(int $actorId, string $action, ?array $ticket, DateTimeImmutable $atInstant): void
    {
        $this->assertEligible($actorId, $atInstant);
        if ($ticket === null) {
            if ($action !== 'create_ticket') {
                throw new DomainException('ERTAQ_ACCESS_DENIED');
            }
            return;
        }
        if ((int) ($ticket['requester_user_id'] ?? 0) !== $actorId
            || !in_array($action, ['post_message', 'request_withdrawal'], true)) {
            throw new DomainException('ERTAQ_ACCESS_DENIED');
        }
    }

    public function assertCanAssign(
        int $actorId,
        array $ticket,
        ?int $assignedTeamId,
        ?int $assignedToUserId,
        DateTimeImmutable $atInstant
    ): void {
        throw new DomainException('ERTAQ_ACCESS_DENIED');
    }

    public function resolveForCreate(int $requesterUserId, array $requested, DateTimeImmutable $atInstant): array
    {
        $this->assertEligible($requesterUserId, $atInstant);
        $type = (string) ($requested['type'] ?? 'other');
        $confidentiality = $this->requestedEnum(
            $requested['requested_confidentiality_level'] ?? 'restricted',
            ['normal', 'restricted', 'highly_restricted']
        );
        $priority = $this->requestedEnum(
            $requested['requested_priority'] ?? 'normal',
            ['normal', 'high', 'urgent']
        );
        $riskLevel = $this->requestedEnum(
            $requested['requested_risk_level'] ?? 'none',
            ['none', 'immediate']
        );
        if ($riskLevel === 'immediate') {
            $priority = 'urgent';
            $confidentiality = 'highly_restricted';
        } elseif ($priority === 'urgent') {
            // Self-service may request high priority, but an urgent protection
            // route is server-owned and requires a configured protection team.
            $priority = 'high';
        }

        return [
            'classification' => $type,
            'confidentiality_level' => $confidentiality,
            'priority' => $priority,
            'risk_level' => $riskLevel,
            'sla_policy_id' => null,
            'sla_policy_snapshot' => null,
            'first_response_due_at' => null,
            'sla_due_at' => null,
        ];
    }

    public function resolveForClassification(
        int $actorId,
        array $ticket,
        array $requested,
        DateTimeImmutable $atInstant
    ): array {
        throw new DomainException('ERTAQ_ACCESS_DENIED');
    }

    public function resolveMessageVisibility(
        int $actorId,
        array $ticket,
        string $messageType,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string {
        $this->assertCanAct($actorId, 'post_message', $ticket, $atInstant);
        if ($messageType !== 'requester_message') {
            throw new DomainException('ERTAQ_MESSAGE_TYPE_FORBIDDEN');
        }

        return 'requester';
    }

    public function resolvePartyVisibility(
        int $actorId,
        array $ticket,
        array $party,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string {
        throw new DomainException('ERTAQ_ACCESS_DENIED');
    }

    /**
     * SLA scheduling for a newly-created ticket does not call this method.
     * Queue processing and escalation routing are administrative background
     * operations and must never be authorized through worker self-service.
     */
    public function resolveEscalation(
        int $actorId,
        array $ticket,
        array $slaEvent,
        DateTimeImmutable $atInstant
    ): array {
        throw new DomainException('ERTAQ_ACCESS_DENIED');
    }

    public function resolveLinkVisibility(
        int $actorId,
        array $ticket,
        array $link,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string {
        throw new DomainException('ERTAQ_ACCESS_DENIED');
    }

    private function assertEligible(int $actorId, DateTimeImmutable $atInstant): void
    {
        if ($actorId <= 0) {
            throw new DomainException('ERTAQ_ACCESS_DENIED');
        }
        $result = $this->eligibility->forUser($actorId, $atInstant);
        if (($result['eligible'] ?? false) !== true
            || (int) ($result['staff_id'] ?? 0) !== $actorId
            || !in_array('staff.portal.self_service', (array) ($result['capabilities'] ?? []), true)) {
            throw new DomainException('ERTAQ_ACCESS_DENIED');
        }
    }

    /** @param list<string> $allowed */
    private function requestedEnum(mixed $value, array $allowed): string
    {
        $normalized = trim((string) $value);
        if (!in_array($normalized, $allowed, true)) {
            throw new DomainException('ERTAQ_ACCESS_DENIED');
        }

        return $normalized;
    }
}
