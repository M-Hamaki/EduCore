<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Converts transient, already-validated CSV rows into the narrow payload
 * accepted by AttendanceEventIngestor. It does not resolve an employee code
 * to a worker: biometric identity must be resolved by the dated mapping owner.
 */
final class BiometricCsvImportPreparationService
{
    private const EVENT_TYPES = ['in', 'out', 'unknown'];

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{events:list<array<string,mixed>>,summary:array<string,mixed>}
     */
    public function prepare(
        array $rows,
        int $deviceId,
        string $deviceTimezone,
        string $receivedAt
    ): array {
        if ($deviceId <= 0) {
            throw new InvalidArgumentException('BIOMETRIC_DEVICE_ID_INVALID');
        }
        if ($rows === []) {
            throw new InvalidArgumentException('BIOMETRIC_EVENTS_REQUIRED');
        }
        $timezone = $this->timezone($deviceTimezone);
        $this->receivedAt($receivedAt);

        $events = [];
        foreach ($rows as $index => $row) {
            $identity = trim((string) ($row['biometric_identity'] ?? ''));
            if ($identity === '' || strlen($identity) > 100) {
                throw new InvalidArgumentException('BIOMETRIC_IDENTITY_INVALID');
            }
            $eventAt = trim((string) ($row['log_datetime'] ?? ''));
            $this->deviceLocal($eventAt, $timezone);
            $eventType = trim((string) ($row['log_type'] ?? 'unknown'));
            if (!in_array($eventType, self::EVENT_TYPES, true)) {
                throw new InvalidArgumentException('BIOMETRIC_EVENT_TYPE_INVALID');
            }
            $rowDeviceId = trim((string) ($row['device_id'] ?? ''));
            if ($rowDeviceId !== '' && (!ctype_digit($rowDeviceId) || (int) $rowDeviceId !== $deviceId)) {
                throw new InvalidArgumentException('BIOMETRIC_EVENT_DEVICE_MISMATCH');
            }
            if (!array_key_exists('raw_payload', $row) || trim((string) $row['raw_payload']) === '') {
                throw new InvalidArgumentException('BIOMETRIC_RAW_EVIDENCE_REQUIRED');
            }

            $events[] = [
                'biometric_identity' => $identity,
                'device_id' => $deviceId,
                'device_timezone' => $deviceTimezone,
                'device_event_at' => $eventAt,
                'received_at' => $receivedAt,
                'event_type' => $eventType,
                'raw_payload' => (string) $row['raw_payload'],
            ];
        }

        return [
            'events' => $events,
            'summary' => $this->summary($events),
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array{valid_rows:int,duplicate_rows_in_file:int,new_rows:int,estimated_attendance_days_to_sync:int,preview_rows:list<array<string,string>>}
     */
    private function summary(array $events): array
    {
        $seen = [];
        $duplicates = 0;
        $dates = [];
        $previewRows = [];
        foreach ($events as $index => $event) {
            $fingerprint = hash('sha256', implode('|', [
                (string) $event['device_id'],
                (string) $event['biometric_identity'],
                (string) $event['device_event_at'],
                (string) $event['event_type'],
                (string) $event['raw_payload'],
            ]));
            $duplicate = isset($seen[$fingerprint]);
            $seen[$fingerprint] = true;
            if ($duplicate) {
                ++$duplicates;
            }
            $dates[substr((string) $event['device_event_at'], 0, 10)] = true;
            if ($index < 200) {
                $previewRows[] = [
                    'identity_hint' => $this->identityHint((string) $event['biometric_identity']),
                    'log_datetime' => (string) $event['device_event_at'],
                    'log_type' => (string) $event['event_type'],
                    'device_id' => (string) $event['device_id'],
                    'duplicate_in_file' => $duplicate ? '1' : '0',
                ];
            }
        }

        return [
            'valid_rows' => count($events),
            'duplicate_rows_in_file' => $duplicates,
            'new_rows' => count($events) - $duplicates,
            // CSV import is raw evidence only. Day calculation is an explicit
            // later shadow/recalculation operation, never a hidden side effect.
            'estimated_attendance_days_to_sync' => count($dates),
            'preview_rows' => $previewRows,
        ];
    }

    private function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('BIOMETRIC_DEVICE_TIMEZONE_INVALID', 0, $exception);
        }
    }

    private function deviceLocal(string $value, DateTimeZone $timezone): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('BIOMETRIC_DEVICE_EVENT_AT_INVALID');
        }
    }

    private function receivedAt(string $value): void
    {
        try {
            new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('BIOMETRIC_RECEIVED_AT_INVALID', 0, $exception);
        }
    }

    private function identityHint(string $identity): string
    {
        $length = strlen($identity);
        if ($length <= 2) {
            return str_repeat('•', $length);
        }

        return substr($identity, 0, 1) . str_repeat('•', max(1, $length - 2)) . substr($identity, -1);
    }
}
