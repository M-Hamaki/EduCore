<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

$options = getopt('', ['database:']);
$database = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $database) || $database === 'educore') { fwrite(STDERR, "FAILED: isolated *_test database required.\n"); exit(1); }
$db = new PDO('mysql:host=localhost;dbname=' . $database . ';charset=utf8mb4', (string) env('DB_USER', env('DB_USERNAME', 'root')), (string) env('DB_PASS', env('DB_PASSWORD_LOCAL', '')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$yearId = 990119;
$db->exec("DELETE FROM accounting_journal_lines WHERE journal_entry_id IN (SELECT id FROM accounting_journal_entries WHERE source_idempotency_key IN ('" . md5('manual-original-990119') . "','" . md5('manual-reversal-990119') . "'))");
$db->exec("DELETE FROM accounting_journal_entries WHERE source_idempotency_key = '" . md5('manual-reversal-990119') . "'");
$db->exec("DELETE FROM accounting_journal_entries WHERE source_idempotency_key = '" . md5('manual-original-990119') . "'");
$db->prepare('DELETE FROM finance_periods WHERE academic_year_id = ?')->execute([$yearId]);
$db->prepare('DELETE FROM academic_years WHERE id = ?')->execute([$yearId]);
$db->prepare('INSERT INTO academic_years (id, name, is_active, locked, status) VALUES (?, ?, 1, 0, ?)')->execute([$yearId, '2099-2100', 'active']);
$account = $db->prepare('INSERT INTO accounting_accounts (code, name_ar, type, is_control_account) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), is_control_account = 0');
$account->execute(['MANUAL-DR-990119', 'مدين اختبار قيد يدوي', 'expense']); $debitId = (int) $db->lastInsertId();
$account->execute(['MANUAL-CR-990119', 'دائن اختبار قيد يدوي', 'asset']); $creditId = (int) $db->lastInsertId();
$audit = new class implements AuditEventWriter { public array $events = []; public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void { $this->events[] = $action; } };
$factory = new FinanceServiceFactory($db, $audit);
$periodId = $factory->financePeriodService()->createPeriod($yearId, 'فترة اختبار القيد اليدوي', '2099-09-01', '2100-06-30', 1);
$service = $factory->manualJournalService();
$originalKey = md5('manual-original-990119');
$reversalKey = md5('manual-reversal-990119');
$lines = [['account_id' => $debitId, 'debit' => '250.00', 'credit' => '0.00', 'description' => 'مصروف'], ['account_id' => $creditId, 'debit' => '0.00', 'credit' => '250.00', 'description' => 'نقدية']];
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; } };
try {
    try { $service->post($yearId, $periodId, '2099-10-01', $lines, 'قيد', 1, 1, $originalKey); $assert(false, 'same actor cannot approve manual journal'); } catch (RuntimeException) { $assert(true, 'same actor refused'); }
    try { $service->post($yearId, $periodId, '2099-10-01', [['account_id' => $debitId, 'debit' => '250.00', 'credit' => '0.00'], ['account_id' => $creditId, 'debit' => '0.00', 'credit' => '200.00']], 'غير متوازن', 1, 2, md5('bad-manual')); $assert(false, 'unbalanced journal rejected'); } catch (InvalidArgumentException) { $assert(true, 'unbalanced refused'); }
    $originalId = $service->post($yearId, $periodId, '2099-10-01', $lines, 'قيد يدوي متوازن', 1, 2, $originalKey);
    try { $service->reverse($originalKey, '2099-10-02', 'تصحيح', 3, 3, $reversalKey); $assert(false, 'same actor cannot approve reversal'); } catch (RuntimeException) { $assert(true, 'same actor reversal refused'); }
    $reversalId = $service->reverse($originalKey, '2099-10-02', 'تصحيح موثق', 3, 4, $reversalKey);
    $row = $db->query('SELECT source_type, subledger_transaction_id, reversal_of FROM accounting_journal_entries WHERE id = ' . $reversalId)->fetch(PDO::FETCH_ASSOC);
    $assert((string) $row['source_type'] === 'manual_reversal' && $row['subledger_transaction_id'] === null && (int) $row['reversal_of'] === $originalId, 'manual reversal is pure GL and linked to immutable original');
    $net = (string) $db->query('SELECT COALESCE(SUM(debit-credit),0) FROM accounting_journal_lines WHERE journal_entry_id IN (' . $originalId . ',' . $reversalId . ')')->fetchColumn();
    $assert($net === '0.00', 'original plus reversal returns GL total to zero');
    $assert(in_array('finance_manual_journal_post', $audit->events, true) && in_array('finance_manual_journal_reverse', $audit->events, true), 'manual post and reversal audited');
} finally {
    $db->exec("DELETE FROM accounting_journal_lines WHERE journal_entry_id IN (SELECT id FROM accounting_journal_entries WHERE source_idempotency_key IN ('{$originalKey}','{$reversalKey}'))");
    $db->exec("DELETE FROM accounting_journal_entries WHERE source_idempotency_key = '{$reversalKey}'");
    $db->exec("DELETE FROM accounting_journal_entries WHERE source_idempotency_key = '{$originalKey}'");
    $db->prepare('DELETE FROM finance_periods WHERE academic_year_id = ?')->execute([$yearId]);
    $db->prepare('DELETE FROM academic_years WHERE id = ?')->execute([$yearId]);
    $db->prepare('DELETE FROM accounting_accounts WHERE id IN (?, ?)')->execute([$debitId, $creditId]);
}
if ($failures > 0) { exit(1); }
echo "Finance manual-journal integration test PASSED on {$database}.\n";
