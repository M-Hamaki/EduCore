<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Append-only evidence store for an authorized staffing exception.
 *
 * It is deliberately separate from the editable leave-request row so a
 * draft edit cannot rewrite a historical authorization decision.
 */
interface LeaveStaffingOverrideRepository
{
    /** @return array<string,mixed>|null */
    public function decisionByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $decision */
    public function insertDecision(array $decision): int;
}
