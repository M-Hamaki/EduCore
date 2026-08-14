<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\ControlAccountRepository;
use PDO;

final class PdoControlAccountRepository implements ControlAccountRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findControlAccount(string $subLedgerType): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT control_accounts.*, accounts.code, accounts.name_ar
             FROM accounting_control_accounts control_accounts
             INNER JOIN accounting_accounts accounts ON accounts.id = control_accounts.account_id
             WHERE control_accounts.sub_ledger_type = ? LIMIT 1'
        );
        $stmt->execute([$subLedgerType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function glBalance(int $accountId): string
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(lines.debit - lines.credit), 0)
             FROM accounting_journal_lines lines
             INNER JOIN accounting_journal_entries entries ON entries.id = lines.journal_entry_id
             WHERE lines.account_id = ? AND entries.status = ?'
        );
        $stmt->execute([$accountId, 'posted']);

        return (string) $stmt->fetchColumn();
    }

    public function isControlAccount(int $accountId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM accounting_control_accounts WHERE account_id = ?');
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
