<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

/** Read-only catalog used by authenticated Attendance presentation adapters. */
interface AttendanceEntryMethodQuery
{
    /** @return list<array{id:int,code:string,name:string,method_type:string}> */
    public function activeBiometricMethods(): array;
}
