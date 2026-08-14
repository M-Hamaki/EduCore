<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';

final class EvaluationBackupService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function resetAll(int $actorId, string $actorName): array
    {
        $this->db->beginTransaction();
        try {
            $stats = $this->currentStats();
            $backupKey = $this->createSnapshot('backup', $actorId, $actorName);
            $this->db->exec('DELETE FROM evaluations');
            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                'reset', 'evaluation_dataset', null, 'تصفير التقييمات',
                [
                    'backup_key' => $backupKey,
                    'before' => $stats,
                    'after_count' => 0,
                    'undo_policy' => 'restore_from_evaluation_backup',
                ]
            );
            $this->db->commit();
            return $stats + ['backup_key' => $backupKey];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function restore(string $backupKey, int $actorId, string $actorName): array
    {
        $snapshot = $this->findSnapshot($backupKey);
        if (!$snapshot) {
            throw new RuntimeException('جدول النسخة الاحتياطية غير موجود');
        }

        $this->db->beginTransaction();
        try {
            $before = (int)$this->db->query('SELECT COUNT(*) FROM evaluations')->fetchColumn();
            $preRestoreKey = $this->createSnapshot('pre_restore', $actorId, $actorName);
            $this->db->exec('DELETE FROM evaluations');
            $this->restoreRows((int)$snapshot['id']);
            $after = (int)$this->db->query('SELECT COUNT(*) FROM evaluations')->fetchColumn();
            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                'restore', 'evaluation_dataset', (int)$snapshot['id'], $backupKey,
                [
                    'source_backup_key' => $backupKey,
                    'pre_restore_backup_key' => $preRestoreKey,
                    'before_count' => $before,
                    'after_count' => $after,
                    'undo_policy' => 'restore_from_pre_restore_backup',
                ]
            );
            $this->db->commit();
            return ['before' => $before, 'after' => $after, 'pre_restore_key' => $preRestoreKey];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function delete(string $backupKey): void
    {
        $this->assertBackupKey($backupKey);
        $this->db->beginTransaction();
        try {
            $snapshot = $this->findSnapshot($backupKey);
            if (!$snapshot) {
                throw new RuntimeException('جدول النسخة الاحتياطية غير موجود');
            }
            $stmt = $this->db->prepare('DELETE FROM evaluation_backup_snapshots WHERE backup_key = ?');
            $stmt->execute([$backupKey]);
            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                'delete', 'evaluation_backup', (int)$snapshot['id'], $backupKey,
                [
                    'snapshot' => $snapshot,
                    'undo_policy' => 'deleted_backup_not_restorable',
                ]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function all(): array
    {
        $stmt = $this->db->query(
            "SELECT backup_key AS table_name, created_at AS date, record_count AS total_evaluations,
                    student_count AS total_students, snapshot_type
             FROM evaluation_backup_snapshots ORDER BY created_at DESC, id DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_pre_restore'] = $row['snapshot_type'] === 'pre_restore';
        }
        unset($row);
        return $rows;
    }

    private function createSnapshot(string $type, int $actorId, string $actorName): string
    {
        if (!in_array($type, ['backup', 'pre_restore'], true)) {
            throw new InvalidArgumentException('نوع النسخة الاحتياطية غير صالح');
        }

        $key = $this->nextBackupKey($type);
        $stats = $this->currentStats();
        $stmt = $this->db->prepare(
            'INSERT INTO evaluation_backup_snapshots
             (backup_key, snapshot_type, record_count, student_count, created_by, created_by_name)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $key,
            $type,
            (int)$stats['total_evaluations'],
            (int)$stats['affected_students'],
            $actorId,
            $actorName,
        ]);
        $snapshotId = (int)$this->db->lastInsertId();

        $rows = $this->db->query('SELECT * FROM evaluations WHERE id > 0 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $rowStmt = $this->db->prepare(
            'INSERT INTO evaluation_backup_rows (snapshot_id, evaluation_id, student_id, row_data) VALUES (?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $rowStmt->execute([$snapshotId, (int)$row['id'], (int)($row['student_id'] ?? 0), $payload]);
        }
        return $key;
    }

    private function restoreRows(int $snapshotId): void
    {
        $columnStmt = $this->db->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluations' ORDER BY ORDINAL_POSITION"
        );
        $allowedColumns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);
        $rowsStmt = $this->db->prepare(
            'SELECT row_data FROM evaluation_backup_rows WHERE snapshot_id = ? ORDER BY evaluation_id'
        );
        $rowsStmt->execute([$snapshotId]);
        $prepared = [];
        while (($payload = $rowsStmt->fetchColumn()) !== false) {
            $row = json_decode((string)$payload, true, 512, JSON_THROW_ON_ERROR);
            $columns = array_values(array_intersect($allowedColumns, array_keys($row)));
            if (!$columns) {
                continue;
            }
            $signature = implode(',', $columns);
            if (!isset($prepared[$signature])) {
                $quoted = array_map(static fn (string $column): string => "`$column`", $columns);
                $prepared[$signature] = $this->db->prepare(
                    'INSERT INTO evaluations (' . implode(', ', $quoted) . ') VALUES ('
                    . implode(', ', array_fill(0, count($columns), '?')) . ')'
                );
            }
            $prepared[$signature]->execute(array_map(static fn (string $column) => $row[$column], $columns));
        }
    }

    private function currentStats(): array
    {
        $stmt = $this->db->query(
            'SELECT COUNT(*) AS total_evaluations, COUNT(DISTINCT student_id) AS affected_students,
                    COUNT(DISTINCT teacher_id) AS teachers_involved, COUNT(DISTINCT class_id) AS classes_involved,
                    MIN(date_created) AS oldest_evaluation, MAX(date_created) AS newest_evaluation
             FROM evaluations'
        );
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function findSnapshot(string $backupKey): ?array
    {
        $this->assertBackupKey($backupKey);
        $stmt = $this->db->prepare('SELECT * FROM evaluation_backup_snapshots WHERE backup_key = ?');
        $stmt->execute([$backupKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function nextBackupKey(string $type): string
    {
        $prefix = $type === 'pre_restore' ? 'evaluations_pre_restore_' : 'evaluations_backup_';
        for ($offset = 0; $offset < 60; $offset++) {
            $key = $prefix . date('Y_m_d_H_i_s', time() + $offset);
            $stmt = $this->db->prepare('SELECT 1 FROM evaluation_backup_snapshots WHERE backup_key = ?');
            $stmt->execute([$key]);
            if (!$stmt->fetchColumn()) {
                return $key;
            }
        }
        throw new RuntimeException('تعذر إنشاء اسم فريد للنسخة الاحتياطية');
    }

    private function assertBackupKey(string $backupKey): void
    {
        if (!preg_match('/^evaluations_(backup|pre_restore)_\d{4}_\d{2}_\d{2}_\d{2}_\d{2}_\d{2}$/', $backupKey)) {
            throw new InvalidArgumentException('اسم جدول النسخة الاحتياطية غير صالح');
        }
    }
}
