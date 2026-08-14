<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Queries;

interface FinanceReconciliationQuery
{
    /** @return list<array<string,mixed>> */
    public function partyJournalLinkAnomalies(): array;

    /** @return list<array<string,mixed>> */
    public function pureGlLinkAnomalies(): array;

    /** @return list<array<string,mixed>> */
    public function accountScopeAnomalies(): array;

    /** @return list<array<string,mixed>> */
    public function domainBucketMismatches(): array;
}
