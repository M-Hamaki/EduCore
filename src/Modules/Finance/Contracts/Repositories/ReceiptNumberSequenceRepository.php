<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for receipt number sequencing (per-cashbox/year, atomic).
 */
interface ReceiptNumberSequenceRepository
{
    /**
     * Allocate the next sequence number for a cashbox within an academic year (atomic FOR UPDATE).
     *
     * @param int $cashboxId
     * @param int $academicYearId
     * @return int the allocated sequence number
     */
    public function allocateSequenceNumber(int $cashboxId, int $academicYearId): int;
}
