<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Compatibility projection owned by the leave-request repository.
 *
 * The immutable override record remains the authority. This small projection
 * preserves existing request readers while preventing them from becoming a
 * bypass for the verification query used at submission.
 */
interface LeaveStaffingOverrideRequestGateway
{
    public function applyStaffingOverrideDecision(
        int $requestId,
        int $expectedLockVersion,
        bool $granted,
        ?string $reason
    ): bool;
}
