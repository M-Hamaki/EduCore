<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'database:',
    'permission-type-id:',
    'idempotency:',
    'request-id:',
    'request-period-id:',
    'movement-type:',
    'count:',
    'minutes:',
    'period-key:',
    'max-requests:',
    'max-minutes:',
    'allow-override:',
    'override-authorized:',
    'override-max-minutes:',
    'reason-code:',
    'fail-audit',
]);
$databaseName = trim((string) ($options['database'] ?? ''));
$marker = trim((string) (getenv('STAFF_HR_TEST_MARKER') ?: ''));
if ($marker !== 'integrated-staff-hr'
    || $databaseName === ''
    || !preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName)
    || strtolower($databaseName) === 'educore') {
    fwrite(STDERR, "FAIL: worker requires STAFF_HR_TEST_MARKER and an explicit isolated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('DB_NAME=' . $databaseName);
putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = $databaseName;
$_ENV['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_ENV['STAFF_HR_TEST_MARKER'] = $marker;
$_SERVER['APP_ENV'] = 'test';
$_SERVER['DB_NAME'] = $databaseName;
$_SERVER['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_SERVER['STAFF_HR_TEST_MARKER'] = $marker;

require_once dirname(__DIR__) . '/bootstrap_staff_hr.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Permission\PermissionQuotaLedger;
use EduCore\Modules\Staff\Infrastructure\PdoPermissionQuotaLedgerRepository;

final class PermissionQuotaLedgerWorkerAudit implements AuditEventWriter
{
    public function __construct(private bool $fail)
    {
    }

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->fail) {
            throw new RuntimeException('AUDIT_WRITE_FAILED');
        }
    }
}

$required = [
    'permission-type-id',
    'idempotency',
    'request-id',
    'request-period-id',
    'movement-type',
    'count',
    'minutes',
    'period-key',
];
foreach ($required as $name) {
    if (!array_key_exists($name, $options)) {
        fwrite(STDERR, "FAIL: missing worker option --{$name}.\n");
        exit(2);
    }
}

try {
    $db = staffHrTestDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ledger = new PermissionQuotaLedger(
        new PdoPermissionQuotaLedgerRepository($db),
        new PermissionQuotaLedgerWorkerAudit(array_key_exists('fail-audit', $options))
    );
    $result = $ledger->record([
        'actor_id' => 990010,
        'staff_user_id' => 1001,
        'permission_type_id' => (int) $options['permission-type-id'],
        'period_key' => (string) $options['period-key'],
        'request_id' => (int) $options['request-id'],
        'request_period_id' => (int) $options['request-period-id'],
        'movement_type' => (string) $options['movement-type'],
        'count_delta' => (int) $options['count'],
        'minutes_delta' => (int) $options['minutes'],
        'idempotency_key' => (string) $options['idempotency'],
        'reason_code' => trim((string) ($options['reason-code'] ?? 'WORKER_TEST')),
        'limits' => [
            'max_requests_per_month' => array_key_exists('max-requests', $options)
                ? (int) $options['max-requests']
                : null,
            'max_minutes_per_month' => array_key_exists('max-minutes', $options)
                ? (int) $options['max-minutes']
                : null,
            'allow_quota_override' => (int) ($options['allow-override'] ?? 0) === 1,
            'quota_override_max_minutes' => array_key_exists('override-max-minutes', $options)
                ? (int) $options['override-max-minutes']
                : null,
            'override_authorized' => (int) ($options['override-authorized'] ?? 0) === 1,
        ],
    ]);
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(3);
}
