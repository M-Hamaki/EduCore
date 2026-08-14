<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendanceAdjustmentAuthorization;
use EduCore\Modules\Attendance\Contracts\AttendanceAdjustmentRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use JsonException;

/**
 * Owns the correction-request state machine and the immutable official-day
 * successor created by an independently authorized approval.
 */
final class AttendanceAdjustmentService
{
    public const ENGINE_VERSION = 'attendance-adjustment-v1';

    /** @var list<string> */
    private const REQUESTER_KINDS = ['self', 'manager', 'hr'];

    /** @var list<string> */
    private const PROPOSABLE_INTEGER_FIELDS = [
        'worked_minutes',
        'covered_late_minutes',
        'covered_early_minutes',
        'mission_minutes',
        'leave_minutes',
        'late_minutes',
        'early_leave_minutes',
        'missing_minutes',
    ];

    /** @var list<string> */
    private const PROPOSABLE_DATETIME_FIELDS = ['first_in', 'last_out'];

    /** @var list<string> */
    private const PROPOSABLE_STATUSES = ['present', 'absent', 'partial', 'non_working'];

    private DateTimeZone $utc;

    public function __construct(
        private AttendanceTransactionManager $transactions,
        private AttendanceAdjustmentRepository $repository,
        private AttendanceAdjustmentAuthorization $authorization,
        private AuditEventWriter $audit
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    /** @return list<array<string,mixed>> */
    public function forRequester(int $actorId, int $limit = 50): array
    {
        $this->assertPositiveId($actorId, 'ATTENDANCE_ADJUSTMENT_ACTOR_INVALID');
        $this->authorization->assertCanAct($actorId, $actorId, 'self', 'request', null, new DateTimeImmutable('now', $this->utc));
        return $this->repository->adjustmentsForRequester($actorId, $limit);
    }

    /** @return list<array<string,mixed>> */
    public function pendingForReviewer(int $actorId, int $limit = 100): array
    {
        $this->assertPositiveId($actorId, 'ATTENDANCE_ADJUSTMENT_ACTOR_INVALID');
        $visible = [];
        $now = new DateTimeImmutable('now', $this->utc);
        foreach ($this->repository->pendingAdjustments($limit) as $adjustment) {
            try {
                $this->authorization->assertCanAct(
                    $actorId,
                    (int) $adjustment['staff_user_id'],
                    (string) $adjustment['requester_kind'],
                    'decide',
                    isset($adjustment['workflow_instance_id']) ? (int) $adjustment['workflow_instance_id'] : null,
                    $now
                );
                $visible[] = $adjustment;
            } catch (DomainException) {
                continue;
            }
        }
        return $visible;
    }

    /**
     * @param array<string,mixed> $proposedValues
     * @return array<string,mixed>
     */
    public function createDraft(
        int $actorId,
        int $staffUserId,
        string $requesterKind,
        string $workDate,
        string $reason,
        array $proposedValues,
        string $idempotencyKey
    ): array {
        $this->assertPositiveId($actorId, 'ATTENDANCE_ADJUSTMENT_ACTOR_INVALID');
        $this->assertPositiveId($staffUserId, 'ATTENDANCE_ADJUSTMENT_STAFF_INVALID');
        $requesterKind = $this->requesterKind($requesterKind);
        if ($requesterKind === 'self' && $actorId !== $staffUserId) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_SELF_REQUESTER_MISMATCH');
        }
        $workDate = $this->workDate($workDate);
        $reason = $this->requiredText($reason, 2000, 'ATTENDANCE_ADJUSTMENT_REASON_REQUIRED');
        $proposedValues = $this->normalizeProposedValues($proposedValues);
        $proposedJson = $this->encodeJson($proposedValues, 'ATTENDANCE_ADJUSTMENT_PROPOSED_VALUES_INVALID');
        $idempotencyKey = $this->requiredText(
            $idempotencyKey,
            190,
            'ATTENDANCE_ADJUSTMENT_IDEMPOTENCY_KEY_INVALID'
        );
        $now = new DateTimeImmutable('now', $this->utc);
        $this->authorization->assertCanAct(
            $actorId,
            $staffUserId,
            $requesterKind,
            'request',
            null,
            $now
        );

        return $this->transactions->transactional(function () use (
            $actorId,
            $staffUserId,
            $requesterKind,
            $workDate,
            $reason,
            $proposedValues,
            $proposedJson,
            $idempotencyKey,
            $now
        ): array {
            $existing = $this->repository->adjustmentByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if ($this->sameDraftRequest(
                    $existing,
                    $actorId,
                    $staffUserId,
                    $requesterKind,
                    $workDate,
                    $reason,
                    $proposedValues
                )) {
                    return $this->receipt($existing, true);
                }
                throw new DomainException('ATTENDANCE_ADJUSTMENT_IDEMPOTENCY_CONFLICT');
            }

            $before = $this->repository->currentOfficialDayForUpdate($staffUserId, $workDate);
            if ($before === null) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_SOURCE_NOT_FOUND');
            }
            $this->validateProposedValuesForDay($before, $proposedValues);
            $adjustmentId = $this->repository->insertAdjustment([
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate,
                'requester_id' => $actorId,
                'requester_kind' => $requesterKind,
                'reason' => $reason,
                'before_version_id' => (int) $before['id'],
                'proposed_values' => $proposedJson,
                'workflow_instance_id' => null,
                'status' => 'draft',
                'submitted_at' => null,
                'approved_version_id' => null,
                'resolution_comment' => null,
                'idempotency_key' => $idempotencyKey,
                'lock_version' => 1,
            ]);
            $stored = [
                'id' => $adjustmentId,
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate,
                'requester_id' => $actorId,
                'requester_kind' => $requesterKind,
                'before_version_id' => (int) $before['id'],
                'workflow_instance_id' => null,
                'status' => 'draft',
                'approved_version_id' => null,
                'lock_version' => 1,
            ];
            $this->audit->recordEvent(
                'staff_attendance_adjustment_drafted',
                'staff_attendance_adjustments',
                $adjustmentId,
                null,
                [
                    'staff_user_id' => $staffUserId,
                    'work_date' => $workDate,
                    'requester_kind' => $requesterKind,
                    'before_version_id' => (int) $before['id'],
                    'reason_hash' => hash('sha256', $reason),
                    'proposed_fields' => array_keys($proposedValues),
                    'proposed_values_hash' => hash('sha256', $proposedJson),
                ],
                ['user_id' => $actorId]
            );
            return $this->receipt($stored, false);
        });
    }

    /** @return array<string,mixed> */
    public function submit(
        int $actorId,
        int $adjustmentId,
        int $expectedLockVersion,
        ?int $workflowInstanceId = null
    ): array {
        $this->assertPositiveId($actorId, 'ATTENDANCE_ADJUSTMENT_ACTOR_INVALID');
        $this->assertPositiveId($adjustmentId, 'ATTENDANCE_ADJUSTMENT_ID_INVALID');
        $this->assertPositiveId($expectedLockVersion, 'ATTENDANCE_ADJUSTMENT_LOCK_INVALID');
        if ($workflowInstanceId !== null) {
            $this->assertPositiveId($workflowInstanceId, 'ATTENDANCE_ADJUSTMENT_WORKFLOW_INVALID');
        }
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $adjustmentId,
            $expectedLockVersion,
            $workflowInstanceId,
            $now
        ): array {
            $adjustment = $this->requiredAdjustment($adjustmentId);
            $this->assertRequesterActor($adjustment, $actorId);
            $this->authorization->assertCanAct(
                $actorId,
                (int) $adjustment['staff_user_id'],
                (string) $adjustment['requester_kind'],
                'submit',
                $workflowInstanceId,
                $now
            );
            if (($adjustment['status'] ?? null) === 'pending') {
                if (($adjustment['workflow_instance_id'] ?? null) == $workflowInstanceId) {
                    return $this->receipt($adjustment, true);
                }
                throw new DomainException('ATTENDANCE_ADJUSTMENT_STALE');
            }
            if (($adjustment['status'] ?? null) !== 'draft'
                || (int) ($adjustment['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_STALE');
            }
            if (!$this->repository->submitAdjustment($adjustmentId, $expectedLockVersion, $workflowInstanceId, $now)) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_STALE');
            }
            $adjustment['status'] = 'pending';
            $adjustment['workflow_instance_id'] = $workflowInstanceId;
            $adjustment['submitted_at'] = $this->databaseInstant($now);
            $adjustment['lock_version'] = $expectedLockVersion + 1;
            $this->audit->recordEvent(
                'staff_attendance_adjustment_submitted',
                'staff_attendance_adjustments',
                $adjustmentId,
                null,
                [
                    'staff_user_id' => (int) $adjustment['staff_user_id'],
                    'before_version_id' => (int) $adjustment['before_version_id'],
                    'workflow_instance_id' => $workflowInstanceId,
                ],
                ['user_id' => $actorId]
            );
            return $this->receipt($adjustment, false);
        });
    }

    /** @return array<string,mixed> */
    public function cancel(
        int $actorId,
        int $adjustmentId,
        int $expectedLockVersion,
        string $comment
    ): array {
        $this->assertPositiveId($actorId, 'ATTENDANCE_ADJUSTMENT_ACTOR_INVALID');
        $this->assertPositiveId($adjustmentId, 'ATTENDANCE_ADJUSTMENT_ID_INVALID');
        $this->assertPositiveId($expectedLockVersion, 'ATTENDANCE_ADJUSTMENT_LOCK_INVALID');
        $comment = $this->requiredText($comment, 2000, 'ATTENDANCE_ADJUSTMENT_CANCELLATION_COMMENT_REQUIRED');
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $adjustmentId,
            $expectedLockVersion,
            $comment,
            $now
        ): array {
            $adjustment = $this->requiredAdjustment($adjustmentId);
            $this->assertRequesterActor($adjustment, $actorId);
            $this->authorization->assertCanAct(
                $actorId,
                (int) $adjustment['staff_user_id'],
                (string) $adjustment['requester_kind'],
                'cancel',
                isset($adjustment['workflow_instance_id']) ? (int) $adjustment['workflow_instance_id'] : null,
                $now
            );
            if (($adjustment['status'] ?? null) === 'cancelled') {
                return $this->receipt($adjustment, true);
            }
            if (!in_array((string) ($adjustment['status'] ?? ''), ['draft', 'pending'], true)
                || (int) ($adjustment['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_STALE');
            }
            if (!$this->repository->cancelAdjustment($adjustmentId, $expectedLockVersion, $comment, $now)) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_STALE');
            }
            $adjustment['status'] = 'cancelled';
            $adjustment['submitted_at'] ??= $this->databaseInstant($now);
            $adjustment['resolution_comment'] = $comment;
            $adjustment['lock_version'] = $expectedLockVersion + 1;
            $this->audit->recordEvent(
                'staff_attendance_adjustment_cancelled',
                'staff_attendance_adjustments',
                $adjustmentId,
                null,
                [
                    'staff_user_id' => (int) $adjustment['staff_user_id'],
                    'before_version_id' => (int) $adjustment['before_version_id'],
                    'comment_hash' => hash('sha256', $comment),
                ],
                ['user_id' => $actorId]
            );
            return $this->receipt($adjustment, false);
        });
    }

    /** @return array<string,mixed> */
    public function decide(
        int $actorId,
        int $adjustmentId,
        int $expectedLockVersion,
        string $decision,
        ?string $resolutionComment = null
    ): array {
        $this->assertPositiveId($actorId, 'ATTENDANCE_ADJUSTMENT_ACTOR_INVALID');
        $this->assertPositiveId($adjustmentId, 'ATTENDANCE_ADJUSTMENT_ID_INVALID');
        $this->assertPositiveId($expectedLockVersion, 'ATTENDANCE_ADJUSTMENT_LOCK_INVALID');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_DECISION_INVALID');
        }
        $resolutionComment = $this->nullableText(
            $resolutionComment,
            2000,
            'ATTENDANCE_ADJUSTMENT_RESOLUTION_COMMENT_INVALID'
        );
        if ($decision === 'rejected' && $resolutionComment === null) {
            throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_REJECTION_COMMENT_REQUIRED');
        }
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $adjustmentId,
            $expectedLockVersion,
            $decision,
            $resolutionComment,
            $now
        ): array {
            $adjustment = $this->requiredAdjustment($adjustmentId);
            if ((int) ($adjustment['requester_id'] ?? 0) === $actorId) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_SELF_DECISION_FORBIDDEN');
            }
            $workflowInstanceId = isset($adjustment['workflow_instance_id'])
                && $adjustment['workflow_instance_id'] !== null
                ? (int) $adjustment['workflow_instance_id']
                : null;
            $this->authorization->assertCanAct(
                $actorId,
                (int) $adjustment['staff_user_id'],
                (string) $adjustment['requester_kind'],
                'decide',
                $workflowInstanceId,
                $now
            );
            $status = (string) ($adjustment['status'] ?? '');
            if (in_array($status, ['approved', 'rejected'], true)) {
                if ($status === $decision) {
                    return $this->receipt($adjustment, true);
                }
                throw new DomainException('ATTENDANCE_ADJUSTMENT_FINAL');
            }
            if ($status !== 'pending' || (int) ($adjustment['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_STALE');
            }

            if ($decision === 'rejected') {
                if (!$this->repository->finalizeAdjustment(
                    $adjustmentId,
                    $expectedLockVersion,
                    'rejected',
                    null,
                    $resolutionComment
                )) {
                    throw new DomainException('ATTENDANCE_ADJUSTMENT_STALE');
                }
                $adjustment['status'] = 'rejected';
                $adjustment['resolution_comment'] = $resolutionComment;
                $adjustment['lock_version'] = $expectedLockVersion + 1;
                $this->audit->recordEvent(
                    'staff_attendance_adjustment_rejected',
                    'staff_attendance_adjustments',
                    $adjustmentId,
                    null,
                    [
                        'staff_user_id' => (int) $adjustment['staff_user_id'],
                        'before_version_id' => (int) $adjustment['before_version_id'],
                        'comment_hash' => hash('sha256', (string) $resolutionComment),
                    ],
                    ['user_id' => $actorId]
                );
                return $this->receipt($adjustment, false);
            }

            $before = $this->repository->currentOfficialDayForUpdate(
                (int) $adjustment['staff_user_id'],
                (string) $adjustment['work_date']
            );
            if ($before === null || (int) ($before['id'] ?? 0) !== (int) $adjustment['before_version_id']) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_SOURCE_STALE');
            }
            $proposedValues = $this->decodeProposedValues($adjustment['proposed_values'] ?? null);
            $candidate = $this->validateProposedValuesForDay($before, $proposedValues);
            $sourceFingerprint = $this->sourceFingerprint($before, $adjustmentId, $proposedValues);
            $runId = $this->repository->insertRecalculationRun([
                'engine_version' => self::ENGINE_VERSION,
                'mode' => 'recalculation',
                'range_from' => (string) $adjustment['work_date'],
                'range_to' => (string) $adjustment['work_date'],
                'cutoff_at' => $this->databaseInstant($now),
                'initiated_by' => $actorId,
                'status' => 'queued',
                'source_fingerprint' => $sourceFingerprint,
                'idempotency_key' => 'attendance-adjustment:' . $adjustmentId . ':' . (int) $before['id'],
                'supersedes_run_id' => (int) $before['run_id'],
            ]);
            if (!$this->repository->startRun($runId, $now)) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_RUN_STALE');
            }
            $versionNo = $this->repository->nextDayVersionNoForUpdate(
                (int) $adjustment['staff_user_id'],
                (string) $adjustment['work_date']
            );
            $newDayId = $this->repository->insertDayVersion($this->dayPayload(
                $before,
                $candidate,
                $versionNo,
                $runId,
                $sourceFingerprint,
                $now
            ));
            $this->repository->copySegments((int) $before['id'], $newDayId);
            $lastReasonLine = $this->repository->copyReasonLines((int) $before['id'], $newDayId);
            $this->repository->appendReasonLine([
                'day_version_id' => $newDayId,
                'line_no' => $lastReasonLine + 1,
                'reason_code' => 'ATTENDANCE_ADJUSTMENT_APPROVED',
                'from_at' => null,
                'to_at' => null,
                'minutes' => 0,
                'source_type' => 'attendance_adjustment',
                'source_id' => $adjustmentId,
                'explanation' => 'تم إنشاء نسخة حضور بديلة بعد اعتماد طلب تصحيح موثق.',
                'metadata' => [
                    'before_version_id' => (int) $before['id'],
                    'proposed_values' => $proposedValues,
                    'proposed_fields' => array_keys($proposedValues),
                    'proposed_values_hash' => hash('sha256', $this->encodeJson($proposedValues, 'ATTENDANCE_ADJUSTMENT_PROPOSED_VALUES_INVALID')),
                ],
            ]);
            if (!$this->repository->completeRun($runId, $now, [
                'adjustment_id' => $adjustmentId,
                'before_version_id' => (int) $before['id'],
                'approved_version_id' => $newDayId,
            ])) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_RUN_STALE');
            }
            if (!$this->repository->demoteOfficialDay((int) $before['id'])
                || !$this->repository->publishDayVersion($newDayId, $actorId, $now)) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_PUBLICATION_STALE');
            }
            if (!$this->repository->finalizeAdjustment(
                $adjustmentId,
                $expectedLockVersion,
                'approved',
                $newDayId,
                $resolutionComment
            )) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_STALE');
            }
            $adjustment['status'] = 'approved';
            $adjustment['approved_version_id'] = $newDayId;
            $adjustment['resolution_comment'] = $resolutionComment;
            $adjustment['lock_version'] = $expectedLockVersion + 1;
            $this->audit->recordEvent(
                'staff_attendance_adjustment_approved',
                'staff_attendance_adjustments',
                $adjustmentId,
                null,
                [
                    'staff_user_id' => (int) $adjustment['staff_user_id'],
                    'before_version_id' => (int) $before['id'],
                    'approved_version_id' => $newDayId,
                    'recalculation_run_id' => $runId,
                    'proposed_fields' => array_keys($proposedValues),
                    'source_fingerprint' => $sourceFingerprint,
                ],
                ['user_id' => $actorId]
            );
            return $this->receipt($adjustment, false);
        });
    }

    /** @return array<string,mixed> */
    private function requiredAdjustment(int $adjustmentId): array
    {
        $adjustment = $this->repository->adjustmentForUpdate($adjustmentId);
        if ($adjustment === null) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_NOT_FOUND');
        }
        return $adjustment;
    }

    /** @param array<string,mixed> $adjustment */
    private function assertRequesterActor(array $adjustment, int $actorId): void
    {
        if ((int) ($adjustment['requester_id'] ?? 0) !== $actorId) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_REQUESTER_ONLY');
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $candidate @return array<string,mixed> */
    private function dayPayload(
        array $before,
        array $candidate,
        int $versionNo,
        int $runId,
        string $sourceFingerprint,
        DateTimeImmutable $calculatedAt
    ): array {
        return [
            'staff_user_id' => (int) $before['staff_user_id'],
            'work_date' => (string) $before['work_date'],
            'version_no' => $versionNo,
            'run_id' => $runId,
            'assignment_id' => $before['assignment_id'] ?? null,
            'schedule_policy_version_id' => $before['schedule_policy_version_id'] ?? null,
            'calendar_exception_id' => $before['calendar_exception_id'] ?? null,
            'expected_start' => $before['expected_start'] ?? null,
            'expected_end' => $before['expected_end'] ?? null,
            'required_minutes' => (int) $before['required_minutes'],
            'first_in' => $candidate['first_in'],
            'last_out' => $candidate['last_out'],
            'worked_minutes' => $candidate['worked_minutes'],
            'covered_late_minutes' => $candidate['covered_late_minutes'],
            'covered_early_minutes' => $candidate['covered_early_minutes'],
            'mission_minutes' => $candidate['mission_minutes'],
            'leave_minutes' => $candidate['leave_minutes'],
            'late_minutes' => $candidate['late_minutes'],
            'early_leave_minutes' => $candidate['early_leave_minutes'],
            'missing_minutes' => $candidate['missing_minutes'],
            'status' => $candidate['status'],
            'calculation_mode' => 'recalculation',
            'engine_version' => self::ENGINE_VERSION,
            'source_fingerprint' => $sourceFingerprint,
            'is_official' => 0,
            'officialized_by' => null,
            'officialized_at' => null,
            'supersedes_id' => (int) $before['id'],
            'calculated_at' => $this->databaseInstant($calculatedAt),
        ];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $proposed @return array<string,mixed> */
    private function validateProposedValuesForDay(array $before, array $proposed): array
    {
        $candidate = [];
        foreach (self::PROPOSABLE_DATETIME_FIELDS as $field) {
            $candidate[$field] = $before[$field] ?? null;
        }
        foreach (self::PROPOSABLE_INTEGER_FIELDS as $field) {
            $candidate[$field] = (int) ($before[$field] ?? 0);
        }
        $candidate['status'] = (string) ($before['status'] ?? 'partial');
        foreach ($proposed as $field => $value) {
            $candidate[$field] = $value;
        }
        $requiredMinutes = (int) ($before['required_minutes'] ?? 0);
        if ($requiredMinutes < 0) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_SOURCE_CORRUPT');
        }
        foreach (self::PROPOSABLE_INTEGER_FIELDS as $field) {
            $value = (int) $candidate[$field];
            if ($value < 0 || $value > $requiredMinutes) {
                throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_MINUTES_INVALID');
            }
            $candidate[$field] = $value;
        }
        if (!in_array($candidate['status'], self::PROPOSABLE_STATUSES, true)) {
            throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_STATUS_INVALID');
        }
        if ($candidate['first_in'] !== null && $candidate['last_out'] !== null
            && strcmp((string) $candidate['last_out'], (string) $candidate['first_in']) < 0) {
            throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_ACTUAL_WINDOW_INVALID');
        }
        return $candidate;
    }

    /** @param array<string,mixed> $proposedValues @return array<string,mixed> */
    private function normalizeProposedValues(array $proposedValues): array
    {
        if ($proposedValues === []) {
            throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_PROPOSED_VALUES_REQUIRED');
        }
        $allowed = array_merge(
            self::PROPOSABLE_INTEGER_FIELDS,
            self::PROPOSABLE_DATETIME_FIELDS,
            ['status']
        );
        $normalized = [];
        foreach ($proposedValues as $field => $value) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_PROPOSED_FIELD_INVALID');
            }
            if (in_array($field, self::PROPOSABLE_INTEGER_FIELDS, true)) {
                $normalized[$field] = $this->unsignedMinutes($value);
                continue;
            }
            if (in_array($field, self::PROPOSABLE_DATETIME_FIELDS, true)) {
                $normalized[$field] = $this->nullableDatabaseDateTime($value);
                continue;
            }
            $status = trim((string) $value);
            if (!in_array($status, self::PROPOSABLE_STATUSES, true)) {
                throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_STATUS_INVALID');
            }
            $normalized[$field] = $status;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private function unsignedMinutes(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value) === 1)) {
            throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_MINUTES_INVALID');
        }
        $minutes = (int) $value;
        if ($minutes < 0 || $minutes > 2880) {
            throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_MINUTES_INVALID');
        }
        return $minutes;
    }

    private function nullableDatabaseDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $text, $this->utc);
            $errors = DateTimeImmutable::getLastErrors();
            $outputFormat = $format === '!Y-m-d H:i:s.u' ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s';
            if ($parsed !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format($outputFormat) === $text) {
                return $parsed->format('Y-m-d H:i:s.u');
            }
        }
        throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_DATETIME_INVALID');
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $proposed */
    private function sameDraftRequest(
        array $existing,
        int $actorId,
        int $staffUserId,
        string $requesterKind,
        string $workDate,
        string $reason,
        array $proposed
    ): bool {
        return (int) ($existing['requester_id'] ?? 0) === $actorId
            && (int) ($existing['staff_user_id'] ?? 0) === $staffUserId
            && (string) ($existing['requester_kind'] ?? '') === $requesterKind
            && (string) ($existing['work_date'] ?? '') === $workDate
            && hash_equals((string) ($existing['reason'] ?? ''), $reason)
            && $this->decodeProposedValues($existing['proposed_values'] ?? null) === $proposed;
    }

    /** @return array<string,mixed> */
    private function decodeProposedValues(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new DomainException('ATTENDANCE_ADJUSTMENT_PROPOSED_VALUES_CORRUPT', 0, $exception);
            }
        }
        if (!is_array($value)) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_PROPOSED_VALUES_CORRUPT');
        }
        try {
            return $this->normalizeProposedValues($value);
        } catch (InvalidArgumentException $exception) {
            throw new DomainException('ATTENDANCE_ADJUSTMENT_PROPOSED_VALUES_CORRUPT', 0, $exception);
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $proposed */
    private function sourceFingerprint(array $before, int $adjustmentId, array $proposed): string
    {
        return hash('sha256', $this->encodeJson([
            'before_version_id' => (int) $before['id'],
            'before_source_fingerprint' => (string) ($before['source_fingerprint'] ?? ''),
            'adjustment_id' => $adjustmentId,
            'proposed_values' => $proposed,
            'engine_version' => self::ENGINE_VERSION,
        ], 'ATTENDANCE_ADJUSTMENT_FINGERPRINT_INVALID'));
    }

    private function requesterKind(string $requesterKind): string
    {
        $requesterKind = trim($requesterKind);
        if (!in_array($requesterKind, self::REQUESTER_KINDS, true)) {
            throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_REQUESTER_KIND_INVALID');
        }
        return $requesterKind;
    }

    private function workDate(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('ATTENDANCE_ADJUSTMENT_WORK_DATE_INVALID');
        }
        return $value;
    }

    /** @param array<string,mixed> $adjustment @return array<string,mixed> */
    private function receipt(array $adjustment, bool $replayed): array
    {
        return [
            'adjustment_id' => (int) ($adjustment['id'] ?? 0),
            'staff_user_id' => (int) ($adjustment['staff_user_id'] ?? 0),
            'work_date' => (string) ($adjustment['work_date'] ?? ''),
            'requester_kind' => (string) ($adjustment['requester_kind'] ?? ''),
            'before_version_id' => (int) ($adjustment['before_version_id'] ?? 0),
            'workflow_instance_id' => isset($adjustment['workflow_instance_id'])
                && $adjustment['workflow_instance_id'] !== null
                ? (int) $adjustment['workflow_instance_id']
                : null,
            'status' => (string) ($adjustment['status'] ?? ''),
            'approved_version_id' => isset($adjustment['approved_version_id'])
                && $adjustment['approved_version_id'] !== null
                ? (int) $adjustment['approved_version_id']
                : null,
            'lock_version' => (int) ($adjustment['lock_version'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    private function assertPositiveId(int $value, string $error): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException($error);
        }
    }

    private function requiredText(string $value, int $maxLength, string $error): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private function nullableText(mixed $value, int $maxLength, string $error): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return $this->requiredText((string) $value, $maxLength, $error);
    }

    /** @param array<string,mixed> $value */
    private function encodeJson(array $value, string $error): string
    {
        try {
            return json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException($error, 0, $exception);
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

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }
}
