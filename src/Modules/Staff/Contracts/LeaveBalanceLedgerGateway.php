<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned application boundary for immutable leave-balance movements.
 *
 * Callers never update balance cache columns directly. They submit one
 * idempotent movement, an atomic carry transfer, or a documented reversal.
 */
interface LeaveBalanceLedgerGateway
{
    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function record(array $command): array;

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function carry(array $command): array;

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function reverse(array $command): array;
}
