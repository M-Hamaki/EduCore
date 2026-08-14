<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Contracts;

interface StudentWriteEligibility
{
    /** @return array<string,mixed> */
    public function assertWritable(int $studentId): array;

    /** @param list<int|string> $studentIds */
    public function assertWritableMany(array $studentIds): void;
}
