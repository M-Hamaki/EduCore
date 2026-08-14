<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Approval;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\ApprovalDelegationRevalidationQuery;
use PDO;

/** Rechecks the exact delegation that was frozen into the approval assignment. */
final class PdoApprovalDelegationRevalidationQuery implements ApprovalDelegationRevalidationQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function isStillActive(
        int $delegationId,
        int $delegatorUserId,
        int $delegateUserId,
        DateTimeImmutable $atInstant
    ): bool {
        $timestamp = $atInstant->format('Y-m-d H:i:s.u');
        $statement = $this->db->prepare(
            "SELECT id
             FROM staff_delegations
             WHERE id = :id
               AND delegator_user_id = :delegator_user_id
               AND delegate_user_id = :delegate_user_id
               AND status = 'active'
               AND valid_from <= :effective_at
               AND valid_to > :effective_at_again
             LIMIT 1"
        );
        $statement->execute([
            ':id' => $delegationId,
            ':delegator_user_id' => $delegatorUserId,
            ':delegate_user_id' => $delegateUserId,
            ':effective_at' => $timestamp,
            ':effective_at_again' => $timestamp,
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
