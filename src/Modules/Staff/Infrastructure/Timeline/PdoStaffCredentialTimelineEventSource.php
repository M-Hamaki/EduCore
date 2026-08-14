<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Staff\Contracts\StaffTimelineEventSource;
use InvalidArgumentException;
use PDO;

/** Summary-only timeline stream for immutable Staff credential records. */
final class PdoStaffCredentialTimelineEventSource implements StaffTimelineEventSource
{
    private const SOURCE_KEY = 'credentials';

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
            throw new InvalidArgumentException('A staff credential timeline source requires positive staff and limit values.');
        }

        $statement = $this->db->prepare(
            "SELECT id, credential_kind, effective_on, verification_status, lifecycle_status, version
             FROM staff_credential_records
             WHERE staff_user_id = :staff_user_id
               AND effective_on >= :from_inclusive
               AND effective_on < :to_exclusive
             ORDER BY effective_on DESC, id DESC
             LIMIT :limit"
        );
        $statement->bindValue(':staff_user_id', $staffUserId, PDO::PARAM_INT);
        $statement->bindValue(':from_inclusive', $fromInclusive->format('Y-m-d'));
        $statement->bindValue(':to_exclusive', $toExclusive->format('Y-m-d'));
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $timezone = new DateTimeZone('Africa/Cairo');
        $events = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $credential) {
            $credentialId = (int) $credential['id'];
            $version = (int) $credential['version'];
            $events[] = [
                'event_id' => 'credential-' . $credentialId . '-v' . $version,
                'occurred_at' => new DateTimeImmutable((string) $credential['effective_on'] . ' 00:00:00', $timezone),
                'event_type' => 'staff.credential.' . strtolower((string) $credential['credential_kind']),
                'resource_type' => 'staff_credential_record',
                'resource_id' => $credentialId,
                'status' => strtolower((string) $credential['lifecycle_status'])
                    . '.' . strtolower((string) $credential['verification_status']),
                'version' => $version,
            ];
        }

        return $events;
    }
}
