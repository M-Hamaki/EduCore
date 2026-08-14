<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceAuthorization;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceEventRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use InvalidArgumentException;
use JsonException;

/**
 * Records non-biometric attendance as append-only evidence and keeps its
 * independent review separate from the raw event fields.
 */
final class AlternativeAttendanceRecorder
{
    /** @var list<string> */
    private const EVENT_TYPES = ['in', 'out', 'break_start', 'break_end'];

    /** @var list<string> */
    private const ALTERNATIVE_METHOD_TYPES = ['manual_verified', 'device_fallback', 'access_log'];

    private DateTimeZone $utc;

    public function __construct(
        private AttendanceTransactionManager $transactions,
        private AlternativeAttendanceEventRepository $events,
        private StaffAssignmentAtDateQuery $staffAssignments,
        private AlternativeAttendanceAuthorization $authorization,
        private AuditEventWriter $audit
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    /** @return array<string,mixed> */
    public function createMethod(int $actorId, string $code, string $name, string $methodType, string $allowedScope): array
    {
        $this->assertPositiveId($actorId, 'ALTERNATIVE_ATTENDANCE_ACTOR_INVALID');
        $code = strtoupper($this->requiredText($code, 80, 'ALTERNATIVE_ATTENDANCE_METHOD_CODE_INVALID'));
        if (preg_match('/^[A-Z0-9_-]+$/D', $code) !== 1) {
            throw new InvalidArgumentException('ALTERNATIVE_ATTENDANCE_METHOD_CODE_INVALID');
        }
        $name = $this->requiredText($name, 200, 'ALTERNATIVE_ATTENDANCE_METHOD_NAME_INVALID');
        if (!in_array($methodType, self::ALTERNATIVE_METHOD_TYPES, true)) {
            throw new InvalidArgumentException('ALTERNATIVE_ATTENDANCE_METHOD_TYPE_INVALID');
        }
        $allowedScope = $this->requiredText($allowedScope, 50, 'ALTERNATIVE_ATTENDANCE_METHOD_SCOPE_INVALID');
        return $this->transactions->transactional(function () use ($actorId, $code, $name, $methodType, $allowedScope): array {
            $methodId = $this->events->insertAlternativeEntryMethod([
                'code' => $code, 'name' => $name, 'method_type' => $methodType,
                'requires_attachment' => 0, 'allowed_scope' => $allowedScope, 'created_by' => $actorId,
            ]);
            $this->audit->recordEvent('staff_alternative_attendance_method_created', 'staff_attendance_entry_methods', $methodId, null, [
                'code' => $code, 'method_type' => $methodType, 'allowed_scope' => $allowedScope,
            ], ['user_id' => $actorId]);
            return ['method_id' => $methodId, 'code' => $code, 'status' => 'active'];
        });
    }

    /** @return list<array<string,mixed>> */
    public function methods(): array { return $this->events->activeAlternativeEntryMethods(); }

    public function retireMethod(int $actorId, int $methodId): array
    {
        $this->assertPositiveId($actorId, 'ALTERNATIVE_ATTENDANCE_ACTOR_INVALID');
        $this->assertPositiveId($methodId, 'ALTERNATIVE_ATTENDANCE_METHOD_INVALID');
        return $this->transactions->transactional(function () use ($actorId, $methodId): array {
            if (!$this->events->retireAlternativeEntryMethod($methodId)) {
                throw new DomainException('ALTERNATIVE_ATTENDANCE_METHOD_NOT_ACTIVE');
            }
            $this->audit->recordEvent('staff_alternative_attendance_method_retired', 'staff_attendance_entry_methods', $methodId, null, ['status' => 'retired'], ['user_id' => $actorId]);
            return ['method_id' => $methodId, 'status' => 'retired'];
        });
    }

    /** @return list<array<string,mixed>> */
    public function pendingEvents(int $actorId, int $limit = 100): array
    {
        $visible = [];
        $now = new DateTimeImmutable('now', $this->utc);
        foreach ($this->events->pendingAlternativeEvents($limit) as $event) {
            try {
                $this->authorization->assertCanReview(
                    $actorId,
                    (int) ($event['staff_user_id'] ?? 0),
                    (string) ($event['allowed_scope'] ?? ''),
                    $now
                );
                $visible[] = $event;
            } catch (DomainException) {
                // Fail closed per row; never expose another manager's evidence.
            }
        }
        return $visible;
    }

    /**
     * @param array<string,mixed> $evidence Supported keys: event_type,
     *   attachment_ref, evidence_ref. References are opaque private-storage IDs,
     *   not file-system paths or URLs.
     * @return array<string,mixed>
     */
    public function record(
        int $actorId,
        int $staffUserId,
        int $entryMethodId,
        DateTimeImmutable $occurredAt,
        string $reason,
        array $evidence,
        string $idempotencyKey
    ): array {
        $this->assertPositiveId($actorId, 'ALTERNATIVE_ATTENDANCE_ACTOR_INVALID');
        $this->assertPositiveId($staffUserId, 'ALTERNATIVE_ATTENDANCE_STAFF_INVALID');
        $this->assertPositiveId($entryMethodId, 'ALTERNATIVE_ATTENDANCE_METHOD_INVALID');
        $reason = $this->requiredText($reason, 1000, 'ALTERNATIVE_ATTENDANCE_REASON_REQUIRED');
        $idempotencyKey = $this->requiredText(
            $idempotencyKey,
            190,
            'ALTERNATIVE_ATTENDANCE_IDEMPOTENCY_KEY_INVALID'
        );
        $normalizedEvidence = $this->normalizeEvidence($evidence);
        $recordedAt = new DateTimeImmutable('now', $this->utc);
        $occurredAtUtc = $occurredAt->setTimezone($this->utc);
        $requestHash = $this->requestHash(
            $actorId,
            $staffUserId,
            $entryMethodId,
            $occurredAt,
            $reason,
            $normalizedEvidence
        );

        return $this->transactions->transactional(function () use (
            $actorId,
            $staffUserId,
            $entryMethodId,
            $occurredAt,
            $occurredAtUtc,
            $reason,
            $normalizedEvidence,
            $idempotencyKey,
            $recordedAt,
            $requestHash
        ): array {
            $existing = $this->events->alternativeEventByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (!in_array((string) ($existing['method_type'] ?? ''), self::ALTERNATIVE_METHOD_TYPES, true)) {
                    $requestedMethod = $this->events->activeAlternativeEntryMethodForUpdate($entryMethodId);
                    if ($requestedMethod === null
                        || !in_array(
                            (string) ($requestedMethod['method_type'] ?? ''),
                            self::ALTERNATIVE_METHOD_TYPES,
                            true
                        )) {
                        throw new DomainException('ALTERNATIVE_ATTENDANCE_METHOD_NOT_ACTIVE');
                    }
                    $requestedScope = $this->requiredText(
                        (string) ($requestedMethod['allowed_scope'] ?? ''),
                        50,
                        'ALTERNATIVE_ATTENDANCE_METHOD_SCOPE_INVALID'
                    );
                    $this->authorization->assertCanRecord(
                        $actorId,
                        $staffUserId,
                        $requestedScope,
                        $recordedAt
                    );
                    throw new DomainException('ALTERNATIVE_ATTENDANCE_IDEMPOTENCY_CONFLICT');
                }
                $scope = $this->requiredText(
                    (string) ($existing['allowed_scope'] ?? ''),
                    50,
                    'ALTERNATIVE_ATTENDANCE_METHOD_SCOPE_INVALID'
                );
                $this->authorization->assertCanRecord($actorId, $staffUserId, $scope, $recordedAt);
                if ((int) ($existing['staff_user_id'] ?? 0) === $staffUserId
                    && (int) ($existing['entry_method_id'] ?? 0) === $entryMethodId
                    && hash_equals((string) ($existing['raw_hash'] ?? ''), $requestHash)) {
                    return $this->receipt($existing, true);
                }
                throw new DomainException('ALTERNATIVE_ATTENDANCE_IDEMPOTENCY_CONFLICT');
            }

            $method = $this->events->activeAlternativeEntryMethodForUpdate($entryMethodId);
            if ($method === null
                || !in_array((string) ($method['method_type'] ?? ''), self::ALTERNATIVE_METHOD_TYPES, true)) {
                throw new DomainException('ALTERNATIVE_ATTENDANCE_METHOD_NOT_ACTIVE');
            }
            $scope = $this->requiredText(
                (string) ($method['allowed_scope'] ?? ''),
                50,
                'ALTERNATIVE_ATTENDANCE_METHOD_SCOPE_INVALID'
            );
            $this->authorization->assertCanRecord($actorId, $staffUserId, $scope, $recordedAt);
            if ($this->staffAssignments->forStaff($staffUserId, $occurredAt) === null) {
                throw new DomainException('STAFF_NOT_ELIGIBLE_AT_ALTERNATIVE_ATTENDANCE_DATE');
            }
            if ((int) ($method['requires_attachment'] ?? 0) === 1
                && $normalizedEvidence['attachment_ref'] === null) {
                throw new InvalidArgumentException('ALTERNATIVE_ATTENDANCE_ATTACHMENT_REQUIRED');
            }

            $timezone = $occurredAt->getTimezone();
            $reviewStatus = (int) ($method['requires_review'] ?? 1) === 1
                ? 'pending'
                : 'not_required';
            $event = [
                'batch_id' => null,
                'entry_method_id' => $entryMethodId,
                'device_id' => null,
                'external_event_key' => null,
                'idempotency_key' => $idempotencyKey,
                'biometric_identity' => null,
                'identity_mapping_id' => null,
                'staff_user_id' => $staffUserId,
                'device_event_at' => $occurredAt->format('Y-m-d H:i:s.u'),
                'received_at' => $this->databaseInstant($recordedAt),
                'device_timezone' => $timezone->getName(),
                'normalized_event_at_utc' => $this->databaseInstant($occurredAtUtc),
                'event_at_local' => $occurredAt->format('Y-m-d H:i:s.u'),
                'clock_offset_seconds' => 0,
                'clock_status' => 'trusted',
                'event_type' => $normalizedEvidence['event_type'],
                'raw_hash' => $requestHash,
                'raw_payload_ref' => $normalizedEvidence['evidence_ref'],
                'link_status' => 'matched',
                'link_reason' => 'alternative_attendance',
                'processing_order' => 0,
                'recorded_by' => $actorId,
                'reason_text' => $reason,
                'attachment_ref' => $normalizedEvidence['attachment_ref'],
                'review_status' => $reviewStatus,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ];
            $eventId = $this->events->insertAlternativeEvent($event);
            $stored = ['id' => $eventId, 'allowed_scope' => $scope] + $event;

            $this->audit->recordEvent(
                'staff_alternative_attendance_recorded',
                'staff_biometric_events',
                $eventId,
                null,
                [
                    'staff_user_id' => $staffUserId,
                    'entry_method_id' => $entryMethodId,
                    'event_type' => $normalizedEvidence['event_type'],
                    'occurred_at' => $occurredAt->format('Y-m-d\\TH:i:s.uP'),
                    'review_status' => $reviewStatus,
                    'reason_hash' => hash('sha256', $reason),
                    'attachment_present' => $normalizedEvidence['attachment_ref'] !== null,
                    'evidence_reference_hash' => $normalizedEvidence['evidence_ref'] === null
                        ? null
                        : hash('sha256', $normalizedEvidence['evidence_ref']),
                ],
                ['user_id' => $actorId]
            );

            return $this->receipt($stored, false);
        });
    }

    /** @return array<string,mixed> */
    public function review(
        int $actorId,
        int $eventId,
        string $decision,
        ?string $comment = null
    ): array {
        $this->assertPositiveId($actorId, 'ALTERNATIVE_ATTENDANCE_REVIEWER_INVALID');
        $this->assertPositiveId($eventId, 'ALTERNATIVE_ATTENDANCE_EVENT_INVALID');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('ALTERNATIVE_ATTENDANCE_REVIEW_DECISION_INVALID');
        }
        $comment = $this->nullableText($comment, 1000, 'ALTERNATIVE_ATTENDANCE_REVIEW_COMMENT_INVALID');
        if ($decision === 'rejected' && $comment === null) {
            throw new InvalidArgumentException('ALTERNATIVE_ATTENDANCE_REJECTION_COMMENT_REQUIRED');
        }
        $reviewedAt = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $eventId,
            $decision,
            $comment,
            $reviewedAt
        ): array {
            $event = $this->events->pendingAlternativeEventForReview($eventId);
            if ($event === null) {
                throw new DomainException('ALTERNATIVE_ATTENDANCE_REVIEW_NOT_PENDING');
            }
            $staffUserId = (int) ($event['staff_user_id'] ?? 0);
            $recordedBy = (int) ($event['recorded_by'] ?? 0);
            if ($staffUserId <= 0) {
                throw new DomainException('ALTERNATIVE_ATTENDANCE_EVENT_CORRUPT');
            }
            if ($recordedBy === $actorId) {
                throw new DomainException('ALTERNATIVE_ATTENDANCE_SELF_REVIEW_FORBIDDEN');
            }
            $scope = $this->requiredText(
                (string) ($event['allowed_scope'] ?? ''),
                50,
                'ALTERNATIVE_ATTENDANCE_METHOD_SCOPE_INVALID'
            );
            $this->authorization->assertCanReview($actorId, $staffUserId, $scope, $reviewedAt);
            if (!$this->events->finalizeAlternativeReview($eventId, $decision, $actorId, $reviewedAt)) {
                throw new DomainException('ALTERNATIVE_ATTENDANCE_REVIEW_STALE');
            }

            $this->audit->recordEvent(
                'staff_alternative_attendance_reviewed',
                'staff_biometric_events',
                $eventId,
                null,
                [
                    'staff_user_id' => $staffUserId,
                    'before_status' => 'pending',
                    'after_status' => $decision,
                    'review_comment_hash' => $comment === null ? null : hash('sha256', $comment),
                ],
                ['user_id' => $actorId]
            );

            return [
                'event_id' => $eventId,
                'staff_user_id' => $staffUserId,
                'review_status' => $decision,
                'reviewed_by' => $actorId,
                'reviewed_at' => $reviewedAt->format(DateTimeImmutable::ATOM),
            ];
        });
    }

    /** @param array<string,mixed> $evidence @return array{event_type:string,attachment_ref:?string,evidence_ref:?string} */
    private function normalizeEvidence(array $evidence): array
    {
        $eventType = trim((string) ($evidence['event_type'] ?? ''));
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('ALTERNATIVE_ATTENDANCE_EVENT_TYPE_INVALID');
        }
        return [
            'event_type' => $eventType,
            'attachment_ref' => $this->opaqueReference(
                $evidence['attachment_ref'] ?? null,
                'ALTERNATIVE_ATTENDANCE_ATTACHMENT_REF_INVALID'
            ),
            'evidence_ref' => $this->opaqueReference(
                $evidence['evidence_ref'] ?? null,
                'ALTERNATIVE_ATTENDANCE_EVIDENCE_REF_INVALID'
            ),
        ];
    }

    private function opaqueReference(mixed $value, string $error): ?string
    {
        $reference = $this->nullableText($value, 500, $error);
        if ($reference === null) {
            return null;
        }
        if (str_contains($reference, '\\')
            || str_contains($reference, '://')
            || str_starts_with($reference, '/')
            || preg_match('/^[A-Za-z]:/', $reference) === 1
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $reference) === 1
            || preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $reference) !== 1) {
            throw new InvalidArgumentException($error);
        }
        return $reference;
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function receipt(array $event, bool $replayed): array
    {
        return [
            'event_id' => (int) ($event['id'] ?? 0),
            'staff_user_id' => (int) ($event['staff_user_id'] ?? 0),
            'entry_method_id' => (int) ($event['entry_method_id'] ?? 0),
            'event_type' => (string) ($event['event_type'] ?? ''),
            'occurred_at' => (string) ($event['event_at_local'] ?? $event['device_event_at'] ?? ''),
            'review_status' => (string) ($event['review_status'] ?? ''),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $evidence */
    private function requestHash(
        int $actorId,
        int $staffUserId,
        int $entryMethodId,
        DateTimeImmutable $occurredAt,
        string $reason,
        array $evidence
    ): string {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize([
                    'actor_id' => $actorId,
                    'staff_user_id' => $staffUserId,
                    'entry_method_id' => $entryMethodId,
                    'occurred_at' => $occurredAt->format(DateTimeImmutable::ATOM),
                    'event_type' => $evidence['event_type'],
                    'attachment_ref' => $evidence['attachment_ref'],
                    'evidence_ref' => $evidence['evidence_ref'],
                    'reason_hash' => hash('sha256', $reason),
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('ALTERNATIVE_ATTENDANCE_REQUEST_INVALID', 0, $exception);
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

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }
}
