<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Domain\Policy;

use EduCore\Modules\Finance\Domain\Money;

/**
 * Sibling discount policy: computes the discount tier based on sibling ordering.
 *
 * Ordering rule: oldest enrollment date first; ties broken by student_id (ascending).
 * The discount tier (percentage) for each sibling is determined by their position
 * in the ordered family group, NOT by charge-generation order.
 */
final class SiblingDiscountPolicy
{
    /**
     * Given a list of siblings (with enrollment_date and student_id), return them ordered
     * by oldest enrollment date first; ties broken by student_id ascending.
     *
     * @param array $siblings  each: ['student_id' => int, 'enrollment_date' => string (Y-m-d)]
     * @return array ordered siblings with an added 'sibling_order' (1-based)
     */
    public function orderSiblings(array $siblings): array
    {
        usort($siblings, static function (array $a, array $b): int {
            $dateA = (string) ($a['enrollment_date'] ?? '9999-12-31');
            $dateB = (string) ($b['enrollment_date'] ?? '9999-12-31');
            $cmp = strcmp($dateA, $dateB);
            if ($cmp !== 0) {
                return $cmp;
            }
            return (int) $a['student_id'] <=> (int) $b['student_id'];
        });

        $order = 1;
        foreach ($siblings as &$sibling) {
            $sibling['sibling_order'] = $order;
            ++$order;
        }
        unset($sibling);
        return $siblings;
    }

    /**
     * Compute the discount amount for a sibling given their order and the tier percentages.
     *
     * @param Money $tuition
     * @param int $siblingOrder  1-based
     * @param array $tiers  Decimal percentage strings, e.g. [1 => '10.00', 2 => '15.00'].
     * @return Money the discount amount
     */
    public function computeDiscount(Money $tuition, int $siblingOrder, array $tiers): Money
    {
        $percentage = trim((string) ($tiers[$siblingOrder] ?? '0'));
        if (!preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/', $percentage)) {
            throw new \InvalidArgumentException('Sibling discount percentage must be a decimal string between 0 and 100.');
        }

        [$whole, $fraction] = array_pad(explode('.', $percentage, 2), 2, '');
        $basisPoints = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($basisPoints === 0) {
            return Money::zero();
        }

        // Half-up rounding using integer piasters only: 100.00% = 10,000 basis points.
        $minorDiscount = intdiv(($tuition->toMinorUnits() * $basisPoints) + 5000, 10000);
        return Money::fromMinorUnits($minorDiscount);
    }
}
