<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use EduCore\Modules\Attendance\Contracts\LegacyStaffAttendanceDayQuery;
use PDO;

/**
 * Read-only legacy adapter used only while Attendance is in shadow/compare
 * mode. It projects the historical report row and never writes legacy data.
 */
final class PdoLegacyStaffAttendanceDayQuery implements LegacyStaffAttendanceDayQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function forStaffDate(int $staffUserId, string $workDate): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, status, check_in, check_out, late_minutes
             FROM staff_attendance
             WHERE user_id = :staff_user_id AND attendance_date = :work_date
             ORDER BY id ASC
             LIMIT 2'
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'work_date' => $workDate,
        ]);
        $rows = array_values($statement->fetchAll(PDO::FETCH_ASSOC));
        if ($rows === []) {
            return null;
        }
        if (count($rows) > 1) {
            return [
                'id' => (int) $rows[0]['id'],
                'status' => 'legacy_ambiguous',
                'check_in' => null,
                'check_out' => null,
                'late_minutes' => 0,
                'legacy_row_count' => count($rows),
            ];
        }

        return [
            'id' => (int) $rows[0]['id'],
            'status' => (string) ($rows[0]['status'] ?? ''),
            'check_in' => $rows[0]['check_in'] ?? null,
            'check_out' => $rows[0]['check_out'] ?? null,
            'late_minutes' => (int) ($rows[0]['late_minutes'] ?? 0),
            'legacy_row_count' => 1,
        ];
    }
}
