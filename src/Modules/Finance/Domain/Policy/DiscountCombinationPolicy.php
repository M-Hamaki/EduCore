<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Domain\Policy;

use EduCore\Modules\Finance\Domain\Money;
use InvalidArgumentException;

/**
 * Discount combination policy.
 *
 * DEFAULT: no-combination (highest-benefit discount applies).
 * Explicit-combine: only when the policy states it, with a MANDATORY cap.
 */
final class DiscountCombinationPolicy
{
    /**
     * Resolve which discount(s) to apply given a list of candidate discounts.
     *
     * @param array $candidates  each: ['amount' => Money, 'combinable' => bool, 'cap_amount' => ?Money, 'priority' => int]
     * @return array{applied: Money, combined: bool}
     */
    public function resolve(array $candidates): array
    {
        if (empty($candidates)) {
            return ['applied' => Money::zero(), 'combined' => false];
        }

        // If only one candidate, apply it.
        if (count($candidates) === 1) {
            return ['applied' => $candidates[0]['amount'], 'combined' => false];
        }

        // Check if ALL candidates are combinable.
        $allCombinable = true;
        foreach ($candidates as $c) {
            if (!(bool) ($c['combinable'] ?? false)) {
                $allCombinable = false;
                break;
            }
        }

        if (!$allCombinable) {
            // Default: no-combine → highest-benefit only.
            $best = $candidates[0];
            foreach ($candidates as $c) {
                if ($c['amount']->greaterThan($best['amount'])) {
                    $best = $c;
                }
            }
            return ['applied' => $best['amount'], 'combined' => false];
        }

        // Explicit-combine: sum, but cap it.
        $sum = Money::zero();
        foreach ($candidates as $c) {
            $sum = $sum->add($c['amount']);
        }

        // Find the cap (minimum cap among all combinable candidates, since each may have its own).
        $cap = null;
        foreach ($candidates as $c) {
            if (!isset($c['cap_amount']) || !$c['cap_amount'] instanceof Money) {
                throw new InvalidArgumentException('Every combinable discount requires an explicit Money cap.');
            }

            /** @var Money $candidateCap */
            $candidateCap = $c['cap_amount'];
            if ($cap === null || $candidateCap->compareTo($cap) < 0) {
                $cap = $candidateCap;
            }
        }

        if ($sum->greaterThan($cap)) {
            $sum = $cap;
        }

        return ['applied' => $sum, 'combined' => true];
    }
}
