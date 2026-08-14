# Quickstart: Finance Ledger & Payroll

> This quickstart validates the feature on an isolated database whose name ends in `_test`. It MUST NOT touch the `educore` database. It does NOT run migrations or write code in this phase; it documents the verification path for the implementation phase. Examples use PowerShell (not bash).

## Prerequisites

1. The isolated implementation worktree at `<feature-worktree>` on branch `feature/003-finance-ledger-payroll`.
2. PHP 8.0+ available as `php` on `PATH`.
3. An isolated test database whose name ends in `_test` (verified locally as `educore_finance_codex_test`). Every integration command passes the database explicitly; missing/non-`*_test` values are failures, never accepted skips.
4. `storage/private/db_backups` is NOT used as a truth source. Schema is inspected only from the `*_test` database. No personal data is displayed.
5. Currency is EGP; money is integer piaster minor units in PHP / `DECIMAL(14,2)` in DB.

## Verification Steps (implementation phase)

### Step 0 — Resync workflow (BLOCKS all implementation)

```powershell
# 1) Commit the staged spec package only.
# 2) Inventory the exact tracked/untracked/deleted local state at `<project-root>`.
# 3) Create a manifest and non-destructively apply tracked changes plus required untracked
#    source/docs/tests/migrations into this isolated worktree.
# 4) Exclude .env, secrets, storage/private, backups, caches, logs, generated outputs, scratch/.
# 5) Review the full adopted diff and commit it separately:
#    baseline: adopt current local main state
# 6) Prove the source checkout was not modified by adoption.
# origin/main is not the local state. No stash/reset/clean/force.
```

Expected: clean feature worktree with separate spec and baseline commits, a complete manifest, zero unsafe artifacts, no unresolved conflicts/dependencies, and unchanged local `main`.

### Step 1 — Schema on `*_test` only

```powershell
# From the feature worktree root
php tools/run_finance_test_migrations.php --database=educore_finance_codex_test
# Confirm new finance tables exist on educore_finance_codex_test only
```

Expected: finance core, fee plans, student accounts/charges/installments, discounts (rules/awards/applications), receipts/allocations/unapplied_credits/adjustments/refunds, receipt number sequences, staff compensation contracts + components, payroll runs/items/components, advances, cashboxes/settlements, GL (accounts/cost centers/journal entries/lines/account-mapping policies/control accounts), and budget tables exist on `*_test` and NOT on `educore`.

Run the acceptance suite after migrations:

```powershell
php tests/run_finance_suite.php --database=educore_finance_codex_test
```

Expected: `FINANCE_TEST_FILES=77 FAILED=0`. Individual integration tests below also require `--database=educore_finance_codex_test`; pure source/value-object tests ignore the extra option.

The final migration, `20260728_finance_default_configuration.php`, seeds the minimum safe posting configuration only when no user-managed mapping lines exist: 13 chart-of-account rows, `MAIN` cashbox, an open period for the active academic year, 19 operation mappings, and four student/staff control accounts. `tests/finance_default_configuration_integration_test.php` proves idempotency and deterministic resolution.

### Step 2 — Money value object

```powershell
php tests/finance_money_contract_test.php
```

Expected: integer piaster minor units, half-up rounding at presentation, no float drift; passes.

### Step 3 — Idempotent receipt + per-cashbox/year numbering

```powershell
php tests/finance_receipt_idempotency_contract_test.php
```

Expected: posting a receipt, then retrying with the same `idempotency_key`, returns the original and creates zero duplicates; the receipt number follows the per-cashbox/per-year sequence.

### Step 4 — Reversal-only cancellation + unapplied_credit

```powershell
php tests/finance_receipt_reversal_contract_test.php
```

Expected: cancelling a posted receipt leaves the original intact and creates a reversing entry; a hard `DELETE` is refused; an overpayment becomes an independent `unapplied_credit` movement.

### Step 5 — Period close lock (maker-checker reopen)

```powershell
php tests/finance_period_close_contract_test.php
```

Expected: backdated writes into a closed period are rejected; a recorded reopen with `period_reopen` permission (maker-checker) is logged and allows writes.

### Step 6 — Server-computed payroll + reversal + separate settlements

```powershell
php tests/finance_payroll_calculation_contract_test.php
```

Expected: `gross`/`net` are computed server-side from `staff_compensation_contract_components`; a tampered client-sent net is ignored; a posted payslip is unchanged after a contract change with a new `effective_from`; a retroactive difference is a separate settlement in an open period; `payroll_runs` reversal uses `reversal_of`.

### Step 7 — Reconciliation (unified equation)

```powershell
php tests/finance_reconciliation_contract_test.php
```

Expected: for each student, `outstanding_due = SUM(amount_delta WHERE bucket_code=STUDENT_OUTSTANDING_DUE)`, `unapplied_credit = SUM(amount_delta WHERE bucket_code=STUDENT_UNAPPLIED_CREDIT)`, and `net_account_position = outstanding_due − unapplied_credit`, all from posted `finance_subledger_lines`, matching the legacy balance. For staff, the same lifetime `STAFF_GLOBAL` account remains queryable after compensation-contract changes.

### Step 8 — GL double-entry + control-account reconciliation

```powershell
php tests/finance_gl_balance_contract_test.php
php tests/finance_control_account_reconciliation_contract_test.php
```

Expected: per journal entry, `SUM(debit) = SUM(credit)`; every posted Student/Payroll/Cashbox operation created a GL entry in the same transaction; student/staff sub-ledger balances reconcile to GL control accounts.

### Step 9 — Budget actuals from GL only

```powershell
php tests/finance_budget_actuals_contract_test.php
```

Expected: budget `actual_amount` comes EXCLUSIVELY from posted GL journal entries; `admin/school_budget.php` is NOT used.

### Step 10 — BusSubscriptionQuery + prior-year debt migration

```powershell
php tests/finance_bus_subscription_integration_test.php
php tests/finance_prior_year_debt_migration_test.php
```

Expected: bus charges use `BusSubscriptionQuery` (not the legacy string match); prior-year debt migrates to an `opening_balance` movement linked to the original year without deleting/modifying the old record.

### Step 11 — Maker-checker

```powershell
php tests/finance_maker_checker_contract_test.php
```

Expected: the creator cannot approve the same sensitive operation (receipt reversal, refund, write-off, manual journal, import posting, payroll approval/payment, period reopen, manual/exception discount).

### Step 12 — Invariants & unified sub-ledger

```powershell
php tests/finance_invariants_contract_test.php
php tests/finance_subledger_balances_contract_test.php
```

Expected: `SUM(installment.net_amount)=charge.net_due`; `SUM(discount_applications)=charge.discount_amount`; `allocation<=installment.remaining_due`; `receipt.amount=SUM(allocations)+SUM(unapplied_credit)`; `unapplied_credit_application<=credit.remaining`; domain totals match sub-ledger deltas; `original + reversal = 0`; balances come only from `finance_subledger_lines.amount_delta`; every party-affecting transaction has one linked GL journal through unique `subledger_transaction_id`, while a pure GL voucher has one journal and no sub-ledger transaction.

### Step 12A — Receipt granularity (one receipt = one transaction + one GL journal)

```powershell
php tests/finance_receipt_granularity_contract_test.php
```

Expected: a receipt allocated across 3 installments + overpayment produces exactly ONE sub-ledger transaction (multi-line: 3 negative `STUDENT_OUTSTANDING_DUE` lines + 1 positive `STUDENT_UNAPPLIED_CREDIT` line) + ONE GL journal (same `source_idempotency_key`). All allocation/unapplied-credit rows share the SAME `subledger_transaction_id`.

### Step 12B — Staff ledger rules (payroll/advance/payment)

```powershell
php tests/finance_staff_ledger_rules_contract_test.php
php tests/finance_staff_control_account_reconciliation_contract_test.php
```

Expected: payroll posting and retroactive-settlement posting affect `STAFF_PAYROLL_PAYABLE`; payroll payment clears that bucket. Advance issue adds to `STAFF_ADVANCE_RECEIVABLE`; cash repayment and payroll deduction reduce it with distinct GL mappings. Advance 1000 + repayment 200 + approved write-off 800 = zero; write-off 1000 is rejected; write-off is not a reversal of the issue. The same `STAFF_GLOBAL` account survives contract changes; staff ledger reads only sub-ledger lines and staff ↔ GL reconciliation passes.

### Step 12C — Discount concurrency (MariaDB-safe activation)

```powershell
php tests/finance_discount_concurrency_contract_test.php
```

Expected: two simultaneous activations of the same policy scope fail (activation transaction + `FOR UPDATE`); only one active version per scope; MariaDB-safe (no partial index).

### Step 12D — Vouchers

```powershell
php tests/finance_voucher_gl_contract_test.php
```

Expected: `SUM(debit)=SUM(credit)` per voucher; vouchers create no party sub-ledger transaction; expense/other-income has one holding cashbox; cash transfer requires positive amount and distinct source/destination cashboxes; same-source transfer is rejected; GL mapping resolves deterministically.

### Step 13 — Upload safety (FileUploadGuard)

```powershell
php tests/finance_upload_safety_contract_test.php
php tools\audit_upload_policy.php --strict
composer upload-policy-audit
```

Expected: invalid MIME, dangerous double extension, size/upload errors, collision-resistant names, authorization, file/DB rollback all pass; `upload_policy_manifest.json` classifies the import path; `composer upload-policy-audit` passes.

### Step 14 — Role-footer / form-safety / undo-toast coverage

```powershell
php tests/finance_role_footer_coverage_contract_test.php
```

Expected: every new data-entry page uses shared `admin_footer`/role footer, `form-safety.js`, `undo-toast.js`; no page-local toast/draft/logger/competing storage key; role-coverage contract test passes.

## Gates (before any commit)

```powershell
php -l <each touched file>
composer audit-write-coverage   # MUST end AUDIT_REVIEW_REQUIRED=0
composer architecture-audit      # zero regressions
composer admin-ui-audit          # if admin pages touched — UI_AUDIT_ISSUES=0
composer upload-policy-audit     # if uploads touched — must pass
composer quality                 # full CI gate
git diff --check
```

## Retention

- No financial record is auto-deleted until a formal retention policy is approved.
- Temporary export files are deleted after 24 hours.

## Stop Conditions

- Stop if baseline adoption finds an actual conflict, unsafe/unclassified file, secret, or unresolvable dependency.
- Stop if an isolated `*_test` database cannot be proven.
- Stop if a migration precondition or rollback cannot be established.
- Stop if reconciliation cannot be proven.
- Stop if a new admin page does not follow `admin/ui_preview.php` and `AGENTS.md` UI rules.
