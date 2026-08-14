<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Consumer-owned command boundary from Staff approval to Attendance.
 *
 * The event contains only identifiers, timing/hash evidence, and a stable
 * idempotency key. The Attendance implementation owns period locks, official
 * recalculation, and any closed-period reopen request; Staff never writes an
 * Attendance table directly.
 */
interface AttendanceCoverageChangeGateway
{
    /**
     * @param array{
     *   actor_id:int,
     *   staff_user_id:int,
     *   work_date:string,
     *   event_type:'coverage_approved'|'coverage_reversed',
     *   source_type:string,
     *   source_id:int,
     *   source_fingerprint:string,
     *   reason_code:string,
     *   idempotency_key:string
     * } $event
     * @return array<string,mixed>
     */
    public function publish(array $event): array;
}
