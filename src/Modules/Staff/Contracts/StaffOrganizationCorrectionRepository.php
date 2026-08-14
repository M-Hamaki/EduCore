<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/** Persistence boundary for immutable organization-correction previews and decisions. */
interface StaffOrganizationCorrectionRepository
{
    public function transactional(callable $work): mixed;

    public function actorCanRequestCorrection(int $actorId): bool;

    /** Super-admin or an effective HR manager; the service also enforces two-person control. */
    public function actorCanApproveCorrection(int $actorId): bool;

    /** @return array<string,mixed>|null */
    public function correctionByIdempotencyForUpdate(string $key): ?array;

    /** @return array<string,mixed>|null */
    public function correctionByIdForUpdate(int $correctionId): ?array;

    /** @return array<string,mixed>|null */
    public function finalDecisionForCorrectionForUpdate(int $correctionId): ?array;

    /** @return array<string,mixed>|null */
    public function decisionByIdempotencyForUpdate(string $key): ?array;

    /** @param array<string,mixed> $correction */
    public function insertCorrection(array $correction): int;

    /** @param array<string,mixed> $decision */
    public function insertDecision(array $decision): int;

    /** @return list<array<string,mixed>> */
    public function recentCorrections(int $limit): array;
}
