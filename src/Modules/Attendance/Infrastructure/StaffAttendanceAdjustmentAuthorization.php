<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendanceAdjustmentAuthorization;
use EduCore\Modules\Staff\Contracts\StaffAccessEligibilityQuery;

/** Maps correction actions to Staff-owned current-access capability checks. */
final class StaffAttendanceAdjustmentAuthorization implements AttendanceAdjustmentAuthorization
{
    /** @var list<string> */
    private const REQUESTER_KINDS = ['self', 'manager', 'hr'];

    /** @var list<string> */
    private const ACTIONS = ['request', 'submit', 'cancel', 'decide'];

    public function __construct(private StaffAccessEligibilityQuery $access)
    {
    }

    public function assertCanAct(
        int $actorId,
        int $staffUserId,
        string $requesterKind,
        string $action,
        ?int $workflowInstanceId,
        DateTimeImmutable $atInstant
    ): void {
        if ($actorId <= 0 || $staffUserId <= 0
            || !in_array($requesterKind, self::REQUESTER_KINDS, true)
            || !in_array($action, self::ACTIONS, true)) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_ACCESS_SUBJECT_INVALID');
        }
        if ($requesterKind === 'self' && $action === 'request' && $actorId !== $staffUserId) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_SELF_REQUESTER_MISMATCH');
        }
        if ($workflowInstanceId !== null && $workflowInstanceId <= 0) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_WORKFLOW_INVALID');
        }
        if ($action === 'decide') {
            foreach (['manager', 'hr'] as $reviewerKind) {
                $result = $this->access->assertCurrentAccess(
                    $actorId,
                    'attendance.adjustment.decide.' . $reviewerKind,
                    'attendance:adjustment:staff:' . $staffUserId,
                    $atInstant
                );
                if (($result['allowed'] ?? false) === true) {
                    return;
                }
            }
            throw new DomainException('ATTENDANCE_ADJUSTMENT_NOT_AUTHORIZED');
        }

        $result = $this->access->assertCurrentAccess(
            $actorId,
            'attendance.adjustment.' . $action . '.' . $requesterKind,
            'attendance:adjustment:staff:' . $staffUserId,
            $atInstant
        );
        if (($result['allowed'] ?? false) !== true) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_NOT_AUTHORIZED');
        }
    }
}
