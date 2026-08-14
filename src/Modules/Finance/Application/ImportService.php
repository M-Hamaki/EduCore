<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\AcademicStructure\Contracts\AcademicYearQuery;
use EduCore\Modules\Finance\Contracts\FinanceImportOperation;
use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\ImportBatchRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

final class ImportService
{
    /** @var array<string,FinanceImportOperation> */
    private array $operations = [];

    public function __construct(
        private ImportBatchRepository $imports,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit,
        iterable $operations = [],
        private ?AcademicYearQuery $academicYears = null
    ) {
        foreach ($operations as $operation) {
            if (!$operation instanceof FinanceImportOperation || isset($this->operations[$operation->operationType()])) {
                throw new InvalidArgumentException('Finance import operations must be unique contract implementations.');
            }
            $this->operations[$operation->operationType()] = $operation;
        }
    }

    public function createBatch(string $batchId, string $schemaVersion, ?string $sourceFileRef, int $createdBy, string $operationType = 'staging_only', ?int $academicYearId = null, ?int $reversalOf = null): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/i', $batchId) || trim($schemaVersion) === '' || !preg_match('/^[a-z][a-z0-9_]{2,49}$/', $operationType) || $createdBy <= 0) {
            throw new InvalidArgumentException('Invalid finance import batch context.');
        }
        if ($sourceFileRef !== null && (str_contains($sourceFileRef, '..') || preg_match('/^(?:[A-Za-z]:|[\\\\\/])/', $sourceFileRef))) {
            throw new InvalidArgumentException('Import source reference must be a normalized relative identifier.');
        }
        if ($academicYearId !== null && ($academicYearId <= 0 || ($this->academicYears !== null && $this->academicYears->isLocked($academicYearId)))) {
            throw new RuntimeException('Import into a closed academic year is forbidden.');
        }
        $existing = $this->imports->findByBatchKey($batchId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        return $this->transactions->transactional(function () use ($batchId, $schemaVersion, $sourceFileRef, $createdBy, $operationType, $academicYearId, $reversalOf): int {
            $id = $this->imports->create($batchId, $operationType, trim($schemaVersion), $academicYearId, $sourceFileRef, $createdBy, $reversalOf);
            $this->audit->recordEvent('finance_import_stage', 'finance_import_batch', $id, $batchId, ['created_by' => $createdBy, 'schema_version' => trim($schemaVersion), 'operation_type' => $operationType, 'academic_year_id' => $academicYearId]);
            return $id;
        });
    }

    public function stagePayload(int $batchId, int $rowNumber, array $payload): void
    {
        $batch = $this->imports->findById($batchId);
        if ($batch === null || (string) $batch['status'] !== 'staged') {
            throw new RuntimeException('Staged import batch not found.');
        }
        $operation = $this->operationFor((string) $batch['operation_type']);
        $errors = $operation->validate($payload, ['academic_year_id' => $batch['academic_year_id'], 'schema_version' => (string) $batch['schema_version']]);
        $this->addRow($batchId, $rowNumber, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $errors === [] ? 'valid' : 'invalid', json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function addRow(int $batchId, int $rowNumber, string $payloadJson, string $validationStatus, ?string $errorMessages): void
    {
        if (!in_array($validationStatus, ['valid', 'invalid'], true) || json_decode($payloadJson, true) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid import row payload or validation status.');
        }
        $this->transactions->transactional(function () use ($batchId, $rowNumber, $payloadJson, $validationStatus, $errorMessages): void {
            $this->imports->addRow($batchId, $rowNumber, $payloadJson, $validationStatus, $errorMessages);
            $this->audit->recordEvent('finance_import_row_stage', 'finance_import_batch', $batchId, null, ['row_number' => $rowNumber, 'validation_status' => $validationStatus]);
        });
    }

    public function updateCounts(int $batchId, int $rowCount, int $errorCount): void
    {
        $this->transactions->transactional(function () use ($batchId, $rowCount, $errorCount): void {
            $this->imports->updateCounts($batchId, $rowCount, $errorCount);
            $this->audit->recordEvent('finance_import_counts', 'finance_import_batch', $batchId, null, ['row_count' => $rowCount, 'error_count' => $errorCount]);
        });
    }

    public function postBatch(int $batchId, int $postedBy, int $approvedBy): void
    {
        FinanceAuthorization::assertMakerChecker('import_post', $postedBy, $approvedBy);
        $this->transactions->transactional(function () use ($batchId, $postedBy, $approvedBy): void {
            $batch = $this->imports->findById($batchId);
            if ($batch === null || (string) $batch['status'] !== 'staged') {
                throw new RuntimeException('Staged import batch not found.');
            }
            if ((int) $batch['error_count'] > 0) {
                throw new RuntimeException('Import batch contains validation errors.');
            }
            $rows = $this->imports->preview($batchId);
            foreach ($rows as $row) {
                if ((string) $row['validation_status'] !== 'valid') {
                    throw new RuntimeException('Import batch contains an invalid staged row.');
                }
            }
            if ((string) $batch['operation_type'] !== 'staging_only') {
                $operation = $this->operationFor((string) $batch['operation_type']);
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                    $context = [
                        'batch_id' => (string) $batch['batch_id'],
                        'batch_record_id' => $batchId,
                        'row_number' => (int) $row['row_number'],
                        'request_id' => md5((string) $batch['batch_id'] . ':' . (string) $row['row_number']),
                        'academic_year_id' => $batch['academic_year_id'],
                        'posted_by' => $postedBy,
                        'approved_by' => $approvedBy,
                    ];
                    $errors = $operation->validate($payload, $context);
                    if ($errors !== []) {
                        throw new RuntimeException('Import row failed posting validation: ' . implode('; ', $errors));
                    }
                    $result = $operation->post($payload, $context);
                    $this->imports->markRowPosted((int) $row['id'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                }
            }
            $this->imports->post($batchId, $postedBy, $approvedBy);
            $this->audit->recordEvent('finance_import_post', 'finance_import_batch', $batchId, (string) $batch['batch_id'], ['posted_by' => $postedBy, 'approved_by' => $approvedBy, 'operation_type' => (string) $batch['operation_type'], 'row_count' => count($rows)], ['batch_id' => (string) $batch['batch_id']]);
        });
    }

    public function reverseBatch(int $batchId, int $requestedBy, int $approvedBy, ?string $reversalBatchKey = null): int
    {
        FinanceAuthorization::assertMakerChecker('import_post', $requestedBy, $approvedBy);
        $reversalBatchKey = $reversalBatchKey ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/i', $reversalBatchKey)) {
            throw new InvalidArgumentException('Import reversal batch key must be hexadecimal.');
        }
        $existingByKey = $this->imports->findByBatchKey($reversalBatchKey);
        if ($existingByKey !== null) {
            return (int) $existingByKey['id'];
        }
        return $this->transactions->transactional(function () use ($batchId, $requestedBy, $approvedBy, $reversalBatchKey): int {
            $original = $this->imports->findById($batchId);
            if ($original === null || (string) $original['status'] !== 'posted' || (string) $original['operation_type'] === 'staging_only') {
                throw new RuntimeException('Only a posted business import batch can be reversed.');
            }
            $existing = $this->imports->findByReversalOf($batchId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            $operation = $this->operationFor((string) $original['operation_type']);
            $reversalId = $this->imports->create($reversalBatchKey, (string) $original['operation_type'], (string) $original['schema_version'], $original['academic_year_id'] === null ? null : (int) $original['academic_year_id'], null, $requestedBy, $batchId);
            $rows = array_reverse($this->imports->preview($batchId));
            foreach ($rows as $row) {
                $postingResult = json_decode((string) $row['posting_result_json'], true, 512, JSON_THROW_ON_ERROR);
                $context = [
                    'batch_id' => $reversalBatchKey,
                    'batch_record_id' => $reversalId,
                    'row_number' => (int) $row['row_number'],
                    'request_id' => md5($reversalBatchKey . ':' . (string) $row['row_number']),
                    'academic_year_id' => $original['academic_year_id'],
                    'posted_by' => $requestedBy,
                    'approved_by' => $approvedBy,
                ];
                $operation->reverse($postingResult, $context);
                $payload = (string) $row['payload_json'];
                $this->imports->addRow($reversalId, (int) $row['row_number'], $payload, 'valid', '[]');
            }
            $this->imports->updateCounts($reversalId, count($rows), 0);
            $this->imports->post($reversalId, $requestedBy, $approvedBy);
            $this->imports->markReversed($batchId, $requestedBy);
            $this->audit->recordEvent('finance_import_reverse', 'finance_import_batch', $reversalId, $reversalBatchKey, ['reversal_of' => $batchId, 'operation_type' => (string) $original['operation_type'], 'row_count' => count($rows), 'approved_by' => $approvedBy], ['batch_id' => $reversalBatchKey]);
            return $reversalId;
        });
    }

    public function abandonBatch(int $batchId, int $abandonedBy = 0): void
    {
        $this->transactions->transactional(function () use ($batchId, $abandonedBy): void {
            $this->imports->abandon($batchId);
            $this->audit->recordEvent('finance_import_abandon', 'finance_import_batch', $batchId, null, ['abandoned_by' => $abandonedBy]);
        });
    }

    public function previewBatch(int $batchId): array
    {
        return $this->imports->preview($batchId);
    }

    private function operationFor(string $operationType): FinanceImportOperation
    {
        if (!isset($this->operations[$operationType])) {
            throw new RuntimeException('No approved import operation handles ' . $operationType . '.');
        }
        return $this->operations[$operationType];
    }
}
