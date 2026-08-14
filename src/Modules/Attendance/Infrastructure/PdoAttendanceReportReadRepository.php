<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use EduCore\Modules\Attendance\Contracts\AttendanceReportReadRepository;
use PDO;

/**
 * Read-only Attendance projection. The selected fields exclude raw biometric
 * identity, raw payload locations, attachments, and Staff-owned request data.
 */
final class PdoAttendanceReportReadRepository implements AttendanceReportReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function officialDays(array $filters): array
    {
        $staffUserIds = $filters['staff_user_ids'] ?? null;
        if (is_array($staffUserIds) && $staffUserIds === []) {
            return [];
        }

        $where = [
            'day_version.work_date >= :date_from',
            'day_version.work_date <= :date_to',
        ];
        $params = [
            ':date_from' => (string) $filters['date_from'],
            ':date_to' => (string) $filters['date_to'],
        ];

        $asOf = $filters['as_of'] ?? null;
        if ($asOf === null) {
            $where[] = 'day_version.is_official = 1';
        } else {
            $where[] = 'day_version.officialized_at IS NOT NULL';
            $where[] = 'day_version.officialized_at <= :as_of_outer';
            $where[] = 'NOT EXISTS (
                SELECT 1
                FROM staff_attendance_day_versions newer_day
                WHERE newer_day.staff_user_id = day_version.staff_user_id
                  AND newer_day.work_date = day_version.work_date
                  AND newer_day.officialized_at IS NOT NULL
                  AND newer_day.officialized_at <= :as_of_newer
                  AND (
                    newer_day.officialized_at > day_version.officialized_at
                    OR (
                        newer_day.officialized_at = day_version.officialized_at
                        AND newer_day.id > day_version.id
                    )
                  )
            )';
            $params[':as_of_outer'] = (string) $asOf;
            $params[':as_of_newer'] = (string) $asOf;
        }

        if (is_array($staffUserIds)) {
            $placeholders = [];
            foreach (array_values($staffUserIds) as $index => $staffUserId) {
                $placeholder = ':staff_user_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = (int) $staffUserId;
            }
            $where[] = 'day_version.staff_user_id IN (' . implode(', ', $placeholders) . ')';
        }

        $cursor = $filters['cursor'] ?? null;
        if (is_array($cursor)) {
            $cursorDate = (string) ($cursor['work_date'] ?? '');
            $cursorStaffUserId = (int) ($cursor['staff_user_id'] ?? 0);
            $cursorDayVersionId = (int) ($cursor['day_version_id'] ?? 0);
            if (!$this->validDate($cursorDate) || $cursorStaffUserId <= 0 || $cursorDayVersionId <= 0) {
                throw new \InvalidArgumentException('ATTENDANCE_REPORT_CURSOR_INVALID');
            }
            $where[] = '(day_version.work_date > :cursor_work_date
                OR (day_version.work_date = :cursor_work_date AND day_version.staff_user_id > :cursor_staff_user_id)
                OR (day_version.work_date = :cursor_work_date AND day_version.staff_user_id = :cursor_staff_user_id AND day_version.id > :cursor_day_version_id))';
            $params[':cursor_work_date'] = $cursorDate;
            $params[':cursor_staff_user_id'] = $cursorStaffUserId;
            $params[':cursor_day_version_id'] = $cursorDayVersionId;
        }

        $scanLimit = max(1, (int) ($filters['scan_limit'] ?? 1));
        $statement = $this->db->prepare(
            'SELECT day_version.id AS day_version_id,
                    day_version.staff_user_id,
                    day_version.work_date,
                    day_version.assignment_id,
                    day_version.schedule_policy_version_id,
                    day_version.calendar_exception_id,
                    day_version.expected_start,
                    day_version.expected_end,
                    day_version.required_minutes,
                    day_version.first_in,
                    day_version.last_out,
                    day_version.worked_minutes,
                    day_version.covered_late_minutes,
                    day_version.covered_early_minutes,
                    day_version.mission_minutes,
                    day_version.leave_minutes,
                    day_version.late_minutes,
                    day_version.early_leave_minutes,
                    day_version.missing_minutes,
                    day_version.status,
                    day_version.run_id,
                    day_version.calculation_mode,
                    day_version.engine_version,
                    day_version.source_fingerprint,
                    day_version.officialized_at
             FROM staff_attendance_day_versions day_version
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY day_version.work_date ASC, day_version.staff_user_id ASC, day_version.id ASC
             LIMIT ' . $scanLimit
        );
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function reasonLinesForDayVersions(array $dayVersionIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $dayVersionIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            'SELECT day_version_id, line_no, reason_code, from_at, to_at, minutes, source_type, source_id, explanation
             FROM staff_attendance_reason_lines
             WHERE day_version_id IN (' . $placeholders . ')
             ORDER BY day_version_id ASC, line_no ASC'
        );
        $statement->execute($ids);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }
}
