<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface ImportBatchRepository
{
    public function create(string $batchId, string $operationType, string $schemaVersion, ?int $academicYearId, ?string $sourceFileRef, int $createdBy, ?int $reversalOf = null): int;
    public function addRow(int $batchId, int $rowNumber, string $payloadJson, string $validationStatus, ?string $errorMessages): void;
    public function updateCounts(int $batchId, int $rowCount, int $errorCount): void;
    public function findById(int $batchId): ?array;
    public function post(int $batchId, int $postedBy, int $approvedBy): void;
    public function abandon(int $batchId): void;
    public function preview(int $batchId): array;
    public function findByBatchKey(string $batchKey): ?array;
    public function findByReversalOf(int $batchId): ?array;
    public function markRowPosted(int $rowId, string $postingResultJson): void;
    public function markReversed(int $batchId, int $reversedBy): void;
}
