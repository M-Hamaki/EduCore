# Feature Specification: Finance Ledger & Payroll

**Feature Branch**: `feature/003-finance-ledger-payroll` (worktree at `C:\xampp\worktrees\EduCore-finance` — NOT clean: `.specify/feature.json` is modified and `specs/003-finance-ledger-payroll/` is untracked; isolated from the in-progress dirty `main` worktree)

**Created**: 2026-07-22

**Status**: Implementation and convergence verification in progress on the isolated feature worktree; no push, production deployment, `.env` change, or write to `educore` (updated 2026-07-26)

**Input**: User description: "تصميم وتنفيذ تطوير شامل وتدريجي لنظام مالية الطلاب والعاملين، دون إعادة كتابة المشروع أو كسر الصفحات والعقود الحالية. وحدة تدريجية تحت src/Modules/Finance، مع إبقاء صفحات admin الحالية كـ compatibility adapters رفيعة، واتجاه Entrypoint → Application Service → Domain Policy → Repository Contract → PDO Infrastructure، reversal-only للمدفوعات والرواتب والقيود، مع إقفال فترات وفصل صلاحيات."

## Clarifications

### Session 2026-07-22

- Q: هل النطاق المالي المطلوب يشمل محاسبة مزدوجة كاملة (GL/دليل حسابات/ميزانية فعلية) أم دفتر طلاب وعاملين فقط في الموجة الأولى؟ → A: محاسبة مزدوجة كاملة.

### Session 2026-07-23 (all deferred decisions resolved)

- **D-1 Discounts**: Policies are configurable and versioned per academic year and charge type. The DEFAULT is NO combination — the highest-benefit discount applies. Combination occurs ONLY when the policy explicitly states it, with a MANDATORY cap. Sibling ordering is oldest enrollment date first; ties broken by `student_id`. Employee-child eligibility is verified at the charge due date from a documented relationship and an active employment contract.
- **D-2 Payments & Debt**: Auto-allocation to the oldest-due installment, with manual override requiring permission and a recorded reason. Overpayment becomes an independent `unapplied_credit` movement (NOT a silent balance edit). Refunds are linked to the receipt and payment method and require review and approval. Prior-year debt becomes a documented `opening_balance` movement linked to the original year — the old record is never deleted or modified. A closed year is never modified; correction is via reversing entries or an approved reopen.
- **D-3 Payroll**: Monthly periodicity. Tax, insurance, overtime, and deductions are configurable components with effective dates. A change takes effect from `effective_from`. Retroactive differences are recorded as a SEPARATE settlement in an open period and NEVER edit an old payslip. Gross/net are computed server-side only.
- **D-4 Import/Export & Currency**: Currency is EGP, computed in piaster minor units (no PHP float), stored as `DECIMAL(14,2)`. Import is CSV/XLSX; export is CSV/XLSX/PDF. Monthly periods follow the academic year. No automatic deletion of financial records until a formal retention policy is approved; temporary export files are deleted after 24 hours. Quickstart examples use PowerShell (not bash).
- **C2 Maker-Checker**: MANDATORY in v1 for: receipt reversal, refund, write-off, manual journal entries, import posting, payroll approval/payment, period reopen, and manual/exception discounts. The creator MUST NOT approve the same sensitive operation.
- **U1 Budget**: A new versioned model is in scope: `finance_budgets`, `finance_budget_versions`, `finance_budget_lines`. Budget cycle is draft → reviewed → approved → locked/revised. Entry is manual or via import staging. Actuals come EXCLUSIVELY from posted GL journal entries. The current `admin/school_budget.php` is NOT the truth source for the new budget model.

## Scope And Non-Scope

### In Scope

- A new incremental `src/Modules/Finance` module (fees, payments, salaries, financial operations, full double-entry accounting) following Entrypoint → Application Service → Domain Policy → Repository Contract → PDO Infrastructure, with no new framework/router/ORM/auth stack.
- **Full double-entry accounting (GL) in v1**: chart of accounts, account-mapping policies, journal entries and lines, cost centers (stage/grade/bus/activity/department), control accounts, trial balance, P&L, cash flow, budget vs actual, and cash/bank movement — IN SCOPE for v1, with the invariant that total debit equals total credit per journal entry. Every posted Student/Payroll/Cashbox operation MUST create a balanced GL journal entry INSIDE THE SAME TRANSACTION (GL is NOT a later phase).
- Student/staff sub-ledger balances MUST reconcile to GL control accounts via control-account reconciliation.
- Versioned fee plans, installments, and a per-student annual financial contract that snapshots the plan at assignment time.
- Per-student special fees via override or separate charge without editing the grade plan.
- Independent charges per line/installment carrying gross amount, discount, adjustment, net due, due date, status, source, and academic_year_id.
- Configurable, versioned discount policies (per academic year and charge type): sibling, employee_child, scholarship, hardship, manual, exemption, promotional — with effective date, priority, default-no-combine (highest-benefit applies), explicit-combine-with-cap, document/reason, requester, approver. Sibling ordering: oldest enrollment date first, ties by `student_id`. Employee-child eligibility verified at charge due date from a documented relationship + active employment contract.
- `finance_discount_applications` linking a discount to the specific charge/installment it applies to.
- Bus fees tied to a subscription/area/route with effective date and academic year, splittable into independent installments.
- Independent, immutable-after-posting payment receipts with a per-cashbox/per-year receipt numbering policy/sequence and an idempotency key to prevent duplicate payment on retry.
- `PaymentAllocation` targeting `finance_charge_installments` (the installment is the due-unit), distributing one receipt across one or more installments with partial payment, prepayment, overpayment, refund, and reversal. Student debt write-off/credit/debit adjustment is a separate maker-checker operation, never disguised as a payment allocation.
- A clear `unapplied_credits` model with separate equations: `outstanding_due`, `unapplied_credit`, `net_account_position`.
- Student and staff balances computed EXCLUSIVELY from posted `finance_subledger_lines.amount_delta` grouped by bucket (NOT a directly editable standalone number, NOT from domain-table sums or a fixed list of table names). Opening balance is a documented sub-ledger transaction, not a parallel truth source. No parallel student-specific ledger tables are introduced.
- Prior-year debt carried forward as a documented `opening_balance` movement linked to the original year, without deleting or modifying the old record.
- Period close that forbids any backdated modification or movement except via a recorded reopen action with higher permission.
- Staff financial data separated from `staff_profiles`: one lifetime EGP sub-ledger account per staff member using stable scope `STAFF_GLOBAL`; compensation contracts with effective_from/effective_to/status/approval; `staff_compensation_contract_components` as queryable, audited rows (snapshot_json is NOT the primary component source); payroll cycle draft→calculated→reviewed→approved→posted→paid with reversal handling; `payroll_runs` support versions, settlements, and reversal via `reversal_of` (NOT a strict unique that blocks reversal); Payroll Run per period, Payroll Item per staff, frozen component copy; salary contract changes do not alter historical payslips; advances with normalized repayment schedules, separate cash-repayment/payroll-deduction flows, and approved write-off limited to the remaining receivable; attendance/overtime/job-movement linkage via documented contracts with StaffHr/Attendance; server-computed gross/net (no trust in JS-sent net); printable/exportable payslip with reference number and payment status; staff financial ledger showing contracts, runs, advances, payments, write-offs, and settlements.
- Cashboxes and bank accounts with daily settlement.
- Versioned budget model (`finance_budgets`/`finance_budget_versions`/`finance_budget_lines`) with cycle draft→reviewed→approved→locked/revised; manual or import-staging entry; actuals exclusively from posted GL journal entries.
- Account-mapping policies mapping each finance operation type to GL accounts; control-account reconciliation.
- Archive pages for disabled settings/plans/rules showing reason, actor, time, status, approvals, and restore possibility or the reason it is blocked.
- Import (CSV/XLSX with schema version, staging+preview, full validation, dedup/idempotency, approval before posting, one batch per operation, reversal batch for rollback — never delete on financial posting; reject import into a closed year; handle dangerous files, size, encoding, Arabic names per `docs/file-upload-standard.md` when a file is stored). No automatic deletion of financial records until a formal retention policy is approved; temporary export files deleted after 24 hours.
- Export (CSV/XLSX/PDF per report) respecting filters, columns, and permissions; logging actor/time/report-type/filters/row-count without copying report content into audit; temp files with 24-hour retention and cleanup.
- All required finance pages, dashboards, ledgers, archive, payroll, receipts, statistics, and reports (see Page Inventory section), each following `admin/ui_preview.php` and `AGENTS.md` rules (no page-local button/stat-card CSS, `admin-filter-bar`/`admin-list-surface`/`admin-data-table`, `stat-card` with `counter`/`data-target`, `btn-action-pills`, Bootstrap modals not `confirm()`/`alert()`/SweetAlert, server-side DataTables for large tables, RTL responsive printable).
- Student reports (due/paid/remaining/collection rate by year/stage/grade/class/fee type; due/overdue installments; debt aging current/1–30/31–60/61–90/90+; discount analysis by type/reason/approver; sibling and employee-child discounts; bus revenue and arrears; daily payments by cashbox/method/receiver; cancelled receipts/returns/reversals; family aggregate account; students with no financial contract or incomplete plan; ledger-vs-summary reconciliation).
- Staff reports (payroll cost by month/department/title; basic/allowances/deductions/tax/insurance; net paid/unpaid; period-over-period comparison with variance explanation; contract/salary changes by effective date; advances paid/remaining/delinquent; individual payslip and full financial history; payroll approval/operation/payment report).
- Accounting reports (revenue/expenses; budget vs actual; cash/bank movement; journal and ledger; trial balance; cash flow; customer/student and staff balances; cost centers by stage/grade/bus/activity).
- Separation of duties with MANDATORY maker-checker in v1 for: receipt reversal, refund, write-off, manual journal entries, import posting, payroll approval/payment, period reopen, and manual/exception discounts. Proposed independent permissions: `finance_view`, `student_charge_manage`, `payment_record`, `payment_reverse`, `discount_request`, `discount_approve`, `payroll_prepare`, `payroll_review`, `payroll_approve`, `payroll_pay`, `finance_export`, `finance_audit_view`, `period_close`, `period_reopen`, `budget_manage`. The creator MUST NOT approve the same sensitive operation.
- Incremental conversion of legacy admin pages into compatibility adapters alongside each vertical slice and after characterization tests, preserving URLs, POST fields, and JSON contracts. Adapters are NOT all deferred to a final phase.
- Cross-module communication with Students, StaffHr, Transport, and AcademicStructure via small documented Query/Application contracts OWNED BY THE SOURCE MODULES (not by Finance) — never by reaching into their internals.
- Registration of every new finance entity in `AuditPolicyRegistry` and routing every new write owner through `AuditService` inside the business transaction with a shared `batch_id`/`request_id`.
- Migrations with dated, project-style names and explicit preconditions + rollback, only in `database/migrations/`; runtime DDL forbidden.
- Money as integer minor units (piasters) or decimal strings with a unified rounding policy in PHP (no float for financial math); DECIMAL(14,2) in the database; EGP currency.
- Reconciliation that recomputes summary balances from posted movements (charges + adjustments + credit notes + allocations + reversals + refunds).
- Feature flag / read-only adapter for gradual cutover; avoid long dual-write.
- Explicit BusSubscriptionQuery wiring task and test.
- Prior-year debt migration task and test.

### Out Of Scope

- Rewriting the whole project, replacing the framework, introducing a new router/auth stack/ORM, or any parallel architecture.
- Touching any currently dirty `main` path until the current user-owned source state is adopted into this isolated feature worktree as a reviewed baseline commit. Adoption MUST leave `main` untouched, include tracked source/docs/tests/migrations and required untracked source dependencies, and exclude secrets, ignored data, backups, caches, logs, generated artifacts, and `scratch/`.
- Production deployment, push, migration execution, or any write against the `educore` database.
- Reading or using any data dump under `storage/private/db_backups` as a source of truth; schema is inspected only from an isolated database whose name ends in `_test`, and no personal data is displayed.
- Deleting legacy tables in the same cutover release as their replacement.
- Hardcoding an `effective_from` date for migrated legacy salary contracts.
- Automatic deletion of financial records before a formal retention policy is approved.
- Treating `admin/school_budget.php` as the truth source for the new budget model.

### Compatibility Baseline

- Preserve every existing admin URL, POST `action` value, form `name`/`id`, form `action`, hidden inputs, CSRF fields, session keys (`user_id`, `role`, `name`, `csrf_token`, `active_role`), JSON response field names, permissions, and SQL behavior of the current finance pages unless an explicitly approved, separately specified compatibility change requires otherwise.
- Legacy pages (`admin/fee_structure.php`, `admin/fee_calculator.php`, `admin/fee_payments.php`, `admin/ajax_fee_payments_datatable.php`, `admin/staff_financial_data.php`, `admin/school_budget.php`, `admin/student_buses.php`, `admin/bus_report.php`, `admin/statements.php`) remain valid HTTP entrypoints and keep their observable contracts. Finance-owned operations delegate to Finance services; student-bus writes delegate to the Transport-owned application service; the Transport report and official student-statement workflow remain source-owned passthrough surfaces rather than being misclassified as Finance.
- `AcademicYearWriteGuard` remains the shared rejection contract for protected finance writes; the new period-close guard extends, not replaces, it.
- `AuditService` / `UndoManager` / `ActivityLog` shared architecture is reused; no parallel audit/undo path is introduced.
- `admin/ui_preview.php` is the canonical visual and structural reference; shared CSS in `assets/css/buttons.css`, `assets/css/premium-dashboard.css`, `assets/css/admin-unified.css`; no page-local button/stat-card CSS.
- No `confirm()`/`alert()`/SweetAlert; Bootstrap modals only; server-side DataTables for large tables; RTL responsive printable UI.
- The schema inspection source is an isolated `*_test` database only; production dumps under `storage/private/db_backups` are explicitly excluded as a truth source.

## User Scenarios & Testing

### User Story 1 - Versioned Fee Plans and Per-Student Annual Contract (Priority: P1)

As a finance administrator, I want a different fee plan per grade and academic year, with versions that are never retroactively edited after use, and a per-student annual contract that snapshots the plan at assignment, so that historical charges remain stable and auditable.

**Why this priority**: Every downstream payment, discount, and reconciliation depends on a stable, versioned plan.

**Independent Test**: On an isolated `*_test` database, create a plan for grade X / year Y, use it, then attempt to edit the used version — the edit is refused and a new version is created; assign a student and prove the contract snapshot is immutable even if the plan later changes; generate bus charges via `BusSubscriptionQuery` (not the legacy string match).

**Acceptance Scenarios**:

1. **Given** an active plan for grade X and year Y, **When** an admin assigns a student, **Then** a contract is created with a frozen snapshot of the plan version and cannot be altered by later plan edits.
2. **Given** a used plan version, **When** an edit is attempted, **Then** the system refuses retroactive edit and requires creating a new version.
3. **Given** a student with a special fee need, **When** an admin adds a special charge, **Then** the grade plan is unchanged and the student gets an independent charge.
4. **Given** a student with a bus subscription, **When** charges are generated, **Then** the bus charge is created from `BusSubscriptionQuery` data, not from the legacy `buses.area == bus_fee_zones.zone_name` string match.

---

### User Story 2 - Eligibility-Proven Discounts with Fixed Ordering (Priority: P1)

As a finance administrator, I want sibling and employee-child discounts applied from a trusted family group and a documented employment relationship, with a fixed sibling ordering and a default-no-combine policy (highest-benefit applies, explicit-combine-with-cap when stated), so that discounts are fair, reproducible, and not derived from charge-generation order.

**Why this priority**: Discounts directly reduce net due; an unstable ordering or unverified eligibility creates financial errors and audit risk.

**Independent Test**: On an isolated `*_test` database, enroll three siblings with different enrollment dates and prove the sibling order is by oldest enrollment date first (ties by `student_id`) regardless of charge generation order; create an employee-child discount and prove it is granted only when an active employment relationship is documented at the charge due date; prove default-no-combine applies the highest-benefit discount and explicit-combine respects the cap.

**Acceptance Scenarios**:

1. **Given** a family group of three siblings with distinct enrollment dates, **When** charges are generated, **Then** the sibling discount tier for each is fixed by oldest-enrollment-date-first order (ties by `student_id`) and is stable across regeneration.
2. **Given** an employee-child discount rule, **When** a student charge is due, **Then** the discount is applied only if a documented relationship to an active employment contract exists at the charge due date; otherwise it is refused.
3. **Given** two discount rules with default-no-combine policy, **When** both could apply, **Then** only the highest-benefit discount applies; when the policy explicitly states combinability with a cap, **Then** both apply up to the cap.
4. **Given** a discount is applied, **When** it is awarded, **Then** a `finance_discount_application` links the discount to the specific charge/installment.

---

### User Story 3 - Immutable Receipts, Allocation, Idempotent Reversal-Only Collection (Priority: P1)

As a collector, I want to record a payment that is distributed across installments, with an idempotency key that prevents duplicate payment on retry, an immutable receipt with a per-cashbox/per-year numbering policy, and an overpayment that becomes an independent `unapplied_credit` (not a silent balance edit), so that the ledger is append-only and duplicate submissions are impossible.

**Why this priority**: Collection is the primary cash-inflow path; duplicates and hard-deletes are the highest financial risk.

**Independent Test**: On an isolated `*_test` database, post a partial payment allocated to `finance_charge_installments`, retry with the same idempotency key and prove only one receipt exists; post an overpayment and prove the excess is an independent `unapplied_credit` movement; cancel the receipt and prove the original remains and a reversing entry is created; attempt a hard-delete and prove it is refused; prove the receipt number follows the per-cashbox/per-year sequence.

**Acceptance Scenarios**:

1. **Given** a posted receipt, **When** the same request is retried with the same idempotency key, **Then** no duplicate receipt is created and the original is returned.
2. **Given** a posted receipt, **When** it is cancelled, **Then** the original remains unchanged and a reversing entry is created with a `reversal_of` link; a hard-delete is refused.
3. **Given** an overpayment, **When** the receipt amount exceeds the due, **Then** the excess becomes an independent `unapplied_credit` movement (not a silent balance edit) and `net_account_position` reflects it.
4. **Given** a closed period, **When** any backdated write is attempted, **Then** it is rejected unless a recorded reopen action with higher permission has occurred.
5. **Given** a receipt is posted, **When** it is allocated, **Then** the allocation targets `finance_charge_installments` (the installment is the due-unit).
6. **Given** a receipt is posted, **When** it is created, **Then** a balanced GL journal entry is created INSIDE THE SAME TRANSACTION.
7. **Given** a refund, **When** it is requested, **Then** it is linked to the receipt and payment method and requires review and approval (maker-checker).

---

### User Story 4 - Server-Computed Payroll with Effective-Dated Contracts and Components (Priority: P2)

As a payroll administrator, I want compensation contracts with effective dates, salary components as auditable rows (`staff_compensation_contract_components`, not `snapshot_json` as primary source), and a payroll cycle draft→calculated→reviewed→approved→posted→paid, with the server computing gross/net, `payroll_runs` supporting versions/settlements/reversal via `reversal_of`, so that payslips are correct, historical payslips are stable, net is never trusted from the client, and retroactive differences are a separate settlement in an open period.

**Why this priority**: Payroll is high-value and currently stores client-computed net in JSON; correctness and historical stability are mandatory before any cutover.

**Independent Test**: On an isolated `*_test` database, create a compensation contract effective from a business-decided date with components as rows, run a monthly payroll cycle through to posting, then change the contract and prove the posted payslip is unchanged, the new contract applies only from its effective date, and both appear in the same `STAFF_GLOBAL` ledger; prove gross/net are computed server-side and a tampered client-sent net is ignored; prove a retroactive difference is a separate settlement posting into payroll payable and does NOT edit the old payslip; issue an advance of 1000, repay 200, approve a write-off of 800 and prove the balance becomes zero while a write-off of 1000 is refused; prove cash repayment and payroll deduction use distinct GL mappings; prove `payroll_runs` supports reversal via `reversal_of`; print a payslip with a reference number and payment status.

**Acceptance Scenarios**:

1. **Given** a posted payslip, **When** the staff compensation contract is changed with a new effective date, **Then** the historical payslip remains unchanged and the new contract applies only from its effective date.
2. **Given** a payroll run, **When** gross/net are calculated, **Then** the server computes them from `staff_compensation_contract_components` (basic, allowances, bonuses, overtime, insurance, tax, attendance deductions, penalties, advances) and ignores any client-sent net value.
3. **Given** a payroll cycle, **When** it advances through draft→calculated→reviewed→approved→posted→paid, **Then** each transition is audited and a posted payslip is reversal-only (no hard-delete); reversal uses `reversal_of`.
4. **Given** a staff advance of 1000 with 200 already repaid, **When** an authorized independent approver accepts a write-off of 800, **Then** the remaining advance becomes zero; a write-off above 800 is refused and the write-off is not represented as a reversal of the original advance.
5. **Given** an advance repayment, **When** it is paid in cash versus deducted from payroll, **Then** both reduce `STAFF_ADVANCE_RECEIVABLE` but use their distinct GL mappings.
6. **Given** a retroactive salary change, **When** the difference is computed, **Then** it is recorded as a separate settlement in an OPEN period, posts to `STAFF_PAYROLL_PAYABLE`, follows the normal payroll-payment clearing path, and does NOT edit the old payslip.
7. **Given** a posted payslip, **When** payroll is posted, **Then** one linked balanced GL journal entry is created INSIDE THE SAME TRANSACTION.

---

### User Story 5 - Period Close, Archive, Import/Export, Budget, GL Reports with Maker-Checker (Priority: P2)

As a finance director, I want to close a financial period, archive disabled settings, run a safe staged import, manage a versioned budget, export permission-filtered reports, generate GL reports (trial balance, P&L, cash flow, budget vs actual), with MANDATORY maker-checker for sensitive operations, so that the books are closed cleanly and operations are auditable.

**Why this priority**: Period integrity, budgeting, and safe bulk operations are required before the module can operate production-financially.

**Independent Test**: On an isolated `*_test` database, close a period and prove backdated writes are rejected; reopen with higher permission and prove it is logged; run a staged import with an invalid row and prove no business write occurs during preview; post the batch (maker-checker) and reconcile; create a versioned budget (draft→reviewed→approved→locked) and prove actuals come exclusively from posted GL entries; export a report and prove the export log records actor/time/filters/row-count without copying content and the temp file is deleted after 24 hours; generate trial balance, P&L, cash flow, budget-vs-actual; archive a setting and prove it is restorable or blocked with a reason; prove the creator cannot approve the same sensitive operation.

**Acceptance Scenarios**:

1. **Given** an open period, **When** it is closed by an authorized actor, **Then** backdated writes are rejected and a reopen requires higher permission and is logged.
2. **Given** an import file with some invalid rows, **When** the file is staged and previewed, **Then** no business write occurs; errors are shown per row/field; posting requires approval (maker-checker).
3. **Given** a posted import batch, **When** it needs correction, **Then** the correction is a reversal batch, not a delete; the original batch remains.
4. **Given** an export request, **When** it runs, **Then** it respects filters/columns/permissions and the audit log records actor/time/report-type/filters/row-count without report content; the temp file is deleted after 24 hours.
5. **Given** a disabled setting/plan/rule, **When** it is archived, **Then** the archive shows reason/actor/time/status/approvals and restore possibility or the blocking reason; a posted movement cannot be hard-deleted.
6. **Given** maker-checker, **When** a sensitive operation (receipt reversal, refund, write-off, manual journal, import posting, payroll approval/payment, period reopen, manual/exception discount) is created by actor A, **Then** it cannot be approved by actor A.
7. **Given** a budget, **When** it is created (manual or import staging), **Then** it follows draft→reviewed→approved→locked/revised; actuals come EXCLUSIVELY from posted GL journal entries.
8. **Given** GL data, **When** reports are generated, **Then** trial balance, P&L, cash flow, and budget-vs-actual are available and respect cost centers.

---

### User Story 6 - Reconciliation, Unified Ledger, and Gradual Adapter Cutover (Priority: P3)

As a finance administrator, I want student/staff balances computed EXCLUSIVELY from posted `finance_subledger_lines.amount_delta` by bucket, party-affecting sub-ledger transactions linked 1:1 to GL journal entries, GL-only operations posted without fake party transactions, domain totals matching sub-ledger deltas, original + reversal = zero net effect, and legacy pages converted to thin adapters slice by slice after characterization tests, so that cutover is gradual, reversible, and provably correct.

**Why this priority**: Cutover safety is the final gate; a single ledger truth source proves the new balances match the old truth before any path is retired.

**Independent Test**: On an isolated `*_test` database, run reconciliation for every student and staff member by `SUM(finance_subledger_lines.amount_delta)` per bucket and prove student balances match the legacy balance and staff balances survive compensation-contract changes; prove each party-affecting sub-ledger transaction has exactly one GL journal entry while a pure expense voucher has one GL journal and no sub-ledger transaction; prove `original + reversal = zero net effect`; prove domain totals match sub-ledger deltas; convert one legacy page to an adapter after its characterization tests pass and prove URLs/POST/JSON remain compatible.

**Acceptance Scenarios**:

1. **Given** migrated sub-ledger transactions/lines, **When** reconciliation runs, **Then** for each student `outstanding_due = SUM(amount_delta WHERE bucket_code=STUDENT_OUTSTANDING_DUE)`, `unapplied_credit = SUM(amount_delta WHERE bucket_code=STUDENT_UNAPPLIED_CREDIT)`, and `net_account_position = outstanding_due − unapplied_credit` — all computed EXCLUSIVELY from posted `finance_subledger_lines`, and matching the legacy `balance`.
2. **Given** sub-ledger balances, **When** control-account reconciliation runs, **Then** the student/staff sub-ledger totals reconcile to the GL control account balances.
3. **Given** a legacy page with passing characterization tests, **When** it is converted to an adapter, **Then** its URL, POST fields, and JSON response fields remain compatible and behavior is unchanged for callers.
4. **Given** a feature flag, **When** the new ledger path is enabled in shadow mode, **Then** it computes balances from movements and compares to the legacy `balance` without dual-writing; at zero differences it is promoted to read source.
5. **Given** a legacy table, **When** its users reach zero, **Then** it is retired only after a rollback drill.

### Edge Cases

- A plan is used by a contract and then edited — must refuse retroactive edit.
- Two siblings generated concurrently — must not both compute the same discount tier; ties broken by `student_id`.
- An employee relationship is inactive or missing at the charge due date — employee-child discount must be refused.
- A bus area name does not exactly match a zone string (case/whitespace) — must use `BusSubscriptionQuery`, not the legacy string match.
- A payment is retried with the same idempotency key — must return the original, not duplicate.
- An overpayment — must become an independent `unapplied_credit`, not a silent balance edit.
- A receipt is cancelled — original must remain, reversing entry must be created, hard-delete must be refused.
- A backdated write targets a closed period — must be rejected unless a recorded reopen occurred.
- A client-sent net salary is tampered — must be ignored; server recomputes.
- A salary contract change has no effective date — must not be applied; effective date is required.
- A retroactive salary difference — must be a separate settlement in an open period, not an old-payslip edit.
- An import file has invalid rows — no business write during preview.
- An import targets a closed year — must be rejected.
- A reversal is attempted by the same actor who recorded the original (maker-checker) — must be refused.
- A posted movement is targeted by hard-delete — must be refused.
- A reconciliation difference is found — must be a documented adjustment, not a silent balance overwrite.
- A legacy page adapter changes a URL/POST field/JSON field — must be rejected unless an approved compatibility change is on file.
- Concurrent payments on the same installment — must be serialized via `FOR UPDATE`/optimistic revision and conflict must be surfaced.
- A budget actual differs from GL — must use GL as the exclusive truth, not the budget line.
- A `payroll_runs` reversal — must use `reversal_of`, not be blocked by a strict unique.
- A GL journal entry is unbalanced at posting — must be refused.

## Requirements

### Functional Requirements

- **FR-001**: The system MUST introduce an incremental `src/Modules/Finance` module with dependency direction Entrypoint → Application Service → Domain Policy → Repository Contract → PDO Infrastructure and MUST NOT introduce a new framework, router, ORM, or auth stack.
- **FR-002**: Fee plans MUST be versioned; a used version MUST NOT be edited retroactively; a new version MUST be created instead.
- **FR-003**: A per-student annual financial contract MUST snapshot the plan version at assignment and remain immutable to later plan edits.
- **FR-004**: Special student fees MUST be possible via override or separate charge WITHOUT modifying the grade plan.
- **FR-005**: Every charge MUST carry gross amount, discount, adjustment, net due, due date, status, source, and academic_year_id.
- **FR-006**: Discount policies MUST be configurable AND versioned per academic year and charge type, with effective date, priority, document/reason, requester, and approver.
- **FR-007**: The DEFAULT discount policy MUST be no-combination (highest-benefit applies); combination MUST occur ONLY when the policy explicitly states it, with a MANDATORY cap.
- **FR-008**: Sibling ordering MUST be oldest enrollment date first, with ties broken by `student_id`; it MUST NOT derive from charge-generation order.
- **FR-009**: Employee-child discount eligibility MUST be verified at the charge due date from a documented relationship and an active employment contract, not from a manual discount name.
- **FR-010**: A `finance_discount_application` MUST link a discount to the specific charge/installment it applies to.
- **FR-011**: Bus fees MUST be tied to a subscription/area/route with effective date and academic year, MUST be splittable into independent installments, and MUST be generated from `BusSubscriptionQuery` (not the legacy `buses.area == bus_fee_zones.zone_name` string match).
- **FR-012**: Payment receipts MUST be independent and immutable after posting; the receipt number MUST follow a per-cashbox/per-year numbering policy/sequence.
- **FR-013**: Every payment receipt MUST carry an idempotency key; a retry with the same key MUST return the original receipt, not create a duplicate.
- **FR-014**: `PaymentAllocation` MUST target `finance_charge_installments` and support partial payment, prepayment, overpayment, refund, and reversal. Student debt write-off, credit note, and debit note MUST be separate adjustment source operations; a debt write-off MUST be maker-checker approved, positive, and capped at the locked remaining due.
- **FR-015**: Overpayment MUST become an independent `unapplied_credit` movement (NOT a silent balance edit); the system MUST expose separate equations: `outstanding_due`, `unapplied_credit`, `net_account_position`.
- **FR-016**: Refunds MUST be linked to the receipt and payment method and MUST require review and approval (maker-checker).
- **FR-017**: Student account balances (`outstanding_due`, `unapplied_credit`, `net_account_position`) MUST be computed EXCLUSIVELY from posted `finance_subledger_lines.amount_delta` in the student buckets; `finance_student_accounts` MUST NOT carry an authoritative balance or opening_balance column.
- **FR-018**: Prior-year debt MUST become a documented `opening_balance` movement linked to the original year; the old record MUST NOT be deleted or modified.
- **FR-019**: A closed year MUST NOT be modified; correction MUST be via reversing entries or an approved reopen.
- **FR-020**: Period close MUST forbid backdated modification or movement except via a recorded reopen action with higher permission.
- **FR-021**: Staff financial data MUST be separated from `staff_profiles`; compensation contracts MUST carry effective_from/effective_to/status/approval.
- **FR-022**: `staff_compensation_contract_components` MUST be the primary source of contract components; `snapshot_json` MUST NOT be the primary source.
- **FR-023**: Salary components (tax, insurance, overtime, deductions) MUST be configurable components with effective dates.
- **FR-024**: The payroll cycle MUST be draft→calculated→reviewed→approved→posted→paid; periodicity MUST be monthly; cancellation MUST be by reversal.
- **FR-025**: `payroll_runs` MUST support versions, settlements, and reversal via `reversal_of` (NOT a strict unique that blocks reversal).
- **FR-026**: A Payroll Run per period and a Payroll Item per staff MUST hold a frozen copy of all components; salary contract changes MUST NOT alter historical payslips.
- **FR-027**: Staff advances MUST have a repayment schedule, installments, and remaining balance.
- **FR-028**: Attendance/overtime/job-movement linkage MUST go through documented contracts with StaffHr/Attendance; no direct access to their internals.
- **FR-029**: The server MUST compute gross/net salary from components; a client-sent net value MUST be ignored.
- **FR-030**: A payslip MUST be printable/exportable with a reference number and payment status.
- **FR-031**: A retroactive salary difference MUST be recorded as a SEPARATE settlement in an OPEN period and MUST NOT edit an old payslip.
- **FR-032**: Cashboxes and bank accounts MUST support daily settlement.
- **FR-033**: Archive pages MUST show reason, actor, time, status, approvals, and restore possibility or the blocking reason; a posted movement MUST NOT be hard-deleted.
- **FR-034**: Import MUST use CSV/XLSX templates with a clear schema version, stage to preview (no business write during preview), validate all rows with per-row/field errors, deduplicate, support idempotency, require approval (maker-checker) before posting, use one batch per operation, and use a reversal batch (not delete) for rollback; import into a closed year MUST be rejected; dangerous files, size, encoding, and Arabic names MUST be handled per `docs/file-upload-standard.md` when a file is stored.
- **FR-035**: No financial record MUST be automatically deleted until a formal retention policy is approved; temporary export files MUST be deleted after 24 hours.
- **FR-036**: Export MUST be CSV/XLSX/PDF, respect filters, columns, and permissions; the audit log MUST record actor/time/report-type/filters/row-count WITHOUT copying report content; temp files MUST have 24-hour retention and cleanup.
- **FR-037**: Currency MUST be EGP; money MUST be integer piaster minor units in PHP (no float) and `DECIMAL(14,2)` in the database; a unified rounding policy MUST apply.
- **FR-038**: Monthly periods MUST follow the academic year.
- **FR-039**: The system MUST propose and request adoption of independent permissions: `finance_view`, `student_charge_manage`, `payment_record`, `payment_reverse`, `discount_request`, `discount_approve`, `payroll_prepare`, `payroll_review`, `payroll_approve`, `payroll_pay`, `finance_export`, `finance_audit_view`, `period_close`, `period_reopen`, `budget_manage`.
- **FR-040**: Maker-checker MUST be MANDATORY in v1 for: receipt reversal, refund, write-off, manual journal entries, import posting, payroll approval/payment, period reopen, and manual/exception discounts; the creator MUST NOT approve the same sensitive operation.
- **FR-041**: Cross-module communication with Students, StaffHr, Transport, and AcademicStructure MUST use small documented Query/Application contracts OWNED BY THE SOURCE MODULES (not by Finance); direct access to another module's internals or tables is forbidden in new code.
- **FR-042**: Every table created by the finance migrations MUST be registered in `AuditPolicyRegistry` with an explicit policy for undo eligibility, reversal-only behavior, actor scope, retention, redaction, conflicts, and external effects. Coverage MUST include generic sub-ledger accounts/transactions/lines; fees/contracts/charges/installments; discounts; receipts/allocations/credits/refunds/adjustments; compensation/payroll/advance installments and movements/payments; vouchers/lines; GL/mapping/control-account tables; budgets/versions/lines; periods/cashboxes/settlements; and import staging. Every new write owner MUST route through `AuditService` inside the business transaction with shared `batch_id`/`request_id`; posted financial movements are reversal-only and never hard-deleted.
- **FR-043**: Reconciliation MUST compute `outstanding_due = SUM(amount_delta WHERE bucket_code=STUDENT_OUTSTANDING_DUE)`, `unapplied_credit = SUM(amount_delta WHERE bucket_code=STUDENT_UNAPPLIED_CREDIT)`, and `net_account_position = outstanding_due − unapplied_credit` EXCLUSIVELY from posted `finance_subledger_lines`. No equation subtracts `refunds` or `reversals` as fixed categories — their signed bucket movements already encode the effect. Discounts are NOT subtracted again (`net_due` is already discount-adjusted).
- **FR-044**: Migrations MUST use dated, project-style names (matching the existing `YYYYMMDD_snake_name.php` pattern), MUST define preconditions and rollback, and MUST live only in `database/migrations/`; runtime DDL in request paths is forbidden; migrations MUST NOT be named `0XX_*` and MUST NOT copy `archive/_CLEANUP/install_*.sql` verbatim.
- **FR-045**: Legacy admin pages MUST be converted to compatibility adapters incrementally alongside each vertical slice and after characterization tests, preserving URLs, POST fields, and JSON contracts; adapter conversion MUST NOT all be deferred to a final phase.
- **FR-046**: The schema inspection source for this feature MUST be an isolated database whose name ends in `_test`; data dumps under `storage/private/db_backups` MUST NOT be read or used as a truth source; no personal data MUST be displayed.
- **FR-047**: The feature flag / read-only adapter MUST enable gradual cutover in shadow → display → execute modes; long dual-write MUST be avoided; legacy tables MUST NOT be deleted in the same cutover release as their replacement.
- **FR-048**: Migrated legacy salary contracts MUST NOT guess `effective_from`; the date MUST come from a business decision or a migration provenance field carrying an "uncertain history" status.
- **FR-049**: Tests MUST follow the project's existing PHP test-script style (e.g., `tests/finance_money_contract_test.php`); PHPUnit MUST NOT be assumed.
- **FR-050**: The system MUST remain a single-school deployment; no school/tenant/branch ownership keys.
- **FR-051**: Full double-entry accounting (GL) MUST be in scope for v1: chart of accounts, account-mapping policies, journal entries/lines, cost centers, control accounts, trial balance, P&L, cash flow, budget vs actual, and cash/bank movement.
- **FR-052**: Every posted Student/Payroll/Cashbox operation MUST create a balanced GL journal entry INSIDE THE SAME TRANSACTION (GL MUST NOT be a later phase); the invariant `SUM(debit) = SUM(credit)` per journal entry MUST be enforced at posting.
- **FR-053**: Account-mapping policies MUST map each finance operation type to GL accounts; control-account reconciliation MUST reconcile student/staff sub-ledger balances to GL control accounts.
- **FR-054**: A versioned budget model (`finance_budgets`/`finance_budget_versions`/`finance_budget_lines`) MUST be in scope; cycle MUST be draft→reviewed→approved→locked/revised; entry MUST be manual or via import staging; actuals MUST come EXCLUSIVELY from posted GL journal entries; `admin/school_budget.php` MUST NOT be the truth source for the new budget model.
- **FR-055**: Every new admin page MUST follow `admin/ui_preview.php` and `AGENTS.md` rules: `admin-filter-bar`/`admin-list-surface`/`admin-data-table`, `stat-card` with `counter`/`data-target`, `btn-action-pills`, `buttons.css`, no page-local button/stat-card CSS, Bootstrap modals (no `confirm()`/`alert()`/SweetAlert), server-side DataTables for large tables, RTL responsive printable.

### Functional Requirements — Unified Ledger Truth Source (added 2026-07-23)

- **FR-056**: The generic party sub-ledger defined by FR-071 MUST be the only sub-ledger schema. Implementations and migrations MUST NOT create or reference a parallel student-specific ledger or separate due/credit delta columns.
- **FR-057**: Student balances MUST use the two student bucket equations over posted `finance_subledger_lines.amount_delta`; staff balances MUST use their staff buckets over the same table. No equation subtracts refunds or reversals as fixed categories.
- **FR-058**: A reversal MUST create a NEW ledger transaction with `reversal_of` → original and opposite-sign lines. The original transaction MUST remain `posted` and ALWAYS counted in SUM (never excluded). `reversed_at`/`reversed_by` are status metadata only, NOT a reason to exclude the original. Invariant: `SUM(original lines) + SUM(reversal lines) = 0`.
- **FR-059**: A refund MUST NOT be auto-subtracted. `refund_allocation` posts `amount_delta = +amount` to `STUDENT_OUTSTANDING_DUE`; `refund_unapplied_credit` posts `amount_delta = −amount` to `STUDENT_UNAPPLIED_CREDIT`.
- **FR-060**: Every posted party-affecting accounting source operation MUST create its domain record(s) + exactly one sub-ledger transaction/lines + exactly one GL journal + mandatory audit INSIDE THE SAME database transaction. Source granularity is party-specific: one receipt, charge, refund, adjustment, advance movement, or `payroll_run_item` is one source operation; a payroll run/import is a `batch_id` grouping many atomic source operations. A pure GL operation (expense, other income, cash transfer, or manual journal not tied to a student/staff account) creates exactly one GL journal + mandatory audit and no fake sub-ledger transaction. Budget plan/version/line writes are audited planning data and create neither GL nor sub-ledger postings. Failure of any mandatory participant rolls back the atomic operation; a batch declares and tests its all-or-nothing policy.
- **FR-061**: For party-affecting operations only, the sub-ledger transaction ↔ GL journal relationship MUST be 1:1 through a nullable-unique `accounting_journal_entries.subledger_transaction_id`, and both records MUST share the same `source_idempotency_key`. A pure GL operation has `subledger_transaction_id = NULL`. A retry returns the original operation, not a duplicate.

### Functional Requirements — Invariants (added 2026-07-23)

- **FR-062**: The following MUST hold at posting and be tested: `SUM(installment.net_amount) = charge.net_due`; `SUM(discount_applications.applied_amount for a charge) = charge.discount_amount`; `allocation.allocated_amount <= installment.remaining_due`; `receipt.amount = SUM(allocations) + SUM(created unapplied_credit)`; `unapplied_credit_application.applied_amount <= unapplied_credit.remaining`; domain totals MUST match the corresponding ledger transaction's deltas.
- **FR-063**: `finance_unapplied_credit_applications` MUST exist to support partial application of an unapplied credit to an installment/allocation, with `reversal_of`/`batch_id`/`request_id`.

### Functional Requirements — Versioning, Numbering, Deterministic Mapping (added 2026-07-23)

- **FR-064**: Discount rules MUST be truly versioned and scope-aware: uniqueness MUST include `code + academic_year_id + scope_charge_type_key + version_number` where `scope_charge_type_key` is NOT NULL (literal `'ALL'` for a general policy, or the charge_type `code` for a specific one). No two active versions MAY overlap in time for the same `code + academic_year_id + scope_charge_type_key`. A used version MUST NOT be edited; `effective_from`/`effective_to`/`superseded_at` MUST be present. Tests MUST cover both the general (`ALL`) scope and the charge-type-specific scope.
- **FR-065**: Receipt numbering MUST use `cashbox_id + academic_year_id + sequence_number` uniqueness; the displayed `receipt_number` MUST be built from the cashbox prefix/year/sequence WITHOUT collision; `finance_receipt_number_sequences` MUST provide atomic per-cashbox/year sequencing under `FOR UPDATE`.
- **FR-066**: Account-mapping MUST be a multi-line model (`accounting_account_mapping_headers` versioned + `accounting_account_mapping_lines` with selectors for charge type, payroll component, payment method, cashbox, and operation type). Resolution MUST be deterministic: `specificity_score` DESC, then `priority` DESC, then `version_number` DESC. No two active lines MAY share the same `operation_type + selectors + specificity + priority`. Posting MUST be REFUSED on zero matches OR ambiguous matches (same specificity+priority) OR an unbalanced journal entry. Multi-component payroll (basic + allowances + tax + insurance) MUST map to multiple balanced lines. Tests MUST cover missing, ambiguous, and specific-override cases.

### Functional Requirements — Budget, Upload, Role-Footer, Resync Workflow (added 2026-07-23)

- **FR-067**: Budget `actual_amount` MUST NOT be stored as an independent source; it MUST be computed from posted GL journal entries via a view/query OR a derived cache with a documented refresh/reconciliation mechanism reconcilable to GL on demand.
- **FR-068**: Every import workflow MUST read `docs/file-upload-standard.md` first; every uploaded file MUST be validated through `FileUploadGuard` (real MIME, dangerous double extension, byte limit, random storage name); the path MUST be classified in `tools/upload_policy_manifest.json` and pass `php tools/audit_upload_policy.php --strict` and `composer upload-policy-audit`. Tests MUST cover invalid MIME, dangerous names, size/upload errors, collision-resistant names, authorization, and file/DB rollback.
- **FR-069**: Every new data-entry admin page MUST use the shared `admin_footer`/role footer, `assets/js/form-safety.js`, and `assets/js/undo-toast.js`; a role-coverage contract test MUST exist for it; NO page-local toast/draft/keyboard-shortcut/logger or competing storage-key behavior is permitted.
- **FR-070**: Before implementation code: commit the approved spec-only package; inventory the exact current dirty state of `C:\xampp\htdocs\EduCore`; create a manifest; adopt the current tracked diff plus required untracked source/docs/tests/migrations into this isolated feature worktree as a separate reviewed `baseline: adopt current local main state` commit; exclude `.env`, secrets, private storage/backups, caches/logs, generated outputs, and `scratch/`; leave `main` untouched; then run a full overlap/dependency review and all safe baseline checks. `origin/main` MUST NOT substitute for local state. No `stash`/`reset`/`clean`/`force`. Stop only on an actual patch conflict, unsafe file, secret, or unresolvable dependency—not merely because `main` is dirty.

### Functional Requirements — Unified Sub-ledger, Staff Ledger, Vouchers (added 2026-07-23)

- **FR-071**: A unified sub-ledger MUST be the single source of truth for BOTH student and staff balances: `finance_subledger_accounts` (`party_type` student|staff, `party_id`, `scope_key`, `currency`, no balance column) + `finance_subledger_transactions` (one per party-affecting source operation, unique `source_idempotency_key`, `status` draft|posted ONLY — no `reversed`) + `finance_subledger_lines` (append-only, `bucket_code`, signed `amount_delta`, unique(`transaction_id`,`line_number`), FK ON DELETE RESTRICT). Student account scope is the academic-year ID; staff account scope is the stable constant `STAFF_GLOBAL`, independent of compensation contract, payroll model, or employment episode. Account uniqueness includes `party_type + party_id + scope_key + currency`. Balances are computed EXCLUSIVELY from `SUM(amount_delta)` per bucket.
- **FR-072**: Student buckets MUST be `STUDENT_OUTSTANDING_DUE` and `STUDENT_UNAPPLIED_CREDIT`. Staff buckets MUST be `STAFF_PAYROLL_PAYABLE` and `STAFF_ADVANCE_RECEIVABLE`. A retroactive payroll settlement is a separate payroll source operation but posts into `STAFF_PAYROLL_PAYABLE`, so it follows the normal payroll-payment clearing path and cannot remain in an uncleared special bucket. Separate views/query services MUST serve student vs staff displays.
- **FR-073**: Staff ledger rules MUST hold: payroll posting → `STAFF_PAYROLL_PAYABLE +net`; payroll payment → `STAFF_PAYROLL_PAYABLE −amount`; retroactive settlement posting → `STAFF_PAYROLL_PAYABLE ±net_difference`; advance issue → `STAFF_ADVANCE_RECEIVABLE +amount`; cash repayment → `STAFF_ADVANCE_RECEIVABLE −amount` with GL `Dr Cash/Bank, Cr Staff Advance Receivable`; payroll deduction → `STAFF_ADVANCE_RECEIVABLE −amount` with GL `Dr Payroll Payable, Cr Staff Advance Receivable`; advance write-off → an independent maker-checker-approved `advance_write_off` operation posting `STAFF_ADVANCE_RECEIVABLE −written_off_amount` with GL `Dr Advance Write-off Expense, Cr Staff Advance Receivable`. A write-off MUST be positive, MUST NOT exceed the current remaining receivable, and MUST NOT set `reversal_of` unless reversing a previously posted erroneous write-off. Reversing an erroneous advance issue remains a distinct reversal of the original. `admin/finance_staff_ledger.php` MUST read EXCLUSIVELY from sub-ledger lines, and staff ↔ GL control-account reconciliation MUST be tested.
- **FR-074**: Receipt posting MUST be ONE source operation (`source_type=receipt`, `source_ref_id=receipt.id`): ONE sub-ledger transaction with multi-line (one negative `STUDENT_OUTSTANDING_DUE` line per allocation + one positive `STUDENT_UNAPPLIED_CREDIT` line for overpayment) + ONE GL journal (same `source_idempotency_key`). All allocation/unapplied-credit rows from one receipt share the SAME `subledger_transaction_id`. Refund/application/reversal are SEPARATE transactions. A receipt allocated across three installments with overpayment MUST produce exactly one transaction + one GL journal (tested).
- **FR-075**: Sub-ledger transaction `status` MUST be `draft` or `posted` ONLY (no `reversed`). The original is NEVER excluded from SUM. `is_reversed` is a DERIVED flag (another transaction has `reversal_of` = this id). `original + reversal = 0` per bucket.
- **FR-076**: Discount active-version uniqueness MUST be enforced by an activation transaction using `SELECT ... FOR UPDATE` on `(code, academic_year_id, scope_charge_type_key)` — no partial index assumed (MariaDB-safe). Only ONE active version per policy scope; future versions remain `draft` until activation. A concurrency test MUST prove two simultaneous activations fail. If a generated/computed active-key column is used, its MariaDB compatibility MUST be documented.
- **FR-077**: `staff_advance_installments` MUST be the PRIMARY source for the repayment schedule; `repayment_plan_json` MUST NOT be a parallel truth source. `remaining_amount` MUST be derived from the staff sub-ledger or a documented reconciled cache. `payroll_periods` uniqueness MUST be `finance_period_id + start_date + end_date` (NOT unique by `name`).
- **FR-078**: General vouchers (`finance_vouchers`/`finance_voucher_lines`) MUST be in scope for v1: types `expense`, `other_income`, `cash_transfer`; cashbox/bank, cost center, documents, maker-checker, reversal-only, GL mapping. Expense/other-income vouchers reference one holding cashbox. Cash transfers MUST reference distinct source and destination cashboxes, both non-null, with positive amount and a balanced GL entry; the source and destination MUST NOT be the same. `admin/finance_vouchers.php` and expense/revenue/cash-transfer reports MUST exist. **Supplier/AP is OUT OF SCOPE for v1**; pure GL vouchers have no sub-ledger transaction.
- **FR-079**: Every task ID mentioned in Dependencies/Independent Test/Implementation Strategy MUST have an independent checkbox row in `tasks.md`; no dangling IDs.
- **FR-080**: A voucher or manual journal MUST NOT post directly to a student/staff GL control account. Any operation that changes a party control account MUST go through the owned party-adjustment/posting service and carry a linked sub-ledger transaction; zero-link or mismatched-link control-account postings MUST be refused. Budget planning entries MUST NOT create GL journals.

### Key Entities

- **Charge Type**: fee category (tuition, bus, books, activities, events, uniform, other_services, opening_balance).
- **Fee Plan**: a plan for charge type × academic year × stage/grade; has versions.
- **Fee Plan Version**: an immutable-after-use snapshot of a plan with installments.
- **Fee Plan Installment**: a line in a plan version with gross amount and due date.
- **Student Finance Account**: one per student × academic year; carries no authoritative balance (balance is derived).
- **Student Contract**: annual assignment snapshotting a plan version.
- **Student Charge**: an independent line/installment with gross, discount, adjustment, net due, due date, status, source, academic_year_id; reversible.
- **Charge Installment**: a split of a charge — the due-unit for allocation.
- **Discount Rule**: configurable, versioned policy with priority/combinability/cap/effective dates.
- **Discount Award**: a granted discount on a student account with requester/approver/status/document.
- **Discount Application**: links a discount to the specific charge/installment.
- **Receipt**: immutable-after-posting payment receipt with per-cashbox/per-year number and idempotency key; reversal-only.
- **Payment Allocation**: distribution of a receipt across `finance_charge_installments`.
- **Unapplied Credit**: independent movement for overpayment/prepayment (NOT a balance edit).
- **Adjustment / Refund**: credit/debit settlement and return movements.
- **Cashbox / Bank Account**: cash and bank holding with daily settlement.
- **Finance Period**: academic year + optional sub-period with open/closed/reopen state.
- **Import Batch / Import Row**: staged, validated, approved-then-posted import with schema version.
- **Compensation Contract**: staff salary contract with effective_from/effective_to/status/approval.
- **Compensation Contract Component**: a salary component row (primary source, not snapshot_json).
- **Payroll Component**: definition of a salary component (basic, allowances, bonuses, overtime, insurance, tax, attendance deduction, penalties, advances, other deductions).
- **Payroll Period / Payroll Run / Payroll Run Item / Payroll Item Component**: the payroll cycle and frozen per-staff component copy; runs support versions/settlements/reversal via `reversal_of`.
- **Staff Advance / Staff Advance Installment**: loan with repayment schedule and remaining balance.
- **Payroll Payment**: payment of a posted payslip.
- **Budget / Budget Version / Budget Line**: versioned budget model; actuals from GL only.
- **Accounting Account / Cost Center / Journal Entry / Journal Line / Account-Mapping Policy / Control Account**: GL entities.

### Page Inventory (each new page MUST follow `admin/ui_preview.php` + AGENTS.md)

1. `admin/finance_dashboard.php` — finance overview, stat cards, collection summary.
2. `admin/finance_fee_plans.php` — fee types, plans, versions, installments.
3. `admin/finance_student_accounts.php` — student financial accounts list.
4. `admin/finance_student_ledger.php` — detailed student ledger (charges, payments, discounts, documents, log).
5. `admin/finance_receipts.php` — collection, receipts, allocation, idempotency, reversal.
6. `admin/finance_debts.php` — debts and aging (current/1–30/31–60/61–90/90+).
7. `admin/finance_discounts.php` — discounts/exemptions and approval requests.
8. `admin/finance_buses.php` — bus finance.
9. `admin/finance_staff_contracts.php` — staff compensation contracts.
10. `admin/finance_payroll_runs.php` — payroll runs and payslips.
11. `admin/finance_staff_advances.php` — advances and deductions.
12. `admin/finance_staff_ledger.php` — staff financial ledger.
13. `admin/finance_cashboxes.php` — cashboxes, banks, daily settlement.
14. `admin/finance_journal.php` — journal entries and chart of accounts.
15. `admin/finance_budgets.php` — versioned budgets and budget-vs-actual.
16. `admin/finance_archive.php` — archive and reversals.
17. `admin/finance_import_export.php` — import/export center.
18. `admin/finance_audit_log.php` — finance audit log.
19. `admin/finance_reports.php` — statistics and reports (trial balance, P&L, cash flow, debt aging, bus reports, payroll reports, collection reports).
20. `admin/finance_vouchers.php` — expense, other-income, and cash-transfer vouchers with maker-checker and GL mapping.

## Success Criteria

### Measurable Outcomes

- **SC-001**: 100% of used fee plan versions reject retroactive edit and require a new version.
- **SC-002**: 100% of sibling discount tiers are fixed by oldest-enrollment-date-first order (ties by `student_id`) and stable across regeneration.
- **SC-003**: 100% of employee-child discounts are refused when no documented active employment relationship exists at the charge due date.
- **SC-004**: 100% of discount combinations respect default-no-combine (highest-benefit) and explicit-combine-with-cap.
- **SC-005**: 100% of bus charges are generated from `BusSubscriptionQuery`, not the legacy string match.
- **SC-006**: 100% of retried payments with the same idempotency key return the original receipt and create zero duplicates.
- **SC-007**: 100% of receipt numbers follow the per-cashbox/per-year numbering policy.
- **SC-008**: 100% of receipt cancellations create a reversing entry and leave the original intact; 0% allow hard-delete of posted movements.
- **SC-009**: 100% of overpayments become independent `unapplied_credit` movements (0% silent balance edits).
- **SC-010**: 100% of backdated writes into a closed period are rejected unless a recorded reopen occurred.
- **SC-011**: 100% of payroll gross/net values are server-computed; 0% of client-sent net values are trusted.
- **SC-012**: 100% of salary contract changes leave historical payslips unchanged and apply only from the effective date.
- **SC-013**: 100% of retroactive salary differences are separate settlements in an open period; 0% edit old payslips.
- **SC-014**: 100% of `payroll_runs` reversals use `reversal_of` (0% blocked by a strict unique).
- **SC-015**: 0% of import operations write business data during preview; 100% of posted imports are reversal-batch-correctable.
- **SC-016**: 100% of exports respect filters/columns/permissions and log actor/time/report-type/filters/row-count without report content.
- **SC-017**: 100% of temporary export files are deleted after 24 hours.
- **SC-018**: 0% of financial records are auto-deleted before a formal retention policy is approved.
- **SC-019**: 100% of finance write owners route through `AuditService` inside the business transaction with `AUDIT_REVIEW_REQUIRED=0` on `composer audit-write-coverage`.
- **SC-020**: 100% of student balances (`outstanding_due`, `unapplied_credit`, `net_account_position`) are computed EXCLUSIVELY from `SUM(finance_subledger_lines.amount_delta)` in the student buckets; 0% from a fixed table-name list, domain-table sums, or a standalone column.
- **SC-021**: 100% of sub-ledger balances reconcile to GL control accounts.
- **SC-022**: 100% of posted Student/Payroll/Cashbox operations create a balanced GL journal entry inside the same transaction.
- **SC-023**: 0% of legacy pages change URL/POST field/JSON field during adapter conversion unless an approved compatibility change is on file.
- **SC-024**: 0% of migrated salary contracts guess `effective_from`; 100% carry a business-decided date or provenance with "uncertain history" status.
- **SC-025**: 0% of the feature reads or uses `storage/private/db_backups` dumps as a truth source; 100% of schema inspection uses an isolated `*_test` database.
- **SC-026**: 100% of sensitive operations (receipt reversal, refund, write-off, manual journal, import posting, payroll approval/payment, period reopen, manual/exception discount) enforce maker-checker; 0% allow the creator to approve the same operation.
- **SC-027**: 100% of budget actuals come exclusively from posted GL journal entries (0% from `admin/school_budget.php`).
- **SC-028**: GL reports (trial balance, P&L, cash flow, budget vs actual) are available and respect cost centers.
- **SC-029**: 100% of new admin pages follow `admin/ui_preview.php` and `AGENTS.md` UI rules.
- **SC-030**: `composer quality` (lint + tests + `audit-write-coverage` + `architecture-audit` + `admin-ui-audit`) passes before close.
- **SC-031**: 100% of staff history remains queryable through the same `STAFF_GLOBAL` account after compensation-contract, payroll-model, or employment-status changes.
- **SC-032**: 100% of invariants hold at posting: `SUM(installment.net_amount)=charge.net_due`; `SUM(discount_applications)=charge.discount_amount`; `allocation<=installment.remaining_due`; `receipt.amount=SUM(allocations)+SUM(unapplied_credit)`; `unapplied_credit_application<=credit.remaining`; domain totals match ledger deltas.
- **SC-033**: 100% of party-affecting source operations create exactly one sub-ledger transaction and exactly one linked GL journal; 100% of pure GL operations create exactly one GL journal and zero sub-ledger transactions; 0% duplicates.
- **SC-034**: 100% of reversals create a new opposite-sign transaction with `reversal_of`→original; the original is never excluded from SUM; `original + reversal = 0` in 100% of cases.
- **SC-035**: 100% of discount rule versions are immutable after use; uniqueness includes `code+year+scope_charge_type_key+version`; no two active versions overlap in time for the same scope.
- **SC-036**: 100% of receipt numbers follow `cashbox+year+sequence` uniqueness with no collision.
- **SC-037**: 100% of account-mapping resolutions are deterministic (specificity→priority→version); 0% ambiguous; posting refused on zero/ambiguous matches or unbalanced entries.
- **SC-038**: 100% of budget actuals come from GL (view/query or reconciled cache); 0% independent source.
- **SC-039**: 100% of import workflows use `FileUploadGuard`, pass `upload_policy_manifest.json` and `composer upload-policy-audit`.
- **SC-040**: 100% of new data-entry pages use shared role-footer/form-safety/undo-toast and have a role-coverage contract test; 0% page-local toast/draft/logger.
- **SC-041**: Before feature code, the spec-only commit and separate local-baseline commit exist; the baseline manifest accounts for every adopted/excluded dirty path with reason; `main` remains byte-for-byte untouched by adoption; zero secrets/backups/cache/log/scratch artifacts are committed; baseline overlap/dependency checks pass; zero use of `stash`/`reset`/`clean`/`force` or `origin/main` as a substitute for local changes.
- **SC-042**: 100% of student AND staff balances computed EXCLUSIVELY from `finance_subledger_lines.amount_delta` per bucket; 0% from domain-table sums.
- **SC-043**: 100% of staff ledger rules hold for payroll posting/payment, settlement posting/payment, advance issue, cash repayment, payroll deduction, and write-off; after advance 1000 and repayment 200, write-off 800 yields zero while write-off 1000 is rejected; staff ledger reads EXCLUSIVELY from the sub-ledger and staff ↔ GL reconciliation passes.
- **SC-044**: 100% of receipts create exactly one sub-ledger transaction (multi-line) + one GL journal; a 3-installment + overpayment receipt produces one transaction + one journal.
- **SC-045**: Sub-ledger `status` is draft|posted only; `is_reversed` is derived; `original + reversal = 0` per bucket in 100% of cases.
- **SC-046**: Discount active-version uniqueness enforced by activation transaction + `FOR UPDATE`; two simultaneous activations fail (concurrency tested); MariaDB-safe (no partial index).
- **SC-047**: `staff_advance_installments` is the primary repayment source; `remaining_amount` derived from sub-ledger; `payroll_periods` unique by `finance_period_id+start_date+end_date`.
- **SC-048**: Vouchers (expense/other_income/cash_transfer) in v1 have deterministic GL mapping; every cash transfer has distinct source/destination holdings and a balanced journal; `finance_vouchers.php` + expense/revenue/cash-transfer reports exist; AP/supplier is explicitly out of scope for v1.
- **SC-049**: 100% of unlinked voucher/manual attempts against student/staff control accounts are refused; 0% of budget plan/version/line writes create GL or party sub-ledger postings.

## Assumptions

- The deployment remains one school on the current XAMPP/MariaDB host.
- Currency is EGP with piasters as minor units; a unified rounding policy (half-up) is applied in the `Money` value object at presentation, not during accumulation.
- New tables use `academic_year_id` (integer FK) as the canonical year link, with adapters for legacy text `academic_year` columns during cutover.
- The current user-owned local `main` source state is the approved baseline for implementation, but it must first be copied non-destructively into this isolated worktree and committed separately under FR-070; `main` itself is never modified by the adoption.
- Full double-entry accounting (GL) is IN SCOPE for v1 (not deferred).
- Production deployment, push, migration execution, and any write against `educore` are out of scope and require explicit authority.
- Reading or using any data dump under `storage/private/db_backups` as a source of truth is out of scope; schema is inspected only from an isolated `*_test` database and no personal data is displayed.
- The test database is created by cloning the live source structure OR by running the new dated foundation migrations on an empty `*_test` database; the foundation migrations MUST make the finance schema creatable from scratch without relying on a production dump.
- No financial record is auto-deleted until a formal retention policy is approved; temporary export files are deleted after 24 hours.
- The legacy `student_fees.balance` is the current truth during cutover; the new ledger computes a derived balance and reconciles against it before any path is retired.
- `admin/school_budget.php` is NOT the truth source for the new budget model; a new versioned budget model is built.

## Compatibility, Security, and Data Impact

- **Existing contracts**: Preserve all admin finance URLs, POST `action` values, form `name`/`id`/`action`, hidden inputs, CSRF fields, session keys, JSON response field names, permissions, and SQL behavior of the legacy finance pages. Legacy pages become thin adapters.
- **Roles and authorization**: Admin/finance roles; server-side `validateSession()` and CSRF before any state processing; proposed independent permissions (`finance_view`, `student_charge_manage`, `payment_record`, `payment_reverse`, `discount_request`, `discount_approve`, `payroll_prepare`, `payroll_review`, `payroll_approve`, `payroll_pay`, `finance_export`, `finance_audit_view`, `period_close`, `period_reopen`, `budget_manage`) requiring business adoption; MANDATORY maker-checker for sensitive operations.
- **State-changing requests**: POST + timing-safe CSRF, PRG responses, `AuditService` inside the business transaction, `batch_id`/`request_id` threading, `FOR UPDATE`/optimistic revision for concurrent writes, idempotency keys for payments, reversal-only for posted movements (no hard-delete), period-close guard extending `AcademicYearWriteGuard`, balanced GL journal entry inside the same transaction.
- **Data/schema**: New tables only via dated migrations in `database/migrations/` with preconditions + rollback; DECIMAL(14,2) money; `created_at`/`created_by` + `posted_at`/`reversed_at`/`archived_at` + reason; unique constraints to prevent duplication (idempotency key, receipt number per cashbox/year, contract effective date); `FOR UPDATE`/optimistic revision on allocation/reversal; no runtime DDL; no `0XX_*` names; no verbatim copy of `archive/_CLEANUP/install_*.sql`. The schema inspection source is an isolated `*_test` database; `storage/private/db_backups` dumps are NOT a truth source.
- **Sensitive data and errors**: No secrets/hashes/tokens/credentials in audit or undo snapshots (per `AuditPolicyRegistry::SENSITIVE_FIELDS`); no full payment-sensitive data; receipts/payslips attached via `FileUploadGuard` with random stored names and relative DB paths, served through authenticated authorized controllers; sensitive attachments in `storage/private/`; exports log scope/filters/row-count without content; temp export files deleted after 24 hours; server-side `error_log()` only, no `display_errors`; HTML escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- **Rollback**: Code rollback disables the new Finance services/adapters first; data rollback uses reconciliation-verified reversal batches and manifest-owned migration rollback (DROP new tables only, never legacy); a rollback drill on `*_test` must prove full return to original state before any production cutover; feature flag enables shadow → display → execute; legacy tables are NOT deleted in the same release as their replacement.
- **Stop conditions / unknowns**: Stop if baseline adoption produces an actual conflict, unsafe/unclassified file, secret, or missing dependency; stop if an isolated `*_test` database cannot be proven; stop if a legacy adapter would change a URL/POST/JSON contract without approval; stop if reconciliation, migration preconditions, or rollback cannot be established. A dirty `main` by itself is not a stop condition after the user-approved non-destructive baseline workflow is followed.
