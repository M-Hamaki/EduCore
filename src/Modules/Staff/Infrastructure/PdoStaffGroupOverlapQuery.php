<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\StaffGroupOverlapQuery;
use PDO;
use InvalidArgumentException;

final class PdoStaffGroupOverlapQuery implements StaffGroupOverlapQuery
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function groupsShareActiveMember(
        int $leftGroupId,
        int $rightGroupId,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): bool {
        if ($leftGroupId <= 0 || $rightGroupId <= 0 || $to <= $from) {
            throw new InvalidArgumentException('STAFF_GROUP_OVERLAP_RANGE_INVALID');
        }
        if ($leftGroupId === $rightGroupId) {
            return true;
        }
        $statement = $this->db->prepare(
            'SELECT 1 FROM staff_policy_group_memberships left_member
             JOIN staff_policy_group_memberships right_member
               ON right_member.staff_user_id = left_member.staff_user_id
             WHERE left_member.group_id = ? AND right_member.group_id = ?
               AND left_member.status = \'active\' AND right_member.status = \'active\'
               AND left_member.valid_from <= ? AND right_member.valid_from <= ?
               AND (left_member.valid_to IS NULL OR left_member.valid_to >= ?)
               AND (right_member.valid_to IS NULL OR right_member.valid_to >= ?)
             LIMIT 1'
        );
        $statement->execute([
            $leftGroupId,
            $rightGroupId,
            $to->modify('-1 microsecond')->format('Y-m-d'),
            $to->modify('-1 microsecond')->format('Y-m-d'),
            $from->format('Y-m-d'),
            $from->format('Y-m-d'),
        ]);

        return (bool) $statement->fetchColumn();
    }
}
