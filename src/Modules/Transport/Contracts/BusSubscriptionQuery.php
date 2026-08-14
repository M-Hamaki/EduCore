<?php

declare(strict_types=1);

namespace EduCore\Modules\Transport\Contracts;

/**
 * Bus subscription query contract — owned by the Transport module.
 * Consumed read-only by Finance for: bus fee charge generation
 * (replaces the fragile `buses.area == bus_fee_zones.zone_name` string match).
 */
interface BusSubscriptionQuery
{
    /**
     * Get the bus subscription for a student in an academic year.
     *
     * @return array{bus_id: int, area: ?string, route_ref: ?string, effective_from: ?string, academic_year_id: int}|null
     */
    public function subscriptionOf(int $studentId, int $academicYearId): ?array;
}
