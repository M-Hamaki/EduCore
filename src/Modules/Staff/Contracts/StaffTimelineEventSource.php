<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * A bounded, summary-safe event stream owned by one Staff/HR resource area.
 *
 * Sources return identifiers/statuses only: no request reasons, medical data,
 * complaint text, attachment references, investigation content, or financial
 * values may cross into the generic staff timeline.
 */
interface StaffTimelineEventSource
{
    /** Stable source identity used for deterministic event keys and warnings. */
    public function sourceKey(): string;

    /**
     * @return list<array{
     *     event_id:string,
     *     occurred_at:DateTimeImmutable,
     *     event_type:string,
     *     resource_type:string,
     *     resource_id:int,
     *     status?:string,
     *     version?:int|null
     * }>
     */
    public function eventsForStaff(
        int $staffUserId,
        DateTimeImmutable $fromInclusive,
        DateTimeImmutable $toExclusive,
        int $limit
    ): array;
}
