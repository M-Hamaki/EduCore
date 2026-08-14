<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use PDO;
use Throwable;

final class PdoAttendanceTransactionManager implements AttendanceTransactionManager
{
    private PDO $db;
    private int $savepointSequence = 0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        } else {
            $savepoint = 'attendance_' . spl_object_id($this) . '_' . (++$this->savepointSequence);
            $this->db->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                if (!$this->db->inTransaction()) {
                    throw new \RuntimeException('Attendance transaction boundary was lost before commit.');
                }
                $this->db->commit();
            } elseif ($savepoint !== null) {
                if (!$this->db->inTransaction()) {
                    throw new \RuntimeException('Attendance nested transaction boundary was lost.');
                }
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            } elseif ($savepoint !== null && !$this->db->inTransaction()) {
                throw new \RuntimeException(
                    'Attendance nested transaction boundary was lost and cannot be rolled back.',
                    0,
                    $exception
                );
            } elseif ($savepoint !== null) {
                try {
                    $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable $rollbackException) {
                    error_log('Attendance nested transaction rollback failed: ' . $rollbackException->getMessage());
                    if ($this->db->inTransaction()) {
                        try {
                            $this->db->rollBack();
                        } catch (Throwable $outerRollbackException) {
                            error_log('Attendance outer transaction rollback failed: ' . $outerRollbackException->getMessage());
                        }
                    }
                    throw new \RuntimeException(
                        'Attendance nested rollback failed; the outer transaction was aborted.',
                        0,
                        $exception
                    );
                }
            }
            throw $exception;
        }
    }
}
