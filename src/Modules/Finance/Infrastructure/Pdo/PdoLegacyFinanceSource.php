<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\LegacyFinanceSource;
use PDO;

final class PdoLegacyFinanceSource implements LegacyFinanceSource
{
    public function __construct(private PDO $db)
    {
    }

    public function studentFees(): iterable
    {
        $stmt = $this->db->query(
            'SELECT id, student_id, academic_year, final_amount, balance, created_at
             FROM student_fees ORDER BY id'
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    public function paymentsForStudentFee(int $studentFeeId): iterable
    {
        $stmt = $this->db->prepare(
            'SELECT id, student_fee_id, student_id, amount, payment_date, payment_method, receipt_number, notes, received_by
             FROM fee_payments WHERE student_fee_id = ? ORDER BY payment_date, id'
        );
        $stmt->execute([$studentFeeId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    public function priorYearBalances(): iterable
    {
        $stmt = $this->db->query(
            'SELECT h.id, h.student_id, h.academic_year_id, h.balance, h.created_at
             FROM student_fee_balances_history h
             JOIN academic_years ay ON ay.id = h.academic_year_id
             WHERE h.carried_forward = 1 AND h.balance > 0
               AND NOT EXISTS (
                   SELECT 1 FROM student_fees sf
                   WHERE sf.student_id = h.student_id AND sf.academic_year = ay.name
               )
             ORDER BY h.id'
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }
}
