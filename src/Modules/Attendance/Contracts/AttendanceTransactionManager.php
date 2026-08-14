<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

interface AttendanceTransactionManager
{
    public function transactional(callable $operation): mixed;
}
