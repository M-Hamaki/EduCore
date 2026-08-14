<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/env_loader.php';

$options = getopt('', ['database:']);
$testDb = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb) || $testDb === 'educore') {
    fwrite(STDERR, "FAILED: --database must name an isolated *_test database.\n");
    exit(1);
}
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; } };

try {
    $db = new PDO('mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4', (string) env('DB_USER', 'root'), (string) env('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $invalidStatus = (int) $db->query("SELECT COUNT(*) FROM accounting_journal_entries WHERE status NOT IN ('draft','posted')")->fetchColumn();
    $assert($invalidStatus === 0, 'journal status is draft or posted only');
    $unbalanced = (int) $db->query('SELECT COUNT(*) FROM (SELECT je.id FROM accounting_journal_entries je JOIN accounting_journal_lines jl ON jl.journal_entry_id = je.id WHERE je.status = \'posted\' GROUP BY je.id HAVING ROUND(SUM(jl.debit),2) <> ROUND(SUM(jl.credit),2)) x')->fetchColumn();
    $assert($unbalanced === 0, 'every posted journal balances debit and credit');
    $reversals = $db->query('SELECT id, reversal_of, status FROM accounting_journal_entries WHERE reversal_of IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reversals as $reversal) {
        $originalStatus = $db->query('SELECT status FROM accounting_journal_entries WHERE id = ' . (int) $reversal['reversal_of'])->fetchColumn();
        $assert($originalStatus === 'posted' && (string) $reversal['status'] === 'posted', 'original and reversal both remain posted');
        $stmt = $db->prepare('SELECT account_id, ROUND(SUM(debit-credit),2) AS net FROM accounting_journal_lines WHERE journal_entry_id IN (?, ?) GROUP BY account_id HAVING ROUND(SUM(debit-credit),2) <> 0');
        $stmt->execute([(int) $reversal['reversal_of'], (int) $reversal['id']]);
        $assert($stmt->fetchAll(PDO::FETCH_ASSOC) === [], 'original plus reversal has zero net effect per account');
    }
} catch (Throwable $exception) { fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n"); ++$failures; }
if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s).\n"); exit(1); }
echo "Finance GL balance and reversal contract PASSED on {$testDb}.\n";
