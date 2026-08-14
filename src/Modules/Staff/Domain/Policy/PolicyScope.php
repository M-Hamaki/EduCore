<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Domain\Policy;

use DateTimeImmutable;
use InvalidArgumentException;

/** Immutable effective-dated policy scope. */
final class PolicyScope
{
    public const TYPE_GLOBAL = 'global';
    public const TYPE_JOB_TITLE = 'job_title';
    public const TYPE_ORG_UNIT = 'org_unit';
    public const TYPE_GROUP = 'group';
    public const TYPE_STAFF = 'staff';

    /** @var array<string,int> */
    private const PRECEDENCE = [
        self::TYPE_GLOBAL => 100,
        self::TYPE_JOB_TITLE => 200,
        self::TYPE_ORG_UNIT => 300,
        self::TYPE_GROUP => 400,
        self::TYPE_STAFF => 500,
    ];

    private string $type;
    private ?int $scopeId;
    private int $priority;
    private DateTimeImmutable $validFrom;
    private ?DateTimeImmutable $validTo;

    public function __construct(
        string $type,
        ?int $scopeId,
        int $priority,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo = null
    ) {
        if (!isset(self::PRECEDENCE[$type])) {
            throw new InvalidArgumentException('Unsupported policy scope type: ' . $type);
        }
        if ($type === self::TYPE_GLOBAL && $scopeId !== null) {
            throw new InvalidArgumentException('Global policy scope must not have a scope id.');
        }
        if ($type !== self::TYPE_GLOBAL && ($scopeId === null || $scopeId <= 0)) {
            throw new InvalidArgumentException('A non-global policy scope requires a positive scope id.');
        }
        if ($priority < 0) {
            throw new InvalidArgumentException('Policy scope priority cannot be negative.');
        }
        if ($validTo !== null && $validTo < $validFrom) {
            throw new InvalidArgumentException('Policy scope valid_to cannot precede valid_from.');
        }

        $this->type = $type;
        $this->scopeId = $scopeId;
        $this->priority = $priority;
        $this->validFrom = $validFrom;
        $this->validTo = $validTo;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function scopeId(): ?int
    {
        return $this->scopeId;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function validFrom(): DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validTo(): ?DateTimeImmutable
    {
        return $this->validTo;
    }

    public function precedence(): int
    {
        return self::PRECEDENCE[$this->type];
    }

    public function isEffectiveAt(DateTimeImmutable $atDate): bool
    {
        return $atDate >= $this->validFrom
            && ($this->validTo === null || $atDate <= $this->validTo);
    }

    public function identity(): string
    {
        return implode(':', [
            $this->type,
            $this->scopeId === null ? '*' : (string) $this->scopeId,
            (string) $this->priority,
            $this->validFrom->format(DateTimeImmutable::ATOM),
            $this->validTo === null ? '*' : $this->validTo->format(DateTimeImmutable::ATOM),
        ]);
    }
}
