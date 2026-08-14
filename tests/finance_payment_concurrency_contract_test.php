<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$receiptService = (string) file_get_contents($root . '/src/Modules/Finance/Application/ReceiptService.php');
$receiptRepository = (string) file_get_contents($root . '/src/Modules/Finance/Infrastructure/Pdo/PdoReceiptRepository.php');
$cashboxRepository = (string) file_get_contents($root . '/src/Modules/Finance/Infrastructure/Pdo/PdoCashboxRepository.php');
$chargeRepository = (string) file_get_contents($root . '/src/Modules/Finance/Infrastructure/Pdo/PdoChargeRepository.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260723_finance_collection.php');

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$cashboxLock = strpos($receiptService, '$this->cashboxes->lockById($cashboxId)');
$idempotencyRead = strpos($receiptService, '$this->receipts->findByIdempotencyKey($idempotencyKey)', (int) $cashboxLock);
$assert($cashboxLock !== false && $idempotencyRead !== false && $cashboxLock < $idempotencyRead, 'cashbox row serializes concurrent receipt requests before the in-transaction idempotency read');
$assert(str_contains($receiptService, 'lockInstallmentRemainingDue'), 'receipt allocations lock each target installment before checking remaining due');
$assert(str_contains($cashboxRepository, 'FOR UPDATE') && str_contains($chargeRepository, 'FOR UPDATE'), 'PDO repositories use row locks for cashbox and installment concurrency boundaries');
$assert(str_contains($receiptRepository, 'finance_receipt_number_sequences') && str_contains($receiptRepository, 'FOR UPDATE'), 'receipt sequence allocation is atomic under a row lock');
$assert(str_contains($migration, 'UNIQUE KEY `uk_receipt_number_seq`') && str_contains($migration, 'UNIQUE KEY `uk_receipt_idempotency`'), 'database uniqueness is the final collision guard for sequence and request keys');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} payment concurrency contract failure(s).\n");
    exit(1);
}

require __DIR__ . '/finance_allocation_integration_test.php';
echo "Payment serialization and concurrency contract passed.\n";
