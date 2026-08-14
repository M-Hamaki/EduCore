<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use EduCore\Modules\Attendance\Contracts\AttendanceExceptionQuery;
use PDO;

/**
 * Attendance-owned, read-only exception projection. Raw evidence locations,
 * biometric identities, link reasons, and attachments never leave this adapter.
 */
final class PdoAttendanceExceptionQuery implements AttendanceExceptionQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function summary(array $filters): array
    {
        return [
            'raw_events' => $this->countRawEvents($filters),
            'unresolved_days' => $this->countUnresolvedDays($filters),
            'comparison_differences' => $this->countComparisonDifferences($filters),
        ];
    }

    public function listItems(array $filters): array
    {
        $rows = [];
        if ($filters['category'] === 'all' || $filters['category'] === 'raw') {
            $rows = array_merge($rows, $this->rawEventItems($filters));
        }
        if ($filters['category'] === 'all' || $filters['category'] === 'day') {
            $rows = array_merge($rows, $this->unresolvedDayItems($filters));
        }
        if ($filters['category'] === 'all' || $filters['category'] === 'comparison') {
            $rows = array_merge($rows, $this->comparisonItems($filters));
        }

        usort($rows, static function (array $left, array $right): int {
            $leftAt = (string) ($left['occurred_at'] ?? '');
            $rightAt = (string) ($right['occurred_at'] ?? '');
            if ($leftAt === $rightAt) {
                return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
            }

            return $rightAt <=> $leftAt;
        });

        return array_slice($rows, 0, (int) $filters['limit']);
    }

    private function countRawEvents(array $filters): int
    {
        [$where, $params] = $this->rawEventWhere($filters);
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM staff_biometric_events AS event_row
             WHERE ' . implode(' AND ', $where)
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private function countUnresolvedDays(array $filters): int
    {
        [$where, $params] = $this->unresolvedDayWhere($filters);
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM staff_attendance_day_versions AS day_row
             WHERE ' . implode(' AND ', $where)
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private function countComparisonDifferences(array $filters): int
    {
        [$where, $params] = $this->comparisonWhere($filters);
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM staff_attendance_reason_lines AS reason_row
             INNER JOIN staff_attendance_day_versions AS day_row ON day_row.id = reason_row.day_version_id
             WHERE ' . implode(' AND ', $where)
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function rawEventItems(array $filters): array
    {
        [$where, $params] = $this->rawEventWhere($filters);
        $statement = $this->db->prepare(
            'SELECT event_row.id,
                    event_row.staff_user_id,
                    COALESCE(event_row.event_at_local, event_row.device_event_at) AS occurred_at,
                    event_row.review_status,
                    CASE
                        WHEN event_row.link_status = \'unmatched\' THEN \'RAW_IDENTITY_UNMATCHED\'
                        WHEN event_row.link_status = \'ambiguous\' THEN \'RAW_IDENTITY_AMBIGUOUS\'
                        WHEN event_row.link_status = \'retired_mapping\' THEN \'RAW_IDENTITY_MAPPING_RETIRED\'
                        WHEN event_row.link_status = \'manual_review\' THEN \'RAW_MANUAL_REVIEW\'
                        WHEN event_row.review_status = \'rejected\' THEN \'RAW_REVIEW_REJECTED\'
                        WHEN event_row.clock_status = \'invalid\' THEN \'RAW_CLOCK_INVALID\'
                        WHEN event_row.clock_status = \'drifted\' THEN \'RAW_CLOCK_DRIFTED\'
                        ELSE \'RAW_REVIEW_PENDING\'
                    END AS issue_code,
                    \'raw\' AS category
             FROM staff_biometric_events AS event_row
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY occurred_at DESC, event_row.id DESC
             LIMIT ' . $this->safeLimit($filters)
        );
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function unresolvedDayItems(array $filters): array
    {
        [$where, $params] = $this->unresolvedDayWhere($filters);
        $statement = $this->db->prepare(
            'SELECT day_row.id,
                    day_row.staff_user_id,
                    day_row.work_date AS occurred_at,
                    day_row.status,
                    day_row.calculation_mode,
                    day_row.is_official,
                    CASE WHEN day_row.status = \'unresolved\' THEN \'DAY_UNRESOLVED\' ELSE \'DAY_EXCEPTION\' END AS issue_code,
                    \'day\' AS category
             FROM staff_attendance_day_versions AS day_row
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY day_row.work_date DESC, day_row.id DESC
             LIMIT ' . $this->safeLimit($filters)
        );
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function comparisonItems(array $filters): array
    {
        [$where, $params] = $this->comparisonWhere($filters);
        $statement = $this->db->prepare(
            'SELECT reason_row.id,
                    day_row.staff_user_id,
                    day_row.work_date AS occurred_at,
                    reason_row.reason_code AS issue_code,
                    day_row.calculation_mode,
                    day_row.is_official,
                    \'comparison\' AS category
             FROM staff_attendance_reason_lines AS reason_row
             INNER JOIN staff_attendance_day_versions AS day_row ON day_row.id = reason_row.day_version_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY day_row.work_date DESC, reason_row.id DESC
             LIMIT ' . $this->safeLimit($filters)
        );
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private function rawEventWhere(array $filters): array
    {
        $where = [
            'COALESCE(event_row.event_at_local, event_row.device_event_at) >= :event_from',
            'COALESCE(event_row.event_at_local, event_row.device_event_at) < :event_to',
            "(event_row.link_status IN ('unmatched', 'ambiguous', 'retired_mapping', 'manual_review')
                OR event_row.review_status IN ('pending', 'rejected')
                OR event_row.clock_status IN ('drifted', 'invalid'))",
        ];
        $params = [
            'event_from' => $filters['date_from'] . ' 00:00:00',
            'event_to' => $this->exclusiveEnd($filters['date_to']),
        ];
        if ($filters['staff_user_id'] !== null) {
            $where[] = 'event_row.staff_user_id = :event_staff_user_id';
            $params['event_staff_user_id'] = $filters['staff_user_id'];
        }

        return [$where, $params];
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private function unresolvedDayWhere(array $filters): array
    {
        $where = [
            'day_row.work_date >= :day_from',
            'day_row.work_date <= :day_to',
            "day_row.status IN ('exception', 'unresolved')",
            'NOT EXISTS (
                SELECT 1
                FROM staff_attendance_day_versions AS newer_day
                WHERE newer_day.staff_user_id = day_row.staff_user_id
                  AND newer_day.work_date = day_row.work_date
                  AND newer_day.version_no > day_row.version_no
            )',
        ];
        $params = [
            'day_from' => $filters['date_from'],
            'day_to' => $filters['date_to'],
        ];
        if ($filters['staff_user_id'] !== null) {
            $where[] = 'day_row.staff_user_id = :day_staff_user_id';
            $params['day_staff_user_id'] = $filters['staff_user_id'];
        }

        return [$where, $params];
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private function comparisonWhere(array $filters): array
    {
        $where = [
            'day_row.work_date >= :comparison_from',
            'day_row.work_date <= :comparison_to',
            "reason_row.reason_code LIKE 'LEGACY_%'",
            "day_row.calculation_mode = 'shadow'",
            'NOT EXISTS (
                SELECT 1
                FROM staff_attendance_day_versions AS newer_day
                WHERE newer_day.staff_user_id = day_row.staff_user_id
                  AND newer_day.work_date = day_row.work_date
                  AND newer_day.version_no > day_row.version_no
            )',
        ];
        $params = [
            'comparison_from' => $filters['date_from'],
            'comparison_to' => $filters['date_to'],
        ];
        if ($filters['staff_user_id'] !== null) {
            $where[] = 'day_row.staff_user_id = :comparison_staff_user_id';
            $params['comparison_staff_user_id'] = $filters['staff_user_id'];
        }

        return [$where, $params];
    }

    private function exclusiveEnd(string $date): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('Africa/Cairo')))
            ->modify('+1 day')
            ->format('Y-m-d H:i:s');
    }

    private function safeLimit(array $filters): int
    {
        return max(1, min(100, (int) ($filters['limit'] ?? 100)));
    }
}
