<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\AcademicStructure\Infrastructure\PdoAcademicYearQuery;
use EduCore\Modules\Finance\Application\FinancePeriodService;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinancePeriodRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

$options = getopt('', ['database:']);
$database = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $database) || $database === 'educore') { fwrite(STDERR, "FAILED: isolated *_test database required.\n"); exit(1); }
$db = new PDO('mysql:host=localhost;dbname=' . $database . ';charset=utf8mb4', (string) env('DB_USER', env('DB_USERNAME', 'root')), (string) env('DB_PASS', env('DB_PASSWORD_LOCAL', '')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$yearId = 990116;
$db->prepare('DELETE FROM finance_periods WHERE academic_year_id = ?')->execute([$yearId]);
$db->prepare('DELETE FROM academic_years WHERE id = ?')->execute([$yearId]);
$db->prepare('INSERT INTO academic_years (id, name, is_active, locked, status) VALUES (?, ?, 1, 0, ?)')->execute([$yearId, '2096-2097', 'active']);
$audit = new class implements AuditEventWriter { public array $events = []; public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void { $this->events[] = $action; } };
$service = new FinancePeriodService(new PdoFinancePeriodRepository($db), new PdoAcademicYearQuery($db), new PdoFinanceTransactionManager($db), $audit);
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; } };
try {
    $periodId = $service->createPeriod($yearId, 'الفصل الأول', '2096-09-01', '2097-01-31', 1);
    try { $service->closePeriod($periodId, 1, 1); $assert(false, 'same actor cannot close and approve'); } catch (RuntimeException) { $assert(true, 'same actor close refused'); }
    $service->closePeriod($periodId, 1, 2);
    try { $service->assertWritable($yearId, $periodId); $assert(false, 'closed period rejects writes'); } catch (RuntimeException) { $assert(true, 'closed period rejected'); }
    try { $service->reopenPeriod($periodId, 3, 3, 'تصحيح'); $assert(false, 'same actor cannot reopen and approve'); } catch (RuntimeException) { $assert(true, 'same actor reopen refused'); }
    $service->reopenPeriod($periodId, 3, 4, 'تصحيح قيد موثق');
    $row = $service->assertWritable($yearId, $periodId);
    $assert((string) $row['status'] === 'reopened', 'approved reopen restores writability');
    $assert($audit->events === ['finance_period_create', 'finance_period_close', 'finance_period_reopen'], 'create, close, and reopen are audited once');
} finally {
    $db->prepare('DELETE FROM finance_periods WHERE academic_year_id = ?')->execute([$yearId]);
    $db->prepare('DELETE FROM academic_years WHERE id = ?')->execute([$yearId]);
}
if ($failures > 0) { exit(1); }
echo "Finance period lifecycle integration test PASSED on {$database}.\n";
