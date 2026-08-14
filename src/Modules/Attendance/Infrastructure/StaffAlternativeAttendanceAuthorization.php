<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceAuthorization;
use EduCore\Modules\Staff\Contracts\StaffAccessEligibilityQuery;

/**
 * Adapts Attendance entry-method scopes to the Staff-owned current-access
 * query. The scope is a fixed entry-method configuration value, never input
 * from the request.
 */
final class StaffAlternativeAttendanceAuthorization implements AlternativeAttendanceAuthorization
{
    public function __construct(private StaffAccessEligibilityQuery $access)
    {
    }

    public function assertCanRecord(
        int $actorId,
        int $staffUserId,
        string $allowedScope,
        DateTimeImmutable $atInstant
    ): void {
        $this->assertAccess($actorId, $staffUserId, $allowedScope, 'record', $atInstant);
    }

    public function assertCanReview(
        int $actorId,
        int $staffUserId,
        string $allowedScope,
        DateTimeImmutable $atInstant
    ): void {
        $this->assertAccess($actorId, $staffUserId, $allowedScope, 'review', $atInstant);
    }

    private function assertAccess(
        int $actorId,
        int $staffUserId,
        string $allowedScope,
        string $action,
        DateTimeImmutable $atInstant
    ): void {
        if ($actorId <= 0 || $staffUserId <= 0) {
            throw new DomainException('ALTERNATIVE_ATTENDANCE_ACCESS_SUBJECT_INVALID');
        }
        $scope = strtolower(trim($allowedScope));
        if (preg_match('/^[a-z0-9_-]{1,50}$/D', $scope) !== 1) {
            throw new DomainException('ALTERNATIVE_ATTENDANCE_METHOD_SCOPE_INVALID');
        }
        $relationshipScope = $scope === 'self_manager'
            ? ($action === 'record' ? 'self' : 'manager')
            : $scope;
        $result = $this->access->assertCurrentAccess(
            $actorId,
            'attendance.alternative.' . $action . '.' . $relationshipScope,
            'attendance:alternative:staff:' . $staffUserId,
            $atInstant
        );
        if (($result['allowed'] ?? false) !== true) {
            throw new DomainException(
                $action === 'record'
                    ? 'ALTERNATIVE_ATTENDANCE_RECORD_NOT_AUTHORIZED'
                    : 'ALTERNATIVE_ATTENDANCE_REVIEW_NOT_AUTHORIZED'
            );
        }
    }
}
