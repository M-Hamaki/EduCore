<?php

declare(strict_types=1);

namespace EduCore\Modules\Transport\Contracts;

interface StudentBusAssignmentRepository
{
    /** @return array<string,mixed>|null */
    public function lock(int $studentId, int $academicYearId): ?array;

    /** @return array<string,mixed>|null */
    public function replace(
        int $studentId,
        int $academicYearId,
        ?int $busId,
        ?int $backupBusId,
        ?string $notes,
        int $actorId
    ): ?array;

    public function activeBusExists(int $busId): bool;
}
