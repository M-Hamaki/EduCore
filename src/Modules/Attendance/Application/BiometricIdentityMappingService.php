<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\BiometricIdentityMappingRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use InvalidArgumentException;

/**
 * Owns effective-dated device identity assignments.
 *
 * Mapping periods use half-open UTC intervals [valid_from, valid_to). Reuse of
 * a device identity therefore preserves the worker snapshot on older punches.
 */
final class BiometricIdentityMappingService
{
    private DateTimeZone $utc;

    public function __construct(
        private AttendanceTransactionManager $transactions,
        private BiometricIdentityMappingRepository $mappings,
        private StaffAssignmentAtDateQuery $staffAssignments,
        private AuditEventWriter $audit
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @return array<string,mixed>
     */
    public function assign(
        int $actorId,
        int $deviceId,
        string $biometricIdentity,
        int $staffUserId,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        string $source,
        ?string $retiredReason = null
    ): array {
        $identity = $this->normalizeIdentity($biometricIdentity);
        $source = $this->normalizeSource($source);
        $from = $validFrom->setTimezone($this->utc);
        $to = $validTo?->setTimezone($this->utc);
        $retiredReason = $this->normalizeRetiredReason($to, $retiredReason);
        $this->assertIds($actorId, $deviceId, $staffUserId);
        $this->assertRange($from, $to);
        $this->assertEligibleStaff($staffUserId, $from);

        return $this->transactions->transactional(function () use (
            $actorId,
            $deviceId,
            $identity,
            $staffUserId,
            $from,
            $to,
            $source,
            $retiredReason
        ): array {
            $existing = $this->mappings->mappingsForUpdate($deviceId, $identity);
            foreach ($existing as $mapping) {
                if ($this->sameInstant($mapping['valid_from'] ?? null, $from)) {
                    if ($this->sameAssignment($mapping, $staffUserId, $to, $source, $retiredReason)) {
                        return $this->mappingReceipt($mapping, true);
                    }
                    throw new DomainException('BIOMETRIC_MAPPING_IDEMPOTENCY_CONFLICT');
                }
                if ($this->overlaps($mapping, $from, $to)) {
                    throw new DomainException('IDENTITY_OVERLAP');
                }
            }

            $payload = [
                'device_id' => $deviceId,
                'biometric_identity' => $identity,
                'staff_user_id' => $staffUserId,
                'valid_from' => $this->databaseInstant($from),
                'valid_to' => $to === null ? null : $this->databaseInstant($to),
                'source' => $source,
                'confirmed_by' => $actorId,
                'retired_reason' => $retiredReason,
            ];
            $mappingId = $this->mappings->insertMapping($payload);

            $this->audit->recordEvent(
                'staff_biometric_identity_mapped',
                'staff_biometric_identity_mappings',
                $mappingId,
                null,
                [
                    'device_id' => $deviceId,
                    'staff_user_id' => $staffUserId,
                    'valid_from' => $from->format(DateTimeImmutable::ATOM),
                    'valid_to' => $to?->format(DateTimeImmutable::ATOM),
                    'source' => $source,
                    'identity_hash' => $this->identityHash($deviceId, $identity),
                ],
                ['user_id' => $actorId]
            );

            return $this->mappingReceipt(['id' => $mappingId] + $payload, false);
        });
    }

    /**
     * Atomically close the mapping effective immediately before the replacement.
     * Existing raw events are deliberately not updated or re-linked.
     *
     * @return array{retired_mapping_id:int,new_mapping:array<string,mixed>}
     */
    public function reassign(
        int $actorId,
        int $deviceId,
        string $biometricIdentity,
        int $newStaffUserId,
        DateTimeImmutable $effectiveAt,
        string $source,
        string $reason
    ): array {
        $identity = $this->normalizeIdentity($biometricIdentity);
        $source = $this->normalizeSource($source);
        $reason = $this->requiredText($reason, 1000, 'BIOMETRIC_MAPPING_RETIREMENT_REASON_REQUIRED');
        $effectiveAt = $effectiveAt->setTimezone($this->utc);
        $this->assertIds($actorId, $deviceId, $newStaffUserId);
        $this->assertEligibleStaff($newStaffUserId, $effectiveAt);

        return $this->transactions->transactional(function () use (
            $actorId,
            $deviceId,
            $identity,
            $newStaffUserId,
            $effectiveAt,
            $source,
            $reason
        ): array {
            $history = $this->mappings->mappingsForUpdate($deviceId, $identity);
            $effective = array_values(array_filter(
                $history,
                fn (array $mapping): bool => $this->contains($mapping, $effectiveAt)
            ));
            if ($effective === []) {
                throw new DomainException('BIOMETRIC_MAPPING_NOT_FOUND');
            }
            if (count($effective) !== 1) {
                throw new DomainException('IDENTITY_OVERLAP');
            }
            $current = $effective[0];
            if ((int) ($current['staff_user_id'] ?? 0) === $newStaffUserId) {
                throw new DomainException('IDENTITY_ALREADY_ASSIGNED');
            }
            if (new DateTimeImmutable((string) $current['valid_from'], $this->utc) >= $effectiveAt) {
                throw new DomainException('BIOMETRIC_MAPPING_RANGE_INVALID');
            }

            foreach ($history as $mapping) {
                if ((int) ($mapping['id'] ?? 0) === (int) ($current['id'] ?? 0)) {
                    continue;
                }
                if ($this->overlaps($mapping, $effectiveAt, null)) {
                    throw new DomainException('IDENTITY_OVERLAP');
                }
            }

            $retiredId = (int) ($current['id'] ?? 0);
            if ($retiredId <= 0
                || !$this->mappings->retireMapping($retiredId, $effectiveAt, $reason)) {
                throw new DomainException('BIOMETRIC_MAPPING_STALE');
            }

            $payload = [
                'device_id' => $deviceId,
                'biometric_identity' => $identity,
                'staff_user_id' => $newStaffUserId,
                'valid_from' => $this->databaseInstant($effectiveAt),
                'valid_to' => null,
                'source' => $source,
                'confirmed_by' => $actorId,
                'retired_reason' => null,
            ];
            $newId = $this->mappings->insertMapping($payload);

            $this->audit->recordEvent(
                'staff_biometric_identity_reassigned',
                'staff_biometric_identity_mappings',
                $newId,
                null,
                [
                    'device_id' => $deviceId,
                    'retired_mapping_id' => $retiredId,
                    'previous_staff_user_id' => (int) $current['staff_user_id'],
                    'new_staff_user_id' => $newStaffUserId,
                    'effective_at' => $effectiveAt->format(DateTimeImmutable::ATOM),
                    'source' => $source,
                    'identity_hash' => $this->identityHash($deviceId, $identity),
                ],
                ['user_id' => $actorId]
            );

            return [
                'retired_mapping_id' => $retiredId,
                'new_mapping' => $this->mappingReceipt(['id' => $newId] + $payload, false),
            ];
        });
    }

    private function assertEligibleStaff(int $staffUserId, DateTimeImmutable $at): void
    {
        if ($this->staffAssignments->forStaff($staffUserId, $at) === null) {
            throw new DomainException('STAFF_NOT_ELIGIBLE_AT_MAPPING_DATE');
        }
    }

    private function assertIds(int $actorId, int $deviceId, int $staffUserId): void
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('BIOMETRIC_MAPPING_ACTOR_INVALID');
        }
        if ($deviceId <= 0) {
            throw new InvalidArgumentException('BIOMETRIC_DEVICE_ID_INVALID');
        }
        if ($staffUserId <= 0) {
            throw new InvalidArgumentException('BIOMETRIC_STAFF_ID_INVALID');
        }
    }

    private function assertRange(DateTimeImmutable $from, ?DateTimeImmutable $to): void
    {
        if ($to !== null && $to <= $from) {
            throw new InvalidArgumentException('BIOMETRIC_MAPPING_RANGE_INVALID');
        }
    }

    private function normalizeIdentity(string $identity): string
    {
        return $this->requiredText($identity, 100, 'BIOMETRIC_IDENTITY_INVALID');
    }

    private function normalizeSource(string $source): string
    {
        $source = $this->requiredText($source, 50, 'BIOMETRIC_MAPPING_SOURCE_INVALID');
        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $source) !== 1) {
            throw new InvalidArgumentException('BIOMETRIC_MAPPING_SOURCE_INVALID');
        }
        return $source;
    }

    private function normalizeRetiredReason(?DateTimeImmutable $to, ?string $reason): ?string
    {
        if ($to === null) {
            if ($reason !== null && trim($reason) !== '') {
                throw new InvalidArgumentException('BIOMETRIC_MAPPING_RETIREMENT_REASON_WITHOUT_END');
            }
            return null;
        }
        return $this->requiredText(
            (string) $reason,
            1000,
            'BIOMETRIC_MAPPING_RETIREMENT_REASON_REQUIRED'
        );
    }

    private function requiredText(string $value, int $maxLength, string $error): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    /** @param array<string,mixed> $mapping */
    private function contains(array $mapping, DateTimeImmutable $at): bool
    {
        $from = new DateTimeImmutable((string) ($mapping['valid_from'] ?? ''), $this->utc);
        $to = isset($mapping['valid_to']) && $mapping['valid_to'] !== null
            ? new DateTimeImmutable((string) $mapping['valid_to'], $this->utc)
            : null;
        return $from <= $at && ($to === null || $at < $to);
    }

    /** @param array<string,mixed> $mapping */
    private function overlaps(array $mapping, DateTimeImmutable $from, ?DateTimeImmutable $to): bool
    {
        $existingFrom = new DateTimeImmutable((string) ($mapping['valid_from'] ?? ''), $this->utc);
        $existingTo = isset($mapping['valid_to']) && $mapping['valid_to'] !== null
            ? new DateTimeImmutable((string) $mapping['valid_to'], $this->utc)
            : null;
        return ($existingTo === null || $existingTo > $from)
            && ($to === null || $existingFrom < $to);
    }

    /** @param array<string,mixed> $mapping */
    private function sameAssignment(
        array $mapping,
        int $staffUserId,
        ?DateTimeImmutable $to,
        string $source,
        ?string $retiredReason
    ): bool {
        $mappingTo = $mapping['valid_to'] ?? null;
        return (int) ($mapping['staff_user_id'] ?? 0) === $staffUserId
            && (string) ($mapping['source'] ?? '') === $source
            && (($mappingTo === null && $to === null)
                || ($mappingTo !== null && $to !== null && $this->sameInstant($mappingTo, $to)))
            && (($mapping['retired_reason'] ?? null) === $retiredReason);
    }

    private function sameInstant(mixed $stored, DateTimeImmutable $instant): bool
    {
        if ($stored === null || trim((string) $stored) === '') {
            return false;
        }
        return (new DateTimeImmutable((string) $stored, $this->utc))->format('U.u')
            === $instant->format('U.u');
    }

    /** @param array<string,mixed> $mapping @return array<string,mixed> */
    private function mappingReceipt(array $mapping, bool $replayed): array
    {
        return [
            'mapping_id' => (int) ($mapping['id'] ?? 0),
            'device_id' => (int) ($mapping['device_id'] ?? 0),
            'biometric_identity' => (string) ($mapping['biometric_identity'] ?? ''),
            'staff_user_id' => (int) ($mapping['staff_user_id'] ?? 0),
            'valid_from' => (string) ($mapping['valid_from'] ?? ''),
            'valid_to' => $mapping['valid_to'] ?? null,
            'source' => (string) ($mapping['source'] ?? ''),
            'replayed' => $replayed,
        ];
    }

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    private function identityHash(int $deviceId, string $identity): string
    {
        return hash('sha256', $deviceId . ':' . $identity);
    }
}
