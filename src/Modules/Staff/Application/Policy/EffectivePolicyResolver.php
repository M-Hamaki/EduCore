<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Policy;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Domain\Policy\EffectiveDatedPolicy;
use InvalidArgumentException;

/** Selects one effective policy using a deterministic, fail-closed precedence. */
final class EffectivePolicyResolver
{
    /**
     * @param list<EffectiveDatedPolicy> $candidates
     */
    public function resolve(array $candidates, DateTimeImmutable $atDate): ?EffectiveDatedPolicy
    {
        /** @var array<string,EffectiveDatedPolicy> $eligible */
        $eligible = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof EffectiveDatedPolicy) {
                throw new InvalidArgumentException('Every policy candidate must be an EffectiveDatedPolicy.');
            }
            if ($candidate->isEffectiveAt($atDate)) {
                $eligible[$candidate->selectionIdentity()] = $candidate;
            }
        }

        if ($eligible === []) {
            return null;
        }

        $ranked = array_values($eligible);
        usort($ranked, function (EffectiveDatedPolicy $left, EffectiveDatedPolicy $right): int {
            return $this->comparePrecedence($left, $right);
        });

        $winner = $ranked[0];
        $ties = array_values(array_filter(
            $ranked,
            function (EffectiveDatedPolicy $candidate) use ($winner): bool {
                return $this->samePrecedence($winner, $candidate);
            }
        ));

        if (count($ties) > 1) {
            $versionIds = array_map(
                static function (EffectiveDatedPolicy $candidate): int {
                    return $candidate->versionId();
                },
                $ties
            );
            sort($versionIds, SORT_NUMERIC);

            throw new DomainException(
                'AMBIGUOUS_EFFECTIVE_POLICY: equally ranked policy versions '
                . implode(', ', $versionIds)
            );
        }

        return $winner;
    }

    /**
     * @param list<EffectiveDatedPolicy> $candidates
     * @return array{policy:EffectiveDatedPolicy,reason:array<string,int|string|null>}|null
     */
    public function resolveWithExplanation(array $candidates, DateTimeImmutable $atDate): ?array
    {
        $policy = $this->resolve($candidates, $atDate);
        if ($policy === null) {
            return null;
        }

        return [
            'policy' => $policy,
            'reason' => $policy->explanation(),
        ];
    }

    private function comparePrecedence(EffectiveDatedPolicy $left, EffectiveDatedPolicy $right): int
    {
        $scopeOrder = $right->scope()->precedence() <=> $left->scope()->precedence();
        if ($scopeOrder !== 0) {
            return $scopeOrder;
        }

        $priorityOrder = $right->scope()->priority() <=> $left->scope()->priority();
        if ($priorityOrder !== 0) {
            return $priorityOrder;
        }

        return $right->effectiveStart() <=> $left->effectiveStart();
    }

    private function samePrecedence(EffectiveDatedPolicy $left, EffectiveDatedPolicy $right): bool
    {
        return $left->scope()->precedence() === $right->scope()->precedence()
            && $left->scope()->priority() === $right->scope()->priority()
            && $left->effectiveStart() == $right->effectiveStart();
    }
}
