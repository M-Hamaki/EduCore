<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';

final class UserAuditSupport
{
    public function __construct(private PDO $db) {}

    public function fetchUserRow(int $id, bool $lock): array
    {
        return $this->fetchTableRow('users', $id, $lock);
    }

    public function fetchTableRow(string $table, int $id, bool $lock): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function fetchRowsForUser(string $table, string $column, int $userId, bool $lock): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE `{$column}` = ? ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function upgradePasswordHash(int $userId, string $replacementHash, string $source): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $upgrade = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            if (!$upgrade->execute([$replacementHash, $userId])) throw new RuntimeException('Credential hash upgrade failed.');
            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                'credential_upgrade', 'user_credential', $userId, 'بيانات دخول مستخدم #' . $userId,
                ['source' => $source, 'algorithm' => password_get_info($replacementHash)['algoName'] ?? 'unknown']
            );
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function auditDeletedRows(string $entityType, int $recordId, string $name, string $table, array $rows): void
    {
        if (!$rows) return;
        $deleted = array_map(static fn(array $row): array => [
            'table' => $table, 'record_id' => $row['id'], 'snapshot' => $row, 'description' => $name,
        ], $rows);
        (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordReplacement(
            $entityType, $recordId, $name, $deleted, [], ['summary' => $name, 'deleted_count' => count($rows)]
        );
    }

    public function auditRoleMigration(int $userId, array $beforeUser, array $afterUser, array $beforeSets, array $afterSets, string $oldRole, string $newRole): void
    {
        $beforeIndex = $this->indexRowsByTable($beforeSets);
        $afterIndex = $this->indexRowsByTable($afterSets);
        $deleted = $inserted = [];
        foreach ($beforeIndex as $key => $item) if (!isset($afterIndex[$key])) $deleted[] = $this->replacementItem($item);
        foreach ($afterIndex as $key => $item) if (!isset($beforeIndex[$key])) $inserted[] = $this->replacementItem($item);
        $batchId = UndoManager::newBatchId();
        $details = ['summary' => 'ترحيل دور مستخدم', 'old_role' => $oldRole, 'new_role' => $newRole];
        $name = (string) ($afterUser['name'] ?? ('User #' . $userId));
        $audit = new \EduCore\Modules\Operations\Audit\AuditService($this->db);
        if ($deleted || $inserted) $audit->recordReplacement('user_role_migration', $userId, $name, $deleted, $inserted, $details, $batchId);
        if ($beforeUser != $afterUser) $audit->recordCompositeUpdate('user_role_migration', $userId, $name, [[
            'table' => 'users', 'record_id' => $userId, 'before' => $beforeUser, 'after' => $afterUser, 'description' => 'تغيير دور مستخدم',
        ]], $details, $batchId);
    }

    private function replacementItem(array $item): array
    {
        return ['table' => $item['table'], 'record_id' => $item['row']['id'], 'snapshot' => $item['row'], 'description' => 'ترحيل دور مستخدم'];
    }

    private function indexRowsByTable(array $sets): array
    {
        $indexed = [];
        foreach ($sets as $table => $rows) foreach ($rows as $row) $indexed[$table . ':' . $row['id']] = ['table' => $table, 'row' => $row];
        return $indexed;
    }
}
