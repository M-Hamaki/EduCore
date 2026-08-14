<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Permission;

use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerGateway;
use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Applies permission-quota movements through one locked monthly account.
 *
 * Counter columns are a transactional cache. The immutable movement ledger is
 * the history that explains every reservation, consumption, release, reversal,
 * or signed administrative correction.
 */
final class PermissionQuotaLedger implements PermissionQuotaLedgerGateway
{
    /** @var list<string> */
    private const MOVEMENT_TYPES = ['reserve', 'consume', 'release', 'adjust', 'reverse'];

    public function __construct(
        private PermissionQuotaLedgerRepository $repository,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function record(array $command): array
    {
        $command = $this->normalizeCommand($command);

        return $this->repository->transactional(function () use ($command): array {
            $existing = $this->repository->movementByIdempotencyForUpdate($command['idempotency_key']);
            if ($existing !== null) {
                if (!hash_equals((string) ($existing['movement_hash'] ?? ''), $command['movement_hash'])) {
                    throw new DomainException('PERMISSION_QUOTA_IDEMPOTENCY_CONFLICT');
                }

                return $this->existingReceipt($existing);
            }

            $account = $this->repository->ensureQuotaAccountForUpdate(
                $command['staff_user_id'],
                $command['permission_type_id'],
                $command['period_key']
            );
            if ((string) ($account['status'] ?? '') !== 'open') {
                throw new DomainException('PERMISSION_QUOTA_ACCOUNT_CLOSED');
            }
            $expectedLockVersion = (int) ($account['lock_version'] ?? 0);
            if ($expectedLockVersion <= 0) {
                throw new DomainException('PERMISSION_QUOTA_ACCOUNT_CORRUPT');
            }

            [$updatedAccount, $quotaException] = $this->applyMovement($account, $command);
            if (!$this->repository->updateQuotaAccount($updatedAccount, $expectedLockVersion)) {
                throw new DomainException('PERMISSION_QUOTA_ACCOUNT_STALE');
            }
            $updatedAccount['lock_version'] = $expectedLockVersion + 1;

            $movement = [
                'account_id' => (int) ($account['id'] ?? 0),
                'request_id' => $command['request_id'],
                'request_period_id' => $command['request_period_id'],
                'movement_type' => $command['movement_type'],
                'count_delta' => $command['count_delta'],
                'minutes_delta' => $command['minutes_delta'],
                'quota_exception' => $quotaException ? 1 : 0,
                'idempotency_key' => $command['idempotency_key'],
                'movement_hash' => $command['movement_hash'],
                'reason_code' => $command['reason_code'],
                'created_by' => $command['actor_id'],
            ];
            $movementId = $this->repository->insertMovement($movement);
            if ($movementId <= 0) {
                throw new DomainException('PERMISSION_QUOTA_MOVEMENT_PERSIST_FAILED');
            }
            $movement['id'] = $movementId;

            $this->audit->recordEvent(
                'staff_permission_quota_movement_recorded',
                'staff_permission_quota_movements',
                $movementId,
                null,
                [
                    'staff_user_id' => $command['staff_user_id'],
                    'permission_type_id' => $command['permission_type_id'],
                    'period_key' => $command['period_key'],
                    'request_id' => $command['request_id'],
                    'request_period_id' => $command['request_period_id'],
                    'movement_type' => $command['movement_type'],
                    'count_delta' => $command['count_delta'],
                    'minutes_delta' => $command['minutes_delta'],
                    'quota_exception' => $quotaException,
                    'reason_code' => $command['reason_code'],
                    'idempotency_hash' => hash('sha256', $command['idempotency_key']),
                    'before' => $this->counterSnapshot($account),
                    'after' => $this->counterSnapshot($updatedAccount),
                ],
                ['user_id' => $command['actor_id']]
            );

            return $this->receipt($movement, $updatedAccount, $command['limits'], false);
        });
    }

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $command
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function applyMovement(array $account, array $command): array
    {
        $after = $account;
        foreach (['reserved_count', 'consumed_count', 'reserved_minutes', 'consumed_minutes'] as $field) {
            $after[$field] = (int) ($account[$field] ?? 0);
        }
        $count = $command['count_delta'];
        $minutes = $command['minutes_delta'];
        $movementType = $command['movement_type'];
        $quotaException = false;

        if ($movementType === 'reserve') {
            $after['reserved_count'] += $count;
            $after['reserved_minutes'] += $minutes;
            $quotaException = $this->assertWithinLimits($after, $command);
        } elseif ($movementType === 'consume') {
            if ($after['reserved_count'] < $count || $after['reserved_minutes'] < $minutes) {
                throw new DomainException('PERMISSION_QUOTA_RESERVATION_MISSING');
            }
            $after['reserved_count'] -= $count;
            $after['reserved_minutes'] -= $minutes;
            $after['consumed_count'] += $count;
            $after['consumed_minutes'] += $minutes;
        } elseif ($movementType === 'release') {
            if ($after['reserved_count'] < $count || $after['reserved_minutes'] < $minutes) {
                throw new DomainException('PERMISSION_QUOTA_RESERVATION_MISSING');
            }
            $after['reserved_count'] -= $count;
            $after['reserved_minutes'] -= $minutes;
        } elseif ($movementType === 'reverse') {
            if ($after['consumed_count'] < $count || $after['consumed_minutes'] < $minutes) {
                throw new DomainException('PERMISSION_QUOTA_CONSUMPTION_MISSING');
            }
            $after['consumed_count'] -= $count;
            $after['consumed_minutes'] -= $minutes;
        } else {
            $after['consumed_count'] += $count;
            $after['consumed_minutes'] += $minutes;
            if ($count > 0 || $minutes > 0) {
                $quotaException = $this->assertWithinLimits($after, $command);
            }
        }

        foreach (['reserved_count', 'consumed_count', 'reserved_minutes', 'consumed_minutes'] as $field) {
            if ($after[$field] < 0) {
                throw new DomainException('PERMISSION_QUOTA_COUNTER_NEGATIVE');
            }
        }

        return [$after, $quotaException];
    }

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $command
     */
    private function assertWithinLimits(array $account, array $command): bool
    {
        $limits = $command['limits'];
        $usedRequests = (int) $account['reserved_count'] + (int) $account['consumed_count'];
        $usedMinutes = (int) $account['reserved_minutes'] + (int) $account['consumed_minutes'];
        $requestLimit = $limits['max_requests_per_month'];
        $minuteLimit = $limits['max_minutes_per_month'];
        $requestExcess = $requestLimit === null ? 0 : max(0, $usedRequests - $requestLimit);
        $minuteExcess = $minuteLimit === null ? 0 : max(0, $usedMinutes - $minuteLimit);
        if ($requestExcess === 0 && $minuteExcess === 0) {
            return false;
        }

        if (!$limits['allow_quota_override']) {
            throw new DomainException('PERMISSION_QUOTA_EXCEEDED');
        }
        if (!$limits['override_authorized']) {
            throw new DomainException('PERMISSION_QUOTA_OVERRIDE_NOT_ALLOWED');
        }
        if ($requestExcess > 0) {
            throw new DomainException('PERMISSION_QUOTA_EXCEEDED');
        }
        if ($limits['quota_override_max_minutes'] === null
            || $minuteExcess > $limits['quota_override_max_minutes']) {
            throw new DomainException('PERMISSION_QUOTA_OVERRIDE_LIMIT_EXCEEDED');
        }
        if ($command['reason_code'] === null) {
            throw new DomainException('PERMISSION_QUOTA_OVERRIDE_REASON_REQUIRED');
        }

        return true;
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function normalizeCommand(array $command): array
    {
        $normalized = [
            'actor_id' => $this->positiveId($command['actor_id'] ?? null, 'PERMISSION_QUOTA_ACTOR_INVALID'),
            'staff_user_id' => $this->positiveId($command['staff_user_id'] ?? null, 'PERMISSION_QUOTA_STAFF_INVALID'),
            'permission_type_id' => $this->positiveId(
                $command['permission_type_id'] ?? null,
                'PERMISSION_QUOTA_TYPE_INVALID'
            ),
            'request_id' => $this->positiveId($command['request_id'] ?? null, 'PERMISSION_QUOTA_REQUEST_INVALID'),
            'request_period_id' => $this->positiveId(
                $command['request_period_id'] ?? null,
                'PERMISSION_QUOTA_REQUEST_PERIOD_INVALID'
            ),
            'period_key' => $this->periodKey($command['period_key'] ?? null),
            'movement_type' => $this->movementType($command['movement_type'] ?? null),
            'count_delta' => $this->integer($command['count_delta'] ?? null, 'PERMISSION_QUOTA_COUNT_INVALID'),
            'minutes_delta' => $this->integer($command['minutes_delta'] ?? null, 'PERMISSION_QUOTA_MINUTES_INVALID'),
            'idempotency_key' => $this->requiredText(
                $command['idempotency_key'] ?? null,
                190,
                'PERMISSION_QUOTA_IDEMPOTENCY_KEY_INVALID'
            ),
            'reason_code' => $this->nullableText(
                $command['reason_code'] ?? null,
                100,
                'PERMISSION_QUOTA_REASON_CODE_INVALID'
            ),
            'limits' => $this->normalizeLimits((array) ($command['limits'] ?? [])),
        ];
        $this->assertMovementAmounts(
            $normalized['movement_type'],
            $normalized['count_delta'],
            $normalized['minutes_delta']
        );
        $normalized['movement_hash'] = $this->movementHash($normalized);

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function normalizeLimits(array $limits): array
    {
        $allowOverride = (bool) ($limits['allow_quota_override'] ?? false);

        return [
            'max_requests_per_month' => $this->nullableNonNegativeInt(
                $limits['max_requests_per_month'] ?? null,
                'PERMISSION_QUOTA_REQUEST_LIMIT_INVALID'
            ),
            'max_minutes_per_month' => $this->nullableNonNegativeInt(
                $limits['max_minutes_per_month'] ?? null,
                'PERMISSION_QUOTA_MINUTE_LIMIT_INVALID'
            ),
            'allow_quota_override' => $allowOverride,
            'quota_override_max_minutes' => $this->nullableNonNegativeInt(
                $limits['quota_override_max_minutes'] ?? null,
                'PERMISSION_QUOTA_OVERRIDE_LIMIT_INVALID'
            ),
            'override_authorized' => (bool) ($limits['override_authorized'] ?? false),
        ];
    }

    private function assertMovementAmounts(string $movementType, int $count, int $minutes): void
    {
        if ($movementType === 'adjust') {
            if ($count === 0 && $minutes === 0) {
                throw new InvalidArgumentException('PERMISSION_QUOTA_MOVEMENT_AMOUNT_INVALID');
            }

            return;
        }
        if ($count < 0 || $minutes < 0 || ($count === 0 && $minutes === 0)) {
            throw new InvalidArgumentException('PERMISSION_QUOTA_MOVEMENT_AMOUNT_INVALID');
        }
    }

    /** @param array<string,mixed> $normalized */
    private function movementHash(array $normalized): string
    {
        $payload = [
            'staff_user_id' => $normalized['staff_user_id'],
            'permission_type_id' => $normalized['permission_type_id'],
            'period_key' => $normalized['period_key'],
            'request_id' => $normalized['request_id'],
            'request_period_id' => $normalized['request_period_id'],
            'movement_type' => $normalized['movement_type'],
            'count_delta' => $normalized['count_delta'],
            'minutes_delta' => $normalized['minutes_delta'],
            'reason_code' => $normalized['reason_code'],
            'limits' => $normalized['limits'],
        ];
        try {
            return hash(
                'sha256',
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (JsonException $exception) {
            throw new DomainException('PERMISSION_QUOTA_COMMAND_INVALID', 0, $exception);
        }
    }

    /** @param array<string,mixed> $movement @return array<string,mixed> */
    private function existingReceipt(array $movement): array
    {
        return [
            'replayed' => true,
            'movement_id' => (int) ($movement['id'] ?? 0),
            'account_id' => (int) ($movement['account_id'] ?? 0),
            'request_id' => (int) ($movement['request_id'] ?? 0),
            'request_period_id' => (int) ($movement['request_period_id'] ?? 0),
            'movement_type' => (string) ($movement['movement_type'] ?? ''),
            'count_delta' => (int) ($movement['count_delta'] ?? 0),
            'minutes_delta' => (int) ($movement['minutes_delta'] ?? 0),
            'quota_exception' => (bool) ($movement['quota_exception'] ?? false),
            'available' => ['requests' => null, 'minutes' => null],
        ];
    }

    /**
     * @param array<string,mixed> $movement
     * @param array<string,mixed> $account
     * @param array<string,mixed> $limits
     * @return array<string,mixed>
     */
    private function receipt(array $movement, array $account, array $limits, bool $replayed): array
    {
        return [
            'replayed' => $replayed,
            'movement_id' => (int) $movement['id'],
            'account_id' => (int) $movement['account_id'],
            'request_id' => (int) $movement['request_id'],
            'request_period_id' => (int) $movement['request_period_id'],
            'movement_type' => (string) $movement['movement_type'],
            'count_delta' => (int) $movement['count_delta'],
            'minutes_delta' => (int) $movement['minutes_delta'],
            'quota_exception' => (bool) $movement['quota_exception'],
            'available' => [
                'requests' => $this->available(
                    $limits['max_requests_per_month'],
                    (int) $account['reserved_count'] + (int) $account['consumed_count']
                ),
                'minutes' => $this->available(
                    $limits['max_minutes_per_month'],
                    (int) $account['reserved_minutes'] + (int) $account['consumed_minutes']
                ),
            ],
        ];
    }

    /** @param array<string,mixed> $account @return array<string,int> */
    private function counterSnapshot(array $account): array
    {
        return [
            'reserved_count' => (int) ($account['reserved_count'] ?? 0),
            'consumed_count' => (int) ($account['consumed_count'] ?? 0),
            'reserved_minutes' => (int) ($account['reserved_minutes'] ?? 0),
            'consumed_minutes' => (int) ($account['consumed_minutes'] ?? 0),
        ];
    }

    private function available(?int $limit, int $used): ?int
    {
        return $limit === null ? null : max(0, $limit - $used);
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $id;
    }

    private function integer(mixed $value, string $error): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new InvalidArgumentException($error);
    }

    private function nullableNonNegativeInt(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $integer = $this->integer($value, $error);
        if ($integer < 0) {
            throw new InvalidArgumentException($error);
        }

        return $integer;
    }

    private function periodKey(mixed $value): string
    {
        $period = trim((string) $value);
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException('PERMISSION_QUOTA_PERIOD_INVALID');
        }

        return $period;
    }

    private function movementType(mixed $value): string
    {
        $type = trim((string) $value);
        if (!in_array($type, self::MOVEMENT_TYPES, true)) {
            throw new InvalidArgumentException('PERMISSION_QUOTA_MOVEMENT_TYPE_INVALID');
        }

        return $type;
    }

    private function requiredText(mixed $value, int $maxLength, string $error): string
    {
        $text = $this->nullableText($value, $maxLength, $error);
        if ($text === null) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function nullableText(mixed $value, int $maxLength, string $error): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }
}
