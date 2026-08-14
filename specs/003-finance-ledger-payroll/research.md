# Research: Finance Ledger & Payroll

## Decision 1: Modular extraction into `src/Modules/Finance`, legacy pages become thin adapters

- **Decision**: Build a new incremental `src/Modules/Finance` module with dependency direction Entrypoint → Application Service → Domain Policy → Repository Contract → PDO Infrastructure. Existing `admin/fee_*.php`, `admin/staff_financial_data.php`, and `admin/school_budget.php` remain HTTP entrypoints and become thin adapters that delegate to Finance services while preserving URLs, POST `action` values, form `name`/`id`, hidden inputs, CSRF fields, session keys, JSON response fields, permissions, and SQL behavior. `admin/student_buses.php` delegates writes to a Transport-owned application service and archives unassignment rather than deleting it. `admin/bus_report.php` remains a Transport-owned report and `admin/statements.php` remains an official-document workflow; both pass through unchanged instead of being incorrectly redirected into Finance.
- **Rationale**: `AGENTS.md`, `docs/project-structure.md`, and the Constitution mandate a pragmatic modular monolith reached incrementally; no parallel framework/router/auth stack. Finance is an approved target module boundary (`docs/architecture.md` line 209). Staff employment data is owned by StaffHr; salary calculation/payment rules are owned by Finance, communicating via a documented contract (`docs/project-structure.md` line 10).
- **Alternatives considered**: A parallel finance framework/router was rejected (forbidden). A full rewrite of finance pages was rejected (breaks URLs/contracts). Treating unrelated Transport reports or official student statements as Finance pages was rejected because it violates source-module ownership and observable behavior.

## Decision 2: Full double-entry accounting (GL) is IN SCOPE for v1 (not deferred)

- **Decision**: Build the chart of accounts, account-mapping policies, journal entries/lines, cost centers, control accounts, trial balance, P&L, cash flow, budget vs actual, and cash/bank movement — all in v1. The invariant "total debit = total credit per journal entry" is enforced at posting. Every posted Student/Payroll/Cashbox operation MUST create a balanced GL journal entry INSIDE THE SAME TRANSACTION (GL is NOT a later phase). Student/staff sub-ledger balances reconcile to GL control accounts.
- **Rationale**: User clarification (2026-07-22) chose full double-entry accounting, and the 2026-07-23 correction removed all "deferred/out-of-scope" GL language. Building GL later would require a more invasive retrofit and would leave the sub-ledger without a complete financial picture.
- **Alternatives considered**: Sub-ledger-only (deferred GL) was rejected by the user. A pre-built-but-disabled GL schema was rejected as complexity without immediate value. Adding GL as a final phase was explicitly rejected — every posted operation creates its GL entry in the same transaction.

## Decision 3: Money as integer minor units (EGP, piasters) with a unified rounding policy; no PHP float

- **Decision**: Currency is EGP. Represent money internally as integer minor units (piasters) in PHP and as `DECIMAL(14,2)` in the database. Apply a single rounding policy (half-up) in the `Money` value object at presentation, never during accumulation.
- **Rationale**: The current code casts `DECIMAL` to PHP `float` pervasively and computes client-side net in JS. Float is a known precision hazard for money. `AGENTS.md` requires DECIMAL and forbids page-local logic.
- **Alternatives considered**: `bcmath` strings everywhere was considered but integer minor units are simpler. Keeping float was rejected.

## Decision 4: Balance derived EXCLUSIVELY from unified ledger lines; opening balance is a ledger movement

- **Decision**: `finance_student_accounts` carries NO authoritative balance/opening_balance column. Student and staff balances are computed EXCLUSIVELY from posted `finance_subledger_lines.amount_delta` grouped by `bucket_code` — never from domain-table sums or a fixed list of table names. No equation subtracts refunds or reversals as fixed categories; their signed bucket movements already encode the effect. Opening balance is a documented `opening_balance`/`prior_year` sub-ledger transaction linked to the original year, never an editable standalone column and never deleting/modifying the old record.
- **Rationale**: A fixed table-name equation hides the actual arithmetic and cannot express refund distinction (allocation vs unapplied-credit) or partial credit application. The unified ledger truth source (Decision 15) makes balances unambiguous and composable by SUM.
- **Alternatives considered**: Computing balances from domain-table sums was rejected (hidden arithmetic). A fixed table-name equation was rejected. Storing `opening_balance` as a column was rejected (parallel truth source).
- **Rationale**: The current `student_fees.balance` is directly editable and the only truth; mismatches are undetectable. Balances must be computed EXCLUSIVELY from the unified ledger lines (Decision 15), where every movement type (charge, adjustment, allocation, unapplied credit, refund, reversal) posts its own deltas — no fixed-category equation is needed.
- **Alternatives considered**: Keeping `balance` as a column with triggers was rejected. Storing `opening_balance` as a column was explicitly rejected as a parallel truth source.

## Decision 5: Posted movements are reversal-only; enforcement at the write layer; GL entry in the same transaction

- **Decision**: For posted receipts, payroll items, journal entries, allocations, and refunds, hard `DELETE` is forbidden in new code; correction is via a reversing entry with a `reversal_of` self-reference. `payroll_runs` supports reversal via `reversal_of` (NOT a strict unique that blocks reversal). New finance tables are registered in `AuditPolicyRegistry::REVERSAL_ONLY_TABLES`. Every new write owner routes through `AuditService` INSIDE the business transaction; audit failure rolls back the business write. Every posted Student/Payroll/Cashbox operation creates a balanced GL journal entry INSIDE THE SAME TRANSACTION.
- **Rationale**: `AuditPolicyRegistry::REVERSAL_ONLY_TABLES` currently only contains `fee_payments`, and the policy is enforced at the undo layer, not the write layer — `fee_payments.php:303` hard-deletes and audits AFTER commit. ADR-028 and the audit/undo roadmap mandate reversal-only for financial payments. GL-in-same-transaction is required so the books never desync from the sub-ledger.
- **Alternatives considered**: Keeping post-commit audit was rejected. Allowing hard-delete under a permission was rejected. Adding GL as a later phase was rejected.

## Decision 6: Idempotency via unique key; per-cashbox/per-year receipt numbering; concurrency via FOR UPDATE

- **Decision**: Every receipt carries an `idempotency_key` with a unique constraint; a retry returns the original. Receipt numbers follow a per-cashbox/per-year sequence (`finance_receipt_number_sequences`) with atomic increment under `FOR UPDATE`. Concurrent allocations/reversals use `SELECT ... FOR UPDATE` or an optimistic revision column.
- **Rationale**: No idempotency mechanism exists today (`request_id` is for correlation only). Receipt numbering is currently a free `receipt_number` column with no enforced policy. `AGENTS.md` requires `FOR UPDATE`/optimistic revision.
- **Alternatives considered**: Application-level dedup without a unique key was rejected.

## Decision 7: Period close is a real object extending `AcademicYearWriteGuard`; maker-checker

- **Decision**: Introduce `finance_periods` with `open/closed/reopened` state. A `FinancePeriodGuard` extends `AcademicYearWriteGuard` and rejects backdated writes into closed periods; reopen requires higher permission, is maker-checker, and is logged. A closed year is never modified; correction is via reversing entries or an approved reopen.
- **Rationale**: The only close-lock today is `academic_years.locked` (coarse). The audit/undo roadmap requires a period-close gate with separate view vs. reversal permissions and maker-checker.
- **Alternatives considered**: Reusing only `academic_years.locked` was rejected (cannot express sub-period close or controlled reopen).

## Decision 8: Cross-module contracts OWNED BY THE SOURCE MODULES, not Finance

- **Decision**: Communication with Students, StaffHr, Transport, and AcademicStructure uses small documented Query/Application contracts OWNED BY THE SOURCE MODULES: `StudentEnrollmentQuery` (Students), `StaffEmploymentQuery` (StaffHr), `BusSubscriptionQuery` (Transport), `AcademicYearQuery`/`StageGradeClassQuery` (AcademicStructure). New Finance code never `include`s another module's page or queries its tables directly.
- **Rationale**: `docs/project-structure.md` line 111 forbids cross-module internal access. The 2026-07-23 correction #13 requires that cross-module Query contracts be owned by the source modules, not Finance. Bus fee currently matches by exact string `buses.area == bus_fee_zones.zone_name` — fragile.
- **Alternatives considered**: Finance owning the contracts was rejected. Direct reads of other modules' tables were rejected.

## Decision 9: Discounts — default no-combine, explicit-combine-with-cap, fixed sibling ordering, employee-child at due date

- **Decision**: Discount policies are configurable AND versioned per academic year and charge type. The DEFAULT is no-combination (highest-benefit applies); combination occurs ONLY when the policy explicitly states it, with a MANDATORY cap. Sibling ordering is oldest enrollment date first; ties broken by `student_id`. Employee-child eligibility is verified at the charge due date from a documented relationship and an active employment contract. `finance_discount_applications` links a discount to the specific charge/installment.
- **Rationale**: User decision D-1 (2026-07-23). Today sibling order is operator row order or insertion sequence (race-prone). There is NO employee-child discount today.
- **Alternatives considered**: Always-combine without cap was rejected (can zero/negative net). Birthdate ordering was considered but enrollment date is trusted and available.

## Decision 10: Payments & debt — auto-oldest allocation, unapplied_credit, reversal-only, prior-year as opening movement

- **Decision**: Auto-allocation to the oldest-due installment, with manual override requiring permission and a recorded reason. Overpayment becomes an independent `unapplied_credit` movement (NOT a silent balance edit). Refunds linked to receipt + payment method, maker-checker. Prior-year debt becomes a documented `opening_balance` movement linked to the original year — the old record is never deleted or modified. A closed year is never modified; correction is via reversing entries or an approved reopen. `PaymentAllocation` targets `finance_charge_installments` (the installment is the due-unit).
- **Rationale**: User decision D-2 (2026-07-23). The 2026-07-23 corrections #2 (discount_applications), #3 (allocation to installments), #4 (unapplied credits + separate equations) are encoded.
- **Alternatives considered**: Silent balance edits for overpayment were rejected. Deleting/modifying old records for prior-year debt was rejected.

## Decision 11: Payroll — monthly, effective-dated components, server-computed, reversal-of, separate settlements

- **Decision**: Monthly periodicity. Tax/insurance/overtime/deductions are configurable components with effective dates. A change takes effect from `effective_from`. Retroactive differences are a SEPARATE settlement in an open period and NEVER edit an old payslip. Gross/net computed server-side only. `staff_compensation_contract_components` is the PRIMARY source (not `snapshot_json`). `payroll_runs` support versions, settlements, and reversal via `reversal_of` (NOT a strict unique that blocks reversal). Migrated contracts do not guess `effective_from` (provenance + history_confidence).
- **Rationale**: User decision D-3 (2026-07-23). The 2026-07-23 corrections #5 (contract_components primary), #6 (payroll_runs reversal) are encoded. `staff_financial_data.php` currently stores client-computed net in JSON.
- **Alternatives considered**: Guessing `effective_from` was explicitly rejected. Editing old payslips for retroactive differences was rejected.

## Decision 12: Import/export, EGP, retention 24h, no auto-delete

- **Decision**: Currency EGP, integer piaster minor units, `DECIMAL(14,2)`. Import CSV/XLSX with schema version + staging; export CSV/XLSX/PDF. Monthly periods follow the academic year. No automatic deletion of financial records until a formal retention policy is approved; temporary export files deleted after 24 hours.
- **Rationale**: User decision D-4 (2026-07-23). `AGENTS.md` file-upload standard applies when files are stored.
- **Alternatives considered**: Auto-deletion was rejected until a formal policy exists.

## Decision 13: Maker-checker MANDATORY in v1 for sensitive operations

- **Decision**: Maker-checker is MANDATORY in v1 for: receipt reversal, refund, write-off, manual journal entries, import posting, payroll approval/payment, period reopen, and manual/exception discounts. The creator MUST NOT approve the same sensitive operation.
- **Rationale**: User decision C2 (2026-07-23). Separation of duties is a core accounting control; the audit/undo roadmap requires separating view-log permission from reversal-execution permission.
- **Alternatives considered**: Maker-checker as optional/deferred was rejected.

## Decision 14: Account-mapping policies + control-account reconciliation (multi-line, deterministic)

- **Decision**: Account-mapping is a multi-line model: `accounting_account_mapping_headers` (versioned, effective dates) + `accounting_account_mapping_lines` with selectors for charge type, payroll component, payment method, cashbox, and operation type. Resolution is deterministic: `specificity_score` DESC, then `priority` DESC, then `version_number` DESC. No two active lines may share the same `operation_type + selectors + specificity + priority`. Posting is REFUSED on zero matches, ambiguous matches, or an unbalanced journal entry. `accounting_control_accounts` reconcile student/staff/cashbox sub-ledger balances to GL.
- **Rationale**: Single debit/credit mapping cannot express multi-component payroll (basic + allowances + tax + insurance map to multiple balanced lines). The 2026-07-23 corrections #7 (multi-line model with selectors and refusal) and the final-correction #7 (deterministic resolution, no ambiguity) require this.
- **Alternatives considered**: Hardcoding account IDs per operation was rejected. A single debit/credit mapping was rejected. Non-deterministic resolution was rejected (ambiguous postings).

## Decision 15: Only the generic party sub-ledger is allowed

- **Decision**: `finance_subledger_accounts` + `finance_subledger_transactions` + `finance_subledger_lines` are the only party-ledger tables for both students and staff. No parallel student-specific ledger or separate due/credit delta columns may be introduced. Domain tables hold operational detail only; balances come from posted generic bucket lines.
- **Rationale**: One generic party sub-ledger prevents two competing truth sources and supports both student and staff reconciliation with the same posting contract.
- **Alternatives considered**: A student-specific ledger alongside a later generic ledger was rejected as duplicated schema and ambiguous truth.

## Decision 16: Reversal semantics — original stays posted, reversal adds opposite deltas

- **Decision**: A reversal creates a NEW sub-ledger transaction with `reversal_of`→original and opposite-sign bucket lines. The original remains `posted` and is ALWAYS counted in SUM. Invariant: `SUM(original lines) + SUM(reversal lines) = 0`. A refund is NOT auto-subtracted: `refund_allocation` posts `amount_delta=+amount` to `STUDENT_OUTSTANDING_DUE`; `refund_unapplied_credit` posts `amount_delta=−amount` to `STUDENT_UNAPPLIED_CREDIT`.
- **Rationale**: The final-correction #4 requires that the original is never excluded from SUM and that `original + reversal = 0`.
- **Alternatives considered**: Excluding the original from SUM was rejected (loses history, breaks audit). Auto-subtracting refunds was rejected (wrong arithmetic).

## Decision 17: 1:1 Sub-ledger ↔ GL applies only to party-affecting operations

- **Decision**: A student/staff-affecting source operation creates exactly one sub-ledger transaction and one GL journal inside the same database transaction. Source granularity is party-specific: a payroll run/import is a batch, while each payroll item/import row is an idempotent source operation. The journal carries a nullable-unique `subledger_transaction_id`, and both records share `source_idempotency_key`. A pure GL voucher/manual operation creates one journal with NULL link. Budget planning data creates no GL journal. A retry returns the original.
- **Rationale**: Party balances require atomic sub-ledger/GL linkage. Party-specific granularity avoids trying to attach one payroll-run journal to multiple staff accounts. Expense, other-income, cash-transfer, and manual GL entries can be valid without a party, while a budget is a plan rather than an actual journal event.
- **Alternatives considered**: Creating dummy party accounts/transactions for GL-only vouchers was rejected. Idempotency without database uniqueness was rejected.

## Decision 18: Versioned budget model; actuals from GL, not an independent source

- **Decision**: A versioned model `finance_budgets` / `finance_budget_versions` / `finance_budget_lines`. Cycle: draft → reviewed → approved → locked/revised. Entry is manual or via import staging. Budget `actual_amount` is NOT stored as an independent source; it is computed from posted GL journal entries via a view (`v_budget_actuals`) OR a derived cache with a documented refresh/reconciliation mechanism reconcilable to GL on demand. `admin/school_budget.php` is NOT the truth source.
- **Rationale**: User decision U1 (2026-07-23) and the 2026-07-23 correction #8 forbid storing actuals as an independent source.
- **Alternatives considered**: Reusing `admin/school_budget.php` was rejected. A non-versioned budget was rejected. Storing `actual_amount` as a column updated by a batch was rejected (hidden state).

## Decision 19: File-upload + role-footer gates are mandatory for imports and new pages

- **Decision**: Every import workflow reads `docs/file-upload-standard.md`, uses `FileUploadGuard` (real MIME, dangerous double extension, byte limit, random storage name), classifies paths in `tools/upload_policy_manifest.json`, and passes `php tools/audit_upload_policy.php --strict` + `composer upload-policy-audit`. Every new data-entry page uses shared `admin_footer`/role footer, `form-safety.js`, `undo-toast.js`, has a role-coverage contract test, and has NO page-local toast/draft/logger/competing storage key.
- **Rationale**: The 2026-07-23 corrections #9 (upload gates) and #10 (role-footer gates) encode `AGENTS.md` Future Write/Audit/Undo/Draft Contract and File Upload Standard.
- **Alternatives considered**: Page-local toast/draft/logger was rejected (violates shared components).

## Decision 20: Resync workflow blocks all implementation

- **Decision**: After the spec-only commit, inventory the full current dirty local `main`, adopt its tracked diff and required untracked source dependencies non-destructively into the isolated feature worktree, and commit that adoption separately with a manifest. `main` remains untouched; secrets/private backups/cache/log/scratch/generated files are excluded. `origin/main`, stash, reset, clean, and force are forbidden substitutes.
- **Rationale**: The actual local baseline spans many interdependent source/docs/test paths, not only the initially identified finance files. A separate recoverable baseline commit preserves user work and gives implementation a clean, reviewable starting point.
- **Alternatives considered**: Selectively copying only seven files was rejected because it can omit dependencies. Committing directly on dirty `main`, using `origin/main`, or destructive cleanup was rejected.

## Decision 21: Unified sub-ledger for students AND staff (party-typed)

- **Decision**: One generic append-only model (`finance_subledger_accounts`, `finance_subledger_transactions`, `finance_subledger_lines`) is the source of truth for ALL party balances. Student accounts are scoped by academic year. Each staff member has one EGP account with stable `scope_key=STAFF_GLOBAL`, independent of contracts or payroll models. Student buckets: `STUDENT_OUTSTANDING_DUE`, `STUDENT_UNAPPLIED_CREDIT`. Staff buckets: `STAFF_PAYROLL_PAYABLE`, `STAFF_ADVANCE_RECEIVABLE`. Retroactive settlements are separate payroll source operations posted into payroll payable so the normal payment flow clears them.
- **Rationale**: The final-correction #1 requires a general sub-ledger covering both students and staff, not two separate ledger models. This unifies reconciliation and GL control-account linkage.
- **Alternatives considered**: Separate student and staff ledger tables were rejected (duplicate architecture). Computing staff balances from `staff_profiles` columns was rejected (not append-only, not auditable).

## Decision 22: Receipt = one source operation (one sub-ledger transaction, multi-line)

- **Decision**: A receipt is ONE source operation: ONE `finance_subledger_transactions` row with multiple lines (one negative `STUDENT_OUTSTANDING_DUE` line per allocation + one positive `STUDENT_UNAPPLIED_CREDIT` line for overpayment) + ONE GL journal (same `source_idempotency_key`). All allocation/unapplied-credit rows from one receipt share the SAME `subledger_transaction_id`. Refund/application/reversal are SEPARATE transactions.
- **Rationale**: The final-correction #3 requires that one receipt produces one transaction + one GL journal, not one transaction per allocation.
- **Alternatives considered**: One transaction per allocation was rejected (fragments the receipt, breaks 1:1 GL linkage).

## Decision 23: Sub-ledger status draft|posted only; is_reversed derived

- **Decision**: `finance_subledger_transactions.status` is `draft` or `posted` ONLY — no `reversed`. The original is NEVER excluded from SUM. `is_reversed` is a DERIVED flag (another transaction has `reversal_of` = this id). `original + reversal = 0` per bucket.
- **Rationale**: The final-correction #4 requires that the original stays posted and counted, and that reversal is a separate posted transaction with opposite deltas.
- **Alternatives considered**: A `reversed` status that excludes the original from SUM was rejected (loses history, breaks audit).

## Decision 24: MariaDB-safe discount activation (no partial index)

- **Decision**: Discount active-version uniqueness is enforced by an activation transaction using `SELECT ... FOR UPDATE` on `(code, academic_year_id, scope_charge_type_key)` — no partial index assumed. Only one active version per policy scope; future versions remain `draft` until activation. A concurrency test proves two simultaneous activations fail. If a generated/computed active-key column is used, its MariaDB compatibility is documented.
- **Rationale**: The final-correction #5 requires MariaDB-safe constraints. Partial indexes are not reliably available across MariaDB versions.
- **Alternatives considered**: A partial index on `status='active'` was rejected (MariaDB compatibility risk).

## Decision 25: Staff advances and payroll period fixes

- **Decision**: `staff_advance_installments` is the PRIMARY repayment schedule source; `remaining_amount` is derived from `STAFF_ADVANCE_RECEIVABLE`. `staff_advance_movements` distinguishes cash repayment, payroll deduction, and write-off. A write-off is an independent maker-checker-approved operation, may not exceed the locked/derived remaining receivable, and is not a reversal of the advance issue. Cash repayment maps `Dr Cash/Bank, Cr Advance Receivable`; payroll deduction maps `Dr Payroll Payable, Cr Advance Receivable`; write-off maps `Dr Advance Write-off Expense, Cr Advance Receivable`. `payroll_periods` uniqueness is `finance_period_id + start_date + end_date`.
- **Rationale**: A repayment plan must be queryable and auditable, and financially different repayment methods require different GL mappings. Treating a partial write-off as reversal of the original advance can create a negative receivable.
- **Alternatives considered**: JSON as primary, combined repayment/deduction source type, write-off-as-reversal, and unique-by-period-name were rejected.

## Decision 26: General vouchers in v1; AP/supplier out of scope

- **Decision**: `finance_vouchers` (expense, other_income, cash_transfer) + `finance_voucher_lines` are in scope for v1 with cost center, documents, maker-checker, reversal-only, and GL mapping. Expense/other-income references one holding cashbox. Cash transfer requires positive amount and distinct non-null source/destination cashboxes. Vouchers are pure GL operations and create no party sub-ledger transaction. `admin/finance_vouchers.php` and expense/revenue/cash-transfer reports exist. Supplier/AP is OUT OF SCOPE for v1.
- **Rationale**: The final-correction #7 requires that Full GL + P&L in v1 includes general operations, and that AP scope is explicitly resolved, not implicit.
- **Alternatives considered**: Leaving AP implicit was rejected. Deferring all vouchers was rejected (needed for P&L).

## Confirmed Runtime

- PHP 8.0+ per `composer.json`; MariaDB/MySQL via PDO; XAMPP/Windows.
- `AuditService` is the shared write-owner contract: `recordInsert/Update/Delete/Event/CompositeUpdate/Replacement` throw `RuntimeException` on failure; `batch_id` via `UndoManager::newBatchId()`, `request_id` via `AuditContext::requestId()`.
- `AuditPolicyRegistry` has six policy axes; `REVERSAL_ONLY_TABLES = ['fee_payments']` today; new finance tables will be added.
- `AcademicYearWriteGuard` is the shared rejection contract for locked years; `FinancePeriodGuard` extends it.
- Test DB is cloned from the live source structure by `tools/clone_schema_to_test_database.php` and requires `EDUCORE_TEST_DB_NAME` ending in `_test`; no PHPUnit, tests are plain PHP scripts.
- `composer quality` runs `audit-write-coverage` (must end `AUDIT_REVIEW_REQUIRED=0`), `architecture-audit`, `admin-ui-audit`, and the test suite.
- PowerShell is the shell for quickstart examples (not bash).

## Resolved Decisions (all formerly deferred)

- D-1 Discounts: resolved — default no-combine, explicit-combine-with-cap, oldest-enrollment-first (ties by `student_id`), employee-child at due date. (Decision 9)
- D-2 Payments & debt: resolved — auto-oldest allocation, unapplied_credit, reversal-only, prior-year as opening movement, closed year never modified. (Decision 10)
- D-3 Payroll: resolved — monthly, effective-dated components, server-computed, reversal-of, separate settlements, no guessed `effective_from`. (Decision 11)
- D-4 Import/export & currency: resolved — EGP, piaster minor units, CSV/XLSX/PDF, monthly periods follow academic year, no auto-delete, 24h temp file retention. (Decision 12)
- C2 Maker-checker: resolved — MANDATORY in v1 for sensitive operations. (Decision 13)
- U1 Budget: resolved — versioned model, actuals from GL only, `admin/school_budget.php` not the truth source. (Decision 14)

No decisions remain deferred.
