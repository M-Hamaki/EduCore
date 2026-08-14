<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\StudentFinanceAccountRepository;
use PDO;

final class PdoStudentFinanceAccountRepository implements StudentFinanceAccountRepository
{
    public function __construct(private PDO $db) {}
    public function findOrCreate(int $studentId, int $academicYearId, int $subledgerAccountId): int
    {
        $stmt = $this->db->prepare('SELECT id FROM finance_student_accounts WHERE student_id = ? AND academic_year_id = ? LIMIT 1');
        $stmt->execute([$studentId, $academicYearId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $this->db->prepare('UPDATE finance_student_accounts SET subledger_account_id = COALESCE(subledger_account_id, ?) WHERE id = ?')->execute([$subledgerAccountId, $id]);
            return (int) $id;
        }
        try {
            $this->db->prepare('INSERT INTO finance_student_accounts (student_id, academic_year_id, currency, status, subledger_account_id) VALUES (?, ?, ?, ?, ?)')->execute([$studentId, $academicYearId, 'EGP', 'active', $subledgerAccountId]);
            return (int) $this->db->lastInsertId();
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) { throw $exception; }
            $stmt->execute([$studentId, $academicYearId]);
            return (int) $stmt->fetchColumn();
        }
    }
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_student_accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Student finance account locks require an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_student_accounts WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
