<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\PayrollRunRepository;
use PDO;

final class PdoPayrollRunRepository implements PayrollRunRepository
{
    public function __construct(private PDO $db) {}
    public function create(int $payrollPeriodId, int $versionNumber, int $createdBy, bool $isSettlement, string $batchId): int
    {
        $this->db->prepare('INSERT INTO payroll_runs (payroll_period_id, version_number, status, created_by, is_settlement, batch_id) VALUES (?, ?, ?, ?, ?, ?)')->execute([$payrollPeriodId, $versionNumber, 'draft', $createdBy, $isSettlement ? 1 : 0, $batchId]);
        return (int) $this->db->lastInsertId();
    }
    public function nextVersion(int $payrollPeriodId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM payroll_runs WHERE payroll_period_id = ? FOR UPDATE');
        $stmt->execute([$payrollPeriodId]);
        return (int) $stmt->fetchColumn();
    }
    public function setStatus(int $runId, string $fromStatus, string $toStatus, int $actorId): void
    {
        $fieldSql = match ($toStatus) {
            'reviewed' => ', reviewed_by = ?, reviewed_at = NOW()',
            'approved' => ', approved_by = ?',
            default => '',
        };
        $params = [$toStatus];
        if ($fieldSql !== '') {
            $params[] = $actorId;
        }
        $params[] = $runId;
        $params[] = $fromStatus;
        $stmt = $this->db->prepare('UPDATE payroll_runs SET status = ?' . $fieldSql . ' WHERE id = ? AND status = ?');
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Payroll run state transition was rejected.');
        }
    }
    public function createItem(int $runId, int $staffId, string $contractSnapshotJson, string $gross, string $totalDeductions, string $net, ?int $subledgerTxId): int
    {
        $this->db->prepare('INSERT INTO payroll_run_items (payroll_run_id, staff_id, contract_snapshot_json, gross, total_deductions, net, status, payment_status, subledger_transaction_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$runId, $staffId, $contractSnapshotJson, $gross, $totalDeductions, $net, 'locked', 'unpaid', $subledgerTxId]);
        $itemId = (int) $this->db->lastInsertId();
        $this->db->prepare('UPDATE payroll_run_items SET payslip_ref_number = ? WHERE id = ?')
            ->execute([sprintf('PAY-%06d-%06d', $runId, $itemId), $itemId]);
        return $itemId;
    }
    public function addItemComponent(int $itemId, int $componentId, string $amount, string $direction): void
    {
        $this->db->prepare('INSERT INTO payroll_item_components (payroll_run_item_id, payroll_component_id, amount, direction) VALUES (?, ?, ?, ?)')->execute([$itemId, $componentId, $amount, $direction]);
    }
    public function post(int $runId, int $postedBy): void
    {
        $this->db->prepare('UPDATE payroll_runs SET status = ?, posted_at = NOW(), posted_by = ? WHERE id = ? AND status = ?')->execute(['posted', $postedBy, $runId, 'approved']);
    }
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT pr.*, pp.start_date AS payroll_start_date, pp.end_date AS payroll_end_date, pp.pay_date, fp.status AS finance_period_status FROM payroll_runs pr JOIN payroll_periods pp ON pp.id = pr.payroll_period_id JOIN finance_periods fp ON fp.id = pp.finance_period_id WHERE pr.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function lockRun(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Payroll run locking requires an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT pr.*, pp.start_date AS payroll_start_date, pp.end_date AS payroll_end_date, pp.pay_date, fp.status AS finance_period_status FROM payroll_runs pr JOIN payroll_periods pp ON pp.id = pr.payroll_period_id JOIN finance_periods fp ON fp.id = pp.finance_period_id WHERE pr.id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findRunByReversalOf(int $originalRunId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payroll_runs WHERE reversal_of = ? LIMIT 1');
        $stmt->execute([$originalRunId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function createReversalRun(array $originalRun, int $versionNumber, int $createdBy, string $batchId): int
    {
        $this->db->prepare('INSERT INTO payroll_runs (payroll_period_id, version_number, status, batch_id, created_by, posted_at, posted_by, approved_by, reversal_of, is_settlement) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)')
            ->execute([(int) $originalRun['payroll_period_id'], $versionNumber, 'posted', $batchId, $createdBy, $createdBy, $createdBy, (int) $originalRun['id'], (int) $originalRun['is_settlement']]);
        return (int) $this->db->lastInsertId();
    }
    public function itemsForRun(int $runId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payroll_run_items WHERE payroll_run_id = ? ORDER BY id');
        $stmt->execute([$runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function componentsForItem(int $itemId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payroll_item_components WHERE payroll_run_item_id = ? ORDER BY id');
        $stmt->execute([$itemId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function createReversalItem(int $runId, array $originalItem, string $snapshotJson): int
    {
        $negative = static fn (string $amount): string => \EduCore\Modules\Finance\Domain\SignedMoneyDelta::fromDecimalString($amount)->negate()->toDatabaseString();
        $this->db->prepare('INSERT INTO payroll_run_items (payroll_run_id, staff_id, contract_snapshot_json, gross, total_deductions, net, status, reversal_of, payslip_ref_number, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$runId, (int) $originalItem['staff_id'], $snapshotJson, $negative((string) $originalItem['gross']), $negative((string) $originalItem['total_deductions']), $negative((string) $originalItem['net']), 'reversed', (int) $originalItem['id'], 'REV-' . (string) $originalItem['payslip_ref_number'], 'unpaid']);
        return (int) $this->db->lastInsertId();
    }
    public function hasPostedPaymentsForRun(int $runId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM (SELECT pp.payroll_run_item_id FROM payroll_payments pp JOIN payroll_run_items pri ON pri.id = pp.payroll_run_item_id WHERE pri.payroll_run_id = ? AND pp.status = 'posted' GROUP BY pp.payroll_run_item_id HAVING SUM(CASE WHEN pp.reversal_of IS NULL THEN pp.amount ELSE -pp.amount END) <> 0) active_payments");
        $stmt->execute([$runId]);
        return (int) $stmt->fetchColumn() > 0;
    }
    public function markReversed(int $runId, int $reversedBy): void
    {
        $stmt = $this->db->prepare("UPDATE payroll_runs SET status = 'reversed', reversed_at = NOW(), reversed_by = ? WHERE id = ? AND status = 'posted'");
        $stmt->execute([$reversedBy, $runId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Payroll run reversal state change was rejected.');
        }
    }
    public function markItemReversed(int $itemId): void
    {
        $this->db->prepare("UPDATE payroll_run_items SET status = 'reversed' WHERE id = ? AND status = 'locked'")->execute([$itemId]);
    }
    public function findItem(int $itemId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payroll_run_items WHERE id = ? LIMIT 1');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function lockItem(int $itemId): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Payroll item locking requires an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM payroll_run_items WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findItemByRunAndStaff(int $runId, int $staffId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payroll_run_items WHERE payroll_run_id = ? AND staff_id = ? LIMIT 1');
        $stmt->execute([$runId, $staffId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function linkItemPosting(int $itemId, int $subledgerTransactionId): void
    {
        $this->db->prepare('UPDATE payroll_run_items SET subledger_transaction_id = ? WHERE id = ? AND subledger_transaction_id IS NULL')->execute([$subledgerTransactionId, $itemId]);
    }
    public function createPayment(int $itemId, int $cashboxId, string $amount, string $paymentMethod, int $postedBy, int $approvedBy, string $requestId): int
    {
        $this->db->prepare('INSERT INTO payroll_payments (payroll_run_item_id, cashbox_id, amount, payment_method, status, posted_at, posted_by, approved_by, request_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)')->execute([$itemId, $cashboxId, $amount, $paymentMethod, 'posted', $postedBy, $approvedBy, $requestId]);
        return (int) $this->db->lastInsertId();
    }
    public function findPaymentByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payroll_payments WHERE request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function lockPayment(int $paymentId): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Payroll payment locking requires an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM payroll_payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findPaymentByReversalOf(int $paymentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payroll_payments WHERE reversal_of = ? LIMIT 1');
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function createPaymentReversal(array $originalPayment, int $postedBy, int $approvedBy, string $requestId): int
    {
        $this->db->prepare('INSERT INTO payroll_payments (payroll_run_item_id, cashbox_id, amount, payment_method, status, posted_at, posted_by, approved_by, reversal_of, batch_id, request_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)')
            ->execute([(int) $originalPayment['payroll_run_item_id'], (int) $originalPayment['cashbox_id'], (string) $originalPayment['amount'], (string) $originalPayment['payment_method'], 'posted', $postedBy, $approvedBy, (int) $originalPayment['id'], $originalPayment['batch_id'] ?? null, $requestId]);
        return (int) $this->db->lastInsertId();
    }
    public function linkPaymentPosting(int $paymentId, int $subledgerTransactionId): void
    {
        $this->db->prepare('UPDATE payroll_payments SET subledger_transaction_id = ? WHERE id = ? AND subledger_transaction_id IS NULL')->execute([$subledgerTransactionId, $paymentId]);
    }
    public function paidAmountForItem(int $itemId): string
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(CASE WHEN reversal_of IS NULL THEN amount ELSE -amount END), 0) FROM payroll_payments WHERE payroll_run_item_id = ? AND status = 'posted'");
        $stmt->execute([$itemId]);
        return (string) $stmt->fetchColumn();
    }
    public function markItemPaid(int $itemId): void
    {
        $this->db->prepare('UPDATE payroll_run_items SET payment_status = ?, status = ? WHERE id = ?')->execute(['paid', 'paid', $itemId]);
    }
    public function refreshItemPaymentStatus(int $itemId): void
    {
        $stmt = $this->db->prepare("UPDATE payroll_run_items SET payment_status = CASE WHEN net = (SELECT COALESCE(SUM(CASE WHEN reversal_of IS NULL THEN amount ELSE -amount END), 0) FROM payroll_payments WHERE payroll_run_item_id = ? AND status = 'posted') THEN 'paid' ELSE 'unpaid' END, status = CASE WHEN net = (SELECT COALESCE(SUM(CASE WHEN reversal_of IS NULL THEN amount ELSE -amount END), 0) FROM payroll_payments WHERE payroll_run_item_id = ? AND status = 'posted') THEN 'paid' ELSE 'locked' END WHERE id = ? AND reversal_of IS NULL");
        $stmt->execute([$itemId, $itemId, $itemId]);
    }
    public function payslip(int $itemId): ?array
    {
        $item = $this->findItem($itemId);
        if ($item === null) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM payroll_item_components WHERE payroll_run_item_id = ? ORDER BY id');
        $stmt->execute([$itemId]);
        $item['components'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->db->prepare('SELECT * FROM payroll_payments WHERE payroll_run_item_id = ? ORDER BY id');
        $stmt->execute([$itemId]);
        $item['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $item;
    }
}
