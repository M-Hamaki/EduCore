<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyRepository;
use EduCore\Modules\Attendance\Contracts\ScheduleChangeAuthorization;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

/** Transaction-owning command owner for temporary schedule changes. */
final class ScheduleChangeRequestService
{
    private const CHANGE_TYPES = ['temporary_shift', 'shift_swap', 'overtime', 'alternative_attendance'];
    private const OVERLAP_STATUSES = ['pending_counterpart', 'submitted', 'approved'];

    private AttendanceTransactionManager $transactions;
    private SchedulePolicyRepository $repository;
    private AuditEventWriter $audit;
    private ScheduleChangeAuthorization $authorization;

    public function __construct(
        AttendanceTransactionManager $transactions,
        SchedulePolicyRepository $repository,
        AuditEventWriter $audit,
        ScheduleChangeAuthorization $authorization
    ) {
        $this->transactions = $transactions;
        $this->repository = $repository;
        $this->audit = $audit;
        $this->authorization = $authorization;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function submit(int $actorId, array $payload, string $idempotencyKey): array
    {
        $this->assertPositive($actorId, 'SCHEDULE_CHANGE_ACTOR_INVALID');
        $staffId = (int) ($payload['staff_user_id'] ?? 0);
        $this->assertPositive($staffId, 'SCHEDULE_CHANGE_STAFF_INVALID');
        if (!$this->authorization->canSubmit($actorId, $staffId, $payload)) {
            throw new DomainException('SCHEDULE_CHANGE_SUBMIT_FORBIDDEN');
        }
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->transactions->transactional(function () use ($actorId, $payload, $idempotencyKey): array {
            $request = $this->normalizeSubmission($payload, $actorId, $idempotencyKey);
            $payloadHash = $this->payloadHash(['actor_id' => $actorId, 'request' => $request]);
            $replay = $this->replayReceipt('submit_schedule_change', $idempotencyKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            $legacyReplay = $this->repository->findChangeRequestByIdempotency($idempotencyKey);
            if ($legacyReplay !== null) {
                if (!hash_equals((string) ($legacyReplay['payload_hash'] ?? ''), $payloadHash)) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT');
                }
                return $this->requestReceipt($legacyReplay);
            }

            $request['payload_hash'] = $payloadHash;
            $participants = $this->participants($request);
            $this->repository->lockChangeParticipants($participants);
            $this->assertNoOverlap(
                $participants,
                new DateTimeImmutable((string) $request['from_at']),
                new DateTimeImmutable((string) $request['to_at']),
                null,
                self::OVERLAP_STATUSES
            );
            $requestId = $this->repository->insertChangeRequest($request);
            $this->audit->recordEvent(
                'staff_schedule_change_submitted',
                'staff_schedule_change_request',
                $requestId,
                null,
                [
                    'staff_user_id' => $request['staff_user_id'],
                    'change_type' => $request['change_type'],
                    'from_at' => $request['from_at'],
                    'to_at' => $request['to_at'],
                    'counterpart_staff_id' => $request['counterpart_staff_id'],
                    'status' => $request['status'],
                    'idempotency_hash' => hash('sha256', $idempotencyKey),
                ],
                ['user_id' => $actorId]
            );
            $result = [
                'id' => $requestId,
                'staff_user_id' => $request['staff_user_id'],
                'change_type' => $request['change_type'],
                'status' => $request['status'],
                'lock_version' => 1,
            ];
            $this->recordReceipt(
                'submit_schedule_change',
                $requestId,
                $idempotencyKey,
                $payloadHash,
                $result,
                $actorId
            );

            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function acceptSwap(
        int $requestId,
        int $counterpartActorId,
        int $expectedLockVersion,
        DateTimeImmutable $acceptedAt,
        string $idempotencyKey
    ): array {
        $this->assertPositive($requestId, 'SCHEDULE_CHANGE_ID_INVALID');
        $this->assertPositive($counterpartActorId, 'SCHEDULE_CHANGE_ACTOR_INVALID');
        $this->assertPositive($expectedLockVersion, 'SCHEDULE_CHANGE_LOCK_INVALID');
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->transactions->transactional(function () use (
            $requestId,
            $counterpartActorId,
            $expectedLockVersion,
            $acceptedAt,
            $idempotencyKey
        ): array {
            $payloadHash = $this->payloadHash([
                'request_id' => $requestId,
                'actor_id' => $counterpartActorId,
                'expected_lock_version' => $expectedLockVersion,
            ]);
            $replay = $this->replayReceipt('accept_schedule_swap', $idempotencyKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            $current = $this->repository->changeRequestForUpdate($requestId);
            if ($current === null) {
                throw new DomainException('SCHEDULE_CHANGE_NOT_FOUND');
            }
            if (($current['change_type'] ?? '') !== 'shift_swap') {
                throw new DomainException('SCHEDULE_CHANGE_NOT_SWAP');
            }
            if ((int) ($current['counterpart_staff_id'] ?? 0) !== $counterpartActorId) {
                throw new DomainException('SWAP_COUNTERPART_ONLY');
            }
            if (($current['status'] ?? '') !== 'pending_counterpart') {
                throw new DomainException('SWAP_NOT_PENDING_COUNTERPART');
            }
            if ((int) ($current['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('SCHEDULE_CHANGE_STALE');
            }
            $changes = [
                'status' => 'submitted',
                'counterpart_accepted_by' => $counterpartActorId,
                'counterpart_accepted_at' => $this->instant($acceptedAt),
                'last_command_key' => $idempotencyKey,
                'last_command_payload_hash' => $payloadHash,
            ];
            if (!$this->repository->updateChangeRequest($requestId, $expectedLockVersion, $changes)) {
                throw new DomainException('SCHEDULE_CHANGE_STALE');
            }
            $this->audit->recordEvent(
                'staff_schedule_swap_accepted',
                'staff_schedule_change_request',
                $requestId,
                null,
                ['counterpart_staff_id' => $counterpartActorId, 'from_status' => 'pending_counterpart', 'to_status' => 'submitted'],
                ['user_id' => $counterpartActorId]
            );
            $result = [
                'id' => $requestId,
                'staff_user_id' => (int) $current['staff_user_id'],
                'change_type' => 'shift_swap',
                'status' => 'submitted',
                'lock_version' => $expectedLockVersion + 1,
                'counterpart_accepted_by' => $counterpartActorId,
                'counterpart_accepted_at' => $this->instant($acceptedAt),
            ];
            $this->recordReceipt('accept_schedule_swap', $requestId, $idempotencyKey, $payloadHash, $result, $counterpartActorId);

            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function linkWorkflow(
        int $requestId,
        int $workflowInstanceId,
        int $actorId,
        int $expectedLockVersion,
        string $idempotencyKey
    ): array {
        $this->assertPositive($requestId, 'SCHEDULE_CHANGE_ID_INVALID');
        $this->assertPositive($workflowInstanceId, 'SCHEDULE_CHANGE_WORKFLOW_INVALID');
        $this->assertPositive($actorId, 'SCHEDULE_CHANGE_ACTOR_INVALID');
        $this->assertPositive($expectedLockVersion, 'SCHEDULE_CHANGE_LOCK_INVALID');
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->transactions->transactional(function () use (
            $requestId,
            $workflowInstanceId,
            $actorId,
            $expectedLockVersion,
            $idempotencyKey
        ): array {
            $payloadHash = $this->payloadHash([
                'request_id' => $requestId,
                'workflow_instance_id' => $workflowInstanceId,
                'actor_id' => $actorId,
                'expected_lock_version' => $expectedLockVersion,
            ]);
            $replay = $this->replayReceipt('link_schedule_change_workflow', $idempotencyKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            $current = $this->repository->changeRequestForUpdate($requestId);
            if ($current === null) {
                throw new DomainException('SCHEDULE_CHANGE_NOT_FOUND');
            }
            if ((int) ($current['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('SCHEDULE_CHANGE_STALE');
            }
            if (!in_array((string) ($current['status'] ?? ''), ['pending_counterpart', 'submitted'], true)) {
                throw new DomainException('SCHEDULE_CHANGE_WORKFLOW_LINK_FORBIDDEN');
            }
            if ((int) ($current['workflow_instance_id'] ?? 0) > 0) {
                throw new DomainException('SCHEDULE_CHANGE_WORKFLOW_ALREADY_LINKED');
            }
            if (!$this->authorization->canLinkWorkflow($actorId, $current, $workflowInstanceId)) {
                throw new DomainException('SCHEDULE_CHANGE_WORKFLOW_MISMATCH');
            }
            $changes = [
                'workflow_instance_id' => $workflowInstanceId,
                'last_command_key' => $idempotencyKey,
                'last_command_payload_hash' => $payloadHash,
            ];
            if (!$this->repository->updateChangeRequest($requestId, $expectedLockVersion, $changes)) {
                throw new DomainException('SCHEDULE_CHANGE_STALE');
            }
            $this->audit->recordEvent(
                'staff_schedule_change_workflow_linked',
                'staff_schedule_change_request',
                $requestId,
                null,
                ['workflow_instance_id' => $workflowInstanceId],
                ['user_id' => $actorId]
            );
            $result = [
                'id' => $requestId,
                'staff_user_id' => (int) $current['staff_user_id'],
                'change_type' => (string) $current['change_type'],
                'status' => (string) $current['status'],
                'workflow_instance_id' => $workflowInstanceId,
                'lock_version' => $expectedLockVersion + 1,
            ];
            $this->recordReceipt(
                'link_schedule_change_workflow',
                $requestId,
                $idempotencyKey,
                $payloadHash,
                $result,
                $actorId
            );

            return $result;
        });
    }

    /** @param array<string,mixed> $approvedSnapshot @return array<string,mixed> */
    public function approve(
        int $requestId,
        int $actorId,
        int $expectedLockVersion,
        array $approvedSnapshot,
        DateTimeImmutable $approvedAt,
        string $idempotencyKey
    ): array {
        $this->assertPositive($requestId, 'SCHEDULE_CHANGE_ID_INVALID');
        $this->assertPositive($actorId, 'SCHEDULE_CHANGE_ACTOR_INVALID');
        $this->assertPositive($expectedLockVersion, 'SCHEDULE_CHANGE_LOCK_INVALID');
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->transactions->transactional(function () use (
            $requestId,
            $actorId,
            $expectedLockVersion,
            $approvedSnapshot,
            $approvedAt,
            $idempotencyKey
        ): array {
            $payloadHash = $this->payloadHash([
                'request_id' => $requestId,
                'actor_id' => $actorId,
                'expected_lock_version' => $expectedLockVersion,
                'approved_snapshot' => $approvedSnapshot,
            ]);
            $replay = $this->replayReceipt('approve_schedule_change', $idempotencyKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            $current = $this->repository->changeRequestForUpdate($requestId);
            if ($current === null) {
                throw new DomainException('SCHEDULE_CHANGE_NOT_FOUND');
            }
            if ((int) ($current['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('SCHEDULE_CHANGE_STALE');
            }
            if (($current['change_type'] ?? '') === 'shift_swap'
                && (($current['status'] ?? '') === 'pending_counterpart'
                    || (int) ($current['counterpart_accepted_by'] ?? 0) <= 0
                    || empty($current['counterpart_accepted_at']))) {
                throw new DomainException('SWAP_COUNTERPART_ACCEPTANCE_REQUIRED');
            }
            if (($current['status'] ?? '') !== 'submitted') {
                throw new DomainException('SCHEDULE_CHANGE_NOT_SUBMITTED');
            }
            if (!$this->authorization->canApprove($actorId, $current)) {
                throw new DomainException('SCHEDULE_CHANGE_APPROVAL_FORBIDDEN');
            }

            $snapshot = $this->normalizeApprovedSnapshot($current, $approvedSnapshot);
            $from = new DateTimeImmutable((string) $current['from_at']);
            $to = new DateTimeImmutable((string) $current['to_at']);
            $participants = $this->participants($current);
            $this->repository->lockChangeParticipants($participants);
            $this->assertNoOverlap($participants, $from, $to, $requestId, ['approved']);
            $changes = [
                'status' => 'approved',
                'approved_schedule_snapshot' => $snapshot,
                'approved_by' => $actorId,
                'approved_at' => $this->instant($approvedAt),
                'last_command_key' => $idempotencyKey,
                'last_command_payload_hash' => $payloadHash,
            ];
            if (!$this->repository->updateChangeRequest($requestId, $expectedLockVersion, $changes)) {
                throw new DomainException('SCHEDULE_CHANGE_STALE');
            }
            $this->audit->recordEvent(
                'staff_schedule_change_approved',
                'staff_schedule_change_request',
                $requestId,
                null,
                [
                    'staff_user_id' => (int) $current['staff_user_id'],
                    'change_type' => (string) $current['change_type'],
                    'from_at' => (string) $current['from_at'],
                    'to_at' => (string) $current['to_at'],
                    'from_status' => 'submitted',
                    'to_status' => 'approved',
                ],
                ['user_id' => $actorId]
            );
            $result = [
                'id' => $requestId,
                'staff_user_id' => (int) $current['staff_user_id'],
                'change_type' => (string) $current['change_type'],
                'status' => 'approved',
                'lock_version' => $expectedLockVersion + 1,
                'approved_by' => $actorId,
                'approved_at' => $this->instant($approvedAt),
            ];
            $this->recordReceipt('approve_schedule_change', $requestId, $idempotencyKey, $payloadHash, $result, $actorId);

            return $result;
        });
    }

    /** @return array<string,mixed> */
    private function normalizeSubmission(array $payload, int $actorId, string $idempotencyKey): array
    {
        $staffId = (int) ($payload['staff_user_id'] ?? 0);
        $this->assertPositive($staffId, 'SCHEDULE_CHANGE_STAFF_INVALID');
        $type = trim((string) ($payload['change_type'] ?? ''));
        if (!in_array($type, self::CHANGE_TYPES, true)) {
            throw new InvalidArgumentException('SCHEDULE_CHANGE_TYPE_INVALID');
        }
        $from = $this->parseInstant($payload['from_at'] ?? null, 'SCHEDULE_CHANGE_FROM_INVALID');
        $to = $this->parseInstant($payload['to_at'] ?? null, 'SCHEDULE_CHANGE_TO_INVALID');
        if ($to <= $from) {
            throw new InvalidArgumentException('SCHEDULE_CHANGE_WINDOW_INVALID');
        }
        $counterpartId = isset($payload['counterpart_staff_id']) ? (int) $payload['counterpart_staff_id'] : null;
        if ($type === 'shift_swap') {
            if ($counterpartId === null || $counterpartId <= 0 || $counterpartId === $staffId) {
                throw new InvalidArgumentException('SCHEDULE_CHANGE_COUNTERPART_INVALID');
            }
        } elseif ($counterpartId !== null && $counterpartId !== 0) {
            throw new InvalidArgumentException('SCHEDULE_CHANGE_COUNTERPART_INVALID');
        } else {
            $counterpartId = null;
        }
        $requestedVersionId = isset($payload['requested_schedule_version_id'])
            ? (int) $payload['requested_schedule_version_id']
            : null;
        if (in_array($type, ['temporary_shift', 'shift_swap'], true)) {
            if ($requestedVersionId === null || $requestedVersionId <= 0) {
                throw new InvalidArgumentException('SCHEDULE_CHANGE_VERSION_REQUIRED');
            }
            $version = $this->repository->findVersion($requestedVersionId);
            if ($version === null || ($version['state'] ?? '') !== 'published') {
                throw new DomainException('SCHEDULE_CHANGE_VERSION_NOT_PUBLISHED');
            }
            WorkSchedule::fromArray((array) ($version['schedule'] ?? []));
        } else {
            $requestedVersionId = null;
        }
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('SCHEDULE_CHANGE_REASON_INVALID');
        }
        if (isset($payload['workflow_instance_id']) && (int) $payload['workflow_instance_id'] > 0) {
            throw new InvalidArgumentException('SCHEDULE_CHANGE_WORKFLOW_MUST_BE_LINKED');
        }

        return [
            'staff_user_id' => $staffId,
            'change_type' => $type,
            'from_at' => $this->instant($from),
            'to_at' => $this->instant($to),
            'counterpart_staff_id' => $counterpartId,
            'requested_schedule_version_id' => $requestedVersionId,
            'reason' => $reason,
            'workflow_instance_id' => null,
            'status' => $type === 'shift_swap' ? 'pending_counterpart' : 'submitted',
            'approved_schedule_snapshot' => null,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actorId,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeApprovedSnapshot(array $current, array $snapshot): array
    {
        $type = (string) $current['change_type'];
        if ($type === 'temporary_shift' && $snapshot === []) {
            $version = $this->repository->findVersion((int) $current['requested_schedule_version_id']);
            if ($version === null || ($version['state'] ?? '') !== 'published') {
                throw new DomainException('SCHEDULE_CHANGE_VERSION_NOT_PUBLISHED');
            }
            $snapshot = ['schedule' => $version['schedule']];
        } elseif (in_array($type, ['overtime', 'alternative_attendance'], true) && $snapshot === []) {
            $snapshot = [
                'change_type' => $type,
                'from_at' => (string) $current['from_at'],
                'to_at' => (string) $current['to_at'],
            ];
        }

        try {
            if ($type === 'shift_swap') {
                $staffSchedules = $snapshot['staff_schedules'] ?? null;
                if (!is_array($staffSchedules)) {
                    throw new DomainException('SWAP_APPROVED_SNAPSHOTS_REQUIRED');
                }
                foreach ([(string) $current['staff_user_id'], (string) $current['counterpart_staff_id']] as $staffId) {
                    $staffSnapshot = $staffSchedules[$staffId] ?? null;
                    $schedule = is_array($staffSnapshot) && isset($staffSnapshot['schedule'])
                        ? $staffSnapshot['schedule']
                        : $staffSnapshot;
                    if (!is_array($schedule)) {
                        throw new DomainException('SWAP_APPROVED_SNAPSHOTS_REQUIRED');
                    }
                    WorkSchedule::fromArray($schedule);
                }
            } elseif ($type === 'temporary_shift') {
                $schedule = $snapshot['schedule'] ?? $snapshot;
                WorkSchedule::fromArray((array) $schedule);
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof DomainException && $exception->getMessage() === 'SWAP_APPROVED_SNAPSHOTS_REQUIRED') {
                throw $exception;
            }
            throw new DomainException('SCHEDULE_CHANGE_SNAPSHOT_INVALID', 0, $exception);
        }

        return $snapshot;
    }

    /** @return list<int> */
    private function participants(array $request): array
    {
        $ids = [(int) ($request['staff_user_id'] ?? 0)];
        if ((int) ($request['counterpart_staff_id'] ?? 0) > 0) {
            $ids[] = (int) $request['counterpart_staff_id'];
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    private function assertNoOverlap(
        array $staffIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $excludeRequestId,
        array $statuses
    ): void {
        $conflicts = $this->repository->overlappingChangeRequests(
            $staffIds,
            $from,
            $to,
            $statuses,
            $excludeRequestId
        );
        if ($conflicts === []) {
            return;
        }
        $ids = array_values(array_unique(array_map(
            static fn (array $request): int => (int) ($request['id'] ?? 0),
            $conflicts
        )));
        sort($ids, SORT_NUMERIC);
        throw new DomainException('SCHEDULE_CHANGE_OVERLAP:' . implode(',', $ids));
    }

    /** @return array<string,mixed>|null */
    private function replayReceipt(string $commandType, string $key, string $payloadHash): ?array
    {
        $receipt = $this->repository->findCommandReceipt($key);
        if ($receipt === null) {
            return null;
        }
        if (($receipt['command_type'] ?? '') !== $commandType
            || !hash_equals((string) ($receipt['payload_hash'] ?? ''), $payloadHash)) {
            throw new DomainException('IDEMPOTENCY_CONFLICT');
        }
        $result = $receipt['result_json'] ?? null;
        if (is_string($result)) {
            $result = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($result)) {
            throw new RuntimeException('SCHEDULE_COMMAND_RECEIPT_INVALID');
        }

        return $result;
    }

    private function recordReceipt(
        string $commandType,
        int $requestId,
        string $key,
        string $payloadHash,
        array $result,
        int $actorId
    ): void {
        $this->repository->recordCommandReceipt([
            'command_type' => $commandType,
            'resource_type' => 'staff_schedule_change_request',
            'resource_id' => $requestId,
            'idempotency_key' => $key,
            'payload_hash' => $payloadHash,
            'result_json' => $result,
            'actor_user_id' => $actorId,
        ]);
    }

    /** @return array<string,mixed> */
    private function requestReceipt(array $request): array
    {
        return [
            'id' => (int) ($request['id'] ?? 0),
            'staff_user_id' => (int) ($request['staff_user_id'] ?? 0),
            'change_type' => (string) ($request['change_type'] ?? ''),
            'status' => (string) ($request['status'] ?? ''),
            'lock_version' => (int) ($request['lock_version'] ?? 1),
        ];
    }

    private function parseInstant(mixed $value, string $error): DateTimeImmutable
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($error);
        }
        try {
            return new DateTimeImmutable($text);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($error, 0, $exception);
        }
    }

    private function instant(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.u');
    }

    private function assertPositive(int $value, string $error): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException($error);
        }
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 190) {
            throw new InvalidArgumentException('SCHEDULE_IDEMPOTENCY_KEY_INVALID');
        }

        return $key;
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
