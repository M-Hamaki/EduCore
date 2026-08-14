<?php

declare(strict_types=1);

use EduCore\Modules\Staff\Application\Leave\LegacyLeaveCompatibilityService;
use EduCore\Modules\Staff\Contracts\LegacyLeaveGateway;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class MemoryLegacyLeaveGateway implements LegacyLeaveGateway
{
    /** @var list<array{method:string,args:list<mixed>}> */
    public array $calls = [];

    public function activeStaffList(): array
    {
        $this->record(__FUNCTION__);

        return [['id' => 17, 'name' => 'عامل تجريبي']];
    }

    public function leaveById(int $leaveId): ?array
    {
        $this->record(__FUNCTION__, [$leaveId]);

        return $leaveId === 17 ? ['id' => 17, 'user_id' => 17] : null;
    }

    public function leaves(array $filters = []): array
    {
        $this->record(__FUNCTION__, [$filters]);

        return [['id' => 17, 'user_id' => 17]];
    }

    public function leaveStats(): array
    {
        $this->record(__FUNCTION__);

        return ['leave_stats_map' => [], 'status_stats' => [], 'total' => 0];
    }

    public function deductibleTypes(array $leaveTypes): array
    {
        $this->record(__FUNCTION__, [$leaveTypes]);

        return ['regular'];
    }

    public function leaveBalancePolicy(): array
    {
        $this->record(__FUNCTION__);

        return [['label' => 'افتراضي', 'months_from' => 0, 'months_to' => null, 'balance' => 30]];
    }

    public function annualLeaveBalanceRows(
        int $year,
        array $deductibleTypes,
        ?int $userId = null,
        string $role = 'teacher'
    ): array {
        $this->record(__FUNCTION__, [$year, $deductibleTypes, $userId, $role]);

        return [['user_id' => $userId ?? 17, 'effective_balance' => 30.0]];
    }

    public function saveDeductibleTypes(array $selectedDeductTypes, array $leaveTypes): void
    {
        $this->record(__FUNCTION__, [$selectedDeductTypes, $leaveTypes]);
    }

    public function saveLeave(array $data, int $actorId, ?int $leaveId = null): int
    {
        $this->record(__FUNCTION__, [$data, $actorId, $leaveId]);

        return $leaveId ?? 17;
    }

    public function deleteLeave(int $leaveId): bool
    {
        $this->record(__FUNCTION__, [$leaveId]);

        return true;
    }

    public function saveLeaveBalancePolicy(array $tiers): void
    {
        $this->record(__FUNCTION__, [$tiers]);
    }

    public function applyLeaveBalancePolicy(
        int $year,
        array $deductibleTypes,
        string $role = 'teacher',
        ?int $userId = null
    ): int {
        $this->record(__FUNCTION__, [$year, $deductibleTypes, $role, $userId]);

        return 1;
    }

    public function updateAnnualLeaveBalance(int $userId, float $balance, string $notes = ''): void
    {
        $this->record(__FUNCTION__, [$userId, $balance, $notes]);
    }

    /** @param list<mixed> $args */
    private function record(string $method, array $args = []): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}

$failures = [];
$assert = static function (string $name, bool $passed) use (&$failures): void {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
};
$throws = static function (callable $operation): bool {
    try {
        $operation();
    } catch (Throwable) {
        return true;
    }

    return false;
};
$position = static function (string $needle, string $source): int {
    $value = strpos($source, $needle);

    return $value === false ? PHP_INT_MAX : $value;
};

$gateway = new MemoryLegacyLeaveGateway();
$service = new LegacyLeaveCompatibilityService($gateway);
$leavePayload = [
    'user_id' => 17,
    'leave_type' => 'regular',
    'start_date' => '2026-08-08',
    'end_date' => '2026-08-09',
    'status' => 'pending',
    'reason' => 'اختبار توافق',
    'notes' => 'لا يفقد النموذج حقوله العامة',
];

$leaveId = $service->saveLeave($leavePayload, 99);
$lastCall = $gateway->calls[array_key_last($gateway->calls)] ?? [];
$assert(
    'compatibility_owner_preserves_legacy_leave_payload',
    $leaveId === 17
        && ($lastCall['method'] ?? '') === 'saveLeave'
        && ($lastCall['args'][0]['leave_type'] ?? '') === 'regular'
        && ($lastCall['args'][1] ?? 0) === 99
);

$callsBeforeInvalidActor = count($gateway->calls);
$assert(
    'invalid_actor_is_rejected_before_legacy_write',
    $throws(static fn (): int => $service->saveLeave($leavePayload, 0))
        && count($gateway->calls) === $callsBeforeInvalidActor
);

$callsBeforeInvalidScope = count($gateway->calls);
$assert(
    'invalid_balance_scope_is_rejected_before_legacy_write',
    $throws(static fn (): int => $service->applyLeaveBalancePolicy(2026, ['regular'], 'unknown'))
        && count($gateway->calls) === $callsBeforeInvalidScope
);

$assert(
    'negative_manual_balance_is_rejected_before_legacy_write',
    $throws(static fn (): null => $service->updateAnnualLeaveBalance(17, -1.0))
        && count($gateway->calls) === $callsBeforeInvalidScope
);

$updatedCount = $service->applyLeaveBalancePolicy(2026, ['regular'], 'all', 17);
$applyCall = $gateway->calls[array_key_last($gateway->calls)] ?? [];
$assert(
    'valid_balance_policy_keeps_legacy_filters',
    $updatedCount === 1
        && ($applyCall['method'] ?? '') === 'applyLeaveBalancePolicy'
        && ($applyCall['args'] ?? []) === [2026, ['regular'], 'all', 17]
);

$root = dirname(__DIR__);
$leavesPath = $root . '/admin/leaves.php';
$balancesPath = $root . '/admin/leave_balances.php';
$bootstrapPath = $root . '/src/Modules/Staff/bootstrap.php';
$factoryPath = $root . '/src/Modules/Staff/Infrastructure/StaffModuleFactory.php';
$leavesPage = (string) file_get_contents($leavesPath);
$balancesPage = (string) file_get_contents($balancesPath);
$bootstrap = (string) file_get_contents($bootstrapPath);
$factory = (string) file_get_contents($factoryPath);

foreach ([
    'leaves' => $leavesPage,
    'leave_balances' => $balancesPage,
] as $surface => $page) {
    $assert(
        $surface . '_auth_precedes_database_and_request_input',
        $position("Utilities::validateSession('admin');", $page) < $position('$database = new Database()', $page)
            && $position("Utilities::validateSession('admin');", $page) < $position('$_POST', $page)
    );
    $assert(
        $surface . '_uses_staff_owned_compatibility_service',
        str_contains($page, 'StaffModuleFactory') && str_contains($page, 'legacyLeaveCompatibility()')
    );
    $assert(
        $surface . '_does_not_instantiate_legacy_leave_service_or_runtime_schema_guard',
        !str_contains($page, 'new StaffLeaveService(') && !str_contains($page, 'ensureLeaveBalanceColumns(')
    );
    $assert(
        $surface . '_converts_unexpected_errors_to_safe_reference',
        str_contains($page, 'catch (InvalidArgumentException $exception)')
            && str_contains($page, 'error_log($reference')
            && !str_contains($page, 'catch (Throwable $e)')
            && !str_contains($page, '$_SESSION[\'error_message\'] = $e->getMessage()')
    );
    $assert(
        $surface . '_uses_no_page_local_styles_or_browser_confirmation',
        !str_contains($page, '<style>') && !str_contains($page, 'confirm(') && !str_contains($page, 'Swal')
    );
}

$assert(
    'leaves_page_keeps_public_actions_fields_and_list_surface',
    str_contains($leavesPage, 'name="save_leave_policy"')
        && str_contains($leavesPage, 'name="deduct_types[]"')
        && str_contains($leavesPage, 'name="add_leave"')
        && str_contains($leavesPage, 'edit_leave')
        && str_contains($leavesPage, 'name="delete_leave"')
        && str_contains($leavesPage, 'name="user_id"')
        && str_contains($leavesPage, 'name="leave_type"')
        && str_contains($leavesPage, 'name="start_date"')
        && str_contains($leavesPage, 'name="end_date"')
        && str_contains($leavesPage, 'name="status"')
        && str_contains($leavesPage, 'name="reason"')
        && str_contains($leavesPage, 'name="notes"')
        && str_contains($leavesPage, 'admin-filter-bar')
        && str_contains($leavesPage, 'admin-list-surface')
);

$assert(
    'leave_balances_page_keeps_public_actions_and_fields',
    str_contains($balancesPage, 'name="save_balance_policy"')
        && str_contains($balancesPage, 'name="apply_balance_policy"')
        && str_contains($balancesPage, 'name="save_staff_balance"')
        && str_contains($balancesPage, 'name="policy_label[]"')
        && str_contains($balancesPage, 'name="months_from[]"')
        && str_contains($balancesPage, 'name="months_to[]"')
        && str_contains($balancesPage, 'name="policy_balance[]"')
        && str_contains($balancesPage, 'name="staff_user_id"')
        && str_contains($balancesPage, 'name="annual_leave_balance"')
        && str_contains($balancesPage, 'name="leave_balance_notes"')
);

$assert(
    'leave_balances_page_records_opening_balance_through_modern_audited_ledger',
    str_contains($balancesPage, '$staffModuleFactory->leaveBalanceLedger()')
        && str_contains($balancesPage, '$staffModuleFactory->permissionPortal()')
        && str_contains($balancesPage, 'name="record_opening_leave_balance"')
        && str_contains($balancesPage, 'name="entitlement_period_key"')
        && str_contains($balancesPage, 'name="opening_units"')
        && str_contains($balancesPage, "'movement_type' => 'grant'")
        && str_contains($balancesPage, "'source_type' => 'admin_opening_balance'")
        && !str_contains($balancesPage, 'INSERT INTO staff_leave_balance')
        && !str_contains($balancesPage, 'UPDATE staff_leave_balance')
);

$assert(
    'staff_bootstrap_and_factory_register_leave_compatibility_boundary',
    str_contains($bootstrap, "'Contracts/LegacyLeaveGateway.php'")
        && str_contains($bootstrap, "'Infrastructure/StaffLeaveLegacyGateway.php'")
        && str_contains($bootstrap, "'Application/Leave/LegacyLeaveCompatibilityService.php'")
        && str_contains($factory, 'function legacyLeaveCompatibility()')
        && str_contains($factory, 'new StaffLeaveLegacyGateway($this->db)')
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Staff-HR leave UI contract failure(s).\n");
    exit(1);
}

echo "Staff-HR leave UI and compatibility contracts passed.\n";
