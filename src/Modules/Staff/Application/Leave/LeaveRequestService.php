<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowResolutionGateway;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerGateway;
use EduCore\Modules\Staff\Contracts\LeaveAttachmentVerificationQuery;
use EduCore\Modules\Staff\Contracts\LeaveRequestAuthorization;
use EduCore\Modules\Staff\Contracts\LeaveRequestClock;
use EduCore\Modules\Staff\Contracts\LeaveRequestOverlapQuery;
use EduCore\Modules\Staff\Contracts\LeaveRequestRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideApprovalQuery;
use EduCore\Modules\Staff\Domain\Leave\LeaveEntitlementPeriod;
use EduCore\Modules\Staff\Domain\Leave\LeaveUnits;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Owns the worker-side leave request lifecycle.
 *
 * A draft holds only calculated allocations and editable worker evidence.
 * Submission re-resolves the dated policy, assignment, staffing/workflow
 * evidence, creates an immutable request-day allocation, reserves every
 * entitlement-period balance, opens the shared approval instance, and audits
 * the result in one transaction. An approved original is never overwritten:
 * extension, early-return, and cancellation are explicit successor records.
 */
final class LeaveRequestService
{
    /** @var list<string> */
    private const REQUEST_KINDS = ['leave', 'extension', 'early_return', 'cancellation'];

    private LeaveRequestClock $clock;

    public function __construct(
        private LeaveRequestRepository $repository,
        private LeaveRequestOverlapQuery $overlaps,
        private LeavePolicyService $policies,
        private LeaveBalanceLedgerGateway $balances,
        private LeaveRequestAuthorization $authorization,
        private ApprovalWorkflowResolutionGateway $workflows,
        private AuditEventWriter $audit,
        ?LeaveRequestClock $clock = null,
        private ?ApprovalWorkflowSubmissionGateway $approvalWorkflow = null,
        private ?LeaveStaffingPolicy $staffingPolicy = null,
        private ?LeaveAttachmentVerificationQuery $attachments = null,
        private ?LeaveStaffingOverrideApprovalQuery $staffingOverrideEvidence = null
    ) {
        $this->clock = $clock ?? new SystemLeaveRequestClock();
    }

    /**
     * Creates one ordinary leave draft for the current worker.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function createDraft(array $command): array
    {
        $input = $this->normalizeDraftInput($command, 'leave', null);
        $now = $this->now();
        $this->assertSelfActor($input['actor_id'], $input['staff_user_id']);
        $this->authorization->assertCanAct($input['actor_id'], $input['staff_user_id'], 'create_draft', $now);

        return $this->repository->transactional(function () use ($input, $now): array {
            if (!$this->repository->lockStaffForRequest($input['staff_user_id'])) {
                throw new DomainException('LEAVE_REQUEST_STAFF_NOT_FOUND');
            }
            $quote = $this->draftQuote($input);
            $draft = $this->materializeDraft($input, $quote);
            $existing = $this->repository->requestByCreateIdempotencyForUpdate($draft['create_idempotency_key']);
            if ($existing !== null) {
                if ((int) ($existing['staff_user_id'] ?? 0) === $draft['staff_user_id']
                    && hash_equals((string) ($existing['request_hash'] ?? ''), $draft['request_hash'])) {
                    return $this->receipt($existing, true);
                }
                throw new DomainException('LEAVE_REQUEST_CREATE_IDEMPOTENCY_CONFLICT');
            }

            $requestId = $this->repository->insertDraft($draft);
            if ($requestId <= 0) {
                throw new DomainException('LEAVE_REQUEST_DRAFT_PERSIST_FAILED');
            }
            $storedDays = $this->repository->replaceDraftDays($requestId, $draft['request_days']);
            $this->assertPersistedDays($storedDays, $draft['request_days']);
            $stored = $draft + [
                'id' => $requestId,
                'status' => 'draft',
                'lock_version' => 1,
                'policy_version_id' => null,
                'workflow_version_id' => null,
                'workflow_instance_id' => null,
                'assignment_id' => null,
            ];
            $this->audit->recordEvent(
                'staff_leave_request_drafted',
                'staff_leave_requests',
                $requestId,
                null,
                [
                    'staff_user_id' => $draft['staff_user_id'],
                    'leave_type_id' => $draft['leave_type_id'],
                    'request_kind' => $draft['request_kind'],
                    'parent_request_id' => $draft['parent_request_id'],
                    'from_at' => $draft['from_at'],
                    'to_at' => $draft['to_at'],
                    'requested_units' => $draft['requested_units'],
                    'request_day_count' => count($storedDays),
                    'request_hash' => $draft['request_hash'],
                    'create_idempotency_hash' => hash('sha256', $draft['create_idempotency_key']),
                ],
                ['user_id' => $draft['actor_id'], 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->receipt($stored, false);
        });
    }

    /**
     * Creates an immutable-successor draft that extends an approved leave.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function createExtensionDraft(array $command): array
    {
        return $this->createSuccessorDraft($command, 'extension');
    }

    /**
     * Creates a proposed early-return successor for an approved leave.
     *
     * The successor window is the unused portion [actual return, original end).
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function createEarlyReturnDraft(array $command): array
    {
        return $this->createSuccessorDraft($command, 'early_return');
    }

    /**
     * Creates a proposed cancellation successor for an approved leave.
     *
     * It does not release a finalized balance or uncover attendance directly;
     * the later approval outcome owns that official reversal.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function requestCancellation(array $command): array
    {
        return $this->createSuccessorDraft($command, 'cancellation');
    }

    /**
     * Updates an editable leave draft and recalculates its draft allocations.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function updateDraft(array $command): array
    {
        $requestId = $this->positiveId($command['request_id'] ?? null, 'LEAVE_REQUEST_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'LEAVE_REQUEST_LOCK_INVALID'
        );
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'LEAVE_REQUEST_ACTOR_INVALID');
        $now = $this->now();

        return $this->repository->transactional(function () use ($command, $requestId, $expectedLockVersion, $actorId, $now): array {
            $current = $this->requiredRequest($requestId);
            $staffUserId = $this->positiveId($current['staff_user_id'] ?? null, 'LEAVE_REQUEST_STAFF_INVALID');
            $this->assertSelfActor($actorId, $staffUserId);
            $this->authorization->assertCanAct($actorId, $staffUserId, 'update_draft', $now);
            if ((string) ($current['status'] ?? '') !== 'draft'
                || (int) ($current['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('LEAVE_REQUEST_STALE');
            }

            $kind = $this->requestKind($current['request_kind'] ?? null);
            $input = $this->normalizeDraftInput(
                array_replace($command, [
                    'staff_user_id' => $staffUserId,
                    'leave_type_id' => (int) ($current['leave_type_id'] ?? 0),
                    'parent_request_id' => $current['parent_request_id'] ?? null,
                    'supersedes_id' => $current['supersedes_id'] ?? null,
                    'create_idempotency_key' => $current['create_idempotency_key'] ?? null,
                ]),
                $kind,
                $current
            );
            $this->assertSuccessorStillValid($input, $current);
            $quote = $this->draftQuote($input);
            $draft = $this->materializeDraft($input, $quote);

            $storedDays = $this->repository->replaceDraftDays($requestId, $draft['request_days']);
            $this->assertPersistedDays($storedDays, $draft['request_days']);
            if (!$this->repository->updateDraft($requestId, $expectedLockVersion, $draft)) {
                throw new DomainException('LEAVE_REQUEST_STALE');
            }

            $after = array_replace($current, $draft, ['lock_version' => $expectedLockVersion + 1]);
            $this->audit->recordEvent(
                'staff_leave_request_draft_updated',
                'staff_leave_requests',
                $requestId,
                null,
                [
                    'staff_user_id' => $staffUserId,
                    'leave_type_id' => $draft['leave_type_id'],
                    'request_kind' => $draft['request_kind'],
                    'from_at' => $draft['from_at'],
                    'to_at' => $draft['to_at'],
                    'requested_units' => $draft['requested_units'],
                    'before_request_hash' => (string) ($current['request_hash'] ?? ''),
                    'after_request_hash' => $draft['request_hash'],
                    'request_day_count' => count($storedDays),
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->receipt($after, false);
        });
    }

    /**
     * Submits a draft with fresh policy, assignment, balance, and workflow
     * evidence. A policy or calendar change since the draft is intentionally
     * reflected before the immutable request is persisted.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function submit(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'LEAVE_REQUEST_ACTOR_INVALID');
        $requestId = $this->positiveId($command['request_id'] ?? null, 'LEAVE_REQUEST_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'LEAVE_REQUEST_LOCK_INVALID'
        );
        $submissionKey = $this->requiredText(
            $command['submission_idempotency_key'] ?? null,
            190,
            'LEAVE_REQUEST_SUBMISSION_IDEMPOTENCY_KEY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $requestId,
            $expectedLockVersion,
            $submissionKey,
            $now
        ): array {
            $request = $this->requiredRequest($requestId);
            $staffUserId = $this->positiveId($request['staff_user_id'] ?? null, 'LEAVE_REQUEST_STAFF_INVALID');
            $this->assertSelfActor($actorId, $staffUserId);
            $this->authorization->assertCanAct($actorId, $staffUserId, 'submit', $now);

            if ((string) ($request['status'] ?? '') !== 'draft') {
                if ((string) ($request['status'] ?? '') === 'pending_approval'
                    && hash_equals((string) ($request['submission_idempotency_key'] ?? ''), $submissionKey)) {
                    return $this->receipt($request, true);
                }
                throw new DomainException('LEAVE_REQUEST_NOT_DRAFT');
            }
            if ((int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('LEAVE_REQUEST_STALE');
            }
            if (!$this->repository->lockStaffForRequest($staffUserId)) {
                throw new DomainException('LEAVE_REQUEST_STAFF_NOT_FOUND');
            }
            $idempotent = $this->repository->requestBySubmissionIdempotencyForUpdate($submissionKey);
            if ($idempotent !== null && (int) ($idempotent['id'] ?? 0) !== $requestId) {
                throw new DomainException('LEAVE_REQUEST_SUBMISSION_IDEMPOTENCY_CONFLICT');
            }

            $kind = $this->requestKind($request['request_kind'] ?? null);
            $input = $this->storedDraftInput($request, $actorId);
            $this->assertSuccessorStillValid($input, $request);
            $quote = $this->submissionQuote($input, $now);
            $draft = $this->materializeDraft($input, $quote);
            $this->assertAttachmentRequirements($requestId, $request, $quote);
            $staffing = $this->staffingAssessment($requestId, $draft, $quote, $now);

            if (!(bool) ($quote['policy']['allow_overlap'] ?? false)) {
                $conflicts = $this->overlaps->conflictsForStaffForUpdate(
                    $staffUserId,
                    $input['from_object'],
                    $input['to_object'],
                    $requestId
                );
                $conflicts = $this->withoutAllowedParentConflict(
                    $conflicts,
                    $kind,
                    $this->nullablePositiveId($request['parent_request_id'] ?? null, 'LEAVE_REQUEST_PARENT_INVALID')
                );
                if ($conflicts !== []) {
                    throw new DomainException('LEAVE_REQUEST_OVERLAP');
                }
            }

            $assignment = $quote['assignment'] ?? null;
            if (!is_array($assignment)) {
                throw new DomainException('LEAVE_REQUEST_ASSIGNMENT_INVALID');
            }
            $assignmentId = $this->positiveId($assignment['assignment_id'] ?? null, 'LEAVE_REQUEST_ASSIGNMENT_INVALID');
            $workflow = $this->normalizeWorkflow($this->workflows->resolveForResource(
                'leave_request',
                $staffUserId,
                [
                    'actor_id' => $actorId,
                    'leave_type_id' => $draft['leave_type_id'],
                    'request_id' => $requestId,
                    'request_kind' => $kind,
                    'parent_request_id' => $draft['parent_request_id'],
                    'assignment_id' => $assignmentId,
                    'assignment' => $assignment,
                ],
                $input['from_object'],
                $now
            ));
            $storedDays = $this->repository->replaceDraftDays($requestId, $draft['request_days']);
            $this->assertPersistedDays($storedDays, $draft['request_days']);
            $snapshot = $this->submissionSnapshot($quote, $workflow, $draft, $staffing, $now);
            $policyVersionId = $this->positiveId(
                $quote['policy']['version_id'] ?? null,
                'LEAVE_REQUEST_POLICY_VERSION_INVALID'
            );
            if (!$this->repository->submitDraft($requestId, $expectedLockVersion, [
                'requested_units' => $draft['requested_units'],
                'requested_minutes' => $draft['requested_minutes'],
                'timezone' => $draft['timezone'],
                'request_hash' => $draft['request_hash'],
                'policy_version_id' => $policyVersionId,
                'policy_snapshot' => $this->encodeJson($snapshot, 'LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID'),
                'workflow_version_id' => $workflow['workflow_version_id'],
                'assignment_id' => $assignmentId,
                'submitted_by' => $actorId,
                'submitted_at' => $this->databaseInstant($now),
                'submission_idempotency_key' => $submissionKey,
            ])) {
                throw new DomainException('LEAVE_REQUEST_STALE');
            }

            $reservesBalance = in_array($kind, ['leave', 'extension'], true);
            if ($reservesBalance) {
                foreach ($storedDays as $day) {
                    $this->reserveDay($actorId, $requestId, $submissionKey, $draft, $quote, $day);
                }
            }

            if ($this->approvalWorkflow === null) {
                throw new DomainException('LEAVE_APPROVAL_GATEWAY_UNAVAILABLE');
            }
            $approval = $this->approvalWorkflow->submit([
                'actor_id' => $actorId,
                'resource_type' => 'leave_request',
                'resource_id' => $requestId,
                'workflow_version_id' => $workflow['workflow_version_id'],
                'snapshot' => $workflow['snapshot'],
                'idempotency_key' => 'leave-approval-submit:' . hash('sha256', $submissionKey),
                'submitted_at' => $now,
            ]);
            $workflowInstanceId = $this->positiveId(
                $approval['instance_id'] ?? null,
                'LEAVE_APPROVAL_INSTANCE_INVALID'
            );
            $lockVersion = $expectedLockVersion + 1;
            if (!$this->repository->attachWorkflowInstance($requestId, $lockVersion, $workflowInstanceId)) {
                throw new DomainException('LEAVE_REQUEST_STALE');
            }
            ++$lockVersion;

            $after = array_replace($request, $draft, [
                'status' => 'pending_approval',
                'policy_version_id' => $policyVersionId,
                'workflow_version_id' => $workflow['workflow_version_id'],
                'workflow_instance_id' => $workflowInstanceId,
                'assignment_id' => $assignmentId,
                'submitted_by' => $actorId,
                'submitted_at' => $this->databaseInstant($now),
                'submission_idempotency_key' => $submissionKey,
                'lock_version' => $lockVersion,
            ]);
            $this->audit->recordEvent(
                'staff_leave_request_submitted',
                'staff_leave_requests',
                $requestId,
                null,
                [
                    'staff_user_id' => $staffUserId,
                    'leave_type_id' => $draft['leave_type_id'],
                    'request_kind' => $kind,
                    'parent_request_id' => $draft['parent_request_id'],
                    'policy_version_id' => $policyVersionId,
                    'workflow_version_id' => $workflow['workflow_version_id'],
                    'workflow_instance_id' => $workflowInstanceId,
                    'assignment_id' => $assignmentId,
                    'requested_units' => $draft['requested_units'],
                    'request_day_count' => count($storedDays),
                    'balance_reserved' => $reservesBalance,
                    'submission_idempotency_hash' => hash('sha256', $submissionKey),
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->receipt($after, false);
        });
    }

    /** @return array<string,mixed> */
    public function withdrawDraft(int $actorId, int $requestId, int $expectedLockVersion): array
    {
        $this->assertPositiveId($actorId, 'LEAVE_REQUEST_ACTOR_INVALID');
        $this->assertPositiveId($requestId, 'LEAVE_REQUEST_ID_INVALID');
        $this->assertPositiveId($expectedLockVersion, 'LEAVE_REQUEST_LOCK_INVALID');
        $now = $this->now();

        return $this->repository->transactional(function () use ($actorId, $requestId, $expectedLockVersion, $now): array {
            $request = $this->requiredRequest($requestId);
            $staffUserId = $this->positiveId($request['staff_user_id'] ?? null, 'LEAVE_REQUEST_STAFF_INVALID');
            $this->assertSelfActor($actorId, $staffUserId);
            $this->authorization->assertCanAct($actorId, $staffUserId, 'withdraw_draft', $now);
            if ((string) ($request['status'] ?? '') === 'withdrawn') {
                return $this->receipt($request, true);
            }
            if ((string) ($request['status'] ?? '') !== 'draft'
                || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('LEAVE_REQUEST_STALE');
            }
            if (!$this->repository->withdrawDraft($requestId, $expectedLockVersion)) {
                throw new DomainException('LEAVE_REQUEST_STALE');
            }
            $after = array_replace($request, ['status' => 'withdrawn', 'lock_version' => $expectedLockVersion + 1]);
            $this->audit->recordEvent(
                'staff_leave_request_draft_withdrawn',
                'staff_leave_requests',
                $requestId,
                null,
                [
                    'staff_user_id' => $staffUserId,
                    'leave_type_id' => (int) ($request['leave_type_id'] ?? 0),
                    'request_kind' => (string) ($request['request_kind'] ?? ''),
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->receipt($after, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    private function createSuccessorDraft(array $command, string $kind): array
    {
        if (!in_array($kind, ['extension', 'early_return', 'cancellation'], true)) {
            throw new InvalidArgumentException('LEAVE_REQUEST_KIND_INVALID');
        }
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'LEAVE_REQUEST_ACTOR_INVALID');
        $parentId = $this->positiveId($command['parent_request_id'] ?? null, 'LEAVE_REQUEST_PARENT_INVALID');
        $now = $this->now();

        return $this->repository->transactional(function () use ($command, $kind, $actorId, $parentId, $now): array {
            $parent = $this->requiredRequest($parentId);
            $staffUserId = $this->positiveId($parent['staff_user_id'] ?? null, 'LEAVE_REQUEST_STAFF_INVALID');
            $this->assertSelfActor($actorId, $staffUserId);
            $this->authorization->assertCanAct($actorId, $staffUserId, 'create_' . $kind . '_draft', $now);
            if (!$this->repository->lockStaffForRequest($staffUserId)) {
                throw new DomainException('LEAVE_REQUEST_STAFF_NOT_FOUND');
            }

            $input = $this->normalizeDraftInput(
                array_replace($command, [
                    'staff_user_id' => $staffUserId,
                    'leave_type_id' => (int) ($parent['leave_type_id'] ?? 0),
                    'parent_request_id' => $parentId,
                ]),
                $kind,
                null
            );
            $this->assertSuccessorWindow($input, $parent);
            $quote = $this->draftQuote($input);
            $draft = $this->materializeDraft($input, $quote);
            $existing = $this->repository->requestByCreateIdempotencyForUpdate($draft['create_idempotency_key']);
            if ($existing !== null) {
                if ((int) ($existing['staff_user_id'] ?? 0) === $staffUserId
                    && hash_equals((string) ($existing['request_hash'] ?? ''), $draft['request_hash'])) {
                    return $this->receipt($existing, true);
                }
                throw new DomainException('LEAVE_REQUEST_CREATE_IDEMPOTENCY_CONFLICT');
            }

            $requestId = $this->repository->insertDraft($draft);
            if ($requestId <= 0) {
                throw new DomainException('LEAVE_REQUEST_DRAFT_PERSIST_FAILED');
            }
            $storedDays = $this->repository->replaceDraftDays($requestId, $draft['request_days']);
            $this->assertPersistedDays($storedDays, $draft['request_days']);
            $stored = $draft + [
                'id' => $requestId,
                'status' => 'draft',
                'lock_version' => 1,
                'policy_version_id' => null,
                'workflow_version_id' => null,
                'workflow_instance_id' => null,
                'assignment_id' => null,
            ];
            $this->audit->recordEvent(
                'staff_leave_request_successor_drafted',
                'staff_leave_requests',
                $requestId,
                null,
                [
                    'staff_user_id' => $staffUserId,
                    'leave_type_id' => $draft['leave_type_id'],
                    'request_kind' => $kind,
                    'parent_request_id' => $parentId,
                    'from_at' => $draft['from_at'],
                    'to_at' => $draft['to_at'],
                    'requested_units' => $draft['requested_units'],
                    'request_day_count' => count($storedDays),
                    'request_hash' => $draft['request_hash'],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->receipt($stored, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function normalizeDraftInput(array $command, string $kind, ?array $existing): array
    {
        $kind = $this->requestKind($kind);
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'LEAVE_REQUEST_ACTOR_INVALID');
        $staffUserId = $this->positiveId($command['staff_user_id'] ?? null, 'LEAVE_REQUEST_STAFF_INVALID');
        $leaveTypeId = $this->positiveId($command['leave_type_id'] ?? null, 'LEAVE_REQUEST_TYPE_INVALID');
        $timezone = $this->timezone($command['timezone'] ?? null, 'LEAVE_REQUEST_TIMEZONE_INVALID');
        $from = $this->localDateTime($command['from_at'] ?? null, $timezone, 'LEAVE_REQUEST_WINDOW_INVALID');
        $to = $this->localDateTime($command['to_at'] ?? null, $timezone, 'LEAVE_REQUEST_WINDOW_INVALID');
        if ($to <= $from) {
            throw new InvalidArgumentException('LEAVE_REQUEST_WINDOW_INVALID');
        }

        $parentId = $this->nullablePositiveId($command['parent_request_id'] ?? null, 'LEAVE_REQUEST_PARENT_INVALID');
        $supersedesId = $this->nullablePositiveId($command['supersedes_id'] ?? null, 'LEAVE_REQUEST_SUPERSEDES_INVALID');
        if (($kind === 'leave' && ($parentId !== null || $supersedesId !== null))
            || ($kind !== 'leave' && $parentId === null)) {
            throw new InvalidArgumentException('LEAVE_REQUEST_PARENT_KIND_INVALID');
        }
        if ($existing !== null) {
            if ((int) ($existing['staff_user_id'] ?? 0) !== $staffUserId
                || (int) ($existing['leave_type_id'] ?? 0) !== $leaveTypeId
                || (string) ($existing['request_kind'] ?? '') !== $kind
                || $this->nullablePositiveId($existing['parent_request_id'] ?? null, 'LEAVE_REQUEST_PARENT_INVALID') !== $parentId
                || $this->nullablePositiveId($existing['supersedes_id'] ?? null, 'LEAVE_REQUEST_SUPERSEDES_INVALID') !== $supersedesId) {
                throw new DomainException('LEAVE_REQUEST_DRAFT_IDENTITY_IMMUTABLE');
            }
        }

        $documentReference = $this->managedDocumentReference($command, $existing);

        return [
            'actor_id' => $actorId,
            'staff_user_id' => $staffUserId,
            'leave_type_id' => $leaveTypeId,
            'request_kind' => $kind,
            'parent_request_id' => $parentId,
            'supersedes_id' => $supersedesId,
            'from_object' => $from,
            'to_object' => $to,
            'from_at' => $this->databaseInstant($from),
            'to_at' => $this->databaseInstant($to),
            'timezone' => $timezone->getName(),
            'reason' => $this->nullableText($command['reason'] ?? null, 10000, 'LEAVE_REQUEST_REASON_INVALID'),
            'reason_code' => $this->nullableText($command['reason_code'] ?? null, 100, 'LEAVE_REQUEST_REASON_CODE_INVALID'),
            'supporting_document_ref' => $documentReference,
            'create_idempotency_key' => $this->requiredText(
                $command['create_idempotency_key'] ?? null,
                190,
                'LEAVE_REQUEST_CREATE_IDEMPOTENCY_KEY_INVALID'
            ),
        ];
    }

    /**
     * The browser never selects a private attachment reference. It is written
     * only by LeaveAttachmentService after storage, authorization, and audit
     * have succeeded. An existing reference is retained on ordinary draft
     * edits so a form cannot accidentally detach medical evidence.
     *
     * @param array<string,mixed> $command
     * @param array<string,mixed>|null $existing
     */
    private function managedDocumentReference(array $command, ?array $existing): ?string
    {
        $hasInput = array_key_exists('supporting_document_ref', $command);
        $provided = $hasInput
            ? $this->nullableText(
                $command['supporting_document_ref'],
                500,
                'LEAVE_REQUEST_DOCUMENT_REFERENCE_INVALID'
            )
            : null;
        if ($existing === null) {
            if ($provided !== null) {
                throw new DomainException('LEAVE_REQUEST_DOCUMENT_MANAGED_SEPARATELY');
            }

            return null;
        }

        $current = $this->nullableText(
            $existing['supporting_document_ref'] ?? null,
            500,
            'LEAVE_REQUEST_DOCUMENT_REFERENCE_INVALID'
        );
        if ($hasInput && $provided !== $current) {
            throw new DomainException('LEAVE_REQUEST_DOCUMENT_MANAGED_SEPARATELY');
        }

        return $current;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function storedDraftInput(array $request, int $actorId): array
    {
        return $this->normalizeDraftInput([
            'actor_id' => $actorId,
            'staff_user_id' => $request['staff_user_id'] ?? null,
            'leave_type_id' => $request['leave_type_id'] ?? null,
            'request_kind' => $request['request_kind'] ?? null,
            'parent_request_id' => $request['parent_request_id'] ?? null,
            'supersedes_id' => $request['supersedes_id'] ?? null,
            'from_at' => $request['from_at'] ?? null,
            'to_at' => $request['to_at'] ?? null,
            'timezone' => $request['timezone'] ?? null,
            'reason' => $request['reason'] ?? null,
            'reason_code' => $request['reason_code'] ?? null,
            'supporting_document_ref' => $request['supporting_document_ref'] ?? null,
            'create_idempotency_key' => $request['create_idempotency_key'] ?? null,
        ], $this->requestKind($request['request_kind'] ?? null), $request);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function draftQuote(array $input): array
    {
        // A draft needs the authoritative calendar allocation but is not
        // submission evidence: using a safely earlier server instant keeps
        // notice/retroactivity enforcement exclusively in submit().
        $draftCalculatedAt = $input['from_object']->modify('-100 years');

        return $this->policies->quote(
            $input['staff_user_id'],
            $input['leave_type_id'],
            $input['from_object'],
            $input['to_object'],
            $draftCalculatedAt
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function submissionQuote(array $input, DateTimeImmutable $submittedAt): array
    {
        return $this->policies->quote(
            $input['staff_user_id'],
            $input['leave_type_id'],
            $input['from_object'],
            $input['to_object'],
            $submittedAt
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $quote
     * @return array<string,mixed>
     */
    private function materializeDraft(array $input, array $quote): array
    {
        $this->assertQuote($quote, $input);
        $base = [
            'actor_id' => $input['actor_id'],
            'staff_user_id' => $input['staff_user_id'],
            'leave_type_id' => $input['leave_type_id'],
            'request_kind' => $input['request_kind'],
            'parent_request_id' => $input['parent_request_id'],
            'supersedes_id' => $input['supersedes_id'],
            'from_at' => (string) $quote['from_at'],
            'to_at' => (string) $quote['to_at'],
            'timezone' => (string) $quote['timezone'],
            'requested_units' => $this->canonicalUnits($quote['requested_units'] ?? null, 'LEAVE_REQUEST_UNITS_INVALID'),
            'requested_minutes' => $this->positiveInt($quote['requested_minutes'] ?? null, 'LEAVE_REQUEST_MINUTES_INVALID'),
            'reason' => $input['reason'],
            'reason_code' => $input['reason_code'],
            'supporting_document_ref' => $input['supporting_document_ref'],
            'create_idempotency_key' => $input['create_idempotency_key'],
        ];
        $base['request_hash'] = $this->requestHash($base);
        $base['request_days'] = $this->materializeDays((array) $quote['request_days'], $base['request_hash']);

        return $base;
    }

    /** @param list<array<string,mixed>> $days @return list<array<string,mixed>> */
    private function materializeDays(array $days, string $requestHash): array
    {
        if ($days === []) {
            throw new DomainException('LEAVE_REQUEST_DAYS_EMPTY');
        }
        $prepared = [];
        foreach ($days as $day) {
            if (!is_array($day)) {
                throw new DomainException('LEAVE_REQUEST_DAY_INVALID');
            }
            $kind = strtolower(trim((string) ($day['day_kind'] ?? '')));
            if (!in_array($kind, ['workday', 'partial', 'non_working'], true)) {
                throw new DomainException('LEAVE_REQUEST_DAY_INVALID');
            }
            $requestedUnits = $this->canonicalNonNegativeUnits(
                $day['requested_units'] ?? null,
                'LEAVE_REQUEST_DAY_UNITS_INVALID'
            );
            $consumedUnits = $this->canonicalNonNegativeUnits(
                $day['consumed_units'] ?? null,
                'LEAVE_REQUEST_DAY_UNITS_INVALID'
            );
            $requestedMinutes = $this->nonNegativeInt($day['requested_minutes'] ?? null, 'LEAVE_REQUEST_DAY_MINUTES_INVALID');
            $consumedMinutes = $this->nonNegativeInt($day['consumed_minutes'] ?? null, 'LEAVE_REQUEST_DAY_MINUTES_INVALID');
            if (($kind === 'non_working')
                && ($requestedUnits !== '0.000' || $consumedUnits !== '0.000' || $requestedMinutes !== 0 || $consumedMinutes !== 0)) {
                throw new DomainException('LEAVE_REQUEST_NON_WORKING_ALLOCATION_INVALID');
            }
            if ($kind !== 'non_working' && ($requestedUnits === '0.000' || $requestedMinutes === 0)) {
                throw new DomainException('LEAVE_REQUEST_DAY_INVALID');
            }
            $workDate = $this->dateKey($day['work_date'] ?? null, 'LEAVE_REQUEST_DAY_DATE_INVALID');
            $from = $this->nullableStoredInstant($day['from_at'] ?? null, 'LEAVE_REQUEST_DAY_WINDOW_INVALID');
            $to = $this->nullableStoredInstant($day['to_at'] ?? null, 'LEAVE_REQUEST_DAY_WINDOW_INVALID');
            if (($from === null) !== ($to === null)) {
                throw new DomainException('LEAVE_REQUEST_DAY_WINDOW_INVALID');
            }
            if ($from !== null && $to !== null && $to <= $from) {
                throw new DomainException('LEAVE_REQUEST_DAY_WINDOW_INVALID');
            }
            $periodKey = $this->nullableText(
                $day['entitlement_period_key'] ?? null,
                80,
                'LEAVE_REQUEST_DAY_PERIOD_INVALID'
            );
            if (($kind === 'non_working') !== ($periodKey === null)) {
                throw new DomainException('LEAVE_REQUEST_DAY_PERIOD_INVALID');
            }
            $preparedDay = [
                'work_date' => $workDate,
                'day_kind' => $kind,
                'from_at' => $from === null ? null : $this->databaseInstant($from),
                'to_at' => $to === null ? null : $this->databaseInstant($to),
                'requested_units' => $requestedUnits,
                'requested_minutes' => $requestedMinutes,
                'consumed_units' => $consumedUnits,
                'consumed_minutes' => $consumedMinutes,
                'entitlement_period_key' => $periodKey,
                'calendar_exception_id' => $this->nullablePositiveId(
                    $day['calendar_exception_id'] ?? null,
                    'LEAVE_REQUEST_DAY_CALENDAR_EXCEPTION_INVALID'
                ),
            ];
            $preparedDay['allocation_key'] = hash(
                'sha256',
                $this->encodeJson(['request_hash' => $requestHash, 'allocation' => $preparedDay], 'LEAVE_REQUEST_DAY_HASH_INVALID')
            );
            $prepared[] = $preparedDay;
        }

        return $prepared;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $quote */
    private function assertAttachmentRequirements(int $requestId, array $request, array $quote): void
    {
        $policy = $quote['policy'] ?? null;
        if (!is_array($policy)) {
            throw new DomainException('LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID');
        }
        $document = $this->nullableText(
            $request['supporting_document_ref'] ?? null,
            500,
            'LEAVE_REQUEST_DOCUMENT_REFERENCE_INVALID'
        );
        $requiresAttachment = (bool) ($policy['requires_attachment'] ?? false);
        $requiresMedicalDocument = (bool) ($policy['requires_medical_document'] ?? false);
        if (($requiresAttachment || $requiresMedicalDocument) && $document === null) {
            throw new DomainException('LEAVE_REQUEST_ATTACHMENT_REQUIRED');
        }
        if ($document === null) {
            return;
        }
        if ($this->attachments === null) {
            throw new DomainException('LEAVE_REQUEST_ATTACHMENT_CHECK_UNAVAILABLE');
        }
        $attachment = $this->attachments->currentAttachmentForRequestForUpdate($requestId);
        if (!is_array($attachment)
            || (int) ($attachment['request_id'] ?? 0) !== $requestId
            || (string) ($attachment['storage_ref'] ?? '') !== $document
            || (string) ($attachment['status'] ?? '') !== 'active') {
            throw new DomainException('LEAVE_REQUEST_ATTACHMENT_INVALID');
        }
        if ($requiresMedicalDocument
            && (string) ($attachment['attachment_kind'] ?? '') !== 'medical') {
            throw new DomainException('LEAVE_REQUEST_MEDICAL_ATTACHMENT_REQUIRED');
        }
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $parent */
    private function assertSuccessorWindow(array $input, array $parent): void
    {
        if ((string) ($parent['status'] ?? '') !== 'approved') {
            throw new DomainException('LEAVE_REQUEST_PARENT_NOT_APPROVED');
        }
        $parentTimezone = $this->timezone($parent['timezone'] ?? null, 'LEAVE_REQUEST_PARENT_INVALID');
        $parentFrom = $this->localDateTime($parent['from_at'] ?? null, $parentTimezone, 'LEAVE_REQUEST_PARENT_INVALID');
        $parentTo = $this->localDateTime($parent['to_at'] ?? null, $parentTimezone, 'LEAVE_REQUEST_PARENT_INVALID');
        $kind = $input['request_kind'];
        if ($kind === 'extension') {
            if ($input['from_object'] != $parentTo) {
                throw new DomainException('LEAVE_REQUEST_EXTENSION_MUST_BE_CONTIGUOUS');
            }

            return;
        }
        if ($input['from_object'] < $parentFrom || $input['to_object'] > $parentTo) {
            throw new DomainException('LEAVE_REQUEST_SUCCESSOR_WINDOW_INVALID');
        }
        if ($kind === 'early_return' && $input['from_object'] <= $parentFrom) {
            throw new DomainException('LEAVE_REQUEST_EARLY_RETURN_WINDOW_INVALID');
        }
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $request */
    private function assertSuccessorStillValid(array $input, array $request): void
    {
        if ($input['request_kind'] === 'leave') {
            return;
        }
        $parentId = $this->positiveId($input['parent_request_id'] ?? null, 'LEAVE_REQUEST_PARENT_INVALID');
        $parent = $this->requiredRequest($parentId);
        if ((int) ($parent['staff_user_id'] ?? 0) !== $input['staff_user_id']
            || (int) ($parent['leave_type_id'] ?? 0) !== $input['leave_type_id']) {
            throw new DomainException('LEAVE_REQUEST_PARENT_IDENTITY_INVALID');
        }
        $this->assertSuccessorWindow($input, $parent);
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @return list<array<string,mixed>>
     */
    private function withoutAllowedParentConflict(array $conflicts, string $kind, ?int $parentId): array
    {
        if (!in_array($kind, ['early_return', 'cancellation'], true) || $parentId === null) {
            return $conflicts;
        }

        return array_values(array_filter(
            $conflicts,
            static fn (array $conflict): bool => !(
                (string) ($conflict['resource_type'] ?? '') === 'leave_request'
                && (int) ($conflict['resource_id'] ?? 0) === $parentId
            )
        ));
    }

    /**
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $day
     */
    private function reserveDay(
        int $actorId,
        int $requestId,
        string $submissionKey,
        array $draft,
        array $quote,
        array $day
    ): void {
        $units = $this->canonicalNonNegativeUnits($day['requested_units'] ?? null, 'LEAVE_REQUEST_DAY_UNITS_INVALID');
        if ($units === '0.000') {
            return;
        }
        $dayId = $this->positiveId($day['id'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED');
        $account = $this->accountIdentity($draft, $quote, $day);
        $allocationKey = $this->hashValue($day['allocation_key'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED');
        $this->balances->record([
            'actor_id' => $actorId,
            'account' => $account,
            'leave_request_id' => $requestId,
            'request_day_id' => $dayId,
            'movement_type' => 'reserve',
            'units' => $units,
            'source_type' => 'leave_request',
            'source_id' => $requestId,
            'logical_key' => hash('sha256', 'leave-reserve:' . $requestId . ':' . $allocationKey),
            'idempotency_key' => 'leave-reserve:' . hash('sha256', $submissionKey . ':' . $dayId),
            'reason_code' => null,
        ]);
    }

    /**
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $day
     * @return array<string,mixed>
     */
    private function accountIdentity(array $draft, array $quote, array $day): array
    {
        $snapshot = $quote['policy_snapshot'] ?? null;
        if (!is_array($snapshot)) {
            throw new DomainException('LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID');
        }
        $timezone = $this->timezone($snapshot['timezone'] ?? null, 'LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID');
        $workDate = $this->localDateTime(
            $this->dateKey($day['work_date'] ?? null, 'LEAVE_REQUEST_DAY_DATE_INVALID') . ' 00:00:00',
            $timezone,
            'LEAVE_REQUEST_DAY_DATE_INVALID'
        );
        $serviceStart = null;
        if (!empty($snapshot['service_start_at'])) {
            $serviceStart = $this->localDateTime(
                (string) $snapshot['service_start_at'] . ' 00:00:00',
                $timezone,
                'LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID'
            );
        }
        $period = LeaveEntitlementPeriod::forWorkDate($workDate, $snapshot, $serviceStart);
        if (!hash_equals(
            (string) ($day['entitlement_period_key'] ?? ''),
            $period['key']
        )) {
            throw new DomainException('LEAVE_REQUEST_DAY_PERIOD_INVALID');
        }
        $negativeLimit = $this->canonicalNonNegativeUnits(
            $snapshot['negative_balance_limit_units_milli'] ?? null,
            'LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID'
        );

        return [
            'staff_user_id' => $draft['staff_user_id'],
            'leave_type_id' => $draft['leave_type_id'],
            'entitlement_period_key' => $period['key'],
            'period_from' => $period['period_from'],
            'period_to' => $period['period_to'],
            'negative_balance_limit_units' => $negativeLimit,
        ];
    }

    /**
     * @param array<string,mixed> $quote
     * @param array{workflow_version_id:int,snapshot:array<string,mixed>} $workflow
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $staffing
     * @return array<string,mixed>
     */
    private function submissionSnapshot(
        array $quote,
        array $workflow,
        array $draft,
        array $staffing,
        DateTimeImmutable $resolvedAt
    ): array {
        return [
            'schema_version' => 1,
            'resolved_at' => $this->databaseInstant($resolvedAt),
            'policy' => $quote['policy_snapshot'],
            'leave_type' => $quote['leave_type'],
            'assignment' => $quote['assignment'],
            'workflow' => $workflow,
            'staffing' => $staffing,
            'allocation' => [
                'requested_units' => $draft['requested_units'],
                'requested_minutes' => $draft['requested_minutes'],
                'day_count' => count($draft['request_days']),
                'allocation_hash' => hash('sha256', $this->encodeJson(
                    array_map(
                        static fn (array $day): array => [
                            'key' => $day['allocation_key'],
                            'date' => $day['work_date'],
                            'units' => $day['requested_units'],
                            'minutes' => $day['requested_minutes'],
                            'period' => $day['entitlement_period_key'],
                        ],
                        $draft['request_days']
                    ),
                    'LEAVE_REQUEST_POLICY_SNAPSHOT_INVALID'
                )),
            ],
        ];
    }

    /** @param array<string,mixed> $draft @param array<string,mixed> $quote @return array<string,mixed> */
    private function staffingAssessment(
        int $requestId,
        array $draft,
        array $quote,
        DateTimeImmutable $checkedAt
    ): array {
        if ($this->staffingPolicy === null) {
            throw new DomainException('LEAVE_STAFFING_POLICY_UNAVAILABLE');
        }

        $requestHash = (string) ($draft['request_hash'] ?? '');
        $approvedOverride = $this->staffingOverrideEvidence === null
            ? null
            : $this->staffingOverrideEvidence->approvedDecisionForRequestHashForUpdate($requestId, $requestHash);

        return $this->staffingPolicy->assess(
            $requestId,
            $draft,
            $quote,
            $checkedAt,
            $approvedOverride
        );
    }

    /** @param array<string,mixed> $workflow @return array{workflow_version_id:int,snapshot:array<string,mixed>} */
    private function normalizeWorkflow(array $workflow): array
    {
        $workflowVersionId = $this->positiveId(
            $workflow['workflow_version_id'] ?? null,
            'LEAVE_REQUEST_WORKFLOW_VERSION_INVALID'
        );
        if (!is_array($workflow['snapshot'] ?? null)) {
            throw new DomainException('LEAVE_REQUEST_WORKFLOW_SNAPSHOT_INVALID');
        }

        return ['workflow_version_id' => $workflowVersionId, 'snapshot' => $workflow['snapshot']];
    }

    /** @param array<string,mixed> $quote @param array<string,mixed> $input */
    private function assertQuote(array $quote, array $input): void
    {
        if ((int) ($quote['staff_user_id'] ?? 0) !== $input['staff_user_id']
            || (int) (($quote['leave_type']['id'] ?? null)) !== $input['leave_type_id']
            || !is_array($quote['policy'] ?? null)
            || !is_array($quote['policy_snapshot'] ?? null)
            || !is_array($quote['assignment'] ?? null)
            || !is_array($quote['request_days'] ?? null)
            || (string) ($quote['timezone'] ?? '') === '') {
            throw new DomainException('LEAVE_REQUEST_QUOTE_INVALID');
        }
        $this->canonicalUnits($quote['requested_units'] ?? null, 'LEAVE_REQUEST_QUOTE_INVALID');
        $this->positiveInt($quote['requested_minutes'] ?? null, 'LEAVE_REQUEST_QUOTE_INVALID');
    }

    /** @param list<array<string,mixed>> $stored @param list<array<string,mixed>> $expected */
    private function assertPersistedDays(array $stored, array $expected): void
    {
        if (count($stored) !== count($expected)) {
            throw new DomainException('LEAVE_REQUEST_DAY_PERSIST_FAILED');
        }
        foreach ($stored as $index => $storedDay) {
            $expectedDay = $expected[$index] ?? null;
            if (!is_array($storedDay)
                || !is_array($expectedDay)
                || $this->positiveId($storedDay['id'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED') <= 0
                || !hash_equals(
                    $this->hashValue($storedDay['allocation_key'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED'),
                    $this->hashValue($expectedDay['allocation_key'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED')
                )
                || (string) ($storedDay['work_date'] ?? '') !== (string) $expectedDay['work_date']
                || $this->canonicalNonNegativeUnits($storedDay['requested_units'] ?? null, 'LEAVE_REQUEST_DAY_PERSIST_FAILED')
                    !== (string) $expectedDay['requested_units']
                || (int) ($storedDay['requested_minutes'] ?? -1) !== (int) $expectedDay['requested_minutes']
                || (string) ($storedDay['entitlement_period_key'] ?? '') !== (string) ($expectedDay['entitlement_period_key'] ?? '')) {
                throw new DomainException('LEAVE_REQUEST_DAY_PERSIST_FAILED');
            }
        }
    }

    /** @param array<string,mixed> $request */
    private function requiredRequest(int $requestId): array
    {
        $request = $this->repository->requestForUpdate($requestId);
        if ($request === null) {
            throw new DomainException('LEAVE_REQUEST_NOT_FOUND');
        }

        return $request;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function receipt(array $request, bool $replayed): array
    {
        return [
            'replayed' => $replayed,
            'request_id' => (int) ($request['id'] ?? 0),
            'staff_user_id' => (int) ($request['staff_user_id'] ?? 0),
            'leave_type_id' => (int) ($request['leave_type_id'] ?? 0),
            'request_kind' => (string) ($request['request_kind'] ?? ''),
            'parent_request_id' => $this->nullablePositiveId($request['parent_request_id'] ?? null, 'LEAVE_REQUEST_RECEIPT_INVALID'),
            'status' => (string) ($request['status'] ?? ''),
            'lock_version' => (int) ($request['lock_version'] ?? 0),
            'requested_units' => $this->canonicalUnits($request['requested_units'] ?? null, 'LEAVE_REQUEST_RECEIPT_INVALID'),
            'requested_minutes' => (int) ($request['requested_minutes'] ?? 0),
            'policy_version_id' => $this->nullablePositiveId($request['policy_version_id'] ?? null, 'LEAVE_REQUEST_RECEIPT_INVALID'),
            'workflow_version_id' => $this->nullablePositiveId($request['workflow_version_id'] ?? null, 'LEAVE_REQUEST_RECEIPT_INVALID'),
            'workflow_instance_id' => $this->nullablePositiveId($request['workflow_instance_id'] ?? null, 'LEAVE_REQUEST_RECEIPT_INVALID'),
            'assignment_id' => $this->nullablePositiveId($request['assignment_id'] ?? null, 'LEAVE_REQUEST_RECEIPT_INVALID'),
        ];
    }

    /** @param array<string,mixed> $draft */
    private function requestHash(array $draft): string
    {
        return hash('sha256', $this->encodeJson([
            'staff_user_id' => $draft['staff_user_id'],
            'leave_type_id' => $draft['leave_type_id'],
            'request_kind' => $draft['request_kind'],
            'parent_request_id' => $draft['parent_request_id'],
            'supersedes_id' => $draft['supersedes_id'],
            'from_at' => $draft['from_at'],
            'to_at' => $draft['to_at'],
            'timezone' => $draft['timezone'],
            'requested_units' => $draft['requested_units'],
            'requested_minutes' => $draft['requested_minutes'],
            'reason_code' => $draft['reason_code'],
            'reason_hash' => $draft['reason'] === null ? null : hash('sha256', $draft['reason']),
            'document_ref_hash' => $draft['supporting_document_ref'] === null
                ? null
                : hash('sha256', $draft['supporting_document_ref']),
        ], 'LEAVE_REQUEST_HASH_INVALID'));
    }

    private function assertSelfActor(int $actorId, int $staffUserId): void
    {
        if ($actorId !== $staffUserId) {
            throw new DomainException('LEAVE_REQUEST_OWNER_ONLY');
        }
    }

    private function requestKind(mixed $value): string
    {
        $kind = strtolower(trim((string) $value));
        if (!in_array($kind, self::REQUEST_KINDS, true)) {
            throw new InvalidArgumentException('LEAVE_REQUEST_KIND_INVALID');
        }

        return $kind;
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock->now();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('LEAVE_REQUEST_CLOCK_INVALID');
        }

        return $now;
    }

    private function timezone(mixed $value, string $error): DateTimeZone
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($error);
        }
        try {
            return new DateTimeZone(trim($value));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($error, 0, $exception);
        }
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

    private function nullableStoredInstant(mixed $value, string $error): ?DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($error, 0, $exception);
        }
    }

    private function dateKey(mixed $value, string $error): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException($error);
        }
        try {
            $date = new DateTimeImmutable($value . ' 00:00:00', new DateTimeZone('UTC'));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($error, 0, $exception);
        }
        if ($date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function canonicalUnits(mixed $value, string $error): string
    {
        $milli = LeaveUnits::fromDecimal($value, false, $error);
        if ($milli <= 0) {
            throw new InvalidArgumentException($error);
        }

        return LeaveUnits::format($milli);
    }

    private function canonicalNonNegativeUnits(mixed $value, string $error): string
    {
        return LeaveUnits::format(LeaveUnits::fromDecimal($value, false, $error));
    }

    private function hashValue(mixed $value, string $error): string
    {
        $hash = strtolower(trim((string) $value));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return $hash;
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, $error);
    }

    private function assertPositiveId(mixed $value, string $error): void
    {
        $this->positiveId($value, $error);
    }

    private function positiveInt(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function nonNegativeInt(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
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

    /** @param array<string,mixed> $value */
    private function encodeJson(array $value, string $error): string
    {
        try {
            return json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $exception) {
            throw new DomainException($error, 0, $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
