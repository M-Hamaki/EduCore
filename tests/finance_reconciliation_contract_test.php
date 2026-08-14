<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

$options = getopt('', ['database:']);
$testDb = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb) || $testDb === 'educore') {
    fwrite(STDERR, "FAILED: --database must name an isolated *_test database.\n");
    exit(1);
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4',
        (string) env('DB_USER', 'root'),
        (string) env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $audit = new class implements AuditEventWriter {
        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void {}
    };
    $report = (new FinanceServiceFactory($db, $audit))->reconciliationService()->integrityReport();
    foreach (['party_gl_links', 'pure_gl_links', 'account_scopes', 'domain_bucket_totals'] as $section) {
        $assert(array_key_exists($section, $report) && is_array($report[$section]), "reconciliation report exposes {$section}");
    }
    $assert($report['party_gl_links'] === [], 'every posted party transaction has exactly one matching posted GL journal');
    $assert($report['pure_gl_links'] === [], 'pure GL vouchers/manual journals have NULL subledger links');
    $assert($report['account_scopes'] === [], 'staff accounts keep STAFF_GLOBAL and student accounts never use it');
    $assert($report['domain_bucket_totals'] === [], 'charge, payroll, and advance source totals match their bucket deltas');
    $assert($report['is_clean'] === true, 'aggregate reconciliation result is clean');
    if ($report['is_clean'] !== true) {
        fwrite(STDERR, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    }

    $schema = (string) file_get_contents(__DIR__ . '/../database/migrations/20260723_finance_core_and_subledger.php');
    $assert(!preg_match('/CREATE TABLE `finance_subledger_accounts`[\s\S]*?`balance`/i', $schema), 'subledger account schema stores no balance column');
    $views = (string) file_get_contents(__DIR__ . '/../database/migrations/20260724_finance_views.php');
    $assert(str_contains($views, "scope_key = 'STAFF_GLOBAL'"), 'staff balance view is restricted to stable STAFF_GLOBAL scope');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s).\n"); exit(1); }
echo "Finance reconciliation contract test PASSED on {$testDb}.\n";
