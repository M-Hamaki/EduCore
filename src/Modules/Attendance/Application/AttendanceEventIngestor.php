<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendanceEventRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\BiometricIdentityResolver;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Transaction-owning append-only biometric event ingestor.
 *
 * device_event_at is the uncorrected local device clock. A positive
 * clock_offset_seconds means the device is ahead of the reference clock;
 * normalized_event_at_utc/event_at_local are corrected by subtracting it.
 * received_at is independent delivery evidence and never determines drift.
 */
final class AttendanceEventIngestor
{
    private const SOURCES = ['device_pull', 'file_import', 'api', 'manual'];
    private const EVENT_TYPES = ['in', 'out', 'break_start', 'break_end', 'unknown'];
    private const MAX_CLOCK_OFFSET_SECONDS = 86400;

    private DateTimeZone $utc;

    public function __construct(
        private AttendanceTransactionManager $transactions,
        private AttendanceEventRepository $events,
        private BiometricIdentityResolver $identities,
        private AuditEventWriter $audit
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @param array<string,mixed> $batch
     * @param list<array<string,mixed>> $rawEvents
     * @return array<string,mixed>
     */
    public function ingest(
        int $actorId,
        array $batch,
        array $rawEvents,
        string $idempotencyKey
    ): array {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('BIOMETRIC_INGEST_ACTOR_INVALID');
        }
        if ($rawEvents === []) {
            throw new InvalidArgumentException('BIOMETRIC_EVENTS_REQUIRED');
        }
        $idempotencyKey = $this->requiredText(
            $idempotencyKey,
            190,
            'BIOMETRIC_BATCH_IDEMPOTENCY_KEY_INVALID'
        );
        $normalizedBatch = $this->normalizeBatch($batch);
        $normalizedEvents = $this->normalizeEvents($normalizedBatch, $rawEvents);
        if ($normalizedBatch['started_at'] === null) {
            $normalizedBatch['started_at'] = $this->earliestReceivedAt($normalizedEvents);
        }
        $requestHash = $this->payloadHash([
            'actor_id' => $actorId,
            'batch' => $normalizedBatch,
            'events' => $normalizedEvents,
        ]);

        return $this->transactions->transactional(function () use (
            $actorId,
            $normalizedBatch,
            $normalizedEvents,
            $idempotencyKey,
            $requestHash
        ): array {
            $batchCandidates = $this->uniqueRowsById($this->events->batchesForUpdate(
                $idempotencyKey,
                $normalizedBatch['file_fingerprint']
            ));
            if (count($batchCandidates) > 1) {
                throw new DomainException('BIOMETRIC_BATCH_IDEMPOTENCY_CONFLICT');
            }
            if ($batchCandidates !== []) {
                $existing = $batchCandidates[0];
                if (!hash_equals((string) ($existing['request_hash'] ?? ''), $requestHash)) {
                    throw new DomainException('BIOMETRIC_BATCH_IDEMPOTENCY_CONFLICT');
                }
                $result = $this->decodeBatchResult($existing['row_counts'] ?? null);
                $result['replayed'] = true;
                return $result;
            }

            $method = $this->events->activeBiometricEntryMethod($normalizedBatch['entry_method_id']);
            if ($method === null) {
                throw new DomainException('BIOMETRIC_ENTRY_METHOD_NOT_ACTIVE');
            }
            if ((int) ($method['requires_reason'] ?? 0) === 1
                || (int) ($method['requires_attachment'] ?? 0) === 1) {
                throw new DomainException('BIOMETRIC_ENTRY_METHOD_CONFIGURATION_INVALID');
            }
            $reviewStatus = (int) ($method['requires_review'] ?? 1) === 1
                ? 'pending'
                : 'not_required';

            $batchId = $this->events->insertBatch([
                'source_type' => $normalizedBatch['source_type'],
                'device_id' => $normalizedBatch['device_id'],
                'file_fingerprint' => $normalizedBatch['file_fingerprint'],
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'started_at' => $normalizedBatch['started_at'],
                'status' => 'processing',
                'initiated_by' => $actorId,
            ]);

            $counts = [
                'total' => count($normalizedEvents),
                'inserted' => 0,
                'duplicates' => 0,
                'matched' => 0,
                'unmatched' => 0,
                'ambiguous' => 0,
                'clock_drifted' => 0,
                'clock_unknown' => 0,
            ];
            $eventReceipts = [];
            foreach ($normalizedEvents as $event) {
                $candidates = $this->uniqueRowsById($this->events->duplicateEventsForUpdate(
                    $normalizedBatch['device_id'],
                    $event['idempotency_key'],
                    $event['external_event_key'],
                    $event['raw_hash']
                ));
                if (count($candidates) > 1) {
                    throw new DomainException('BIOMETRIC_EVENT_IDEMPOTENCY_CONFLICT');
                }
                if ($candidates !== []) {
                    $duplicate = $candidates[0];
                    if (!hash_equals((string) ($duplicate['raw_hash'] ?? ''), $event['raw_hash'])) {
                        throw new DomainException('BIOMETRIC_EVENT_IDEMPOTENCY_CONFLICT');
                    }
                    ++$counts['duplicates'];
                    $this->accumulateEvidenceCounts($counts, $duplicate);
                    $eventReceipts[] = $this->eventReceipt($duplicate, true);
                    continue;
                }

                $mappingInstant = new DateTimeImmutable($event['normalized_event_at_utc'], $this->utc);
                $mappingCandidates = $this->identities->mappingsAt(
                    $normalizedBatch['device_id'],
                    $event['biometric_identity'],
                    $mappingInstant
                );
                if (count($mappingCandidates) === 1) {
                    $mapping = $mappingCandidates[0];
                    $identityMappingId = (int) ($mapping['id'] ?? 0);
                    $staffUserId = (int) ($mapping['staff_user_id'] ?? 0);
                    if ($identityMappingId <= 0 || $staffUserId <= 0) {
                        throw new RuntimeException('BIOMETRIC_MAPPING_RESULT_INVALID');
                    }
                    $linkStatus = 'matched';
                    $linkReason = 'DATED_MAPPING_MATCHED';
                } elseif ($mappingCandidates === []) {
                    $identityMappingId = null;
                    $staffUserId = null;
                    $linkStatus = 'unmatched';
                    $linkReason = 'IDENTITY_UNMATCHED';
                } else {
                    $identityMappingId = null;
                    $staffUserId = null;
                    $linkStatus = 'ambiguous';
                    $linkReason = 'IDENTITY_OVERLAP';
                }
                if ($event['clock_status'] === 'drifted') {
                    $linkReason .= ';DEVICE_CLOCK_DRIFT';
                }

                $payload = [
                    'batch_id' => $batchId,
                    'entry_method_id' => $normalizedBatch['entry_method_id'],
                    'device_id' => $normalizedBatch['device_id'],
                    'external_event_key' => $event['external_event_key'],
                    'idempotency_key' => $event['idempotency_key'],
                    'biometric_identity' => $event['biometric_identity'],
                    'identity_mapping_id' => $identityMappingId,
                    'staff_user_id' => $staffUserId,
                    'device_event_at' => $event['device_event_at'],
                    'received_at' => $event['received_at'],
                    'device_timezone' => $event['device_timezone'],
                    'normalized_event_at_utc' => $event['normalized_event_at_utc'],
                    'event_at_local' => $event['event_at_local'],
                    'clock_offset_seconds' => $event['clock_offset_seconds'],
                    'clock_status' => $event['clock_status'],
                    'event_type' => $event['event_type'],
                    'raw_hash' => $event['raw_hash'],
                    'raw_payload_ref' => $event['raw_payload_ref'],
                    'link_status' => $linkStatus,
                    'link_reason' => $linkReason,
                    'processing_order' => $event['processing_order'],
                    'recorded_by' => $actorId,
                    'reason_text' => null,
                    'attachment_ref' => null,
                    'review_status' => $reviewStatus,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ];
                $eventId = $this->events->insertEvent($payload);
                ++$counts['inserted'];
                $this->accumulateEvidenceCounts($counts, $payload);
                $eventReceipts[] = $this->eventReceipt(['id' => $eventId] + $payload, false);
            }

            $status = ($counts['unmatched'] > 0
                || $counts['ambiguous'] > 0
                || $counts['clock_drifted'] > 0)
                ? 'partial'
                : 'completed';
            $finishedAt = $this->latestReceivedAt($normalizedEvents);
            $startedAt = new DateTimeImmutable($normalizedBatch['started_at'], $this->utc);
            if ($finishedAt < $startedAt) {
                $finishedAt = $startedAt;
            }
            $result = [
                'batch_id' => $batchId,
                'status' => $status,
                'replayed' => false,
                'request_hash' => $requestHash,
                'counts' => $counts,
                'events' => $eventReceipts,
            ];
            $this->events->finishBatch($batchId, $status, $finishedAt, $result);

            $this->audit->recordEvent(
                'staff_biometric_events_ingested',
                'staff_biometric_import_batches',
                $batchId,
                null,
                [
                    'device_id' => $normalizedBatch['device_id'],
                    'source_type' => $normalizedBatch['source_type'],
                    'request_hash' => $requestHash,
                    'idempotency_hash' => hash('sha256', $idempotencyKey),
                    'counts' => $counts,
                    'status' => $status,
                ],
                ['user_id' => $actorId]
            );

            return $result;
        });
    }

    /** @param array<string,mixed> $batch @return array<string,mixed> */
    private function normalizeBatch(array $batch): array
    {
        $source = trim((string) ($batch['source_type'] ?? ''));
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('BIOMETRIC_BATCH_SOURCE_INVALID');
        }
        $deviceId = filter_var($batch['device_id'] ?? null, FILTER_VALIDATE_INT);
        if ($deviceId === false || $deviceId <= 0) {
            throw new InvalidArgumentException('BIOMETRIC_DEVICE_ID_INVALID');
        }
        $entryMethodId = filter_var($batch['entry_method_id'] ?? null, FILTER_VALIDATE_INT);
        if ($entryMethodId === false || $entryMethodId <= 0) {
            throw new InvalidArgumentException('BIOMETRIC_ENTRY_METHOD_ID_INVALID');
        }
        $timezoneName = trim((string) ($batch['device_timezone'] ?? 'Africa/Cairo'));
        if (strlen($timezoneName) > 64) {
            throw new InvalidArgumentException('BIOMETRIC_DEVICE_TIMEZONE_INVALID');
        }
        try {
            $deviceTimezone = new DateTimeZone($timezoneName);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('BIOMETRIC_DEVICE_TIMEZONE_INVALID', 0, $exception);
        }
        $threshold = filter_var(
            $batch['clock_drift_threshold_seconds'] ?? 300,
            FILTER_VALIDATE_INT
        );
        if ($threshold === false || $threshold < 0 || $threshold > self::MAX_CLOCK_OFFSET_SECONDS) {
            throw new InvalidArgumentException('BIOMETRIC_CLOCK_THRESHOLD_INVALID');
        }
        $fingerprint = $this->nullableHash($batch['file_fingerprint'] ?? null, 'BIOMETRIC_FILE_FINGERPRINT_INVALID');
        $startedAt = null;
        if ($this->hasTemporalValue($batch['started_at'] ?? null)) {
            $startedAt = $this->databaseInstant($this->parseAbsoluteInstant(
                $batch['started_at'],
                'BIOMETRIC_BATCH_STARTED_AT_INVALID'
            ));
        }
        $deviceObservation = $batch['device_clock_observed_at'] ?? null;
        $referenceObservation = $batch['clock_observed_at'] ?? null;
        $hasDeviceObservation = $this->hasTemporalValue($deviceObservation);
        $hasReferenceObservation = $this->hasTemporalValue($referenceObservation);
        if ($hasDeviceObservation xor $hasReferenceObservation) {
            throw new InvalidArgumentException('BIOMETRIC_CLOCK_OBSERVATION_INCOMPLETE');
        }
        if ($hasDeviceObservation) {
            $deviceObservation = $this->parseDeviceLocal(
                $deviceObservation,
                $deviceTimezone,
                'BIOMETRIC_DEVICE_CLOCK_OBSERVATION_INVALID'
            )->format('Y-m-d H:i:s.u');
            $referenceObservation = $this->databaseInstant($this->parseAbsoluteInstant(
                $referenceObservation,
                'BIOMETRIC_REFERENCE_CLOCK_OBSERVATION_INVALID'
            ));
        } else {
            $deviceObservation = null;
            $referenceObservation = null;
        }

        return [
            'source_type' => $source,
            'device_id' => (int) $deviceId,
            'entry_method_id' => (int) $entryMethodId,
            'device_timezone' => $timezoneName,
            'file_fingerprint' => $fingerprint,
            'clock_drift_threshold_seconds' => (int) $threshold,
            'clock_offset_seconds' => $this->nullableClockOffset($batch['clock_offset_seconds'] ?? null),
            'device_clock_observed_at' => $deviceObservation,
            'clock_observed_at' => $referenceObservation,
            'started_at' => $startedAt,
        ];
    }

    /**
     * @param array<string,mixed> $batch
     * @param list<array<string,mixed>> $rawEvents
     * @return list<array<string,mixed>>
     */
    private function normalizeEvents(array $batch, array $rawEvents): array
    {
        $normalized = [];
        foreach ($rawEvents as $index => $event) {
            if (!is_array($event)) {
                throw new InvalidArgumentException('BIOMETRIC_EVENT_INVALID');
            }
            $identity = $this->requiredText(
                (string) ($event['biometric_identity'] ?? ''),
                100,
                'BIOMETRIC_IDENTITY_INVALID'
            );
            $eventDeviceId = isset($event['device_id'])
                ? filter_var($event['device_id'], FILTER_VALIDATE_INT)
                : $batch['device_id'];
            if ($eventDeviceId === false || (int) $eventDeviceId !== $batch['device_id']) {
                throw new InvalidArgumentException('BIOMETRIC_EVENT_DEVICE_MISMATCH');
            }
            $timezoneName = trim((string) ($event['device_timezone'] ?? $batch['device_timezone']));
            if ($timezoneName !== $batch['device_timezone']) {
                throw new InvalidArgumentException('BIOMETRIC_EVENT_TIMEZONE_MISMATCH');
            }
            $timezone = new DateTimeZone($timezoneName);
            $deviceLocal = $this->parseDeviceLocal(
                $event['device_event_at'] ?? null,
                $timezone,
                'BIOMETRIC_DEVICE_EVENT_AT_INVALID'
            );
            $receivedAt = $this->parseAbsoluteInstant(
                $event['received_at'] ?? null,
                'BIOMETRIC_RECEIVED_AT_INVALID'
            );
            $clockOffset = $this->resolveClockOffset($event, $batch, $timezone);
            $rawUtc = $deviceLocal->setTimezone($this->utc);
            $normalizedUtc = $clockOffset === null
                ? $rawUtc
                : $rawUtc->modify(($clockOffset >= 0 ? '-' : '+') . abs($clockOffset) . ' seconds');
            $correctedLocal = $normalizedUtc->setTimezone($timezone);
            $clockStatus = $clockOffset === null
                ? 'unknown'
                : (abs($clockOffset) > $batch['clock_drift_threshold_seconds'] ? 'drifted' : 'trusted');
            $eventType = trim((string) ($event['event_type'] ?? 'unknown'));
            if (!in_array($eventType, self::EVENT_TYPES, true)) {
                throw new InvalidArgumentException('BIOMETRIC_EVENT_TYPE_INVALID');
            }
            $externalKey = $this->nullableText(
                $event['external_event_key'] ?? null,
                190,
                'BIOMETRIC_EXTERNAL_EVENT_KEY_INVALID'
            );
            $rawHash = $this->rawHash($event);
            $eventKey = isset($event['idempotency_key'])
                ? $this->requiredText((string) $event['idempotency_key'], 190, 'BIOMETRIC_EVENT_IDEMPOTENCY_KEY_INVALID')
                : $this->derivedEventKey($batch['device_id'], $externalKey, $rawHash);
            $rawPayloadRef = $this->normalizeEvidenceRef($event['raw_payload_ref'] ?? null);

            $normalized[] = [
                'input_order' => (int) $index,
                'external_event_key' => $externalKey,
                'idempotency_key' => $eventKey,
                'biometric_identity' => $identity,
                'device_event_at' => $deviceLocal->format('Y-m-d H:i:s.u'),
                'received_at' => $this->databaseInstant($receivedAt),
                'device_timezone' => $timezoneName,
                'normalized_event_at_utc' => $this->databaseInstant($normalizedUtc),
                'event_at_local' => $correctedLocal->format('Y-m-d H:i:s.u'),
                'clock_offset_seconds' => $clockOffset,
                'clock_status' => $clockStatus,
                'event_type' => $eventType,
                'raw_hash' => $rawHash,
                'raw_payload_ref' => $rawPayloadRef,
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            return [$left['normalized_event_at_utc'], $left['input_order']]
                <=> [$right['normalized_event_at_utc'], $right['input_order']];
        });
        foreach ($normalized as $index => &$event) {
            $event['processing_order'] = $index + 1;
            unset($event['input_order']);
        }
        unset($event);
        return $normalized;
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $batch */
    private function resolveClockOffset(array $event, array $batch, DateTimeZone $timezone): ?int
    {
        $direct = array_key_exists('clock_offset_seconds', $event)
            ? $this->nullableClockOffset($event['clock_offset_seconds'])
            : $batch['clock_offset_seconds'];
        $deviceObservation = $event['device_clock_observed_at']
            ?? $batch['device_clock_observed_at'];
        $referenceObservation = $event['clock_observed_at']
            ?? $batch['clock_observed_at'];
        $hasDeviceObservation = $this->hasTemporalValue($deviceObservation);
        $hasReferenceObservation = $this->hasTemporalValue($referenceObservation);
        if ($hasDeviceObservation xor $hasReferenceObservation) {
            throw new InvalidArgumentException('BIOMETRIC_CLOCK_OBSERVATION_INCOMPLETE');
        }
        if (!$hasDeviceObservation) {
            return $direct;
        }

        $deviceClock = $this->parseDeviceLocal(
            $deviceObservation,
            $timezone,
            'BIOMETRIC_DEVICE_CLOCK_OBSERVATION_INVALID'
        )->setTimezone($this->utc);
        $referenceClock = $this->parseAbsoluteInstant(
            $referenceObservation,
            'BIOMETRIC_REFERENCE_CLOCK_OBSERVATION_INVALID'
        );
        $calculated = (int) round((float) $deviceClock->format('U.u') - (float) $referenceClock->format('U.u'));
        if (abs($calculated) > self::MAX_CLOCK_OFFSET_SECONDS) {
            throw new InvalidArgumentException('BIOMETRIC_CLOCK_OFFSET_INVALID');
        }
        if ($direct !== null && $direct !== $calculated) {
            throw new InvalidArgumentException('BIOMETRIC_CLOCK_OBSERVATION_CONFLICT');
        }
        return $calculated;
    }

    private function nullableClockOffset(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $offset = filter_var($value, FILTER_VALIDATE_INT);
        if ($offset === false || abs((int) $offset) > self::MAX_CLOCK_OFFSET_SECONDS) {
            throw new InvalidArgumentException('BIOMETRIC_CLOCK_OFFSET_INVALID');
        }
        return (int) $offset;
    }

    private function parseDeviceLocal(mixed $value, DateTimeZone $timezone, string $error): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        }
        $text = trim((string) $value);
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $text, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            $outputFormat = $format === '!Y-m-d H:i:s.u' ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s';
            if ($parsed !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format($outputFormat) === $text) {
                return $parsed;
            }
        }
        throw new InvalidArgumentException($error);
    }

    private function hasTemporalValue(mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function parseAbsoluteInstant(mixed $value, string $error): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($this->utc);
        }
        $text = trim((string) $value);
        if ($text === '' || preg_match(
            '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?$/D',
            $text
        ) !== 1) {
            throw new InvalidArgumentException($error);
        }
        try {
            $timezone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $text) === 1
                ? null
                : $this->utc;
            $parsed = new DateTimeImmutable($text, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new InvalidArgumentException($error);
            }
            return $parsed->setTimezone($this->utc);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($error, 0, $exception);
        }
    }

    /** @param array<string,mixed> $event */
    private function rawHash(array $event): string
    {
        if (isset($event['raw_hash']) && trim((string) $event['raw_hash']) !== '') {
            return $this->requiredHash($event['raw_hash'], 'BIOMETRIC_RAW_HASH_INVALID');
        }
        if (!array_key_exists('raw_payload', $event)) {
            throw new InvalidArgumentException('BIOMETRIC_RAW_EVIDENCE_REQUIRED');
        }
        $payload = $event['raw_payload'];
        if (is_string($payload)) {
            return hash('sha256', $payload);
        }
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('BIOMETRIC_RAW_EVIDENCE_INVALID', 0, $exception);
        }
    }

    private function normalizeEvidenceRef(mixed $value): ?string
    {
        $reference = $this->nullableText($value, 500, 'BIOMETRIC_RAW_PAYLOAD_REF_INVALID');
        if ($reference === null) {
            return null;
        }
        $normalized = str_replace('\\', '/', $reference);
        if (str_contains($reference, '://')
            || preg_match('/^[A-Za-z]:/', $reference) === 1
            || str_starts_with($normalized, '/')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $normalized) === 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/:\-]*$/D', $normalized) !== 1) {
            throw new InvalidArgumentException('BIOMETRIC_RAW_PAYLOAD_REF_INVALID');
        }
        return $normalized;
    }

    private function derivedEventKey(int $deviceId, ?string $externalKey, string $rawHash): string
    {
        $kind = $externalKey === null ? 'raw' : 'external';
        $source = $externalKey ?? $rawHash;
        return 'bio:' . $deviceId . ':' . $kind . ':' . hash('sha256', $source);
    }

    /** @param array<string,int> $counts @param array<string,mixed> $event */
    private function accumulateEvidenceCounts(array &$counts, array $event): void
    {
        $linkStatus = (string) ($event['link_status'] ?? 'unmatched');
        if (isset($counts[$linkStatus])) {
            ++$counts[$linkStatus];
        }
        $clockStatus = (string) ($event['clock_status'] ?? 'unknown');
        if ($clockStatus === 'drifted') {
            ++$counts['clock_drifted'];
        } elseif ($clockStatus === 'unknown') {
            ++$counts['clock_unknown'];
        }
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function eventReceipt(array $event, bool $duplicate): array
    {
        return [
            'event_id' => (int) ($event['id'] ?? 0),
            'duplicate' => $duplicate,
            'staff_user_id' => isset($event['staff_user_id']) ? (int) $event['staff_user_id'] : null,
            'identity_mapping_id' => isset($event['identity_mapping_id']) ? (int) $event['identity_mapping_id'] : null,
            'link_status' => (string) ($event['link_status'] ?? 'unmatched'),
            'link_reason' => (string) ($event['link_reason'] ?? ''),
            'device_event_at' => (string) ($event['device_event_at'] ?? ''),
            'received_at' => (string) ($event['received_at'] ?? ''),
            'normalized_event_at_utc' => $event['normalized_event_at_utc'] ?? null,
            'event_at_local' => $event['event_at_local'] ?? null,
            'clock_offset_seconds' => isset($event['clock_offset_seconds']) ? (int) $event['clock_offset_seconds'] : null,
            'clock_status' => (string) ($event['clock_status'] ?? 'unknown'),
            'processing_order' => (int) ($event['processing_order'] ?? 0),
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function uniqueRowsById(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('BIOMETRIC_REPOSITORY_ROW_INVALID');
            }
            $unique[$id] = $row;
        }
        return array_values($unique);
    }

    /** @return array<string,mixed> */
    private function decodeBatchResult(mixed $encoded): array
    {
        if (is_string($encoded)) {
            try {
                $encoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('BIOMETRIC_BATCH_RECEIPT_INVALID', 0, $exception);
            }
        }
        if (!is_array($encoded)
            || !isset($encoded['batch_id'], $encoded['status'], $encoded['counts'], $encoded['events'])) {
            throw new RuntimeException('BIOMETRIC_BATCH_RECEIPT_INVALID');
        }
        return $encoded;
    }

    /** @param list<array<string,mixed>> $events */
    private function earliestReceivedAt(array $events): string
    {
        $values = array_column($events, 'received_at');
        sort($values, SORT_STRING);
        return (string) $values[0];
    }

    /** @param list<array<string,mixed>> $events */
    private function latestReceivedAt(array $events): DateTimeImmutable
    {
        $values = array_column($events, 'received_at');
        rsort($values, SORT_STRING);
        return new DateTimeImmutable((string) $values[0], $this->utc);
    }

    private function nullableText(mixed $value, int $maxLength, string $error): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return $this->requiredText((string) $value, $maxLength, $error);
    }

    private function requiredText(string $value, int $maxLength, string $error): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private function nullableHash(mixed $value, string $error): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return $this->requiredHash($value, $error);
    }

    private function requiredHash(mixed $value, string $error): string
    {
        $hash = strtolower(trim((string) $value));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new InvalidArgumentException($error);
        }
        return $hash;
    }

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    private function payloadHash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('BIOMETRIC_BATCH_PAYLOAD_INVALID', 0, $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        if ($keys !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
