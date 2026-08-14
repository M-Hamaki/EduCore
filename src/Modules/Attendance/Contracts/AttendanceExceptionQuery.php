<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

/**
 * Read-only, presentation-safe view of attendance evidence that requires
 * human attention. Implementations must not expose biometric identifiers,
 * raw payload locations, or private attachments.
 */
interface AttendanceExceptionQuery
{
    /**
     * @param array{date_from:string,date_to:string,staff_user_id:?int,category:string,limit:int} $filters
     * @return array{raw_events:int,unresolved_days:int,comparison_differences:int}
     */
    public function summary(array $filters): array;

    /**
     * @param array{date_from:string,date_to:string,staff_user_id:?int,category:string,limit:int} $filters
     * @return list<array<string,mixed>>
     */
    public function listItems(array $filters): array;
}
