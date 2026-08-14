<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Organization;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Permission\PermissionRequestService;
use EduCore\Modules\Staff\Contracts\StaffEmploymentLifecycleRepository;
use InvalidArgumentException;

/** Atomic effective-dated transfer/service-end orchestration. */
final class StaffEmploymentLifecycleService
{
    public function __construct(
        private StaffEmploymentLifecycleRepository $organization,
        private PermissionRequestService $permissions,
        private AuditEventWriter $audit
    ) {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function transfer(array $input, int $actorId): array
    {
        $staffId = $this->positiveId($input['staff_user_id'] ?? null, 'STAFF_LIFECYCLE_STAFF_INVALID');
        $orgUnitId = $this->positiveId($input['org_unit_id'] ?? null, 'STAFF_LIFECYCLE_UNIT_INVALID');
        $jobTitleId = $this->positiveId($input['job_title_id'] ?? null, 'STAFF_LIFECYCLE_TITLE_INVALID');
        $effectiveDate = $this->date($input['effective_date'] ?? null);
        $reason = $this->text($input['reason'] ?? null);
        return $this->organization->transactional(function () use ($actorId, $staffId, $orgUnitId, $jobTitleId, $effectiveDate, $reason): array {
            $this->assertActor($actorId);
            if (!$this->organization->orgUnitAvailableForRange($orgUnitId, $effectiveDate, null)
                || !$this->organization->jobTitleAvailableForRange($jobTitleId, $effectiveDate, null)) {
                throw new DomainException('STAFF_ORG_ASSIGNMENT_REFERENCE_UNAVAILABLE');
            }
            $current = $this->organization->currentPrimaryAssignmentForUpdate($staffId, $effectiveDate);
            if ($current === null) throw new DomainException('STAFF_LIFECYCLE_ASSIGNMENT_NOT_FOUND');
            $previousDay = (new DateTimeImmutable($effectiveDate, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
            if (!$this->organization->closeAssignment((int) $current['id'], $previousDay)) {
                throw new DomainException('STAFF_LIFECYCLE_ASSIGNMENT_CLOSE_FAILED');
            }
            $assignmentId = $this->organization->insertAssignment([
                'staff_user_id' => $staffId, 'org_unit_id' => $orgUnitId, 'job_title_id' => $jobTitleId,
                'assignment_kind' => 'primary', 'employment_status' => 'active', 'work_fraction' => $current['work_fraction'],
                'valid_from' => $effectiveDate, 'valid_to' => null, 'source' => 'lifecycle_transfer',
                'source_ref' => 'transfer:' . $current['id'], 'version' => (int) $current['version'] + 1, 'created_by' => $actorId,
            ]);
            $this->audit->recordEvent('staff_employment_transferred', 'staff_assignments', $assignmentId, null, [
                'staff_user_id' => $staffId, 'previous_assignment_id' => (int) $current['id'],
                'effective_date' => $effectiveDate, 'reason_hash' => hash('sha256', $reason),
            ], ['user_id' => $actorId]);
            return ['assignment_id' => $assignmentId, 'previous_assignment_id' => (int) $current['id']];
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function endService(array $input, int $actorId): array
    {
        $staffId = $this->positiveId($input['staff_user_id'] ?? null, 'STAFF_LIFECYCLE_STAFF_INVALID');
        $effectiveDate = $this->date($input['effective_date'] ?? null);
        $reason = $this->text($input['reason'] ?? null);
        return $this->organization->transactional(function () use ($actorId, $staffId, $effectiveDate, $reason): array {
            $this->assertActor($actorId);
            $current = $this->organization->currentPrimaryAssignmentForUpdate($staffId, $effectiveDate);
            if ($current === null) throw new DomainException('STAFF_LIFECYCLE_ASSIGNMENT_NOT_FOUND');
            $previousDay = (new DateTimeImmutable($effectiveDate, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
            if (!$this->organization->closeAssignment((int) $current['id'], $previousDay)) {
                throw new DomainException('STAFF_LIFECYCLE_ASSIGNMENT_CLOSE_FAILED');
            }
            $endedId = $this->organization->insertAssignment([
                'staff_user_id' => $staffId, 'org_unit_id' => (int) $current['org_unit_id'], 'job_title_id' => (int) $current['job_title_id'],
                'assignment_kind' => 'primary', 'employment_status' => 'ended', 'work_fraction' => $current['work_fraction'],
                'valid_from' => $effectiveDate, 'valid_to' => $effectiveDate, 'source' => 'lifecycle_service_end',
                'source_ref' => 'service-end:' . $current['id'], 'version' => (int) $current['version'] + 1, 'created_by' => $actorId,
            ]);
            $permissionResult = $this->permissions->cancelPendingForServiceEnd($actorId, $staffId, $reason);
            $this->audit->recordEvent('staff_employment_service_ended', 'staff_assignments', $endedId, null, [
                'staff_user_id' => $staffId, 'previous_assignment_id' => (int) $current['id'],
                'effective_date' => $effectiveDate, 'cancelled_permission_count' => $permissionResult['cancelled_count'],
                'reason_hash' => hash('sha256', $reason),
            ], ['user_id' => $actorId]);
            return ['assignment_id' => $endedId, 'cancelled_permission_count' => $permissionResult['cancelled_count']];
        });
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0 || !$this->organization->actorCanManageOrganization($actorId)) {
            throw new DomainException('STAFF_ORG_ACTOR_FORBIDDEN');
        }
    }

    private function positiveId(mixed $value, string $code): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) throw new InvalidArgumentException($code);
        return (int) $value;
    }

    private function date(mixed $value): string
    {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('STAFF_LIFECYCLE_DATE_INVALID');
        return $value;
    }

    private function text(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > 2000) throw new InvalidArgumentException('STAFF_LIFECYCLE_REASON_REQUIRED');
        return $value;
    }
}
