<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts;

/**
 * Owns the infrastructure transaction boundary used by Finance application
 * services. Nested calls join the current transaction.
 */
interface FinanceTransactionManager
{
    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
