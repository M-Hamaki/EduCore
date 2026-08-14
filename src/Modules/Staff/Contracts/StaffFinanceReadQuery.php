<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/** Staff-owned read boundary used by Finance compatibility adapters. */
interface StaffFinanceReadQuery
{
    /** @return array<string,mixed>|null */
    public function staff(int $staffId): ?array;
}
