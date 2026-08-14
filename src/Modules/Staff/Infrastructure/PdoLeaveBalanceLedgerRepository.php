<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerRepository;
use EduCore\Modules\Staff\Contracts\LeaveBalanceMovementLookup;
use PDO;
use RuntimeException;

/**
 * PDO implementation of the Staff-owned, lock-versioned leave ledger.
 */
final class PdoLeaveBalanceLedgerRepository implements LeaveBalanceLedgerRepository, LeaveBalanceMovementLookup
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $work): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        $attempt = 0;
        do {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            try {
                $result = $work();
                if ($ownsTransaction) {
                    $this->db->commit();
                }

                return $result;
            } catch (\Throwable $exception) {
                if ($ownsTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if (!$ownsTransaction || !$this->isRetryableConcurrencyFailure($exception) || ++$attempt >= 4) {
                    throw $exception;
                }
                usleep(5000 * $attempt);
            }
        } while (true);
    }

    public function movementByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare($this->movementSelect() . ' WHERE idempotency_key = ? FOR UPDATE');
        $statement->execute([$idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function movementByIdForUpdate(int $movementId): ?array
    {
        $statement = $this->db->prepare($this->movementSelect() . ' WHERE id = ? FOR UPDATE');
        $statement->execute([$movementId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function reversalForMovementForUpdate(int $movementId): ?array
    {
        $statement = $this->db->prepare(
            $this->movementSelect() . ' WHERE reverses_movement_id = ? FOR UPDATE'
        );
        $statement->execute([$movementId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function restorationMovementsForSourceForUpdate(int $sourceMovementId): array
    {
        $statement = $this->db->prepare(
            $this->movementSelect()
            . " WHERE movement_type = 'restore'"
            . " AND source_type = 'leave_balance_movement'"
            . ' AND source_id = ? FOR UPDATE'
        );
        $statement->execute([$sourceMovementId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function accountForUpdate(array $identity): ?array
    {
        $statement = $this->db->prepare(
            $this->accountSelect()
            . ' WHERE staff_user_id = ? AND leave_type_id = ? AND entitlement_period_key = ? FOR UPDATE'
        );
        $statement->execute([
            (int) $identity['staff_user_id'],
            (int) $identity['leave_type_id'],
            (string) $identity['entitlement_period_key'],
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function ensureAccountForUpdate(array $identity): array
    {
        $insert = $this->db->prepare(
            'INSERT INTO staff_leave_balance_accounts
                (staff_user_id, leave_type_id, entitlement_period_key, period_from, period_to, negative_balance_limit_units)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $insert->execute([
            (int) $identity['staff_user_id'],
            (int) $identity['leave_type_id'],
            (string) $identity['entitlement_period_key'],
            (string) $identity['period_from'],
            (string) $identity['period_to'],
            (string) $identity['negative_balance_limit_units'],
        ]);

        $account = $this->accountForUpdate($identity);
        if ($account === null) {
            throw new RuntimeException('LEAVE_BALANCE_ACCOUNT_LOCK_FAILED');
        }

        return $account;
    }

    public function accountByIdForUpdate(int $accountId): ?array
    {
        $statement = $this->db->prepare($this->accountSelect() . ' WHERE id = ? FOR UPDATE');
        $statement->execute([$accountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function updateAccount(array $account, int $expectedLockVersion): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_leave_balance_accounts
             SET available_units = ?, reserved_units = ?, consumed_units = ?,
                 granted_units = ?, expired_units = ?, lock_version = lock_version + 1
             WHERE id = ? AND status = \'open\' AND lock_version = ?'
        );
        $statement->execute([
            (string) $account['available_units'],
            (string) $account['reserved_units'],
            (string) $account['consumed_units'],
            (string) $account['granted_units'],
            (string) $account['expired_units'],
            (int) $account['id'],
            $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function insertMovement(array $movement): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_leave_balance_movements
                (account_id, leave_request_id, request_day_id, movement_type,
                 units_delta, available_delta, reserved_delta, consumed_delta,
                 source_type, source_id, logical_key, reverses_movement_id,
                 idempotency_key, movement_hash, reason_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            (int) $movement['account_id'],
            $movement['leave_request_id'],
            $movement['request_day_id'],
            (string) $movement['movement_type'],
            (string) $movement['units_delta'],
            (string) $movement['available_delta'],
            (string) $movement['reserved_delta'],
            (string) $movement['consumed_delta'],
            (string) $movement['source_type'],
            $movement['source_id'],
            (string) $movement['logical_key'],
            $movement['reverses_movement_id'],
            (string) $movement['idempotency_key'],
            (string) $movement['movement_hash'],
            $movement['reason_code'],
            (int) $movement['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function accountSelect(): string
    {
        return 'SELECT id, staff_user_id, leave_type_id, entitlement_period_key,
                       period_from, period_to, status, available_units, reserved_units,
                       consumed_units, granted_units, expired_units,
                       negative_balance_limit_units, lock_version
                FROM staff_leave_balance_accounts';
    }

    private function movementSelect(): string
    {
        return 'SELECT id, account_id, leave_request_id, request_day_id, movement_type,
                       units_delta, available_delta, reserved_delta, consumed_delta,
                       source_type, source_id, logical_key, reverses_movement_id,
                       idempotency_key, movement_hash, reason_code, created_by, created_at
                FROM staff_leave_balance_movements';
    }

    private function isRetryableConcurrencyFailure(\Throwable $exception): bool
    {
        if (!$exception instanceof \PDOException) {
            return false;
        }
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '40001' || in_array($driverCode, [1205, 1213], true);
    }
}
