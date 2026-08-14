<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned persistence boundary for the incident/case/party aggregate.
 *
 * Implementations must keep each business write and the caller's shared audit
 * event in the same transaction. Cross-module source IDs remain scalar
 * references; this repository never writes Attendance, Ertaq, or Finance.
 */
interface DisciplineCaseRepository
{
    public function transactional(callable $work): mixed;

    public function lockStaff(int $staffUserId): bool;

    /** @return array<string,mixed>|null */
    public function incidentByCreateIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function incidentForUpdate(int $incidentId): ?array;

    /** @param array<string,mixed> $incident */
    public function insertIncident(array $incident): int;

    public function markIncidentTriaged(int $incidentId, int $expectedLockVersion): bool;

    public function cancelIncident(
        int $incidentId,
        int $expectedLockVersion,
        int $actorId,
        string $reason,
        string $cancelledAt
    ): bool;

    /** @return array<string,mixed>|null */
    public function caseByCreateIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function caseByIncidentForUpdate(int $incidentId): ?array;

    /** @return array<string,mixed>|null */
    public function caseForUpdate(int $caseId): ?array;

    /** @param array<string,mixed> $case */
    public function insertCase(array $case): int;

    /** @param array<string,mixed> $changes */
    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus,
        array $changes
    ): bool;

    public function cancelCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        int $actorId,
        string $reason,
        string $cancelledAt
    ): bool;

    /** @return array<string,mixed>|null */
    public function partyByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function partyForUpdate(int $partyId): ?array;

    /** @param array<string,mixed> $party */
    public function insertParty(array $party): int;

    public function declarePartyConflict(
        int $partyId,
        int $expectedLockVersion,
        string $declaration,
        string $declaredAt
    ): bool;

    public function withdrawParty(
        int $partyId,
        int $expectedLockVersion,
        int $actorId,
        string $reason,
        string $withdrawnAt
    ): bool;
}
