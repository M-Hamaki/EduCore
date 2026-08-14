<?php

declare(strict_types=1);

// Pure GL control-account bypass and voucher NULL-link behavior.
require __DIR__ . '/finance_voucher_gl_integration_test.php';
// Budget planning must remain outside both GL and party sub-ledgers.
require __DIR__ . '/finance_budget_actuals_contract_test.php';
