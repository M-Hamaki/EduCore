<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Finance-owned side-effect boundary.
 *
 * Staff submits auditable facts and units only; Finance remains responsible
 * for money, maker-checker, closed periods, posting, and reversals.
 */
interface PayrollImpactGateway
{
    /**
     * @param numeric-string $units Exact fact units; never a floating-point amount.
     * @param array<string,mixed> $metadata
     * @return array{accepted:bool,status:string,finance_reference:?string}
     */
    public function submitFacts(
        string $effectKey,
        int $staffId,
        string $factType,
        string $units,
        string $effectivePeriod,
        string $sourceRef,
        array $metadata
    ): array;
}
