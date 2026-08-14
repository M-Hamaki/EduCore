<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Approval;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\ApprovalActorEligibilityQuery;
use PDO;

/**
 * Reads the minimum live evidence needed to keep a frozen approval assignment
 * safe at decision time. It deliberately does not re-resolve the workflow:
 * the recorded assignment remains the workflow evidence, while account and
 * service eligibility must still be current.
 */
final class PdoApprovalActorEligibilityQuery implements ApprovalActorEligibilityQuery
{
    /** @var list<string> */
    private const MANAGER_RELATIONSHIPS = [
        'direct_manager',
        'administrative_manager',
        'delegated_direct_manager',
        'delegated_administrative_manager',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function currentEligibility(
        int $userId,
        string $relationshipKind,
        DateTimeImmutable $atInstant
    ): array {
        $account = $this->db->prepare(
            'SELECT id, role, status
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $account->execute([':id' => $userId]);
        $row = $account->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (string) ($row['status'] ?? '') !== 'active') {
            return [
                'allowed' => false,
                'reason' => 'session_inactive',
                'can_manage_approvals' => false,
            ];
        }

        $canManageApprovals = in_array(
            strtolower((string) ($row['role'] ?? '')),
            ['admin', 'super_admin'],
            true
        );
        if (!in_array($relationshipKind, self::MANAGER_RELATIONSHIPS, true)) {
            return [
                'allowed' => true,
                'reason' => 'allowed',
                'can_manage_approvals' => $canManageApprovals,
            ];
        }

        $assignment = $this->db->prepare(
            "SELECT id
             FROM staff_assignments
             WHERE staff_user_id = :staff_user_id
               AND assignment_kind = 'primary'
               AND valid_from <= :at_date
               AND (valid_to IS NULL OR valid_to >= :at_date_again)
             ORDER BY valid_from DESC, id DESC
             LIMIT 1"
        );
        $assignment->execute([
            ':staff_user_id' => $userId,
            ':at_date' => $atInstant->format('Y-m-d'),
            ':at_date_again' => $atInstant->format('Y-m-d'),
        ]);
        if ($assignment->fetch(PDO::FETCH_ASSOC) === false) {
            return [
                'allowed' => false,
                'reason' => 'service_ended',
                'can_manage_approvals' => $canManageApprovals,
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'allowed',
            'can_manage_approvals' => $canManageApprovals,
        ];
    }
}
