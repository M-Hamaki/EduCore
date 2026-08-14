<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Permission\PermissionQuotaLedger;
use EduCore\Modules\Staff\Contracts\PermissionQuotaLedgerRepository;

final class PermissionQuotaLedgerFixtureRepository implements PermissionQuotaLedgerRepository
{
    /** @var array<string,array<string,mixed>> */
    public array $accounts = [];

    /** @var array<string,array<string,mixed>> */
    public array $movements = [];

    private int $nextAccountId = 1;
    private int $nextMovementId = 1;

    public function transactional(callable $work): mixed
    {
        $beforeAccounts = $this->accounts;
        $beforeMovements = $this->movements;
        $beforeAccountId = $this->nextAccountId;
        $beforeMovementId = $this->nextMovementId;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->accounts = $beforeAccounts;
            $this->movements = $beforeMovements;
            $this->nextAccountId = $beforeAccountId;
            $this->nextMovementId = $beforeMovementId;
            throw $exception;
        }
    }

    public function movementByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->movements[$idempotencyKey] ?? null;
    }

    public function ensureQuotaAccountForUpdate(
        int $staffUserId,
        int $permissionTypeId,
        string $periodKey
    ): array {
        $key = $staffUserId . ':' . $permissionTypeId . ':' . $periodKey;
        if (!isset($this->accounts[$key])) {
            $this->accounts[$key] = [
                'id' => $this->nextAccountId++,
                'staff_user_id' => $staffUserId,
                'permission_type_id' => $permissionTypeId,
                'period_key' => $periodKey,
                'status' => 'open',
                'reserved_count' => 0,
                'consumed_count' => 0,
                'reserved_minutes' => 0,
                'consumed_minutes' => 0,
                'lock_version' => 1,
            ];
        }

        return $this->accounts[$key];
    }

    public function updateQuotaAccount(array $account, int $expectedLockVersion): bool
    {
        $key = (int) $account['staff_user_id'] . ':' . (int) $account['permission_type_id'] . ':' . (string) $account['period_key'];
        $current = $this->accounts[$key] ?? null;
        if ($current === null
            || (int) $current['lock_version'] !== $expectedLockVersion
            || (string) $current['status'] !== 'open') {
            return false;
        }
        $account['lock_version'] = $expectedLockVersion + 1;
        $this->accounts[$key] = $account;

        return true;
    }

    public function insertMovement(array $movement): int
    {
        $movement['id'] = $this->nextMovementId++;
        $this->movements[(string) $movement['idempotency_key']] = $movement;

        return (int) $movement['id'];
    }
}

final class PermissionQuotaLedgerFixtureAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public bool $fail = false;

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
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $callback, string $code, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), $code), $message . ' (' . $exception->getMessage() . ')');
    }
};

$repository = new PermissionQuotaLedgerFixtureRepository();
$audit = new PermissionQuotaLedgerFixtureAudit();
$ledger = new PermissionQuotaLedger($repository, $audit);
$command = static function (
    string $movementType,
    int $countDelta,
    int $minutesDelta,
    string $idempotencyKey,
    string $periodKey = '2026-10',
    array $limits = []
): array {
    return [
        'actor_id' => 990001,
        'staff_user_id' => 1001,
        'permission_type_id' => 44,
        'period_key' => $periodKey,
        'request_id' => 5001,
        'request_period_id' => $periodKey === '2026-10' ? 6001 : 6002,
        'movement_type' => $movementType,
        'count_delta' => $countDelta,
        'minutes_delta' => $minutesDelta,
        'idempotency_key' => $idempotencyKey,
        'reason_code' => 'REQUEST_SUBMITTED',
        'limits' => $limits + [
            'max_requests_per_month' => 2,
            'max_minutes_per_month' => 120,
            'allow_quota_override' => false,
            'quota_override_max_minutes' => null,
            'override_authorized' => false,
        ],
    ];
};
$accountKey = '1001:44:2026-10';

$reserveOne = $ledger->record($command('reserve', 1, 60, 'quota-reserve-1'));
$assert($reserveOne['replayed'] === false, 'first quota reservation is stored');
$assert($reserveOne['quota_exception'] === false, 'normal reservation does not claim an exception');
$assert($repository->accounts[$accountKey]['reserved_count'] === 1, 'reservation increments the held request count');
$assert($repository->accounts[$accountKey]['reserved_minutes'] === 60, 'reservation increments the held minutes');
$assert(count($repository->movements) === 1 && count($audit->events) === 1, 'reservation writes one movement and one audit event');

$reserveReplay = $ledger->record($command('reserve', 1, 60, 'quota-reserve-1'));
$assert($reserveReplay['replayed'] === true, 'same idempotency key replays the original quota movement');
$assert(count($repository->movements) === 1 && count($audit->events) === 1, 'replay writes neither a second movement nor a second audit event');
$assertThrows(
    static fn (): array => $ledger->record($command('reserve', 1, 30, 'quota-reserve-1')),
    'PERMISSION_QUOTA_IDEMPOTENCY_CONFLICT',
    'reusing a quota idempotency key with different data is rejected'
);

$ledger->record($command('reserve', 1, 60, 'quota-reserve-2'));
$assert($repository->accounts[$accountKey]['reserved_count'] === 2, 'second valid reservation consumes the second monthly request slot');
$assertThrows(
    static fn (): array => $ledger->record($command('reserve', 1, 1, 'quota-reserve-over-limit')),
    'PERMISSION_QUOTA_EXCEEDED',
    'reservation fails when held plus consumed usage exceeds the monthly limit'
);

$consume = $ledger->record($command('consume', 1, 60, 'quota-consume-1'));
$assert($consume['movement_type'] === 'consume', 'consume movement returns its type');
$assert(
    $repository->accounts[$accountKey]['reserved_count'] === 1
    && $repository->accounts[$accountKey]['consumed_count'] === 1,
    'consume moves a reservation into consumed request count'
);
$assert(
    $repository->accounts[$accountKey]['reserved_minutes'] === 60
    && $repository->accounts[$accountKey]['consumed_minutes'] === 60,
    'consume moves a reservation into consumed minutes'
);

$ledger->record($command('release', 1, 60, 'quota-release-1'));
$assert(
    $repository->accounts[$accountKey]['reserved_count'] === 0
    && $repository->accounts[$accountKey]['reserved_minutes'] === 0,
    'release returns an unapproved reservation'
);
$ledger->record($command('reverse', 1, 60, 'quota-reverse-1'));
$assert(
    $repository->accounts[$accountKey]['consumed_count'] === 0
    && $repository->accounts[$accountKey]['consumed_minutes'] === 0,
    'reverse removes approved usage through a new movement'
);

$ledger->record($command('adjust', 0, 120, 'quota-adjust-plus'));
$ledger->record($command('adjust', 0, -60, 'quota-adjust-minus'));
$assert($repository->accounts[$accountKey]['consumed_minutes'] === 60, 'signed adjustment changes consumed quota without rewriting history');
$assertThrows(
    static fn (): array => $ledger->record($command('adjust', 0, -61, 'quota-adjust-negative')),
    'PERMISSION_QUOTA_COUNTER_NEGATIVE',
    'adjustment cannot reduce a cache below zero'
);

$overrideLimits = [
    'max_requests_per_month' => 2,
    'max_minutes_per_month' => 120,
    'allow_quota_override' => true,
    'quota_override_max_minutes' => 60,
    'override_authorized' => true,
];
$overrideCommand = $command('reserve', 1, 180, 'quota-reserve-override', '2026-11', $overrideLimits);
$overrideCommand['reason_code'] = 'HR_QUOTA_OVERRIDE';
$override = $ledger->record($overrideCommand);
$assert($override['quota_exception'] === true, 'authorized policy override is visible in the receipt');
$assert($override['available']['minutes'] === 0, 'overridden quota still reports no remaining normal minutes');

$unauthorizedOverride = $command(
    'reserve',
    1,
    121,
    'quota-reserve-unauthorized-override',
    '2026-12',
    array_replace($overrideLimits, ['override_authorized' => false])
);
$assertThrows(
    static fn (): array => $ledger->record($unauthorizedOverride),
    'PERMISSION_QUOTA_OVERRIDE_NOT_ALLOWED',
    'quota excess cannot be claimed without an authorized override'
);

$audit->fail = true;
$beforeMovementCount = count($repository->movements);
$beforeAccounts = $repository->accounts;
$assertThrows(
    static fn (): array => $ledger->record($command('reserve', 1, 30, 'quota-audit-rollback', '2027-01')),
    'AUDIT_WRITE_FAILED',
    'audit failure reaches the caller'
);
$assert($repository->accounts === $beforeAccounts, 'audit failure rolls quota-account mutation back');
$assert(count($repository->movements) === $beforeMovementCount, 'audit failure rolls the new quota movement back');
$audit->fail = false;

$assertThrows(
    static fn (): array => $ledger->record($command('release', 1, 1, 'quota-release-missing', '2027-02')),
    'PERMISSION_QUOTA_RESERVATION_MISSING',
    'release cannot invent a reservation'
);
$assertThrows(
    static fn (): array => $ledger->record($command('reserve', 0, 0, 'quota-zero-movement')),
    'PERMISSION_QUOTA_MOVEMENT_AMOUNT_INVALID',
    'zero quota movement is rejected before persistence'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} permission quota ledger failure(s).\n");
    exit(1);
}

echo "Staff-HR permission quota ledger tests passed.\n";
