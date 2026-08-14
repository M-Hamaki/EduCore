<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../classes/FinanceFeatureFlag.php';
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';

$root = dirname(__DIR__);
$expected = [
    'fee_structure.php' => ['actions' => ['save_fee_structure','copy_fee_structure','delete_fee_structure','save_sibling_discounts','save_bus_zone','delete_bus_zone','save_other_discount','toggle_other_discount','delete_other_discount'], 'ajax' => ['view_installments','get_fee_structure']],
    'fee_calculator.php' => ['actions' => [], 'ajax' => ['calculate','calculate_family'], 'json' => ['success','tuition','discount_pct','sibling_discount','tuition_after_discount','bus_fee','total']],
    'fee_payments.php' => ['actions' => ['assign_discount'], 'ajax' => ['get_student_fee','record_payment','delete_payment','generate_fees'], 'json' => ['success','message']],
    'ajax_fee_payments_datatable.php' => ['actions' => [], 'ajax' => ['datatable'], 'json' => ['draw','recordsTotal','recordsFiltered','data']],
    'staff_financial_data.php' => ['actions' => ['save_financial_data'], 'ajax' => ['get_staff_financial'], 'json' => ['success','data','message']],
    'school_budget.php' => ['actions' => [], 'ajax' => []],
    'student_buses.php' => ['actions' => ['assign_bus','bulk_assign'], 'ajax' => [], 'passthrough_get' => true],
    'bus_report.php' => ['actions' => ['do_export'], 'ajax' => [], 'passthrough_all' => true],
    'statements.php' => ['actions' => [], 'ajax' => [], 'passthrough_all' => true],
];

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; } };
foreach ($expected as $page => $surface) {
    $source = (string) file_get_contents($root . '/admin/' . $page);
    $contract = FinanceLegacyAdapter::contract($page);
    $assert(str_contains($source, 'FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__)'), "{$page} loads the shared compatibility adapter after authentication");
    $assert($contract['actions'] === $surface['actions'] && $contract['ajax'] === $surface['ajax'], "{$page} action/AJAX inventory is explicit and unchanged");
    foreach (['passthrough_get', 'passthrough_all'] as $passthrough) {
        $assert(
            (bool) ($contract[$passthrough] ?? false) === (bool) ($surface[$passthrough] ?? false),
            "{$page} preserves its documented non-Finance ownership boundary"
        );
    }
    foreach (array_merge($surface['actions'], $surface['ajax']) as $token) {
        if ($token === 'datatable') { continue; }
        $assert(str_contains($source, $token), "{$page} preserves observable token {$token}");
    }
    foreach (($surface['json'] ?? []) as $field) {
        $assert(str_contains($source, "'{$field}'") || str_contains($source, "\"{$field}\""), "{$page} preserves JSON field {$field}");
    }
    $assert(is_file($root . '/admin/' . $contract['target']), "{$page} delegates to an existing finance target page");
}

FinanceFeatureFlag::setOverride('off');
$assert(FinanceLegacyAdapter::bridgeNotice('fee_structure.php') === '', 'off mode leaves the legacy response unchanged');
FinanceFeatureFlag::setOverride('shadow');
$assert(FinanceLegacyAdapter::bridgeNotice('fee_structure.php') === '', 'shadow mode remains an internal rollout detail');
FinanceFeatureFlag::setOverride('display');
$assert(FinanceLegacyAdapter::bridgeNotice('fee_structure.php') === '', 'display mode remains an internal rollout detail');
FinanceFeatureFlag::setOverride('execute');
$assert(FinanceLegacyAdapter::bridgeNotice('fee_structure.php') === '', 'execute mode remains an internal rollout detail');
$assert(FinanceLegacyAdapter::shouldHandle(), 'execute mode selects the new finance read surface');
$adapterSource = (string) file_get_contents($root . '/classes/FinanceLegacyAdapter.php');
$assert(
    str_contains($adapterSource, 'handleFeeStructure')
        && str_contains($adapterSource, 'handleFeeCalculator')
        && str_contains($adapterSource, 'handleFeePayments')
        && str_contains($adapterSource, 'handleStaffFinance')
        && str_contains($adapterSource, 'handleStudentBuses'),
    'execute mode translates every stateful legacy surface through an owned application service'
);
$assert(
    strpos($adapterSource, 'self::requireCsrf($isAjax)') < strpos($adapterSource, 'new \\EduCore\\Modules\\Finance\\Infrastructure\\FinanceServiceFactory'),
    'legacy state and AJAX bridges validate CSRF before application-service dispatch'
);
$assert(
    str_contains($adapterSource, 'legacyCollectionCompatibilityService')
        && str_contains($adapterSource, 'legacyStaffFinanceCompatibilityService')
        && !str_contains($adapterSource, 'emitCutoverJson'),
    'established JSON fields are produced by compatibility services rather than obsolete handler fallthrough'
);
$assert(
    str_contains($adapterSource, "'bus_report.php' => ['target' => 'bus_report.php'")
        && str_contains($adapterSource, "'statements.php' => ['target' => 'statements.php'")
        && str_contains($adapterSource, "'passthrough_all' => true"),
    'Transport reports and official student statements remain in their source-owned workflows'
);
FinanceFeatureFlag::setOverride(null);

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s).\n"); exit(1); }
echo "Finance legacy adapter compatibility contract PASSED for " . count($expected) . " entrypoints.\n";
