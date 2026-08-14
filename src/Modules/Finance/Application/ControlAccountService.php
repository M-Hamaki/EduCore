<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\Repositories\ControlAccountRepository;
use EduCore\Modules\Finance\Contracts\Repositories\SubledgerLineRepository;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use RuntimeException;

final class ControlAccountService
{
    public function __construct(
        private ControlAccountRepository $controlAccounts,
        private SubledgerLineRepository $subledgerLines
    ) {
    }

    /**
     * @return array{subledger_balance:string, gl_balance:string, difference:string, within_tolerance:bool}
     */
    public function reconcile(
        string $partyType,
        string $bucketCode
    ): array {
        $control = $this->controlAccounts->findControlAccount($partyType);
        if ($control === null) {
            throw new RuntimeException('No control account is configured for ' . $partyType . '.');
        }

        $subledger = SignedMoneyDelta::fromDecimalString(
            $this->subledgerLines->sumForPartyTypeBucket($partyType, $bucketCode)
        );
        $gl = SignedMoneyDelta::fromDecimalString(
            $this->controlAccounts->glBalance((int) $control['account_id'])
        );
        if ((string) ($control['normal_balance'] ?? 'debit') === 'credit') {
            $gl = $gl->negate();
        }
        $difference = $subledger->add($gl->negate());
        $tolerance = SignedMoneyDelta::fromDecimalString(
            (string) ($control['reconciliation_tolerance'] ?? '0.00')
        );

        return [
            'subledger_balance' => $subledger->toDatabaseString(),
            'gl_balance' => $gl->toDatabaseString(),
            'difference' => $difference->toDatabaseString(),
            'within_tolerance' => abs($difference->toMinorUnits()) <= abs($tolerance->toMinorUnits()),
        ];
    }

    public function assertPureGlLinesAllowed(array $lines): void
    {
        foreach ($lines as $line) {
            if ($this->controlAccounts->isControlAccount((int) ($line['account_id'] ?? 0))) {
                throw new RuntimeException('Pure GL operations cannot post directly to a party control account.');
            }
        }
    }
}
