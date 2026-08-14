<?php
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
/**
 * StaffPermissionService
 * طبقة خدمة لإدارة واستعلام أذونات الموظفين
 */
class StaffPermissionService
{
    private const ALLOWED_TYPES = ['early_leave', 'late_arrival', 'errand'];
    private const ALLOWED_STATUSES = ['pending', 'approved', 'rejected'];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
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

    public function getPermissionById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM staff_permissions WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getPermissions(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'p.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'p.permission_type = ?';
            $params[] = (string)$filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = (string)$filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'p.permission_date >= ?';
            $params[] = (string)$filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'p.permission_date <= ?';
            $params[] = (string)$filters['date_to'];
        }

        $sql = "SELECT p.*, u.name AS staff_name
                FROM staff_permissions p
                JOIN users u ON p.user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.permission_date DESC, p.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPermissionStats(): array
    {
        $typeStats = $this->db->query("SELECT permission_type, COUNT(*) AS cnt FROM staff_permissions GROUP BY permission_type")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $statusStats = $this->db->query("SELECT status, COUNT(*) AS cnt FROM staff_permissions GROUP BY status")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'type_stats' => $typeStats,
            'status_stats' => $statusStats,
            'total' => array_sum($typeStats)
        ];
    }

    public function getPendingPermissions(int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));
        $stmt = $this->db->query("SELECT p.id, p.permission_type, p.permission_date, p.time_from, p.time_to, p.reason, u.name AS staff_name
                                  FROM staff_permissions p
                                  JOIN users u ON u.id = p.user_id
                                  WHERE p.status = 'pending'
                                  ORDER BY p.permission_date DESC, p.created_at DESC
                                  LIMIT " . (int)$limit);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecentPermissions(array $filters = [], int $limit = 6): array
    {
        $limit = max(1, min($limit, 50));
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'p.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = (string)$filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'p.permission_date >= ?';
            $params[] = (string)$filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'p.permission_date <= ?';
            $params[] = (string)$filters['date_to'];
        }

        $sql = "SELECT p.id, p.user_id, p.permission_type, p.permission_date, p.time_from, p.time_to, p.reason, p.status, p.notes, u.name AS staff_name
                FROM staff_permissions p
                JOIN users u ON u.id = p.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.permission_date DESC, p.created_at DESC
                LIMIT " . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function savePermission(array $data, int $actorId, ?int $id = null): int
    {
        $normalized = $this->normalizePermissionPayload($data, $actorId);
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $before = null;
            if ($id !== null) {
                $before = $this->lockPermission($id);
            }

            if ($id === null) {
                $stmt = $this->db->prepare("INSERT INTO staff_permissions (user_id, permission_type, permission_date, time_from, time_to, reason, status, approved_by, notes)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute(array_values($normalized));
                $id = (int)$this->db->lastInsertId();
            } else {
                $stmt = $this->db->prepare("UPDATE staff_permissions
                                            SET user_id = ?, permission_type = ?, permission_date = ?, time_from = ?, time_to = ?, reason = ?, status = ?, approved_by = ?, notes = ?
                                            WHERE id = ?");
                $stmt->execute(array_merge(array_values($normalized), [$id]));
            }

            $after = $this->lockPermission($id);
            if (!$after) throw new RuntimeException('تعذر إعادة تحميل إذن الموظف بعد الحفظ');
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($this->db);
            if ($before === null) {
                $audit->recordInsert('staff_permission', 'staff_permissions', $id, $this->permissionName($after), $after, 'إضافة إذن موظف');
            } elseif ($before != $after) {
                $audit->recordUpdate('staff_permission', 'staff_permissions', $id, $this->permissionName($after), $before, $after, 'تعديل إذن موظف');
            }
            if ($ownsTransaction) $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function deletePermission(int $id): bool
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $before = $this->lockPermission($id);
            if (!$before) {
                if ($ownsTransaction) $this->db->commit();
                return false;
            }
            $this->db->prepare('DELETE FROM staff_permissions WHERE id = ?')->execute([$id]);
            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordDelete(
                'staff_permission', 'staff_permissions', $id, $this->permissionName($before), $before, 'حذف إذن موظف'
            );
            if ($ownsTransaction) $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function changePermissionStatus(int $id, string $status, int $actorId, string $notes = ''): bool
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('حالة الإذن غير صالحة');
        }

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $before = $this->lockPermission($id);
            if (!$before) {
                if ($ownsTransaction) $this->db->commit();
                return false;
            }
            $approvalUserId = $status === 'approved' ? $actorId : null;
            $this->db->prepare("UPDATE staff_permissions SET status = ?, approved_by = ?, notes = ? WHERE id = ?")
                ->execute([$status, $approvalUserId, trim($notes), $id]);
            $after = $this->lockPermission($id);
            if (!$after) throw new RuntimeException('تعذر إعادة تحميل إذن الموظف بعد تغيير الحالة');
            if ($before != $after) {
                (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordUpdate(
                    'staff_permission', 'staff_permissions', $id, $this->permissionName($after), $before, $after, 'تغيير حالة إذن موظف'
                );
            }
            if ($ownsTransaction) $this->db->commit();
            return $before != $after;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function lockPermission(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_permissions WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function permissionName(array $row): string
    {
        return 'إذن موظف #' . (int)($row['id'] ?? 0);
    }

    private function normalizePermissionPayload(array $data, int $actorId): array
    {
        $userId = (int)($data['user_id'] ?? 0);
        $type = trim((string)($data['permission_type'] ?? ''));
        $date = trim((string)($data['permission_date'] ?? ''));
        $timeFrom = trim((string)($data['time_from'] ?? ''));
        $timeTo = trim((string)($data['time_to'] ?? ''));
        $status = trim((string)($data['status'] ?? 'pending'));
        $reason = trim((string)($data['reason'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));

        if ($userId <= 0) {
            throw new InvalidArgumentException('يجب اختيار الموظف');
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('نوع الإذن غير صالح');
        }
        if (!$this->isValidDate($date)) {
            throw new InvalidArgumentException('تاريخ الإذن غير صالح');
        }
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('حالة الإذن غير صالحة');
        }

        $timeFrom = $timeFrom !== '' ? $timeFrom : null;
        $timeTo = $timeTo !== '' ? $timeTo : null;
        if ($timeFrom !== null && !$this->isValidTime($timeFrom)) {
            throw new InvalidArgumentException('وقت بداية الإذن غير صالح');
        }
        if ($timeTo !== null && !$this->isValidTime($timeTo)) {
            throw new InvalidArgumentException('وقت نهاية الإذن غير صالح');
        }
        if ($timeFrom !== null && $timeTo !== null && strcmp($timeFrom, $timeTo) > 0) {
            throw new InvalidArgumentException('وقت البداية يجب أن يسبق وقت النهاية');
        }

        return [
            'user_id' => $userId,
            'permission_type' => $type,
            'permission_date' => $date,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'reason' => $reason,
            'status' => $status,
            'approved_by' => $status === 'approved' ? $actorId : null,
            'notes' => $notes,
        ];
    }

    private function isValidDate(string $date): bool
    {
        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        return $dateTime && $dateTime->format('Y-m-d') === $date;
    }

    private function isValidTime(string $time): bool
    {
        $dateTime = DateTime::createFromFormat('H:i', $time);
        return $dateTime && $dateTime->format('H:i') === $time;
    }
}
