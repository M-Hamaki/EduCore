<?php
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditContext.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditPolicyRegistry.php';
require_once __DIR__ . '/ActivityLog.php';

use EduCore\Modules\Operations\Audit\AuditContext;
use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

/**
 * نظام التراجع عن التغييرات - UndoManager
 * يسجل جميع عمليات الإضافة والتعديل والحذف ويمكن التراجع عنها بـ CTRL+Z
 */
class UndoManager {

    private static $db = null;
    private static $tableCreated = false;

    // فترة كافية للتراجع التشغيلي مع حد يمنع تضخم السجل.
    private const RETENTION_HOURS = 720;
    // نافذة التراجع السريع التي تعرضها واجهة الإشعار.
    private const QUICK_UNDO_MINUTES = 5;
    // الحد الأقصى لعدد السجلات لكل مستخدم
    private const MAX_RECORDS_PER_USER = 500;

    private static function isAllowedTable($tableName) {
        return AuditPolicyRegistry::isRegisteredTable((string) $tableName);
    }

    public static function newBatchId(): string {
        return bin2hex(random_bytes(16));
    }

    public static function retentionHours(): int {
        return self::RETENTION_HOURS;
    }

    public static function quickUndoMinutes(): int {
        return self::QUICK_UNDO_MINUTES;
    }

    private static function queueUndoNotice(int $undoId, int $userId, int $canUndo): void {
        if ($undoId <= 0 || $userId <= 0 || $canUndo !== 1 || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if ((int) ($_SESSION['user_id'] ?? 0) !== $userId) {
            return;
        }
        $_SESSION['pending_undo_notice'] = [
            'id' => $undoId,
            'user_id' => $userId,
        ];
    }

    private static function normalizeBatchId($batchId): ?string {
        $batchId = strtolower(trim((string) $batchId));
        return preg_match('/^[a-f0-9]{32}$/', $batchId) ? $batchId : null;
    }

    /**
     * الحصول على اتصال قاعدة البيانات
     */
    private static function getDb() {
        if (self::$db === null) {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            self::$db = $database->getConnection();
        }
        return self::$db;
    }

    /**
     * تعيين اتصال قاعدة البيانات
     */
    public static function setDb($db) {
        self::$db = $db;
        self::$tableCreated = false;
        self::ensureTable();
    }

    /**
     * التأكد من وجود الجدول
     */
    private static function ensureTable() {
        if (self::$tableCreated) return;
        $db = self::getDb();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('undo_log', 'recycle_bin')");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() !== 2) {
                throw new RuntimeException('Database schema is not ready. Run pending migrations.');
            }
            self::$tableCreated = true;
            return;
        }
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('undo_log', 'recycle_bin')"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() !== 2) {
            throw new RuntimeException('Database schema is not ready. Run pending migrations.');
        }
        $columnStmt = $db->prepare(
            "SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'undo_log'
             AND COLUMN_NAME IN ('batch_id', 'request_id', 'can_undo', 'undone_by', 'undone_at', 'undo_status', 'failure_reason')"
        );
        $columnStmt->execute();
        if ((int) $columnStmt->fetchColumn() !== 7) {
            throw new RuntimeException('Database schema is not ready. Run pending migrations.');
        }
        self::$tableCreated = true;
    }

    /**
     * تنظيف السجلات القديمة
     */
    private static function cleanup($userId) {
        $db = self::getDb();
        // حذف السجلات الأقدم من 24 ساعة
        $db->exec("DELETE FROM undo_log WHERE created_at < DATE_SUB(NOW(), INTERVAL " . self::RETENTION_HOURS . " HOUR)");
        // الاحتفاظ بآخر 50 سجل فقط لكل مستخدم
        $stmt = $db->prepare("SELECT id FROM undo_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 1 OFFSET " . self::MAX_RECORDS_PER_USER);
        $stmt->execute([$userId]);
        $cutoffId = $stmt->fetchColumn();
        if ($cutoffId) {
            $db->prepare("DELETE FROM undo_log WHERE user_id = ? AND id <= ?")->execute([$userId, $cutoffId]);
        }
    }

    /**
     * تسجيل عملية إضافة (INSERT)
     * عند التراجع: يتم حذف السجل المضاف
     */
    public static function logInsert($tableName, $recordId, $newData = null, $description = '', $batchId = null) {
        try {
            self::ensureTable();
            $db = self::getDb();
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) return false;

            $pageUrl = $_SERVER['REQUEST_URI'] ?? '';
            if (!self::isAllowedTable($tableName)) return false;
            $snapshot = AuditPolicyRegistry::undoSnapshot(self::fetchRecord($tableName, $recordId) ?: (array) $newData, (string) $tableName);
            $newDataJson = $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;
            $canUndo = AuditPolicyRegistry::allowsDirectUndo($tableName, 'insert') ? 1 : 0;
            $blockReason = AuditPolicyRegistry::directUndoBlockReason($tableName, 'insert');

            $stmt = $db->prepare("INSERT INTO undo_log (user_id, action_type, table_name, record_id, new_data, description, page_url, batch_id, request_id, can_undo, failure_reason) VALUES (?, 'insert', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $tableName, $recordId, $newDataJson, $description, $pageUrl, self::normalizeBatchId($batchId), AuditContext::requestId(), $canUndo, $blockReason]);
            $undoId = (int) $db->lastInsertId();
            self::queueUndoNotice($undoId, (int) $userId, $canUndo);
            return $undoId;
        } catch (Exception $e) {
            error_log("UndoManager::logInsert error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تسجيل عملية تعديل (UPDATE)
     * عند التراجع: يتم استعادة البيانات القديمة
     */
    public static function logUpdate($tableName, $recordId, $oldData, $newData = null, $description = '', $batchId = null) {
        try {
            self::ensureTable();
            $db = self::getDb();
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) return false;
            if (!self::isAllowedTable($tableName)) return false;

            $pageUrl = $_SERVER['REQUEST_URI'] ?? '';
            $oldDataJson = json_encode(AuditPolicyRegistry::undoSnapshot((array) $oldData, (string) $tableName), JSON_UNESCAPED_UNICODE);
            $snapshot = AuditPolicyRegistry::undoSnapshot((array) ($newData ?: self::fetchRecord($tableName, $recordId)), (string) $tableName);
            $newDataJson = $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;
            $oldSnapshot = json_decode($oldDataJson, true) ?: [];
            $canUndo = AuditPolicyRegistry::allowsDirectUndo($tableName, 'update')
                && $oldSnapshot != $snapshot ? 1 : 0;
            $blockReason = $oldSnapshot == $snapshot
                ? 'no_reversible_field_changes'
                : AuditPolicyRegistry::directUndoBlockReason($tableName, 'update');

            $stmt = $db->prepare("INSERT INTO undo_log (user_id, action_type, table_name, record_id, old_data, new_data, description, page_url, batch_id, request_id, can_undo, failure_reason) VALUES (?, 'update', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $tableName, $recordId, $oldDataJson, $newDataJson, $description, $pageUrl, self::normalizeBatchId($batchId), AuditContext::requestId(), $canUndo, $blockReason]);
            $undoId = (int) $db->lastInsertId();
            self::queueUndoNotice($undoId, (int) $userId, $canUndo);
            return $undoId;
        } catch (Exception $e) {
            error_log("UndoManager::logUpdate error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تسجيل عملية حذف (DELETE)
     * عند التراجع: يتم إعادة إضافة السجل المحذوف
     */
    public static function logDelete($tableName, $recordId, $oldData, $description = '', $batchId = null) {
        try {
            self::ensureTable();
            $db = self::getDb();
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) return false;
            if (!self::isAllowedTable($tableName)) return false;

            $pageUrl = $_SERVER['REQUEST_URI'] ?? '';
            $oldDataJson = json_encode(AuditPolicyRegistry::undoSnapshot((array) $oldData, (string) $tableName), JSON_UNESCAPED_UNICODE);
            $canUndo = AuditPolicyRegistry::allowsDirectUndo($tableName, 'delete') ? 1 : 0;
            $blockReason = AuditPolicyRegistry::directUndoBlockReason($tableName, 'delete');

            $stmt = $db->prepare("INSERT INTO undo_log (user_id, action_type, table_name, record_id, old_data, description, page_url, batch_id, request_id, can_undo, failure_reason) VALUES (?, 'delete', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $tableName, $recordId, $oldDataJson, $description, $pageUrl, self::normalizeBatchId($batchId), AuditContext::requestId(), $canUndo, $blockReason]);
            $undoId = (int)$db->lastInsertId();
            if ($oldDataJson && $canUndo === 1) {
                $isSqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
                if ($isSqlite) {
                    $recycle = $db->prepare("INSERT OR IGNORE INTO recycle_bin
                        (undo_log_id, deleted_by, table_name, record_id, record_data, description, expires_at)
                        VALUES (?, ?, ?, ?, ?, ?, datetime('now', '+72 hours'))");
                } else {
                    $recycle = $db->prepare("INSERT IGNORE INTO recycle_bin
                        (undo_log_id, deleted_by, table_name, record_id, record_data, description, expires_at)
                        VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . self::RETENTION_HOURS . " HOUR))");
                }
                $recycle->execute([$undoId, $userId, $tableName, $recordId, $oldDataJson, $description]);
            }
            self::queueUndoNotice($undoId, (int) $userId, $canUndo);
            return $undoId;
        } catch (Exception $e) {
            error_log("UndoManager::logDelete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * الحصول على آخر عملية قابلة للتراجع
     */
    public static function getLastUndoable($userId) {
        try {
            self::ensureTable();
            $db = self::getDb();

            $stmt = $db->prepare("SELECT candidate.* FROM undo_log candidate
                WHERE candidate.user_id = ? AND candidate.can_undo = 1 AND candidate.is_undone = 0
                AND candidate.undo_status = 'pending'
                AND candidate.created_at > DATE_SUB(NOW(), INTERVAL " . self::RETENTION_HOURS . " HOUR)
                AND (candidate.batch_id IS NULL OR NOT EXISTS (
                    SELECT 1 FROM undo_log member
                    WHERE member.user_id = candidate.user_id AND member.batch_id = candidate.batch_id
                    AND (member.can_undo = 0 OR member.is_undone = 1 OR member.undo_status <> 'pending')
                ))
                ORDER BY candidate.id DESC LIMIT 1");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("UndoManager::getLastUndoable error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * الحصول على عملية بعينها لواجهة التراجع السريع فقط.
     */
    public static function getQuickUndoable($userId, $entryId) {
        try {
            self::ensureTable();
            $db = self::getDb();
            $minutes = self::QUICK_UNDO_MINUTES;
            $stmt = $db->prepare("SELECT candidate.*,
                    GREATEST(0, (? * 60) - TIMESTAMPDIFF(SECOND, candidate.created_at, NOW())) AS quick_undo_expires_in
                FROM undo_log candidate
                WHERE candidate.id = ? AND candidate.user_id = ?
                AND candidate.can_undo = 1 AND candidate.is_undone = 0
                AND candidate.undo_status = 'pending'
                AND candidate.created_at > DATE_SUB(NOW(), INTERVAL " . $minutes . " MINUTE)
                AND (candidate.batch_id IS NULL OR NOT EXISTS (
                    SELECT 1 FROM undo_log member
                    WHERE member.user_id = candidate.user_id AND member.batch_id = candidate.batch_id
                    AND (member.can_undo = 0 OR member.is_undone = 1 OR member.undo_status <> 'pending')
                ))
                LIMIT 1");
            $stmt->execute([$minutes, (int) $entryId, (int) $userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("UndoManager::getQuickUndoable error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * تنفيذ التراجع عن آخر عملية
     * @return array ['success' => bool, 'message' => string, 'description' => string]
     */
    public static function undo($userId, $entryId = null, $allowAnyUser = false, $maxAgeMinutes = null) {
        try {
            self::ensureTable();
            $db = self::getDb();

            $entries = [];
            if ($entryId !== null) {
                $sql = "SELECT * FROM undo_log WHERE id = ? AND can_undo = 1 AND is_undone = 0 AND undo_status = 'pending'";
                $params = [(int)$entryId];
                if (!$allowAnyUser) {
                    $sql .= ' AND user_id = ?';
                    $params[] = (int)$userId;
                }
                if ($maxAgeMinutes !== null) {
                    $maxAgeMinutes = max(1, (int) $maxAgeMinutes);
                    $sql .= " AND created_at > DATE_SUB(NOW(), INTERVAL {$maxAgeMinutes} MINUTE)";
                }
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $entry = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entry) {
                    $batchId = self::normalizeBatchId($entry['batch_id'] ?? null);
                    if ($batchId !== null) {
                        $batchSql = 'SELECT * FROM undo_log WHERE batch_id = ?';
                        $batchParams = [$batchId];
                        if (!$allowAnyUser) {
                            $batchSql .= ' AND user_id = ?';
                            $batchParams[] = (int) $userId;
                        }
                        $batchSql .= ' ORDER BY id DESC';
                        $batchStmt = $db->prepare($batchSql);
                        $batchStmt->execute($batchParams);
                        $entries = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $entries[] = $entry;
                    }
                }
            } else {
                $entry = self::getLastUndoable($userId);
                if ($entry) {
                    $batchId = self::normalizeBatchId($entry['batch_id'] ?? null);
                    if ($batchId !== null) {
                        $bulkStmt = $db->prepare(
                            "SELECT * FROM undo_log WHERE user_id = ? AND batch_id = ? ORDER BY id DESC"
                        );
                        $bulkStmt->execute([$userId, $batchId]);
                        $entries = $bulkStmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    if (empty($entries)) {
                        $entries[] = $entry;
                    }
                }
            }

            if (empty($entries)) {
                return ['success' => false, 'message' => 'لا توجد عمليات يمكن التراجع عنها'];
            }

            $db->beginTransaction();
            $entryIds = array_map(static fn(array $entry): int => (int) $entry['id'], $entries);
            $lockSql = 'SELECT * FROM undo_log WHERE id IN ('
                . implode(',', array_fill(0, count($entryIds), '?'))
                . ') ORDER BY id DESC FOR UPDATE';
            $lockStmt = $db->prepare($lockSql);
            $lockStmt->execute($entryIds);
            $entries = $lockStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($entries) !== count($entryIds)) {
                return self::rollbackResult($db, 'تعذر تثبيت جميع عناصر العملية للتراجع');
            }

            // 1. التحقق من صحة الجداول وخلوها من التعارض لجميع العمليات أولاً (الكل أو لا شيء)
            foreach ($entries as $ent) {
                $tableName = $ent['table_name'];
                if (!self::isAllowedTable($tableName) || (int) $ent['can_undo'] !== 1
                    || (int) $ent['is_undone'] !== 0 || $ent['undo_status'] !== 'pending') {
                    return self::rollbackResult($db, 'تحتوي العملية على عنصر غير قابل للتراجع الآمن');
                }

                $recordId = $ent['record_id'];
                $currentData = self::fetchRecord($tableName, $recordId, true);
                $newData = $ent['new_data'] ? json_decode($ent['new_data'], true) : null;
                $actionType = $ent['action_type'];

                if (!in_array($actionType, ['insert', 'update', 'delete'], true)) {
                    return self::rollbackResult($db, 'نوع عملية التراجع غير مدعوم');
                }
                if (in_array($actionType, ['insert', 'update'], true) && !$currentData) {
                    return self::rollbackResult(
                        $db,
                        'تعذر التراجع لأن السجل لم يعد موجودًا.',
                        ['conflict' => true]
                    );
                }
                if ($actionType === 'delete' && $currentData
                    && (!array_key_exists('deleted_at', $currentData) || empty($currentData['deleted_at']))) {
                    return self::rollbackResult(
                        $db,
                        'تعذر الاستعادة لأن معرف السجل مستخدم حاليًا.',
                        ['conflict' => true]
                    );
                }

                if (in_array($actionType, ['insert', 'update'], true) && $newData) {
                    foreach ($newData as $column => $expectedValue) {
                        if (!array_key_exists($column, $currentData)) continue;
                        if ((string)$currentData[$column] !== (string)$expectedValue) {
                            return self::rollbackResult(
                                $db,
                                'تعذر التراجع لأن السجل عُدّل بعد هذه العملية. راجع سجل الإصدارات أولاً.',
                                ['conflict' => true]
                            );
                        }
                    }
                }
            }

            foreach ($entries as $ent) {
                $actionType = $ent['action_type'];
                $tableName = $ent['table_name'];
                $recordId = $ent['record_id'];
                $oldData = $ent['old_data'] ? json_decode($ent['old_data'], true) : null;
                $currentData = self::fetchRecord($tableName, $recordId);

                switch ($actionType) {
                    case 'insert':
                        // نستخدم الحذف المؤقت عندما يدعمه الجدول.
                        $columnStmt = $db->prepare(
                            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                        );
                        $columnStmt->execute([$tableName]);
                        $columns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);
                        $sql = in_array('deleted_at', $columns, true)
                            ? "UPDATE `$tableName` SET deleted_at = NOW() WHERE id = ?"
                            : "DELETE FROM `$tableName` WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$recordId]);
                        if ($stmt->rowCount() !== 1) {
                            throw new RuntimeException('Inserted record could not be reversed.');
                        }
                        break;

                    case 'update':
                        if (!$oldData) {
                            return self::rollbackResult($db, 'لا توجد بيانات قديمة للاستعادة');
                        }

                        $setClauses = [];
                        $values = [];
                        foreach ($oldData as $col => $val) {
                            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) continue;
                            $setClauses[] = "`$col` = ?";
                            $values[] = $val;
                        }

                        if (empty($setClauses)) {
                            return self::rollbackResult($db, 'لا توجد بيانات للاستعادة');
                        }

                        $values[] = $recordId;
                        $sql = "UPDATE `$tableName` SET " . implode(', ', $setClauses) . " WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($values);
                        if ($stmt->rowCount() !== 1) {
                            throw new RuntimeException('Updated record could not be restored.');
                        }

                        // إذا كان التراجع عن التعديل في جدول المستخدمين (users) لعمود class_id وكان الدور طالباً، نقوم بمزامنة جداول التسجيلات والملفات تلقائياً
                        if ($tableName === 'users' && array_key_exists('class_id', $oldData) && ($oldData['role'] ?? '') === 'student') {
                            $oldClassId = $oldData['class_id'];
                            if ($oldClassId) {
                                $gradeStmt = $db->prepare("SELECT grade_id FROM classes WHERE id = ?");
                                $gradeStmt->execute([$oldClassId]);
                                $oldGradeId = $gradeStmt->fetchColumn();

                                require_once __DIR__ . '/AcademicYear.php';
                                $yearId = AcademicYear::currentId($db);
                                if ($yearId > 0) {
                                    $db->prepare("UPDATE student_enrollments SET class_id = ?, grade_id = COALESCE(?, grade_id) WHERE student_id = ? AND academic_year_id = ? AND enrollment_status = 'enrolled'")
                                       ->execute([$oldClassId, $oldGradeId ?: null, $recordId, $yearId]);
                                }

                                if ($oldGradeId) {
                                    $db->prepare("UPDATE student_profiles SET grade_id = ? WHERE user_id = ?")
                                       ->execute([$oldGradeId, $recordId]);
                                }
                            }
                        }
                        break;

                    case 'delete':
                        if (!$oldData) {
                            return self::rollbackResult($db, 'لا توجد بيانات لإعادة الإضافة');
                        }

                        if ($currentData && array_key_exists('deleted_at', $currentData)) {
                            $setClauses = [];
                            $values = [];
                            foreach ($oldData as $column => $value) {
                                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) continue;
                                $setClauses[] = "`$column` = ?";
                                $values[] = $value;
                            }
                            $values[] = $recordId;
                            $stmt = $db->prepare("UPDATE `$tableName` SET " . implode(', ', $setClauses) . " WHERE id = ?");
                            $stmt->execute($values);
                            if ($stmt->rowCount() !== 1) {
                                throw new RuntimeException('Soft-deleted record could not be restored.');
                            }
                            break;
                        }

                        $columns = [];
                        $placeholders = [];
                        $values = [];
                        foreach ($oldData as $col => $val) {
                            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) continue;
                            $columns[] = "`$col`";
                            $placeholders[] = '?';
                            $values[] = $val;
                        }

                        if (empty($columns)) {
                            return self::rollbackResult($db, 'لا توجد بيانات لإعادة الإضافة');
                        }

                        $sql = "INSERT INTO `$tableName` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($values);
                        break;
                }

                // تحديث حالة السجل في قاعدة بيانات التراجع
                $markStmt = $db->prepare("UPDATE undo_log SET is_undone = 1, undone_by = ?, undone_at = NOW(), undo_status = 'completed' WHERE id = ? AND is_undone = 0");
                $markStmt->execute([(int) $userId, $ent['id']]);
                if ($markStmt->rowCount() !== 1) {
                    throw new RuntimeException('Undo entry state changed concurrently.');
                }
                if ($actionType === 'delete') {
                    $db->prepare('UPDATE recycle_bin SET restored_at = NOW() WHERE undo_log_id = ?')->execute([$ent['id']]);
                }
            }

            ActivityLog::setDb($db);
            $auditLogged = ActivityLog::log('undo', $entries[0]['table_name'], $entries[0]['record_id'], $entries[0]['description'] ?? '', [
                'undo_entry_id' => (int) $entries[0]['id'],
                'batch_id' => $entries[0]['batch_id'] ?? null,
                'count' => count($entries),
            ], [
                'batch_id' => $entries[0]['batch_id'] ?? null,
                'undo_log_id' => (int) $entries[0]['id'],
            ]);
            if (!$auditLogged) {
                throw new RuntimeException('Undo audit event could not be stored.');
            }

            $db->commit();

            $actionLabels = [
                'insert' => 'الإضافة',
                'update' => 'التعديل',
                'delete' => 'الحذف'
            ];
            $actionLabel = $actionLabels[$entries[0]['action_type']] ?? $entries[0]['action_type'];

            $count = count($entries);
            $message = $count > 1
                ? "تم التراجع عن عمليات {$actionLabel} الجماعية بنجاح (عدد: {$count})"
                : "تم التراجع عن عملية {$actionLabel} بنجاح";

            return [
                'success' => true,
                'message' => $message,
                'description' => $entries[0]['description'] ?: '',
                'action_type' => $actionType
            ];

        } catch (Throwable $e) {
            try {
                if (isset($db) && is_object($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
                    $db->rollBack();
                }
            } catch (Throwable $rollbackError) {
                error_log("UndoManager::undo rollback error: " . $rollbackError->getMessage());
            }
            error_log("UndoManager::undo error: " . $e->getMessage());
            return ['success' => false, 'message' => 'تعذر إتمام عملية التراجع. يرجى المحاولة مرة أخرى.'];
        }
    }

    /**
     * إعادة تنفيذ عملية سبق التراجع عنها.
     *
     * تستخدم نفس لقطة before/after التي اعتمدها التراجع، وتعيد تنفيذ الدفعة
     * كاملة بالترتيب الأصلي أو تتوقف دون أي تعديل عند وجود تعارض.
     *
     * @return array ['success' => bool, 'message' => string, 'description' => string]
     */
    public static function redo($userId, $entryId, $allowAnyUser = false) {
        try {
            self::ensureTable();
            $db = self::getDb();
            $entryId = (int) $entryId;
            if ($entryId <= 0) {
                return ['success' => false, 'message' => 'لم يتم تحديد عملية صالحة لإعادة التنفيذ'];
            }

            $sql = "SELECT * FROM undo_log WHERE id = ? AND can_undo = 1 AND is_undone = 1 AND undo_status = 'completed'";
            $params = [$entryId];
            if (!$allowAnyUser) {
                $sql .= ' AND user_id = ?';
                $params[] = (int) $userId;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                return ['success' => false, 'message' => 'هذه العملية لم تعد متاحة لإعادة التنفيذ'];
            }

            $batchId = self::normalizeBatchId($entry['batch_id'] ?? null);
            if ($batchId !== null) {
                $batchSql = 'SELECT * FROM undo_log WHERE batch_id = ?';
                $batchParams = [$batchId];
                if (!$allowAnyUser) {
                    $batchSql .= ' AND user_id = ?';
                    $batchParams[] = (int) $userId;
                }
                $batchSql .= ' ORDER BY id ASC';
                $batchStmt = $db->prepare($batchSql);
                $batchStmt->execute($batchParams);
                $entries = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $entries = [$entry];
            }

            if (empty($entries)) {
                return ['success' => false, 'message' => 'لا توجد عمليات متاحة لإعادة التنفيذ'];
            }

            $db->beginTransaction();
            $entryIds = array_map(static fn(array $item): int => (int) $item['id'], $entries);
            $lockSql = 'SELECT * FROM undo_log WHERE id IN ('
                . implode(',', array_fill(0, count($entryIds), '?'))
                . ') ORDER BY id ASC FOR UPDATE';
            $lockStmt = $db->prepare($lockSql);
            $lockStmt->execute($entryIds);
            $entries = $lockStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($entries) !== count($entryIds)) {
                return self::rollbackResult($db, 'تعذر تثبيت جميع عناصر العملية لإعادة التنفيذ');
            }

            // التحقق من كل عناصر الدفعة قبل تنفيذ أي كتابة.
            foreach ($entries as $ent) {
                $tableName = (string) $ent['table_name'];
                $actionType = (string) $ent['action_type'];
                if (!self::isAllowedTable($tableName)
                    || !AuditPolicyRegistry::allowsDirectUndo($tableName, $actionType)
                    || (int) $ent['can_undo'] !== 1
                    || (int) $ent['is_undone'] !== 1
                    || $ent['undo_status'] !== 'completed') {
                    return self::rollbackResult($db, 'تحتوي العملية على عنصر غير مؤهل لإعادة التنفيذ الآمن');
                }
                if (!in_array($actionType, ['insert', 'update', 'delete'], true)) {
                    return self::rollbackResult($db, 'نوع العملية غير مدعوم لإعادة التنفيذ');
                }

                $recordId = $ent['record_id'];
                $currentData = self::fetchRecord($tableName, $recordId, true);
                $oldData = self::decodeSnapshot($ent['old_data'] ?? null);
                $newData = self::decodeSnapshot($ent['new_data'] ?? null);

                if ($actionType === 'insert') {
                    if (!$newData) {
                        return self::rollbackResult($db, 'لا توجد لقطة بيانات لإعادة الإضافة');
                    }
                    if ($currentData) {
                        if (!array_key_exists('deleted_at', $currentData) || empty($currentData['deleted_at'])) {
                            return self::rollbackResult(
                                $db,
                                'تعذر إعادة الإضافة لأن معرف السجل مستخدم حاليًا.',
                                ['conflict' => true]
                            );
                        }
                        if (!self::snapshotMatches($currentData, $newData, ['deleted_at'])) {
                            return self::rollbackResult(
                                $db,
                                'تعذر إعادة الإضافة لأن السجل المؤرشف تغيّر بعد التراجع.',
                                ['conflict' => true]
                            );
                        }
                    }
                } elseif ($actionType === 'update') {
                    if (!$oldData || !$newData || !$currentData) {
                        return self::rollbackResult(
                            $db,
                            'تعذر إعادة التعديل لأن السجل أو إحدى لقطات البيانات غير متاحة.',
                            ['conflict' => true]
                        );
                    }
                    if (!self::snapshotMatches($currentData, $oldData)) {
                        return self::rollbackResult(
                            $db,
                            'تعذر إعادة التعديل لأن السجل تغيّر بعد التراجع.',
                            ['conflict' => true]
                        );
                    }
                } else {
                    if (!$oldData || !$currentData) {
                        return self::rollbackResult(
                            $db,
                            'تعذر إعادة الحذف لأن السجل لم يعد موجودًا.',
                            ['conflict' => true]
                        );
                    }
                    if (!self::snapshotMatches($currentData, $oldData)) {
                        return self::rollbackResult(
                            $db,
                            'تعذر إعادة الحذف لأن السجل تغيّر بعد استعادته.',
                            ['conflict' => true]
                        );
                    }
                }
            }

            foreach ($entries as $ent) {
                $actionType = (string) $ent['action_type'];
                $tableName = (string) $ent['table_name'];
                $recordId = $ent['record_id'];
                $oldData = self::decodeSnapshot($ent['old_data'] ?? null);
                $newData = self::decodeSnapshot($ent['new_data'] ?? null);
                $currentData = self::fetchRecord($tableName, $recordId);

                if ($actionType === 'insert') {
                    if ($currentData && array_key_exists('deleted_at', $currentData)) {
                        $restoredInsertData = $newData;
                        if (!array_key_exists('deleted_at', $restoredInsertData)) {
                            $restoredInsertData['deleted_at'] = null;
                        }
                        self::updateRecord($tableName, $recordId, $restoredInsertData);
                    } else {
                        self::insertRecord($tableName, $newData);
                    }
                } elseif ($actionType === 'update') {
                    self::updateRecord($tableName, $recordId, $newData);
                    self::syncStudentClassReferences($tableName, $recordId, $newData);
                } else {
                    if (self::tableHasColumn($tableName, 'deleted_at')) {
                        $deleteStmt = $db->prepare("UPDATE `$tableName` SET deleted_at = NOW() WHERE id = ?");
                    } else {
                        $deleteStmt = $db->prepare("DELETE FROM `$tableName` WHERE id = ?");
                    }
                    $deleteStmt->execute([$recordId]);
                    if ($deleteStmt->rowCount() !== 1) {
                        throw new RuntimeException('Restored record could not be deleted again.');
                    }
                    $db->prepare('UPDATE recycle_bin SET restored_at = NULL WHERE undo_log_id = ?')->execute([$ent['id']]);
                }

                $markStmt = $db->prepare(
                    "UPDATE undo_log SET is_undone = 0, undo_status = 'pending', failure_reason = NULL
                     WHERE id = ? AND can_undo = 1 AND is_undone = 1 AND undo_status = 'completed'"
                );
                $markStmt->execute([(int) $ent['id']]);
                if ($markStmt->rowCount() !== 1) {
                    throw new RuntimeException('Redo entry state changed concurrently.');
                }
            }

            ActivityLog::setDb($db);
            $auditLogged = ActivityLog::log('redo', $entries[0]['table_name'], $entries[0]['record_id'], $entries[0]['description'] ?? '', [
                'undo_entry_id' => (int) $entries[0]['id'],
                'batch_id' => $entries[0]['batch_id'] ?? null,
                'count' => count($entries),
            ], [
                'batch_id' => $entries[0]['batch_id'] ?? null,
                'undo_log_id' => (int) $entries[0]['id'],
            ]);
            if (!$auditLogged) {
                throw new RuntimeException('Redo audit event could not be stored.');
            }

            $db->commit();

            $actionLabels = [
                'insert' => 'الإضافة',
                'update' => 'التعديل',
                'delete' => 'الحذف',
            ];
            $actionLabel = $actionLabels[$entries[0]['action_type']] ?? $entries[0]['action_type'];
            $count = count($entries);
            $message = $count > 1
                ? "تمت إعادة تنفيذ عمليات {$actionLabel} الجماعية بنجاح (عدد: {$count})"
                : "تمت إعادة تنفيذ عملية {$actionLabel} بنجاح";

            return [
                'success' => true,
                'message' => $message,
                'description' => $entries[0]['description'] ?: '',
                'action_type' => $entries[0]['action_type'],
            ];
        } catch (Throwable $e) {
            try {
                if (isset($db) && is_object($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
                    $db->rollBack();
                }
            } catch (Throwable $rollbackError) {
                error_log('UndoManager::redo rollback error: ' . $rollbackError->getMessage());
            }
            error_log('UndoManager::redo error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'تعذر إتمام إعادة تنفيذ العملية. يرجى المحاولة مرة أخرى.'];
        }
    }

    /** @return array<string,mixed>|null */
    private static function decodeSnapshot($snapshot): ?array
    {
        if (!is_string($snapshot) || $snapshot === '') {
            return null;
        }
        $decoded = json_decode($snapshot, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<int,string> $ignoredColumns */
    private static function snapshotMatches(array $currentData, array $expectedData, array $ignoredColumns = []): bool
    {
        foreach ($expectedData as $column => $expectedValue) {
            if (in_array($column, $ignoredColumns, true) || !array_key_exists($column, $currentData)) {
                continue;
            }
            if ((string) $currentData[$column] !== (string) $expectedValue) {
                return false;
            }
        }
        return true;
    }

    private static function tableHasColumn(string $tableName, string $columnName): bool
    {
        $db = self::getDb();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $db->query("PRAGMA table_info(`$tableName`)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if (($column['name'] ?? null) === $columnName) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$tableName, $columnName]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private static function updateRecord(string $tableName, $recordId, array $data): void
    {
        $setClauses = [];
        $values = [];
        foreach ($data as $column => $value) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $column)) {
                continue;
            }
            $setClauses[] = "`$column` = ?";
            $values[] = $value;
        }
        if (!$setClauses) {
            throw new RuntimeException('No safe snapshot fields are available for redo.');
        }
        $values[] = $recordId;
        $stmt = self::getDb()->prepare("UPDATE `$tableName` SET " . implode(', ', $setClauses) . ' WHERE id = ?');
        $stmt->execute($values);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Record could not be reapplied.');
        }
    }

    private static function insertRecord(string $tableName, array $data): void
    {
        $columns = [];
        $placeholders = [];
        $values = [];
        foreach ($data as $column => $value) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $column)) {
                continue;
            }
            $columns[] = "`$column`";
            $placeholders[] = '?';
            $values[] = $value;
        }
        if (!$columns) {
            throw new RuntimeException('No safe snapshot fields are available for redo.');
        }
        $stmt = self::getDb()->prepare(
            "INSERT INTO `$tableName` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($values);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Record could not be inserted again.');
        }
    }

    private static function syncStudentClassReferences(string $tableName, $recordId, array $snapshot): void
    {
        if ($tableName !== 'users' || !array_key_exists('class_id', $snapshot) || ($snapshot['role'] ?? '') !== 'student') {
            return;
        }
        $classId = $snapshot['class_id'];
        if (!$classId) {
            return;
        }
        $db = self::getDb();
        $gradeStmt = $db->prepare('SELECT grade_id FROM classes WHERE id = ?');
        $gradeStmt->execute([$classId]);
        $gradeId = $gradeStmt->fetchColumn();

        require_once __DIR__ . '/AcademicYear.php';
        $yearId = AcademicYear::currentId($db);
        if ($yearId > 0) {
            $db->prepare("UPDATE student_enrollments SET class_id = ?, grade_id = COALESCE(?, grade_id) WHERE student_id = ? AND academic_year_id = ? AND enrollment_status = 'enrolled'")
                ->execute([$classId, $gradeId ?: null, $recordId, $yearId]);
        }
        if ($gradeId) {
            $db->prepare('UPDATE student_profiles SET grade_id = ? WHERE user_id = ?')
                ->execute([$gradeId, $recordId]);
        }
    }

    /**
     * دالة مساعدة: جلب بيانات سجل قبل التعديل/الحذف
     */
    public static function fetchRecord($tableName, $recordId, bool $forUpdate = false) {
        try {
            if (!self::isAllowedTable($tableName)) return null;
            $db = self::getDb();
            $lock = $forUpdate ? ' FOR UPDATE' : '';
            $stmt = $db->prepare("SELECT * FROM `$tableName` WHERE id = ?" . $lock);
            $stmt->execute([$recordId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("UndoManager::fetchRecord error: " . $e->getMessage());
            return null;
        }
    }

    private static function rollbackResult($db, string $message, array $extra = []): array
    {
        if (is_object($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
            $db->rollBack();
        }

        return array_merge(['success' => false, 'message' => $message], $extra);
    }
}
