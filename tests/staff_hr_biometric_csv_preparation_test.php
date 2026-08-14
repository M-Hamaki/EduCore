<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\BiometricCsvImportPreparationService;
use EduCore\Modules\Attendance\Presentation\BiometricImportErrorPresenter;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$expectCode = static function (callable $operation, string $code, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (InvalidArgumentException $exception) {
        $assert($exception->getMessage() === $code, $message);
    }
};

$service = new BiometricCsvImportPreparationService();
$prepared = $service->prepare([
    [
        'biometric_identity' => 'F-9001',
        'log_datetime' => '2026-08-02 07:31:00',
        'log_type' => 'in',
        'device_id' => '7',
        'raw_payload' => '["F-9001","2026-08-02 07:31:00","in","7"]',
        'user_id' => 99,
    ],
    [
        'biometric_identity' => 'F-9001',
        'log_datetime' => '2026-08-02 07:31:00',
        'log_type' => 'in',
        'device_id' => '7',
        'raw_payload' => '["F-9001","2026-08-02 07:31:00","in","7"]',
    ],
    [
        'biometric_identity' => 'F-9002',
        'log_datetime' => '2026-08-02 14:30:00',
        'log_type' => 'out',
        'device_id' => '',
        'raw_payload' => '["F-9002","2026-08-02 14:30:00","out",""]',
    ],
], 7, 'Africa/Cairo', '2026-08-02T12:00:00Z');

$events = $prepared['events'] ?? [];
$summary = $prepared['summary'] ?? [];
$assert(count($events) === 3, 'all valid CSV rows become raw-event candidates');
$assert(($events[0]['biometric_identity'] ?? null) === 'F-9001', 'event retains biometric identity for the dated mapping resolver only');
$assert(($events[0]['device_id'] ?? null) === 7, 'event is bound to the selected numeric device');
$assert(($events[0]['received_at'] ?? null) === '2026-08-02T12:00:00Z', 'received time is frozen at preview time for idempotent confirmation');
$assert(!array_key_exists('user_id', $events[0]), 'legacy user_id is never forwarded to the immutable biometric event owner');
$assert(($summary['duplicate_rows_in_file'] ?? null) === 1, 'preview reports duplicate raw evidence inside one CSV file');
$assert(($summary['new_rows'] ?? null) === 2, 'preview separates candidate rows from in-file duplicates');
$assert(($summary['estimated_attendance_days_to_sync'] ?? null) === 1, 'preview reports affected work dates without silently calculating attendance');
$assert(($summary['preview_rows'][0]['identity_hint'] ?? '') !== 'F-9001', 'preview masks biometric identity instead of displaying it');
$assert(!str_contains((string) ($summary['preview_rows'][0]['identity_hint'] ?? ''), '900'), 'preview does not expose the biometric identity body');

$expectCode(
    static fn () => $service->prepare([['biometric_identity' => '', 'log_datetime' => '2026-08-02 07:31:00', 'log_type' => 'in', 'raw_payload' => 'x']], 7, 'Africa/Cairo', '2026-08-02T12:00:00Z'),
    'BIOMETRIC_IDENTITY_INVALID',
    'empty biometric identity is rejected before ingestion'
);
$expectCode(
    static fn () => $service->prepare([['biometric_identity' => 'F-1', 'log_datetime' => '2026-08-02 07:31:00', 'log_type' => 'in', 'device_id' => '8', 'raw_payload' => 'x']], 7, 'Africa/Cairo', '2026-08-02T12:00:00Z'),
    'BIOMETRIC_EVENT_DEVICE_MISMATCH',
    'event cannot silently cross devices'
);
$expectCode(
    static fn () => $service->prepare([['biometric_identity' => 'F-1', 'log_datetime' => 'bad-time', 'log_type' => 'in', 'raw_payload' => 'x']], 7, 'Africa/Cairo', '2026-08-02T12:00:00Z'),
    'BIOMETRIC_DEVICE_EVENT_AT_INVALID',
    'invalid device-local time is rejected before ingestion'
);
$expectCode(
    static fn () => $service->prepare([['biometric_identity' => 'F-1', 'log_datetime' => '2026-08-02 07:31:00', 'log_type' => 'in', 'raw_payload' => 'x']], 0, 'Africa/Cairo', '2026-08-02T12:00:00Z'),
    'BIOMETRIC_DEVICE_ID_INVALID',
    'zero selected device is rejected'
);

$assert(
    BiometricImportErrorPresenter::present(new InvalidArgumentException('BIOMETRIC_EVENT_DEVICE_MISMATCH'))
        === 'يوجد رقم جهاز مختلف داخل الملف. استخدم ملفًا لجهاز واحد أو صحح رقم الجهاز.',
    'typed device mismatch receives a useful Arabic operator message'
);
$assert(
    !str_contains(
        BiometricImportErrorPresenter::present(new RuntimeException('internal private/reference/path')),
        'internal private/reference/path'
    ),
    'unknown internal errors do not leak to the operator'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} biometric CSV preparation test failure(s).\n");
    exit(1);
}

echo "Biometric CSV preparation tests passed.\n";
