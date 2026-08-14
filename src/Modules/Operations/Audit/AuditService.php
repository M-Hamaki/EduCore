<?php

declare(strict_types=1);

namespace EduCore\Modules\Operations\Audit;

use PDO;
use RuntimeException;

require_once dirname(__DIR__, 4) . '/classes/ActivityLog.php';
require_once dirname(__DIR__, 4) . '/classes/UndoManager.php';
require_once __DIR__ . '/AuditEventWriter.php';

final class AuditService implements AuditEventWriter
{
    private PDO $db;
    private bool $undoReady = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        \ActivityLog::setDb($db);
    }

    public function recordInsert(
        string $entityType,
        string $table,
        $recordId,
        string $name,
        array $after,
        string $description,
        ?string $batchId = null,
        array $details = []
    ): int {
        $this->ensureUndoReady();
        $undoId = \UndoManager::logInsert($table, $recordId, $after, $description, $batchId);
        return $this->recordUndoableEvent('create', $entityType, $recordId, $name, [], $after, $undoId, $batchId, $details);
    }

    public function recordUpdate(
        string $entityType,
        string $table,
        $recordId,
        string $name,
        array $before,
        array $after,
        string $description,
        ?string $batchId = null
    ): int {
        $this->ensureUndoReady();
        $undoId = \UndoManager::logUpdate($table, $recordId, $before, $after, $description, $batchId);
        return $this->recordUndoableEvent('update', $entityType, $recordId, $name, $before, $after, $undoId, $batchId);
    }

    public function recordDelete(
        string $entityType,
        string $table,
        $recordId,
        string $name,
        array $before,
        string $description,
        ?string $batchId = null
    ): int {
        $this->ensureUndoReady();
        $undoId = \UndoManager::logDelete($table, $recordId, $before, $description, $batchId);
        return $this->recordUndoableEvent('delete', $entityType, $recordId, $name, $before, [], $undoId, $batchId);
    }

    public function recordEvent(
        string $action,
        ?string $entityType,
        $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if (!\ActivityLog::log($action, $entityType, $recordId, $name, $details, $context)) {
            throw new RuntimeException('Audit event could not be stored.');
        }
    }

    public function recordCompositeUpdate(
        string $entityType,
        $recordId,
        string $name,
        array $items,
        array $details,
        ?string $batchId = null
    ): array {
        $this->ensureUndoReady();
        $batchId = $batchId ?: \UndoManager::newBatchId();
        $undoIds = [];
        foreach ($items as $item) {
            $table = (string) ($item['table'] ?? '');
            $before = AuditPolicyRegistry::undoSnapshot((array) ($item['before'] ?? []), $table);
            $after = AuditPolicyRegistry::undoSnapshot((array) ($item['after'] ?? []), $table);
            if ($before == $after) {
                continue;
            }
            $undoId = \UndoManager::logUpdate(
                $table,
                $item['record_id'] ?? null,
                $before,
                $after,
                (string) ($item['description'] ?? ''),
                $batchId
            );
            if (!is_int($undoId) || $undoId <= 0) {
                throw new RuntimeException('Composite undo snapshot could not be stored.');
            }
            $undoIds[] = $undoId;
        }

        if (!\ActivityLog::log('update', $entityType, $recordId, $name, $details, [
            'batch_id' => $batchId,
            'undo_log_id' => $undoIds[0] ?? null,
        ])) {
            throw new RuntimeException('Composite audit event could not be stored.');
        }

        return $undoIds;
    }

    public function recordReplacement(
        string $entityType,
        $recordId,
        string $name,
        array $deletedItems,
        array $insertedItems,
        array $details,
        ?string $batchId = null
    ): array {
        $this->ensureUndoReady();
        $batchId = $batchId ?: \UndoManager::newBatchId();
        $undoIds = [];

        foreach ($deletedItems as $item) {
            $table = (string) ($item['table'] ?? '');
            $snapshot = AuditPolicyRegistry::undoSnapshot((array) ($item['snapshot'] ?? []), $table);
            $undoId = \UndoManager::logDelete(
                $table,
                $item['record_id'] ?? null,
                $snapshot,
                (string) ($item['description'] ?? ''),
                $batchId
            );
            if (!is_int($undoId) || $undoId <= 0) {
                throw new RuntimeException('Replacement delete snapshot could not be stored.');
            }
            $undoIds[] = $undoId;
        }

        foreach ($insertedItems as $item) {
            $table = (string) ($item['table'] ?? '');
            $snapshot = AuditPolicyRegistry::undoSnapshot((array) ($item['snapshot'] ?? []), $table);
            $undoId = \UndoManager::logInsert(
                $table,
                $item['record_id'] ?? null,
                $snapshot,
                (string) ($item['description'] ?? ''),
                $batchId
            );
            if (!is_int($undoId) || $undoId <= 0) {
                throw new RuntimeException('Replacement insert snapshot could not be stored.');
            }
            $undoIds[] = $undoId;
        }

        $details['deleted_count'] = count($deletedItems);
        $details['inserted_count'] = count($insertedItems);
        if (!\ActivityLog::log('update', $entityType, $recordId, $name, $details, [
            'batch_id' => $batchId,
            'undo_log_id' => $undoIds[0] ?? null,
        ])) {
            throw new RuntimeException('Replacement audit event could not be stored.');
        }

        return $undoIds;
    }

    private function ensureUndoReady(): void
    {
        if ($this->undoReady) {
            return;
        }
        \UndoManager::setDb($this->db);
        $this->undoReady = true;
    }

    private function recordUndoableEvent(
        string $action,
        string $entityType,
        $recordId,
        string $name,
        array $before,
        array $after,
        $undoId,
        ?string $batchId,
        array $details = []
    ): int {
        if (!is_int($undoId) || $undoId <= 0) {
            throw new RuntimeException('Undo snapshot could not be stored.');
        }

        $details['changes'] = EntityChangeTracker::diff($before, $after, $entityType);
        $logged = \ActivityLog::log($action, $entityType, $recordId, $name, $details, [
            'batch_id' => $batchId,
            'undo_log_id' => $undoId,
        ]);
        if (!$logged) {
            throw new RuntimeException('Audit event could not be stored.');
        }

        return $undoId;
    }
}
