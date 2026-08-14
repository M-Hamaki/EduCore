<?php

declare(strict_types=1);

namespace EduCore\Modules\Accounts;

use AccountListDataTableQuery;
use InvalidArgumentException;
use PDO;

require_once dirname(__DIR__, 3) . '/classes/AccountListDataTableQuery.php';

/**
 * Normalizes and executes bulk account selection.
 * Handles both explicit user-selected IDs and server-side re-evaluation of current DataTables filters.
 */
final class AccountBulkSelection
{
    public const MODE_SELECTED = 'selected';
    public const MODE_FILTERED = 'filtered';
    public const MAX_BATCH_SIZE = 1000;

    /**
     * @param string $mode 'selected' or 'filtered'
     * @param array<int,int> $ids List of user IDs if mode is 'selected'
     * @param array<string,mixed> $filters DataTables filters array if mode is 'filtered'
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $ids = [],
        public readonly array $filters = []
    ) {
        if ($this->mode !== self::MODE_SELECTED && $this->mode !== self::MODE_FILTERED) {
            throw new InvalidArgumentException('وضع التحديد الجماعي غير صالح.');
        }
    }

    /**
     * Parse raw request input.
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $mode = (string)($payload['selection_mode'] ?? self::MODE_SELECTED);
        if ($mode === self::MODE_FILTERED) {
            $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
            return new self(self::MODE_FILTERED, [], $filters);
        }

        $rawIds = is_array($payload['ids'] ?? null) ? $payload['ids'] : [];
        $ids = [];
        foreach ($rawIds as $id) {
            $val = (int)$id;
            if ($val > 0) {
                $ids[$val] = $val;
            }
        }
        $ids = array_values($ids);

        if ($ids === []) {
            throw new InvalidArgumentException('لم يتم تحديد أي حساب للتنفيذ عليه.');
        }

        if (count($ids) > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('عدد الحسابات المحددة يفيض عن الحد الأقصى المسموح به (' . self::MAX_BATCH_SIZE . ').');
        }

        return new self(self::MODE_SELECTED, $ids, []);
    }

    /**
     * Resolve actual target user IDs for student accounts.
     * @return array<int,int>
     */
    public function resolveStudentUserIds(PDO $db, int $academicYearId, int $excludeUserId = 0): array
    {
        if ($this->mode === self::MODE_SELECTED) {
            $ids = array_values(array_filter($this->ids, static fn (int $id): bool => $id !== $excludeUserId));
            if ($ids === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT id FROM users WHERE role = 'student' AND deleted_at IS NULL AND id IN ({$placeholders}) ORDER BY id ASC");
            $stmt->execute($ids);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        // Filtered mode: re-run the same public query contract used by DataTables.
        $queryHelper = new AccountListDataTableQuery($db);
        [$from, $where, $params] = $queryHelper->studentSelectionQueryParts($academicYearId, $this->filters);

        if ($excludeUserId > 0) {
            $where[] = 'u.id <> ?';
            $params[] = $excludeUserId;
        }

        $sql = "SELECT u.id FROM {$from} WHERE " . implode(' AND ', $where)
            . " ORDER BY u.id ASC LIMIT " . (self::MAX_BATCH_SIZE + 1);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($ids) > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException(
                'عدد النتائج المطابقة يتجاوز الحد الأقصى للعملية الواحدة (' . self::MAX_BATCH_SIZE . '). ضيّق الفلاتر ثم أعد المحاولة.'
            );
        }
        return $ids;
    }

    /**
     * Resolve actual target user IDs for staff accounts.
     * @param array<int,string> $validRoles
     * @return array<int,int>
     */
    public function resolveStaffUserIds(PDO $db, array $validRoles, int $excludeUserId = 0): array
    {
        if ($this->mode === self::MODE_SELECTED) {
            $ids = array_values(array_filter($this->ids, static fn (int $id): bool => $id !== $excludeUserId));
            if ($ids === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $roles = [];
            foreach ($validRoles as $key => $value) {
                $roleKey = is_int($key) ? (string)$value : (string)$key;
                if ($roleKey !== '') {
                    $roles[] = $roleKey;
                }
            }
            $roles = array_values(array_unique($roles));
            $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
            $employeeFallback = in_array('employee', $roles, true)
                ? " OR NOT EXISTS (
                    SELECT 1 FROM user_role_assignments uraany
                    WHERE uraany.user_id = u.id AND uraany.status = 'active'
                )"
                : '';
            $roleGuard = $roles === []
                ? '1 = 0'
                : "(EXISTS (
                    SELECT 1 FROM user_role_assignments urasel
                    WHERE urasel.user_id = u.id
                      AND urasel.status = 'active'
                      AND urasel.role_key IN ({$rolePlaceholders})
                ){$employeeFallback})";
            $stmt = $db->prepare(
                "SELECT u.id
                 FROM users u
                 INNER JOIN staff_profiles sp ON sp.user_id = u.id
                 WHERE u.deleted_at IS NULL
                   AND u.id IN ({$placeholders})
                   AND {$roleGuard}
                 ORDER BY u.id ASC"
            );
            $stmt->execute(array_merge($ids, $roles));
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        // Filtered mode: re-run the same public query contract used by DataTables.
        $queryHelper = new AccountListDataTableQuery($db);
        [$from, $where, $params] = $queryHelper->staffSelectionQueryParts($validRoles, $this->filters);

        if ($excludeUserId > 0) {
            $where[] = 'u.id <> ?';
            $params[] = $excludeUserId;
        }

        $sql = "SELECT u.id FROM {$from} WHERE " . implode(' AND ', $where)
            . " ORDER BY u.id ASC LIMIT " . (self::MAX_BATCH_SIZE + 1);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($ids) > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException(
                'عدد النتائج المطابقة يتجاوز الحد الأقصى للعملية الواحدة (' . self::MAX_BATCH_SIZE . '). ضيّق الفلاتر ثم أعد المحاولة.'
            );
        }
        return $ids;
    }
}
