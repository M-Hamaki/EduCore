<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Migration;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use JsonException;
use PDO;
use Throwable;

/**
 * Coordinates resumable Staff-HR migrations without owning source mapping.
 *
 * A migration-specific worker owns row transformation. This coordinator owns
 * only the durable window/batch/checkpoint/quarantine/reconciliation contract.
 */
final class StaffHrMigrationCoordinator
{
    private const MODES = ['capture', 'freeze', 'legacy_only', 'new_only'];
    private DateTimeZone $utc;

    public function __construct(private PDO $db, private AuditEventWriter $audit)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    /** @return array<string,mixed> */
    public function openWindow(
        string $mode,
        ?string $sourceWatermark,
        int $approvedBy,
        string $idempotencyKey,
        ?DateTimeImmutable $rollbackDeadline = null
    ): array {
        $mode = $this->mode($mode);
        if ($mode === 'new_only') {
            throw new InvalidArgumentException('STAFF_HR_CUTOVER_NEW_ONLY_REQUIRES_RECONCILIATION');
        }
        $approvedBy = $this->positiveId($approvedBy, 'STAFF_HR_CUTOVER_APPROVER_INVALID');
        $idempotencyKey = $this->key($idempotencyKey);
        $sourceWatermark = $this->optionalText($sourceWatermark, 255);
        $now = new DateTimeImmutable('now', $this->utc);
        if ($rollbackDeadline !== null && $rollbackDeadline < $now) {
            throw new InvalidArgumentException('STAFF_HR_CUTOVER_ROLLBACK_DEADLINE_INVALID');
        }

        return $this->transactional(function () use (
            $mode,
            $sourceWatermark,
            $approvedBy,
            $idempotencyKey,
            $rollbackDeadline,
            $now
        ): array {
            $existing = $this->windowByKeyForUpdate($idempotencyKey);
            $fingerprint = $this->hash([
                'mode' => $mode,
                'source_watermark' => $sourceWatermark,
                'approved_by' => $approvedBy,
                'rollback_deadline' => $rollbackDeadline?->format('Y-m-d H:i:s.u'),
            ]);
            if ($existing !== null) {
                $stored = $this->hash([
                    'mode' => $existing['write_mode'],
                    'source_watermark' => $existing['source_watermark'],
                    'approved_by' => (int) $existing['approved_by'],
                    'rollback_deadline' => $existing['rollback_deadline'],
                ]);
                if (!hash_equals($stored, $fingerprint)) {
                    throw new DomainException('STAFF_HR_CUTOVER_IDEMPOTENCY_CONFLICT');
                }
                return $this->windowReceipt($existing, true);
            }

            $statement = $this->db->prepare(
                'INSERT INTO staff_hr_cutover_windows
                 (opened_at, write_mode, source_watermark, approved_by, rollback_deadline,
                  reconciliation_status, status, idempotency_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $this->instant($now),
                $mode,
                $sourceWatermark,
                $approvedBy,
                $rollbackDeadline === null ? null : $this->instant($rollbackDeadline),
                'pending',
                'open',
                $idempotencyKey,
            ]);
            $windowId = (int) $this->db->lastInsertId();
            $this->audit->recordEvent(
                'staff_hr_cutover_window_opened',
                'staff_hr_cutover_windows',
                $windowId,
                null,
                ['write_mode' => $mode, 'source_watermark_hash' => $this->hash($sourceWatermark)],
                ['user_id' => $approvedBy, 'occurred_at' => $this->instant($now)]
            );

            return $this->windowReceipt($this->windowForUpdate($windowId), false);
        });
    }

    /** @param list<array<string,mixed>> $manifest @return array<string,mixed> */
    public function beginBatch(
        int $windowId,
        string $migrationKey,
        ?string $sourceWatermark,
        int $actorId,
        string $idempotencyKey,
        array $manifest = []
    ): array {
        $windowId = $this->positiveId($windowId, 'STAFF_HR_MIGRATION_WINDOW_INVALID');
        $actorId = $this->positiveId($actorId, 'STAFF_HR_MIGRATION_ACTOR_INVALID');
        $migrationKey = $this->requiredText($migrationKey, 190, 'STAFF_HR_MIGRATION_KEY_INVALID');
        $idempotencyKey = $this->key($idempotencyKey);
        $sourceWatermark = $this->optionalText($sourceWatermark, 255);
        $manifestJson = $this->manifestJson($manifest);
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactional(function () use (
            $windowId,
            $migrationKey,
            $sourceWatermark,
            $actorId,
            $idempotencyKey,
            $manifestJson,
            $now
        ): array {
            $window = $this->windowForUpdate($windowId);
            $this->assertOpenWindow($window);
            $existing = $this->batchByKeyForUpdate($idempotencyKey);
            if ($existing !== null) {
                $expected = $this->hash([$windowId, $migrationKey, $sourceWatermark, $manifestJson]);
                $stored = $this->hash([
                    (int) $existing['cutover_window_id'],
                    $existing['migration_key'],
                    $existing['source_watermark'],
                    $existing['manifest_json'],
                ]);
                if (!hash_equals($stored, $expected)) {
                    throw new DomainException('STAFF_HR_MIGRATION_IDEMPOTENCY_CONFLICT');
                }
                return $this->batchReceipt($existing, true);
            }

            $statement = $this->db->prepare(
                'INSERT INTO staff_hr_migration_batches
                 (migration_key, source_watermark, started_at, status, idempotency_key,
                  cutover_window_id, manifest_json, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $migrationKey,
                $sourceWatermark,
                $this->instant($now),
                'running',
                $idempotencyKey,
                $windowId,
                $manifestJson,
                $actorId,
            ]);
            $batchId = (int) $this->db->lastInsertId();
            $this->audit->recordEvent(
                'staff_hr_migration_batch_started',
                'staff_hr_migration_batches',
                $batchId,
                null,
                ['migration_key' => $migrationKey, 'manifest_hash' => $this->hash($manifestJson)],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            return $this->batchReceipt($this->batchForUpdate($batchId), false);
        });
    }

    /** @param array{read:int,write:int,skip:int,error:int} $counts @return array<string,mixed> */
    public function checkpoint(
        int $windowId,
        int $batchId,
        string $resumeToken,
        array $counts,
        string $checksum,
        int $actorId
    ): array {
        $windowId = $this->positiveId($windowId, 'STAFF_HR_MIGRATION_WINDOW_INVALID');
        $batchId = $this->positiveId($batchId, 'STAFF_HR_MIGRATION_BATCH_INVALID');
        $actorId = $this->positiveId($actorId, 'STAFF_HR_MIGRATION_ACTOR_INVALID');
        $resumeToken = $this->requiredText($resumeToken, 500, 'STAFF_HR_MIGRATION_RESUME_TOKEN_INVALID');
        $counts = $this->counts($counts);
        $checksum = $this->checksum($checksum);
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactional(function () use (
            $windowId,
            $batchId,
            $resumeToken,
            $counts,
            $checksum,
            $actorId,
            $now
        ): array {
            $this->assertOpenWindow($this->windowForUpdate($windowId));
            $batch = $this->batchForUpdate($batchId);
            if ((int) $batch['cutover_window_id'] !== $windowId) {
                throw new DomainException('STAFF_HR_MIGRATION_BATCH_WINDOW_MISMATCH');
            }
            if (!in_array((string) $batch['status'], ['running', 'queued'], true)) {
                throw new DomainException('STAFF_HR_MIGRATION_BATCH_NOT_RUNNING');
            }
            $storedCounts = $this->countsFromRow($batch);
            if ((string) ($batch['resume_token'] ?? '') === $resumeToken) {
                if ($storedCounts !== $counts || !hash_equals((string) ($batch['checksum'] ?? ''), $checksum)) {
                    throw new DomainException('STAFF_HR_MIGRATION_CHECKPOINT_CONFLICT');
                }
                return $this->batchReceipt($batch, true);
            }
            foreach ($counts as $key => $value) {
                if ($value < $storedCounts[$key]) {
                    throw new DomainException('STAFF_HR_MIGRATION_CHECKPOINT_REGRESSION');
                }
            }
            $statement = $this->db->prepare(
                'UPDATE staff_hr_migration_batches
                 SET read_count = ?, write_count = ?, skip_count = ?, error_count = ?,
                     checksum = ?, resume_token = ?, checkpoint_at = ?, status = ?
                 WHERE id = ? AND status IN (?, ?)'
            );
            $statement->execute([
                $counts['read'], $counts['write'], $counts['skip'], $counts['error'],
                $checksum, $resumeToken, $this->instant($now), 'running', $batchId, 'running', 'queued',
            ]);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('STAFF_HR_MIGRATION_CHECKPOINT_STALE');
            }
            $this->audit->recordEvent(
                'staff_hr_migration_checkpointed',
                'staff_hr_migration_batches',
                $batchId,
                null,
                ['counts' => $counts, 'checksum' => $checksum, 'resume_token_hash' => $this->hash($resumeToken)],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            return $this->batchReceipt($this->batchForUpdate($batchId), false);
        });
    }

    /** @return array<string,mixed> */
    public function quarantine(
        int $batchId,
        string $sourceType,
        string $sourceKey,
        string $reasonCode,
        string $payloadHash,
        int $actorId
    ): array {
        $batchId = $this->positiveId($batchId, 'STAFF_HR_MIGRATION_BATCH_INVALID');
        $actorId = $this->positiveId($actorId, 'STAFF_HR_MIGRATION_ACTOR_INVALID');
        $sourceType = $this->requiredText($sourceType, 80, 'STAFF_HR_MIGRATION_SOURCE_TYPE_INVALID');
        $sourceKey = $this->requiredText($sourceKey, 190, 'STAFF_HR_MIGRATION_SOURCE_KEY_INVALID');
        $reasonCode = $this->requiredText($reasonCode, 80, 'STAFF_HR_MIGRATION_REASON_CODE_INVALID');
        $payloadHash = $this->checksum($payloadHash);

        return $this->transactional(function () use (
            $batchId,
            $sourceType,
            $sourceKey,
            $reasonCode,
            $payloadHash,
            $actorId
        ): array {
            $batch = $this->batchForUpdate($batchId);
            if ((string) $batch['status'] !== 'running') {
                throw new DomainException('STAFF_HR_MIGRATION_BATCH_NOT_RUNNING');
            }
            $statement = $this->db->prepare(
                'SELECT * FROM staff_hr_migration_exceptions
                 WHERE batch_id = ? AND source_type = ? AND source_key = ? AND reason_code = ? FOR UPDATE'
            );
            $statement->execute([$batchId, $sourceType, $sourceKey, $reasonCode]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                    throw new DomainException('STAFF_HR_MIGRATION_QUARANTINE_CONFLICT');
                }
                return ['exception_id' => (int) $existing['id'], 'replayed' => true];
            }
            $insert = $this->db->prepare(
                'INSERT INTO staff_hr_migration_exceptions
                 (batch_id, source_type, source_key, reason_code, payload_hash)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([$batchId, $sourceType, $sourceKey, $reasonCode, $payloadHash]);
            $exceptionId = (int) $this->db->lastInsertId();
            $this->audit->recordEvent(
                'staff_hr_migration_row_quarantined',
                'staff_hr_migration_exceptions',
                $exceptionId,
                null,
                ['source_type' => $sourceType, 'source_key_hash' => $this->hash($sourceKey), 'reason_code' => $reasonCode],
                ['user_id' => $actorId]
            );
            return ['exception_id' => $exceptionId, 'replayed' => false];
        });
    }

    /** @return array<string,mixed> */
    public function recordConcurrentLegacyWrite(
        int $windowId,
        string $sourceWatermark,
        string $payloadHash,
        int $actorId
    ): array {
        $windowId = $this->positiveId($windowId, 'STAFF_HR_MIGRATION_WINDOW_INVALID');
        $actorId = $this->positiveId($actorId, 'STAFF_HR_MIGRATION_ACTOR_INVALID');
        $sourceWatermark = $this->requiredText($sourceWatermark, 255, 'STAFF_HR_MIGRATION_SOURCE_WATERMARK_INVALID');
        $payloadHash = $this->checksum($payloadHash);

        return $this->transactional(function () use ($windowId, $sourceWatermark, $payloadHash, $actorId): array {
            $window = $this->windowForUpdate($windowId);
            $this->assertOpenWindow($window);
            $mode = (string) $window['write_mode'];
            if (in_array($mode, ['freeze', 'new_only'], true)) {
                throw new DomainException('STAFF_HR_CUTOVER_LEGACY_WRITE_BLOCKED');
            }
            if ($mode === 'capture') {
                $statement = $this->db->prepare(
                    'UPDATE staff_hr_cutover_windows SET source_watermark = ? WHERE id = ? AND status = ?'
                );
                $statement->execute([$sourceWatermark, $windowId, 'open']);
                if ($statement->rowCount() !== 1) {
                    throw new DomainException('STAFF_HR_CUTOVER_WINDOW_STALE');
                }
                $this->audit->recordEvent(
                    'staff_hr_cutover_legacy_write_captured',
                    'staff_hr_cutover_windows',
                    $windowId,
                    null,
                    ['source_watermark_hash' => $this->hash($sourceWatermark), 'payload_hash' => $payloadHash],
                    ['user_id' => $actorId]
                );
            }
            return ['window_id' => $windowId, 'mode' => $mode, 'captured' => $mode === 'capture'];
        });
    }

    /** @return array<string,mixed> */
    public function completeBatch(int $batchId, ?string $targetWatermark, int $actorId): array
    {
        $batchId = $this->positiveId($batchId, 'STAFF_HR_MIGRATION_BATCH_INVALID');
        $actorId = $this->positiveId($actorId, 'STAFF_HR_MIGRATION_ACTOR_INVALID');
        $targetWatermark = $this->optionalText($targetWatermark, 255);
        $now = new DateTimeImmutable('now', $this->utc);
        return $this->transactional(function () use ($batchId, $targetWatermark, $actorId, $now): array {
            $batch = $this->batchForUpdate($batchId);
            if (in_array((string) $batch['status'], ['completed', 'completed_with_exceptions'], true)) {
                return $this->batchReceipt($batch, true);
            }
            if ((string) $batch['status'] !== 'running' || empty($batch['resume_token']) || empty($batch['checksum'])) {
                throw new DomainException('STAFF_HR_MIGRATION_BATCH_NOT_CHECKPOINTED');
            }
            $open = $this->db->prepare(
                "SELECT COUNT(*) FROM staff_hr_migration_exceptions WHERE batch_id = ? AND resolution_status = 'open'"
            );
            $open->execute([$batchId]);
            $status = (int) $open->fetchColumn() > 0 ? 'completed_with_exceptions' : 'completed';
            $statement = $this->db->prepare(
                'UPDATE staff_hr_migration_batches
                 SET target_watermark = ?, completed_at = ?, status = ?
                 WHERE id = ? AND status = ?'
            );
            $statement->execute([$targetWatermark, $this->instant($now), $status, $batchId, 'running']);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('STAFF_HR_MIGRATION_BATCH_STALE');
            }
            $this->audit->recordEvent(
                'staff_hr_migration_batch_completed',
                'staff_hr_migration_batches',
                $batchId,
                null,
                ['status' => $status, 'target_watermark_hash' => $this->hash($targetWatermark)],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            return $this->batchReceipt($this->batchForUpdate($batchId), false);
        });
    }

    /** @param array{read:int,write:int,skip:int,error:int,checksum:string} $reconciliation @return array<string,mixed> */
    public function closeWindow(int $windowId, array $reconciliation, int $actorId): array
    {
        $windowId = $this->positiveId($windowId, 'STAFF_HR_MIGRATION_WINDOW_INVALID');
        $actorId = $this->positiveId($actorId, 'STAFF_HR_MIGRATION_ACTOR_INVALID');
        $counts = $this->counts($reconciliation);
        $checksum = $this->checksum((string) ($reconciliation['checksum'] ?? ''));
        $now = new DateTimeImmutable('now', $this->utc);
        return $this->transactional(function () use ($windowId, $counts, $checksum, $actorId, $now): array {
            $window = $this->windowForUpdate($windowId);
            $this->assertOpenWindow($window);
            $batches = $this->batchesForWindowForUpdate($windowId);
            if ($batches === []) {
                throw new DomainException('STAFF_HR_CUTOVER_NO_BATCHES');
            }
            foreach ($batches as $batch) {
                if ((string) $batch['status'] !== 'completed') {
                    throw new DomainException('STAFF_HR_CUTOVER_RECONCILIATION_INCOMPLETE');
                }
            }
            $actual = ['read' => 0, 'write' => 0, 'skip' => 0, 'error' => 0];
            $checksums = [];
            foreach ($batches as $batch) {
                foreach ($actual as $key => $_) {
                    $actual[$key] += $this->countsFromRow($batch)[$key];
                }
                $checksums[] = (string) $batch['checksum'];
            }
            $actualChecksum = count($checksums) === 1 ? $checksums[0] : $this->hash($checksums);
            if ($actual !== $counts || !hash_equals($actualChecksum, $checksum)) {
                $failed = $this->db->prepare(
                    "UPDATE staff_hr_cutover_windows SET reconciliation_status = 'failed' WHERE id = ? AND status = 'open'"
                );
                $failed->execute([$windowId]);
                throw new DomainException('STAFF_HR_CUTOVER_RECONCILIATION_MISMATCH');
            }
            $targetWatermarks = array_values(array_unique(array_filter(array_map(
                static fn (array $batch): string => trim((string) ($batch['target_watermark'] ?? '')),
                $batches
            ))));
            $targetWatermark = $targetWatermarks === [] ? null : implode('|', $targetWatermarks);
            $statement = $this->db->prepare(
                "UPDATE staff_hr_cutover_windows
                 SET write_mode = 'new_only', target_watermark = ?, closed_at = ?,
                     reconciliation_status = 'matched', status = 'closed'
                 WHERE id = ? AND status = 'open'"
            );
            $statement->execute([$targetWatermark, $this->instant($now), $windowId]);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('STAFF_HR_CUTOVER_WINDOW_STALE');
            }
            $this->audit->recordEvent(
                'staff_hr_cutover_window_closed',
                'staff_hr_cutover_windows',
                $windowId,
                null,
                ['counts' => $counts, 'checksum' => $checksum, 'write_mode' => 'new_only'],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            return $this->windowReceipt($this->windowForUpdate($windowId), false);
        });
    }

    /**
     * @param callable(list<array<string,mixed>>,PDO):array{reversed:int,checksum:string} $rollbackOwnedRows
     * @return array<string,mixed>
     */
    public function rollbackWindow(
        int $windowId,
        string $reason,
        int $actorId,
        callable $rollbackOwnedRows
    ): array {
        $windowId = $this->positiveId($windowId, 'STAFF_HR_MIGRATION_WINDOW_INVALID');
        $actorId = $this->positiveId($actorId, 'STAFF_HR_MIGRATION_ACTOR_INVALID');
        $reason = $this->requiredText($reason, 1000, 'STAFF_HR_CUTOVER_ROLLBACK_REASON_INVALID');
        $now = new DateTimeImmutable('now', $this->utc);
        return $this->transactional(function () use ($windowId, $reason, $actorId, $rollbackOwnedRows, $now): array {
            $window = $this->windowForUpdate($windowId);
            if ((string) $window['status'] === 'rolled_back') {
                return $this->windowReceipt($window, true);
            }
            $deadline = trim((string) ($window['rollback_deadline'] ?? ''));
            if ($deadline !== '' && new DateTimeImmutable($deadline, $this->utc) < $now) {
                throw new DomainException('STAFF_HR_CUTOVER_ROLLBACK_DEADLINE_EXPIRED');
            }
            $batches = $this->batchesForWindowForUpdate($windowId);
            $manifest = [];
            foreach ($batches as $batch) {
                $rows = json_decode((string) ($batch['manifest_json'] ?? '[]'), true);
                foreach (is_array($rows) ? $rows : [] as $row) {
                    if (is_array($row)) {
                        $manifest[] = $row + ['batch_id' => (int) $batch['id']];
                    }
                }
            }
            $receipt = $rollbackOwnedRows($manifest, $this->db);
            $reversed = (int) ($receipt['reversed'] ?? -1);
            $receiptChecksum = (string) ($receipt['checksum'] ?? '');
            if ($reversed < 0 || preg_match('/^[a-f0-9]{64}$/D', $receiptChecksum) !== 1) {
                throw new DomainException('STAFF_HR_CUTOVER_ROLLBACK_RECEIPT_INVALID');
            }
            $batchUpdate = $this->db->prepare(
                "UPDATE staff_hr_migration_batches SET status = 'rolled_back'
                 WHERE cutover_window_id = ? AND status IN ('queued','running','completed','completed_with_exceptions','failed')"
            );
            $batchUpdate->execute([$windowId]);
            $windowUpdate = $this->db->prepare(
                "UPDATE staff_hr_cutover_windows
                 SET write_mode = 'legacy_only', closed_at = ?, reconciliation_status = 'rolled_back', status = 'rolled_back'
                 WHERE id = ? AND status <> 'rolled_back'"
            );
            $windowUpdate->execute([$this->instant($now), $windowId]);
            if ($windowUpdate->rowCount() !== 1) {
                throw new DomainException('STAFF_HR_CUTOVER_WINDOW_STALE');
            }
            $this->audit->recordEvent(
                'staff_hr_cutover_window_rolled_back',
                'staff_hr_cutover_windows',
                $windowId,
                null,
                [
                    'reason_hash' => $this->hash($reason),
                    'manifest_hash' => $this->hash($manifest),
                    'reversed_count' => $reversed,
                    'receipt_checksum' => $receiptChecksum,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            return $this->windowReceipt($this->windowForUpdate($windowId), false) + [
                'reversed_count' => $reversed,
                'rollback_checksum' => $receiptChecksum,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function windowByKeyForUpdate(string $key): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_hr_cutover_windows WHERE idempotency_key = ? FOR UPDATE');
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function windowForUpdate(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_hr_cutover_windows WHERE id = ? FOR UPDATE');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('STAFF_HR_CUTOVER_WINDOW_NOT_FOUND');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function batchByKeyForUpdate(string $key): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_hr_migration_batches WHERE idempotency_key = ? FOR UPDATE');
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function batchForUpdate(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_hr_migration_batches WHERE id = ? FOR UPDATE');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('STAFF_HR_MIGRATION_BATCH_NOT_FOUND');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function batchesForWindowForUpdate(int $windowId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_hr_migration_batches WHERE cutover_window_id = ? ORDER BY id FOR UPDATE'
        );
        $statement->execute([$windowId]);
        return array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC), 'is_array'));
    }

    /** @param array<string,mixed> $window */
    private function assertOpenWindow(array $window): void
    {
        if ((string) ($window['status'] ?? '') !== 'open') {
            throw new DomainException('STAFF_HR_CUTOVER_WINDOW_NOT_OPEN');
        }
    }

    /** @param array<string,mixed> $window @return array<string,mixed> */
    private function windowReceipt(array $window, bool $replayed): array
    {
        return [
            'window_id' => (int) $window['id'],
            'write_mode' => (string) $window['write_mode'],
            'source_watermark' => $window['source_watermark'],
            'target_watermark' => $window['target_watermark'],
            'reconciliation_status' => (string) $window['reconciliation_status'],
            'status' => (string) $window['status'],
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $batch @return array<string,mixed> */
    private function batchReceipt(array $batch, bool $replayed): array
    {
        return [
            'batch_id' => (int) $batch['id'],
            'window_id' => (int) $batch['cutover_window_id'],
            'migration_key' => (string) $batch['migration_key'],
            'resume_token' => $batch['resume_token'],
            'counts' => $this->countsFromRow($batch),
            'checksum' => $batch['checksum'],
            'status' => (string) $batch['status'],
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $row @return array{read:int,write:int,skip:int,error:int} */
    private function countsFromRow(array $row): array
    {
        return [
            'read' => (int) ($row['read_count'] ?? 0),
            'write' => (int) ($row['write_count'] ?? 0),
            'skip' => (int) ($row['skip_count'] ?? 0),
            'error' => (int) ($row['error_count'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $counts @return array{read:int,write:int,skip:int,error:int} */
    private function counts(array $counts): array
    {
        $normalized = [];
        foreach (['read', 'write', 'skip', 'error'] as $key) {
            $value = filter_var($counts[$key] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0) {
                throw new InvalidArgumentException('STAFF_HR_MIGRATION_COUNTS_INVALID');
            }
            $normalized[$key] = $value;
        }
        return $normalized;
    }

    /** @param list<array<string,mixed>> $manifest */
    private function manifestJson(array $manifest): string
    {
        $normalized = [];
        foreach ($manifest as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('STAFF_HR_MIGRATION_MANIFEST_INVALID');
            }
            $resourceType = $this->requiredText((string) ($row['resource_type'] ?? ''), 80, 'STAFF_HR_MIGRATION_MANIFEST_INVALID');
            $resourceId = $this->positiveId((int) ($row['resource_id'] ?? 0), 'STAFF_HR_MIGRATION_MANIFEST_INVALID');
            $normalized[] = ['resource_type' => $resourceType, 'resource_id' => $resourceId];
        }
        usort($normalized, static fn (array $a, array $b): int => [$a['resource_type'], $a['resource_id']] <=> [$b['resource_type'], $b['resource_id']]);
        return $this->json($normalized);
    }

    private function mode(string $mode): string
    {
        $mode = trim($mode);
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('STAFF_HR_CUTOVER_MODE_INVALID');
        }
        return $mode;
    }

    private function key(string $key): string
    {
        return $this->requiredText($key, 190, 'STAFF_HR_MIGRATION_IDEMPOTENCY_KEY_INVALID');
    }

    private function checksum(string $checksum): string
    {
        $checksum = strtolower(trim($checksum));
        if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            throw new InvalidArgumentException('STAFF_HR_MIGRATION_CHECKSUM_INVALID');
        }
        return $checksum;
    }

    private function positiveId(int $value, string $code): int
    {
        if ($value <= 0) {
            throw new InvalidArgumentException($code);
        }
        return $value;
    }

    private function requiredText(string $value, int $max, string $code): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max) {
            throw new InvalidArgumentException($code);
        }
        return $value;
    }

    private function optionalText(?string $value, int $max): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw new InvalidArgumentException('STAFF_HR_MIGRATION_VALUE_TOO_LONG');
        }
        return $value;
    }

    private function transactional(callable $operation): mixed
    {
        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }
        try {
            $result = $operation();
            if ($owned) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function instant(DateTimeImmutable $value): string
    {
        return $value->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('STAFF_HR_MIGRATION_JSON_INVALID', 0, $exception);
        }
    }
}
