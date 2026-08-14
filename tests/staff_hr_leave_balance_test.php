<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Leave\LeaveBalanceLedger;
use EduCore\Modules\Staff\Contracts\LeaveBalanceLedgerRepository;

final class LeaveBalanceTestRepository implements LeaveBalanceLedgerRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $accounts = [];

    /** @var array<int,array<string,mixed>> */
    public array $movements = [];

    public bool $failNextUpdate = false;
    private int $nextAccountId = 1;
    private int $nextMovementId = 1;

    public function transactional(callable $work): mixed
    {
        $snapshot = serialize([
            'accounts' => $this->accounts,
            'movements' => $this->movements,
            'nextAccountId' => $this->nextAccountId,
            'nextMovementId' => $this->nextMovementId,
            'failNextUpdate' => $this->failNextUpdate,
        ]);
        try {
            return $work();
        } catch (\Throwable $exception) {
            $state = unserialize($snapshot, ['allowed_classes' => false]);
            $this->accounts = $state['accounts'];
            $this->movements = $state['movements'];
            $this->nextAccountId = $state['nextAccountId'];
            $this->nextMovementId = $state['nextMovementId'];
            $this->failNextUpdate = $state['failNextUpdate'];
            throw $exception;
        }
    }

    public function movementByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->movements as $movement) {
            if (($movement['idempotency_key'] ?? '') === $idempotencyKey) {
                return $movement;
            }
        }

        return null;
    }

    public function movementByIdForUpdate(int $movementId): ?array
    {
        return $this->movements[$movementId] ?? null;
    }

    public function reversalForMovementForUpdate(int $movementId): ?array
    {
        foreach ($this->movements as $movement) {
            if ((int) ($movement['reverses_movement_id'] ?? 0) === $movementId) {
                return $movement;
            }
        }

        return null;
    }

    public function accountForUpdate(array $identity): ?array
    {
        foreach ($this->accounts as $account) {
            if ((int) $account['staff_user_id'] === (int) $identity['staff_user_id']
                && (int) $account['leave_type_id'] === (int) $identity['leave_type_id']
                && (string) $account['entitlement_period_key'] === (string) $identity['entitlement_period_key']) {
                return $account;
            }
        }

        return null;
    }

    public function ensureAccountForUpdate(array $identity): array
    {
        $existing = $this->accountForUpdate($identity);
        if ($existing !== null) {
            return $existing;
        }
        $account = [
            'id' => $this->nextAccountId++,
            'staff_user_id' => $identity['staff_user_id'],
            'leave_type_id' => $identity['leave_type_id'],
            'entitlement_period_key' => $identity['entitlement_period_key'],
            'period_from' => $identity['period_from'],
            'period_to' => $identity['period_to'],
            'status' => 'open',
            'available_units' => '0.000',
            'reserved_units' => '0.000',
            'consumed_units' => '0.000',
            'granted_units' => '0.000',
            'expired_units' => '0.000',
            'negative_balance_limit_units' => $identity['negative_balance_limit_units'],
            'lock_version' => 1,
        ];
        $this->accounts[(int) $account['id']] = $account;

        return $account;
    }

    public function accountByIdForUpdate(int $accountId): ?array
    {
        return $this->accounts[$accountId] ?? null;
    }

    public function updateAccount(array $account, int $expectedLockVersion): bool
    {
        if ($this->failNextUpdate) {
            $this->failNextUpdate = false;

            return false;
        }
        $id = (int) $account['id'];
        $existing = $this->accounts[$id] ?? null;
        if ($existing === null || $existing['status'] !== 'open' || (int) $existing['lock_version'] !== $expectedLockVersion) {
            return false;
        }
        foreach (['available_units', 'reserved_units', 'consumed_units', 'granted_units', 'expired_units'] as $field) {
            $existing[$field] = $account[$field];
        }
        $existing['lock_version'] = $expectedLockVersion + 1;
        $this->accounts[$id] = $existing;

        return true;
    }

    public function insertMovement(array $movement): int
    {
        foreach ($this->movements as $existing) {
            if (($existing['idempotency_key'] ?? '') === $movement['idempotency_key']) {
                throw new RuntimeException('duplicate idempotency');
            }
            if ((int) $existing['account_id'] === (int) $movement['account_id']
                && ($existing['logical_key'] ?? '') === $movement['logical_key']) {
                throw new RuntimeException('duplicate logical key');
            }
            if ($movement['reverses_movement_id'] !== null
                && (int) ($existing['reverses_movement_id'] ?? 0) === (int) $movement['reverses_movement_id']) {
                throw new RuntimeException('duplicate reversal');
            }
        }
        $id = $this->nextMovementId++;
        $this->movements[$id] = $movement + ['id' => $id];

        return $id;
    }
}

final class LeaveBalanceTestAudit implements AuditEventWriter
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
            throw new RuntimeException('audit failed');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertThrows = static function (callable $operation, string $expectedCode, string $message) use (&$assertions): void {
    ++$assertions;
    try {
        $operation();
    } catch (\Throwable $exception) {
        if ($exception->getMessage() === $expectedCode) {
            return;
        }
        throw new RuntimeException($message . ': expected ' . $expectedCode . ', got ' . $exception->getMessage());
    }
    throw new RuntimeException($message . ': no exception');
};
$pdoRepositorySource = (string) file_get_contents(
    $root . '/src/Modules/Staff/Infrastructure/PdoLeaveBalanceLedgerRepository.php'
);
$assert(
    str_contains($pdoRepositorySource, 'FOR UPDATE')
        && str_contains($pdoRepositorySource, 'lock_version = lock_version + 1'),
    'PDO ledger adapter locks account/idempotency rows and uses optimistic account updates'
);
$hash = static fn (string $value): string => hash('sha256', $value);
$identity = static function (string $period, string $from, string $to, string $negativeLimit = '0.000'): array {
    return [
        'staff_user_id' => 55,
        'leave_type_id' => 3,
        'entitlement_period_key' => $period,
        'period_from' => $from,
        'period_to' => $to,
        'negative_balance_limit_units' => $negativeLimit,
    ];
};
$command = static function (
    array $account,
    string $movementType,
    string $units,
    string $idempotency,
    string $logical,
    ?int $requestId = null,
    ?int $requestDayId = null
): array {
    return [
        'actor_id' => 1,
        'account' => $account,
        'leave_request_id' => $requestId,
        'request_day_id' => $requestDayId,
        'movement_type' => $movementType,
        'units' => $units,
        'source_type' => 'test_fixture',
        'source_id' => 1,
        'logical_key' => $logical,
        'idempotency_key' => $idempotency,
        'reason_code' => in_array($movementType, ['adjust', 'restore'], true) ? 'correction' : null,
    ];
};

$repository = new LeaveBalanceTestRepository();
$audit = new LeaveBalanceTestAudit();
$ledger = new LeaveBalanceLedger($repository, $audit);
$period2026 = $identity('CY-2026', '2026-01-01', '2026-12-31');

$opening = $command($period2026, 'grant', '10.000', 'open-2026', $hash('open-2026'));
$grant = $ledger->record($opening);
$assert($grant['account']['available_units'] === '10.000', 'grant creates available balance');
$assert($grant['account']['granted_units'] === '10.000', 'grant updates cumulative grant cache');
$accrual = $ledger->record($command($period2026, 'accrue', '2.000', 'accrue-2026-01', $hash('accrue-2026-01')));
$assert($accrual['account']['available_units'] === '12.000', 'accrual adds entitlement without float rounding');

$reserved = $ledger->record($command($period2026, 'reserve', '3.000', 'reserve-700', $hash('reserve-700'), 700, 701));
$assert($reserved['account']['available_units'] === '9.000' && $reserved['account']['reserved_units'] === '3.000', 'reservation atomically moves availability into reserved balance');
$consumed = $ledger->record($command($period2026, 'consume', '3.000', 'consume-700', $hash('consume-700'), 700, 701));
$assert($consumed['account']['reserved_units'] === '0.000' && $consumed['account']['consumed_units'] === '3.000', 'consumption requires and transfers an existing reservation');
$restored = $ledger->record($command($period2026, 'restore', '1.000', 'restore-700', $hash('restore-700'), 703, 703));
$assert($restored['account']['available_units'] === '10.000' && $restored['account']['consumed_units'] === '2.000', 'approved early-return restoration moves only consumed entitlement back to availability');

$releaseReserve = $ledger->record($command($period2026, 'reserve', '2.000', 'reserve-701', $hash('reserve-701'), 702, 702));
$released = $ledger->record($command($period2026, 'release', '2.000', 'release-701', $hash('release-701'), 702, 702));
$assert($released['account']['available_units'] === '10.000' && $released['account']['reserved_units'] === '0.000', 'release restores reserved entitlement exactly');

$replayed = $ledger->record($opening);
$assert($replayed['replayed'] === true && count($repository->movements) === 7, 'same idempotency key replays without a second grant');
$changedOpening = $opening;
$changedOpening['units'] = '11.000';
$assertThrows(
    static fn (): array => $ledger->record($changedOpening),
    'LEAVE_BALANCE_IDEMPOTENCY_CONFLICT',
    'same idempotency key with a changed financial payload fails closed'
);

$assertThrows(
    static fn (): array => $ledger->record($command($period2026, 'reserve', '10.001', 'reserve-too-much', $hash('reserve-too-much'), 703, 703)),
    'LEAVE_BALANCE_NEGATIVE_NOT_ALLOWED',
    'reservation cannot overdraw an account without an explicit policy limit'
);
$assert($repository->accounts[1]['available_units'] === '10.000', 'failed overdraw rolls back the counter cache');

$negativePeriod = $identity('CY-2025', '2025-01-01', '2025-12-31', '1.000');
$ledger->record($command($negativePeriod, 'grant', '1.000', 'grant-negative', $hash('grant-negative')));
$negativeReserve = $ledger->record($command($negativePeriod, 'reserve', '2.000', 'reserve-negative', $hash('reserve-negative'), 704, 704));
$assert($negativeReserve['account']['available_units'] === '-1.000', 'explicit negative policy permits reservation only within its bound');
$assertThrows(
    static fn (): array => $ledger->record($command($negativePeriod, 'reserve', '0.001', 'reserve-negative-over', $hash('reserve-negative-over'), 704, 705)),
    'LEAVE_BALANCE_NEGATIVE_LIMIT_EXCEEDED',
    'negative policy bound rejects a further thousandth'
);

$period2027 = $identity('CY-2027', '2027-01-01', '2027-12-31');
$carry = $ledger->carry([
    'actor_id' => 1,
    'source_account' => $period2026,
    'target_account' => $period2027,
    'units' => '4.000',
    'logical_key' => $hash('carry-2026-2027'),
    'idempotency_key' => 'carry-2026-2027',
    'reason_code' => 'annual_carry',
]);
$assert($carry['source_account']['available_units'] === '6.000', 'carry atomically debits the prior entitlement period');
$assert($carry['target_account']['available_units'] === '4.000', 'carry atomically credits the next entitlement period');
$assert($carry['target_account']['entitlement_period_key'] === 'CY-2027', 'cross-year carry reaches the target account only');
$carryReplay = $ledger->carry([
    'actor_id' => 1,
    'source_account' => $period2026,
    'target_account' => $period2027,
    'units' => '4.000',
    'logical_key' => $hash('carry-2026-2027'),
    'idempotency_key' => 'carry-2026-2027',
    'reason_code' => 'annual_carry',
]);
$assert($carryReplay['replayed'] === true, 'carry replay cannot duplicate a year-crossing credit');
$assertThrows(
    static fn (): array => $ledger->reverse([
        'actor_id' => 1,
        'reverses_movement_id' => $carry['target_movement_id'],
        'logical_key' => $hash('reverse-carry-target'),
        'idempotency_key' => 'reverse-carry-target',
        'reason_code' => 'wrong_path',
    ]),
    'LEAVE_BALANCE_CARRY_REVERSE_MUST_TRANSFER',
    'a carry correction must be a new atomic transfer rather than one-sided reversal'
);

$expired = $ledger->record($command($period2027, 'expire', '1.000', 'expire-2027', $hash('expire-2027')));
$assert($expired['account']['available_units'] === '3.000' && $expired['account']['expired_units'] === '1.000', 'expiry debits availability and preserves expiry evidence');
$reversedExpiry = $ledger->reverse([
    'actor_id' => 1,
    'reverses_movement_id' => $expired['movement_id'],
    'logical_key' => $hash('reverse-expire-2027'),
    'idempotency_key' => 'reverse-expire-2027',
    'reason_code' => 'expiry_corrected',
]);
$assert($reversedExpiry['account']['available_units'] === '4.000' && $reversedExpiry['account']['expired_units'] === '0.000', 'reversal restores an expiry through a new immutable movement');

$repository->failNextUpdate = true;
$movementCountBeforeStale = count($repository->movements);
$assertThrows(
    static fn (): array => $ledger->record($command($period2027, 'grant', '1.000', 'stale-grant', $hash('stale-grant'))),
    'LEAVE_BALANCE_ACCOUNT_STALE',
    'optimistic lock mismatch rejects a concurrent counter update'
);
$assert(count($repository->movements) === $movementCountBeforeStale, 'stale update leaves no partial movement');

$auditFailureRepository = new LeaveBalanceTestRepository();
$auditFailureAudit = new LeaveBalanceTestAudit();
$auditFailureAudit->fail = true;
$auditFailureLedger = new LeaveBalanceLedger($auditFailureRepository, $auditFailureAudit);
$assertThrows(
    static fn (): array => $auditFailureLedger->record($command($period2026, 'grant', '1.000', 'audit-failure', $hash('audit-failure'))),
    'audit failed',
    'mandatory audit failure aborts a ledger write'
);
$assert($auditFailureRepository->accounts === [] && $auditFailureRepository->movements === [], 'audit failure rolls back account and movement atomically');
$assert(count($audit->events) >= count($repository->movements) - 1, 'each successful movement writes shared audit evidence');

echo 'staff_hr_leave_balance_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
