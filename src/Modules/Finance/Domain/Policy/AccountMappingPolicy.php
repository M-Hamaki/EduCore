<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Domain\Policy;

/**
 * Account-mapping policy: deterministic resolution for GL account mapping.
 *
 * Resolution order: specificity_score DESC, then priority DESC, then version_number DESC.
 * No two active lines may share the same operation_type + selectors + specificity + priority.
 * Posting is REFUSED on zero matches OR ambiguous matches.
 */
final class AccountMappingPolicy
{
    /**
     * Resolve the best mapping line from a list of candidates.
     *
     * @param array $candidates  each line must have: specificity_score, priority, version_number, debit_account_id, credit_account_id
     * @return array the resolved line
     * @throws \RuntimeException if zero matches or ambiguous matches (same specificity+priority).
     */
    public function resolve(array $candidates): array
    {
        if (empty($candidates)) {
            throw new \RuntimeException('لا يوجد mapping مطابق للعملية المالية.');
        }

        // Sort by specificity DESC, priority DESC, version DESC.
        usort($candidates, static function (array $a, array $b): int {
            $specCmp = (int)($b['specificity_score'] ?? 0) <=> (int)($a['specificity_score'] ?? 0);
            if ($specCmp !== 0) {
                return $specCmp;
            }
            $prioCmp = (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0);
            if ($prioCmp !== 0) {
                return $prioCmp;
            }
            return (int)($b['version_number'] ?? 0) <=> (int)($a['version_number'] ?? 0);
        });

        $best = $candidates[0];

        // Check for ambiguity: another line with the same specificity+priority+version.
        // version is the tiebreaker; if versions differ, the higher version wins (not ambiguous).
        if (count($candidates) > 1) {
            $second = $candidates[1];
            $bestSpec = (int)($best['specificity_score'] ?? 0);
            $secondSpec = (int)($second['specificity_score'] ?? 0);
            $bestPrio = (int)($best['priority'] ?? 0);
            $secondPrio = (int)($second['priority'] ?? 0);
            $bestVersion = (int)($best['version_number'] ?? 0);
            $secondVersion = (int)($second['version_number'] ?? 0);

            if ($bestSpec === $secondSpec && $bestPrio === $secondPrio && $bestVersion === $secondVersion) {
                throw new \RuntimeException(
                    'تعارض في account-mapping: يوجد أكثر من خيط مطابق بنفس الأولوية والخصوصية والإصدار.'
                );
            }
        }

        return $best;
    }
}
