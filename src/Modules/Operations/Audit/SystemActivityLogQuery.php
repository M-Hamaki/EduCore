<?php

declare(strict_types=1);

namespace EduCore\Modules\Operations\Audit;

use PDO;

/**
 * Read model for the undo state shown by the unified system activity log.
 *
 * The activity row remains the public anchor.  An undo request is accepted
 * only when that exact row links to the exact pending undo entry supplied by
 * the caller; UndoManager remains the sole write owner for the reversal.
 */
final class SystemActivityLogQuery
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Attach undo metadata to activity rows without changing ActivityLog's
     * existing query contract or creating one query per table row.
     *
     * @param array<int,array<string,mixed>> $logs
     * @return array<int,array<string,mixed>>
     */
    public function enrich(array $logs): array
    {
        $undoIds = [];
        foreach ($logs as $log) {
            $undoId = (int) ($log['undo_log_id'] ?? 0);
            if ($undoId > 0) {
                $undoIds[$undoId] = $undoId;
            }
        }

        $undoRows = [];
        if ($undoIds !== []) {
            $placeholders = implode(',', array_fill(0, count($undoIds), '?'));
            $stmt = $this->db->prepare(
                'SELECT id AS undo_id, user_id AS undo_user_id, action_type AS undo_action_type,
                        table_name AS undo_table_name, description AS undo_description,
                        batch_id AS undo_batch_id, can_undo, is_undone, undo_status,
                        failure_reason AS undo_failure_reason, undone_by, undone_at
                   FROM undo_log
                  WHERE id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_values($undoIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $undoRow) {
                $undoRows[(int) $undoRow['undo_id']] = $undoRow;
            }
        }

        $defaults = [
            'undo_id' => null,
            'undo_user_id' => null,
            'undo_action_type' => null,
            'undo_table_name' => null,
            'undo_description' => null,
            'undo_batch_id' => null,
            'can_undo' => 0,
            'is_undone' => 0,
            'undo_status' => null,
            'undo_failure_reason' => null,
            'undone_by' => null,
            'undone_at' => null,
        ];

        foreach ($logs as &$log) {
            $undoId = (int) ($log['undo_log_id'] ?? 0);
            $isAuditTransition = in_array((string) ($log['action'] ?? ''), ['undo', 'redo'], true);
            $log = array_merge($log, $defaults, $isAuditTransition ? [] : ($undoRows[$undoId] ?? []));
        }
        unset($log);

        return $logs;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int,undone_total:int} */
    public function load(array $filters, string $tab, int $limit, int $offset): array
    {
        $tab = $tab === 'undone' ? 'undone' : 'active';
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        [$whereSql, $params] = $this->buildWhere($filters, $tab);

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM activity_logs al LEFT JOIN undo_log ul ON ul.id = al.undo_log_id WHERE ' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT al.*,
                    ul.id AS undo_id, ul.user_id AS undo_user_id,
                    ul.action_type AS undo_action_type, ul.table_name AS undo_table_name,
                    ul.description AS undo_description, ul.batch_id AS undo_batch_id,
                    ul.can_undo, ul.is_undone, ul.undo_status,
                    ul.failure_reason AS undo_failure_reason, ul.undone_by, ul.undone_at
               FROM activity_logs al
               LEFT JOIN undo_log ul ON ul.id = al.undo_log_id
              WHERE ' . $whereSql . '
              ORDER BY al.created_at DESC, al.id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        [$undoneWhere, $undoneParams] = $this->buildWhere($filters, 'undone');
        $undoneStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM activity_logs al LEFT JOIN undo_log ul ON ul.id = al.undo_log_id WHERE ' . $undoneWhere
        );
        $undoneStmt->execute($undoneParams);

        return [
            'rows' => $rows,
            'total' => $total,
            'undone_total' => (int) $undoneStmt->fetchColumn(),
        ];
    }

    /** @return array<string,mixed>|null */
    public function findUndoableOperation(int $activityId, int $undoId): ?array
    {
        if ($activityId <= 0 || $undoId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT al.id AS activity_id, al.action, al.target_type, al.target_name,
                    ul.*
               FROM activity_logs al
               INNER JOIN undo_log ul ON ul.id = al.undo_log_id
              WHERE al.id = ?
                AND ul.id = ?
                AND COALESCE(al.action, '') NOT IN ('undo', 'redo')
                AND ul.can_undo = 1
                AND ul.is_undone = 0
                AND ul.undo_status = 'pending'
              LIMIT 1"
        );
        $stmt->execute([$activityId, $undoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findRedoableOperation(int $activityId, int $undoId): ?array
    {
        if ($activityId <= 0 || $undoId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT al.id AS activity_id, al.action, al.target_type, al.target_name,
                    ul.*
               FROM activity_logs al
               INNER JOIN undo_log ul ON ul.id = al.undo_log_id
              WHERE al.id = ?
                AND ul.id = ?
                AND COALESCE(al.action, '') NOT IN ('undo', 'redo')
                AND ul.can_undo = 1
                AND ul.is_undone = 1
                AND ul.undo_status = 'completed'
              LIMIT 1"
        );
        $stmt->execute([$activityId, $undoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function undoState(array $row): string
    {
        if (in_array((string) ($row['action'] ?? ''), ['undo', 'redo'], true)) {
            return 'unavailable';
        }
        if (empty($row['undo_id'])) {
            return 'unavailable';
        }
        if ((int) ($row['is_undone'] ?? 0) === 1 || ($row['undo_status'] ?? '') === 'completed') {
            return 'completed';
        }
        if ((int) ($row['can_undo'] ?? 0) === 1 && ($row['undo_status'] ?? '') === 'pending') {
            return 'available';
        }

        return 'unavailable';
    }

    public static function redoReason(array $row, bool $canManageSystemUndo): string
    {
        if (self::undoState($row) !== 'completed') {
            return 'هذه العملية ليست في حالة تسمح بإعادة التنفيذ';
        }
        return $canManageSystemUndo
            ? 'يمكن إعادة تنفيذ العملية بعد التحقق من عدم وجود تعارض'
            : 'تتطلب إعادة التنفيذ الشامل صلاحية المدير العام';
    }

    public static function undoReason(array $row, bool $canManageSystemUndo): string
    {
        $state = self::undoState($row);
        if ($state === 'available') {
            return $canManageSystemUndo
                ? 'يمكن التراجع عن هذه العملية بأمان'
                : 'يتطلب التراجع الشامل صلاحية المدير العام';
        }
        if ($state === 'completed') {
            return 'تم التراجع عن هذه العملية بالفعل';
        }
        if (empty($row['undo_id'])) {
            return 'لم تُربط العملية بلقطة استعادة آمنة';
        }

        return 'سياسة سلامة البيانات لا تسمح بالتراجع المباشر عن هذه العملية';
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function buildWhere(array $filters, string $tab): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['action'])) {
            $where[] = 'al.action = ?';
            $params[] = (string) $filters['action'];
        }
        if (!empty($filters['target_type'])) {
            $where[] = 'al.target_type = ?';
            $params[] = (string) $filters['target_type'];
        }
        if (!empty($filters['target_type_prefix'])) {
            $prefix = strtolower(trim((string) $filters['target_type_prefix']));
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $prefix) !== 1) {
                // A presentation filter must fail closed, never become a
                // broad audit search through an unvalidated prefix.
                $where[] = '1 = 0';
            } else {
                $where[] = 'al.target_type LIKE ?';
                $params[] = $prefix . '%';
            }
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= ?';
            $params[] = (string) $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= ?';
            $params[] = (string) $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            if (!empty($filters['operational_search'])) {
                $where[] = '(al.user_name LIKE ? OR al.action LIKE ? OR al.target_type LIKE ?)';
            } else {
                $where[] = '(al.user_name LIKE ? OR al.target_name LIKE ? OR al.details LIKE ?)';
            }
            $search = '%' . (string) $filters['search'] . '%';
            array_push($params, $search, $search, $search);
        }

        if ($tab === 'undone') {
            $where[] = "COALESCE(al.action, '') NOT IN ('undo', 'redo')";
            $where[] = "ul.id IS NOT NULL AND (ul.is_undone = 1 OR ul.undo_status = 'completed')";
        } else {
            $where[] = "(COALESCE(al.action, '') IN ('undo', 'redo') OR ul.id IS NULL OR NOT (ul.is_undone = 1 OR ul.undo_status = 'completed'))";
        }

        return [implode(' AND ', $where), $params];
    }
}
