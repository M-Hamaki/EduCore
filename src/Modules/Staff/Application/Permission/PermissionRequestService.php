<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Permission;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerGateway;
use EduCore\Modules\Staff\Contracts\PermissionRequestAuthorization;
use EduCore\Modules\Staff\Contracts\PermissionRequestClock;
use EduCore\Modules\Staff\Contracts\PermissionRequestOverlapQuery;
use EduCore\Modules\Staff\Contracts\PermissionRequestRepository;
use EduCore\Modules\Staff\Contracts\PermissionSubmissionWorkflowResolver;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Owns the employee-side permission request lifecycle.
 *
 * A draft intentionally has no policy or workflow evidence. Submission locks
 * the employee, resolves dated policy and workflow snapshots, creates exact
 * month slices, reserves quota, and audits the whole change in one shared
 * transaction. A direct cancellation is permitted only before approval; an
 * approved permission remains protected until the approval workflow owns its
 * cancellation path.
 */
final class PermissionRequestService
{
    private PermissionRequestClock $clock;

    public function __construct(
        private PermissionRequestRepository $repository,
        private PermissionRequestOverlapQuery $overlaps,
        private PermissionPolicyResolver $policies,
        private PermissionQuotaLedgerGateway $quotaLedger,
        private PermissionRequestAuthorization $authorization,
        private PermissionSubmissionWorkflowResolver $workflows,
        private AuditEventWriter $audit,
        ?PermissionRequestClock $clock = null,
        private ?ApprovalWorkflowSubmissionGateway $approvalWorkflow = null
    ) {
        $this->clock = $clock ?? new SystemPermissionRequestClock();
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function createDraft(array $command): array
    {
        $draft = $this->normalizeDraftCommand($command, true);
        $now = $this->now();
        $this->assertSelfActor($draft['actor_id'], $draft['staff_user_id']);
        $this->authorization->assertCanAct(
            $draft['actor_id'],
            $draft['staff_user_id'],
            'create_draft',
            $now
        );

        return $this->repository->transactional(function () use ($draft, $now): array {
            if (!$this->repository->lockStaffForRequest($draft['staff_user_id'])) {
                throw new DomainException('PERMISSION_REQUEST_STAFF_NOT_FOUND');
            }
            $existing = $this->repository->requestByCreateIdempotencyForUpdate($draft['create_idempotency_key']);
            if ($existing !== null) {
                if ((int) ($existing['staff_user_id'] ?? 0) === $draft['staff_user_id']
                    && hash_equals((string) ($existing['request_hash'] ?? ''), $draft['request_hash'])) {
                    return $this->receipt($existing, true);
                }
                throw new DomainException('PERMISSION_REQUEST_CREATE_IDEMPOTENCY_CONFLICT');
            }

            $requestId = $this->repository->insertDraft($draft);
            if ($requestId <= 0) {
                throw new DomainException('PERMISSION_REQUEST_DRAFT_PERSIST_FAILED');
            }
            $stored = $draft + [
                'id' => $requestId,
                'status' => 'draft',
                'lock_version' => 1,
                'policy_version_id' => null,
                'workflow_version_id' => null,
                'assignment_id' => null,
                'quota_exception' => false,
            ];
            $this->audit->recordEvent(
                'staff_permission_request_drafted',
                'staff_permission_requests',
                $requestId,
                null,
                [
                    'staff_user_id' => $draft['staff_user_id'],
                    'permission_type_id' => $draft['permission_type_id'],
                    'from_at' => $draft['from_at'],
                    'to_at' => $draft['to_at'],
                    'requested_minutes' => $draft['requested_minutes'],
                    'request_hash' => $draft['request_hash'],
                    'create_idempotency_hash' => hash('sha256', $draft['create_idempotency_key']),
                ],
                ['user_id' => $draft['actor_id'], 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->receipt($stored, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function updateDraft(array $command): array
    {
        $draft = $this->normalizeDraftCommand($command, false);
        $requestId = $this->positiveId($command['request_id'] ?? null, 'PERMISSION_REQUEST_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'PERMISSION_REQUEST_LOCK_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($draft, $requestId, $expectedLockVersion, $now): array {
            $request = $this->requiredRequest($requestId);
            $this->assertSelfActor($draft['actor_id'], (int) ($request['staff_user_id'] ?? 0));
            $this->authorization->assertCanAct(
                $draft['actor_id'],
                (int) $request['staff_user_id'],
                'update_draft',
                $now
            );
            if ((string) ($request['status'] ?? '') !== 'draft'
                || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('PERMISSION_REQUEST_STALE');
            }
            if ((int) $request['staff_user_id'] !== $draft['staff_user_id']) {
                throw new DomainException('PERMISSION_REQUEST_STAFF_IMMUTABLE');
            }
            if (!$this->repository->updateDraft($requestId, $expectedLockVersion, $draft)) {
                throw new DomainException('PERMISSION_REQUEST_STALE');
            }
            $after = array_replace($request, $draft);
            $after['lock_version'] = $expectedLockVersion + 1;
            $this->audit->recordEvent(
                'staff_permission_request_draft_updated',
                'staff_permission_requests',
                $requestId,
                null,
                [
                    'staff_user_id' => $draft['staff_user_id'],
                    'permission_type_id' => $draft['permission_type_id'],
                    'from_at' => $draft['from_at'],
                    'to_at' => $draft['to_at'],
                    'requested_minutes' => $draft['requested_minutes'],
                    'before_request_hash' => (string) ($request['request_hash'] ?? ''),
                    'after_request_hash' => $draft['request_hash'],
                ],
                ['user_id' => $draft['actor_id'], 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->receipt($after, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function submit(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'PERMISSION_REQUEST_ACTOR_INVALID');
        $requestId = $this->positiveId($command['request_id'] ?? null, 'PERMISSION_REQUEST_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'PERMISSION_REQUEST_LOCK_INVALID'
        );
        $submissionIdempotencyKey = $this->requiredText(
            $command['submission_idempotency_key'] ?? null,
            190,
            'PERMISSION_REQUEST_SUBMISSION_IDEMPOTENCY_KEY_INVALID'
        );
        $quotaExceptionReason = $this->nullableText(
            $command['quota_exception_reason'] ?? null,
            1000,
            'PERMISSION_REQUEST_QUOTA_EXCEPTION_REASON_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $requestId,
            $expectedLockVersion,
            $submissionIdempotencyKey,
            $quotaExceptionReason,
            $now
        ): array {
            $request = $this->requiredRequest($requestId);
            $staffUserId = (int) ($request['staff_user_id'] ?? 0);
            $this->assertSelfActor($actorId, $staffUserId);
            $this->authorization->assertCanAct($actorId, $staffUserId, 'submit', $now);

            if ((string) ($request['status'] ?? '') !== 'draft') {
                if ((string) ($request['status'] ?? '') === 'pending_approval'
                    && hash_equals(
                        (string) ($request['submission_idempotency_key'] ?? ''),
                        $submissionIdempotencyKey
                    )) {
                    return $this->receipt($request, true);
                }
                throw new DomainException('PERMISSION_REQUEST_NOT_DRAFT');
            }
            if ((int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('PERMISSION_REQUEST_STALE');
            }
            if (!$this->repository->lockStaffForRequest($staffUserId)) {
                throw new DomainException('PERMISSION_REQUEST_STAFF_NOT_FOUND');
            }
            $idempotentRequest = $this->repository->requestBySubmissionIdempotencyForUpdate($submissionIdempotencyKey);
            if ($idempotentRequest !== null && (int) ($idempotentRequest['id'] ?? 0) !== $requestId) {
                throw new DomainException('PERMISSION_REQUEST_SUBMISSION_IDEMPOTENCY_CONFLICT');
            }

            $window = $this->windowFromStoredRequest($request);
            $resolution = $this->policies->resolve(
                $staffUserId,
                (int) ($request['permission_type_id'] ?? 0),
                $window['from']
            );
            if (($resolution['status'] ?? '') !== 'resolved') {
                throw new DomainException((string) ($resolution['reason_code'] ?? 'PERMISSION_POLICY_UNAVAILABLE'));
            }
            $policy = (array) $resolution['policy'];
            $type = (array) $resolution['type'];
            $assignment = (array) $resolution['assignment'];
            $policyTimezone = $this->policyTimezone($policy);
            $this->assertPolicyCompatible(
                $actorId,
                $request,
                $window,
                $type,
                $policy,
                $policyTimezone,
                $now
            );

            if (!(bool) ($policy['allow_overlap'] ?? false)) {
                $conflicts = $this->overlaps->conflictsForStaffForUpdate(
                    $staffUserId,
                    $window['from'],
                    $window['to'],
                    $requestId
                );
                if ($conflicts !== []) {
                    throw new DomainException('PERMISSION_REQUEST_OVERLAP');
                }
            }

            $workflow = $this->normalizeWorkflowSnapshot($this->workflows->resolveForSubmission(
                $actorId,
                $staffUserId,
                (int) $request['permission_type_id'],
                $request,
                $policy,
                $assignment,
                $now
            ));
            $periods = $this->splitIntoMonthlyPeriods($window['from'], $window['to'], $policyTimezone);
            if ($this->sumPeriodMinutes($periods) !== (int) $request['requested_minutes']) {
                throw new DomainException('PERMISSION_REQUEST_PERIOD_SPLIT_INVALID');
            }
            $storedPeriods = $this->repository->replaceDraftPeriods($requestId, $periods);
            $this->assertPersistedPeriods($storedPeriods, $periods);
            $snapshot = $this->policySnapshot($policy, $type, $assignment, $workflow, $now);
            $snapshotJson = $this->encodeJson($snapshot, 'PERMISSION_REQUEST_POLICY_SNAPSHOT_INVALID');
            $assignmentId = $this->positiveId(
                $assignment['assignment_id'] ?? null,
                'PERMISSION_REQUEST_ASSIGNMENT_INVALID'
            );
            if (!$this->repository->submitDraft($requestId, $expectedLockVersion, [
                'policy_version_id' => $this->positiveId(
                    $policy['version_id'] ?? null,
                    'PERMISSION_REQUEST_POLICY_VERSION_INVALID'
                ),
                'policy_snapshot' => $snapshotJson,
                'workflow_version_id' => $workflow['workflow_version_id'],
                'assignment_id' => $assignmentId,
                'submitted_by' => $actorId,
                'submitted_at' => $this->databaseInstant($now),
                'submission_idempotency_key' => $submissionIdempotencyKey,
            ])) {
                throw new DomainException('PERMISSION_REQUEST_STALE');
            }

            $quotaException = false;
            $overrideAuthorized = false;
            if ($quotaExceptionReason !== null) {
                $overrideAuthorized = $this->authorization->canOverrideQuota(
                    $actorId,
                    $staffUserId,
                    (int) $request['permission_type_id'],
                    $now
                );
                if (!$overrideAuthorized) {
                    throw new DomainException('PERMISSION_QUOTA_OVERRIDE_NOT_ALLOWED');
                }
            }
            if ((bool) ($policy['reserve_on_submit'] ?? false)) {
                foreach ($storedPeriods as $period) {
                    $movement = $this->quotaLedger->record([
                        'actor_id' => $actorId,
                        'staff_user_id' => $staffUserId,
                        'permission_type_id' => (int) $request['permission_type_id'],
                        'period_key' => (string) $period['period_key'],
                        'request_id' => $requestId,
                        'request_period_id' => (int) $period['id'],
                        'movement_type' => 'reserve',
                        'count_delta' => (int) $period['requested_count'],
                        'minutes_delta' => (int) $period['requested_minutes'],
                        'idempotency_key' => $this->movementIdempotencyKey(
                            'reserve',
                            $submissionIdempotencyKey,
                            (int) $period['id']
                        ),
                        'reason_code' => $quotaExceptionReason === null ? null : 'quota_override',
                        'limits' => $this->quotaLimits($policy, $overrideAuthorized),
                    ]);
                    $quotaException = $quotaException || (bool) ($movement['quota_exception'] ?? false);
                }
            }

            $lockVersion = $expectedLockVersion + 1;
            if ($quotaException) {
                if ($quotaExceptionReason === null) {
                    throw new DomainException('PERMISSION_QUOTA_OVERRIDE_REASON_REQUIRED');
                }
                if (!$this->repository->markQuotaException($requestId, $lockVersion, $quotaExceptionReason)) {
                    throw new DomainException('PERMISSION_REQUEST_STALE');
                }
                ++$lockVersion;
            }
            if ($this->approvalWorkflow === null) {
                throw new DomainException('PERMISSION_APPROVAL_GATEWAY_UNAVAILABLE');
            }
            $approval = $this->approvalWorkflow->submit([
                'actor_id' => $actorId,
                'resource_type' => 'permission_request',
                'resource_id' => $requestId,
                'workflow_version_id' => $workflow['workflow_version_id'],
                'snapshot' => $workflow['snapshot'],
                'idempotency_key' => $this->approvalSubmissionIdempotencyKey($submissionIdempotencyKey),
                'submitted_at' => $now,
            ]);
            $workflowInstanceId = $this->positiveId(
                $approval['instance_id'] ?? null,
                'PERMISSION_APPROVAL_INSTANCE_INVALID'
            );
            if (!$this->repository->attachWorkflowInstance($requestId, $lockVersion, $workflowInstanceId)) {
                throw new DomainException('PERMISSION_REQUEST_STALE');
            }
            ++$lockVersion;
            $after = array_replace($request, [
                'status' => 'pending_approval',
                'policy_version_id' => (int) $policy['version_id'],
                'workflow_version_id' => $workflow['workflow_version_id'],
                'workflow_instance_id' => $workflowInstanceId,
                'assignment_id' => $assignmentId,
                'submitted_by' => $actorId,
                'submitted_at' => $this->databaseInstant($now),
                'submission_idempotency_key' => $submissionIdempotencyKey,
                'quota_exception' => $quotaException,
                'quota_exception_reason' => $quotaException ? $quotaExceptionReason : null,
                'lock_version' => $lockVersion,
            ]);
            $this->audit->recordEvent(
                'staff_permission_request_submitted',
                'staff_permission_requests',
                $requestId,
                null,
                [
                    'staff_user_id' => $staffUserId,
                    'permission_type_id' => (int) $request['permission_type_id'],
                    'policy_version_id' => (int) $policy['version_id'],
                    'workflow_version_id' => $workflow['workflow_version_id'],
                    'workflow_instance_id' => $workflowInstanceId,
                    'assignment_id' => $assignmentId,
                    'period_keys' => array_values(array_map(
                        static fn (array $period): string => (string) $period['period_key'],
                        $storedPeriods
                    )),
                    'requested_minutes' => (int) $request['requested_minutes'],
                    'quota_reserved' => (bool) ($policy['reserve_on_submit'] ?? false),
                    'quota_exception' => $quotaException,
                    'submission_idempotency_hash' => hash('sha256', $submissionIdempotencyKey),
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->receipt($after, false);
        });
    }

    /** @return array<string,mixed> */
    public function withdrawDraft(int $actorId, int $requestId, int $expectedLockVersion): array
    {
        $this->assertPositiveId($actorId, 'PERMISSION_REQUEST_ACTOR_INVALID');
        $this->assertPositiveId($requestId, 'PERMISSION_REQUEST_ID_INVALID');
        $this->assertPositiveId($expectedLockVersion, 'PERMISSION_REQUEST_LOCK_INVALID');
        $now = $this->now();

        return $this->repository->transactional(function () use ($actorId, $requestId, $expectedLockVersion, $now): array {
            $request = $this->requiredRequest($requestId);
            $staffUserId = (int) ($request['staff_user_id'] ?? 0);
            $this->assertSelfActor($actorId, $staffUserId);
            $this->authorization->assertCanAct($actorId, $staffUserId, 'withdraw_draft', $now);
            if ((string) ($request['status'] ?? '') === 'withdrawn') {
                return $this->receipt($request, true);
            }
            if ((string) ($request['status'] ?? '') !== 'draft'
                || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('PERMISSION_REQUEST_STALE');
            }
            if (!$this->repository->withdrawDraft($requestId, $expectedLockVersion)) {
                throw new DomainException('PERMISSION_REQUEST_STALE');
            }
            $request['status'] = 'withdrawn';
            $request['lock_version'] = $expectedLockVersion + 1;
            $this->audit->recordEvent(
                'staff_permission_request_draft_withdrawn',
                'staff_permission_requests',
                $requestId,
                null,
                ['staff_user_id' => $staffUserId, 'permission_type_id' => (int) $request['permission_type_id']],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );
            return $this->receipt($request, false);
        });
    }

    /**
     * Cancels a pending request and releases its held quota in the same
     * transaction. An approved request must use the approval-owned
     * cancellation workflow so that attendance is never silently uncovered.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function cancel(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'PERMISSION_REQUEST_ACTOR_INVALID');
        $requestId = $this->positiveId($command['request_id'] ?? null, 'PERMISSION_REQUEST_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'PERMISSION_REQUEST_LOCK_INVALID'
        );
        $cancellationReason = $this->requiredText(
            $command['reason'] ?? null,
            2000,
            'PERMISSION_REQUEST_CANCELLATION_REASON_REQUIRED'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $requestId,
            $expectedLockVersion,
            $cancellationReason,
            $now
        ): array {
            $request = $this->requiredRequest($requestId);
            $staffUserId = (int) ($request['staff_user_id'] ?? 0);
            $this->assertSelfActor($actorId, $staffUserId);
            $this->authorization->assertCanAct($actorId, $staffUserId, 'cancel', $now);
            $status = (string) ($request['status'] ?? '');
            if ($status === 'cancelled') {
                return $this->receipt($request, true);
            }
            if ($status === 'approved') {
                throw new DomainException('PERMISSION_REQUEST_CANCELLATION_WORKFLOW_REQUIRED');
            }
            if ($status !== 'pending_approval'
                || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('PERMISSION_REQUEST_STALE');
            }
            if (!$this->repository->lockStaffForRequest($staffUserId)) {
                throw new DomainException('PERMISSION_REQUEST_STAFF_NOT_FOUND');
            }
            $snapshot = $this->decodePolicySnapshot($request['policy_snapshot'] ?? null);
            $policy = (array) ($snapshot['policy'] ?? []);
            $periods = $this->repository->periodsForRequestForUpdate($requestId);
            if ($periods === []) {
                throw new DomainException('PERMISSION_REQUEST_PERIODS_MISSING');
            }
            if ((bool) ($policy['reserve_on_submit'] ?? false)) {
                foreach ($periods as $period) {
                    $this->assertPeriodRecord($period);
                    $this->quotaLedger->record([
                        'actor_id' => $actorId,
                        'staff_user_id' => $staffUserId,
                        'permission_type_id' => (int) $request['permission_type_id'],
                        'period_key' => (string) $period['period_key'],
                        'request_id' => $requestId,
                        'request_period_id' => (int) $period['id'],
                        'movement_type' => 'release',
                        'count_delta' => (int) $period['requested_count'],
                        'minutes_delta' => (int) $period['requested_minutes'],
                        'idempotency_key' => $this->movementIdempotencyKey(
                            'cancel-release',
                            (string) ($request['submission_idempotency_key'] ?? ''),
                            (int) $period['id']
                        ),
                        'reason_code' => 'request_cancelled',
                        'limits' => $this->quotaLimits($policy, false),
                    ]);
                }
            }
            if (!$this->repository->cancelPendingRequest($requestId, $expectedLockVersion)) {
                throw new DomainException('PERMISSION_REQUEST_STALE');
            }
            $request['status'] = 'cancelled';
            $request['lock_version'] = $expectedLockVersion + 1;
            $this->audit->recordEvent(
                'staff_permission_request_cancelled',
                'staff_permission_requests',
                $requestId,
                null,
                [
                    'staff_user_id' => $staffUserId,
                    'permission_type_id' => (int) $request['permission_type_id'],
                    'quota_released' => (bool) ($policy['reserve_on_submit'] ?? false),
                    'period_count' => count($periods),
                    'cancellation_reason_hash' => hash('sha256', $cancellationReason),
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );
            return $this->receipt($request, false);
        });
    }

    /**
     * HR-owned lifecycle bridge: service end cancels every still-pending
     * permission and releases its reserved quota atomically.
     *
     * @return array{cancelled_count:int,replayed:bool}
     */
    public function cancelPendingForServiceEnd(int $actorId, int $staffUserId, string $reason): array
    {
        $this->positiveId($actorId, 'PERMISSION_REQUEST_ACTOR_INVALID');
        $this->positiveId($staffUserId, 'PERMISSION_REQUEST_STAFF_INVALID');
        $reason = $this->requiredText($reason, 2000, 'PERMISSION_REQUEST_CANCELLATION_REASON_REQUIRED');
        $now = $this->now();

        return $this->repository->transactional(function () use ($actorId, $staffUserId, $reason, $now): array {
            $this->authorization->assertCanAct($actorId, $staffUserId, 'service_end', $now);
            if (!$this->repository->lockStaffForRequest($staffUserId)) {
                throw new DomainException('PERMISSION_REQUEST_STAFF_NOT_FOUND');
            }
            $requests = $this->repository->pendingRequestsForStaffForUpdate($staffUserId);
            $cancelled = 0;
            foreach ($requests as $request) {
                $requestId = (int) $request['id'];
                $snapshot = $this->decodePolicySnapshot($request['policy_snapshot'] ?? null);
                $policy = (array) ($snapshot['policy'] ?? []);
                if ((bool) ($policy['reserve_on_submit'] ?? false)) {
                    foreach ($this->repository->periodsForRequestForUpdate($requestId) as $period) {
                        $this->assertPeriodRecord($period);
                        $this->quotaLedger->record([
                            'actor_id' => $actorId,
                            'staff_user_id' => $staffUserId,
                            'permission_type_id' => (int) $request['permission_type_id'],
                            'period_key' => (string) $period['period_key'],
                            'request_id' => $requestId,
                            'request_period_id' => (int) $period['id'],
                            'movement_type' => 'release',
                            'count_delta' => (int) $period['requested_count'],
                            'minutes_delta' => (int) $period['requested_minutes'],
                            'idempotency_key' => $this->movementIdempotencyKey('service-end-release', (string) ($request['submission_idempotency_key'] ?? ''), (int) $period['id']),
                            'reason_code' => 'service_end',
                            'limits' => $this->quotaLimits($policy, false),
                        ]);
                    }
                }
                if (!$this->repository->cancelPendingRequestDueToServiceEnd($requestId, (int) $request['lock_version'])) {
                    throw new DomainException('PERMISSION_REQUEST_STALE');
                }
                $this->audit->recordEvent(
                    'staff_permission_request_cancelled_due_to_service_end',
                    'staff_permission_requests',
                    $requestId,
                    null,
                    ['staff_user_id' => $staffUserId, 'reason_hash' => hash('sha256', $reason)],
                    ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
                );
                ++$cancelled;
            }
            return ['cancelled_count' => $cancelled, 'replayed' => $requests === []];
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function normalizeDraftCommand(array $command, bool $requiresCreateIdempotency): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'PERMISSION_REQUEST_ACTOR_INVALID');
        $staffUserId = $this->positiveId($command['staff_user_id'] ?? null, 'PERMISSION_REQUEST_STAFF_INVALID');
        $permissionTypeId = $this->positiveId(
            $command['permission_type_id'] ?? null,
            'PERMISSION_REQUEST_TYPE_INVALID'
        );
        $window = $this->normalizeWindow(
            $command['from_at'] ?? null,
            $command['to_at'] ?? null,
            $command['timezone'] ?? 'Africa/Cairo'
        );
        $customLabel = $this->nullableText(
            $command['custom_label'] ?? null,
            200,
            'PERMISSION_REQUEST_CUSTOM_LABEL_INVALID'
        );
        $reason = $this->nullableText($command['reason'] ?? null, 4000, 'PERMISSION_REQUEST_REASON_INVALID');
        $attachmentRef = $this->nullableText(
            $command['attachment_ref'] ?? null,
            500,
            'PERMISSION_REQUEST_ATTACHMENT_REF_INVALID'
        );
        $normalized = [
            'actor_id' => $actorId,
            'staff_user_id' => $staffUserId,
            'permission_type_id' => $permissionTypeId,
            'from_at' => $this->databaseInstant($window['from']),
            'to_at' => $this->databaseInstant($window['to']),
            'timezone' => $window['timezone'],
            'requested_minutes' => $window['requested_minutes'],
            'custom_label' => $customLabel,
            'reason' => $reason,
            'attachment_ref' => $attachmentRef,
        ];
        if ($requiresCreateIdempotency) {
            $normalized['create_idempotency_key'] = $this->requiredText(
                $command['create_idempotency_key'] ?? null,
                190,
                'PERMISSION_REQUEST_CREATE_IDEMPOTENCY_KEY_INVALID'
            );
        }
        $normalized['request_hash'] = $this->requestHash($normalized);

        return $normalized;
    }

    /** @param array<string,mixed> $request @return array{from:DateTimeImmutable,to:DateTimeImmutable,timezone:string,requested_minutes:int} */
    private function windowFromStoredRequest(array $request): array
    {
        return $this->normalizeWindow(
            $request['from_at'] ?? null,
            $request['to_at'] ?? null,
            $request['timezone'] ?? null
        );
    }

    /** @return array{from:DateTimeImmutable,to:DateTimeImmutable,timezone:string,requested_minutes:int} */
    private function normalizeWindow(mixed $from, mixed $to, mixed $timezone): array
    {
        $zone = $this->timezone($timezone, 'PERMISSION_REQUEST_TIMEZONE_INVALID');
        $fromAt = $this->localDateTime($from, $zone, 'PERMISSION_REQUEST_FROM_INVALID');
        $toAt = $this->localDateTime($to, $zone, 'PERMISSION_REQUEST_TO_INVALID');
        if ($toAt <= $fromAt) {
            throw new InvalidArgumentException('PERMISSION_REQUEST_WINDOW_INVALID');
        }
        if ($fromAt->format('s.u') !== '00.000000' || $toAt->format('s.u') !== '00.000000') {
            throw new InvalidArgumentException('PERMISSION_REQUEST_DURATION_NOT_WHOLE_MINUTES');
        }
        $seconds = $toAt->getTimestamp() - $fromAt->getTimestamp();
        if ($seconds <= 0 || $seconds % 60 !== 0) {
            throw new InvalidArgumentException('PERMISSION_REQUEST_DURATION_NOT_WHOLE_MINUTES');
        }

        return [
            'from' => $fromAt,
            'to' => $toAt,
            'timezone' => $zone->getName(),
            'requested_minutes' => intdiv($seconds, 60),
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @param array{from:DateTimeImmutable,to:DateTimeImmutable,timezone:string,requested_minutes:int} $window
     * @param array<string,mixed> $type
     * @param array<string,mixed> $policy
     */
    private function assertPolicyCompatible(
        int $actorId,
        array $request,
        array $window,
        array $type,
        array $policy,
        DateTimeZone $policyTimezone,
        DateTimeImmutable $now
    ): void {
        if ($window['timezone'] !== $policyTimezone->getName()) {
            throw new DomainException('PERMISSION_REQUEST_TIMEZONE_POLICY_MISMATCH');
        }
        $policyEndsAt = $this->nullablePolicyDate($policy['valid_to'] ?? null, $policyTimezone);
        if ($policyEndsAt !== null && $window['to'] > $policyEndsAt) {
            throw new DomainException('PERMISSION_REQUEST_POLICY_WINDOW_CROSSES_BOUNDARY');
        }
        $maxMinutes = $this->nullableInt(
            $policy['max_minutes_per_request'] ?? null,
            'PERMISSION_REQUEST_POLICY_LIMIT_INVALID'
        );
        if ($maxMinutes !== null && $window['requested_minutes'] > $maxMinutes) {
            throw new DomainException('PERMISSION_REQUEST_MAX_DURATION_EXCEEDED');
        }
        if ((bool) ($type['requires_reason'] ?? false)
            && $this->nullableText($request['reason'] ?? null, 4000, 'PERMISSION_REQUEST_REASON_INVALID') === null) {
            throw new DomainException('PERMISSION_REQUEST_REASON_REQUIRED');
        }
        if ((bool) ($type['requires_custom_label'] ?? false)
            && $this->nullableText($request['custom_label'] ?? null, 200, 'PERMISSION_REQUEST_CUSTOM_LABEL_INVALID') === null) {
            throw new DomainException('PERMISSION_REQUEST_CUSTOM_LABEL_REQUIRED');
        }
        if ((bool) ($type['requires_attachment'] ?? false)
            && $this->nullableText($request['attachment_ref'] ?? null, 500, 'PERMISSION_REQUEST_ATTACHMENT_REF_INVALID') === null) {
            throw new DomainException('PERMISSION_REQUEST_ATTACHMENT_REQUIRED');
        }

        $now = $now->setTimezone($policyTimezone);
        if ($window['from'] < $now) {
            if (!(bool) ($type['allow_retroactive'] ?? false)) {
                throw new DomainException('PERMISSION_REQUEST_RETROACTIVE_NOT_ALLOWED');
            }
            $retroactiveDays = $this->nonNegativeInt(
                $policy['retroactive_limit_days'] ?? 0,
                'PERMISSION_REQUEST_RETROACTIVE_LIMIT_INVALID'
            );
            $firstAllowedDay = $now->setTime(0, 0)->modify('-' . $retroactiveDays . ' days');
            if ($window['from'] < $firstAllowedDay) {
                throw new DomainException('PERMISSION_REQUEST_RETROACTIVE_LIMIT_EXCEEDED');
            }
            $this->authorization->assertCanSubmitRetroactive(
                $actorId,
                (int) $request['staff_user_id'],
                (int) $request['permission_type_id'],
                $window['from'],
                $now
            );
            return;
        }

        $noticeMinutes = $this->nonNegativeInt(
            $policy['min_notice_minutes'] ?? 0,
            'PERMISSION_REQUEST_NOTICE_POLICY_INVALID'
        );
        if ($noticeMinutes > 0 && $window['from'] < $now->modify('+' . $noticeMinutes . ' minutes')) {
            throw new DomainException('PERMISSION_REQUEST_MIN_NOTICE_NOT_MET');
        }
    }

    /**
     * @return list<array{period_key:string,period_from_at:string,period_to_at:string,requested_count:int,requested_minutes:int}>
     */
    private function splitIntoMonthlyPeriods(
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        DateTimeZone $timezone
    ): array {
        $cursor = $fromAt->setTimezone($timezone);
        $end = $toAt->setTimezone($timezone);
        $periods = [];
        while ($cursor < $end) {
            $nextMonth = $cursor->modify('first day of next month')->setTime(0, 0);
            if ($nextMonth <= $cursor) {
                throw new RuntimeException('PERMISSION_REQUEST_PERIOD_SPLIT_INVALID');
            }
            $periodEnd = $nextMonth < $end ? $nextMonth : $end;
            $seconds = $periodEnd->getTimestamp() - $cursor->getTimestamp();
            if ($seconds <= 0 || $seconds % 60 !== 0) {
                throw new RuntimeException('PERMISSION_REQUEST_PERIOD_SPLIT_INVALID');
            }
            $periods[] = [
                'period_key' => $cursor->format('Y-m'),
                'period_from_at' => $this->databaseInstant($cursor),
                'period_to_at' => $this->databaseInstant($periodEnd),
                'requested_count' => 1,
                'requested_minutes' => intdiv($seconds, 60),
            ];
            $cursor = $periodEnd;
        }

        return $periods;
    }

    /** @param list<array<string,mixed>> $stored @param list<array<string,mixed>> $expected */
    private function assertPersistedPeriods(array $stored, array $expected): void
    {
        if (count($stored) !== count($expected)) {
            throw new DomainException('PERMISSION_REQUEST_PERIOD_PERSIST_FAILED');
        }
        foreach ($stored as $index => $period) {
            $this->assertPeriodRecord($period);
            $expectedPeriod = $expected[$index] ?? null;
            if (!is_array($expectedPeriod)
                || (string) $period['period_key'] !== (string) $expectedPeriod['period_key']
                || (int) $period['requested_count'] !== (int) $expectedPeriod['requested_count']
                || (int) $period['requested_minutes'] !== (int) $expectedPeriod['requested_minutes']) {
                throw new DomainException('PERMISSION_REQUEST_PERIOD_PERSIST_FAILED');
            }
        }
    }

    /** @param array<string,mixed> $period */
    private function assertPeriodRecord(array $period): void
    {
        if ($this->positiveId($period['id'] ?? null, 'PERMISSION_REQUEST_PERIOD_PERSIST_FAILED') <= 0
            || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', (string) ($period['period_key'] ?? '')) !== 1
            || $this->positiveId($period['requested_count'] ?? null, 'PERMISSION_REQUEST_PERIOD_PERSIST_FAILED') <= 0
            || $this->positiveId($period['requested_minutes'] ?? null, 'PERMISSION_REQUEST_PERIOD_PERSIST_FAILED') <= 0) {
            throw new DomainException('PERMISSION_REQUEST_PERIOD_PERSIST_FAILED');
        }
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function quotaLimits(array $policy, bool $overrideAuthorized): array
    {
        return PermissionQuotaLimits::fromPolicy($policy, $overrideAuthorized);
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $type @param array<string,mixed> $assignment @param array<string,mixed> $workflow */
    private function policySnapshot(
        array $policy,
        array $type,
        array $assignment,
        array $workflow,
        DateTimeImmutable $resolvedAt
    ): array {
        return [
            'schema_version' => 1,
            'resolved_at' => $this->databaseInstant($resolvedAt),
            'policy' => [
                'version_id' => (int) $policy['version_id'],
                'version_no' => (int) ($policy['version_no'] ?? 0),
                'timezone' => (string) $policy['timezone'],
                'max_requests_per_month' => $this->nullableInt($policy['max_requests_per_month'] ?? null, 'PERMISSION_REQUEST_POLICY_LIMIT_INVALID'),
                'max_minutes_per_request' => $this->nullableInt($policy['max_minutes_per_request'] ?? null, 'PERMISSION_REQUEST_POLICY_LIMIT_INVALID'),
                'max_minutes_per_month' => $this->nullableInt($policy['max_minutes_per_month'] ?? null, 'PERMISSION_REQUEST_POLICY_LIMIT_INVALID'),
                'min_notice_minutes' => $this->nonNegativeInt($policy['min_notice_minutes'] ?? 0, 'PERMISSION_REQUEST_NOTICE_POLICY_INVALID'),
                'retroactive_limit_days' => $this->nonNegativeInt($policy['retroactive_limit_days'] ?? 0, 'PERMISSION_REQUEST_RETROACTIVE_LIMIT_INVALID'),
                'reserve_on_submit' => (bool) ($policy['reserve_on_submit'] ?? false),
                'allow_overlap' => (bool) ($policy['allow_overlap'] ?? false),
                'allow_quota_override' => (bool) ($policy['allow_quota_override'] ?? false),
                'quota_override_max_minutes' => $this->nullableInt($policy['quota_override_max_minutes'] ?? null, 'PERMISSION_REQUEST_POLICY_LIMIT_INVALID'),
                'scope' => (array) ($policy['scope'] ?? []),
            ],
            'type' => [
                'id' => (int) $type['id'],
                'code' => (string) ($type['code'] ?? ''),
                'coverage_behavior' => (string) ($type['coverage_behavior'] ?? 'none'),
                'requires_reason' => (bool) ($type['requires_reason'] ?? false),
                'requires_custom_label' => (bool) ($type['requires_custom_label'] ?? false),
                'requires_attachment' => (bool) ($type['requires_attachment'] ?? false),
                'allow_retroactive' => (bool) ($type['allow_retroactive'] ?? false),
            ],
            'assignment' => [
                'assignment_id' => (int) $assignment['assignment_id'],
                'org_unit_id' => $assignment['org_unit_id'] ?? null,
                'job_title_id' => $assignment['job_title_id'] ?? null,
                'group_ids' => array_values(array_map('intval', (array) ($assignment['group_ids'] ?? []))),
                'employment_status' => (string) ($assignment['employment_status'] ?? ''),
            ],
            'workflow' => $workflow,
        ];
    }

    /** @param array<string,mixed> $workflow @return array{workflow_version_id:int,snapshot:array<string,mixed>} */
    private function normalizeWorkflowSnapshot(array $workflow): array
    {
        $versionId = $this->positiveId(
            $workflow['workflow_version_id'] ?? null,
            'PERMISSION_REQUEST_WORKFLOW_VERSION_INVALID'
        );
        $snapshot = $workflow['snapshot'] ?? null;
        if (!is_array($snapshot)) {
            throw new DomainException('PERMISSION_REQUEST_WORKFLOW_SNAPSHOT_INVALID');
        }

        return ['workflow_version_id' => $versionId, 'snapshot' => $snapshot];
    }

    /** @return array<string,mixed> */
    private function decodePolicySnapshot(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new DomainException('PERMISSION_REQUEST_POLICY_SNAPSHOT_INVALID');
        }
        try {
            $snapshot = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('PERMISSION_REQUEST_POLICY_SNAPSHOT_INVALID', 0, $exception);
        }
        if (!is_array($snapshot) || !is_array($snapshot['policy'] ?? null)) {
            throw new DomainException('PERMISSION_REQUEST_POLICY_SNAPSHOT_INVALID');
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $request */
    private function requiredRequest(int $requestId): array
    {
        $request = $this->repository->requestForUpdate($requestId);
        if ($request === null) {
            throw new DomainException('PERMISSION_REQUEST_NOT_FOUND');
        }

        return $request;
    }

    /** @param array<string,mixed> $request */
    private function receipt(array $request, bool $replayed): array
    {
        return [
            'replayed' => $replayed,
            'request_id' => (int) ($request['id'] ?? 0),
            'staff_user_id' => (int) ($request['staff_user_id'] ?? 0),
            'permission_type_id' => (int) ($request['permission_type_id'] ?? 0),
            'status' => (string) ($request['status'] ?? ''),
            'lock_version' => (int) ($request['lock_version'] ?? 0),
            'requested_minutes' => (int) ($request['requested_minutes'] ?? 0),
            'policy_version_id' => $this->nullableInt($request['policy_version_id'] ?? null, 'PERMISSION_REQUEST_RECEIPT_INVALID'),
            'workflow_version_id' => $this->nullableInt($request['workflow_version_id'] ?? null, 'PERMISSION_REQUEST_RECEIPT_INVALID'),
            'workflow_instance_id' => $this->nullableInt($request['workflow_instance_id'] ?? null, 'PERMISSION_REQUEST_RECEIPT_INVALID'),
            'assignment_id' => $this->nullableInt($request['assignment_id'] ?? null, 'PERMISSION_REQUEST_RECEIPT_INVALID'),
            'quota_exception' => (bool) ($request['quota_exception'] ?? false),
        ];
    }

    /** @param array<string,mixed> $draft */
    private function requestHash(array $draft): string
    {
        return hash('sha256', $this->encodeJson([
            'staff_user_id' => $draft['staff_user_id'],
            'permission_type_id' => $draft['permission_type_id'],
            'from_at' => $draft['from_at'],
            'to_at' => $draft['to_at'],
            'timezone' => $draft['timezone'],
            'requested_minutes' => $draft['requested_minutes'],
            'custom_label' => $draft['custom_label'],
            'reason_hash' => $draft['reason'] === null ? null : hash('sha256', $draft['reason']),
            'attachment_ref_hash' => $draft['attachment_ref'] === null ? null : hash('sha256', $draft['attachment_ref']),
        ], 'PERMISSION_REQUEST_HASH_INVALID'));
    }

    private function movementIdempotencyKey(string $operation, string $submissionKey, int $periodId): string
    {
        if ($submissionKey === '') {
            throw new DomainException('PERMISSION_REQUEST_SUBMISSION_IDEMPOTENCY_KEY_INVALID');
        }

        return 'permission-' . $operation . ':' . hash('sha256', $submissionKey . ':' . $periodId);
    }

    private function approvalSubmissionIdempotencyKey(string $submissionKey): string
    {
        if ($submissionKey === '') {
            throw new DomainException('PERMISSION_REQUEST_SUBMISSION_IDEMPOTENCY_KEY_INVALID');
        }

        return 'permission-approval-submit:' . hash('sha256', $submissionKey);
    }

    private function assertSelfActor(int $actorId, int $staffUserId): void
    {
        if ($staffUserId <= 0) {
            throw new DomainException('PERMISSION_REQUEST_STAFF_INVALID');
        }
        if ($actorId !== $staffUserId) {
            throw new DomainException('PERMISSION_REQUEST_OWNER_ONLY');
        }
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock->now();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('PERMISSION_REQUEST_CLOCK_INVALID');
        }

        return $now;
    }

    private function timezone(mixed $value, string $error): DateTimeZone
    {
        $name = trim((string) $value);
        if ($name === '') {
            throw new InvalidArgumentException($error);
        }
        try {
            return new DateTimeZone($name);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($error, 0, $exception);
        }
    }

    private function policyTimezone(array $policy): DateTimeZone
    {
        return $this->timezone($policy['timezone'] ?? null, 'PERMISSION_POLICY_TIMEZONE_INVALID');
    }

    private function localDateTime(mixed $value, DateTimeZone $timezone, string $error): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($timezone);
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        }
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($error);
        }
        $source = trim($value);
        foreach (['Y-m-d\\TH:i', 'Y-m-d H:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i:s.u'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $source, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format($format) === $source) {
                return $parsed;
            }
        }
        throw new InvalidArgumentException($error);
    }

    private function nullablePolicyDate(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->localDateTime($value, $timezone, 'PERMISSION_POLICY_VALID_TO_INVALID');
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $id;
    }

    private function assertPositiveId(mixed $value, string $error): void
    {
        $this->positiveId($value, $error);
    }

    private function nonNegativeInt(mixed $value, string $error): int
    {
        $integer = $this->nullableInt($value, $error);
        if ($integer === null || $integer < 0) {
            throw new InvalidArgumentException($error);
        }

        return $integer;
    }

    private function nullableInt(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new InvalidArgumentException($error);
    }

    private function requiredText(mixed $value, int $maxLength, string $error): string
    {
        $text = $this->nullableText($value, $maxLength, $error);
        if ($text === null) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function nullableText(mixed $value, int $maxLength, string $error): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function encodeJson(array $value, string $error): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $exception) {
            throw new DomainException($error, 0, $exception);
        }
    }

    /** @param list<array<string,mixed>> $periods */
    private function sumPeriodMinutes(array $periods): int
    {
        return array_reduce(
            $periods,
            static fn (int $total, array $period): int => $total + (int) ($period['requested_minutes'] ?? 0),
            0
        );
    }
}
