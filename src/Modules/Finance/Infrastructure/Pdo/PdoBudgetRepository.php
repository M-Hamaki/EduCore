<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\BudgetRepository;
use PDO;

final class PdoBudgetRepository implements BudgetRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findById(int $budgetId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_budgets WHERE id = ? LIMIT 1');
        $stmt->execute([$budgetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lockById(int $budgetId): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Budget locking requires an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_budgets WHERE id = ? FOR UPDATE');
        $stmt->execute([$budgetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findVersionById(int $versionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_budget_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(int $academicYearId, string $name, int $createdBy): int
    {
        $this->db->prepare('INSERT INTO finance_budgets (academic_year_id, name, status, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$academicYearId, $name, 'draft', $createdBy]);
        return (int) $this->db->lastInsertId();
    }

    public function createVersion(int $budgetId, int $createdBy): int
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Budget version creation requires an active transaction.');
        }
        $budget = $this->lockById($budgetId);
        if ($budget === null || !in_array((string) $budget['status'], ['draft', 'revised'], true)) {
            throw new \RuntimeException('Budget is not open for a new version.');
        }
        $draftStmt = $this->db->prepare("SELECT COUNT(*) FROM finance_budget_versions WHERE budget_id = ? AND status = 'draft'");
        $draftStmt->execute([$budgetId]);
        if ((int) $draftStmt->fetchColumn() > 0) {
            throw new \RuntimeException('Budget already has a draft version.');
        }
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM finance_budget_versions WHERE budget_id = ? FOR UPDATE');
        $stmt->execute([$budgetId]);
        $version = (int) $stmt->fetchColumn();
        $this->db->prepare('INSERT INTO finance_budget_versions (budget_id, version_number, status, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$budgetId, $version, 'draft', $createdBy]);
        return (int) $this->db->lastInsertId();
    }

    public function addLine(int $versionId, int $accountId, ?int $costCenterId, ?int $periodId, string $plannedAmount): int
    {
        $version = $this->findVersionById($versionId);
        if ($version === null || (string) $version['status'] !== 'draft') {
            throw new \RuntimeException('Budget lines can be added only to a draft version.');
        }
        $this->db->prepare('INSERT INTO finance_budget_lines (budget_version_id, account_id, cost_center_id, period_id, planned_amount) VALUES (?, ?, ?, ?, ?)')
            ->execute([$versionId, $accountId, $costCenterId, $periodId, $plannedAmount]);
        return (int) $this->db->lastInsertId();
    }

    public function actualForLine(int $accountId, ?int $costCenterId, ?int $periodId): string
    {
        $sql = 'SELECT COALESCE(SUM(jl.debit - jl.credit), 0) FROM accounting_journal_lines jl JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id WHERE jl.account_id = ? AND je.status = ?';
        $params = [$accountId, 'posted'];
        if ($costCenterId !== null) {
            $sql .= ' AND jl.cost_center_id = ?';
            $params[] = $costCenterId;
        }
        if ($periodId !== null) {
            $sql .= ' AND je.finance_period_id = ?';
            $params[] = $periodId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (string) $stmt->fetchColumn();
    }

    public function approve(int $budgetId, int $approvedBy): void
    {
        $stmt = $this->db->prepare('UPDATE finance_budgets SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND status = ?');
        $stmt->execute(['approved', $approvedBy, $budgetId, 'reviewed']);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Budget approval transition was rejected.');
        }
    }

    public function lock(int $budgetId): void
    {
        $stmt = $this->db->prepare('UPDATE finance_budgets SET status = ? WHERE id = ? AND status = ?');
        $stmt->execute(['locked', $budgetId, 'approved']);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Budget lock transition was rejected.');
        }
    }

    public function review(int $budgetId, int $reviewedBy): void
    {
        $stmt = $this->db->prepare("UPDATE finance_budgets SET status = 'reviewed' WHERE id = ? AND status IN ('draft','revised')");
        $stmt->execute([$budgetId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Budget review transition was rejected.');
        }
    }

    public function revise(int $budgetId): void
    {
        $stmt = $this->db->prepare("UPDATE finance_budgets SET status = 'revised' WHERE id = ? AND status = 'locked'");
        $stmt->execute([$budgetId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Only a locked budget can be revised.');
        }
    }

    public function activateLatestVersion(int $budgetId): int
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Budget version activation requires an active transaction.');
        }
        $stmt = $this->db->prepare("SELECT id FROM finance_budget_versions WHERE budget_id = ? AND status = 'draft' ORDER BY version_number DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$budgetId]);
        $versionId = (int) $stmt->fetchColumn();
        if ($versionId <= 0) {
            throw new \RuntimeException('Budget has no draft version to activate.');
        }
        $this->db->prepare("UPDATE finance_budget_versions SET status = 'superseded', superseded_at = NOW() WHERE budget_id = ? AND status = 'active'")->execute([$budgetId]);
        $this->db->prepare("UPDATE finance_budget_versions SET status = 'active' WHERE id = ? AND status = 'draft'")->execute([$versionId]);
        return $versionId;
    }
}
