# Implementation Plan: Finance Ledger & Payroll

**Branch**: `feature/003-finance-ledger-payroll` (implementation worktree at `<feature-worktree>`; Finance-only delta installed locally after explicit authorization; no push; authenticated browser smoke test pending) | **Date**: 2026-07-26 | **Spec**: [spec.md](spec.md)

## Summary

Build an incremental `src/Modules/Finance` module (fees, payments, salaries, full double-entry accounting, versioned budget, general vouchers) on one **generic party sub-ledger truth source** (`finance_subledger_accounts` + `finance_subledger_transactions` + `finance_subledger_lines`) where ALL student/staff balances are computed EXCLUSIVELY from posted `amount_delta` bucket lines. Student buckets: `STUDENT_OUTSTANDING_DUE`/`STUDENT_UNAPPLIED_CREDIT`; staff buckets: `STAFF_PAYROLL_PAYABLE`/`STAFF_ADVANCE_RECEIVABLE`. Student accounts are academic-year scoped; staff accounts use lifetime-stable `STAFF_GLOBAL`. A receipt is one source operation → one multi-line sub-ledger transaction + one linked GL journal. Every party-affecting operation has this 1:1 atomic link; a pure GL voucher/manual operation creates one GL journal and no fake party transaction; budget planning writes are audited but never posted to GL. Payroll run items are party-specific source operations grouped by `batch_id`. Reversals create new posted opposite movements. Staff advance cash repayment, payroll deduction, and write-off are distinct operations/mappings; write-off is independently approved and capped at remaining receivable. Retroactive payroll settlement posts into payroll payable and clears through the normal payment flow. Discount activation is MariaDB-safe. Cash transfers require distinct source/destination cashboxes. MANDATORY maker-checker, control-account reconciliation, FileUploadGuard/role-footer gates, and gradual legacy-page adapter cutover preserve existing URLs/POST/JSON. The resync workflow blocks implementation; no `stash`/`reset`/`clean`/`force`.

## Scope Boundaries

- **In scope**: `src/Modules/Finance` (Domain/Contracts/Application/Infrastructure), dated migrations with preconditions + rollback, **Full GL in v1** (chart of accounts, account-mapping policies, journal entries/lines, cost centers, control accounts, trial balance, P&L, cash flow, budget vs actual, cash/bank movement), fee plans/versions/installments, student accounts/contracts/charges/installments, discounts (rules/awards/applications), receipts/allocation/unapplied_credits/adjustments/refunds, staff compensation contracts + components, payroll runs/items/components (versions/settlements/reversal), staff advances and movements, cashboxes/banks/settlements, receipt numbering sequences, versioned budget model, vouchers, archive, import/export, finance dashboard and reports, all 30 new admin surfaces, legacy-page adapters (slice by slice after characterization tests), test scripts, ADR/docs. Every posted accounting operation creates exactly one GL entry in the same transaction; party-affecting operations additionally create exactly one linked sub-ledger transaction.
- **Out of scope**: rewriting the project; new framework/router/ORM/auth stack; modifying dirty `main` directly during baseline adoption; committing secrets/private backups/cache/log/scratch/generated artifacts; production deployment/push/migration; reading `storage/private/db_backups` as a truth source; deleting legacy tables in the same cutover release; guessing `effective_from`; automatic deletion of financial records before a formal retention policy; treating `admin/school_budget.php` as the budget truth source; adding GL as a later phase.
- **Compatibility baseline**: all admin finance URLs, POST `action` values, form `name`/`id`/`action`, hidden inputs, CSRF fields, session keys, JSON response field names, permissions, and SQL behavior of legacy finance pages. `AcademicYearWriteGuard` extended (not replaced) by `FinancePeriodGuard`. `AuditService`/`UndoManager`/`ActivityLog` reused. `admin/ui_preview.php` visual reference; shared CSS only; no `confirm()`/`alert()`/SweetAlert; Bootstrap modals; server-side DataTables; RTL.
- **Authorized side effects**: repository file changes in the worktree; isolated `*_test` database creation/migration for verification only. No production schema migration, no push, no `educore` writes.

## Technical Context

**Language/Version**: PHP >= 8.0 (from `composer.json`)

**Primary Dependencies**: PDO (no new ORM), existing `AuditService`/`UndoManager`/`ActivityLog`, `AcademicYearWriteGuard`, `StudentOperationalGuard`, Bootstrap 5 RTL, jQuery/DataTables; no new framework or router.

**Storage**: MariaDB/MySQL via PDO; new tables via dated migrations in `database/migrations/`; money as `DECIMAL(14,2)`; integer piaster minor units (EGP) in PHP; no runtime DDL; schema inspected only from an isolated `*_test` database (never from `storage/private/db_backups`).

**Testing**: PHP test scripts under `tests/` (e.g., `tests/finance_money_contract_test.php`); no PHPUnit assumed; touched-file lint; `composer architecture-audit`; `composer audit-write-coverage` (must end `AUDIT_REVIEW_REQUIRED=0`); `composer admin-ui-audit` when admin pages change; `composer quality` before close; guarded integration tests only on a confirmed `*_test` database.

**Target Platform**: Existing XAMPP/Windows deployment; production topology is `Not confirmed yet`.

**Project Type**: Server-rendered PHP modular monolith with role/API entrypoints; incremental modular extraction into `src/Modules/Finance`.

**Performance Goals**: Reconciliation streams/aggregates rather than loading all rows; stat cards use counters; large tables are server-side DataTables; GL journal entry creation is bounded per operation.

**Constraints**: One school; no production-writing tests; no raw secret/command output; no runtime DDL; no long dual-write; no `confirm()`/SweetAlert; no page-local button/stat-card CSS; no guessing `effective_from`; no auto-deletion of financial records before a formal retention policy; temp export files deleted after 24 hours; GL is in v1 (not a later phase).

**Scale/Scope**: One school, thousands of students and staff, many retained years; reconciliation must aggregate/stream.

## Constitution Check

- [x] **Canonical context**: `AGENTS.md` and focused docs read; all unknowns resolved (D-1..D-4, C2, U1); signed-movement model and invariants defined.
- [x] **Compatibility**: affected URLs, request/form fields, IDs, session keys, JSON contracts, roles, permissions, and behavior listed with preservation strategy (adapter cutover, no URL/POST/JSON changes without approved compatibility change).
- [x] **Architecture**: uses the existing modular-monolith direction and real repository paths; no parallel framework/helper/service; cross-module dependencies documented as Query/Application contracts OWNED BY THE SOURCE MODULES (StudentEnrollmentQuery→Students, StaffEmploymentQuery→StaffHr, BusSubscriptionQuery→Transport, AcademicYearQuery/StageGradeClassQuery→AcademicStructure).
- [x] **Security/data**: `validateSession()`/CSRF server-side before state processing; secrets from `env()`; HTML escaped; no `display_errors`; `AuditService` inside transactions; `batch_id`/`request_id`; `FOR UPDATE`/optimistic revision; idempotency keys; per-cashbox/year receipt numbering; reversal-only posted movements (opposite-sign via `reversal_of`); `FinancePeriodGuard`; MANDATORY maker-checker for sensitive operations; exactly one balanced GL entry per source op via `source_idempotency_key` in the same transaction; FileUploadGuard + `upload_policy_manifest.json` + `composer upload-policy-audit` for imports; shared role-footer/form-safety/undo-toast + role-coverage tests for new pages; sensitive attachments via `FileUploadGuard` in `storage/private/`; no secrets in audit snapshots; temp export files deleted after 24 hours.
- [x] **Testing/rollback**: characterization tests before legacy extraction; contract/domain/integration PHP scripts including invariant tests (`SUM(installment.net_amount)=charge.net_due`, `SUM(discount_applications)=charge.discount_amount`, `allocation<=installment.remaining_due`, `receipt.amount=SUM(allocations)+SUM(unapplied_credit)`, `unapplied_credit_application<=credit.remaining`); `*_test` guard; rollback steps per migration and per cutover phase; feature flag shadow→display→execute; T001A resync-first blocks implementation; stop conditions defined.
- [x] **Governance**: ADR-0XX for Finance module boundaries + GL + budget + maker-checker + signed-movement model required; `composer architecture-audit` + `composer admin-ui-audit` + `composer upload-policy-audit` planned; no baseline expansion to pass the gate.

Post-design re-check: all gates remain satisfied; no exception required.

## Project Structure

```text
specs/003-finance-ledger-payroll/
├── spec.md, plan.md, research.md, data-model.md, quickstart.md
├── contracts/README.md
└── tasks.md

src/Modules/Finance/
├── Domain/
│   ├── Money.php, FinancePeriod.php, FinanceAuthorization.php
│   └── Policy/{FeePlanPolicy,SiblingDiscountPolicy,EmployeeChildEligibilityPolicy,
│              ReceiptAllocationPolicy,PayrollCalculationPolicy,PeriodClosePolicy,
│              DiscountCombinationPolicy,UnappliedCreditPolicy,AccountMappingPolicy}.php
├── Contracts/
│   ├── Repositories/{FeePlanRepository,StudentFinanceAccountRepository,ChargeRepository,
│   │   ChargeInstallmentRepository,ReceiptRepository,PaymentAllocationRepository,
│   │   UnappliedCreditRepository,DiscountRuleRepository,DiscountAwardRepository,
│   │   DiscountApplicationRepository,StaffCompensationContractRepository,
│   │   StaffCompensationContractComponentRepository,PayrollRunRepository,
│   │   StaffAdvanceRepository,CashboxRepository,ReceiptNumberSequenceRepository,
│   │   JournalEntryRepository,BudgetRepository,AccountMappingPolicyRepository,
│   │   ControlAccountRepository,ArchiveRepository}.php
│   └── (cross-module Query contracts live in the SOURCE modules, NOT here — see contracts/README.md)
├── Application/
│   └── {FeePlanService,StudentChargeService,ReceiptService,PaymentAllocationService,
│       UnappliedCreditService,DiscountService,PayrollRunService,StaffAdvanceService,
│       ImportService,ExportService,ArchiveService,FinancePeriodService,
│       JournalEntryService,ReconciliationService,BudgetService,ControlAccountService,
│       AccountMappingService,DailySettlementService,ReportService}.php
└── Infrastructure/Pdo/
    └── (PDO implementations of repository contracts)

admin/ (compatibility adapters + new finance pages — each following admin/ui_preview.php + AGENTS.md)
├── (legacy adapters) fee_structure.php, fee_calculator.php, fee_payments.php,
│   ajax_fee_payments_datatable.php, staff_financial_data.php, school_budget.php,
│   student_buses.php (Transport-owned writes); bus_report.php and statements.php
│   remain source-owned passthrough surfaces because they are not Finance workflows
└── (new finance pages) finance_dashboard.php, finance_fee_plans.php,
    finance_student_accounts.php, finance_student_ledger.php, finance_receipts.php,
    finance_debts.php, finance_discounts.php, finance_buses.php, finance_staff_contracts.php,
    finance_payroll_runs.php, finance_staff_advances.php, finance_staff_ledger.php,
    finance_cashboxes.php, finance_vouchers.php, finance_journal.php, finance_budgets.php,
    finance_archive.php, finance_import_export.php, finance_audit_log.php, finance_reports.php

classes/ (FinancePeriodGuard.php, FinanceAuthorizationFacade.php — owned application/infrastructure boundary)

database/migrations/
└── (dated YYYYMMDD_*.php migrations with preconditions + rollback; NOT 0XX_*;
     NOT copies of archive/_CLEANUP/install_*.sql; include GL, budget, account-mapping,
     control-account, receipt-number-sequence, discount-applications, unapplied-credits,
     contract-components tables)

tests/
└── (finance_*_contract_test.php, finance_*_integration_test.php,
     finance_reconciliation_contract_test.php, finance_gl_balance_contract_test.php,
     finance_control_account_reconciliation_contract_test.php, finance_budget_actuals_contract_test.php,
     finance_bus_subscription_integration_test.php, finance_prior_year_debt_migration_test.php,
     finance_maker_checker_contract_test.php)

docs/
└── architecture-decisions.md (ADR-0XX Finance module boundaries + GL + budget + maker-checker)
```

**Structure Decision**: Extend the existing service/route pattern, not a parallel finance framework. `src/Modules/Finance` is a real, tested extraction. Legacy admin pages remain compatibility adapters. Cross-module Query contracts are OWNED BY THE SOURCE MODULES (Students/StaffHr/Transport/AcademicStructure), consumed read-only by Finance. Schema changes are additive dated migrations only. GL is in v1 — every posted operation creates its GL entry in the same transaction. No `0XX_*` migration names; no verbatim `archive/_CLEANUP/install_*.sql` copy; no `storage/private/db_backups` truth source.

## Complexity Tracking

No constitution exceptions. All business decisions (D-1..D-4, C2, U1) are resolved and encoded in the spec/research/data-model. GL in v1 is a scope decision, not an exception.
