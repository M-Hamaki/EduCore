<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerRepository;
use PDO;
use RuntimeException;

/**
 * PDO persistence for one serialized monthly permission-quota account.
 */
final class PdoPermissionQuotaLedgerRepository implements PermissionQuotaLedgerRepository
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
        $statement = $this->db->prepare(
            'SELECT id, account_id, request_id, request_period_id, movement_type,
                    count_delta, minutes_delta, quota_exception, idempotency_key,
                    movement_hash, reason_code, created_by, created_at
             FROM staff_permission_quota_movements
             WHERE idempotency_key = ?
             FOR UPDATE'
        );
        $statement->execute([$idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function ensureQuotaAccountForUpdate(
        int $staffUserId,
        int $permissionTypeId,
        string $periodKey
    ): array {
        $insert = $this->db->prepare(
            'INSERT INTO staff_permission_quota_accounts
                (staff_user_id, permission_type_id, period_key)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $insert->execute([$staffUserId, $permissionTypeId, $periodKey]);

        $statement = $this->db->prepare(
            'SELECT id, staff_user_id, permission_type_id, period_key, status,
                    reserved_count, consumed_count, reserved_minutes, consumed_minutes,
                    lock_version
             FROM staff_permission_quota_accounts
             WHERE staff_user_id = ? AND permission_type_id = ? AND period_key = ?
             FOR UPDATE'
        );
        $statement->execute([$staffUserId, $permissionTypeId, $periodKey]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            throw new RuntimeException('PERMISSION_QUOTA_ACCOUNT_LOCK_FAILED');
        }

        return $account;
    }

    public function updateQuotaAccount(array $account, int $expectedLockVersion): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_permission_quota_accounts
             SET reserved_count = ?, consumed_count = ?,
                 reserved_minutes = ?, consumed_minutes = ?,
                 lock_version = lock_version + 1
             WHERE id = ? AND status = \'open\' AND lock_version = ?'
        );
        $statement->execute([
            (int) $account['reserved_count'],
            (int) $account['consumed_count'],
            (int) $account['reserved_minutes'],
            (int) $account['consumed_minutes'],
            (int) $account['id'],
            $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function insertMovement(array $movement): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_permission_quota_movements
                (account_id, request_id, request_period_id, movement_type,
                 count_delta, minutes_delta, quota_exception, idempotency_key,
                 movement_hash, reason_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            (int) $movement['account_id'],
            (int) $movement['request_id'],
            (int) $movement['request_period_id'],
            (string) $movement['movement_type'],
            (int) $movement['count_delta'],
            (int) $movement['minutes_delta'],
            (int) $movement['quota_exception'],
            (string) $movement['idempotency_key'],
            (string) $movement['movement_hash'],
            $movement['reason_code'],
            (int) $movement['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
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
