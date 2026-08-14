<?php
require_once __DIR__ . '/HrSchemaGuard.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
/**
 * StaffLeaveService
 * طبقة خدمة لإدارة واستعلام إجازات الموظفين
 */
class StaffLeaveService
{
    private const ALLOWED_TYPES = ['regular', 'sick', 'casual', 'exceptional', 'other'];
    private const ALLOWED_STATUSES = ['pending', 'approved', 'rejected'];
    private const LEAVE_POLICY_SETTING_KEY = 'leave_balance_policy_tiers';

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ensureLeaveBalanceColumns(): void
    {
        $guard = new HrSchemaGuard($this->db);
        $guard->assertColumn('staff_profiles', 'annual_leave_balance');
        $guard->assertColumn('staff_profiles', 'leave_balance_notes');
    }

    public function getActiveStaffList(): array
    {
        $stmt = $this->db->query("SELECT u.id, u.name
                                  FROM users u
                                  LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                                  WHERE u.status = 'active'
                                    AND (u.role IN ('teacher','specialist','admin') OR sp.user_id IS NOT NULL)
                                  ORDER BY u.name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLeaveById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM staff_leaves WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getLeaves(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'l.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'l.leave_type = ?';
            $params[] = (string)$filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'l.status = ?';
            $params[] = (string)$filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'l.start_date >= ?';
            $params[] = (string)$filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'l.end_date <= ?';
            $params[] = (string)$filters['date_to'];
        }

        $sql = "SELECT l.*, u.name AS staff_name
                FROM staff_leaves l
                JOIN users u ON l.user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.start_date DESC, l.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLeaveStats(): array
    {
        $stats = $this->db->query("SELECT leave_type, COUNT(*) AS cnt, SUM(days_count) AS total_days
                                   FROM staff_leaves
                                   WHERE status = 'approved'
                                   GROUP BY leave_type")->fetchAll(PDO::FETCH_ASSOC);
        $statsMap = [];
        foreach ($stats as $row) {
            $statsMap[$row['leave_type']] = $row;
        }

        $statusStats = $this->db->query("SELECT status, COUNT(*) AS cnt FROM staff_leaves GROUP BY status")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $total = (int)$this->db->query("SELECT COUNT(*) FROM staff_leaves")->fetchColumn();

        return [
            'leave_stats_map' => $statsMap,
            'status_stats' => $statusStats,
            'total' => $total
        ];
    }

    public function getDeductibleTypes(array $leaveTypes): array
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'leave_deductible_types' LIMIT 1");
        $stmt->execute();
        $setting = (string)($stmt->fetchColumn() ?: '');
        $types = array_values(array_filter(array_map('trim', explode(',', $setting)), static function ($type) use ($leaveTypes) {
            return $type !== '' && isset($leaveTypes[$type]);
        }));

        if (empty($types)) {
            $types = ['regular', 'sick', 'casual', 'exceptional'];
        }

        return $types;
    }

    public function getPendingLeaves(int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));
        $stmt = $this->db->query("SELECT l.id, l.leave_type, l.start_date, l.end_date, l.days_count, l.reason, u.name AS staff_name
                                  FROM staff_leaves l
                                  JOIN users u ON u.id = l.user_id
                                  WHERE l.status = 'pending'
                                  ORDER BY l.start_date DESC, l.created_at DESC
                                  LIMIT " . (int)$limit);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLeaveBalancePolicy(): array
    {
        $defaultPolicy = $this->getDefaultLeaveBalancePolicy();

        $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([self::LEAVE_POLICY_SETTING_KEY]);
        $rawPolicy = (string)($stmt->fetchColumn() ?: '');
        if ($rawPolicy === '') {
            return $defaultPolicy;
        }

        $decoded = json_decode($rawPolicy, true);
        if (!is_array($decoded)) {
            return $defaultPolicy;
        }

        $tiers = $this->normalizeLeavePolicyTiers($decoded);
        return empty($tiers) ? $defaultPolicy : $tiers;
    }

    public function saveLeaveBalancePolicy(array $tiers): void
    {
        $normalizedTiers = $this->normalizeLeavePolicyTiers($tiers, true);
        if (empty($normalizedTiers)) {
            throw new InvalidArgumentException('يجب إدخال سياسة رصيد واحدة على الأقل');
        }

        $this->saveAuditedSetting(
            self::LEAVE_POLICY_SETTING_KEY,
            json_encode($normalizedTiers, JSON_UNESCAPED_UNICODE),
            'سياسات رصيد الإجازات السنوي حسب مدة الخدمة من تاريخ التعيين'
        );
    }

    public function getAnnualLeaveBalanceRows(int $year, array $deductibleTypes, ?int $userId = null, string $role = 'teacher'): array
    {
        if (empty($deductibleTypes)) {
            $deductibleTypes = ['regular', 'sick', 'casual', 'exceptional'];
        }

        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);
        $policyTiers = $this->getLeaveBalancePolicy();

        $where = ["u.status = 'active'", "(u.role IN ('teacher','specialist','admin') OR sp.user_id IS NOT NULL)"];
        $params = [$yearStart, $yearEnd];

        $placeholders = implode(',', array_fill(0, count($deductibleTypes), '?'));
        foreach ($deductibleTypes as $type) {
            $params[] = $type;
        }

        if ($role !== '' && $role !== 'all') {
            $where[] = 'u.role = ?';
            $params[] = $role;
        }
        if ($userId !== null && $userId > 0) {
            $where[] = 'u.id = ?';
            $params[] = $userId;
        }

        $sql = "SELECT
                    u.id AS user_id,
                    u.name AS staff_name,
                    u.role,
                    sp.hire_date,
                    sp.annual_leave_balance,
                    sp.leave_balance_notes,
                    COALESCE(c.consumed_days, 0) AS consumed_days
                FROM users u
                LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                LEFT JOIN (
                    SELECT user_id, SUM(days_count) AS consumed_days
                    FROM staff_leaves
                    WHERE status = 'approved'
                      AND start_date >= ?
                      AND end_date <= ?
                      AND leave_type IN ($placeholders)
                    GROUP BY user_id
                ) c ON c.user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY u.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $policyInfo = $this->resolveLeavePolicyForHireDate($row['hire_date'] ?? null, $yearEnd, $policyTiers);
            $effectiveBalance = isset($row['annual_leave_balance']) ? (float)$row['annual_leave_balance'] : (float)$policyInfo['balance'];
            $row['policy_balance'] = (float)$policyInfo['balance'];
            $row['policy_label'] = $policyInfo['label'];
            $row['service_months'] = $policyInfo['service_months'];
            $row['effective_balance'] = $effectiveBalance;
            $row['remaining_days'] = max($effectiveBalance - (float)$row['consumed_days'], 0);
        }
        unset($row);

        return $rows;
    }

    public function updateAnnualLeaveBalance(int $userId, float $balance, string $notes = ''): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('الموظف المحدد غير صالح');
        }
        if ($balance < 0) {
            throw new InvalidArgumentException('رصيد الإجازات يجب أن يكون صفراً أو أكبر');
        }

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $beforeStmt = $this->db->prepare('SELECT * FROM staff_profiles WHERE user_id = ? FOR UPDATE');
            $beforeStmt->execute([$userId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $stmt = $this->db->prepare("INSERT INTO staff_profiles (user_id, annual_leave_balance, leave_balance_notes)
                                        VALUES (?, ?, ?)
                                        ON DUPLICATE KEY UPDATE annual_leave_balance = VALUES(annual_leave_balance), leave_balance_notes = VALUES(leave_balance_notes)");
            $stmt->execute([$userId, $balance, trim($notes)]);
            $afterStmt = $this->db->prepare('SELECT * FROM staff_profiles WHERE user_id = ?');
            $afterStmt->execute([$userId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new RuntimeException('تعذر إعادة تحميل رصيد إجازات الموظف');
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($this->db);
            if ($before === null) {
                $audit->recordInsert('staff_profile', 'staff_profiles', $userId, 'ملف موظف #' . $userId, $after, 'إنشاء رصيد إجازات موظف');
            } elseif ($before != $after) {
                $audit->recordUpdate('staff_profile', 'staff_profiles', $userId, 'رصيد إجازات موظف #' . $userId, $before, $after, 'تعديل رصيد إجازات موظف');
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function applyLeaveBalancePolicy(int $year, array $deductibleTypes, string $role = 'teacher', ?int $userId = null): int
    {
        $rows = $this->getAnnualLeaveBalanceRows($year, $deductibleTypes, $userId, $role);
        if (empty($rows)) {
            return 0;
        }

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $count = 0;
            foreach ($rows as $row) {
                $note = 'تم تطبيق سياسة الرصيد تلقائياً لسنة ' . $year . ' - ' . $row['policy_label'];
                $this->updateAnnualLeaveBalance((int)$row['user_id'], (float)$row['policy_balance'], $note);
                $count++;
            }
            if ($ownsTransaction) $this->db->commit();
            return $count;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function getRecentLeaves(array $filters = [], int $limit = 6): array
    {
        $limit = max(1, min($limit, 50));
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'l.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'l.status = ?';
            $params[] = (string)$filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'l.start_date >= ?';
            $params[] = (string)$filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'l.end_date <= ?';
            $params[] = (string)$filters['date_to'];
        }

        $sql = "SELECT l.id, l.user_id, l.leave_type, l.start_date, l.end_date, l.days_count, l.reason, l.status, l.notes, u.name AS staff_name
                FROM staff_leaves l
                JOIN users u ON u.id = l.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.start_date DESC, l.created_at DESC
                LIMIT " . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveLeave(array $data, int $actorId, ?int $id = null): int
    {
        $normalized = $this->normalizeLeavePayload($data, $actorId);
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $before = $id !== null ? $this->lockLeave($id) : null;
            if ($id === null) {
                $stmt = $this->db->prepare("INSERT INTO staff_leaves (user_id, leave_type, start_date, end_date, days_count, reason, status, approved_by, notes)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute(array_values($normalized));
                $id = (int)$this->db->lastInsertId();
            } else {
                $stmt = $this->db->prepare("UPDATE staff_leaves
                                            SET user_id = ?, leave_type = ?, start_date = ?, end_date = ?, days_count = ?, reason = ?, status = ?, approved_by = ?, notes = ?
                                            WHERE id = ?");
                $stmt->execute(array_merge(array_values($normalized), [$id]));
            }
            $after = $this->lockLeave($id);
            if (!$after) throw new RuntimeException('تعذر إعادة تحميل إجازة الموظف بعد الحفظ');
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($this->db);
            if ($before === null) {
                $audit->recordInsert('staff_leave', 'staff_leaves', $id, $this->leaveName($after), $after, 'إضافة إجازة موظف');
            } elseif ($before != $after) {
                $audit->recordUpdate('staff_leave', 'staff_leaves', $id, $this->leaveName($after), $before, $after, 'تعديل إجازة موظف');
            }
            if ($ownsTransaction) $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteLeave(int $id): bool
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $before = $this->lockLeave($id);
            if (!$before) {
                if ($ownsTransaction) $this->db->commit();
                return false;
            }
            $this->db->prepare('DELETE FROM staff_leaves WHERE id = ?')->execute([$id]);
            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordDelete(
                'staff_leave', 'staff_leaves', $id, $this->leaveName($before), $before, 'حذف إجازة موظف'
            );
            if ($ownsTransaction) $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function changeLeaveStatus(int $id, string $status, int $actorId, string $notes = ''): bool
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('حالة الإجازة غير صالحة');
        }

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $before = $this->lockLeave($id);
            if (!$before) {
                if ($ownsTransaction) $this->db->commit();
                return false;
            }
            $approvalUserId = $status === 'approved' ? $actorId : null;
            $this->db->prepare("UPDATE staff_leaves SET status = ?, approved_by = ?, notes = ? WHERE id = ?")
                ->execute([$status, $approvalUserId, trim($notes), $id]);
            $after = $this->lockLeave($id);
            if (!$after) throw new RuntimeException('تعذر إعادة تحميل إجازة الموظف بعد تغيير الحالة');
            if ($before != $after) {
                (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordUpdate(
                    'staff_leave', 'staff_leaves', $id, $this->leaveName($after), $before, $after, 'تغيير حالة إجازة موظف'
                );
            }
            if ($ownsTransaction) $this->db->commit();
            return $before != $after;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function lockLeave(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_leaves WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function leaveName(array $row): string
    {
        return 'إجازة موظف #' . (int)($row['id'] ?? 0);
    }

    public function saveDeductibleTypes(array $selectedDeductTypes, array $leaveTypes): void
    {
        $validDeductTypes = [];
        foreach ($selectedDeductTypes as $type) {
            if (isset($leaveTypes[$type])) {
                $validDeductTypes[] = $type;
            }
        }

        $this->saveAuditedSetting(
            'leave_deductible_types',
            implode(',', $validDeductTypes),
            'أنواع الإجازات التي تُخصم من الرصيد السنوي'
        );
    }

    private function saveAuditedSetting(string $key, string $value, string $description): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $beforeStmt = $this->db->prepare('SELECT * FROM settings WHERE setting_key = ? FOR UPDATE');
            $beforeStmt->execute([$key]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value, description)
                                        VALUES (?, ?, ?)
                                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)");
            $stmt->execute([$key, $value, $description]);
            $afterStmt = $this->db->prepare('SELECT * FROM settings WHERE setting_key = ?');
            $afterStmt->execute([$key]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new RuntimeException('تعذر إعادة تحميل إعداد الإجازات');
            $recordId = $after['id'] ?? $key;
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($this->db);
            if ($before === null) {
                $audit->recordInsert('setting', 'settings', $recordId, $key, $after, 'إضافة إعداد إجازات');
            } elseif ($before != $after) {
                $audit->recordUpdate('setting', 'settings', $recordId, $key, $before, $after, 'تعديل إعداد إجازات');
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function normalizeLeavePayload(array $data, int $actorId): array
    {
        $userId = (int)($data['user_id'] ?? 0);
        $type = trim((string)($data['leave_type'] ?? ''));
        $startDate = trim((string)($data['start_date'] ?? ''));
        $endDate = trim((string)($data['end_date'] ?? ''));
        $status = trim((string)($data['status'] ?? 'pending'));
        $reason = trim((string)($data['reason'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));

        if ($userId <= 0) {
            throw new InvalidArgumentException('يجب اختيار الموظف');
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('نوع الإجازة غير صالح');
        }
        if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
            throw new InvalidArgumentException('تواريخ الإجازة غير صالحة');
        }
        if (strcmp($startDate, $endDate) > 0) {
            throw new InvalidArgumentException('تاريخ البداية يجب أن يسبق أو يساوي تاريخ النهاية');
        }
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('حالة الإجازة غير صالحة');
        }

        $daysCount = $this->calculateDaysCount($startDate, $endDate);

        return [
            'user_id' => $userId,
            'leave_type' => $type,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_count' => $daysCount,
            'reason' => $reason,
            'status' => $status,
            'approved_by' => $status === 'approved' ? $actorId : null,
            'notes' => $notes,
        ];
    }

    private function calculateDaysCount(string $startDate, string $endDate): int
    {
        $dateStart = new DateTime($startDate);
        $dateEnd = new DateTime($endDate);
        return $dateStart->diff($dateEnd)->days + 1;
    }

    private function isValidDate(string $date): bool
    {
        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        return $dateTime && $dateTime->format('Y-m-d') === $date;
    }

    private function getDefaultLeaveBalancePolicy(): array
    {
        return [
            ['label' => 'أول 6 أشهر', 'months_from' => 0, 'months_to' => 6, 'balance' => 6],
            ['label' => 'من 7 إلى 12 شهر', 'months_from' => 7, 'months_to' => 12, 'balance' => 12],
            ['label' => 'من السنة الثانية إلى الثالثة', 'months_from' => 13, 'months_to' => 36, 'balance' => 21],
            ['label' => 'بعد ثلاث سنوات', 'months_from' => 37, 'months_to' => null, 'balance' => 30],
        ];
    }

    private function normalizeLeavePolicyTiers(array $tiers, bool $strict = false): array
    {
        $normalized = [];
        foreach ($tiers as $tier) {
            $label = trim((string)($tier['label'] ?? ''));
            $monthsFrom = isset($tier['months_from']) && $tier['months_from'] !== '' ? (int)$tier['months_from'] : null;
            $monthsTo = isset($tier['months_to']) && $tier['months_to'] !== '' ? (int)$tier['months_to'] : null;
            $balance = isset($tier['balance']) && $tier['balance'] !== '' ? (float)$tier['balance'] : null;

            if ($label === '' && $monthsFrom === null && $monthsTo === null && $balance === null) {
                continue;
            }
            if ($label === '') {
                $label = 'سياسة رصيد';
            }
            if ($monthsFrom === null) {
                $monthsFrom = 0;
            }
            if ($monthsFrom < 0) {
                $monthsFrom = 0;
            }
            if ($monthsTo !== null && $monthsTo < $monthsFrom) {
                if ($strict) {
                    throw new InvalidArgumentException('حد الأشهر الأعلى يجب أن يكون أكبر من أو يساوي الحد الأدنى');
                }
                continue;
            }
            if ($balance === null || $balance < 0) {
                if ($strict) {
                    throw new InvalidArgumentException('رصيد السياسة يجب أن يكون صفراً أو أكبر');
                }
                continue;
            }

            $normalized[] = [
                'label' => $label,
                'months_from' => $monthsFrom,
                'months_to' => $monthsTo,
                'balance' => $balance,
            ];
        }

        usort($normalized, static function ($left, $right) {
            return $left['months_from'] <=> $right['months_from'];
        });

        return $normalized;
    }

    private function resolveLeavePolicyForHireDate(?string $hireDate, string $yearEnd, array $policyTiers): array
    {
        if (empty($hireDate) || !$this->isValidDate($hireDate)) {
            $lastTier = end($policyTiers);
            return [
                'balance' => (float)($lastTier['balance'] ?? 30),
                'label' => 'بدون تاريخ تعيين - افتراضي',
                'service_months' => null,
            ];
        }

        $start = new DateTime($hireDate);
        $end = new DateTime($yearEnd);
        if ($start > $end) {
            $serviceMonths = 0;
        } else {
            $interval = $start->diff($end);
            $serviceMonths = ($interval->y * 12) + $interval->m + 1;
        }

        foreach ($policyTiers as $tier) {
            $from = (int)$tier['months_from'];
            $to = $tier['months_to'] !== null ? (int)$tier['months_to'] : null;
            if ($serviceMonths >= $from && ($to === null || $serviceMonths <= $to)) {
                return [
                    'balance' => (float)$tier['balance'],
                    'label' => (string)$tier['label'],
                    'service_months' => $serviceMonths,
                ];
            }
        }

        $lastTier = end($policyTiers);
        return [
            'balance' => (float)($lastTier['balance'] ?? 30),
            'label' => (string)($lastTier['label'] ?? 'سياسة افتراضية'),
            'service_months' => $serviceMonths,
        ];
    }
}
