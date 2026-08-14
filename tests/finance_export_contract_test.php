<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap_finance.php';

use EduCore\Modules\Finance\Application\ExportService;
use EduCore\Modules\Finance\Infrastructure\FinanceExportRenderer;
use EduCore\Modules\Finance\Infrastructure\LocalFinanceExportStorage;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};
$root = sys_get_temp_dir() . '/educore-finance-export-' . bin2hex(random_bytes(8));
$audit = new class implements AuditEventWriter {
    public array $events = [];
    public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void { $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context'); }
};
$storage = new LocalFinanceExportStorage($root);
$service = new ExportService($audit, new FinanceExportRenderer(), $storage);
$refs = [];

try {
    $rows = [['name' => '=cmd', 'amount' => '10.00', 'secret' => 'must-not-export']];
    $refs['csv'] = $service->export('trial_balance', $rows, ['name', 'amount'], ['name', 'amount'], ['period_id' => 7], 9, 'csv');
    $refs['xlsx'] = $service->export('trial_balance', $rows, ['name', 'amount'], ['name', 'amount'], ['period_id' => 7], 9, 'xlsx');
    $refs['pdf'] = $service->export('trial_balance', $rows, ['name', 'amount'], ['name', 'amount'], ['period_id' => 7], 9, 'pdf');
    foreach ($refs as $format => $ref) { $assert($storage->exists($ref), strtoupper($format) . ' export is stored under private temporary storage'); }

    $csvPath = $root . '/storage/private/finance_exports/' . basename(substr($refs['csv'], strlen('private:finance_exports/')));
    $xlsxPath = $root . '/storage/private/finance_exports/' . basename(substr($refs['xlsx'], strlen('private:finance_exports/')));
    $pdfPath = $root . '/storage/private/finance_exports/' . basename(substr($refs['pdf'], strlen('private:finance_exports/')));
    $csv = (string) file_get_contents($csvPath);
    $assert(str_contains($csv, "'=cmd") && !str_contains($csv, 'must-not-export'), 'CSV respects selected columns and neutralizes spreadsheet formulas');
    $assert(str_starts_with((string) file_get_contents($xlsxPath), 'PK'), 'XLSX renderer produces an Office zip package');
    $assert(str_starts_with((string) file_get_contents($pdfPath), '%PDF-'), 'PDF renderer produces a PDF document');

    $rejected = false;
    try { $service->export('trial_balance', $rows, ['secret'], ['name', 'amount'], [], 9, 'csv'); } catch (InvalidArgumentException) { $rejected = true; }
    $assert($rejected, 'export rejects columns outside the caller permission scope');
    $auditJson = json_encode($audit->events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $assert(!str_contains($auditJson, 'must-not-export') && !str_contains($auditJson, '=cmd'), 'audit stores filters and row count without report contents');
    $assert(($audit->events[0]['details']['filters']['period_id'] ?? null) === 7 && ($audit->events[0]['details']['row_count'] ?? null) === 1, 'export audit records actor scope, filters, and row count');

    touch($csvPath, time() - 86401);
    $assert($service->cleanupExpired(time()) === 1 && !$storage->exists($refs['csv']), 'temporary export older than 24 hours is deleted');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
} finally {
    foreach ($refs as $ref) { try { $storage->delete($ref); } catch (Throwable) {} }
    $directory = $root . '/storage/private/finance_exports';
    if (is_dir($directory)) { @rmdir($directory); }
    if (is_dir($root . '/storage/private')) { @rmdir($root . '/storage/private'); }
    if (is_dir($root . '/storage')) { @rmdir($root . '/storage'); }
    if (is_dir($root)) { @rmdir($root); }
}

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s).\n"); exit(1); }
echo "Finance export permission, format, audit, and retention contract PASSED.\n";
