<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Staff\Contracts\StaffApprovedCoverageReadRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Read-only Staff projection for approved permission, mission, immutable
 * leave-ledger, and legacy leave coverage. It deliberately exposes no
 * request detail or workflow snapshot to Attendance.
 */
final class PdoStaffApprovedCoverageReadRepository implements StaffApprovedCoverageReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function approvedCoverageForStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        if ($staffUserId <= 0) {
            throw new InvalidArgumentException('APPROVED_COVERAGE_STAFF_ID_INVALID');
        }
        if ($windowEnd <= $windowStart) {
            throw new InvalidArgumentException('APPROVED_COVERAGE_WINDOW_INVALID');
        }

        $coverage = array_merge(
            $this->approvedPermissions($staffUserId, $windowStart, $windowEnd),
            $this->approvedLedgerLeaves($staffUserId, $windowStart, $windowEnd),
            $this->approvedLegacyLeaves($staffUserId, $windowStart, $windowEnd)
        );

        usort($coverage, static function (array $left, array $right): int {
            $byStart = $left['from_at'] <=> $right['from_at'];
            if ($byStart !== 0) {
                return $byStart;
            }
            $byEnd = $left['to_at'] <=> $right['to_at'];
            if ($byEnd !== 0) {
                return $byEnd;
            }
            return [$left['source_type'], $left['source_id']] <=> [$right['source_type'], $right['source_id']];
        });

        return $coverage;
    }

    /**
     * Immutable leave-ledger projection. A final early-return/cancellation is
     * an approved successor, so its own interval is subtracted from the
     * direct approved parent instead of being exposed as a second leave.
     *
     * @return list<array<string,mixed>>
     */
    private function approvedLedgerLeaves(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        $statement = $this->db->prepare(
            "SELECT id, parent_request_id, request_kind, from_at, to_at, timezone, policy_version_id
             FROM staff_leave_requests
             WHERE staff_user_id = :staff_user_id
               AND status = 'approved'
               AND to_at > :window_start
               AND from_at < :window_end
             ORDER BY from_at ASC, to_at ASC, id ASC"
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'window_start' => $this->databaseDateTime($windowStart),
            'window_end' => $this->databaseDateTime($windowEnd),
        ]);

        /** @var array<int,array<string,mixed>> $positive */
        $positive = [];
        /** @var list<array<string,mixed>> $reversals */
        $reversals = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $requestId = (int) ($row['id'] ?? 0);
            $kind = (string) ($row['request_kind'] ?? '');
            if ($requestId <= 0
                || !in_array($kind, ['leave', 'extension', 'early_return', 'cancellation'], true)) {
                throw new DomainException('APPROVED_LEAVE_LEDGER_EVIDENCE_CORRUPT');
            }
            $timezone = $this->timezone((string) ($row['timezone'] ?? ''));
            $from = $this->parseDatabaseDateTime((string) ($row['from_at'] ?? ''), $timezone);
            $to = $this->parseDatabaseDateTime((string) ($row['to_at'] ?? ''), $timezone);
            if ($to <= $from) {
                throw new DomainException('APPROVED_LEAVE_WINDOW_CORRUPT');
            }
            $normalized = [
                'id' => $requestId,
                'parent_request_id' => isset($row['parent_request_id']) && $row['parent_request_id'] !== null
                    ? (int) $row['parent_request_id']
                    : null,
                'from_at' => $from,
                'to_at' => $to,
                'policy_version_id' => isset($row['policy_version_id']) && $row['policy_version_id'] !== null
                    ? (int) $row['policy_version_id']
                    : null,
            ];
            if (in_array($kind, ['leave', 'extension'], true)) {
                $positive[$requestId] = $normalized;
            } else {
                if (($normalized['parent_request_id'] ?? null) === null
                    || (int) $normalized['parent_request_id'] <= 0) {
                    throw new DomainException('APPROVED_LEAVE_SUCCESSOR_PARENT_CORRUPT');
                }
                $reversals[] = $normalized;
            }
        }

        $coverage = [];
        foreach ($positive as $requestId => $leave) {
            $exclusions = [];
            foreach ($reversals as $reversal) {
                if ((int) $reversal['parent_request_id'] !== $requestId) {
                    continue;
                }
                $exclusions[] = [
                    'from_at' => $reversal['from_at'],
                    'to_at' => $reversal['to_at'],
                ];
            }
            foreach ($this->subtractCoverageIntervals([
                ['from_at' => $leave['from_at'], 'to_at' => $leave['to_at']],
            ], $exclusions) as $interval) {
                if ($interval['to_at'] <= $windowStart || $interval['from_at'] >= $windowEnd) {
                    continue;
                }
                $coverage[] = [
                    'source_type' => 'leave',
                    'source_id' => $requestId,
                    'coverage_behavior' => 'leave',
                    'from_at' => $interval['from_at'],
                    'to_at' => $interval['to_at'],
                    'source_version_id' => $leave['policy_version_id'],
                ];
            }
        }

        return $coverage;
    }

    /** @return list<array<string,mixed>> */
    private function approvedPermissions(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        $statement = $this->db->prepare(
            'SELECT request.id, request.from_at, request.to_at, request.timezone,
                    request.policy_version_id, type.coverage_behavior
             FROM staff_permission_requests request
             INNER JOIN staff_permission_types type ON type.id = request.permission_type_id
             WHERE request.staff_user_id = :staff_user_id
               AND request.status = \'approved\'
               AND request.to_at > :window_start
               AND request.from_at < :window_end
               AND type.coverage_behavior IN (\'late_arrival\', \'early_leave\', \'mission\')
             ORDER BY request.from_at ASC, request.to_at ASC, request.id ASC'
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'window_start' => $this->databaseDateTime($windowStart),
            'window_end' => $this->databaseDateTime($windowEnd),
        ]);

        $coverage = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $behavior = (string) ($row['coverage_behavior'] ?? '');
            if (!in_array($behavior, ['late_arrival', 'early_leave', 'mission'], true)) {
                throw new DomainException('APPROVED_COVERAGE_BEHAVIOR_CORRUPT');
            }
            $timezone = $this->timezone((string) ($row['timezone'] ?? ''));
            $from = $this->parseDatabaseDateTime((string) ($row['from_at'] ?? ''), $timezone);
            $to = $this->parseDatabaseDateTime((string) ($row['to_at'] ?? ''), $timezone);
            if ($to <= $from) {
                throw new DomainException('APPROVED_COVERAGE_WINDOW_CORRUPT');
            }
            if ($to <= $windowStart || $from >= $windowEnd) {
                continue;
            }
            $coverage[] = [
                'source_type' => $behavior === 'mission' ? 'mission' : 'permission',
                'source_id' => (int) ($row['id'] ?? 0),
                'coverage_behavior' => $behavior,
                'from_at' => $from,
                'to_at' => $to,
                'source_version_id' => isset($row['policy_version_id']) && $row['policy_version_id'] !== null
                    ? (int) $row['policy_version_id']
                    : null,
            ];
        }

        return $coverage;
    }

    /**
     * Compatibility-only full-day leave projection.  It remains read-only and
     * will be replaced by the immutable leave ledger in the later leave phase.
     *
     * @return list<array<string,mixed>>
     */
    private function approvedLegacyLeaves(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        $statement = $this->db->prepare(
            'SELECT id, start_date, end_date
             FROM staff_leaves
             WHERE user_id = :staff_user_id
               AND status = \'approved\'
               AND start_date <= :window_end_date
               AND end_date >= :window_start_date
             ORDER BY start_date ASC, end_date ASC, id ASC'
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'window_start_date' => $windowStart->format('Y-m-d'),
            'window_end_date' => $windowEnd->format('Y-m-d'),
        ]);

        $timezone = $windowStart->getTimezone();
        $coverage = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $from = $this->parseDate((string) ($row['start_date'] ?? ''), $timezone);
            $inclusiveEnd = $this->parseDate((string) ($row['end_date'] ?? ''), $timezone);
            $to = $inclusiveEnd->modify('+1 day');
            if ($to <= $from) {
                throw new DomainException('APPROVED_LEAVE_WINDOW_CORRUPT');
            }
            if ($to <= $windowStart || $from >= $windowEnd) {
                continue;
            }
            $coverage[] = [
                'source_type' => 'leave',
                'source_id' => (int) ($row['id'] ?? 0),
                'coverage_behavior' => 'leave',
                'from_at' => $from,
                'to_at' => $to,
                'source_version_id' => null,
            ];
        }

        return $coverage;
    }

    /**
     * @param list<array{from_at:DateTimeImmutable,to_at:DateTimeImmutable}> $source
     * @param list<array{from_at:DateTimeImmutable,to_at:DateTimeImmutable}> $excluded
     * @return list<array{from_at:DateTimeImmutable,to_at:DateTimeImmutable}>
     */
    private function subtractCoverageIntervals(array $source, array $excluded): array
    {
        usort($excluded, static fn (array $left, array $right): int => $left['from_at'] <=> $right['from_at']);
        $remaining = [];
        foreach ($source as $interval) {
            $cursor = $interval['from_at'];
            foreach ($excluded as $block) {
                if ($block['to_at'] <= $cursor) {
                    continue;
                }
                if ($block['from_at'] >= $interval['to_at']) {
                    break;
                }
                if ($block['from_at'] > $cursor) {
                    $remaining[] = [
                        'from_at' => $cursor,
                        'to_at' => $block['from_at'] < $interval['to_at'] ? $block['from_at'] : $interval['to_at'],
                    ];
                }
                if ($block['to_at'] > $cursor) {
                    $cursor = $block['to_at'];
                }
                if ($cursor >= $interval['to_at']) {
                    break;
                }
            }
            if ($cursor < $interval['to_at']) {
                $remaining[] = ['from_at' => $cursor, 'to_at' => $interval['to_at']];
            }
        }

        return array_values(array_filter(
            $remaining,
            static fn (array $interval): bool => $interval['to_at'] > $interval['from_at']
        ));
    }

    private function timezone(string $value): DateTimeZone
    {
        $value = trim($value);
        if ($value === '') {
            throw new DomainException('APPROVED_COVERAGE_TIMEZONE_CORRUPT');
        }
        try {
            return new DateTimeZone($value);
        } catch (Throwable $exception) {
            throw new DomainException('APPROVED_COVERAGE_TIMEZONE_CORRUPT', 0, $exception);
        }
    }

    private function parseDatabaseDateTime(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }
        throw new DomainException('APPROVED_COVERAGE_DATETIME_CORRUPT');
    }

    private function parseDate(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $value) {
            throw new DomainException('APPROVED_LEAVE_DATE_CORRUPT');
        }
        return $parsed;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
