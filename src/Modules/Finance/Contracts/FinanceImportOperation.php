<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts;

interface FinanceImportOperation
{
    public function operationType(): string;

    /** @return list<string> */
    public function validate(array $payload, array $context): array;

    /** @return array<string,mixed> Immutable identifiers needed for a later reversal batch. */
    public function post(array $payload, array $context): array;

    /** Implementations must append a reversal; they must never delete the original posting. */
    public function reverse(array $postingResult, array $context): void;
}
