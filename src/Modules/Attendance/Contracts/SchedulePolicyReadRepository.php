<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

interface SchedulePolicyReadRepository
{
    /** @return list<array<string,mixed>> */
    public function listPolicies(array $filters = []): array;

    /** @return array<string,mixed>|null */
    public function findPolicy(int $policyId): ?array;

    /** @return array<string,mixed>|null */
    public function findVersion(int $versionId): ?array;

    /** @return list<array<string,mixed>> */
    public function candidateVersionsFor(
        int $staffId,
        array $assignmentSnapshot,
        DateTimeImmutable $at
    ): array;

    /** @return list<array<string,mixed>> */
    public function calendarExceptionsFor(
        int $staffId,
        array $assignmentSnapshot,
        DateTimeImmutable $date
    ): array;

    /** @return list<array<string,mixed>> */
    public function approvedChangesFor(
        int $staffId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array;

    /** @return list<array<string,mixed>> */
    public function listCalendarExceptions(array $filters = []): array;
}
