<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\ImportBatchRepository;
use PDO;

final class PdoImportBatchRepository implements ImportBatchRepository
{
    public function __construct(private PDO $db) {}
    public function create(string $batchId, string $operationType, string $schemaVersion, ?int $academicYearId, ?string $sourceFileRef, int $createdBy, ?int $reversalOf = null): int
    {
        $this->db->prepare('INSERT INTO finance_import_batches (batch_id, operation_type, schema_version, academic_year_id, source_file_ref, status, row_count, error_count, created_by, reversal_of) VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?)')->execute([$batchId, $operationType, $schemaVersion, $academicYearId, $sourceFileRef, 'staged', $createdBy, $reversalOf]);
        return (int) $this->db->lastInsertId();
    }
    public function addRow(int $batchId, int $rowNumber, string $payloadJson, string $validationStatus, ?string $errorMessages): void
    {
        $this->db->prepare('INSERT INTO finance_import_rows (import_batch_id, row_number, payload_json, validation_status, error_messages_json) VALUES (?, ?, ?, ?, ?)')->execute([$batchId, $rowNumber, $payloadJson, $validationStatus, $errorMessages]);
    }
    public function updateCounts(int $batchId, int $rowCount, int $errorCount): void
    {
        $this->db->prepare('UPDATE finance_import_batches SET row_count = ?, error_count = ? WHERE id = ? AND status = ?')->execute([$rowCount, $errorCount, $batchId, 'staged']);
    }
    public function findById(int $batchId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_import_batches WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function post(int $batchId, int $postedBy, int $approvedBy): void
    {
        $this->db->prepare('UPDATE finance_import_batches SET status = ?, posted_at = NOW(), posted_by = ?, approved_by = ? WHERE id = ? AND status = ?')->execute(['posted', $postedBy, $approvedBy, $batchId, 'staged']);
    }
    public function abandon(int $batchId): void
    {
        $this->db->prepare('UPDATE finance_import_batches SET status = ? WHERE id = ? AND status = ?')->execute(['abandoned', $batchId, 'staged']);
    }
    public function preview(int $batchId): array
    {
        $stmt = $this->db->prepare('SELECT id, row_number, payload_json, validation_status, error_messages_json, posting_result_json FROM finance_import_rows WHERE import_batch_id = ? ORDER BY row_number');
        $stmt->execute([$batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByBatchKey(string $batchKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_import_batches WHERE batch_id = ? LIMIT 1');
        $stmt->execute([$batchKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByReversalOf(int $batchId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_import_batches WHERE reversal_of = ? LIMIT 1');
        $stmt->execute([$batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markRowPosted(int $rowId, string $postingResultJson): void
    {
        $this->db->prepare('UPDATE finance_import_rows SET posting_result_json = ? WHERE id = ? AND validation_status = ?')->execute([$postingResultJson, $rowId, 'valid']);
    }

    public function markReversed(int $batchId, int $reversedBy): void
    {
        $stmt = $this->db->prepare("UPDATE finance_import_batches SET status = 'reversed', reversed_at = NOW(), reversed_by = ? WHERE id = ? AND status = 'posted'");
        $stmt->execute([$reversedBy, $batchId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Import batch reversal state change was rejected.');
        }
    }
}
