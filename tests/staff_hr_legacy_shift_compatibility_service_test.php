<?php

declare(strict_types=1);

use EduCore\Modules\Attendance\Application\LegacyStaffShiftCompatibilityService;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\LegacyStaffShiftAuditWriter;
use EduCore\Modules\Attendance\Contracts\LegacyStaffShiftRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class MemoryLegacyShiftRepository implements LegacyStaffShiftRepository
{
    public array $settings = [];
    public array $overrides = [];
    public array $eligible = [];
    public int $writes = 0;
    private int $nextId = 1;

    public function viewData(): array { return []; }
    public function lockDefaultSettings(): array { return $this->settings; }
    public function upsertDefaultSetting(string $key, string $value, string $description): void
    {
        $this->settings[$key] = $value;
        $this->writes++;
    }
    public function isEligibleActiveStaff(int $userId): bool { return $this->eligible[$userId] ?? false; }
    public function lockOverrideByUser(int $userId): ?array { return $this->overrides[$userId] ?? null; }
    public function storeOverride(array $values): void
    {
        $userId = (int) $values['user_id'];
        $id = (int) ($this->overrides[$userId]['id'] ?? $this->nextId++);
        $this->overrides[$userId] = ['id' => $id] + $values;
        $this->writes++;
    }
    public function findOverrideByUser(int $userId): ?array
    {
        return isset($this->overrides[$userId])
            ? $this->overrides[$userId] + ['staff_name' => 'عامل ' . $userId]
            : null;
    }
    public function lockOverrideById(int $id): ?array
    {
        foreach ($this->overrides as $row) {
            if ((int) $row['id'] === $id) {
                return $row + ['staff_name' => 'عامل ' . $row['user_id']];
            }
        }
        return null;
    }
    public function deleteOverride(int $id): void
    {
        foreach ($this->overrides as $userId => $row) {
            if ((int) $row['id'] === $id) {
                unset($this->overrides[$userId]);
                $this->writes++;
                return;
            }
        }
    }
    public function snapshot(): array { return [$this->settings, $this->overrides, $this->writes, $this->nextId]; }
    public function restore(array $snapshot): void
    {
        [$this->settings, $this->overrides, $this->writes, $this->nextId] = $snapshot;
    }
}

final class MemoryAttendanceTransactionManager implements AttendanceTransactionManager
{
    public bool $active = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    private MemoryLegacyShiftRepository $repository;

    public function __construct(MemoryLegacyShiftRepository $repository) { $this->repository = $repository; }
    public function transactional(callable $operation): mixed
    {
        $snapshot = $this->repository->snapshot();
        $this->active = true;
        try {
            $result = $operation();
            $this->commits++;
            return $result;
        } catch (Throwable $exception) {
            $this->repository->restore($snapshot);
            $this->rollbacks++;
            throw $exception;
        } finally {
            $this->active = false;
        }
    }
}

final class MemoryLegacyShiftAudit implements LegacyStaffShiftAuditWriter
{
    public array $events = [];
    public ?string $failOn = null;
    private MemoryAttendanceTransactionManager $transactions;

    public function __construct(MemoryAttendanceTransactionManager $transactions) { $this->transactions = $transactions; }
    public function defaultSettingsChanged(array $before, array $after): void { $this->record('default', $after); }
    public function overrideCreated(int $id, string $staffName, array $after): void { $this->record('created', $after); }
    public function overrideUpdated(int $id, string $staffName, array $before, array $after): void { $this->record('updated', $after); }
    public function overrideDeleted(int $id, string $staffName, array $before): void { $this->record('deleted', $before); }
    private function record(string $type, array $data): void
    {
        if (!$this->transactions->active) {
            throw new RuntimeException('AUDIT_OUTSIDE_TRANSACTION');
        }
        if ($this->failOn === $type) {
            throw new RuntimeException('AUDIT_FAILED');
        }
        $this->events[] = ['type' => $type, 'data' => $data];
    }
}

$repository = new MemoryLegacyShiftRepository();
$transactions = new MemoryAttendanceTransactionManager($repository);
$audit = new MemoryLegacyShiftAudit($transactions);
$service = new LegacyStaffShiftCompatibilityService($repository, $transactions, $audit);
$failures = [];

$check = static function (string $name, bool $passed) use (&$failures): void {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failures[] = $name;
};
$throws = static function (callable $operation): bool {
    try { $operation(); } catch (Throwable $exception) { return true; }
    return false;
};

$check('invalid_time_rejected_without_write', $throws(fn () => $service->saveDefaultShift([
    'default_shift_start' => '99:00', 'default_shift_end' => '14:30', 'default_shift_grace_minutes' => 15,
])) && $repository->writes === 0);
$check('equal_time_rejected_without_write', $throws(fn () => $service->saveDefaultShift([
    'default_shift_start' => '07:30', 'default_shift_end' => '07:30', 'default_shift_grace_minutes' => 15,
])) && $repository->writes === 0);
$check('excessive_grace_rejected_without_write', $throws(fn () => $service->saveDefaultShift([
    'default_shift_start' => '07:30', 'default_shift_end' => '14:30', 'default_shift_grace_minutes' => 241,
])) && $repository->writes === 0);

$service->saveDefaultShift([
    'default_shift_start' => '07:30', 'default_shift_end' => '14:30', 'default_shift_grace_minutes' => 20,
]);
$check('default_upsert_and_audit_commit', ($repository->settings['staff_shift_grace_minutes'] ?? null) === '20'
    && ($audit->events[0]['type'] ?? '') === 'default' && $transactions->commits === 1);

$writesBeforeInvalidUser = $repository->writes;
$check('inactive_user_rejected_without_write', $throws(fn () => $service->saveOverride([
    'user_id' => 77, 'shift_start' => '07:30', 'shift_end' => '14:30', 'grace_minutes' => 15,
])) && $repository->writes === $writesBeforeInvalidUser && $transactions->rollbacks === 1);

$repository->eligible[7] = true;
$service->saveOverride([
    'user_id' => 7, 'shift_start' => '20:00', 'shift_end' => '04:00', 'grace_minutes' => 15,
]);
$check('overnight_create_is_atomic_and_inactive_by_default', ($repository->overrides[7]['is_active'] ?? null) === 0
    && ($audit->events[1]['type'] ?? '') === 'created' && $transactions->commits === 2);

$service->saveOverride([
    'user_id' => 7, 'shift_start' => '08:00', 'shift_end' => '16:00', 'grace_minutes' => 10, 'is_active' => '1',
]);
$check('override_update_is_audited', ($repository->overrides[7]['is_active'] ?? null) === 1
    && ($audit->events[2]['type'] ?? '') === 'updated');

$beforeAuditFailure = $repository->overrides[7];
$audit->failOn = 'updated';
$check('audit_failure_rolls_back_business_write', $throws(fn () => $service->saveOverride([
    'user_id' => 7, 'shift_start' => '09:00', 'shift_end' => '17:00', 'grace_minutes' => 5, 'is_active' => '1',
])) && $repository->overrides[7] === $beforeAuditFailure && $transactions->rollbacks === 2);
$audit->failOn = null;

$overrideId = (int) $repository->overrides[7]['id'];
$service->deleteOverride($overrideId);
$check('delete_is_audited_and_committed', !isset($repository->overrides[7])
    && ($audit->events[3]['type'] ?? '') === 'deleted' && $transactions->commits === 4);

exit($failures === [] ? 0 : 1);
