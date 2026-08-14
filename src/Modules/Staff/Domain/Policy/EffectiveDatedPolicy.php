<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Domain\Policy;

use DateTimeImmutable;
use InvalidArgumentException;

/** Immutable published-policy candidate with its dated scope and configuration. */
final class EffectiveDatedPolicy
{
    public const STATE_DRAFT = 'draft';
    public const STATE_PUBLISHED = 'published';
    public const STATE_RETIRED = 'retired';

    private int $policyId;
    private int $versionId;
    private int $versionNumber;
    private string $state;
    private DateTimeImmutable $validFrom;
    private ?DateTimeImmutable $validTo;
    private PolicyScope $scope;

    /** @var array<string,mixed> */
    private array $configuration;

    /**
     * @param array<string,mixed> $configuration JSON-like immutable policy data.
     */
    public function __construct(
        int $policyId,
        int $versionId,
        int $versionNumber,
        string $state,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        PolicyScope $scope,
        array $configuration = []
    ) {
        if ($policyId <= 0 || $versionId <= 0 || $versionNumber <= 0) {
            throw new InvalidArgumentException('Policy and version identifiers must be positive.');
        }
        if (!in_array($state, [self::STATE_DRAFT, self::STATE_PUBLISHED, self::STATE_RETIRED], true)) {
            throw new InvalidArgumentException('Unsupported policy version state: ' . $state);
        }
        if ($validTo !== null && $validTo < $validFrom) {
            throw new InvalidArgumentException('Policy version valid_to cannot precede valid_from.');
        }
        self::assertImmutableConfiguration($configuration);

        $this->policyId = $policyId;
        $this->versionId = $versionId;
        $this->versionNumber = $versionNumber;
        $this->state = $state;
        $this->validFrom = $validFrom;
        $this->validTo = $validTo;
        $this->scope = $scope;
        $this->configuration = $configuration;
    }

    public function policyId(): int
    {
        return $this->policyId;
    }

    public function versionId(): int
    {
        return $this->versionId;
    }

    public function versionNumber(): int
    {
        return $this->versionNumber;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function validFrom(): DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validTo(): ?DateTimeImmutable
    {
        return $this->validTo;
    }

    public function scope(): PolicyScope
    {
        return $this->scope;
    }

    /** @return array<string,mixed> */
    public function configuration(): array
    {
        return $this->configuration;
    }

    public function isEffectiveAt(DateTimeImmutable $atDate): bool
    {
        return $this->state === self::STATE_PUBLISHED
            && $atDate >= $this->validFrom
            && ($this->validTo === null || $atDate <= $this->validTo)
            && $this->scope->isEffectiveAt($atDate);
    }

    /** The first instant at which both the policy version and its scope are effective. */
    public function effectiveStart(): DateTimeImmutable
    {
        return $this->scope->validFrom() > $this->validFrom
            ? $this->scope->validFrom()
            : $this->validFrom;
    }

    /** Stable identity used to collapse duplicate query rows, never competing policies. */
    public function selectionIdentity(): string
    {
        return $this->policyId . ':' . $this->versionId . ':' . $this->scope->identity();
    }

    /** @return array<string,int|string|null> */
    public function explanation(): array
    {
        return [
            'policy_id' => $this->policyId,
            'policy_version_id' => $this->versionId,
            'version_no' => $this->versionNumber,
            'scope_type' => $this->scope->type(),
            'scope_id' => $this->scope->scopeId(),
            'priority' => $this->scope->priority(),
            'effective_from' => $this->effectiveStart()->format('Y-m-d'),
        ];
    }

    /** @param array<mixed> $value */
    private static function assertImmutableConfiguration(array $value): void
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                self::assertImmutableConfiguration($item);
                continue;
            }
            if (is_object($item) || is_resource($item)) {
                throw new InvalidArgumentException('Policy configuration must contain scalar, null, or array values only.');
            }
        }
    }
}
