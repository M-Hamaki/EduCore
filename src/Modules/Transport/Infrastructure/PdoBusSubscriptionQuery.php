<?php

declare(strict_types=1);

namespace EduCore\Modules\Transport\Infrastructure;

use EduCore\Modules\Transport\Contracts\BusSubscriptionQuery;
use PDO;

/**
 * PDO implementation of BusSubscriptionQuery — owned by the Transport module.
 * Replaces the fragile `buses.area == bus_fee_zones.zone_name` string match.
 */
final class PdoBusSubscriptionQuery implements BusSubscriptionQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function subscriptionOf(int $studentId, int $academicYearId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT sba.bus_id, b.area, b.route_description AS route_ref,
                    sba.assigned_at AS effective_from, sba.academic_year_id
             FROM student_bus_assignments sba
             JOIN buses b ON b.id = sba.bus_id
             WHERE sba.student_id = ?
             AND (sba.academic_year_id = ? OR sba.academic_year_id IS NULL)
             AND sba.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$studentId, $academicYearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
