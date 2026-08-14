<?php

declare(strict_types=1);

use EduCore\Modules\Attendance\Application\AttendanceEventIngestor;
use EduCore\Modules\Attendance\Application\BiometricIdentityMappingService;
use EduCore\Modules\Attendance\Contracts\AttendanceEventRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\BiometricIdentityMappingRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;

$root = dirname(__DIR__);
foreach ([
    '/src/Modules/Operations/Audit/AuditEventWriter.php',
    '/src/Modules/Attendance/Contracts/AttendanceTransactionManager.php',
    '/src/Modules/Attendance/Contracts/BiometricIdentityResolver.php',
    '/src/Modules/Attendance/Contracts/BiometricIdentityMappingRepository.php',
    '/src/Modules/Attendance/Contracts/AttendanceEventRepository.php',
    '/src/Modules/Staff/Contracts/StaffAssignmentAtDateQuery.php',
    '/src/Modules/Attendance/Application/BiometricIdentityMappingService.php',
    '/src/Modules/Attendance/Application/AttendanceEventIngestor.php',
] as $file) {
    require_once $root . $file;
}

final class BiometricMemoryStore implements BiometricIdentityMappingRepository, AttendanceEventRepository
{
    /** @var list<array<string,mixed>> */
    public array $mappings = [];
    /** @var list<array<string,mixed>> */
    public array $batches = [];
    /** @var list<array<string,mixed>> */
    public array $events = [];
    /** @var array<int,array<string,mixed>> */
    public array $methods = [
        1 => [
            'id' => 1,
            'method_type' => 'biometric',
            'status' => 'active',
            'requires_reason' => 0,
            'requires_attachment' => 0,
            'requires_review' => 0,
        ],
    ];
    private int $nextMappingId = 1;
    private int $nextBatchId = 1;
    private int $nextEventId = 1;

    public function snapshot(): string
    {
        return serialize([
            $this->mappings,
            $this->batches,
            $this->events,
            $this->methods,
            $this->nextMappingId,
            $this->nextBatchId,
            $this->nextEventId,
        ]);
    }

    public function restore(string $snapshot): void
    {
        [
            $this->mappings,
            $this->batches,
            $this->events,
            $this->methods,
            $this->nextMappingId,
            $this->nextBatchId,
            $this->nextEventId,
        ] = unserialize($snapshot, ['allowed_classes' => false]);
    }

    public function mappingsAt(int $deviceId, string $biometricIdentity, DateTimeImmutable $at): array
    {
        return array_values(array_filter(
            $this->mappings,
            static function (array $mapping) use ($deviceId, $biometricIdentity, $at): bool {
                $from = new DateTimeImmutable((string) $mapping['valid_from'], new DateTimeZone('UTC'));
                $to = $mapping['valid_to'] === null
                    ? null
                    : new DateTimeImmutable((string) $mapping['valid_to'], new DateTimeZone('UTC'));
                return (int) $mapping['device_id'] === $deviceId
                    && (string) $mapping['biometric_identity'] === $biometricIdentity
                    && $from <= $at
                    && ($to === null || $at < $to);
            }
        ));
    }

    public function mappingsForUpdate(int $deviceId, string $biometricIdentity): array
    {
        return array_values(array_filter(
            $this->mappings,
            static fn (array $mapping): bool => (int) $mapping['device_id'] === $deviceId
                && (string) $mapping['biometric_identity'] === $biometricIdentity
        ));
    }

    public function insertMapping(array $mapping): int
    {
        $id = $this->nextMappingId++;
        $this->mappings[] = ['id' => $id] + $mapping;
        return $id;
    }

    public function retireMapping(int $mappingId, DateTimeImmutable $validTo, string $reason): bool
    {
        foreach ($this->mappings as &$mapping) {
            if ((int) $mapping['id'] !== $mappingId
                || $mapping['valid_to'] !== null
                || new DateTimeImmutable((string) $mapping['valid_from']) >= $validTo) {
                continue;
            }
            $mapping['valid_to'] = $validTo->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $mapping['retired_reason'] = $reason;
            unset($mapping);
            return true;
        }
        unset($mapping);
        return false;
    }

    public function activeBiometricEntryMethod(int $entryMethodId): ?array
    {
        $method = $this->methods[$entryMethodId] ?? null;
        return $method !== null
            && $method['status'] === 'active'
            && $method['method_type'] === 'biometric'
            ? $method
            : null;
    }

    public function batchesForUpdate(string $idempotencyKey, ?string $fileFingerprint): array
    {
        return array_values(array_filter(
            $this->batches,
            static fn (array $batch): bool => $batch['idempotency_key'] === $idempotencyKey
                || ($fileFingerprint !== null && $batch['file_fingerprint'] === $fileFingerprint)
        ));
    }

    public function insertBatch(array $batch): int
    {
        $id = $this->nextBatchId++;
        $this->batches[] = ['id' => $id, 'row_counts' => null, 'finished_at' => null] + $batch;
        return $id;
    }

    public function finishBatch(
        int $batchId,
        string $status,
        DateTimeImmutable $finishedAt,
        array $result
    ): void {
        foreach ($this->batches as &$batch) {
            if ((int) $batch['id'] !== $batchId || $batch['status'] !== 'processing') {
                continue;
            }
            $batch['status'] = $status;
            $batch['finished_at'] = $finishedAt->format('Y-m-d H:i:s.u');
            $batch['row_counts'] = $result;
            unset($batch);
            return;
        }
        unset($batch);
        throw new RuntimeException('BIOMETRIC_BATCH_STATE_STALE');
    }

    public function duplicateEventsForUpdate(
        int $deviceId,
        string $idempotencyKey,
        ?string $externalEventKey,
        string $rawHash
    ): array {
        return array_values(array_filter(
            $this->events,
            static fn (array $event): bool => $event['idempotency_key'] === $idempotencyKey
                || ((int) $event['device_id'] === $deviceId && $event['raw_hash'] === $rawHash)
                || ($externalEventKey !== null
                    && (int) $event['device_id'] === $deviceId
                    && $event['external_event_key'] === $externalEventKey)
        ));
    }

    public function insertEvent(array $event): int
    {
        $id = $this->nextEventId++;
        $this->events[] = ['id' => $id] + $event;
        return $id;
    }
}

final class BiometricMemoryTransactions implements AttendanceTransactionManager
{
    public function __construct(private BiometricMemoryStore $store)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $snapshot = $this->store->snapshot();
        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->store->restore($snapshot);
            throw $exception;
        }
    }
}

final class BiometricStaffAssignments implements StaffAssignmentAtDateQuery
{
    /** @param list<int> $eligible */
    public function __construct(private array $eligible)
    {
    }

    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
    {
        if (!in_array($staffId, $this->eligible, true)) {
            return null;
        }
        return [
            'assignment_id' => $staffId * 10,
            'org_unit_id' => 1,
            'job_title_id' => 2,
            'group_ids' => [],
            'employment_status' => 'active',
        ];
    }
}

final class BiometricAuditSpy implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $records = [];
    public bool $failNext = false;

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('AUDIT_WRITE_FAILED');
        }
        $this->records[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$failures = 0;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    ++$checks;
    echo $message . ':' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        ++$failures;
    }
};
$assertThrows = static function (
    callable $operation,
    string $expectedMessage,
    string $label
) use ($assert): void {
    try {
        $operation();
        $assert(false, $label);
    } catch (Throwable $exception) {
        $assert($exception->getMessage() === $expectedMessage, $label);
    }
};

$store = new BiometricMemoryStore();
$transactions = new BiometricMemoryTransactions($store);
$assignments = new BiometricStaffAssignments([10, 20, 30]);
$audit = new BiometricAuditSpy();
$mappingService = new BiometricIdentityMappingService(
    $transactions,
    $store,
    $assignments,
    $audit
);
$ingestor = new AttendanceEventIngestor($transactions, $store, $store, $audit);
$utc = new DateTimeZone('UTC');

// Effective-dated identity reuse must preserve both halves of history.
$first = $mappingService->assign(
    900,
    7,
    'REUSE-17',
    10,
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    null,
    'device_admin'
);
$replay = $mappingService->assign(
    900,
    7,
    'REUSE-17',
    10,
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    null,
    'device_admin'
);
$assert($replay['mapping_id'] === $first['mapping_id'] && $replay['replayed'] === true, 'mapping_natural_replay_is_idempotent');
$assert(count($store->mappings) === 1, 'mapping_replay_creates_no_row');
$assertThrows(
    static fn () => $mappingService->assign(
        900,
        7,
        'REUSE-17',
        20,
        new DateTimeImmutable('2026-01-15T00:00:00Z'),
        null,
        'device_admin'
    ),
    'IDENTITY_OVERLAP',
    'overlapping_identity_assignment_fails_closed'
);
$replacement = $mappingService->reassign(
    900,
    7,
    'REUSE-17',
    20,
    new DateTimeImmutable('2026-02-01T00:00:00Z'),
    'device_admin',
    'documented identity reuse'
);
$januaryMapping = $store->mappingsAt(7, 'REUSE-17', new DateTimeImmutable('2026-01-31T23:59:59Z'));
$februaryMapping = $store->mappingsAt(7, 'REUSE-17', new DateTimeImmutable('2026-02-01T00:00:00Z'));
$assert(count($januaryMapping) === 1 && (int) $januaryMapping[0]['staff_user_id'] === 10, 'identity_reuse_preserves_old_worker');
$assert(count($februaryMapping) === 1 && (int) $februaryMapping[0]['staff_user_id'] === 20, 'identity_reuse_uses_new_worker_at_boundary');
$assert((int) $replacement['retired_mapping_id'] === (int) $first['mapping_id'], 'reassignment_retires_exact_predecessor');
$assert(
    !str_contains(json_encode($audit->records, JSON_UNESCAPED_UNICODE), 'REUSE-17'),
    'mapping_audit_never_copies_biometric_identity'
);

// A failed mandatory audit rolls the mapping command back atomically.
$mappingCountBeforeAuditFailure = count($store->mappings);
$audit->failNext = true;
$assertThrows(
    static fn () => $mappingService->assign(
        900,
        8,
        'ROLLBACK-IDENTITY',
        30,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        null,
        'device_admin'
    ),
    'AUDIT_WRITE_FAILED',
    'mapping_audit_failure_is_reported'
);
$assert(count($store->mappings) === $mappingCountBeforeAuditFailure, 'mapping_audit_failure_rolls_back_insert');

// A punch arriving weeks late resolves against event time, not receipt/current mapping time.
$delayedBatch = [
    'source_type' => 'device_pull',
    'device_id' => 7,
    'entry_method_id' => 1,
    'device_timezone' => 'Africa/Cairo',
    'clock_drift_threshold_seconds' => 300,
    'started_at' => '2026-03-01T10:00:00Z',
];
$delayedEvent = [
    'external_event_key' => 'device-evt-100',
    'biometric_identity' => 'REUSE-17',
    'device_event_at' => '2026-01-15 07:25:00',
    'received_at' => '2026-03-01T10:00:01Z',
    'event_type' => 'in',
    'raw_payload' => ['pin' => 'REUSE-17', 'time' => '2026-01-15 07:25:00'],
    'raw_payload_ref' => 'private/attendance/device-7/event-100.json',
];
$delayedResult = $ingestor->ingest(900, $delayedBatch, [$delayedEvent], 'batch-delayed-1');
$delayedReceipt = $delayedResult['events'][0];
$assert((int) $delayedReceipt['staff_user_id'] === 10, 'delayed_event_uses_historical_mapping');
$assert($delayedReceipt['clock_status'] === 'unknown', 'delivery_delay_is_not_misclassified_as_clock_drift');
$assert($delayedReceipt['device_event_at'] === '2026-01-15 07:25:00.000000', 'raw_device_clock_is_preserved');
$assert($delayedReceipt['received_at'] === '2026-03-01 10:00:01.000000', 'independent_received_time_is_preserved');
$assert($delayedReceipt['normalized_event_at_utc'] === '2026-01-15 05:25:00.000000', 'device_local_time_is_normalized_to_utc');
$assert($delayedReceipt['event_at_local'] === '2026-01-15 07:25:00.000000', 'normalized_local_time_is_explicit');
$oldEventSnapshot = $store->events[0];

// Same batch key is a receipt replay; a fresh batch with the same raw evidence deduplicates the event.
$sameBatchReplay = $ingestor->ingest(900, $delayedBatch, [$delayedEvent], 'batch-delayed-1');
$assert($sameBatchReplay['replayed'] === true && count($store->events) === 1, 'batch_idempotency_replays_without_new_event');
$assertThrows(
    static fn () => $ingestor->ingest(
        900,
        $delayedBatch,
        [array_merge($delayedEvent, ['event_type' => 'out'])],
        'batch-delayed-1'
    ),
    'BIOMETRIC_BATCH_IDEMPOTENCY_CONFLICT',
    'batch_key_reuse_with_different_payload_fails'
);
$secondDeliveryBatch = array_merge($delayedBatch, ['started_at' => '2026-03-02T10:00:00Z']);
$secondDeliveryEvent = array_merge($delayedEvent, ['received_at' => '2026-03-02T10:00:01Z']);
$duplicateResult = $ingestor->ingest(900, $secondDeliveryBatch, [$secondDeliveryEvent], 'batch-delayed-2');
$assert($duplicateResult['counts']['duplicates'] === 1 && $duplicateResult['counts']['inserted'] === 0, 'raw_hash_deduplicates_across_batches');
$assert(count($store->events) === 1, 'duplicate_raw_event_is_not_inserted_twice');
$assert($store->events[0] === $oldEventSnapshot, 'duplicate_and_identity_reuse_never_rewrite_raw_event_snapshot');

// Explicit device-clock observation is corrected independently from receive time.
$mappingService->assign(
    900,
    9,
    'DRIFT-30',
    30,
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    null,
    'device_admin'
);
$driftResult = $ingestor->ingest(
    900,
    [
        'source_type' => 'api',
        'device_id' => 9,
        'entry_method_id' => 1,
        'device_timezone' => 'Africa/Cairo',
        'clock_drift_threshold_seconds' => 300,
        'started_at' => '2026-08-02T07:00:02Z',
    ],
    [[
        'external_event_key' => 'drift-evt-1',
        'biometric_identity' => 'DRIFT-30',
        'device_event_at' => '2026-08-02 10:12:00',
        'received_at' => '2026-08-02T07:00:02Z',
        'device_clock_observed_at' => '2026-08-02 10:12:00',
        'clock_observed_at' => '2026-08-02T07:00:00Z',
        'event_type' => 'in',
        'raw_payload' => 'opaque device record 2',
    ]],
    'batch-drift-1'
);
$drift = $driftResult['events'][0];
$assert($drift['clock_offset_seconds'] === 720 && $drift['clock_status'] === 'drifted', 'clock_drift_is_measured_and_flagged');
$assert($drift['device_event_at'] === '2026-08-02 10:12:00.000000', 'drift_correction_does_not_modify_raw_device_time');
$assert($drift['normalized_event_at_utc'] === '2026-08-02 07:00:00.000000', 'positive_device_offset_is_subtracted_in_utc');
$assert($drift['event_at_local'] === '2026-08-02 10:00:00.000000', 'corrected_local_time_is_recorded_separately');
$assert($driftResult['status'] === 'partial' && $driftResult['counts']['clock_drifted'] === 1, 'drifted_batch_is_sent_to_review');

// Drift correction, not the raw device clock, chooses the mapping at a reuse boundary.
$mappingService->assign(
    900,
    11,
    'BOUNDARY-20',
    10,
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    new DateTimeImmutable('2026-08-02T07:00:00Z'),
    'device_admin',
    'scheduled identity handover'
);
$mappingService->assign(
    900,
    11,
    'BOUNDARY-20',
    20,
    new DateTimeImmutable('2026-08-02T07:00:00Z'),
    null,
    'device_admin'
);
$boundaryResult = $ingestor->ingest(
    900,
    [
        'source_type' => 'api',
        'device_id' => 11,
        'entry_method_id' => 1,
        'device_timezone' => 'Africa/Cairo',
        'clock_drift_threshold_seconds' => 300,
        'started_at' => '2026-08-02T07:07:02Z',
    ],
    [[
        'biometric_identity' => 'BOUNDARY-20',
        'device_event_at' => '2026-08-02 09:55:00',
        'received_at' => '2026-08-02T07:07:02Z',
        'device_clock_observed_at' => '2026-08-02 09:48:00',
        'clock_observed_at' => '2026-08-02T07:00:00Z',
        'event_type' => 'in',
        'raw_payload' => 'reuse boundary with slow clock',
    ]],
    'batch-boundary-drift-1'
);
$boundaryEvent = $boundaryResult['events'][0];
$assert($boundaryEvent['clock_offset_seconds'] === -720, 'slow_device_clock_offset_is_signed');
$assert($boundaryEvent['normalized_event_at_utc'] === '2026-08-02 07:07:00.000000', 'slow_clock_is_corrected_forward');
$assert((int) $boundaryEvent['staff_user_id'] === 20, 'corrected_event_time_selects_post_reuse_mapping');

// Opaque/relative evidence identifiers only; never persist URLs or filesystem paths.
$baseInvalidRefBatch = [
    'source_type' => 'api',
    'device_id' => 9,
    'entry_method_id' => 1,
    'device_timezone' => 'Africa/Cairo',
    'started_at' => '2026-08-02T08:00:00Z',
];
$baseInvalidRefEvent = [
    'biometric_identity' => 'DRIFT-30',
    'device_event_at' => '2026-08-02 11:00:00',
    'received_at' => '2026-08-02T08:00:00Z',
    'event_type' => 'out',
    'raw_payload' => 'raw-ref-check',
];
$eventCountBeforeRefChecks = count($store->events);
foreach (['C:\\secret\\raw.json', 'C:relative\\raw.json', 'file://server/raw.json', 'http://example.test/raw.json'] as $index => $unsafeRef) {
    $assertThrows(
        static fn () => $ingestor->ingest(
            900,
            $baseInvalidRefBatch,
            [array_merge($baseInvalidRefEvent, ['raw_payload_ref' => $unsafeRef])],
            'unsafe-ref-' . $index
        ),
        'BIOMETRIC_RAW_PAYLOAD_REF_INVALID',
        'unsafe_evidence_reference_' . $index . '_is_rejected'
    );
}
$assert(count($store->events) === $eventCountBeforeRefChecks, 'unsafe_evidence_references_write_nothing');

// Mandatory audit receipt shares the transaction with the batch and children.
$batchCountBeforeAuditFailure = count($store->batches);
$eventCountBeforeAuditFailure = count($store->events);
$audit->failNext = true;
$assertThrows(
    static fn () => $ingestor->ingest(
        900,
        array_merge($baseInvalidRefBatch, ['started_at' => '2026-08-02T09:00:00Z']),
        [[
            'biometric_identity' => 'DRIFT-30',
            'device_event_at' => '2026-08-02 12:00:00',
            'received_at' => '2026-08-02T09:00:00Z',
            'event_type' => 'out',
            'raw_payload' => 'audit rollback evidence',
        ]],
        'batch-audit-failure'
    ),
    'AUDIT_WRITE_FAILED',
    'ingest_audit_failure_is_reported'
);
$assert(count($store->batches) === $batchCountBeforeAuditFailure, 'ingest_audit_failure_rolls_back_batch');
$assert(count($store->events) === $eventCountBeforeAuditFailure, 'ingest_audit_failure_rolls_back_events');
$auditJson = json_encode($audit->records, JSON_UNESCAPED_UNICODE);
$assert(!str_contains($auditJson, 'opaque device record 2'), 'audit_never_copies_raw_payload');
$assert(!str_contains($auditJson, 'DRIFT-30'), 'ingest_audit_never_copies_biometric_identity');

// Architecture boundary: Application stays persistence-agnostic; PDO adapters own Attendance SQL only.
$mappingSource = (string) file_get_contents($root . '/src/Modules/Attendance/Application/BiometricIdentityMappingService.php');
$ingestorSource = (string) file_get_contents($root . '/src/Modules/Attendance/Application/AttendanceEventIngestor.php');
$mappingPdoSource = (string) file_get_contents($root . '/src/Modules/Attendance/Infrastructure/PdoBiometricIdentityMappingRepository.php');
$eventPdoSource = (string) file_get_contents($root . '/src/Modules/Attendance/Infrastructure/PdoAttendanceEventRepository.php');
$factorySource = (string) file_get_contents($root . '/src/Modules/Attendance/Infrastructure/AttendanceModuleFactory.php');
$bootstrapSource = (string) file_get_contents($root . '/src/Modules/Attendance/bootstrap.php');
$assert(!str_contains($mappingSource, 'PDO') && !str_contains($ingestorSource, 'PDO'), 'attendance_application_has_no_pdo_dependency');
$assert(str_contains($mappingSource, 'StaffAssignmentAtDateQuery'), 'mapping_uses_explicit_staff_owned_contract');
$assert(!str_contains($mappingPdoSource, 'staff_profiles') && !str_contains($mappingPdoSource, ' users '), 'attendance_mapping_adapter_does_not_query_staff_tables');
$assert(!str_contains($eventPdoSource, 'staff_profiles') && !str_contains($eventPdoSource, ' users '), 'attendance_event_adapter_does_not_query_staff_tables');
$assert(
    str_contains($factorySource, 'function biometricIdentityMappings()')
    && str_contains($factorySource, 'function attendanceEventIngestor()'),
    'attendance_factory_exposes_ingestion_services'
);
$assert(
    str_contains($bootstrapSource, "'Application/BiometricIdentityMappingService.php'")
    && str_contains($bootstrapSource, "'Application/AttendanceEventIngestor.php'")
    && str_contains($bootstrapSource, "'Infrastructure/PdoAttendanceEventRepository.php'"),
    'attendance_bootstrap_loads_ingestion_boundary'
);

if ($failures > 0) {
    fwrite(STDERR, $failures . ' of ' . $checks . " biometric ingestion checks failed.\n");
    exit(1);
}

echo 'Staff-HR biometric ingestor tests passed (' . $checks . " checks).\n";
