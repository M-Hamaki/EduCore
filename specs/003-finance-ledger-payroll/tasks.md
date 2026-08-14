# Tasks: Finance Ledger & Payroll

**Input**: Design documents from `specs/003-finance-ledger-payroll/`

## Phase 1: Setup & Mandatory Resync

**⚠️ CRITICAL: T001A is the FIRST executable task and BLOCKS feature code.**

- [x] T001A Baseline adoption (FR-070, SC-041): commit the staged spec package only; inventory the source checkout with exact tracked/untracked/deleted paths; write a baseline manifest under `specs/003-finance-ledger-payroll/`; apply the current tracked diff plus required untracked source/docs/tests/migrations into this isolated worktree without modifying `main`; exclude `.env`, secrets, `storage/private`, backups, caches, logs, generated files, and `scratch/`; review conflicts/dependencies/diff; commit separately as `baseline: adopt current local main state`; forbid `origin/main` substitution and `stash`/`reset`/`clean`/`force`; stop only for actual conflict/unsafe file/secret/unresolvable dependency
- [x] T001 Confirm the worktree is clean after the spec and baseline commits; prove `main` was not modified by adoption; record the baseline commit IDs and manifest path before feature code
- [x] T002 Record scope, contracts, data policy, rollback, stop conditions, and resolved decisions (D-1..D-4, C2, U1) in `specs/003-finance-ledger-payroll/`
- [x] T003 [P] Confirm existing helpers/services (`AuditService`, `UndoManager`, `ActivityLog`, `AcademicYearWriteGuard`, `StudentOperationalGuard`) and dependencies before adding abstractions
- [x] T004 [P] Confirm the test DB is an isolated `*_test` database; confirm `storage/private/db_backups` is NOT used as a truth source; confirm no personal data is displayed

Do not initialize a new framework, router, auth stack, or parallel project structure.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: No user story can begin until this phase is complete.

- [x] T005 Add dated foundation migration(s) in `database/migrations/YYYYMMDD_finance_core_*.php` with preconditions + rollback; NOT named `0XX_*`; NOT copies of `archive/_CLEANUP/install_*.sql`; create `finance_charge_types`, `finance_periods`, `finance_cashboxes` (with `receipt_prefix`), `finance_bank_accounts`, `finance_cashbox_settlements`, `finance_receipt_number_sequences`, `finance_import_batches`, `finance_import_rows`
- [x] T006 Add dated migration(s) for fee plans + student accounts/contracts/charges/installments + the ONLY unified party sub-ledger: `finance_fee_plans`, `finance_fee_plan_versions`, `finance_fee_plan_installments`, `finance_student_accounts` (with `subledger_account_id`), `finance_student_contracts`, `finance_student_charges` (with `subledger_transaction_id`), `finance_charge_installments`, `finance_subledger_accounts` (student academic-year scope; staff `STAFF_GLOBAL`; unique party+scope+currency; no balance column), `finance_subledger_transactions` (status draft|posted only, unique source idempotency key, reversal_of), `finance_subledger_lines` (bucket_code, amount_delta, unique transaction+line, FK ON DELETE RESTRICT). Do NOT create any parallel student-specific ledger table or separate due/credit delta column
- [x] T007 Add dated migration(s) for discounts: `finance_discount_rules` (versioned: unique `code+year+charge_type+version_number`, `effective_from`/`effective_to`/`superseded_at`), `finance_discount_awards`, `finance_discount_applications`
- [x] T008 Add dated migration(s) for collection (signed): `finance_receipts` (with `academic_year_id`+`sequence_number`, unique `cashbox_id+academic_year_id+sequence_number`), `finance_payment_allocations` (targeting `finance_charge_installments`, with `signed_amount`), `finance_unapplied_credits` (with `signed_amount`), `finance_unapplied_credit_applications` (with `reversal_of`/`batch_id`/`request_id`), `finance_adjustments` (with `signed_amount`), `finance_refunds` (with `refund_type` + `allocation_refund`/`unapplied_credit_refund` distinction + `signed_amount`)
- [x] T009 Add dated migration(s) for staff/payroll: `staff_compensation_contracts`, `staff_compensation_contract_components` (PRIMARY source), `payroll_components`, `payroll_periods`, `payroll_runs` (with `reversal_of`/`version_number`/`is_settlement`), `payroll_run_items`, `payroll_item_components`, `staff_advances`, `staff_advance_installments`, `staff_advance_movements` (cash_repayment/payroll_deduction/write_off), `payroll_payments`
- [x] T010 Add dated migration(s) for GL and vouchers (v1): `accounting_accounts`, `accounting_cost_centers`, `accounting_journal_entries` (unique `source_idempotency_key`, nullable-unique `subledger_transaction_id`), `accounting_journal_lines`, `accounting_account_mapping_headers` (versioned), `accounting_account_mapping_lines` (multi-line + selectors), `accounting_control_accounts`, `finance_vouchers` (expense/other_income/cash_transfer; holding/source/destination cashbox constraints), `finance_voucher_lines`
- [x] T011 Add dated migration(s) for budget: `finance_budgets`, `finance_budget_versions`, `finance_budget_lines` (NO `actual_amount` column — computed from GL via view/query/cache)
- [x] T012 [P] Reuse and verify existing authentication/authorization/CSRF boundaries (`validateSession`, `requireCsrfPost`, `hash_equals`)
- [x] T013 [P] Capture current entrypoint/API contracts with characterization tests in `tests/finance_*_characterization_test.php`
- [x] T014 Add `src/Modules/Finance/Domain/Money.php` (integer piaster minor units, EGP, half-up rounding at presentation, no float)
- [x] T015 [P] Add `tests/finance_money_contract_test.php` (rounding, equality, no float drift)
- [x] T016 Add `src/Modules/Finance/Domain/FinancePeriod.php` + `classes/FinancePeriodGuard.php` extending `AcademicYearWriteGuard` (open/closed/reopen, maker-checker reopen)
- [x] T017 [P] Add `tests/finance_period_close_contract_test.php`
- [x] T018 Add `src/Modules/Finance/Domain/FinanceAuthorization.php` with the permission matrix; MANDATORY maker-checker for sensitive operations
- [x] T019 [P] Add `tests/finance_maker_checker_contract_test.php`
- [x] T020 Register EVERY new finance table in `src/Modules/Operations/Audit/AuditPolicyRegistry.php` with explicit per-table policy (undo eligibility, reversal-only, actor scope, retention, redaction, conflict behavior, external effects), including the three `finance_subledger_*` tables, all fee/discount/receipt/allocation/credit/refund tables, all staff compensation/payroll/advance/installment/movement/payment tables, all GL/mapping/control-account tables, vouchers/lines, budgets/versions/lines, cashboxes/settlements, periods, and import staging tables; update `tools/audit_write_coverage_classifications.php`; fail unless `AUDIT_REVIEW_REQUIRED=0`
- [x] T021 Add all Repository contracts in `src/Modules/Finance/Contracts/Repositories/*` per `contracts/README.md`, including generic sub-ledger repositories, `UnappliedCreditApplicationRepository`, `StaffAdvanceMovementRepository`, and voucher repositories
- [x] T022 Confirm cross-module Query contracts OWNED BY THE SOURCE MODULES: `StudentEnrollmentQuery` (Students), `StaffEmploymentQuery` (StaffHr), `BusSubscriptionQuery` (Transport), `AcademicYearQuery`/`StageGradeClassQuery` (AcademicStructure); add interfaces in the source modules (NOT in Finance)
- [x] T023 Add `src/Modules/Finance/Domain/Policy/AccountMappingPolicy.php`, `ControlAccountService` skeleton, account-mapping/control-account contracts, and the atomic `SubledgerPostingService` contract that distinguishes party-affecting operations from pure GL operations
- [x] T024 Reuse existing error handling and server-side logging (`error_log`); no `display_errors`; no hardcoded secrets (`env()`)
- [x] T025 [P] Add invariant tests: installment/charge totals, discount totals, allocation/credit limits, receipt composition, domain totals vs generic bucket deltas, original+reversal=0, balances only from `finance_subledger_lines.amount_delta`, party operation→one linked GL journal, pure GL operation→one journal and zero sub-ledger transactions in `tests/finance_invariants_contract_test.php`
- [x] T025A Implement `SubledgerPostingService` atomic orchestration in `src/Modules/Finance/Application/SubledgerPostingService.php`: resolve stable account scope, post append-only bucket lines, link one GL journal for party operations, skip sub-ledger for pure GL operations, share idempotency/audit/transaction boundary
- [x] T025B [P] Add PDO implementations for `SubledgerAccountRepository`, `SubledgerTransactionRepository`, and `SubledgerLineRepository` under `src/Modules/Finance/Infrastructure/Pdo/`
- [x] T025C [P] Add contract test for student/staff generic sub-ledger balances, stable `STAFF_GLOBAL`, derived `is_reversed`, original+reversal=0, and absence of old student-ledger schema in `tests/finance_subledger_balances_contract_test.php`
- [x] T025D [P] Add `v_student_subledger_balances`, `v_staff_subledger_balances`, and `v_budget_actuals` through dated migrations/query contracts; staff view filters stable `STAFF_GLOBAL`

**Checkpoint**: Foundation ready.

---

## Phase 3: User Story 1 — Versioned Fee Plans, Per-Student Contract, GL Entry (Priority: P1) 🎯 MVP

**Goal**: Stable, versioned fee plans with immutable per-student contracts; bus charges via `BusSubscriptionQuery`; one generic sub-ledger transaction linked to one GL entry in the same transaction.
**Independent Test**: Create a plan, use it, refuse to edit the used version, create a new version; assign a student and prove the contract snapshot is immutable; generate bus charges via `BusSubscriptionQuery`; prove exactly one charge bucket movement and one linked balanced GL entry were created atomically.

### Tests for User Story 1
- [x] T026 [P] [US1] Contract test for plan version immutability in `tests/finance_fee_plan_version_contract_test.php`
- [x] T027 [P] [US1] Guarded integration test for contract snapshot in `tests/finance_student_contract_integration_test.php`
- [x] T028 [P] [US1] Integration test for `BusSubscriptionQuery` bus charge generation in `tests/finance_bus_subscription_integration_test.php`
- [x] T029 [P] [US1] Contract test that a posted charge creates exactly one generic sub-ledger transaction and exactly one linked balanced GL entry in the same transaction in `tests/finance_charge_gl_entry_contract_test.php`

### Implementation for User Story 1
- [x] T030 [P] [US1] Add `FeePlanRepository` + `StudentFinanceAccountRepository` + `ChargeRepository` + `ChargeInstallmentRepository` (PDO)
- [x] T031 [US1] Implement `FeePlanService` + `StudentChargeService` in `src/Modules/Finance/Application/`
- [x] T032 [US1] Wire `BusSubscriptionQuery` (Transport-owned) into `StudentChargeService` for bus charges
- [x] T033 [US1] Wire `StudentChargeService` through `SubledgerPostingService`
- [x] T034 [US1] Convert `admin/fee_structure.php` to a compatibility adapter after characterization tests (preserve URL/POST/JSON)
- [x] T035 [US1] Build `admin/finance_fee_plans.php` following `admin/ui_preview.php` + AGENTS.md
- [x] T035A [US1] Ensure `admin/finance_fee_plans.php` uses shared `admin_footer`/role footer, `form-safety.js`, `undo-toast.js`; add role-coverage contract test; no page-local toast/draft/logger
- [x] T036 [US1] Add audit via `AuditService` inside the transaction; `batch_id`/`request_id`

**Checkpoint**: US1 fully functional and independently testable.

---

## Phase 4: User Story 2 — Eligibility-Proven Discounts with Applications (Priority: P1)

**Goal**: Sibling and employee-child discounts from trusted data with fixed ordering, default-no-combine, explicit-combine-with-cap; versioned discount rules; `finance_discount_applications` links discounts to charges/installments.
**Independent Test**: Three siblings → fixed oldest-enrollment-first order (ties by `student_id`); employee-child refused without active employment at due date; default-no-combine; explicit-combine respects cap; used discount version immutable.

### Tests for User Story 2
- [x] T037 [P] [US2] Contract test for sibling ordering (oldest enrollment, ties by `student_id`) in `tests/finance_sibling_discount_contract_test.php`
- [x] T038 [P] [US2] Contract test for employee-child eligibility at due date in `tests/finance_employee_child_eligibility_contract_test.php`
- [x] T039 [P] [US2] Contract test for default-no-combine + explicit-combine-with-cap in `tests/finance_discount_combination_contract_test.php`
- [x] T040 [P] [US2] Contract test for discount rule versioning + scope (unique `code+year+scope_charge_type_key+version`; used version immutable; no two active versions overlap in time for same scope; cover BOTH general `'ALL'` scope and charge-type-specific scope) in `tests/finance_discount_versioning_scope_contract_test.php`
- [x] T040A [P] [US2] Add MariaDB concurrency test proving two simultaneous activations of the same discount-policy scope cannot both succeed in `tests/finance_discount_concurrency_contract_test.php`

### Implementation for User Story 2
- [x] T041 [P] [US2] Add `DiscountRuleRepository` + `DiscountAwardRepository` + `DiscountApplicationRepository` (PDO)
- [x] T042 [US2] Implement `SiblingDiscountPolicy` + `EmployeeChildEligibilityPolicy` + `DiscountCombinationPolicy` in `src/Modules/Finance/Domain/Policy/`
- [x] T043 [US2] Implement `DiscountService` (creates `finance_discount_applications`)
- [x] T044 [US2] Convert `admin/fee_calculator.php` to an adapter after characterization tests (preserve URL/POST/JSON)
- [x] T045 [US2] Build `admin/finance_discounts.php` following `admin/ui_preview.php` + AGENTS.md
- [x] T045A [US2] Ensure `admin/finance_discounts.php` uses shared role-footer/form-safety/undo-toast + role-coverage test; no page-local toast/draft/logger

**Checkpoint**: US1 AND US2 work independently.

---

## Phase 5: User Story 3 — Immutable Receipts, Allocation, Unapplied Credits, GL Entry (Priority: P1)

**Goal**: Append-only generic sub-ledger; idempotent receipts with per-cashbox/year numbering; overpayment → independent `unapplied_credit` with partial applications; allocation to `finance_charge_installments`; refund distinguishes allocation vs unapplied-credit; one multi-line party transaction linked to one GL entry.
**Independent Test**: Post partial payment allocated to installments; retry with same key → no duplicate; overpayment → independent `unapplied_credit`; partial application of unapplied credit to an installment; refund of allocation restores due; refund of unapplied credit shrinks credit only; cancel → reversing entry (opposite sign); hard-delete refused; receipt number per cashbox/year; exactly one GL entry.

### Tests for User Story 3
- [x] T046 [P] [US3] Contract test for idempotency in `tests/finance_receipt_idempotency_contract_test.php`
- [x] T047 [P] [US3] Contract test for per-cashbox/year receipt numbering (unique `cashbox+year+sequence`, no collision) in `tests/finance_receipt_numbering_contract_test.php`
- [x] T048 [P] [US3] Contract test for reversal-only cancellation (opposite sign, `reversal_of`; original stays posted and counted in SUM; `original + reversal = 0` net effect) in `tests/finance_receipt_reversal_contract_test.php`
- [x] T049 [P] [US3] Contract test for unapplied_credit creation + partial application via `finance_unapplied_credit_applications` in `tests/finance_unapplied_credit_contract_test.php`
- [x] T050 [P] [US3] Contract test for refund distinction: `allocation_refund` restores due; `unapplied_credit_refund` shrinks credit only in `tests/finance_refund_signed_contract_test.php`
- [x] T050A [P] [US3] Contract test for student debt write-off as a separate maker-checker credit adjustment capped at locked remaining due, never a payment allocation or reversal of the original charge, in `tests/finance_student_debt_writeoff_contract_test.php`
- [x] T051 [P] [US3] Contract test for concurrent payments (FOR UPDATE/revision) in `tests/finance_payment_concurrency_contract_test.php`
- [x] T052 [P] [US3] Contract test that receipt posting creates exactly one multi-line generic sub-ledger transaction and one linked balanced GL entry in the same transaction in `tests/finance_receipt_gl_entry_contract_test.php`
- [x] T052A [P] [US3] Add receipt granularity test: three allocations + overpayment produce one sub-ledger transaction, four bucket lines, one linked GL journal, and one shared `subledger_transaction_id` in `tests/finance_receipt_granularity_contract_test.php`

### Implementation for User Story 3
- [x] T053 [P] [US3] Add `ReceiptRepository` + `PaymentAllocationRepository` + `UnappliedCreditRepository` + `UnappliedCreditApplicationRepository` + `ReceiptNumberSequenceRepository` (PDO)
- [x] T054 [US3] Implement `ReceiptService` + `PaymentAllocationService` + `UnappliedCreditService` (signed movements, idempotency, reversal_of, FOR UPDATE, auto-oldest allocation with manual override+reason, per-cashbox/year numbering, partial unapplied-credit application)
- [x] T055 [US3] Implement refund with `refund_type` distinction (allocation vs unapplied-credit), maker-checker
- [x] T055A [US3] Implement student debt write-off through `finance_adjustments` with account/installment lock, maker-checker, positive amount capped at remaining due, generic sub-ledger credit movement, linked GL mapping, and independent reversal support
- [x] T056 [US3] Wire `ReceiptService` through `SubledgerPostingService` to create exactly one multi-line sub-ledger transaction and one linked balanced GL entry atomically (refuse missing/ambiguous mapping)
- [x] T057 [US3] Convert `admin/fee_payments.php` + `admin/ajax_fee_payments_datatable.php` to adapters after characterization tests (preserve URL/POST/JSON)
- [x] T058 [US3] Build `admin/finance_receipts.php` + `admin/finance_debts.php` following `admin/ui_preview.php` + AGENTS.md
- [x] T058A [US3] Ensure both pages use shared role-footer/form-safety/undo-toast + role-coverage tests; no page-local toast/draft/logger

**Checkpoint**: US1, US2, US3 work independently.

---

## Phase 6: User Story 4 — Server-Computed Payroll, Contract Components, Reversal, GL Entry (Priority: P2)

**Goal**: Correct, stable, server-computed payroll with effective-dated contract components, lifetime `STAFF_GLOBAL` history, settlements clearing through payroll payable, financially correct advance repayment/write-off flows, reversal via `reversal_of`, and one linked GL entry per party operation.
**Independent Test**: Run payroll → posted payslip unchanged after contract change and same staff ledger retained; tampered client net ignored; retroactive difference posts separately into payroll payable; advance 1000, repay 200, write off 800 → zero and 1000 write-off rejected; cash repayment/payroll deduction map differently; reversal works; multi-component GL entry is balanced.

### Tests for User Story 4
- [x] T059 [P] [US4] Contract test for server-computed gross/net from `staff_compensation_contract_components` in `tests/finance_payroll_calculation_contract_test.php`
- [x] T060 [P] [US4] Contract test for effective-dated contracts + frozen payslips in `tests/finance_compensation_contract_contract_test.php`
- [x] T061 [P] [US4] Contract test for `payroll_runs` reversal via `reversal_of` in `tests/finance_payroll_reversal_contract_test.php`
- [x] T062 [P] [US4] Contract test for separate retroactive settlement in an open period in `tests/finance_payroll_retroactive_settlement_contract_test.php`
- [x] T063 [P] [US4] Contract test that each `payroll_run_item` creates one party sub-ledger transaction and one linked balanced multi-component GL journal, while the payroll run groups all atomic item postings by `batch_id`, in `tests/finance_payroll_gl_entry_contract_test.php`
- [x] T063A [P] [US4] Add staff-ledger contract test covering `STAFF_GLOBAL`, payroll posting/payment, settlement posting into payroll payable, advance issue, distinct cash repayment/payroll deduction mappings, advance 1000−repayment 200−write-off 800=0, over-write-off refusal, and reversal of erroneous operations in `tests/finance_staff_ledger_rules_contract_test.php`
- [x] T063B [P] [US4] Add staff sub-ledger ↔ GL control-account reconciliation test in `tests/finance_staff_control_account_reconciliation_contract_test.php`

### Implementation for User Story 4
- [x] T064 [P] [US4] Add PDO implementations for `StaffCompensationContractRepository`, `StaffCompensationContractComponentRepository`, `PayrollRunRepository`, `StaffAdvanceRepository`, and `StaffAdvanceMovementRepository`
- [x] T065 [US4] Implement `PayrollCalculationPolicy` + `PayrollRunService` + `StaffAdvanceService`: server-computed components; settlement posts into payroll payable; separate cash repayment/payroll deduction/write-off source types and GL mappings; lock and derive remaining receivable; maker-checker write-off capped at remaining; no guessed `effective_from`
- [x] T066 [US4] Implement payslip printing with reference number + payment status
- [x] T067 [US4] Wire payroll/settlement/advance operations through `SubledgerPostingService` and `JournalEntryService` to create one party transaction and one linked balanced GL journal atomically
- [x] T068 [US4] Convert `admin/staff_financial_data.php` to an adapter after characterization tests (preserve URL/POST/JSON)
- [x] T069 [US4] Build `admin/finance_staff_contracts.php` + `admin/finance_payroll_runs.php` + `admin/finance_staff_advances.php` + `admin/finance_staff_ledger.php` following `admin/ui_preview.php` + AGENTS.md
- [x] T069A [US4] Ensure all four pages use shared role-footer/form-safety/undo-toast + role-coverage tests; no page-local toast/draft/logger

**Checkpoint**: US1–US4 work independently.

---

## Phase 7: User Story 5 — Period Close, Archive, Import/Export, Budget, GL Reports, Maker-Checker (Priority: P2)

**Goal**: Closed books; safe staged import with FileUploadGuard; versioned budget (actuals from GL); pure-GL vouchers with controlled transfers; GL reports; permission-filtered exports; MANDATORY maker-checker.
**Independent Test**: Close → reject backdated writes; staged import → no business write during preview; budget draft→approved→locked with actuals from GL; expense/other-income/transfer vouchers create one balanced GL journal and no party transaction; same-source transfer rejected; GL reports; export audit log without content + 24h retention; creator ≠ approver.

### Tests for User Story 5
- [x] T070 [P] [US5] Contract test for import staging (no business write during preview) in `tests/finance_import_staging_contract_test.php`
- [x] T071 [P] [US5] Contract test for upload safety (FileUploadGuard: real MIME, double extension, size, random name, authorization, file/DB rollback) in `tests/finance_upload_safety_contract_test.php`
- [x] T072 [P] [US5] Contract test for export permission/filter + 24h retention in `tests/finance_export_contract_test.php`
- [x] T073 [P] [US5] Contract test for archive/restore in `tests/finance_archive_contract_test.php`
- [x] T074 [P] [US5] Contract test for budget actuals from GL only (view/query/cache reconciled) in `tests/finance_budget_actuals_contract_test.php`
- [x] T075 [P] [US5] Contract test for trial balance / P&L / cash flow / budget-vs-actual in `tests/finance_gl_reports_contract_test.php`
- [x] T076 [P] [US5] Contract test for deterministic account-mapping resolution (specificity→priority→version; refuse on zero matches; refuse on ambiguous matches with same specificity+priority; specific override beats general; multi-component payroll balanced) in `tests/finance_account_mapping_resolution_contract_test.php`
- [x] T076A [P] [US5] Add voucher contract test: expense/other-income one holding, transfer positive with distinct source/destination, same-source rejected, journal balanced, deterministic mapping, and zero party sub-ledger transactions in `tests/finance_voucher_gl_contract_test.php`
- [x] T076B [P] [US5] Add control-account bypass test refusing unlinked voucher/manual lines against student/staff control accounts and proving budget planning writes create no GL/sub-ledger records in `tests/finance_control_account_posting_guard_contract_test.php`

### Implementation for User Story 5
- [x] T077 [P] [US5] Add `CashboxRepository` + `ArchiveRepository` + `BudgetRepository` + `VoucherRepository` + `VoucherLineRepository` PDO implementations
- [x] T078 [US5] Read `docs/file-upload-standard.md`; classify import file path in `tools/upload_policy_manifest.json`; use `FileUploadGuard` for all import files; pass `php tools/audit_upload_policy.php --strict` and `composer upload-policy-audit`
- [x] T079 [US5] Implement `ImportService` (staging, schema version, validation, maker-checker approval, reversal batch) + `ExportService` (filter/column/permission respect, audit log without content, 24h temp file retention) + `ArchiveService`
- [x] T080 [US5] Implement `BudgetService` (draft→reviewed→approved→locked/revised; manual or import staging; actuals from GL view/query/cache with reconciliation) + `ReportService` (trial balance, P&L, cash flow, budget vs actual, debt aging, bus reports, payroll reports, collection reports)
- [x] T080A [US5] Implement `VoucherService` for expense/other-income/cash-transfer with maker-checker, reversal-only, distinct transfer endpoints, deterministic GL mapping, one balanced GL journal, mandatory audit, and no party sub-ledger transaction
- [x] T080B [US5] Implement `ControlAccountPostingGuard` in the GL posting path: reject voucher/manual writes to student/staff control accounts without a matching linked party transaction; keep budget planning writes outside GL
- [x] T081 [US5] Implement `DailySettlementService` for cashboxes
- [x] T082 [US5] Build `admin/finance_cashboxes.php` + `admin/finance_budgets.php` + `admin/finance_archive.php` + `admin/finance_import_export.php` + `admin/finance_reports.php` following `admin/ui_preview.php` + AGENTS.md
- [x] T082A [US5] Ensure all five pages use shared role-footer/form-safety/undo-toast + role-coverage tests; no page-local toast/draft/logger
- [x] T082B [US5] Build `admin/finance_vouchers.php` for expense/other-income/cash-transfer entry and reports, following `admin/ui_preview.php`, shared role footer/form safety/undo toast, and role-coverage tests
- [x] T083 [US5] Convert `admin/school_budget.php` + `admin/student_buses.php` + `admin/bus_report.php` + `admin/statements.php` to adapters after characterization tests (preserve URL/POST/JSON)

**Checkpoint**: US1–US5 work independently.

---

## Phase 8: User Story 6 — Reconciliation, Control Accounts, GL, Gradual Adapter Cutover (Priority: P3)

**Goal**: Derived balance from ALL signed movements reconciled with legacy; sub-ledger ↔ GL control-account reconciliation; GL double-entry verified; prior-year debt migration; legacy pages converted slice by slice.
**Independent Test**: Reconciliation per student (signed movements) matches legacy `balance`; sub-ledger reconciles to GL control accounts; GL debit=credit; prior-year debt migrated as `opening_balance` movement; one adapter page preserves URL/POST/JSON.

### Tests for User Story 6
- [x] T084 [P] [US6] Contract test for generic sub-ledger reconciliation: student bucket equations from `finance_subledger_lines.amount_delta`, staff `STAFF_GLOBAL` continuity, party transaction↔GL 1:1 through unique link, pure GL journal with NULL link, and domain totals matching bucket deltas in `tests/finance_reconciliation_contract_test.php`
- [x] T085 [P] [US6] Contract test for GL balance and reversal semantics: debit=credit, journal status draft|posted only, original remains posted/counted, reversal is a new opposite posted journal, original+reversal=0 in `tests/finance_gl_balance_contract_test.php`
- [x] T086 [P] [US6] Contract test for control-account reconciliation in `tests/finance_control_account_reconciliation_contract_test.php`
- [x] T087 [P] [US6] Contract test for adapter compatibility in `tests/finance_adapter_compatibility_contract_test.php`
- [x] T087A [US6] Update `admin/finance_student_ledger.php` and `admin/finance_staff_ledger.php` to read EXCLUSIVELY from `finance_subledger_transactions`/`finance_subledger_lines`, filtered by party type/scope, never domain sums
- [x] T088 [P] [US6] Migration + test for prior-year debt → `opening_balance` movement linked to original year (no delete/modify of old record) in `tests/finance_prior_year_debt_migration_test.php`

### Implementation for User Story 6
- [x] T089 [US6] Implement `ReconciliationService`: student balances from two student buckets, staff balances from `STAFF_GLOBAL` staff buckets, party↔GL linkage checks, pure-GL NULL-link checks, and no domain-table balance computation
- [x] T090 [US6] Implement `ControlAccountService` (sub-ledger ↔ GL control-account reconciliation) + verify `AccountMappingService` for all operation types
- [x] T091 [US6] Add feature flag (shadow → display → execute) for gradual cutover; no long dual-write
- [x] T092 [US6] Add prior-year debt migration (creates `opening_balance` charges with `source=prior_year` linked to original year)
- [x] T093 [US6] Rollback drill on `*_test` proving full return to original state before any production cutover

**Checkpoint**: All user stories independently functional and reconciled.

---

## Phase 9: New Pages, Dashboards, Audit Log UI (Cross-Cutting)

**Purpose**: Build all remaining new admin pages following `admin/ui_preview.php` + AGENTS.md.

- [x] T094 [P] Build `admin/finance_dashboard.php` (overview, stat cards with `counter`/`data-target`, collection summary)
- [x] T095 [P] Build `admin/finance_student_accounts.php` (student financial accounts list; `admin-filter-bar`/`admin-list-surface`/`admin-data-table`)
- [x] T096 [P] Build `admin/finance_student_ledger.php` (detailed student ledger: charges, payments, discounts, documents, log)
- [x] T097 [P] Build `admin/finance_buses.php` (bus finance)
- [x] T098 [P] Build `admin/finance_journal.php` (journal entries and chart of accounts; account-mapping policies)
- [x] T099 [P] Build `admin/finance_audit_log.php` (finance audit log UI)
- [x] T100 [P] Verify every new page passes `tools/audit_admin_ui.php` (UI_AUDIT_ISSUES=0) and follows `admin/ui_preview.php` + AGENTS.md
- [x] T100A [P] Ensure every new page uses shared `admin_footer`/role footer, `form-safety.js`, `undo-toast.js`; add role-coverage contract tests; no page-local toast/draft/logger

---

## Phase 10: Polish & Cross-Cutting Concerns

- [x] T101 [P] Documentation updates in `docs/` (ADR-0XX Finance module boundaries + GL + budget + maker-checker + account-mapping + signed movements; update `docs/architecture-decisions.md`, `docs/project-memory.md`, `docs/database.md`)
- [x] T102 [P] Code cleanup and refactoring
- [x] T103 [P] Performance optimization (streaming aggregation for reconciliation; server-side DataTables)
- [x] T104 [P] Additional unit/contract tests in `tests/`
- [x] T105 Security hardening (reversal-only at write layer, idempotency unique keys, period-close guard, maker-checker, 24h temp file retention, FileUploadGuard)
- [x] T106 Run `quickstart.md` validation (PowerShell examples)
- [x] T107 Run touched-file PHP lint and relevant contract/unit tests
- [x] T108 Run guarded integration tests only against a confirmed `*_test` database
- [x] T109 Run `composer audit-write-coverage` (must end `AUDIT_REVIEW_REQUIRED=0`), `composer architecture-audit`, `composer admin-ui-audit`, `composer upload-policy-audit`, `composer quality`
- [x] T110 Update `docs/architecture-decisions.md` and focused docs when boundaries changed
- [x] T111 Verify rollback and staged paths/diff before the focused commit

---

## Dependencies & Execution Order

### Phase Dependencies
- **Setup & Resync (Phase 1)**: T001A is FIRST and BLOCKS everything; no other task runs until resync + overlap check succeeds and user approves.
- **Foundational (Phase 2, including T025A–T025D)**: Depends on Phase 1 — BLOCKS all user stories.
- **User Stories (Phase 3+)**: Depend on Foundational; can proceed in parallel or sequentially P1→P2→P3.
- **New Pages (Phase 9)**: Each page depends on its service + role-footer gate.
- **Polish (Phase 10)**: Depends on all desired user stories complete.

### Within Each User Story
- Characterization/contract tests before changing legacy behavior.
- Models (Repositories) before Services.
- Services before endpoints/adapters/pages.
- GL entry creation (exactly one per source op via `source_idempotency_key`) wired into the same transaction.
- Role-footer/form-safety/undo-toast + role-coverage test for every new page.
- Story complete before next priority.

## Independent Test Criteria

- **US1**: Used plan version refuses edit; contract snapshot immutable; bus charges via `BusSubscriptionQuery`; exactly one balanced GL entry per source op.
- **US2**: Sibling order by oldest enrollment (ties `student_id`); employee-child refused without active employment; default-no-combine + explicit-combine-with-cap; discount versioning immutable.
- **US3**: Idempotent retry → no duplicate; per-cashbox/year numbering; overpayment → independent credit with partial application; refund distinction; reversal opposite sign; hard-delete refused; one multi-line party transaction + one linked GL entry.
- **US4**: Server-computed payroll; stable `STAFF_GLOBAL`; posted payslip unchanged after contract change; settlement clears through payroll payable; cash repayment/payroll deduction mappings differ; write-off capped at remaining and not a reversal of issue; staff↔GL reconciles.
- **US5**: Closed period rejects backdated writes; staged import writes no business data + FileUploadGuard; budget actuals from GL; pure-GL vouchers have controlled transfer endpoints and no party transaction; GL reports; export permissions + 24h; maker-checker.
- **US6**: Generic bucket reconciliation matches legacy; party operations link 1:1 to GL; pure GL operations have NULL party link; staff history survives contract changes; prior-year debt migrated; adapter preserves URL/POST/JSON.

## Implementation Strategy

T001A (resync + overlap check) is the FIRST task and BLOCKS all implementation. Deliver the complete foundation including T025A–T025D, then P1 stories (US1, US2, US3), P2 (US4, US5), and P3 (US6). Every accounting source operation creates one GL journal; only party-affecting operations also create one linked generic sub-ledger transaction. All business decisions are resolved. No production migration or deployment during implementation.

## Phase 11: Convergence

- [x] T112 CRITICAL: Refactor every Finance Application service to depend only on Domain/Repository/Query/transaction/audit contracts, move all PDO/SQL access into `src/Modules/Finance/Infrastructure/Pdo/`, and add architecture contract coverage per `plan.md` dependency direction (contradicts)
- [x] T113 Make required Finance integration tests fail or report non-acceptance when the isolated `*_test` database is unavailable; distinguish PASSED/FAILED/SKIPPED in the completion evidence and never count SKIPPED as accepted per T108 and SC-040 (partial)
- [x] T114 Synchronize stale worktree/status metadata in `spec.md` and `plan.md` with the current isolated branch state after implementation, without changing approved feature intent per plan metadata (contradicts)

## Phase 12: Convergence

- [x] T115 CRITICAL: Replace the nine legacy finance implementations with thin compatibility entrypoints that delegate owned reads/writes to Finance Application services while preserving URL, POST/action/form/session/JSON contracts; retain non-Finance Transport ownership through documented source-module contracts per Constitution I, FR-045, and SC-023
- [x] T116 CRITICAL: Implement audited finance-period lifecycle ownership (open/close/reopen), reject backdated writes through `FinancePeriodGuard`, require higher-permission maker-checker for reopen, add an admin surface, and prove the closed-period scenario on `*_test` per FR-020 and US5/AC1 (missing)
- [x] T117 CRITICAL: Extend `FinanceApprovalWorkflowService` and its UI/tests to cover every FR-040 sensitive operation: refund post/reversal, debt/advance write-off, manual journal post/reversal, import post/reversal, payroll approve/post/payment/reversal, period reopen, and manual/exception discount approval per FR-040 and SC-026 (partial)
- [x] T118 Add a complete refund request/approval/reversal admin workflow linked to the original receipt/allocation or unapplied credit and original payment method, with focused maker-checker tests per FR-016 (missing)
- [x] T119 Add an owned manual-journal Application service and admin workflow with maker-checker, balanced-lines validation, reversal-only semantics, period guard, audit, and control-account refusal per FR-060 and FR-080 (missing)
- [x] T120 Replace fixed 200-row finance admin reads with server-side filtered/paginated DataTables contracts for large ledgers, receipts, audit, payroll, vouchers, and journal surfaces per plan performance goals and T103 (partial)
- [x] T121 Synchronize `docs/project-memory.md`, `docs/database.md`, `docs/architecture.md`, completion evidence, and Spec Kit status metadata with the implemented Finance module and verified rollout/rollback contract per T101, T110, and T114 (partial)

## Phase 13: Authorized Local Rollout

- [x] T122 Add idempotent default Finance configuration migration and integration coverage for the chart of accounts, main cashbox, open period, 19 deterministic operation mappings, and four control accounts; preserve any user-managed mapping set and fail closed instead of mixing defaults into it
- [x] T123 After explicit user authorization, create and restore-verify a full `educore` backup, preserve dirty-main tracked/untracked work, merge only the Finance delta, enable `FINANCE_LEDGER_MODE=display`, apply all Finance migrations including default configuration, and verify `composer quality` plus the isolated Finance suite
- [x] T124 Smoke-test the authenticated admin dashboard, existing non-Finance pages, Finance sidebar, and representative Finance pages in the user's authenticated Chrome session; record and resolve runtime/console/server errors before completion
- [x] T125 Expose 29 operational Finance and compatibility pages in five labeled sidebar groups, preserve all existing routes, and add a contract test proving every visible target exists
- [x] T126 Resolve authenticated runtime findings: inject the mandatory shared audit writer into the DataTables service factory, keep compatibility mode silent in every user-facing page, remove conflicting Bootstrap instances, replace the removed jQuery tooltip call, and verify statements use the authorized private-photo controller
