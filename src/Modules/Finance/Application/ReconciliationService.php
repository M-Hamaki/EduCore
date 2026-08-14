<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\Repositories\ControlAccountRepository;
use EduCore\Modules\Finance\Contracts\Queries\FinanceReconciliationQuery;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;

final class ReconciliationService
{
    public function __construct(
        private SubledgerPostingService $postingService,
        private ControlAccountRepository $controlAccounts,
        private ?FinanceReconciliationQuery $integrity = null
    ) {
    }

    public function studentBalances(int $subledgerAccountId): array
    {
        $outstanding = $this->postingService->bucketBalance($subledgerAccountId, 'STUDENT_OUTSTANDING_DUE');
        $unapplied = $this->postingService->bucketBalance($subledgerAccountId, 'STUDENT_UNAPPLIED_CREDIT');
        return [
            'outstanding_due' => $outstanding,
            'unapplied_credit' => $unapplied,
            'net_account_position' => $this->difference($outstanding, $unapplied),
        ];
    }

    public function staffBalances(int $subledgerAccountId): array
    {
        return [
            'payroll_payable' => $this->postingService->bucketBalance($subledgerAccountId, 'STAFF_PAYROLL_PAYABLE'),
            'advance_receivable' => $this->postingService->bucketBalance($subledgerAccountId, 'STAFF_ADVANCE_RECEIVABLE'),
            'settlement' => $this->postingService->bucketBalance($subledgerAccountId, 'STAFF_SETTLEMENT'),
        ];
    }

    public function reconcileControlAccount(int $subledgerAccountId, string $bucketCode, int $glControlAccountId): array
    {
        $subledger = $this->postingService->bucketBalance($subledgerAccountId, $bucketCode);
        $gl = $this->controlAccounts->glBalance($glControlAccountId);
        $difference = $this->difference($subledger, $gl);
        return ['subledger_balance' => $subledger, 'gl_balance' => $gl, 'difference' => $difference, 'matched' => SignedMoneyDelta::fromDecimalString($difference)->isZero()];
    }

    /** @return array{party_gl_links:list<array<string,mixed>>,pure_gl_links:list<array<string,mixed>>,account_scopes:list<array<string,mixed>>,domain_bucket_totals:list<array<string,mixed>>,is_clean:bool} */
    public function integrityReport(): array
    {
        if ($this->integrity === null) {
            throw new \RuntimeException('Finance reconciliation query is unavailable.');
        }
        $report = [
            'party_gl_links' => $this->integrity->partyJournalLinkAnomalies(),
            'pure_gl_links' => $this->integrity->pureGlLinkAnomalies(),
            'account_scopes' => $this->integrity->accountScopeAnomalies(),
            'domain_bucket_totals' => $this->integrity->domainBucketMismatches(),
        ];
        $report['is_clean'] = $report['party_gl_links'] === []
            && $report['pure_gl_links'] === []
            && $report['account_scopes'] === []
            && $report['domain_bucket_totals'] === [];
        return $report;
    }

    private function difference(string $left, string $right): string
    {
        return SignedMoneyDelta::fromMinorUnits(
            SignedMoneyDelta::fromDecimalString($left)->toMinorUnits()
            - SignedMoneyDelta::fromDecimalString($right)->toMinorUnits()
        )->toDatabaseString();
    }
}
