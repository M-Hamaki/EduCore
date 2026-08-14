<?php

declare(strict_types=1);

namespace EduCore\Modules\Transport\Contracts;

interface TransportTransactionManager
{
    public function transactional(callable $operation): mixed;
}
