<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerGateway;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerRepository;
use EduCore\Modules\Staff\Domain\Leave\LeaveUnits;
use InvalidArgumentException;
use JsonException;

/**
 * Serializes leave balance cache updates behind an immutable movement ledger.
 *
 * Account counters are a fast, lock-versioned projection. The append-only
 * movement rows explain every change and are the only supported correction
 * path; neither requests nor pages may update the counters directly.
 */
final class LeaveBalanceLedger implements LeaveBalanceLedgerGateway
{
    /** @var list<string> */
    private const DIRECT_MOVEMENT_TYPES = [
        'grant',
        'accrue',
        'reserve',
        'consume',
        'release',
        'restore',
        'expire',
        'adjust',
    ];

    public function __construct(
        private LeaveBalanceLedgerRepository $repository,
        private AuditEventWriter $audit
    ) {
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function record(array $command): array
    {
        $command = $this->normalizeRecordCommand($command);

        return $this->repository->transactional(function () use ($command): array {
            $existing = $this->repository->movementByIdempotencyForUpdate($command['idempotency_key']);
            if ($existing !== null) {
                return $this->replayedReceipt($existing, $command['movement_hash']);
            }

            $account = $this->normalizedAccount($this->repository->ensureAccountForUpdate($command['account']));
            $this->assertAccountIdentity($account, $command['account']);
            [$after, $deltas] = $this->applyDirectMovement($account, $command);
            $after = $this->persistAccount($after, (int) $account['lock_version']);

            $movement = $this->movementRecord(
                $after,
                $command,
                $deltas,
                null
            );
            $movementId = $this->repository->insertMovement($movement);
            if ($movementId <= 0) {
                throw new DomainException('LEAVE_BALANCE_MOVEMENT_PERSIST_FAILED');
            }
            $movement['id'] = $movementId;
            $this->auditMovement($movement, $after, $command, $deltas, null);

            return $this->receipt($movement, $after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function carry(array $command): array
    {
        $command = $this->normalizeCarryCommand($command);

        return $this->repository->transactional(function () use ($command): array {
            $existingOut = $this->repository->movementByIdempotencyForUpdate($command['out_idempotency_key']);
            $existingIn = $this->repository->movementByIdempotencyForUpdate($command['in_idempotency_key']);
            if ($existingOut !== null || $existingIn !== null) {
                if ($existingOut === null || $existingIn === null) {
                    throw new DomainException('LEAVE_BALANCE_CARRY_REPLAY_INCOMPLETE');
                }
                $this->assertMovementHash($existingOut, $command['out_movement_hash']);
                $this->assertMovementHash($existingIn, $command['in_movement_hash']);

                return [
                    'replayed' => true,
                    'movement_type' => 'carry',
                    'source_movement_id' => (int) ($existingOut['id'] ?? 0),
                    'target_movement_id' => (int) ($existingIn['id'] ?? 0),
                    'source_account_id' => (int) ($existingOut['account_id'] ?? 0),
                    'target_account_id' => (int) ($existingIn['account_id'] ?? 0),
                ];
            }

            $locked = $this->lockCarryAccounts($command['source_account'], $command['target_account']);
            $source = $locked['source'];
            $target = $locked['target'];
            if ($source['available_milli'] < $command['units_milli']) {
                throw new DomainException('LEAVE_BALANCE_CARRY_SOURCE_INSUFFICIENT');
            }

            $sourceDeltas = [
                'units_milli' => -$command['units_milli'],
                'available_milli' => -$command['units_milli'],
                'reserved_milli' => 0,
                'consumed_milli' => 0,
                'granted_milli' => 0,
                'expired_milli' => 0,
            ];
            $targetDeltas = [
                'units_milli' => $command['units_milli'],
                'available_milli' => $command['units_milli'],
                'reserved_milli' => 0,
                'consumed_milli' => 0,
                'granted_milli' => $command['units_milli'],
                'expired_milli' => 0,
            ];
            $sourceAfter = $this->applyDeltas($source, $sourceDeltas);
            $targetAfter = $this->applyDeltas($target, $targetDeltas);

            $this->persistCarryAccounts(
                $source,
                $sourceAfter,
                $target,
                $targetAfter
            );

            $sourceCommand = [
                'actor_id' => $command['actor_id'],
                'account' => $command['source_account'],
                'leave_request_id' => null,
                'request_day_id' => null,
                'movement_type' => 'carry',
                'source_type' => 'leave_balance_account',
                'source_id' => $target['id'],
                'logical_key' => $command['out_logical_key'],
                'idempotency_key' => $command['out_idempotency_key'],
                'movement_hash' => $command['out_movement_hash'],
                'reason_code' => $command['reason_code'],
            ];
            $targetCommand = [
                'actor_id' => $command['actor_id'],
                'account' => $command['target_account'],
                'leave_request_id' => null,
                'request_day_id' => null,
                'movement_type' => 'carry',
                'source_type' => 'leave_balance_account',
                'source_id' => $source['id'],
                'logical_key' => $command['in_logical_key'],
                'idempotency_key' => $command['in_idempotency_key'],
                'movement_hash' => $command['in_movement_hash'],
                'reason_code' => $command['reason_code'],
            ];
            $sourceMovement = $this->movementRecord($sourceAfter, $sourceCommand, $sourceDeltas, null);
            $sourceMovementId = $this->repository->insertMovement($sourceMovement);
            $targetMovement = $this->movementRecord($targetAfter, $targetCommand, $targetDeltas, null);
            $targetMovementId = $this->repository->insertMovement($targetMovement);
            if ($sourceMovementId <= 0 || $targetMovementId <= 0) {
                throw new DomainException('LEAVE_BALANCE_MOVEMENT_PERSIST_FAILED');
            }
            $sourceMovement['id'] = $sourceMovementId;
            $targetMovement['id'] = $targetMovementId;
            $this->auditMovement($sourceMovement, $sourceAfter, $sourceCommand, $sourceDeltas, null);
            $this->auditMovement($targetMovement, $targetAfter, $targetCommand, $targetDeltas, null);

            return [
                'replayed' => false,
                'movement_type' => 'carry',
                'source_movement_id' => $sourceMovementId,
                'target_movement_id' => $targetMovementId,
                'source_account' => $this->accountView($sourceAfter),
                'target_account' => $this->accountView($targetAfter),
            ];
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function reverse(array $command): array
    {
        $command = $this->normalizeReverseCommand($command);

        return $this->repository->transactional(function () use ($command): array {
            $existing = $this->repository->movementByIdempotencyForUpdate($command['idempotency_key']);
            if ($existing !== null) {
                return $this->replayedReceipt($existing, $command['movement_hash']);
            }

            $original = $this->repository->movementByIdForUpdate($command['reverses_movement_id']);
            if ($original === null) {
                throw new DomainException('LEAVE_BALANCE_MOVEMENT_NOT_FOUND');
            }
            if ((string) ($original['movement_type'] ?? '') === 'reverse') {
                throw new DomainException('LEAVE_BALANCE_REVERSE_OF_REVERSE_NOT_ALLOWED');
            }
            if ((string) ($original['movement_type'] ?? '') === 'carry') {
                throw new DomainException('LEAVE_BALANCE_CARRY_REVERSE_MUST_TRANSFER');
            }
            if ($this->repository->reversalForMovementForUpdate((int) $original['id']) !== null) {
                throw new DomainException('LEAVE_BALANCE_REVERSAL_ALREADY_RECORDED');
            }
            $account = $this->repository->accountByIdForUpdate((int) ($original['account_id'] ?? 0));
            if ($account === null) {
                throw new DomainException('LEAVE_BALANCE_ACCOUNT_NOT_FOUND');
            }
            $account = $this->normalizedAccount($account);
            $deltas = [
                'units_milli' => -$this->signedUnits($original['units_delta'] ?? null, 'LEAVE_BALANCE_MOVEMENT_INVALID'),
                'available_milli' => -$this->signedUnits($original['available_delta'] ?? null, 'LEAVE_BALANCE_MOVEMENT_INVALID'),
                'reserved_milli' => -$this->signedUnits($original['reserved_delta'] ?? null, 'LEAVE_BALANCE_MOVEMENT_INVALID'),
                'consumed_milli' => -$this->signedUnits($original['consumed_delta'] ?? null, 'LEAVE_BALANCE_MOVEMENT_INVALID'),
                'granted_milli' => $this->reverseGrantedDelta($original),
                'expired_milli' => $this->reverseExpiredDelta($original),
            ];
            $after = $this->applyDeltas($account, $deltas);
            $after = $this->persistAccount($after, (int) $account['lock_version']);

            $movement = [
                'account_id' => $after['id'],
                'leave_request_id' => $this->nullablePositiveId($original['leave_request_id'] ?? null, 'LEAVE_BALANCE_MOVEMENT_INVALID'),
                'request_day_id' => $this->nullablePositiveId($original['request_day_id'] ?? null, 'LEAVE_BALANCE_MOVEMENT_INVALID'),
                'movement_type' => 'reverse',
                'units_delta' => LeaveUnits::format($deltas['units_milli']),
                'available_delta' => LeaveUnits::format($deltas['available_milli']),
                'reserved_delta' => LeaveUnits::format($deltas['reserved_milli']),
                'consumed_delta' => LeaveUnits::format($deltas['consumed_milli']),
                'source_type' => 'leave_balance_reversal',
                'source_id' => (int) $original['id'],
                'logical_key' => $command['logical_key'],
                'reverses_movement_id' => (int) $original['id'],
                'idempotency_key' => $command['idempotency_key'],
                'movement_hash' => $command['movement_hash'],
                'reason_code' => $command['reason_code'],
                'created_by' => $command['actor_id'],
            ];
            $movementId = $this->repository->insertMovement($movement);
            if ($movementId <= 0) {
                throw new DomainException('LEAVE_BALANCE_MOVEMENT_PERSIST_FAILED');
            }
            $movement['id'] = $movementId;
            $this->auditMovement($movement, $after, $command, $deltas, (int) $original['id']);

            return $this->receipt($movement, $after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function normalizeRecordCommand(array $command): array
    {
        $movementType = strtolower(trim((string) ($command['movement_type'] ?? '')));
        if (!in_array($movementType, self::DIRECT_MOVEMENT_TYPES, true)) {
            throw new InvalidArgumentException(
                $movementType === 'carry'
                    ? 'LEAVE_BALANCE_CARRY_MUST_TRANSFER'
                    : 'LEAVE_BALANCE_MOVEMENT_TYPE_INVALID'
            );
        }
        $unitsMilli = $this->signedUnits(
            $command['units'] ?? $command['units_delta'] ?? null,
            'LEAVE_BALANCE_UNITS_INVALID'
        );
        if ($movementType === 'adjust') {
            if ($unitsMilli === 0) {
                throw new InvalidArgumentException('LEAVE_BALANCE_UNITS_INVALID');
            }
        } elseif ($unitsMilli <= 0) {
            throw new InvalidArgumentException('LEAVE_BALANCE_UNITS_INVALID');
        }
        $reasonCode = $this->nullableText($command['reason_code'] ?? null, 100, 'LEAVE_BALANCE_REASON_INVALID');
        if ($movementType === 'adjust' && $reasonCode === null) {
            throw new InvalidArgumentException('LEAVE_BALANCE_ADJUST_REASON_REQUIRED');
        }
        if ($movementType === 'restore' && $reasonCode === null) {
            throw new InvalidArgumentException('LEAVE_BALANCE_RESTORE_REASON_REQUIRED');
        }

        $normalized = [
            'actor_id' => $this->positiveId($command['actor_id'] ?? null, 'LEAVE_BALANCE_ACTOR_INVALID'),
            'account' => $this->normalizeAccountIdentity((array) ($command['account'] ?? $command)),
            'leave_request_id' => $this->nullablePositiveId(
                $command['leave_request_id'] ?? null,
                'LEAVE_BALANCE_REQUEST_INVALID'
            ),
            'request_day_id' => $this->nullablePositiveId(
                $command['request_day_id'] ?? null,
                'LEAVE_BALANCE_REQUEST_DAY_INVALID'
            ),
            'movement_type' => $movementType,
            'units_milli' => $unitsMilli,
            'source_type' => $this->requiredText($command['source_type'] ?? null, 80, 'LEAVE_BALANCE_SOURCE_TYPE_INVALID'),
            'source_id' => $this->nullablePositiveId($command['source_id'] ?? null, 'LEAVE_BALANCE_SOURCE_ID_INVALID'),
            'logical_key' => $this->hashKey($command['logical_key'] ?? null, 'LEAVE_BALANCE_LOGICAL_KEY_INVALID'),
            'idempotency_key' => $this->idempotencyKey($command['idempotency_key'] ?? null, 190),
            'reason_code' => $reasonCode,
        ];
        if ($normalized['request_day_id'] !== null && $normalized['leave_request_id'] === null) {
            throw new InvalidArgumentException('LEAVE_BALANCE_REQUEST_DAY_REQUIRES_REQUEST');
        }
        $normalized['movement_hash'] = $this->movementHash([
            'account' => $normalized['account'],
            'leave_request_id' => $normalized['leave_request_id'],
            'request_day_id' => $normalized['request_day_id'],
            'movement_type' => $normalized['movement_type'],
            'units_milli' => $normalized['units_milli'],
            'source_type' => $normalized['source_type'],
            'source_id' => $normalized['source_id'],
            'logical_key' => $normalized['logical_key'],
            'reason_code' => $normalized['reason_code'],
        ]);

        return $normalized;
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function normalizeCarryCommand(array $command): array
    {
        $source = $this->normalizeAccountIdentity((array) ($command['source_account'] ?? []));
        $target = $this->normalizeAccountIdentity((array) ($command['target_account'] ?? []));
        if ($this->accountIdentityKey($source) === $this->accountIdentityKey($target)) {
            throw new InvalidArgumentException('LEAVE_BALANCE_CARRY_PERIODS_MUST_DIFFER');
        }
        if ($source['staff_user_id'] !== $target['staff_user_id']
            || $source['leave_type_id'] !== $target['leave_type_id']) {
            throw new InvalidArgumentException('LEAVE_BALANCE_CARRY_OWNER_MISMATCH');
        }
        $unitsMilli = $this->positiveUnits(
            $command['units'] ?? $command['units_delta'] ?? null,
            'LEAVE_BALANCE_UNITS_INVALID'
        );
        $baseIdempotencyKey = $this->idempotencyKey($command['idempotency_key'] ?? null, 176);
        $baseLogicalKey = $this->hashKey($command['logical_key'] ?? null, 'LEAVE_BALANCE_LOGICAL_KEY_INVALID');
        $reasonCode = $this->nullableText($command['reason_code'] ?? null, 100, 'LEAVE_BALANCE_REASON_INVALID');
        $normalized = [
            'actor_id' => $this->positiveId($command['actor_id'] ?? null, 'LEAVE_BALANCE_ACTOR_INVALID'),
            'source_account' => $source,
            'target_account' => $target,
            'units_milli' => $unitsMilli,
            'reason_code' => $reasonCode,
            'out_idempotency_key' => $baseIdempotencyKey . ':carry-out',
            'in_idempotency_key' => $baseIdempotencyKey . ':carry-in',
            'out_logical_key' => hash('sha256', $baseLogicalKey . '|carry-out'),
            'in_logical_key' => hash('sha256', $baseLogicalKey . '|carry-in'),
        ];
        $normalized['out_movement_hash'] = $this->movementHash([
            'direction' => 'carry-out',
            'source_account' => $source,
            'target_account' => $target,
            'units_milli' => -$unitsMilli,
            'logical_key' => $normalized['out_logical_key'],
            'reason_code' => $reasonCode,
        ]);
        $normalized['in_movement_hash'] = $this->movementHash([
            'direction' => 'carry-in',
            'source_account' => $source,
            'target_account' => $target,
            'units_milli' => $unitsMilli,
            'logical_key' => $normalized['in_logical_key'],
            'reason_code' => $reasonCode,
        ]);

        return $normalized;
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function normalizeReverseCommand(array $command): array
    {
        $normalized = [
            'actor_id' => $this->positiveId($command['actor_id'] ?? null, 'LEAVE_BALANCE_ACTOR_INVALID'),
            'reverses_movement_id' => $this->positiveId(
                $command['reverses_movement_id'] ?? null,
                'LEAVE_BALANCE_MOVEMENT_ID_INVALID'
            ),
            'logical_key' => $this->hashKey($command['logical_key'] ?? null, 'LEAVE_BALANCE_LOGICAL_KEY_INVALID'),
            'idempotency_key' => $this->idempotencyKey($command['idempotency_key'] ?? null, 190),
            'reason_code' => $this->requiredText(
                $command['reason_code'] ?? null,
                100,
                'LEAVE_BALANCE_REVERSE_REASON_REQUIRED'
            ),
        ];
        $normalized['movement_hash'] = $this->movementHash([
            'reverses_movement_id' => $normalized['reverses_movement_id'],
            'logical_key' => $normalized['logical_key'],
            'reason_code' => $normalized['reason_code'],
        ]);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $command
     * @return array{0:array<string,mixed>,1:array<string,int>}
     */
    private function applyDirectMovement(array $account, array $command): array
    {
        $amount = $command['units_milli'];
        $deltas = match ($command['movement_type']) {
            'grant', 'accrue' => [
                'units_milli' => $amount,
                'available_milli' => $amount,
                'reserved_milli' => 0,
                'consumed_milli' => 0,
                'granted_milli' => $amount,
                'expired_milli' => 0,
            ],
            'reserve' => [
                'units_milli' => 0,
                'available_milli' => -$amount,
                'reserved_milli' => $amount,
                'consumed_milli' => 0,
                'granted_milli' => 0,
                'expired_milli' => 0,
            ],
            'consume' => [
                'units_milli' => 0,
                'available_milli' => 0,
                'reserved_milli' => -$amount,
                'consumed_milli' => $amount,
                'granted_milli' => 0,
                'expired_milli' => 0,
            ],
            'release' => [
                'units_milli' => 0,
                'available_milli' => $amount,
                'reserved_milli' => -$amount,
                'consumed_milli' => 0,
                'granted_milli' => 0,
                'expired_milli' => 0,
            ],
            'restore' => [
                'units_milli' => 0,
                'available_milli' => $amount,
                'reserved_milli' => 0,
                'consumed_milli' => -$amount,
                'granted_milli' => 0,
                'expired_milli' => 0,
            ],
            'expire' => [
                'units_milli' => -$amount,
                'available_milli' => -$amount,
                'reserved_milli' => 0,
                'consumed_milli' => 0,
                'granted_milli' => 0,
                'expired_milli' => $amount,
            ],
            'adjust' => [
                'units_milli' => $amount,
                'available_milli' => $amount,
                'reserved_milli' => 0,
                'consumed_milli' => 0,
                'granted_milli' => 0,
                'expired_milli' => 0,
            ],
            default => throw new DomainException('LEAVE_BALANCE_MOVEMENT_TYPE_INVALID'),
        };

        return [$this->applyDeltas($account, $deltas), $deltas];
    }

    /** @param array<string,mixed> $account @param array<string,int> $deltas @return array<string,mixed> */
    private function applyDeltas(array $account, array $deltas): array
    {
        if ($deltas['units_milli'] !== $deltas['available_milli']
            + $deltas['reserved_milli']
            + $deltas['consumed_milli']) {
            throw new DomainException('LEAVE_BALANCE_MOVEMENT_INVARIANT_INVALID');
        }
        $after = $account;
        foreach (['available_milli', 'reserved_milli', 'consumed_milli', 'granted_milli', 'expired_milli'] as $field) {
            $after[$field] = (int) $account[$field] + (int) $deltas[$field];
        }
        $this->assertAccountCounters($after);

        return $after;
    }

    /** @param array<string,mixed> $account */
    private function assertAccountCounters(array $account): void
    {
        if ((string) $account['status'] !== 'open') {
            throw new DomainException('LEAVE_BALANCE_ACCOUNT_CLOSED');
        }
        foreach (['reserved_milli', 'consumed_milli', 'granted_milli', 'expired_milli'] as $field) {
            if ((int) $account[$field] < 0) {
                throw new DomainException('LEAVE_BALANCE_COUNTER_NEGATIVE');
            }
        }
        $minimum = -(int) $account['negative_balance_limit_milli'];
        if ((int) $account['available_milli'] < $minimum) {
            if ((int) $account['negative_balance_limit_milli'] === 0) {
                throw new DomainException('LEAVE_BALANCE_NEGATIVE_NOT_ALLOWED');
            }
            throw new DomainException('LEAVE_BALANCE_NEGATIVE_LIMIT_EXCEEDED');
        }
    }

    /** @param array<string,mixed> $account */
    /** @param array<string,mixed> $account @return array<string,mixed> */
    private function persistAccount(array $account, int $expectedLockVersion): array
    {
        if (!$this->repository->updateAccount($this->databaseAccount($account), $expectedLockVersion)) {
            throw new DomainException('LEAVE_BALANCE_ACCOUNT_STALE');
        }
        $account['lock_version'] = $expectedLockVersion + 1;

        return $account;
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $sourceAfter
     * @param array<string,mixed> $target
     * @param array<string,mixed> $targetAfter
     */
    private function persistCarryAccounts(
        array &$source,
        array &$sourceAfter,
        array &$target,
        array &$targetAfter
    ): void {
        $updates = [
            ['before' => &$source, 'after' => &$sourceAfter],
            ['before' => &$target, 'after' => &$targetAfter],
        ];
        usort($updates, static fn (array $left, array $right): int => $left['before']['id'] <=> $right['before']['id']);
        foreach ($updates as $update) {
            $before = $update['before'];
            $after = $update['after'];
            if (!$this->repository->updateAccount($this->databaseAccount($after), (int) $before['lock_version'])) {
                throw new DomainException('LEAVE_BALANCE_ACCOUNT_STALE');
            }
            $after['lock_version'] = (int) $before['lock_version'] + 1;
            if ((int) $sourceAfter['id'] === (int) $after['id']) {
                $sourceAfter = $after;
            } else {
                $targetAfter = $after;
            }
        }
    }

    /**
     * @param array<string,mixed> $sourceIdentity
     * @param array<string,mixed> $targetIdentity
     * @return array{source:array<string,mixed>,target:array<string,mixed>}
     */
    private function lockCarryAccounts(array $sourceIdentity, array $targetIdentity): array
    {
        $identities = [
            'source' => $sourceIdentity,
            'target' => $targetIdentity,
        ];
        uasort($identities, fn (array $left, array $right): int => strcmp(
            $this->accountIdentityKey($left),
            $this->accountIdentityKey($right)
        ));
        $locked = [];
        foreach ($identities as $kind => $identity) {
            $account = $kind === 'source'
                ? $this->repository->accountForUpdate($identity)
                : $this->repository->ensureAccountForUpdate($identity);
            if ($account === null) {
                throw new DomainException('LEAVE_BALANCE_CARRY_SOURCE_ACCOUNT_NOT_FOUND');
            }
            $account = $this->normalizedAccount($account);
            $this->assertAccountIdentity($account, $identity);
            $locked[$kind] = $account;
        }

        return ['source' => $locked['source'], 'target' => $locked['target']];
    }

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $command
     * @param array<string,int> $deltas
     * @return array<string,mixed>
     */
    private function movementRecord(array $account, array $command, array $deltas, ?int $reversesMovementId): array
    {
        return [
            'account_id' => (int) $account['id'],
            'leave_request_id' => $command['leave_request_id'],
            'request_day_id' => $command['request_day_id'],
            'movement_type' => $command['movement_type'],
            'units_delta' => LeaveUnits::format($deltas['units_milli']),
            'available_delta' => LeaveUnits::format($deltas['available_milli']),
            'reserved_delta' => LeaveUnits::format($deltas['reserved_milli']),
            'consumed_delta' => LeaveUnits::format($deltas['consumed_milli']),
            'source_type' => $command['source_type'],
            'source_id' => $command['source_id'],
            'logical_key' => $command['logical_key'],
            'reverses_movement_id' => $reversesMovementId,
            'idempotency_key' => $command['idempotency_key'],
            'movement_hash' => $command['movement_hash'],
            'reason_code' => $command['reason_code'],
            'created_by' => $command['actor_id'],
        ];
    }

    /**
     * @param array<string,mixed> $movement
     * @param array<string,mixed> $account
     * @param array<string,mixed> $command
     * @param array<string,int> $deltas
     */
    private function auditMovement(
        array $movement,
        array $account,
        array $command,
        array $deltas,
        ?int $reversesMovementId
    ): void {
        $this->audit->recordEvent(
            'staff_leave_balance_movement_recorded',
            'staff_leave_balance_movements',
            (int) $movement['id'],
            null,
            [
                'account_id' => (int) $account['id'],
                'staff_user_id' => (int) $account['staff_user_id'],
                'leave_type_id' => (int) $account['leave_type_id'],
                'entitlement_period_key' => (string) $account['entitlement_period_key'],
                'movement_type' => (string) $movement['movement_type'],
                'units_delta' => LeaveUnits::format($deltas['units_milli']),
                'available_delta' => LeaveUnits::format($deltas['available_milli']),
                'reserved_delta' => LeaveUnits::format($deltas['reserved_milli']),
                'consumed_delta' => LeaveUnits::format($deltas['consumed_milli']),
                'reverses_movement_id' => $reversesMovementId,
                'logical_key_hash' => hash('sha256', (string) $movement['logical_key']),
                'idempotency_hash' => hash('sha256', (string) $movement['idempotency_key']),
                'before' => $this->accountView($this->beforeAccount($account, $deltas)),
                'after' => $this->accountView($account),
            ],
            ['user_id' => (int) $command['actor_id']]
        );
    }

    /** @param array<string,mixed> $after @param array<string,int> $deltas @return array<string,mixed> */
    private function beforeAccount(array $after, array $deltas): array
    {
        $before = $after;
        foreach (['available_milli', 'reserved_milli', 'consumed_milli', 'granted_milli', 'expired_milli'] as $field) {
            $before[$field] = (int) $after[$field] - (int) $deltas[$field];
        }
        $before['lock_version'] = max(1, (int) $after['lock_version'] - 1);

        return $before;
    }

    /** @param array<string,mixed> $movement @return array<string,mixed> */
    private function replayedReceipt(array $movement, string $expectedHash): array
    {
        $this->assertMovementHash($movement, $expectedHash);

        return [
            'replayed' => true,
            'movement_id' => (int) ($movement['id'] ?? 0),
            'account_id' => (int) ($movement['account_id'] ?? 0),
            'movement_type' => (string) ($movement['movement_type'] ?? ''),
            'units_delta' => (string) ($movement['units_delta'] ?? '0.000'),
        ];
    }

    /** @param array<string,mixed> $movement @param array<string,mixed> $account @return array<string,mixed> */
    private function receipt(array $movement, array $account, bool $replayed): array
    {
        return [
            'replayed' => $replayed,
            'movement_id' => (int) $movement['id'],
            'account_id' => (int) $account['id'],
            'movement_type' => (string) $movement['movement_type'],
            'units_delta' => (string) $movement['units_delta'],
            'account' => $this->accountView($account),
        ];
    }

    /** @param array<string,mixed> $movement */
    private function assertMovementHash(array $movement, string $expectedHash): void
    {
        if (!hash_equals((string) ($movement['movement_hash'] ?? ''), $expectedHash)) {
            throw new DomainException('LEAVE_BALANCE_IDEMPOTENCY_CONFLICT');
        }
    }

    /** @param array<string,mixed> $original */
    private function reverseGrantedDelta(array $original): int
    {
        $amount = abs($this->signedUnits($original['units_delta'] ?? null, 'LEAVE_BALANCE_MOVEMENT_INVALID'));

        $type = (string) ($original['movement_type'] ?? '');
        $isCarryIn = $type === 'carry'
            && $this->signedUnits($original['units_delta'] ?? null, 'LEAVE_BALANCE_MOVEMENT_INVALID') > 0;

        return (in_array($type, ['grant', 'accrue'], true) || $isCarryIn)
            ? -$amount
            : 0;
    }

    /** @param array<string,mixed> $original */
    private function reverseExpiredDelta(array $original): int
    {
        $amount = abs($this->signedUnits($original['units_delta'] ?? null, 'LEAVE_BALANCE_MOVEMENT_INVALID'));

        return (string) ($original['movement_type'] ?? '') === 'expire' ? -$amount : 0;
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalizedAccount(array $raw): array
    {
        $account = [
            'id' => $this->positiveId($raw['id'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'staff_user_id' => $this->positiveId($raw['staff_user_id'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'leave_type_id' => $this->positiveId($raw['leave_type_id'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'entitlement_period_key' => $this->requiredText(
                $raw['entitlement_period_key'] ?? null,
                80,
                'LEAVE_BALANCE_ACCOUNT_INVALID'
            ),
            'period_from' => $this->dateKey($raw['period_from'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'period_to' => $this->dateKey($raw['period_to'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'status' => (string) ($raw['status'] ?? ''),
            'available_milli' => $this->signedUnits($raw['available_units'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'reserved_milli' => $this->signedUnits($raw['reserved_units'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'consumed_milli' => $this->signedUnits($raw['consumed_units'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'granted_milli' => $this->signedUnits($raw['granted_units'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'expired_milli' => $this->signedUnits($raw['expired_units'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
            'negative_balance_limit_milli' => $this->positiveOrZeroUnits(
                $raw['negative_balance_limit_units'] ?? null,
                'LEAVE_BALANCE_ACCOUNT_INVALID'
            ),
            'lock_version' => $this->positiveId($raw['lock_version'] ?? null, 'LEAVE_BALANCE_ACCOUNT_INVALID'),
        ];
        if ($account['period_to'] < $account['period_from']) {
            throw new DomainException('LEAVE_BALANCE_ACCOUNT_INVALID');
        }
        $this->assertAccountCounters($account);

        return $account;
    }

    /** @param array<string,mixed> $account @return array<string,mixed> */
    private function databaseAccount(array $account): array
    {
        return [
            'id' => $account['id'],
            'available_units' => LeaveUnits::format($account['available_milli']),
            'reserved_units' => LeaveUnits::format($account['reserved_milli']),
            'consumed_units' => LeaveUnits::format($account['consumed_milli']),
            'granted_units' => LeaveUnits::format($account['granted_milli']),
            'expired_units' => LeaveUnits::format($account['expired_milli']),
        ];
    }

    /** @param array<string,mixed> $account @return array<string,mixed> */
    private function accountView(array $account): array
    {
        return [
            'id' => (int) $account['id'],
            'staff_user_id' => (int) $account['staff_user_id'],
            'leave_type_id' => (int) $account['leave_type_id'],
            'entitlement_period_key' => (string) $account['entitlement_period_key'],
            'period_from' => (string) $account['period_from'],
            'period_to' => (string) $account['period_to'],
            'available_units' => LeaveUnits::format((int) $account['available_milli']),
            'reserved_units' => LeaveUnits::format((int) $account['reserved_milli']),
            'consumed_units' => LeaveUnits::format((int) $account['consumed_milli']),
            'granted_units' => LeaveUnits::format((int) $account['granted_milli']),
            'expired_units' => LeaveUnits::format((int) $account['expired_milli']),
            'negative_balance_limit_units' => LeaveUnits::format((int) $account['negative_balance_limit_milli']),
            'lock_version' => (int) $account['lock_version'],
        ];
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $identity */
    private function assertAccountIdentity(array $account, array $identity): void
    {
        if ((int) $account['staff_user_id'] !== (int) $identity['staff_user_id']
            || (int) $account['leave_type_id'] !== (int) $identity['leave_type_id']
            || (string) $account['entitlement_period_key'] !== (string) $identity['entitlement_period_key']
            || (string) $account['period_from'] !== (string) $identity['period_from']
            || (string) $account['period_to'] !== (string) $identity['period_to']
            || (int) $account['negative_balance_limit_milli']
                !== $this->positiveOrZeroUnits($identity['negative_balance_limit_units'], 'LEAVE_BALANCE_ACCOUNT_IDENTITY_CONFLICT')) {
            throw new DomainException('LEAVE_BALANCE_ACCOUNT_IDENTITY_CONFLICT');
        }
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalizeAccountIdentity(array $raw): array
    {
        $identity = [
            'staff_user_id' => $this->positiveId($raw['staff_user_id'] ?? null, 'LEAVE_BALANCE_STAFF_INVALID'),
            'leave_type_id' => $this->positiveId($raw['leave_type_id'] ?? null, 'LEAVE_BALANCE_TYPE_INVALID'),
            'entitlement_period_key' => $this->requiredText(
                $raw['entitlement_period_key'] ?? null,
                80,
                'LEAVE_BALANCE_PERIOD_KEY_INVALID'
            ),
            'period_from' => $this->dateKey($raw['period_from'] ?? null, 'LEAVE_BALANCE_PERIOD_INVALID'),
            'period_to' => $this->dateKey($raw['period_to'] ?? null, 'LEAVE_BALANCE_PERIOD_INVALID'),
            'negative_balance_limit_units' => LeaveUnits::format($this->positiveOrZeroUnits(
                $raw['negative_balance_limit_units'] ?? '0.000',
                'LEAVE_BALANCE_NEGATIVE_LIMIT_INVALID'
            )),
        ];
        if ($identity['period_to'] < $identity['period_from']) {
            throw new InvalidArgumentException('LEAVE_BALANCE_PERIOD_INVALID');
        }

        return $identity;
    }

    /** @param array<string,mixed> $identity */
    private function accountIdentityKey(array $identity): string
    {
        return implode('|', [
            str_pad((string) $identity['staff_user_id'], 12, '0', STR_PAD_LEFT),
            str_pad((string) $identity['leave_type_id'], 12, '0', STR_PAD_LEFT),
            $identity['entitlement_period_key'],
        ]);
    }

    private function movementHash(array $payload): string
    {
        try {
            return hash(
                'sha256',
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (JsonException $exception) {
            throw new DomainException('LEAVE_BALANCE_COMMAND_INVALID', 0, $exception);
        }
    }

    private function positiveUnits(mixed $value, string $error): int
    {
        $units = LeaveUnits::fromDecimal($value, false, $error);
        if ($units <= 0) {
            throw new InvalidArgumentException($error);
        }

        return $units;
    }

    private function positiveOrZeroUnits(mixed $value, string $error): int
    {
        return LeaveUnits::fromDecimal($value, false, $error);
    }

    private function signedUnits(mixed $value, string $error): int
    {
        return LeaveUnits::fromDecimal($value, true, $error);
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException($error);
        }

        return $id;
    }

    private function nullablePositiveId(mixed $value, string $error): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->positiveId($value, $error);
    }

    private function requiredText(mixed $value, int $maxLength, string $error): string
    {
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function nullableText(mixed $value, int $maxLength, string $error): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->requiredText($value, $maxLength, $error);
    }

    private function idempotencyKey(mixed $value, int $maxLength): string
    {
        return $this->requiredText($value, $maxLength, 'LEAVE_BALANCE_IDEMPOTENCY_KEY_INVALID');
    }

    private function hashKey(mixed $value, string $error): string
    {
        $key = strtolower(trim((string) $value));
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return $key;
    }

    private function dateKey(mixed $value, string $error): string
    {
        $date = trim((string) $value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException($error);
        }

        return $date;
    }
}
