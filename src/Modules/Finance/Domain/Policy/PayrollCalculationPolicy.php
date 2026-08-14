<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Domain\Policy;

use EduCore\Modules\Finance\Domain\Money;

/**
 * Payroll calculation policy: server-side gross/net computation from components.
 *
 * The server NEVER trusts a client-sent net value. gross = sum of earnings;
 * total_deductions = sum of deductions; net = gross - total_deductions.
 */
final class PayrollCalculationPolicy
{
    /**
     * Compute payroll from a list of components.
     *
     * @param array $components  each: ['amount' => Money, 'direction' => 'earning'|'deduction']
     * @return array{gross: Money, total_deductions: Money, net: Money}
     */
    public function compute(array $components): array
    {
        $gross = Money::zero();
        $totalDeductions = Money::zero();

        foreach ($components as $comp) {
            /** @var Money $amount */
            $amount = $comp['amount'];
            $direction = (string) $comp['direction'];

            if ($direction === 'earning') {
                $gross = $gross->add($amount);
            } elseif ($direction === 'deduction') {
                $totalDeductions = $totalDeductions->add($amount);
            }
        }

        // net = gross - total_deductions; if deductions > gross, net = 0 (no negative salary)
        $net = $gross->greaterThanOrEqual($totalDeductions)
            ? $gross->subtract($totalDeductions)
            : Money::zero();

        return ['gross' => $gross, 'total_deductions' => $totalDeductions, 'net' => $net];
    }

    /**
     * Verify a client-sent net matches the server-computed net.
     * If not, the client value is ignored and the server value is used.
     *
     * @param Money $clientNet
     * @param Money $serverNet
     * @return Money the server-computed net (always)
     */
    public function resolveNet(Money $clientNet, Money $serverNet): Money
    {
        // Server value always wins; client is ignored.
        return $serverNet;
    }
}
