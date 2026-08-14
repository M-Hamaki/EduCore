<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts;

/** Read-only boundary for legacy finance cutover data. */
interface LegacyFinanceSource
{
    /** @return iterable<array<string,mixed>> */
    public function studentFees(): iterable;

    /** @return iterable<array<string,mixed>> */
    public function paymentsForStudentFee(int $studentFeeId): iterable;

    /** Balances not already represented by a legacy student_fees row. @return iterable<array<string,mixed>> */
    public function priorYearBalances(): iterable;
}
