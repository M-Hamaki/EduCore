<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Persistence boundary for investigations and immutable evidence.
 *
 * The case projection exposes only role-separation facts needed by the
 * application owner. It deliberately has no Attendance/Ertaq/Finance writer.
 */
interface DisciplineInvestigationRepository
{
    public function transactional(callable $work): mixed;

    public function lockUser(int $userId): bool;

    /** @return array<string,mixed>|null */
    public function caseForUpdate(int $caseId): ?array;

    /** @return array<string,mixed>|null */
    public function investigationByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function investigationForUpdate(int $investigationId): ?array;

    /** @param array<string,mixed> $investigation */
    public function insertInvestigation(array $investigation): int;

    public function completeInvestigation(
        int $investigationId,
        int $expectedLockVersion,
        string $findings,
        string $recommendation,
        string $completedAt
    ): bool;

    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus
    ): bool;

    /** @return array<string,mixed>|null */
    public function evidenceByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function evidenceForUpdate(int $evidenceId): ?array;

    /** @param array<string,mixed> $evidence */
    public function insertEvidence(array $evidence): int;
}
