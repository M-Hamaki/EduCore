<?php

declare(strict_types=1);

namespace EduCore\Modules\Transport\Infrastructure;

use EduCore\Modules\Transport\Contracts\TransportTransactionManager;
use PDO;
use Throwable;

final class PdoTransportTransactionManager implements TransportTransactionManager
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }
        try {
            $result = $operation();
            if ($owns) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
