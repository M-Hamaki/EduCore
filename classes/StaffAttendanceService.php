<?php
require_once __DIR__ . '/HrSchemaGuard.php';
require_once __DIR__ . '/StaffEmploymentLifecycleService.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
/**
 * StaffAttendanceService
 * طبقة خدمة موحدة لمنطق حضور وغياب الموظفين
 */
class StaffAttendanceService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getStaffReportAccessPolicy(): array
    {
        $stmt = $this->db->query("SELECT setting_key, setting_value
                                  FROM settings
                                  WHERE setting_key IN ('staff_reports_allow_view', 'staff_reports_allow_export')");
        $map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $allowView = !isset($map['staff_reports_allow_view']) || (string)$map['staff_reports_allow_view'] !== '0';
        $allowExport = !isset($map['staff_reports_allow_export']) || (string)$map['staff_reports_allow_export'] !== '0';

        return [
            'allow_view' => $allowView,
            'allow_export' => $allowExport
        ];
    }

    public function getDefaultShiftSettings(): array
    {
        $shiftStart = '07:30';
        $shiftEnd = '14:30';
        $shiftGraceMinutes = 15;

        $stmt = $this->db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('staff_shift_start','staff_shift_end','staff_shift_grace_minutes')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (!empty($settings['staff_shift_start']) && preg_match('/^\d{2}:\d{2}$/', $settings['staff_shift_start'])) {
            $shiftStart = $settings['staff_shift_start'];
        }
        if (!empty($settings['staff_shift_end']) && preg_match('/^\d{2}:\d{2}$/', $settings['staff_shift_end'])) {
            $shiftEnd = $settings['staff_shift_end'];
        }
        if (isset($settings['staff_shift_grace_minutes']) && is_numeric($settings['staff_shift_grace_minutes'])) {
            $shiftGraceMinutes = max(0, (int)$settings['staff_shift_grace_minutes']);
        }

        return [
            'shift_start' => $shiftStart,
            'shift_end' => $shiftEnd,
            'shift_grace_minutes' => $shiftGraceMinutes
        ];
    }

    public function getActiveStaffList(array $filters = []): array
    {
        $where = ["u.status = 'active'", "(u.role IN ('teacher','specialist','admin') OR sp.user_id IS NOT NULL)"];
        $params = [];

        $filterJobTitle = trim((string)($filters['job_title'] ?? ''));
        $filterStageId = (string)($filters['stage_id'] ?? '');
        $filterUser = (string)($filters['user_id'] ?? '');

        if ($filterJobTitle !== '') {
            $jobTitleValues = StaffEmploymentLifecycleService::jobTitleFilterValues($filterJobTitle);
            if ($jobTitleValues === []) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'sp.job_title IN (' . implode(',', array_fill(0, count($jobTitleValues), '?')) . ')';
                array_push($params, ...$jobTitleValues);
            }
        }

        if ($filterStageId !== '') {
            $where[] = "(
                EXISTS (
                    SELECT 1
                    FROM user_class_access uca
                    JOIN classes c ON c.id = uca.class_id
                    JOIN grades g ON g.id = c.grade_id
                    WHERE uca.user_id = u.id AND g.stage_id = ?
                )
                OR EXISTS (
                    SELECT 1
                    FROM specialist_active_classes sc
                    JOIN classes c2 ON c2.id = sc.class_id
                    JOIN grades g2 ON g2.id = c2.grade_id
                    WHERE sc.specialist_id = u.id AND g2.stage_id = ?
                )
            )";
            $params[] = (int)$filterStageId;
            $params[] = (int)$filterStageId;
        }

        if ($filterUser !== '') {
            $where[] = "u.id = ?";
            $params[] = (int)$filterUser;
        }

        $sql = "SELECT u.id, u.name, u.role, sp.job_title
                FROM users u
                LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY u.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null);
        }
        unset($row);
        return $rows;
    }

    public function getShiftOverridesByUser(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        try {
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $this->db->prepare("SELECT user_id, shift_start, shift_end, grace_minutes, is_active
                                       FROM staff_shift_overrides
                                       WHERE is_active = 1 AND user_id IN ($in)");
            $stmt->execute($userIds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $map = [];
            foreach ($rows as $row) {
                $map[(int)$row['user_id']] = $row;
            }
            return $map;
        } catch (Exception $e) {
            return [];
        }
    }

    public function getAttendanceByDate(string $date, array $userIds = []): array
    {
        if (empty($userIds)) {
            return [];
        }

        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare("SELECT a.*, u.name AS staff_name
                                   FROM staff_attendance a
                                   JOIN users u ON a.user_id = u.id
                                   WHERE a.attendance_date = ? AND a.user_id IN ($in)
                                   ORDER BY u.name");
        $stmt->execute(array_merge([$date], $userIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['user_id']] = $row;
        }
        return $map;
    }

    public function getApprovedLeavesByDate(string $date, array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare("SELECT user_id, leave_type, start_date, end_date, reason
                                   FROM staff_leaves
                                   WHERE status = 'approved'
                                     AND ? BETWEEN start_date AND end_date
                                     AND user_id IN ($in)");
        $stmt->execute(array_merge([$date], $userIds));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['user_id']] = $row;
        }
        return $map;
    }

    public function getApprovedPermissionsByDate(string $date, array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare("SELECT user_id, permission_type, permission_date, time_from, time_to, reason
                                   FROM staff_permissions
                                   WHERE status = 'approved'
                                     AND permission_date = ?
                                     AND user_id IN ($in)");
        $stmt->execute(array_merge([$date], $userIds));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['user_id']] = $row;
        }
        return $map;
    }

    public function buildDailyEntryContext(?array $attendance, ?array $leaveInfo, ?array $permInfo, array $attendanceStatus): array
    {
        $hasApprovedExcuse = !empty($leaveInfo) || !empty($permInfo);
        $defaultStatus = $hasApprovedExcuse ? 'excused' : 'present';
        $selectedStatus = $attendance['status'] ?? $defaultStatus;

        if (!isset($attendanceStatus[$selectedStatus])) {
            $selectedStatus = $defaultStatus;
        }

        $autoNote = '';
        if ($leaveInfo) {
            $autoNote = 'إجازة معتمدة (' . ($leaveInfo['leave_type'] ?? '-') . ')';
        } elseif ($permInfo) {
            $autoNote = 'إذن معتمد (' . ($permInfo['permission_type'] ?? '-') . ')';
        }

        $approved = null;
        if ($leaveInfo) {
            $approved = [
                'label' => 'إجازة معتمدة',
                'badge' => 'info',
                'icon' => 'fa-calendar-check',
                'tooltip' => 'من ' . ($leaveInfo['start_date'] ?? '-') . ' إلى ' . ($leaveInfo['end_date'] ?? '-')
            ];
        } elseif ($permInfo) {
            $approved = [
                'label' => 'إذن معتمد',
                'badge' => 'primary',
                'icon' => 'fa-user-shield',
                'tooltip' => ($permInfo['time_from'] ?: '--') . ' - ' . ($permInfo['time_to'] ?: '--')
            ];
        }

        return [
            'has_approved_excuse' => $hasApprovedExcuse,
            'default_status' => $defaultStatus,
            'selected_status' => $selectedStatus,
            'note_value' => $attendance['notes'] ?? $autoNote,
            'approved' => $approved
        ];
    }

    public function getDailyDashboardStats(
        string $selectedDate,
        array $staffList,
        array $dayAttendance,
        array $approvedLeavesByUser,
        array $approvedPermissionsByUser,
        array $shiftSettings,
        array $shiftOverridesByUser = []
    ): array {
        $stats = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'unregistered' => 0];
        $missingRegistrationStaff = [];

        $isSelectedToday = ($selectedDate === date('Y-m-d'));
        $shiftCutoffPassed = false;
        $defaultShiftStart = $shiftSettings['shift_start'];
        $defaultGrace = (int)$shiftSettings['shift_grace_minutes'];

        if ($isSelectedToday) {
            $cutoffTs = strtotime($selectedDate . ' ' . $defaultShiftStart) + ($defaultGrace * 60);
            $shiftCutoffPassed = (time() > $cutoffTs);
        }

        foreach ($staffList as $staffMember) {
            $uid = (int)$staffMember['id'];
            $attendance = $dayAttendance[$uid] ?? null;

            if ($attendance) {
                $statusKey = $attendance['status'] ?? 'present';
                if (!isset($stats[$statusKey])) {
                    $statusKey = 'present';
                }
                $stats[$statusKey]++;
                continue;
            }

            $hasApprovedExcuse = isset($approvedLeavesByUser[$uid]) || isset($approvedPermissionsByUser[$uid]);
            if ($hasApprovedExcuse) {
                $stats['excused']++;
                continue;
            }

            $stats['unregistered']++;

            $effectiveShiftStart = $defaultShiftStart;
            $effectiveGrace = $defaultGrace;
            if (isset($shiftOverridesByUser[$uid])) {
                $effectiveShiftStart = substr((string)$shiftOverridesByUser[$uid]['shift_start'], 0, 5);
                $effectiveGrace = (int)$shiftOverridesByUser[$uid]['grace_minutes'];
            }

            if ($isSelectedToday && $shiftCutoffPassed) {
                $userCutoffTs = strtotime($selectedDate . ' ' . $effectiveShiftStart) + ($effectiveGrace * 60);
                if (time() > $userCutoffTs) {
                    $missingRegistrationStaff[] = $staffMember;
                }
            }
        }

        return [
            'stats' => $stats,
            'missing_registration_staff' => $missingRegistrationStaff,
            'is_selected_today' => $isSelectedToday,
            'shift_cutoff_passed' => $shiftCutoffPassed
        ];
    }

    public function buildDailyReportRows(string $reportDate, array $staffList, array $attendanceStatus): array
    {
        $staffIds = array_values(array_map(static function ($s) {
            return (int)$s['id'];
        }, $staffList));

        $attendanceMap = $this->getAttendanceByDate($reportDate, $staffIds);
        $leaveMap = $this->getApprovedLeavesByDate($reportDate, $staffIds);
        $permMap = $this->getApprovedPermissionsByDate($reportDate, $staffIds);

        $rows = [];
        foreach ($staffList as $staff) {
            $uid = (int)$staff['id'];
            $attendance = $attendanceMap[$uid] ?? null;
            $leave = $leaveMap[$uid] ?? null;
            $perm = $permMap[$uid] ?? null;

            $status = 'غير مسجل';
            $checkIn = '-';
            $checkOut = '-';
            $lateMinutes = '-';
            $note = '-';

            if ($attendance) {
                $status = $attendanceStatus[$attendance['status']] ?? $attendance['status'];
                $checkIn = $attendance['check_in'] ? substr($attendance['check_in'], 0, 5) : '-';
                $checkOut = $attendance['check_out'] ? substr($attendance['check_out'], 0, 5) : '-';
                $lateMinutes = (string)($attendance['late_minutes'] ?? 0);
                $note = $attendance['notes'] ?: '-';
            } elseif ($leave) {
                $status = 'إجازة معتمدة';
                $note = 'نوع الإجازة: ' . ($leave['leave_type'] ?? '-') . ' - ' . ($leave['reason'] ?? '');
            } elseif ($perm) {
                $status = 'إذن معتمد';
                $note = 'نوع الإذن: ' . ($perm['permission_type'] ?? '-') . ' (' . ($perm['time_from'] ?: '--') . '-' . ($perm['time_to'] ?: '--') . ')';
            }

            $rows[] = [
                'name' => $staff['name'],
                'status' => $status,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'late_minutes' => $lateMinutes,
                'note' => $note
            ];
        }

        return $rows;
    }

    public function buildLatenessRows(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare("SELECT a.attendance_date, u.name, a.check_in, a.late_minutes, a.notes
                                   FROM staff_attendance a
                                   JOIN users u ON u.id = a.user_id
                                   WHERE a.attendance_date BETWEEN ? AND ?
                                     AND (a.status = 'late' OR a.late_minutes > 0)
                                   ORDER BY a.attendance_date DESC, a.late_minutes DESC");
        $stmt->execute([$dateFrom, $dateTo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buildMonthlyAgendaRows(string $month, int $userId): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $daysInMonth = (int)date('t', strtotime($month . '-01'));
        $monthStart = $month . '-01';
        $monthEnd = $month . '-' . sprintf('%02d', $daysInMonth);

        $statusByDay = [];
        if ($userId > 0) {
            $attStmt = $this->db->prepare("SELECT attendance_date, status
                                          FROM staff_attendance
                                          WHERE user_id = ? AND attendance_date BETWEEN ? AND ?");
            $attStmt->execute([$userId, $monthStart, $monthEnd]);
            foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $statusByDay[$row['attendance_date']] = $row['status'];
            }

            $leaveStmt = $this->db->prepare("SELECT start_date, end_date
                                            FROM staff_leaves
                                            WHERE user_id = ?
                                              AND status = 'approved'
                                              AND end_date >= ?
                                              AND start_date <= ?");
            $leaveStmt->execute([$userId, $monthStart, $monthEnd]);
            foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $start = strtotime(max($row['start_date'], $monthStart));
                $end = strtotime(min($row['end_date'], $monthEnd));
                for ($day = $start; $day <= $end; $day += 86400) {
                    $statusByDay[date('Y-m-d', $day)] = 'leave';
                }
            }
        }

        $agendaRows = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = $month . '-' . sprintf('%02d', $day);
            $rawStatus = $statusByDay[$date] ?? 'absent';
            $label = 'غائب';
            $color = 'danger';

            if ($rawStatus === 'present') {
                $label = 'حاضر';
                $color = 'success';
            } elseif ($rawStatus === 'late') {
                $label = 'متأخر';
                $color = 'warning';
            } elseif ($rawStatus === 'excused') {
                $label = 'بعذر';
                $color = 'info';
            } elseif ($rawStatus === 'leave') {
                $label = 'إجازة';
                $color = 'warning';
            }

            $agendaRows[] = [
                'date' => $date,
                'day' => date('l', strtotime($date)),
                'label' => $label,
                'color' => $color
            ];
        }

        return $agendaRows;
    }

    public function ensureAttendanceAuditTable(): void
    {
        (new HrSchemaGuard($this->db))->assertTable('staff_attendance_audit');
    }

    public function ensureBiometricTables(): void
    {
        (new HrSchemaGuard($this->db))->assertTable('staff_biometric_logs');
    }

    public function saveManualAttendanceWithAudit(
        int $adminId,
        int $userId,
        string $date,
        string $status,
        ?string $checkIn,
        ?string $checkOut,
        int $lateMinutes,
        string $notes
    ): array {
        $before = $this->getAttendanceRecordByUserDate($userId, $date);

        $stmt = $this->db->prepare("INSERT INTO staff_attendance (user_id, attendance_date, status, check_in, check_out, late_minutes, notes)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE
                                       status = VALUES(status),
                                       check_in = VALUES(check_in),
                                       check_out = VALUES(check_out),
                                       late_minutes = VALUES(late_minutes),
                                       notes = VALUES(notes)");
        $stmt->execute([$userId, $date, $status, $checkIn, $checkOut, $lateMinutes, $notes]);

        $after = $this->getAttendanceRecordByUserDate($userId, $date);
        if (!$after) {
            return ['changed' => false, 'action' => null, 'attendance_id' => null];
        }

        $actionType = $before ? 'update' : 'insert';
        $changed = !$before || $this->attendanceDataChanged($before, $after);

        if ($changed) {
            $this->logAttendanceAudit(
                (int)$after['id'],
                $userId,
                $date,
                $actionType,
                $before,
                $after,
                $adminId,
                'manual'
            );
        }

        return [
            'changed' => $changed,
            'action' => $actionType,
            'attendance_id' => (int)$after['id']
        ];
    }

    public function deleteAttendanceByIdWithAudit(int $attendanceId, int $adminId): bool
    {
        $before = $this->getAttendanceRecordById($attendanceId);
        if (!$before) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM staff_attendance WHERE id = ?");
        $stmt->execute([$attendanceId]);
        if ($stmt->rowCount() <= 0) {
            return false;
        }

        $this->logAttendanceAudit(
            (int)$attendanceId,
            (int)$before['user_id'],
            (string)$before['attendance_date'],
            'delete',
            $before,
            null,
            $adminId,
            'manual'
        );

        return true;
    }

    public function importBiometricRows(array $rows, int $adminId, string $defaultDeviceId = ''): array
    {
        $this->ensureBiometricTables();
        $this->ensureAttendanceAuditTable();

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
        $insertedLogs = 0;
        $duplicateLogs = 0;
        $invalidRows = 0;
        $touched = [];

        $ins = $this->db->prepare("INSERT INTO staff_biometric_logs
            (user_id, log_datetime, log_type, device_id, raw_payload, imported_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id = id");

        foreach ($rows as $row) {
            $userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
            $dateTime = trim((string)($row['log_datetime'] ?? ''));
            $logType = strtolower(trim((string)($row['log_type'] ?? 'unknown')));
            $deviceId = trim((string)($row['device_id'] ?? $defaultDeviceId));

            if ($userId <= 0 || $dateTime === '' || strtotime($dateTime) === false) {
                $invalidRows++;
                continue;
            }

            if (!in_array($logType, ['in', 'out', 'unknown'], true)) {
                $logType = 'unknown';
            }

            $normalizedDateTime = date('Y-m-d H:i:s', strtotime($dateTime));
            $rawPayload = isset($row['raw_payload']) ? (string)$row['raw_payload'] : null;

            $ins->execute([$userId, $normalizedDateTime, $logType, $deviceId !== '' ? $deviceId : null, $rawPayload, $adminId]);
            if ($ins->rowCount() > 0) {
                $insertedLogs++;
                $workDate = substr($normalizedDateTime, 0, 10);
                $touched[$userId . '|' . $workDate] = ['user_id' => $userId, 'date' => $workDate];
            } else {
                $duplicateLogs++;
            }
        }

        $syncedAttendance = 0;
        foreach ($touched as $item) {
            if ($this->syncAttendanceFromBiometric((int)$item['user_id'], (string)$item['date'], $adminId)) {
                $syncedAttendance++;
            }
        }

        $result = [
            'inserted_logs' => $insertedLogs,
            'duplicate_logs' => $duplicateLogs,
            'invalid_rows' => $invalidRows,
            'synced_attendance' => $syncedAttendance
        ];
        (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
            'import', 'staff_biometric_batch', null, 'استيراد سجلات الحضور من جهاز البصمة',
            [
                'device_id' => $defaultDeviceId,
                'input_rows' => count($rows),
                'result' => $result,
                'affected_staff_days' => count($touched),
                'undo_policy' => 'biometric_import_reconciliation_required',
            ]
        );
        if ($ownsTransaction) {
            $this->db->commit();
        }
        return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function previewBiometricRows(array $rows, string $defaultDeviceId = ''): array
    {
        $this->ensureBiometricTables();

        $validRows = 0;
        $invalidRows = 0;
        $duplicatesInFile = 0;
        $duplicatesInDb = 0;
        $newRows = 0;
        $impactedDates = [];
        $seenKeys = [];
        $previewRows = [];

        foreach ($rows as $row) {
            $userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
            $dateTime = trim((string)($row['log_datetime'] ?? ''));
            $logType = strtolower(trim((string)($row['log_type'] ?? 'unknown')));
            $deviceId = trim((string)($row['device_id'] ?? $defaultDeviceId));

            if ($userId <= 0 || $dateTime === '' || strtotime($dateTime) === false) {
                $invalidRows++;
                continue;
            }

            if (!in_array($logType, ['in', 'out', 'unknown'], true)) {
                $logType = 'unknown';
            }

            $normalizedDateTime = date('Y-m-d H:i:s', strtotime($dateTime));
            $deviceIdForKey = ($deviceId !== '') ? $deviceId : '';
            $uniqKey = $userId . '|' . $normalizedDateTime . '|' . $logType . '|' . $deviceIdForKey;

            if (isset($seenKeys[$uniqKey])) {
                $duplicatesInFile++;
                continue;
            }
            $seenKeys[$uniqKey] = true;

            $validRows++;
            $exists = $this->biometricLogExists($userId, $normalizedDateTime, $logType, $deviceId);
            if ($exists) {
                $duplicatesInDb++;
            } else {
                $newRows++;
                $impactedDates[$userId . '|' . substr($normalizedDateTime, 0, 10)] = true;
            }

            if (count($previewRows) < 200) {
                $previewRows[] = [
                    'user_id' => $userId,
                    'log_datetime' => $normalizedDateTime,
                    'log_type' => $logType,
                    'device_id' => $deviceId,
                    'exists_in_db' => $exists
                ];
            }
        }

        return [
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'duplicate_rows_in_file' => $duplicatesInFile,
            'duplicate_rows_in_db' => $duplicatesInDb,
            'new_rows' => $newRows,
            'estimated_attendance_days_to_sync' => count($impactedDates),
            'preview_rows' => $previewRows
        ];
    }

    public function getRecentBiometricLogs(int $limit = 200): array
    {
        $limit = max(1, min($limit, 1000));
        $stmt = $this->db->query("SELECT l.*, u.name AS staff_name
                                  FROM staff_biometric_logs l
                                  JOIN users u ON u.id = l.user_id
                                  ORDER BY l.log_datetime DESC
                                  LIMIT " . (int)$limit);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAttendanceAuditRows(array $filters = []): array
    {
        $this->ensureAttendanceAuditTable();

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['action_type'])) {
            $where[] = 'a.action_type = ?';
            $params[] = (string)$filters['action_type'];
        }
        if (!empty($filters['source'])) {
            $where[] = 'a.source = ?';
            $params[] = (string)$filters['source'];
        }
        if (!empty($filters['changed_by'])) {
            $where[] = 'a.changed_by = ?';
            $params[] = (int)$filters['changed_by'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'a.attendance_date >= ?';
            $params[] = (string)$filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'a.attendance_date <= ?';
            $params[] = (string)$filters['date_to'];
        }

        $sql = "SELECT a.*, u.name AS staff_name, changer.name AS changed_by_name
                FROM staff_attendance_audit a
                JOIN users u ON u.id = a.user_id
                LEFT JOIN users changer ON changer.id = a.changed_by
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.created_at DESC, a.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Loads the attendance audit log in a bounded DataTables window.
     * The audit records themselves remain immutable; this method is read-only.
     */
    public function getAttendanceAuditDataTable(array $request): array
    {
        $this->ensureAttendanceAuditTable();

        $draw = max(0, (int)($request['draw'] ?? 0));
        $start = max(0, (int)($request['start'] ?? 0));
        $requestedLength = (int)($request['length'] ?? 50);
        $length = $requestedLength === -1 ? PHP_INT_MAX : max(10, min($requestedLength, 500));

        [$filteredWhere, $filteredParams] = $this->buildAttendanceAuditWhere($request, true);
        $from = ' FROM staff_attendance_audit a
                  JOIN users u ON u.id = a.user_id
                  LEFT JOIN users changer ON changer.id = a.changed_by ';

        $total = (int)$this->db->query('SELECT COUNT(*) FROM staff_attendance_audit')->fetchColumn();

        $filteredStmt = $this->db->prepare('SELECT COUNT(*)' . $from . ' WHERE ' . implode(' AND ', $filteredWhere));
        $filteredStmt->execute($filteredParams);
        $filtered = (int)$filteredStmt->fetchColumn();

        $orderColumns = [
            0 => 'a.id',
            1 => 'u.name',
            2 => 'a.attendance_date',
            3 => 'a.action_type',
            4 => 'a.source',
            5 => 'changer.name',
            6 => 'a.before_data',
            7 => 'a.after_data',
            8 => 'a.created_at'
        ];
        $orderIndex = (int)($request['order'][0]['column'] ?? 8);
        $orderColumn = $orderColumns[$orderIndex] ?? 'a.created_at';
        $orderDirection = strtolower((string)($request['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $rows = [];
        if ($length > 0) {
            $limit = $length === PHP_INT_MAX ? '' : ' LIMIT ' . $start . ', ' . $length;
            $sql = 'SELECT a.*, u.name AS staff_name, changer.name AS changed_by_name' . $from
                . ' WHERE ' . implode(' AND ', $filteredWhere)
                . ' ORDER BY ' . $orderColumn . ' ' . $orderDirection . ', a.id DESC' . $limit;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($filteredParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'rows' => $rows
        ];
    }

    /** @return array{0: array<int, string>, 1: array<int, mixed>} */
    private function buildAttendanceAuditWhere(array $filters, bool $includeSearch): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['action_type'])) {
            $where[] = 'a.action_type = ?';
            $params[] = (string)$filters['action_type'];
        }
        if (!empty($filters['source'])) {
            $where[] = 'a.source = ?';
            $params[] = (string)$filters['source'];
        }
        if (!empty($filters['changed_by'])) {
            $where[] = 'a.changed_by = ?';
            $params[] = (int)$filters['changed_by'];
        }
        if (!empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['date_from'])) {
            $where[] = 'a.attendance_date >= ?';
            $params[] = (string)$filters['date_from'];
        }
        if (!empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['date_to'])) {
            $where[] = 'a.attendance_date <= ?';
            $params[] = (string)$filters['date_to'];
        }

        $search = trim((string)($filters['search']['value'] ?? $filters['search'] ?? ''));
        if ($includeSearch && $search !== '') {
            $where[] = '(u.name LIKE ? OR changer.name LIKE ? OR a.action_type LIKE ? OR a.source LIKE ? OR a.before_data LIKE ? OR a.after_data LIKE ?)';
            $term = '%' . $search . '%';
            $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
        }

        return [$where, $params];
    }

    public function getHrCenterOverview(): array
    {
        $today = date('Y-m-d');
        $staffCount = (int)$this->db->query("SELECT COUNT(*)
                                             FROM users u
                                             LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                                             WHERE u.status = 'active'
                                               AND (u.role IN ('teacher','specialist','admin') OR sp.user_id IS NOT NULL)")->fetchColumn();

        $pendingPermissions = (int)$this->db->query("SELECT COUNT(*) FROM staff_permissions WHERE status = 'pending'")->fetchColumn();
        $pendingLeaves = (int)$this->db->query("SELECT COUNT(*) FROM staff_leaves WHERE status = 'pending'")->fetchColumn();
        $customShifts = 0;
        try {
            $customShifts = (int)$this->db->query("SELECT COUNT(*) FROM staff_shift_overrides WHERE is_active = 1")->fetchColumn();
        } catch (Exception $e) {
            $customShifts = 0;
        }

        $attendanceToday = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
        $stmt = $this->db->prepare("SELECT status, COUNT(*) AS cnt FROM staff_attendance WHERE attendance_date = ? GROUP BY status");
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attendanceToday[$row['status']] = (int)$row['cnt'];
        }

        $biometricLogsToday = 0;
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM staff_biometric_logs WHERE DATE(log_datetime) = ?");
            $stmt->execute([$today]);
            $biometricLogsToday = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $biometricLogsToday = 0;
        }

        $auditChangesToday = 0;
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM staff_attendance_audit WHERE DATE(created_at) = ?");
            $stmt->execute([$today]);
            $auditChangesToday = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $auditChangesToday = 0;
        }

        return [
            'staff_count' => $staffCount,
            'pending_permissions' => $pendingPermissions,
            'pending_leaves' => $pendingLeaves,
            'custom_shifts' => $customShifts,
            'attendance_today' => $attendanceToday,
            'biometric_logs_today' => $biometricLogsToday,
            'audit_changes_today' => $auditChangesToday
        ];
    }

    private function syncAttendanceFromBiometric(int $userId, string $date, int $adminId): bool
    {
        $stmt = $this->db->prepare("SELECT log_datetime, log_type
                                   FROM staff_biometric_logs
                                   WHERE user_id = ? AND DATE(log_datetime) = ?
                                   ORDER BY log_datetime ASC");
        $stmt->execute([$userId, $date]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($logs)) {
            return false;
        }

        $firstIn = null;
        $lastOut = null;
        foreach ($logs as $log) {
            $time = substr($log['log_datetime'], 11, 8);
            if ($firstIn === null && ($log['log_type'] === 'in' || $log['log_type'] === 'unknown')) {
                $firstIn = $time;
            }
            if ($log['log_type'] === 'out' || $log['log_type'] === 'unknown') {
                $lastOut = $time;
            }
        }
        if ($firstIn === null) {
            $firstIn = substr($logs[0]['log_datetime'], 11, 8);
        }
        if ($lastOut === null) {
            $lastOut = substr($logs[count($logs) - 1]['log_datetime'], 11, 8);
        }

        $effectiveShift = $this->getEffectiveShiftForUser($userId);
        $lateMinutes = $this->calculateLateMinutes($date, $firstIn, $effectiveShift['shift_start'], (int)$effectiveShift['grace_minutes']);
        $status = $lateMinutes > 0 ? 'late' : 'present';

        $before = $this->getAttendanceRecordByUserDate($userId, $date);
        $upsert = $this->db->prepare("INSERT INTO staff_attendance (user_id, attendance_date, status, check_in, check_out, late_minutes, notes)
                                     VALUES (?, ?, ?, ?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE
                                         status = VALUES(status),
                                         check_in = VALUES(check_in),
                                         check_out = VALUES(check_out),
                                         late_minutes = VALUES(late_minutes),
                                         notes = VALUES(notes)");

        $autoNote = 'تم التحديث تلقائياً من سجلات البصمة';
        $upsert->execute([$userId, $date, $status, $firstIn, $lastOut, $lateMinutes, $autoNote]);

        $after = $this->getAttendanceRecordByUserDate($userId, $date);
        if (!$after) {
            return false;
        }

        $changed = !$before || $this->attendanceDataChanged($before, $after);
        if ($changed) {
            $this->logAttendanceAudit(
                (int)$after['id'],
                $userId,
                $date,
                'biometric_import',
                $before,
                $after,
                $adminId,
                'biometric'
            );
        }

        return $changed;
    }

    private function biometricLogExists(int $userId, string $dateTime, string $logType, string $deviceId): bool
    {
        if ($deviceId === '') {
            $stmt = $this->db->prepare("SELECT id FROM staff_biometric_logs
                                       WHERE user_id = ? AND log_datetime = ? AND log_type = ? AND device_id IS NULL
                                       LIMIT 1");
            $stmt->execute([$userId, $dateTime, $logType]);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM staff_biometric_logs
                                       WHERE user_id = ? AND log_datetime = ? AND log_type = ? AND device_id = ?
                                       LIMIT 1");
            $stmt->execute([$userId, $dateTime, $logType, $deviceId]);
        }
        return (bool)$stmt->fetchColumn();
    }

    private function calculateLateMinutes(string $date, string $checkInTime, string $shiftStart, int $graceMinutes): int
    {
        $checkInTs = strtotime($date . ' ' . substr($checkInTime, 0, 5));
        $shiftTs = strtotime($date . ' ' . substr($shiftStart, 0, 5));
        if ($checkInTs === false || $shiftTs === false) {
            return 0;
        }

        $threshold = $shiftTs + ($graceMinutes * 60);
        if ($checkInTs <= $threshold) {
            return 0;
        }

        return (int)floor(($checkInTs - $threshold) / 60);
    }

    private function getEffectiveShiftForUser(int $userId): array
    {
        $default = $this->getDefaultShiftSettings();
        $start = $default['shift_start'];
        $end = $default['shift_end'];
        $grace = (int)$default['shift_grace_minutes'];

        try {
            $stmt = $this->db->prepare("SELECT shift_start, shift_end, grace_minutes
                                       FROM staff_shift_overrides
                                       WHERE user_id = ? AND is_active = 1
                                       LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['shift_start'])) {
                    $start = substr((string)$row['shift_start'], 0, 5);
                }
                if (!empty($row['shift_end'])) {
                    $end = substr((string)$row['shift_end'], 0, 5);
                }
                if (isset($row['grace_minutes'])) {
                    $grace = max(0, (int)$row['grace_minutes']);
                }
            }
        } catch (Exception $e) {
            // ignore missing overrides table
        }

        return [
            'shift_start' => $start,
            'shift_end' => $end,
            'grace_minutes' => $grace
        ];
    }

    private function getAttendanceRecordByUserDate(int $userId, string $date): ?array
    {
        $stmt = $this->db->prepare("SELECT id, user_id, attendance_date, status, check_in, check_out, late_minutes, notes
                                   FROM staff_attendance
                                   WHERE user_id = ? AND attendance_date = ?
                                   LIMIT 1");
        $stmt->execute([$userId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getAttendanceRecordById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT id, user_id, attendance_date, status, check_in, check_out, late_minutes, notes
                                   FROM staff_attendance
                                   WHERE id = ?
                                   LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ──────────────────────────────────────────────────────────────
    // Employee Code Support
    // ──────────────────────────────────────────────────────────────

    /**
     * يضيف عمود employee_code إلى جدول users إن لم يكن موجوداً
     */
    public function ensureEmployeeCodeColumn(): void
    {
        $guard = new HrSchemaGuard($this->db);
        $guard->assertColumn('users', 'employee_code');
        $guard->assertIndex('users', 'uq_employee_code');
    }

    /**
     * تحويل أكواد الموظفين (employee_code) في مصفوفة صفوف إلى user_id حقيقي.
     * يجب أن يكون لكل صف مفتاح 'employee_code'.
     * يُعيد ['rows' => [...], 'unresolved_codes' => [...]]
     */
    public function resolveEmployeeCodesFromRows(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            $code = trim((string)($row['employee_code'] ?? ''));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
        $codes = array_keys($codes);

        if (empty($codes)) {
            return ['rows' => [], 'unresolved_codes' => []];
        }

        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->prepare("SELECT id, employee_code FROM users WHERE employee_code IN ($in) AND status = 'active'");
        $stmt->execute($codes);

        $codeMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $codeMap[trim((string)$row['employee_code'])] = (int)$row['id'];
        }

        $resolved       = [];
        $unresolvedCodes = [];
        foreach ($rows as $row) {
            $code = trim((string)($row['employee_code'] ?? ''));
            if (isset($codeMap[$code])) {
                $row['user_id'] = $codeMap[$code];
                unset($row['employee_code']);
                $resolved[] = $row;
            } else {
                $unresolvedCodes[] = $code;
            }
        }

        return [
            'rows'             => $resolved,
            'unresolved_codes' => array_values(array_unique($unresolvedCodes))
        ];
    }

    /**
     * جلب سجلات البصمة التي لم تُزامَن بعد (لا يوجد سجل حضور مقابلها)
     */
    public function getUnsyncedBiometricLogs(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        try {
            $stmt = $this->db->query(
                "SELECT l.user_id,
                        DATE(l.log_datetime)       AS log_date,
                        MIN(l.log_datetime)        AS first_log,
                        MAX(l.log_datetime)        AS last_log,
                        COUNT(*)                   AS log_count,
                        u.name                     AS staff_name
                 FROM staff_biometric_logs l
                 JOIN users u ON u.id = l.user_id
                 WHERE NOT EXISTS (
                     SELECT 1 FROM staff_attendance a
                     WHERE a.user_id = l.user_id
                       AND a.attendance_date = DATE(l.log_datetime)
                 )
                 GROUP BY l.user_id, DATE(l.log_datetime)
                 ORDER BY log_date DESC
                 LIMIT " . (int)$limit
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────

    private function attendanceDataChanged(array $before, array $after): bool
    {
        $keys = ['status', 'check_in', 'check_out', 'late_minutes', 'notes'];
        foreach ($keys as $k) {
            $v1 = isset($before[$k]) ? (string)$before[$k] : '';
            $v2 = isset($after[$k]) ? (string)$after[$k] : '';
            if ($v1 !== $v2) {
                return true;
            }
        }
        return false;
    }

    private function logAttendanceAudit(
        int $attendanceId,
        int $userId,
        string $attendanceDate,
        string $actionType,
        ?array $before,
        ?array $after,
        ?int $changedBy,
        string $source
    ): void {
        $stmt = $this->db->prepare("INSERT INTO staff_attendance_audit
            (attendance_id, user_id, attendance_date, action_type, before_data, after_data, changed_by, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $attendanceId,
            $userId,
            $attendanceDate,
            $actionType,
            $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            $changedBy,
            $source
        ]);
    }

    public function getAnnualLeaveBalances(string $yearStart, string $yearEnd, array $deductibleTypes, ?int $userId = null): array
    {
        if (empty($deductibleTypes)) {
            $deductibleTypes = ['regular', 'sick', 'casual', 'exceptional'];
        }

        $balanceWhere = "u.status = 'active' AND (u.role IN ('teacher','specialist','admin') OR sp.user_id IS NOT NULL)";
        $tailParams = [];
        if ($userId && $userId > 0) {
            $balanceWhere .= " AND u.id = ?";
            $tailParams[] = $userId;
        }

        $deductTypePlaceholders = implode(',', array_fill(0, count($deductibleTypes), '?'));

        $sql = "SELECT
                    u.id AS user_id,
                    u.name AS staff_name,
                    COALESCE(sp.annual_leave_balance, 30) AS annual_balance,
                    COALESCE(c.consumed_days, 0) AS consumed_days,
                    GREATEST(COALESCE(sp.annual_leave_balance, 30) - COALESCE(c.consumed_days, 0), 0) AS remaining_days
                FROM users u
                LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                LEFT JOIN (
                    SELECT user_id, SUM(days_count) AS consumed_days
                    FROM staff_leaves
                    WHERE status = 'approved'
                      AND start_date >= ?
                      AND end_date <= ?
                      AND leave_type IN ($deductTypePlaceholders)
                    GROUP BY user_id
                ) c ON c.user_id = u.id
                WHERE $balanceWhere
                ORDER BY u.name";

        $stmt = $this->db->prepare($sql);
        $params = array_merge([$yearStart, $yearEnd], $deductibleTypes, $tailParams);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
