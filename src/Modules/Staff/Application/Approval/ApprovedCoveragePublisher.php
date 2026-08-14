<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Approval;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovedCoveragePublicationGateway;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;
use InvalidArgumentException;
use JsonException;

/**
 * Translates a finalized permission snapshot into narrow, dated Attendance
 * coverage facts. It owns no Attendance SQL and deliberately does not try to
 * bypass a closed attendance period.
 */
final class ApprovedCoveragePublisher implements ApprovedCoveragePublicationGateway
{
    /** @var list<string> */
    private const COVERAGE_BEHAVIORS = ['late_arrival', 'early_leave', 'mission'];

    public function __construct(
        private AttendanceCoverageChangeGateway $attendance,
        private AuditEventWriter $audit
    ) {
    }

    public function publishApproved(
        array $request,
        array $snapshot,
        int $workflowInstanceId,
        int $actorId,
        DateTimeImmutable $occurredAt
    ): array {
        return $this->publish(
            'coverage_approved',
            $request,
            $snapshot,
            $workflowInstanceId,
            $actorId,
            $occurredAt
        );
    }

    public function publishReversed(
        array $request,
        array $snapshot,
        int $workflowInstanceId,
        int $actorId,
        DateTimeImmutable $occurredAt
    ): array {
        return $this->publish(
            'coverage_reversed',
            $request,
            $snapshot,
            $workflowInstanceId,
            $actorId,
            $occurredAt
        );
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function publish(
        string $eventType,
        array $request,
        array $snapshot,
        int $workflowInstanceId,
        int $actorId,
        DateTimeImmutable $occurredAt
    ): array {
        if (!in_array($eventType, ['coverage_approved', 'coverage_reversed'], true)) {
            throw new InvalidArgumentException('PERMISSION_COVERAGE_EVENT_TYPE_INVALID');
        }
        $requestId = $this->positiveId($request['id'] ?? null, 'PERMISSION_COVERAGE_REQUEST_ID_INVALID');
        $staffUserId = $this->positiveId($request['staff_user_id'] ?? null, 'PERMISSION_COVERAGE_STAFF_ID_INVALID');
        $workflowInstanceId = $this->positiveId($workflowInstanceId, 'PERMISSION_COVERAGE_WORKFLOW_ID_INVALID');
        $actorId = $this->positiveId($actorId, 'PERMISSION_COVERAGE_ACTOR_INVALID');
        $type = $snapshot['type'] ?? null;
        if (!is_array($type)) {
            throw new DomainException('PERMISSION_COVERAGE_SNAPSHOT_INVALID');
        }
        $behavior = strtolower(trim((string) ($type['coverage_behavior'] ?? '')));
        if ($behavior === 'none') {
            return [
                'event_type' => $eventType,
                'coverage_behavior' => 'none',
                'published_count' => 0,
                'receipts' => [],
            ];
        }
        if (!in_array($behavior, self::COVERAGE_BEHAVIORS, true)) {
            throw new DomainException('PERMISSION_COVERAGE_BEHAVIOR_INVALID');
        }
        $timezone = trim((string) ($request['timezone'] ?? ($snapshot['policy']['timezone'] ?? '')));
        $window = $this->coverageWindow(
            (string) ($request['from_at'] ?? ''),
            (string) ($request['to_at'] ?? ''),
            $timezone
        );
        $requestHash = $this->hashValue(
            (string) ($request['request_hash'] ?? ''),
            'PERMISSION_COVERAGE_REQUEST_HASH_INVALID'
        );
        $rootFingerprint = $this->hash([
            'permission_request_id' => $requestId,
            'request_hash' => $requestHash,
            'workflow_instance_id' => $workflowInstanceId,
            'event_type' => $eventType,
            'coverage_behavior' => $behavior,
            'from_at' => $window['from']->format('Y-m-d H:i:s.u P'),
            'to_at' => $window['to']->format('Y-m-d H:i:s.u P'),
            'timezone' => $timezone,
        ]);

        $receipts = [];
        foreach ($this->daysInWindow($window['from'], $window['to']) as $day) {
            $workDate = $day['work_date'];
            $sourceFingerprint = $this->hash([
                'root_fingerprint' => $rootFingerprint,
                'work_date' => $workDate,
                'window_from' => $day['from']->format('Y-m-d H:i:s.u P'),
                'window_to' => $day['to']->format('Y-m-d H:i:s.u P'),
            ]);
            $idempotencyKey = 'permission-coverage:' . $eventType . ':' . hash(
                'sha256',
                $requestId . ':' . $workflowInstanceId . ':' . $workDate . ':' . $rootFingerprint
            );
            $receipt = $this->attendance->publish([
                'actor_id' => $actorId,
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate,
                'event_type' => $eventType,
                'source_type' => 'permission_request',
                'source_id' => $requestId,
                'source_fingerprint' => $sourceFingerprint,
                'reason_code' => $eventType === 'coverage_approved'
                    ? 'permission_coverage_approved'
                    : 'permission_coverage_reversed',
                'idempotency_key' => $idempotencyKey,
            ]);
            $receipts[] = [
                'change_request_id' => (int) ($receipt['change_request_id'] ?? 0),
                'status' => (string) ($receipt['status'] ?? ''),
                'next_action' => (string) ($receipt['next_action'] ?? ''),
                'replayed' => (bool) ($receipt['replayed'] ?? false),
            ];
        }

        $this->audit->recordEvent(
            'staff_permission_coverage_published',
            'staff_permission_requests',
            $requestId,
            $eventType,
            [
                'approval_instance_id' => $workflowInstanceId,
                'staff_user_id' => $staffUserId,
                'coverage_behavior' => $behavior,
                'affected_day_count' => count($receipts),
                'period_change_ids' => array_values(array_filter(
                    array_map(static fn (array $receipt): int => (int) $receipt['change_request_id'], $receipts),
                    static fn (int $id): bool => $id > 0
                )),
            ],
            ['user_id' => $actorId, 'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u')]
        );

        return [
            'event_type' => $eventType,
            'coverage_behavior' => $behavior,
            'published_count' => count($receipts),
            'receipts' => $receipts,
        ];
    }

    /** @return array{from:DateTimeImmutable,to:DateTimeImmutable} */
    private function coverageWindow(string $from, string $to, string $timezone): array
    {
        if ($timezone === '') {
            throw new DomainException('PERMISSION_COVERAGE_TIMEZONE_INVALID');
        }
        try {
            $zone = new DateTimeZone($timezone);
            $fromAt = new DateTimeImmutable($from, $zone);
            $toAt = new DateTimeImmutable($to, $zone);
        } catch (\Throwable) {
            throw new DomainException('PERMISSION_COVERAGE_WINDOW_INVALID');
        }
        $fromAt = $fromAt->setTimezone($zone);
        $toAt = $toAt->setTimezone($zone);
        if ($fromAt >= $toAt) {
            throw new DomainException('PERMISSION_COVERAGE_WINDOW_INVALID');
        }

        return ['from' => $fromAt, 'to' => $toAt];
    }

    /**
     * @return list<array{work_date:string,from:DateTimeImmutable,to:DateTimeImmutable}>
     */
    private function daysInWindow(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = [];
        $cursor = $from->setTime(0, 0, 0, 0);
        while ($cursor < $to) {
            if (count($days) >= 31) {
                throw new DomainException('PERMISSION_COVERAGE_WINDOW_TOO_LONG');
            }
            $next = $cursor->modify('+1 day');
            $windowFrom = $from > $cursor ? $from : $cursor;
            $windowTo = $to < $next ? $to : $next;
            if ($windowFrom < $windowTo) {
                $days[] = [
                    'work_date' => $cursor->format('Y-m-d'),
                    'from' => $windowFrom,
                    'to' => $windowTo,
                ];
            }
            $cursor = $next;
        }

        return $days;
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function hashValue(string $value, string $error): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new DomainException($error);
        }

        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $exception) {
            throw new DomainException('PERMISSION_COVERAGE_FINGERPRINT_INVALID', 0, $exception);
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
}
