<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Read-only proof consumed by the normal leave submission owner.
 *
 * A request-row flag is intentionally insufficient: the caller needs a
 * locked, immutable decision tied to the exact request hash and the live
 * staffing requirement fingerprint.
 */
interface LeaveStaffingOverrideApprovalQuery
{
    /** @return array<string,mixed>|null */
    public function approvedDecisionForRequestHashForUpdate(int $requestId, string $requestHash): ?array;
}
