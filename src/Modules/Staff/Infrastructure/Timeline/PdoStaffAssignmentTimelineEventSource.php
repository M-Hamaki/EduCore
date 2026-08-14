<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Staff\Contracts\StaffTimelineEventSource;
use InvalidArgumentException;
use PDO;

/**
 * Summary-only timeline stream for effective primary-assignment history.
 *
 * It deliberately exposes identifiers/statuses rather than organization or
 * job-title labels, which remain owned by their own dated read projections.
 */
final class PdoStaffAssignmentTimelineEventSource implements StaffTimelineEventSource
{
    private const SOURCE_KEY = 'assignments';

    public function __construct(private PDO $db)
    {
    }

    public function sourceKey(): string
    {
        return self::SOURCE_KEY;
    }

    public function eventsForStaff(
        int $staffUserId,
        DateTimeImmutable $fromInclusive,
        DateTimeImmutable $toExclusive,
        int $limit
    ): array {
        if ($staffUserId <= 0 || $limit <= 0) {
            throw new InvalidArgumentException('A staff assignment timeline source requires positive staff and limit values.');
        }

        $statement = $this->db->prepare(
            "SELECT id, employment_status, valid_from, version
             FROM staff_assignments
             WHERE staff_user_id = :staff_user_id
               AND assignment_kind = 'primary'
               AND valid_from >= :from_inclusive
               AND valid_from < :to_exclusive
             ORDER BY valid_from DESC, id DESC
             LIMIT :limit"
        );
        $statement->bindValue(':staff_user_id', $staffUserId, PDO::PARAM_INT);
        $statement->bindValue(':from_inclusive', $fromInclusive->format('Y-m-d'));
        $statement->bindValue(':to_exclusive', $toExclusive->format('Y-m-d'));
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $timezone = new DateTimeZone('Africa/Cairo');
        $events = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $assignmentId = (int) $assignment['id'];
            $version = (int) $assignment['version'];
            $events[] = [
                'event_id' => 'assignment-' . $assignmentId . '-v' . $version,
                'occurred_at' => new DateTimeImmutable((string) $assignment['valid_from'] . ' 00:00:00', $timezone),
                'event_type' => 'staff.assignment.effective',
                'resource_type' => 'staff_assignment',
                'resource_id' => $assignmentId,
                'status' => strtolower((string) $assignment['employment_status']),
                'version' => $version,
            ];
        }

        return $events;
    }
}
