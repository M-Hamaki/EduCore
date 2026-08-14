<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Explicit impact boundary for FR-136.
 *
 * Preview returns identifiers/dates only. Publishing persists exact scoped
 * recalculation/rerouting intents; it never rewrites an official report or
 * another module's history synchronously.
 */
interface StaffOrganizationCorrectionImpactGateway
{
    /**
     * @param array<string,mixed> $candidate
     * @return array{
     *   affected_staff_ids:list<int>,
     *   affected_work_dates:list<string>,
     *   affected_requests:list<array{resource_type:string,resource_id:int}>,
     *   affected_report_periods:list<string>,
     *   warnings:list<string>
     * }
     */
    public function previewImpact(array $candidate, int $limit): array;

    /** @param array<string,mixed> $event @return array{accepted:bool,intent_count:int} */
    public function publishImpact(array $event): array;
}
