<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned application boundary for an immutable monthly permission quota.
 *
 * Callers supply a policy snapshot and an idempotency key; the implementation
 * owns the locked counter cache, append-only movement, and mandatory audit.
 */
interface PermissionQuotaLedgerGateway
{
    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function record(array $command): array;
}
