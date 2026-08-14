<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';

use EduCore\Modules\Finance\Application\ControlAccountService;
use EduCore\Modules\Finance\Contracts\Repositories\ControlAccountRepository;
use EduCore\Modules\Finance\Contracts\Repositories\SubledgerLineRepository;

$control = new class implements ControlAccountRepository {
    public string $normal = 'debit';
    public string $gl = '100.00';
    public string $tolerance = '0.00';
    public function findControlAccount(string $subLedgerType): ?array { return ['account_id' => 77, 'normal_balance' => $this->normal, 'reconciliation_tolerance' => $this->tolerance]; }
    public function glBalance(int $accountId): string { return $this->gl; }
    public function isControlAccount(int $accountId): bool { return $accountId === 77; }
};
$lines = new class implements SubledgerLineRepository {
    public string $balance = '100.00';
    public function findById(int $id): ?array { return null; }
    public function findByTransaction(int $transactionId): array { return []; }
    public function sumForBucket(int $subledgerAccountId, string $bucketCode): string { return $this->balance; }
    public function sumForPartyTypeBucket(string $partyType, string $bucketCode): string { return $this->balance; }
    public function sumForTransaction(int $transactionId): string { return '0.00'; }
};
$service = new ControlAccountService($control, $lines);
$matched = $service->reconcile('student', 'STUDENT_OUTSTANDING_DUE');
if (!$matched['within_tolerance'] || $matched['difference'] !== '0.00') { throw new RuntimeException('Debit-normal control account did not reconcile.'); }
$lines->balance = '-100.00'; $control->normal = 'credit';
$creditMatched = $service->reconcile('staff', 'STAFF_PAYROLL_PAYABLE');
if (!$creditMatched['within_tolerance'] || $creditMatched['gl_balance'] !== '-100.00') { throw new RuntimeException('Credit-normal control account sign was not normalized.'); }
$lines->balance = '-100.01'; $control->tolerance = '0.01';
if (!$service->reconcile('staff', 'STAFF_PAYROLL_PAYABLE')['within_tolerance']) { throw new RuntimeException('Configured piaster tolerance was not respected.'); }
$blocked = false;
try { $service->assertPureGlLinesAllowed([['account_id' => 77]]); } catch (RuntimeException) { $blocked = true; }
if (!$blocked) { throw new RuntimeException('Pure GL posting to a party control account was accepted.'); }
echo "Finance control-account reconciliation contract PASSED.\n";
