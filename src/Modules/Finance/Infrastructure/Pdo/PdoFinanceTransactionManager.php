<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use PDO;
use Throwable;

final class PdoFinanceTransactionManager implements FinanceTransactionManager
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }
}
