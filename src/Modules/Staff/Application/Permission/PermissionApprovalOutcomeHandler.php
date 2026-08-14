<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Permission;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\ApprovedCoveragePublicationGateway;
use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerGateway;
use EduCore\Modules\Staff\Contracts\PermissionRequestRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Applies a final workflow outcome to a Staff-owned permission request.
 *
 * ApprovalWorkflowService invokes this handler while holding its transaction.
 * The handler locks the request and monthly slices, moves a reservation into
 * consumption (or releases it), and records the resource audit before the
 * approval decision can commit. Approved coverage is then published through
 * a narrow cross-module gateway; the Attendance owner decides whether the
 * affected month is ready or requires an explicit reopen.
 */
final class PermissionApprovalOutcomeHandler implements ApprovalWorkflowOutcomeHandler
{
    private const RESOURCE_TYPE = 'permission_request';

    /** @var list<string> */
    private const OUTCOMES = ['approved', 'rejected'];

    public function __construct(
        private PermissionRequestRepository $requests,
        private PermissionQuotaLedgerGateway $quotaLedger,
        private AuditEventWriter $audit,
        private ApprovedCoveragePublicationGateway $coveragePublisher
    ) {
    }

    /** @param array<string,mixed> $instance */
    public function apply(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void
    {
        if ((string) ($instance['resource_type'] ?? '') !== self::RESOURCE_TYPE) {
            throw new DomainException('APPROVAL_OUTCOME_RESOURCE_UNSUPPORTED');
        }
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('PERMISSION_APPROVAL_OUTCOME_INVALID');
        }
        if ($actorId <= 0) {
            throw new InvalidArgumentException('APPROVAL_ACTOR_INVALID');
        }

        $instanceId = $this->positiveId($instance['id'] ?? $instance['instance_id'] ?? null, 'APPROVAL_INSTANCE_INVALID');
        $requestId = $this->positiveId($instance['resource_id'] ?? null, 'PERMISSION_REQUEST_ID_INVALID');
        $request = $this->requests->requestForUpdate($requestId);
        if ($request === null) {
            throw new DomainException('PERMISSION_REQUEST_NOT_FOUND');
        }
        if ((string) ($request['status'] ?? '') !== 'pending_approval'
            || (int) ($request['workflow_instance_id'] ?? 0) !== $instanceId) {
            throw new DomainException('PERMISSION_APPROVAL_OUTCOME_STALE');
        }

        $staffUserId = $this->positiveId($request['staff_user_id'] ?? null, 'PERMISSION_REQUEST_STAFF_INVALID');
        $permissionTypeId = $this->positiveId(
            $request['permission_type_id'] ?? null,
            'PERMISSION_REQUEST_TYPE_INVALID'
        );
        $expectedLockVersion = $this->positiveId(
            $request['lock_version'] ?? null,
            'PERMISSION_REQUEST_LOCK_INVALID'
        );
        if (!$this->requests->lockStaffForRequest($staffUserId)) {
            throw new DomainException('PERMISSION_REQUEST_STAFF_NOT_FOUND');
        }

        $snapshot = $this->policySnapshot($request['policy_snapshot'] ?? null);
        $policy = $snapshot['policy'];
        $periods = $this->requests->periodsForRequestForUpdate($requestId);
        if ($periods === []) {
            throw new DomainException('PERMISSION_REQUEST_PERIODS_MISSING');
        }

        $reservesOnSubmit = (bool) ($policy['reserve_on_submit'] ?? false);
        foreach ($periods as $period) {
            $periodId = $this->positiveId($period['id'] ?? null, 'PERMISSION_REQUEST_PERIOD_PERSIST_FAILED');
            $periodKey = trim((string) ($period['period_key'] ?? ''));
            $count = $this->positiveId($period['requested_count'] ?? null, 'PERMISSION_REQUEST_PERIOD_PERSIST_FAILED');
            $minutes = $this->positiveId($period['requested_minutes'] ?? null, 'PERMISSION_REQUEST_PERIOD_PERSIST_FAILED');
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodKey) !== 1) {
                throw new DomainException('PERMISSION_REQUEST_PERIOD_PERSIST_FAILED');
            }

            if ($outcome === 'approved') {
                if (!$reservesOnSubmit) {
                    $this->recordMovement(
                        'reserve',
                        $instanceId,
                        $actorId,
                        $staffUserId,
                        $permissionTypeId,
                        $requestId,
                        $periodId,
                        $periodKey,
                        $count,
                        $minutes,
                        $policy
                    );
                }
                $this->recordMovement(
                    'consume',
                    $instanceId,
                    $actorId,
                    $staffUserId,
                    $permissionTypeId,
                    $requestId,
                    $periodId,
                    $periodKey,
                    $count,
                    $minutes,
                    $policy
                );
            } elseif ($reservesOnSubmit) {
                $this->recordMovement(
                    'release',
                    $instanceId,
                    $actorId,
                    $staffUserId,
                    $permissionTypeId,
                    $requestId,
                    $periodId,
                    $periodKey,
                    $count,
                    $minutes,
                    $policy
                );
            }
        }

        if (!$this->requests->finalizeWorkflowOutcome(
            $requestId,
            $expectedLockVersion,
            $outcome,
            $occurredAt
        )) {
            throw new DomainException('PERMISSION_APPROVAL_OUTCOME_STALE');
        }
        $coveragePublication = null;
        if ($outcome === 'approved') {
            $coveragePublication = $this->coveragePublisher->publishApproved(
                $request,
                $snapshot,
                $instanceId,
                $actorId,
                $occurredAt
            );
        }

        $this->audit->recordEvent(
            'staff_permission_request_approval_finalized',
            'staff_permission_requests',
            $requestId,
            $outcome,
            [
                'approval_instance_id' => $instanceId,
                'staff_user_id' => $staffUserId,
                'permission_type_id' => $permissionTypeId,
                'outcome' => $outcome,
                'period_count' => count($periods),
                'reservation_at_submission' => $reservesOnSubmit,
                'coverage_published_count' => (int) ($coveragePublication['published_count'] ?? 0),
            ],
            ['user_id' => $actorId, 'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u')]
        );
    }

    /** @param array<string,mixed> $policy */
    private function recordMovement(
        string $type,
        int $instanceId,
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        int $requestId,
        int $periodId,
        string $periodKey,
        int $count,
        int $minutes,
        array $policy
    ): void {
        $this->quotaLedger->record([
            'actor_id' => $actorId,
            'staff_user_id' => $staffUserId,
            'permission_type_id' => $permissionTypeId,
            'period_key' => $periodKey,
            'request_id' => $requestId,
            'request_period_id' => $periodId,
            'movement_type' => $type,
            'count_delta' => $count,
            'minutes_delta' => $minutes,
            'idempotency_key' => 'permission-approval-' . $type . ':' . hash('sha256', $instanceId . ':' . $periodId),
            'reason_code' => null,
            'limits' => PermissionQuotaLimits::fromPolicy($policy),
        ]);
    }

    /** @return array{policy:array<string,mixed>,type:array<string,mixed>} */
    private function policySnapshot(mixed $value): array
    {
        if (is_array($value)) {
            $snapshot = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            try {
                $snapshot = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new DomainException('PERMISSION_REQUEST_POLICY_SNAPSHOT_INVALID', 0, $exception);
            }
        } else {
            throw new DomainException('PERMISSION_REQUEST_POLICY_SNAPSHOT_INVALID');
        }
        if (
            !is_array($snapshot)
            || !is_array($snapshot['policy'] ?? null)
            || !is_array($snapshot['type'] ?? null)
        ) {
            throw new DomainException('PERMISSION_REQUEST_POLICY_SNAPSHOT_INVALID');
        }

        return ['policy' => $snapshot['policy'], 'type' => $snapshot['type']];
    }

    private function positiveId(mixed $value, string $errorCode): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($errorCode);
        }

        return (int) $value;
    }
}
