<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/** Persistence boundary for effective schedules and their operational changes. */
interface SchedulePolicyRepository extends SchedulePolicyReadRepository
{
    /** @return array<string,mixed>|null */
    public function findCommandReceipt(string $idempotencyKey): ?array;

    public function recordCommandReceipt(array $receipt): void;

    public function nextVersionNumber(int $policyId): int;

    /** @return array<string,mixed>|null */
    public function policyForUpdate(int $policyId): ?array;

    public function insertPolicy(array $policy): int;

    public function updatePolicy(int $policyId, array $policy): void;

    public function insertDraftVersion(int $policyId, array $version): int;

    /** @return array<string,mixed>|null */
    public function findVersionByCreateKey(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function versionForUpdate(int $versionId): ?array;

    public function updateDraftVersion(int $versionId, int $expectedLockVersion, array $version): bool;

    public function replaceDraftDays(int $versionId, array $days): void;

    public function replaceDraftScopes(int $versionId, array $scopes): void;

    /** @return list<array<string,mixed>> */
    public function publicationConflicts(int $versionId): array;

    public function markPublished(
        int $versionId,
        int $expectedLockVersion,
        int $actorId,
        DateTimeImmutable $publishedAt,
        string $publicationKey,
        string $payloadHash
    ): bool;

    /** @return array<string,mixed>|null */
    public function findCalendarExceptionByIdempotency(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function calendarExceptionForUpdate(int $exceptionId): ?array;

    /**
     * Locks the terminal active/retired calendar-exception chain member for one
     * date/scope identity. This serializes first creation and later immutable
     * successors so one identity cannot silently branch into conflicting rules.
     *
     * @return array<string,mixed>|null
     */
    public function terminalCalendarExceptionForDateScopeForUpdate(
        string $calendarDate,
        string $scopeType,
        int $scopeId
    ): ?array;

    public function insertCalendarException(array $exception): int;

    public function updateDraftCalendarException(
        int $exceptionId,
        int $expectedLockVersion,
        array $exception
    ): bool;

    /** @return array<string,mixed>|null */
    public function findChangeRequestByIdempotency(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function changeRequestForUpdate(int $requestId): ?array;

    public function insertChangeRequest(array $request): int;

    public function updateChangeRequest(
        int $requestId,
        int $expectedLockVersion,
        array $changes
    ): bool;

    /** Serializes overlap decisions for all participants in ascending id order. */
    public function lockChangeParticipants(array $staffIds): void;

    /** @return list<array<string,mixed>> */
    public function overlappingChangeRequests(
        array $staffIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $statuses,
        ?int $excludeRequestId = null
    ): array;
}
