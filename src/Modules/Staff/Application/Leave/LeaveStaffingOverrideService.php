<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\LeaveRequestClock;
use EduCore\Modules\Staff\Contracts\LeaveRequestRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideRequestGateway;
use InvalidArgumentException;
use JsonException;

/**
 * Owns the manager-only staffing-exception decision before ordinary leave
 * submission. It cannot reserve balance, create an approval workflow, or
 * overwrite prior exception evidence.
 */
final class LeaveStaffingOverrideService
{
    private LeaveRequestClock $clock;

    public function __construct(
        private LeaveRequestRepository $requests,
        private LeaveStaffingOverrideRequestGateway $requestProjection,
        private LeaveStaffingOverrideRepository $overrides,
        private LeavePolicyService $policies,
        private LeaveStaffingPolicy $staffing,
        private LeaveStaffingOverrideAuthorization $authorization,
        private AuditEventWriter $audit,
        ?LeaveRequestClock $clock = null
    ) {
        $this->clock = $clock ?? new SystemLeaveRequestClock();
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function decide(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'LEAVE_STAFFING_OVERRIDE_ACTOR_INVALID');
        $requestId = $this->positiveId($command['request_id'] ?? null, 'LEAVE_REQUEST_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'LEAVE_REQUEST_LOCK_INVALID'
        );
        $outcome = $this->outcome($command['decision_outcome'] ?? null);
        $idempotencyKey = $this->requiredText(
            $command['decision_idempotency_key'] ?? null,
            190,
            'LEAVE_STAFFING_OVERRIDE_IDEMPOTENCY_INVALID'
        );
        $reason = $this->requiredText(
            $command['reason'] ?? null,
            1000,
            'LEAVE_STAFFING_OVERRIDE_REASON_REQUIRED'
        );
        $now = $this->clock->now();

        return $this->requests->transactional(function () use (
            $actorId,
            $requestId,
            $expectedLockVersion,
            $outcome,
            $idempotencyKey,
            $reason,
            $now
        ): array {
            $request = $this->requiredRequest($requestId);
            $existing = $this->overrides->decisionByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                return $this->replayedReceipt($existing, $request, $actorId, $outcome, $reason);
            }

            if ((string) ($request['status'] ?? '') !== 'draft'
                || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('LEAVE_REQUEST_STALE');
            }
            $staffUserId = $this->positiveId(
                $request['staff_user_id'] ?? null,
                'LEAVE_REQUEST_STAFF_INVALID'
            );
            if (!$this->requests->lockStaffForRequest($staffUserId)) {
                throw new DomainException('LEAVE_REQUEST_STAFF_NOT_FOUND');
            }

            $quote = $this->quoteForRequest($request, $now);
            $draft = $this->draftForAssessment($request, $quote);
            $requirement = $this->staffing->requiredOverride($requestId, $draft, $quote, $now);
            $requiredRoles = $this->roleKeys(
                (array) ($requirement['required_role_keys'] ?? []),
                'LEAVE_STAFFING_OVERRIDE_REQUIREMENT_INVALID'
            );
            $matchedRoles = $this->authorization->assertCanDecide($actorId, $requiredRoles, $now);
            $requestHash = $this->hash(
                $request['request_hash'] ?? null,
                'LEAVE_STAFFING_OVERRIDE_REQUEST_HASH_INVALID'
            );
            $requirementHash = $this->hash(
                $requirement['fingerprint'] ?? null,
                'LEAVE_STAFFING_OVERRIDE_REQUIREMENT_INVALID'
            );
            if (!hash_equals($requestHash, $this->hash(
                $requirement['request_hash'] ?? null,
                'LEAVE_STAFFING_OVERRIDE_REQUIREMENT_INVALID'
            ))) {
                throw new DomainException('LEAVE_STAFFING_OVERRIDE_REQUIREMENT_STALE');
            }

            $reasonHash = hash('sha256', $reason);
            $decisionHash = $this->decisionHash(
                $requestId,
                $requestHash,
                $outcome,
                $requirementHash,
                $requiredRoles,
                $reasonHash,
                $actorId
            );
            $decisionId = $this->overrides->insertDecision([
                'leave_request_id' => $requestId,
                'request_hash' => $requestHash,
                'decision_outcome' => $outcome,
                'required_role_keys' => $requiredRoles,
                'requirement_fingerprint' => $requirementHash,
                'assessment_snapshot' => [
                    'requirement' => $requirement,
                    'matched_role_keys' => $matchedRoles,
                    'evaluated_at' => $this->instant($now),
                ],
                'decision_reason' => $reason,
                'reason_hash' => $reasonHash,
                'decision_idempotency_key' => $idempotencyKey,
                'decision_hash' => $decisionHash,
                'decided_by' => $actorId,
                'decided_at' => $this->instant($now),
            ]);
            if ($decisionId <= 0) {
                throw new DomainException('LEAVE_STAFFING_OVERRIDE_PERSIST_FAILED');
            }

            $granted = $outcome === 'approved';
            if (!$this->requestProjection->applyStaffingOverrideDecision(
                $requestId,
                $expectedLockVersion,
                $granted,
                $granted ? $reason : null
            )) {
                throw new DomainException('LEAVE_REQUEST_STALE');
            }

            $lockVersion = $expectedLockVersion + 1;
            $details = [
                'leave_request_id' => $requestId,
                'staff_user_id' => $staffUserId,
                'decision_outcome' => $outcome,
                'granted' => $granted,
                'requirement_fingerprint' => $requirementHash,
                'required_role_keys' => $requiredRoles,
                'matched_role_keys' => $matchedRoles,
                'reason_provided' => true,
                'request_hash' => $requestHash,
            ];
            $this->audit->recordEvent(
                'staff_leave_staffing_override_decided',
                'staff_leave_staffing_overrides',
                $decisionId,
                null,
                $details,
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_leave_request_staffing_override_projected',
                'staff_leave_requests',
                $requestId,
                null,
                [
                    'decision_id' => $decisionId,
                    'decision_outcome' => $outcome,
                    'granted' => $granted,
                    'request_hash' => $requestHash,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return [
                'decision_id' => $decisionId,
                'request_id' => $requestId,
                'request_hash' => $requestHash,
                'decision_outcome' => $outcome,
                'granted' => $granted,
                'lock_version' => $lockVersion,
                'requirement_fingerprint' => $requirementHash,
                'required_role_keys' => $requiredRoles,
                'replayed' => false,
            ];
        });
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function quoteForRequest(array $request, DateTimeImmutable $at): array
    {
        $staffUserId = $this->positiveId($request['staff_user_id'] ?? null, 'LEAVE_REQUEST_STAFF_INVALID');
        $leaveTypeId = $this->positiveId($request['leave_type_id'] ?? null, 'LEAVE_REQUEST_TYPE_INVALID');
        $timezone = $this->timezone($request['timezone'] ?? null);
        $from = $this->dateTime($request['from_at'] ?? null, $timezone);
        $to = $this->dateTime($request['to_at'] ?? null, $timezone);
        if ($to <= $from) {
            throw new DomainException('LEAVE_REQUEST_WINDOW_INVALID');
        }

        return $this->policies->quote($staffUserId, $leaveTypeId, $from, $to, $at);
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $quote
     * @return array<string,mixed>
     */
    private function draftForAssessment(array $request, array $quote): array
    {
        if (!is_array($quote['request_days'] ?? null)) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_QUOTE_INVALID');
        }

        return [
            'staff_user_id' => $this->positiveId(
                $request['staff_user_id'] ?? null,
                'LEAVE_REQUEST_STAFF_INVALID'
            ),
            'request_kind' => strtolower(trim((string) ($request['request_kind'] ?? ''))),
            'from_at' => (string) ($quote['from_at'] ?? ''),
            'to_at' => (string) ($quote['to_at'] ?? ''),
            'request_hash' => $this->hash(
                $request['request_hash'] ?? null,
                'LEAVE_STAFFING_OVERRIDE_REQUEST_HASH_INVALID'
            ),
            'request_days' => $quote['request_days'],
        ];
    }

    /**
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function replayedReceipt(
        array $decision,
        array $request,
        int $actorId,
        string $outcome,
        string $reason
    ): array {
        $requestId = $this->positiveId($request['id'] ?? null, 'LEAVE_REQUEST_ID_INVALID');
        $decisionRequestId = $this->positiveId(
            $decision['leave_request_id'] ?? null,
            'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
        );
        if ($decisionRequestId !== $requestId
            || (int) ($decision['decided_by'] ?? 0) !== $actorId
            || (string) ($decision['decision_outcome'] ?? '') !== $outcome
            || !hash_equals(
                hash('sha256', $reason),
                $this->hash($decision['reason_hash'] ?? null, 'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID')
            )) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_IDEMPOTENCY_CONFLICT');
        }

        return [
            'decision_id' => $this->positiveId(
                $decision['id'] ?? null,
                'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
            ),
            'request_id' => $requestId,
            'request_hash' => $this->hash(
                $decision['request_hash'] ?? null,
                'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
            ),
            'decision_outcome' => $outcome,
            'granted' => $outcome === 'approved',
            'lock_version' => $this->positiveId(
                $request['lock_version'] ?? null,
                'LEAVE_REQUEST_LOCK_INVALID'
            ),
            'requirement_fingerprint' => $this->hash(
                $decision['requirement_fingerprint'] ?? null,
                'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
            ),
            'required_role_keys' => $this->roleKeys(
                (array) ($decision['required_role_keys'] ?? []),
                'LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID'
            ),
            'replayed' => true,
        ];
    }

    /** @param list<string> $roles */
    private function decisionHash(
        int $requestId,
        string $requestHash,
        string $outcome,
        string $requirementHash,
        array $roles,
        string $reasonHash,
        int $actorId
    ): string {
        try {
            return hash('sha256', json_encode([
                'leave_request_id' => $requestId,
                'request_hash' => $requestHash,
                'decision_outcome' => $outcome,
                'requirement_fingerprint' => $requirementHash,
                'required_role_keys' => $roles,
                'reason_hash' => $reasonHash,
                'decided_by' => $actorId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new DomainException('LEAVE_STAFFING_OVERRIDE_SERIALIZATION_INVALID', 0, $exception);
        }
    }

    /** @param array<int,mixed> $values @return list<string> */
    private function roleKeys(array $values, string $error): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $role = trim((string) $value);
            if ($role === '' || mb_strlen($role, 'UTF-8') > 80) {
                throw new DomainException($error);
            }
            $normalized[$role] = true;
        }
        $keys = array_keys($normalized);
        sort($keys, SORT_STRING);
        if ($keys === []) {
            throw new DomainException($error);
        }

        return $keys;
    }

    private function outcome(mixed $value): string
    {
        $outcome = strtolower(trim((string) $value));
        if (!in_array($outcome, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('LEAVE_STAFFING_OVERRIDE_OUTCOME_INVALID');
        }

        return $outcome;
    }

    private function requiredText(mixed $value, int $maximumLength, string $error): string
    {
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text, 'UTF-8') > $maximumLength) {
            throw new DomainException($error);
        }

        return $text;
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new DomainException($error);
        }

        return (int) $value;
    }

    private function hash(mixed $value, string $error): string
    {
        $hash = strtolower(trim((string) $value));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new DomainException($error);
        }

        return $hash;
    }

    private function timezone(mixed $value): DateTimeZone
    {
        $name = trim((string) $value);
        if ($name === '') {
            throw new DomainException('LEAVE_REQUEST_TIMEZONE_INVALID');
        }
        try {
            return new DateTimeZone($name);
        } catch (\Throwable $exception) {
            throw new DomainException('LEAVE_REQUEST_TIMEZONE_INVALID', 0, $exception);
        }
    }

    private function dateTime(mixed $value, DateTimeZone $timezone): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable((string) $value, $timezone);
        } catch (\Throwable $exception) {
            throw new DomainException('LEAVE_REQUEST_WINDOW_INVALID', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function requiredRequest(int $requestId): array
    {
        $request = $this->requests->requestForUpdate($requestId);
        if ($request === null) {
            throw new DomainException('LEAVE_REQUEST_NOT_FOUND');
        }

        return $request;
    }

    private function instant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
